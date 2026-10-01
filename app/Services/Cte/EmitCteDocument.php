<?php

namespace App\Services\Cte;

use App\Enums\CteDocumentStatusEnum;
use App\Enums\CteEmissionBatchStatusEnum;
use App\Models\CteDocument;
use App\Services\Dfe\SefazCertificate;
use App\Services\Dfe\SefazException;
use App\Services\Dfe\SefazSoapClient;
use App\Services\Dfe\XmlSigner;
use App\Services\Dfe\XsdValidator;
use DomainException;

/**
 * Seam de emissão direta do CT-e (spec dfe §4.2, espelho do EmitCiotDeclaration):
 * snapshot v2 → BuildCteXml → assinar → validar XSD → transmitir → registrar.
 * Falhas de regra/dados (DomainException) são terminais; erros retryable da
 * SEFAZ sobem para o job re-tentar com o documento em authorizing.
 *
 * GUARD DE RETRANSMISSÃO (regra "o número queima sempre"): os artifacts do
 * documento (chave, número, XML assinado) são persistidos ANTES de transmitir.
 * Se um retry encontrar artifacts (a tentativa anterior pode ter autorizado na
 * SEFAZ antes de um crash), o attempt CONSULTA a chave queimada via
 * CTeConsultaV4: autorizada → registra o protocolo sem retransmitir; não
 * autorizada → a chave está de fato livre e um novo documento é construído
 * (queimando o próximo número). Nunca reusar número de tentativa anterior.
 */
class EmitCteDocument
{
    public function __construct(
        protected ResolveCteSnapshot $snapshotResolver,
        protected BuildCteXml $builder,
        protected SefazSoapClient $client,
        protected RecordDirectCteResult $recorder,
    ) {}

    /**
     * Contrato bruto compartilhado com o EmitCteDirectJob. Não captura nada.
     *
     * @throws DomainException violação de regra/guard — terminal
     * @throws SefazException retryable — o job re-tenta
     */
    public function attempt(CteDocument $document): CteDocument
    {
        $this->assertEmissionRules($document);

        if ($document->status === CteDocumentStatusEnum::QUEUED) {
            app(DirectCteDocumentWorkflow::class)->transition($document, CteDocumentStatusEnum::AUTHORIZING);
            $document->refresh();
        }

        if ($this->hasArtifactsFromPreviousAttempt($document)) {
            $consult = $this->client->consult((string) $document->access_key);

            if ($consult->isAuthorized()) {
                return $this->recorder->handle(
                    $document,
                    $this->builtFromArtifacts($document),
                    (string) $document->payload_xml,
                    $consult,
                );
            }

            // Consulta não autorizada: a chave queimada está de fato livre —
            // um novo documento é construído abaixo (queimando o próximo número).
        }

        $snapshot = $this->snapshotResolver->handle($document);

        $built = $this->builder->handle($snapshot);

        $signedXml = $this->signer()->sign(
            $built->xml,
            'infCte',
            'http://www.portalfiscal.inf.br/cte',
            'CTe'.$built->accessKey,
        );

        app(XsdValidator::class)->validateCte($signedXml);

        // Persiste os artifacts ANTES de transmitir: se o processo morrer
        // depois de a SEFAZ autorizar, o retry consulta esta chave e recupera
        // o protocolo em vez de queimar um novo número às cegas.
        $document->forceFill([
            'cte_number' => (string) $built->number,
            'access_key' => $built->accessKey,
            'series' => (string) $built->serie,
            'payload_xml' => $signedXml,
            'digest_value' => $this->digestOf($signedXml),
            'response_xml' => null,
        ])->save();

        $response = $this->client->transmit($signedXml);

        return $this->recorder->handle($document, $built, $signedXml, $response);
    }

    protected function hasArtifactsFromPreviousAttempt(CteDocument $document): bool
    {
        return filled($document->access_key) && filled($document->payload_xml);
    }

    protected function builtFromArtifacts(CteDocument $document): BuiltCte
    {
        return new BuiltCte(
            xml: (string) $document->payload_xml,
            accessKey: (string) $document->access_key,
            number: (int) $document->cte_number,
            serie: (int) $document->series,
            cct: substr((string) $document->access_key, 34, 8),
        );
    }

    protected function assertEmissionRules(CteDocument $document): void
    {
        $allowed = [CteDocumentStatusEnum::QUEUED, CteDocumentStatusEnum::AUTHORIZING];

        if (! in_array($document->status, $allowed, true)) {
            throw new DomainException("Somente documentos queued ou authorizing podem ser emitidos (atual: {$document->status->value}).");
        }

        if ($document->emitter !== 'direct') {
            throw new DomainException('Documento não pertence ao emissor direto.');
        }

        if ($document->execution_mode !== 'live') {
            throw new DomainException('Somente documentos em modo live são transmitidos à SEFAZ.');
        }

        if ($document->batch?->status !== CteEmissionBatchStatusEnum::APPROVED) {
            throw new DomainException('Somente lotes aprovados podem ser emitidos.');
        }
    }

    protected function signer(): XmlSigner
    {
        return new XmlSigner(app(SefazCertificate::class));
    }

    protected function digestOf(string $signedXml): ?string
    {
        return preg_match('/<DigestValue>([^<]*)<\/DigestValue>/', $signedXml, $matches) === 1
            ? $matches[1]
            : null;
    }
}
