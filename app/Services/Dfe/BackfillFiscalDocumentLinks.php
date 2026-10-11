<?php

namespace App\Services\Dfe;

use App\Enums\CiotStatusEnum;
use App\Enums\CteDocumentStatusEnum;
use App\Enums\FiscalDocumentStatusEnum;
use App\Models\Ciot;
use App\Models\CteDocument;
use App\Models\DfeDocumentReservation;
use App\Models\MdfeDocument;
use DomainException;
use DOMDocument;
use DOMElement;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

class BackfillFiscalDocumentLinks
{
    private const RESERVATION_CONFLICT = 'reservation_conflict';

    public function __construct(private readonly DfeDocumentStore $documentStore) {}

    /**
     * @param  array<int, int|string>  $batchIds
     * @return array{
     *     scanned: int,
     *     resolved: int,
     *     pending: int,
     *     divergent: int,
     *     items: list<array{mdfe_document_id: int, batch_id: ?int, status: string, reasons: list<string>}>
     * }
     */
    public function handle(
        array $batchIds = [],
        ?int $fromBatchId = null,
        ?int $toBatchId = null,
        bool $apply = false,
    ): array {
        foreach ($batchIds as $batchId) {
            if ((! is_int($batchId) && ! is_string($batchId))
                || filter_var($batchId, FILTER_VALIDATE_INT) === false
                || (int) $batchId < 1) {
                throw new InvalidArgumentException('Batch IDs must be positive integers.');
            }
        }

        if (($fromBatchId === null) !== ($toBatchId === null)
            || ($fromBatchId !== null && ($fromBatchId < 1 || $toBatchId < $fromBatchId))) {
            throw new InvalidArgumentException('A batch range requires positive bounds in ascending order.');
        }

        $batchIds = array_values(array_unique(array_map(static fn (int|string $batchId): int => (int) $batchId, $batchIds)));

        $documentsQuery = MdfeDocument::query()->orderBy('id');

        if ($batchIds !== []) {
            $documentsQuery->whereIn('cte_emission_batch_id', $batchIds);
        }

        if ($fromBatchId !== null) {
            $documentsQuery->where('cte_emission_batch_id', '>=', $fromBatchId);
        }

        if ($toBatchId !== null) {
            $documentsQuery->where('cte_emission_batch_id', '<=', $toBatchId);
        }

        $report = [
            'scanned' => 0,
            'resolved' => 0,
            'pending' => 0,
            'divergent' => 0,
            'items' => [],
        ];

        foreach ($documentsQuery->get() as $document) {
            $analysis = $this->analyze($document);
            $reasons = $analysis['reasons'];
            $status = $analysis['status'];

            if ($apply) {
                $reasons = array_values(array_unique(array_merge(
                    $reasons,
                    $this->applyAnalysis($document, $analysis),
                )));

                if ($this->hasApplyDivergence($reasons)
                    || ($reasons !== [] && $status === 'resolved')) {
                    $status = 'divergent';
                }
            }

            $report['scanned']++;
            $report[$status]++;
            $report['items'][] = [
                'mdfe_document_id' => $document->id,
                'batch_id' => $document->cte_emission_batch_id,
                'status' => $status,
                'reasons' => $reasons,
            ];
        }

        return $report;
    }

