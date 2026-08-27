<?php

namespace Tests\Unit\MicrosoftGraph;

use App\Services\MicrosoftGraph\RemovalRequests\RemovalRequestSubjectParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class RemovalRequestSubjectParserTest extends TestCase
{
    public function test_it_parses_a_valid_original_subject(): void
    {
        $result = (new RemovalRequestSubjectParser)->parse(
            'Pedido de Remoção - FSG5551 - 1156340 - ALLIANZ SEGUROS S/A'
        );

        $this->assertSame([
            'vehicle_plate' => 'FSG5551',
            'vehicle_id' => '1156340',
            'insurance' => 'ALLIANZ SEGUROS S/A',
        ], $result);
    }

    public function test_it_parses_a_valid_mercosul_plate_with_flexible_spacing(): void
    {
        $result = (new RemovalRequestSubjectParser)->parse(
            "  pedido   DE remoção\t-  abc1d23 - 1156340 - Allianz\t   Seguros SA  "
        );

        $this->assertSame('ABC1D23', $result['vehicle_plate']);
        $this->assertSame('1156340', $result['vehicle_id']);
        $this->assertSame('Allianz Seguros SA', $result['insurance']);
    }

    #[DataProvider('invalidSubjectProvider')]
    public function test_it_rejects_non_original_or_malformed_subjects(string $subject): void
    {
        $this->assertNull((new RemovalRequestSubjectParser)->parse($subject));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidSubjectProvider(): array
    {
        return [
            'reply prefix' => ['RE: Pedido de Remoção - FSG5551 - 1156340 - Allianz'],
            'reply prefix with s' => ['RES: Pedido de Remoção - FSG5551 - 1156340 - Allianz'],
            'forward prefix' => ['FW: Pedido de Remoção - FSG5551 - 1156340 - Allianz'],
            'forward prefix in portuguese' => ['ENC: Pedido de Remoção - FSG5551 - 1156340 - Allianz'],
            'missing insurance' => ['Pedido de Remoção - FSG5551 - 1156340 -'],
            'punctuation-only hyphen insurance' => ['Pedido de Remoção - FSG5551 - 1156340 - -'],
            'punctuation-only slash insurance' => ['Pedido de Remoção - FSG5551 - 1156340 - /'],
            'multiline insurance' => ["Pedido de Remoção - FSG5551 - 1156340 - Allianz\nSeguros"],
            'missing vehicle id' => ['Pedido de Remoção - FSG5551 - - Allianz'],
            'old plate with wrong length' => ['Pedido de Remoção - FSG555 - 1156340 - Allianz'],
            'plate with invalid character' => ['Pedido de Remoção - FSG@551 - 1156340 - Allianz'],
            'plate with eight characters' => ['Pedido de Remoção - FSG55511 - 1156340 - Allianz'],
            'extra prefix text' => ['Aviso Pedido de Remoção - FSG5551 - 1156340 - Allianz'],
            'extra suffix after empty insurance' => ['Pedido de Remoção - FSG5551 - 1156340 -'],
        ];
    }
}
