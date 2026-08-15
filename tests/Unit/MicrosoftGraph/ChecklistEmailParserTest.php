<?php

namespace Tests\Unit\MicrosoftGraph;

use App\Services\MicrosoftGraph\ChecklistEmailParser;
use PHPUnit\Framework\TestCase;

class ChecklistEmailParserTest extends TestCase
{
    public function test_it_extracts_the_vehicle_id_and_plate_from_a_valid_checklist_email(): void
    {
        $result = (new ChecklistEmailParser)->parse([
            'sender' => 'remocao@copart.com.br',
            'subject' => 'Checklist digital - 1146609',
            'body' => 'Segue anexo a checklist digital do veículo 1146609 - ESN4A20 - ALLIANZ SEGUROS S/A.',
        ]);

        $this->assertTrue($result['valid']);
        $this->assertSame('1146609', $result['vehicle_id']);
        $this->assertSame('ESN4A20', $result['vehicle_plate']);
    }

    public function test_it_rejects_when_subject_and_body_contain_different_ids(): void
    {
        $result = (new ChecklistEmailParser)->parse([
            'sender' => 'remocao@copart.com.br',
            'subject' => 'Checklist digital - 1146609',
            'body' => 'Segue anexo a checklist digital do veículo 9999999 - ESN4A20.',
        ]);

        $this->assertFalse($result['valid']);
        $this->assertSame('vehicle_id_mismatch', $result['reason']);
    }

    public function test_it_rejects_an_untrusted_sender_or_invalid_subject(): void
    {
        $result = (new ChecklistEmailParser)->parse([
            'sender' => 'outro@example.com',
            'subject' => 'Checklist digital - 1146609',
            'body' => 'Veículo 1146609 - ESN4A20.',
        ]);

        $this->assertFalse($result['valid']);
        $this->assertSame('untrusted_sender', $result['reason']);
    }
}
