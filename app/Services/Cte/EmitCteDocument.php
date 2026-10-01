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

        $snapshot = $this->snapshotResolver->handle($document);

        $built = $this->builder->handle($snapshot);

        $signedXml = $this->signer()->sign(
            $built->xml,
            'infCte',
            'http://www.portalfiscal.inf.br/cte',
            'CTe'.$built->accessKey,
        );

        app(XsdValidator::class)->validateCte($signedXml);

        $response = $this->client->transmit($signedXml);

        return $this->recorder->handle($document, $built, $signedXml, $response);
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
}
