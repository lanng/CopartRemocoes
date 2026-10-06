<?php

namespace App\Services\Cte;

use App\Enums\CteDocumentStatusEnum;
use App\Models\MdfeDocument;
use App\Services\Dfe\BuildMdfeXml;
use App\Services\Dfe\BuiltMdfe;
use App\Services\Dfe\MdfeSoapClient;
use App\Services\Dfe\SefazCertificate;
use App\Services\Dfe\XmlSigner;
use App\Services\Dfe\XsdValidator;
use DomainException;

class EmitMdfeDocument
{
    public function __construct(
        private readonly BuildMdfeXml $builder,
        private readonly MdfeSoapClient $client,
        private readonly RecordDirectMdfeResult $recorder,
    ) {}

    public function attempt(MdfeDocument $document): MdfeDocument
    {
        $this->assertEmissionRules($document);

        if ($document->status === CteDocumentStatusEnum::QUEUED) {
            app(DirectMdfeDocumentWorkflow::class)->transition($document, CteDocumentStatusEnum::AUTHORIZING);
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
        }

        $built = $this->builder->handle((array) $document->snapshot);
        $signedXml = $this->signer()->sign(
            $built->xml,
            'infMDFe',
            'http://www.portalfiscal.inf.br/mdfe',
            'MDFe'.$built->accessKey,
        );
        app(XsdValidator::class)->validateMdfe($signedXml);

        $document->forceFill([
            'mdfe_number' => (string) $built->number,
            'access_key' => $built->accessKey,
            'series' => (string) $built->series,
            'payload_xml' => $signedXml,
            'digest_value' => $this->digestOf($signedXml),
            'response_xml' => null,
        ])->save();

        return $this->recorder->handle($document, $built, $signedXml, $this->client->transmit($signedXml));
    }

    private function assertEmissionRules(MdfeDocument $document): void
    {
        if (! in_array($document->status, [CteDocumentStatusEnum::QUEUED, CteDocumentStatusEnum::AUTHORIZING], true)) {
            throw new DomainException("Somente MDF-e queued ou authorizing podem ser emitidos (atual: {$document->status->value}).");
        }

        if ($document->emitter !== 'direct' || $document->execution_mode !== 'live') {
            throw new DomainException('Somente MDF-e do emissor direto em modo live podem ser transmitidos.');
        }

        if ((string) config('dfe.tp_amb') === '1' && ! config('dfe.mdfe.production_enabled')) {
            throw new DomainException('A transmissão de MDF-e em produção está bloqueada por DFE_MDFE_PRODUCTION_ENABLED.');
        }
    }

    private function hasArtifactsFromPreviousAttempt(MdfeDocument $document): bool
    {
        return filled($document->access_key) && filled($document->payload_xml);
    }

    private function builtFromArtifacts(MdfeDocument $document): BuiltMdfe
    {
        return new BuiltMdfe(
            xml: (string) $document->payload_xml,
            accessKey: (string) $document->access_key,
            number: (int) $document->mdfe_number,
            series: (int) $document->series,
            numericCode: substr((string) $document->access_key, 35, 8),
        );
    }

    private function signer(): XmlSigner
    {
        return new XmlSigner(app(SefazCertificate::class));
    }

    private function digestOf(string $signedXml): ?string
    {
        return preg_match('/<DigestValue>([^<]*)<\/DigestValue>/', $signedXml, $matches) === 1 ? $matches[1] : null;
    }
}
