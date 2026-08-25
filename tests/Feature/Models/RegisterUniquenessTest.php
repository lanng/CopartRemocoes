<?php

namespace Tests\Feature\Models;

use App\Models\IntegrationInboxItem;
use App\Models\Register;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegisterUniquenessTest extends TestCase
{
    use RefreshDatabase;

    public function test_millan_vehicle_ids_are_not_required_to_be_unique(): void
    {
        Register::factory()->create([
            'company' => 'millan',
            'vehicle_id' => '1111',
        ]);
        Register::factory()->create([
            'company' => 'millan',
            'vehicle_id' => '1111',
        ]);

        $this->assertDatabaseCount('registers', 2);
    }

    public function test_cte_companies_still_require_unique_vehicle_ids(): void
    {
        Register::factory()->create([
            'company' => 'copart',
            'vehicle_id' => '1111',
        ]);

        $this->expectException(UniqueConstraintViolationException::class);

        Register::factory()->create([
            'company' => 'copart',
            'vehicle_id' => '1111',
        ]);
    }

    public function test_register_persists_pdf_hash_and_exposes_unresolved_removal_imports(): void
    {
        $register = Register::factory()->create([
            'pdf_sha256' => str_repeat('b', 64),
            'fipe_value' => '1234.5',
        ]);

        $pendingRemoval = IntegrationInboxItem::factory()->create([
            'register_id' => $register->id,
            'message_type' => 'removal_request',
            'status' => 'pending',
            'resolved_at' => null,
        ]);
        $alertRemoval = IntegrationInboxItem::factory()->create([
            'register_id' => $register->id,
            'message_type' => 'removal_request',
            'status' => 'alert',
            'resolved_at' => null,
        ]);
        IntegrationInboxItem::factory()->create([
            'register_id' => $register->id,
            'message_type' => 'checklist',
            'status' => 'pending',
            'resolved_at' => null,
        ]);
        IntegrationInboxItem::factory()->create([
            'register_id' => $register->id,
            'message_type' => 'removal_request',
            'status' => 'processed',
            'resolved_at' => null,
        ]);
        IntegrationInboxItem::factory()->create([
            'register_id' => $register->id,
            'message_type' => 'removal_request',
            'status' => 'alert',
            'resolved_at' => now(),
        ]);

        $reloadedRegister = Register::query()->findOrFail($register->id);

        $this->assertSame(str_repeat('b', 64), $reloadedRegister->pdf_sha256);
        $this->assertSame('1234.50', $reloadedRegister->fipe_value);
        $this->assertEqualsCanonicalizing(
            [$pendingRemoval->id, $alertRemoval->id],
            $reloadedRegister->unresolvedRemovalImports->modelKeys(),
        );
    }
}