    /**
     * @return array{
     *     status: string,
     *     reasons: list<string>,
     *     verified_ctes: array<int, CteDocument>,
     *     verified_ciot_id: ?int,
     *     verified_ciot_number: ?string,
     *     verified_ciot_protocol: ?string,
     *     candidate_cte_ids: list<int>,
     *     candidate_ciot_ids: list<int>,
     *     verified_cte_sha256: array<int, string>,
     *     mdfe_proc_sha256: ?string,
     *     existing_cte_links: list<array{cte_document_id: int, position: int}>,
     *     fingerprint: string
     * }
     */
    private function analyze(MdfeDocument $document): array
    {
        $pending = [];
        $divergent = [];
        $snapshot = is_array($document->snapshot) ? $document->snapshot : [];
        $snapshotCteKeys = $this->snapshotCteKeys($snapshot);
        $snapshotCiotNumber = $this->normalizeCiotNumber($snapshot['ciot'] ?? null);
        $mdfeEvidence = null;
        $mdfeProcSha256 = null;
        $xmlCteKeys = [];
        $xmlCiotNumber = null;
        $verifiedCteSha256 = [];
        $existingCteLinks = $this->existingCteLinks($document);

        if (blank($document->xml_path)) {
            $pending[] = 'missing_mdfe_proc_path';
        } elseif (! $this->documentStore->exists((string) $document->xml_path)) {
            $pending[] = 'mdfe_proc_unavailable';
        } else {
            try {
                $mdfeXml = $this->documentStore->read((string) $document->xml_path);
                $mdfeEvidence = $this->parseProcXml($mdfeXml, 'mdfe');
                $mdfeProcSha256 = $mdfeEvidence['sha256'];
            } catch (DomainException) {
                $divergent[] = 'invalid_mdfe_proc';
            } catch (Throwable) {
                $pending[] = 'mdfe_proc_unavailable';
            }
        }

        if ($mdfeEvidence !== null) {
            $xmlCteKeys = $mdfeEvidence['cte_access_keys'];
            $xmlCiotNumber = $mdfeEvidence['ciot_number'];

            $mdfePending = [];
            $mdfeDivergent = [];
            $this->validateInternalDocument($document, $mdfeEvidence, 'mdfe', $mdfePending, $mdfeDivergent);
            $mdfeEvidenceTrusted = $mdfePending === []
                && array_diff($mdfeDivergent, ['mdfe_internal_proc_hash_mismatch']) === [];
            $pending = array_merge($pending, $mdfePending);
            $divergent = array_merge($divergent, $mdfeDivergent);

            if ($snapshotCteKeys === []) {
                $pending[] = 'snapshot_cte_keys_missing';
            }

            if ($xmlCteKeys === []) {
                $pending[] = 'mdfe_proc_cte_keys_missing';
            }

            if ($snapshotCteKeys !== [] && $xmlCteKeys !== []
                && $this->sortedValues($snapshotCteKeys) !== $this->sortedValues($xmlCteKeys)) {
                $divergent[] = 'snapshot_cte_keys_disagree_with_mdfe_proc';
            }

            if ($snapshotCiotNumber === null) {
                $pending[] = 'snapshot_ciot_missing_or_invalid';
            }

            if ($xmlCiotNumber === null) {
                $pending[] = 'mdfe_proc_ciot_missing_or_invalid';
            }

            if ($snapshotCiotNumber !== null && $xmlCiotNumber !== null
                && $snapshotCiotNumber !== $xmlCiotNumber) {
                $divergent[] = 'snapshot_ciot_disagrees_with_mdfe_proc';
            }
        }

        $verifiedCtes = [];
        $cteEvidenceAgrees = $snapshotCteKeys !== []
            && $xmlCteKeys !== []
            && count($snapshotCteKeys) === count(array_unique($snapshotCteKeys))
            && count($xmlCteKeys) === count(array_unique($xmlCteKeys))
            && $this->sortedValues($snapshotCteKeys) === $this->sortedValues($xmlCteKeys);

        if (count($snapshotCteKeys) !== count(array_unique($snapshotCteKeys))
            || count($xmlCteKeys) !== count(array_unique($xmlCteKeys))) {
            $divergent[] = 'duplicate_cte_access_key_evidence';
        }

        if (($mdfeEvidenceTrusted ?? false) && $cteEvidenceAgrees) {
            foreach ($xmlCteKeys as $position => $accessKey) {
                if (! $this->hasDocumentKey($accessKey, '57')) {
                    $divergent[] = 'invalid_cte_access_key';

                    continue;
                }

                $matches = CteDocument::query()->where('access_key', $accessKey)->get();

                if ($matches->count() !== 1) {
                    $pending[] = $matches->isEmpty()
                        ? 'cte_internal_record_missing'
                        : 'duplicate_cte_internal_records';

                    continue;
                }

                /** @var CteDocument $cteDocument */
                $cteDocument = $matches->first();

                if (blank($cteDocument->xml_path)) {
                    $pending[] = 'cte_proc_path_missing';

                    continue;
                }

                if (! $this->documentStore->exists((string) $cteDocument->xml_path)) {
                    $pending[] = 'cte_proc_unavailable';

                    continue;
                }

                try {
                    $cteXml = $this->documentStore->read((string) $cteDocument->xml_path);
                    $cteEvidence = $this->parseProcXml($cteXml, 'cte');
                } catch (DomainException) {
                    $divergent[] = 'invalid_cte_proc';

                    continue;
                } catch (Throwable) {
                    $pending[] = 'cte_proc_unavailable';

                    continue;
                }

                $ctePending = [];
                $cteDivergent = [];
                $this->validateInternalDocument($cteDocument, $cteEvidence, 'cte', $ctePending, $cteDivergent);

                if ($cteEvidence['environment'] !== $mdfeEvidence['environment']) {
                    $cteDivergent[] = 'cte_mdfe_environment_mismatch';
                }

                if ($cteEvidence['issuer_cnpj'] !== $mdfeEvidence['issuer_cnpj']) {
                    $cteDivergent[] = 'cte_mdfe_issuer_mismatch';
                }

                if (blank($cteDocument->emitter) || blank($document->emitter)
                    || blank($cteDocument->execution_mode) || blank($document->execution_mode)) {
                    $ctePending[] = 'internal_emitter_or_execution_mode_missing';
                } elseif ($cteDocument->emitter !== $document->emitter
                    || $cteDocument->execution_mode !== $document->execution_mode) {
                    $cteDivergent[] = 'cte_mdfe_emitter_or_execution_mode_mismatch';
                }

                $pending = array_merge($pending, $ctePending);
                $divergent = array_merge($divergent, $cteDivergent);

                if ($ctePending === [] && $cteDivergent === []) {
                    $verifiedCtes[$position] = $cteDocument;
                    $verifiedCteSha256[$position] = $cteEvidence['sha256'];
                }
            }
        }

        if (($mdfeEvidenceTrusted ?? false)
            && $cteEvidenceAgrees
            && count($verifiedCtes) === count($xmlCteKeys)) {
            $expectedPositions = [];

            foreach ($verifiedCtes as $position => $cteDocument) {
                $expectedPositions[$cteDocument->id] = $position;
            }

            foreach ($existingCteLinks as $linkedCte) {
                if (! array_key_exists($linkedCte['cte_document_id'], $expectedPositions)
                    || $linkedCte['position'] !== $expectedPositions[$linkedCte['cte_document_id']]) {
                    $divergent[] = 'existing_cte_link_conflict';
                    break;
                }
            }
        }

        $verifiedCiotId = null;
        $verifiedCiotNumber = null;
        $verifiedCiotProtocol = null;

        if (($mdfeEvidenceTrusted ?? false) && $snapshotCiotNumber !== null && $xmlCiotNumber !== null
            && $snapshotCiotNumber === $xmlCiotNumber) {
            $ciots = Ciot::query()->where('ciot_number', $snapshotCiotNumber)->get();

            if ($ciots->count() !== 1) {
                $pending[] = $ciots->isEmpty() ? 'ciot_internal_record_missing' : 'duplicate_ciot_internal_records';
            } else {
                /** @var Ciot $ciot */
                $ciot = $ciots->first();

                if ($document->ciot_id !== null && $document->ciot_id !== $ciot->id) {
                    $divergent[] = 'existing_ciot_link_conflict';
                }

                if (! in_array($ciot->status, [CiotStatusEnum::ISSUED, CiotStatusEnum::CLOSED], true)) {
                    $divergent[] = 'ciot_internal_status_not_issued';
                } elseif (blank($ciot->protocol)) {
                    $pending[] = 'ciot_internal_protocol_missing';
                } else {
                    $verifiedCiotId = $ciot->id;
                    $verifiedCiotNumber = (string) $ciot->ciot_number;
                    $verifiedCiotProtocol = (string) $ciot->protocol;
                }
            }
        }

        $candidateCteIds = $this->candidateCteIds($document, array_merge($snapshotCteKeys, $xmlCteKeys));
        $candidateCiotIds = $this->candidateCiotIds($document, array_filter([
            $snapshotCiotNumber,
            $xmlCiotNumber,
        ]));

        if ($document->ciot_id !== null) {
            $candidateCiotIds[] = $document->ciot_id;
        }

        $candidateCiotIds = array_values(array_unique($candidateCiotIds));
        $pending = array_values(array_unique($pending));
        $divergent = array_values(array_unique($divergent));
        $status = $divergent !== [] ? 'divergent' : ($pending !== [] ? 'pending' : 'resolved');

        if ($status === 'resolved' && count($verifiedCtes) !== count($xmlCteKeys)) {
            $pending[] = 'cte_evidence_incomplete';
            $status = 'pending';
        }

        if ($status === 'resolved') {
            $candidateCteIds = array_values(array_unique(array_map(
                static fn (CteDocument $cteDocument): int => $cteDocument->id,
                array_values($verifiedCtes),
            )));
            $candidateCiotIds = $verifiedCiotId !== null && in_array($verifiedCiotId, $candidateCiotIds, true)
                ? [$verifiedCiotId]
                : [];
        }

        return [
            'status' => $status,
            'reasons' => array_values(array_unique(array_merge($pending, $divergent))),
            'verified_ctes' => $verifiedCtes,
            'verified_cte_sha256' => $verifiedCteSha256,
            'verified_ciot_id' => $verifiedCiotId,
            'verified_ciot_number' => $verifiedCiotNumber,
            'verified_ciot_protocol' => $verifiedCiotProtocol,
            'candidate_cte_ids' => $candidateCteIds,
            'candidate_ciot_ids' => $candidateCiotIds,
            'mdfe_proc_sha256' => $mdfeProcSha256,
            'existing_cte_links' => $existingCteLinks,
            'fingerprint' => $this->fingerprint($document),
        ];
    }

