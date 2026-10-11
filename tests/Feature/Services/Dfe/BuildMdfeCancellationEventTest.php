<?php

namespace Tests\Feature\Services\Dfe;

use App\Models\MdfeDocument;
use App\Services\Dfe\BuildMdfeCancellationEvent;
use App\Services\Dfe\SefazCertificate;
use App\Services\Dfe\XmlSigner;
use App\Services\Dfe\XsdValidator;
use Carbon\CarbonImmutable;
use DomainException;
use Tests\Support\DfeTestCertificate;
use Tests\TestCase;

class BuildMdfeCancellationEventTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'dfe.tp_amb' => '2',
            'dfe.cuf' => '35',
            'dfe.emit.cnpj' => '12563112000130',
            'dfe.mdfe.xsd_path' => resource_path('xsds/mdfe'),
        ]);
    }

    public function test_builds_and_signs_a_schema_valid_mdfe_cancellation_event(): void
    {
        $accessKey = str_repeat('2', 44);
        $document = new MdfeDocument([
            'access_key' => $accessKey,
            'protocol' => '935260000000001',
        ]);
        $eventDate = CarbonImmutable::parse('2026-10-07T14:45:00-03:00');

        $built = app(BuildMdfeCancellationEvent::class)->handle(
            $document,
            'Cancelamento solicitado pelo operador.',
            $eventDate,
        );

        $this->assertSame('ID110111'.$accessKey.'01', $built->eventId);
        $this->assertSame($eventDate->toIso8601String(), $built->eventDate->toIso8601String());
        $this->assertStringContainsString('<nProt>935260000000001</nProt>', $built->xml);
        $this->assertStringContainsString('<xJust>Cancelamento solicitado pelo operador.</xJust>', $built->xml);
        $this->assertStringContainsString('<nSeqEvento>1</nSeqEvento>', $built->xml);

        $certificate = DfeTestCertificate::create();
        $signedXml = (new XmlSigner(new SefazCertificate($certificate['path'], $certificate['password'])))->sign(
            $built->xml,
            'infEvento',
            'http://www.portalfiscal.inf.br/mdfe',
            $built->eventId,
        );

        app(XsdValidator::class)->validateMdfeEvent($signedXml);
        $this->assertTrue(true);
    }

    public function test_rejects_a_mdfe_key_or_authorization_protocol_with_an_invalid_shape(): void
    {
        $this->expectException(DomainException::class);

        app(BuildMdfeCancellationEvent::class)->handle(
            new MdfeDocument([
                'access_key' => 'invalid-key',
                'protocol' => '123',
            ]),
            'Cancelamento solicitado pelo operador.',
        );
    }

    public function test_rejects_a_mdfe_justification_outside_the_schema_length(): void
    {
        $this->expectException(DomainException::class);

        app(BuildMdfeCancellationEvent::class)->handle(
            new MdfeDocument([
                'access_key' => str_repeat('2', 44),
                'protocol' => '935260000000001',
            ]),
            'curto',
        );
    }
}
