<?php

namespace App\Services\MicrosoftGraph;

use App\Enums\RegisterStatusEnum;
use App\Models\IntegrationInboxItem;
use App\Models\Register;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

class ResolveIntegrationInboxItem
{
    public function handle(IntegrationInboxItem $item, Register $register, User $user, string $reason): IntegrationInboxItem
    {
        return DB::transaction(function () use ($item, $register, $user, $reason): IntegrationInboxItem {
            if ($item->status !== 'pending') {
                throw new DomainException('Somente pendencias podem ser conciliadas.');
            }

            $register = Register::query()->lockForUpdate()->findOrFail($register->id);
            $plate = strtoupper(str_replace('-', '', $register->vehicle_plate));
            $isAssociatedRegister = (int) $register->id === (int) $item->register_id;

            if ($register->company?->value !== 'copart'
                || (string) $register->vehicle_id !== (string) $item->extracted_vehicle_id
                || ($plate !== strtoupper((string) $item->extracted_vehicle_plate) && ! $isAssociatedRegister)) {
                throw new DomainException('O registro selecionado nao corresponde aos dados extraidos.');
            }

            $register->forceFill([
                'delivery_confirmed_at' => $register->delivery_confirmed_at ?? $item->received_at,
                'status' => RegisterStatusEnum::DELIVERED,
            ])->save();

            $item->forceFill([
                'register_id' => $register->id,
                'status' => 'processed',
                'failure_reason' => $reason,
                'resolved_by' => $user->id,
                'resolved_at' => now(),
            ])->save();

            return $item->refresh();
        });
    }
}