    /**
     * @param  array<string, mixed>  $evidence
     * @param  list<string>  $pending
     * @param  list<string>  $divergent
     */
    private function validateInternalDocument(
        CteDocument|MdfeDocument $model,
        array $evidence,
        string $kind,
        array &$pending,
        array &$divergent,
    ): void {
        if (! $this->hasDocumentKey($evidence['access_key'], $kind === 'cte' ? '57' : '58')) {
            $divergent[] = "invalid_{$kind}_access_key";
        }

        if ($evidence['model'] !== ($kind === 'cte' ? '57' : '58')) {
            $divergent[] = "{$kind}_proc_model_mismatch";
        }

        if ($evidence['access_key'] !== $evidence['protocol_access_key']) {
            $divergent[] = "{$kind}_proc_protocol_key_mismatch";
        }

        if ($evidence['protocol_status'] !== '100') {
            $divergent[] = "{$kind}_proc_not_authorized";
        }

        if (! in_array($model->status, [
            CteDocumentStatusEnum::AUTHORIZED,
            CteDocumentStatusEnum::SUPERSEDED,
        ], true)) {
            $divergent[] = "{$kind}_internal_status_not_authorized";
        }

        if ($model->fiscal_status !== FiscalDocumentStatusEnum::AUTHORIZED) {
            $divergent[] = "{$kind}_internal_fiscal_status_not_authorized";
        }

        if (blank($model->fiscal_status_code)) {
            $pending[] = "{$kind}_internal_fiscal_status_code_missing";
        } elseif ($model->fiscal_status_code !== '100') {
            $divergent[] = "{$kind}_internal_fiscal_status_code_not_authorized";
        }

        if (! $evidence['identity_matches_access_key']) {
            $divergent[] = "{$kind}_proc_identity_mismatch";
        }

        $internalIdentityMatches = $this->internalIdentityMatches($model, $evidence['identity']);

        if ($internalIdentityMatches === null) {
            $pending[] = "{$kind}_internal_identity_missing";
        } elseif (! $internalIdentityMatches) {
            $divergent[] = "{$kind}_internal_identity_mismatch";
        }

        if (blank($model->sha256)) {
            $pending[] = "{$kind}_internal_proc_hash_missing";
        } elseif (! hash_equals((string) $model->sha256, $evidence['sha256'])) {
            $divergent[] = "{$kind}_internal_proc_hash_mismatch";
        }

        if ($evidence['environment'] === '' || $evidence['protocol_environment'] === '') {
            $divergent[] = "{$kind}_proc_environment_missing";
        } elseif ($evidence['environment'] !== $evidence['protocol_environment']) {
            $divergent[] = "{$kind}_proc_environment_mismatch";
        }

        if ($evidence['issuer_cnpj'] !== substr($evidence['access_key'], 6, 14)) {
            $divergent[] = "{$kind}_proc_emitter_key_mismatch";
        }

        $snapshotIssuerCnpj = $this->snapshotIssuerCnpj($model);

        if ($snapshotIssuerCnpj !== null && $snapshotIssuerCnpj !== $evidence['issuer_cnpj']) {
            $divergent[] = "{$kind}_snapshot_emitter_mismatch";
        }

        $snapshotEnvironment = $this->snapshotEnvironment($model);

        if ($snapshotEnvironment !== null && $snapshotEnvironment !== $evidence['environment']) {
            $divergent[] = "{$kind}_snapshot_environment_mismatch";
        }

        if (blank($model->access_key)) {
            $pending[] = "{$kind}_internal_access_key_missing";
        } elseif ($model->access_key !== $evidence['access_key']) {
            $divergent[] = "{$kind}_internal_access_key_mismatch";
        }

        if (blank($model->protocol)) {
            $pending[] = "{$kind}_internal_protocol_missing";
        } elseif ($model->protocol !== $evidence['protocol']) {
            $divergent[] = "{$kind}_internal_protocol_mismatch";
        }

        $expectedPath = $kind === 'cte'
            ? "cte/{$evidence['access_key']}/cte-proc.xml"
            : "mdfe/{$evidence['access_key']}/mdfe-proc.xml";

        if (blank($model->xml_path)) {
            $pending[] = "{$kind}_internal_proc_path_missing";
        } elseif ($model->xml_path !== $expectedPath) {
            $divergent[] = "{$kind}_internal_proc_path_mismatch";
        }

        if (blank($model->emitter) || blank($model->execution_mode)) {
            $pending[] = "{$kind}_internal_emitter_or_execution_mode_missing";
        }
    }

    /**
     * @return array{
     *     access_key: string,
     *     model: string,
     *     protocol_access_key: string,
     *     protocol: string,
     *     protocol_status: string,
     *     environment: string,
     *     protocol_environment: string,
     *     issuer_cnpj: string,
     *     cte_access_keys: list<string>,
     *     ciot_number: ?string,
     *     identity: array{cUF: string, cCT: string, model: string, series: string, number: string, tpEmis: string, cDV: string},
     *     identity_matches_access_key: bool,
     *     sha256: string
     * }
     */
    private function parseProcXml(string $xml, string $kind): array
    {
        if (stripos($xml, '<!DOCTYPE') !== false) {
            throw new DomainException('The proc XML must not declare a document type.');
        }

        $dom = new DOMDocument;
        $previousErrorMode = libxml_use_internal_errors(true);

        try {
            $loaded = $dom->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS);
        } catch (Throwable) {
            $loaded = false;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousErrorMode);
        }

        $root = $loaded ? $dom->documentElement : null;
        $expectedRoot = $kind === 'cte' ? 'cteProc' : 'mdfeProc';
        $expectedNamespace = $kind === 'cte'
            ? 'http://www.portalfiscal.inf.br/cte'
            : 'http://www.portalfiscal.inf.br/mdfe';

        if (! $root instanceof DOMElement || $root->localName !== $expectedRoot
            || $root->namespaceURI !== $expectedNamespace) {
            throw new DomainException('The proc XML root or namespace is invalid.');
        }

        $documentElementName = $kind === 'cte' ? 'infCte' : 'infMDFe';
        $protocolElementName = $kind === 'cte' ? 'protCTe' : 'protMDFe';
        $documentPrefix = $kind === 'cte' ? 'CTe' : 'MDFe';
        $protocolKeyName = $kind === 'cte' ? 'chCTe' : 'chMDFe';
        $numberElementName = $kind === 'cte' ? 'nCT' : 'nMDF';
        $randomCodeElementName = $kind === 'cte' ? 'cCT' : 'cMDF';
        $infDocument = $this->firstDescendant($root, $documentElementName);
        $protocolContainer = $this->firstDescendant($root, $protocolElementName);
        $protocolInfo = $protocolContainer === null ? null : $this->firstChild($protocolContainer, 'infProt');
        $ide = $infDocument === null ? null : $this->firstChild($infDocument, 'ide');
        $issuer = $infDocument === null ? null : $this->firstChild($infDocument, 'emit');
        $accessKey = $infDocument?->getAttribute('Id') ?? '';

        if (! str_starts_with($accessKey, $documentPrefix)) {
            throw new DomainException('The proc XML document identifier is invalid.');
        }

        $accessKey = substr($accessKey, strlen($documentPrefix));
        $issuerCnpj = $issuer === null ? '' : (string) $this->childText($issuer, 'CNPJ');

        if ($infDocument === null || $protocolInfo === null || $ide === null || $issuerCnpj === '') {
            throw new DomainException('The proc XML is missing required fiscal evidence.');
        }

        $identity = [
            'cUF' => (string) $this->childText($ide, 'cUF'),
            'cCT' => (string) $this->childText($ide, $randomCodeElementName),
            'model' => (string) $this->childText($ide, 'mod'),
            'series' => (string) $this->childText($ide, 'serie'),
            'number' => (string) $this->childText($ide, $numberElementName),
            'tpEmis' => (string) $this->childText($ide, 'tpEmis'),
            'cDV' => (string) $this->childText($ide, 'cDV'),
        ];

        if (in_array('', $identity, true)) {
            throw new DomainException('The proc XML is missing access-key identity fields.');
        }

        $cteAccessKeys = [];
        $ciotNumber = null;

        if ($kind === 'mdfe') {
            $infoDocuments = $this->firstChild($infDocument, 'infDoc');

            foreach ($infoDocuments === null ? [] : $this->descendantsNamed($infoDocuments, 'infCTe') as $cteElement) {
                $key = $this->childText($cteElement, 'chCTe');
                $cteAccessKeys[] = $key ?? '';
            }

            $ciotElement = $this->firstDescendant($infDocument, 'CIOT');
            $ciotNumber = $ciotElement !== null ? trim($ciotElement->textContent) : null;
            $ciotNumber = $ciotNumber === '' ? null : $ciotNumber;
        }

        return [
            'access_key' => $accessKey,
            'model' => (string) $this->childText($ide, 'mod'),
            'protocol_access_key' => (string) $this->childText($protocolInfo, $protocolKeyName),
            'protocol' => (string) $this->childText($protocolInfo, 'nProt'),
            'protocol_status' => (string) $this->childText($protocolInfo, 'cStat'),
            'environment' => (string) $this->childText($ide, 'tpAmb'),
            'protocol_environment' => (string) $this->childText($protocolInfo, 'tpAmb'),
            'issuer_cnpj' => $issuerCnpj,
            'cte_access_keys' => $cteAccessKeys,
            'ciot_number' => $ciotNumber,
            'identity' => $identity,
            'identity_matches_access_key' => $this->identityMatchesAccessKey($accessKey, $identity),
            'sha256' => hash('sha256', $xml),
        ];
    }

    /**
     * @param  array<string, mixed>  $analysis
     * @return list<string>
     */
    private function applyAnalysis(MdfeDocument $document, array $analysis): array
    {
        try {
            return DB::transaction(function () use ($document, $analysis): array {
                /** @var MdfeDocument $lockedDocument */
                $lockedDocument = MdfeDocument::query()->lockForUpdate()->findOrFail($document->id);

                if ($this->fingerprint($lockedDocument) !== $analysis['fingerprint']) {
                    return ['mdfe_changed_during_backfill'];
                }

                $currentCteLinks = $this->existingCteLinks($lockedDocument, lock: true);
                $cteIdsToLock = array_values(array_unique(array_merge(
                    $analysis['candidate_cte_ids'],
                    array_map(static fn (CteDocument $cteDocument): int => $cteDocument->id, $analysis['verified_ctes']),
                    array_column($currentCteLinks, 'cte_document_id'),
                )));
                $lockedCteDocuments = $cteIdsToLock === []
                    ? new Collection
                    : CteDocument::query()->whereKey($cteIdsToLock)->orderBy('id')->lockForUpdate()->get()->keyBy('id');

                $ciotIdsToLock = array_values(array_unique(array_merge(
                    $analysis['candidate_ciot_ids'],
                    $analysis['verified_ciot_id'] === null ? [] : [$analysis['verified_ciot_id']],
                )));
                $lockedCiots = $ciotIdsToLock === []
                    ? new Collection
                    : Ciot::query()->whereKey($ciotIdsToLock)->orderBy('id')->lockForUpdate()->get()->keyBy('id');

                $analysis['candidate_cte_ids'] = $lockedCteDocuments
                    ->filter(fn (CteDocument $cteDocument): bool => $this->isPotentialCte($cteDocument))
                    ->keys()
                    ->map(static fn (mixed $id): int => (int) $id)
                    ->values()
                    ->all();
                $analysis['candidate_ciot_ids'] = $lockedCiots
                    ->filter(fn (Ciot $ciot): bool => in_array($ciot->status, [CiotStatusEnum::PENDING, CiotStatusEnum::ISSUED], true))
                    ->keys()
                    ->map(static fn (mixed $id): int => (int) $id)
                    ->values()
                    ->all();

                $reasons = [];

                if ($currentCteLinks !== $analysis['existing_cte_links']) {
                    $reasons[] = 'cte_pivot_changed_during_backfill';
                    $analysis['candidate_cte_ids'] = array_values(array_unique(array_merge(
                        $analysis['candidate_cte_ids'],
                        array_column($currentCteLinks, 'cte_document_id'),
                    )));
                }

                if ($analysis['mdfe_proc_sha256'] !== null
                    && ! $this->procHashMatches($lockedDocument->xml_path, $analysis['mdfe_proc_sha256'])) {
                    $reasons[] = 'mdfe_proc_changed_during_backfill';
                }

                $verifiedCiot = $analysis['verified_ciot_id'] === null
                    ? null
                    : $lockedCiots->get($analysis['verified_ciot_id']);

                if ($analysis['verified_ciot_id'] !== null) {
                    if (! $verifiedCiot instanceof Ciot
                        || $verifiedCiot->ciot_number !== $analysis['verified_ciot_number']
                        || $verifiedCiot->protocol !== $analysis['verified_ciot_protocol']
                        || blank($verifiedCiot->protocol)
                        || ! in_array($verifiedCiot->status, [CiotStatusEnum::ISSUED, CiotStatusEnum::CLOSED], true)) {
                        $verifiedCiot = null;
                        $reasons[] = 'ciot_changed_during_backfill';
                    }
                }

                foreach ($analysis['verified_ctes'] as $position => $analyzedCte) {
                    $cteDocument = $lockedCteDocuments->get($analyzedCte->id);
                    $expectedSha256 = $analysis['verified_cte_sha256'][$position] ?? null;

                    if (! $cteDocument instanceof CteDocument
                        || ! $this->sameCteEvidence($cteDocument, $analyzedCte)
                        || $expectedSha256 === null
                        || ! $this->procHashMatches($cteDocument->xml_path, $expectedSha256)) {
                        $reasons[] = 'cte_changed_during_backfill';
                    }
                }

                if ($this->isActive($lockedDocument)) {
                    if (in_array(self::RESERVATION_CONFLICT, $this->reserveCandidates($lockedDocument, $analysis), true)) {
                        throw new DomainException(self::RESERVATION_CONFLICT);
                    }
                }

                if ($reasons !== []) {
                    return array_values(array_unique($reasons));
                }

                if ($verifiedCiot !== null && $lockedDocument->ciot_id === null) {
                    $lockedDocument->ciot_id = $verifiedCiot->id;
                    $lockedDocument->save();
                }

                foreach ($analysis['verified_ctes'] as $position => $analyzedCte) {
                    $cteDocument = $lockedCteDocuments->get($analyzedCte->id);

                    if (! $cteDocument instanceof CteDocument) {
                        continue;
                    }

                    $existingLink = collect($currentCteLinks)->firstWhere('cte_document_id', $cteDocument->id);

                    if ($existingLink === null) {
                        $lockedDocument->cteDocuments()->attach($cteDocument->id, ['position' => $position]);
                    } elseif ($existingLink['position'] !== $position) {
                        $lockedDocument->cteDocuments()->updateExistingPivot($cteDocument->id, ['position' => $position]);
                    }
                }

                return [];
            }, attempts: 3);
        } catch (DomainException $exception) {
            if ($exception->getMessage() !== self::RESERVATION_CONFLICT) {
                throw $exception;
            }

            return [self::RESERVATION_CONFLICT];
        }
    }

    private function sameCteEvidence(CteDocument $current, CteDocument $analyzed): bool
    {
        return $current->status === $analyzed->status
            && $current->fiscal_status === $analyzed->fiscal_status
            && $current->fiscal_status_code === $analyzed->fiscal_status_code
            && $current->access_key === $analyzed->access_key
            && $current->series === $analyzed->series
            && $current->cte_number === $analyzed->cte_number
            && $current->protocol === $analyzed->protocol
            && $current->xml_path === $analyzed->xml_path
            && $current->sha256 === $analyzed->sha256
            && $current->emitter === $analyzed->emitter
            && $current->execution_mode === $analyzed->execution_mode
            && $current->snapshot === $analyzed->snapshot;
    }

    /**
     * @param  array<string, mixed>  $analysis
     * @return list<string>
     */
    private function reserveCandidates(MdfeDocument $document, array $analysis): array
    {
        if (! $this->isActive($document)) {
            return [];
        }

        $conflicts = [];

        foreach ($analysis['candidate_cte_ids'] as $cteId) {
            if ($this->reserve($document, 'cte', $cteId)) {
                $conflicts[] = 'reservation_conflict';
            }
        }

        foreach ($analysis['candidate_ciot_ids'] as $ciotId) {
            if ($this->reserve($document, 'ciot', $ciotId)) {
                $conflicts[] = 'reservation_conflict';
            }
        }

        return array_values(array_unique($conflicts));
    }

    private function reserve(MdfeDocument $document, string $resourceType, int $resourceId): bool
    {
        $reservation = DfeDocumentReservation::query()
            ->where('resource_type', $resourceType)
            ->where('resource_id', $resourceId)
            ->lockForUpdate()
            ->first();

        if ($reservation !== null) {
            return $reservation->mdfe_document_id !== $document->id;
        }

        try {
            DfeDocumentReservation::query()->create([
                'resource_type' => $resourceType,
                'resource_id' => $resourceId,
                'mdfe_document_id' => $document->id,
            ]);
        } catch (QueryException $exception) {
            $reservation = DfeDocumentReservation::query()
                ->where('resource_type', $resourceType)
                ->where('resource_id', $resourceId)
                ->first();

            if ($reservation === null) {
                throw $exception;
            }

            return $reservation->mdfe_document_id !== $document->id;
        }

        return false;
    }

    private function isActive(MdfeDocument $document): bool
    {
        if (in_array($document->status, [
            CteDocumentStatusEnum::DRAFT,
            CteDocumentStatusEnum::DRY_RUN_COMPLETED,
            CteDocumentStatusEnum::REJECTED,
            CteDocumentStatusEnum::FAILED_BEFORE_AUTHORIZATION,
            CteDocumentStatusEnum::CANCELLED,
        ], true)) {
            return false;
        }

        return ! in_array($document->fiscal_status, [
            FiscalDocumentStatusEnum::CANCELLED,
            FiscalDocumentStatusEnum::CLOSED,
        ], true);
    }

    /** @param array<string, mixed> $snapshot @return list<string> */
    private function snapshotCteKeys(array $snapshot): array
    {
        $keys = is_array($snapshot['cte_access_keys'] ?? null) ? $snapshot['cte_access_keys'] : [];

        foreach ((array) ($snapshot['destinations'] ?? []) as $destination) {
            if (is_array($destination) && is_array($destination['cte_access_keys'] ?? null)) {
                $keys = array_merge($keys, $destination['cte_access_keys']);
            }
        }

        $keys = array_values(array_filter(array_map(
            static fn (mixed $key): string => trim((string) $key),
            $keys,
        )));

        return array_values($keys);
    }

    private function fingerprint(MdfeDocument $document): string
    {
        return hash('sha256', json_encode([
            'batch_id' => $document->cte_emission_batch_id,
            'ciot_id' => $document->ciot_id,
            'access_key' => $document->access_key,
            'series' => $document->series,
            'mdfe_number' => $document->mdfe_number,
            'protocol' => $document->protocol,
            'fiscal_status_code' => $document->fiscal_status_code,
            'sha256' => $document->sha256,
            'xml_path' => $document->xml_path,
            'snapshot' => $document->snapshot,
            'emitter' => $document->emitter,
            'execution_mode' => $document->execution_mode,
            'status' => $document->status instanceof \BackedEnum ? $document->status->value : $document->status,
            'fiscal_status' => $document->fiscal_status instanceof \BackedEnum
                ? $document->fiscal_status->value
                : $document->fiscal_status,
        ], JSON_THROW_ON_ERROR));
    }

    private function snapshotIssuerCnpj(CteDocument|MdfeDocument $model): ?string
    {
        $snapshot = is_array($model->snapshot) ? $model->snapshot : [];
        $emit = is_array($snapshot['emit'] ?? null) ? $snapshot['emit'] : [];
        $emitter = is_array($snapshot['emitter'] ?? null) ? $snapshot['emitter'] : [];

        foreach ([
            $snapshot['emitter_cnpj'] ?? null,
            $snapshot['issuer_cnpj'] ?? null,
            $snapshot['ciot_responsible_cnpj'] ?? null,
            $emit['cnpj'] ?? $emit['CNPJ'] ?? null,
            $emitter['cnpj'] ?? $emitter['CNPJ'] ?? null,
        ] as $value) {
            if (is_string($value) || is_int($value)) {
                $normalized = preg_replace('/\D/', '', (string) $value);

                if ($normalized !== '') {
                    return $normalized;
                }
            }
        }

        return null;
    }

    private function snapshotEnvironment(CteDocument|MdfeDocument $model): ?string
    {
        $snapshot = is_array($model->snapshot) ? $model->snapshot : [];
        $environment = $snapshot['tpAmb'] ?? $snapshot['tp_amb'] ?? null;

        return is_string($environment) || is_int($environment)
            ? trim((string) $environment)
            : null;
    }

    /** @param array<int, string> $keys @return list<int> */
    private function candidateCteIds(MdfeDocument $document, array $keys): array
    {
        $records = new Collection;

        if ($keys !== []) {
            $records = $records->merge(CteDocument::query()
                ->whereIn('access_key', array_unique($keys), 'and', false)
                ->get());
        }

        if ($document->cte_emission_batch_id !== null) {
            $records = $records->merge(CteDocument::query()
                ->where('cte_emission_batch_id', $document->cte_emission_batch_id)
                ->whereNotNull('access_key')
                ->get());
        }

        $records = $records->merge($document->cteDocuments()->get());

        return $records
            ->filter(fn (CteDocument $cteDocument): bool => $this->isPotentialCte($cteDocument))
            ->pluck('id')
            ->unique()
            ->map(static fn (mixed $id): int => (int) $id)
            ->values()
            ->all();
    }

    /** @param array<int, string> $numbers @return list<int> */
    private function candidateCiotIds(MdfeDocument $document, array $numbers): array
    {
        $records = new Collection;

        if ($numbers !== []) {
            $records = $records->merge(Ciot::query()
                ->whereIn('ciot_number', array_unique($numbers), 'and', false)
                ->get());
        }

        if ($document->cte_emission_batch_id !== null) {
            $records = $records->merge(Ciot::query()
                ->where('cte_emission_batch_id', $document->cte_emission_batch_id)
                ->whereNotNull('ciot_number')
                ->get());
        }

        return $records
            ->filter(fn (Ciot $ciot): bool => in_array($ciot->status, [CiotStatusEnum::PENDING, CiotStatusEnum::ISSUED], true))
            ->pluck('id')
            ->unique()
            ->map(static fn (mixed $id): int => (int) $id)
            ->values()
            ->all();
    }

    private function isPotentialCte(CteDocument $document): bool
    {
        if (in_array($document->status, [
            CteDocumentStatusEnum::DRAFT,
            CteDocumentStatusEnum::DRY_RUN_COMPLETED,
            CteDocumentStatusEnum::REJECTED,
            CteDocumentStatusEnum::FAILED_BEFORE_AUTHORIZATION,
            CteDocumentStatusEnum::CANCELLED,
        ], true)) {
            return false;
        }

        return $document->fiscal_status !== FiscalDocumentStatusEnum::CANCELLED;
    }

    private function normalizeCiotNumber(mixed $number): ?string
    {
        if (! is_string($number) && ! is_int($number)) {
            return null;
        }

        $number = trim((string) $number);

        return preg_match('/^\d{12}$/', $number) === 1 ? $number : null;
    }

    private function hasDocumentKey(string $accessKey, string $expectedModel): bool
    {
        return preg_match('/^\d{44}$/', $accessKey) === 1
            && substr($accessKey, 20, 2) === $expectedModel
            && AccessKey::mod11(substr($accessKey, 0, 43)) === substr($accessKey, 43, 1);
    }

    /**
     * @param  array{cUF: string, cCT: string, model: string, series: string, number: string, tpEmis: string, cDV: string}  $identity
     */
    private function identityMatchesAccessKey(string $accessKey, array $identity): bool
    {
        return $identity['cUF'] === substr($accessKey, 0, 2)
            && $identity['model'] === substr($accessKey, 20, 2)
            && $this->sameNumericIdentity($identity['series'], substr($accessKey, 22, 3))
            && $this->sameNumericIdentity($identity['number'], substr($accessKey, 25, 9))
            && $identity['tpEmis'] === substr($accessKey, 34, 1)
            && $identity['cCT'] === substr($accessKey, 35, 8)
            && $identity['cDV'] === substr($accessKey, 43, 1);
    }

    /**
     * @param  array{cUF: string, cCT: string, model: string, series: string, number: string, tpEmis: string, cDV: string}  $identity
     */
    private function internalIdentityMatches(CteDocument|MdfeDocument $model, array $identity): ?bool
    {
        $number = $model instanceof CteDocument ? $model->cte_number : $model->mdfe_number;

        if (blank($model->series) || blank($number)) {
            return null;
        }

        return $this->sameNumericIdentity((string) $model->series, $identity['series'])
            && $this->sameNumericIdentity((string) $number, $identity['number']);
    }

    private function sameNumericIdentity(string $left, string $right): bool
    {
        return ltrim($left, '0') === ltrim($right, '0')
            || (ltrim($left, '0') === '' && ltrim($right, '0') === '');
    }

    /** @return list<array{cte_document_id: int, position: int}> */
    private function existingCteLinks(MdfeDocument $document, bool $lock = false): array
    {
        $query = DB::table('cte_document_mdfe_document')
            ->where('mdfe_document_id', $document->id)
            ->orderBy('cte_document_id');

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->get(['cte_document_id', 'position'])
            ->map(static fn (object $link): array => [
                'cte_document_id' => (int) $link->cte_document_id,
                'position' => (int) $link->position,
            ])
            ->all();
    }

    private function procHashMatches(?string $path, string $expectedSha256): bool
    {
        if (blank($path) || ! $this->documentStore->exists($path)) {
            return false;
        }

        try {
            return hash_equals($expectedSha256, hash('sha256', $this->documentStore->read($path)));
        } catch (Throwable) {
            return false;
        }
    }

    /** @param list<string> $reasons */
    private function hasApplyDivergence(array $reasons): bool
    {
        foreach ($reasons as $reason) {
            if ($reason === self::RESERVATION_CONFLICT
                || $reason === 'existing_ciot_link_conflict'
                || str_ends_with($reason, '_changed_during_backfill')) {
                return true;
            }
        }

        return false;
    }

    /** @param list<string> $values @return list<string> */
    private function sortedValues(array $values): array
    {
        sort($values);

        return $values;
    }

    private function firstChild(DOMElement $parent, string $name): ?DOMElement
    {
        $match = null;

        foreach ($parent->childNodes as $child) {
            if (! $child instanceof DOMElement || $child->localName !== $name) {
                continue;
            }

            if ($child->namespaceURI !== $parent->namespaceURI) {
                throw new DomainException("The proc XML {$name} element has an unexpected namespace.");
            }

            if ($match !== null) {
                throw new DomainException("The proc XML contains duplicate {$name} elements.");
            }

            $match = $child;
        }

        return $match;
    }

    private function childText(DOMElement $parent, string $name): ?string
    {
        $element = $this->firstChild($parent, $name);

        return $element === null ? null : trim($element->textContent);
    }

    private function firstDescendant(DOMElement $parent, string $name): ?DOMElement
    {
        $matches = $this->descendantsNamed($parent, $name);

        if (count($matches) > 1) {
            throw new DomainException("The proc XML contains duplicate {$name} elements.");
        }

        return $matches[0] ?? null;
    }

    /** @return list<DOMElement> */
    private function descendantsNamed(DOMElement $parent, string $name): array
    {
        $matches = [];

        foreach ($parent->childNodes as $child) {
            if (! $child instanceof DOMElement) {
                continue;
            }

            if ($child->localName === $name) {
                if ($child->namespaceURI !== $parent->namespaceURI) {
                    throw new DomainException("The proc XML {$name} element has an unexpected namespace.");
                }

                $matches[] = $child;
            }

            $matches = array_merge($matches, $this->descendantsNamed($child, $name));
        }

        return $matches;
    }
}
