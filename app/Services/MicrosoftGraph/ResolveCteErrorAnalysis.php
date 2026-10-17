<?php

namespace App\Services\MicrosoftGraph;

use App\Enums\RegisterStatusEnum;
use App\Models\IntegrationInboxItem;
use App\Models\Register;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

class ResolveCteErrorAnalysis
{
    public function handle(IntegrationInboxItem $item, User $user, string $decision): IntegrationInboxItem
    {
        return DB::transaction(function () use ($item, $user, $decision): IntegrationInboxItem {
            $register = Register::query()->lockForUpdate()->find($item->register_id);
            $item = IntegrationInboxItem::query()->lockForUpdate()->findOrFail($item->id);

            if (! $item->isCteError() || ! $item->requiresAttention()) {
                throw new DomainException('Somente avisos de erro de CT-e ainda pendentes podem ser revisados.');
            }

            if ($decision === 'manual_review' && $item->register_id === null) {
                $item->forceFill([
                    'status' => 'processed',
                    'resolved_by' => $user->id,
                    'resolved_at' => now(),
                    'acknowledged_by' => $user->id,
                    'acknowledged_at' => now(),
                ])->save();

                return $item->refresh();
            }

            if (! in_array($decision, ['acknowledge', 'false_positive'], true)
                || $register === null
                || $register->id !== $item->register_id
                || $register->status !== RegisterStatusEnum::ANALYSIS) {
                throw new DomainException('Somente erros de CT-e associados a um registro em análise podem ser revisados.');
            }

            if ($decision === 'acknowledge') {
                $item->forceFill([
                    'acknowledged_by' => $item->acknowledged_by ?? $user->id,
                    'acknowledged_at' => $item->acknowledged_at ?? now(),
                ])->save();

                return $item->refresh();
            }

            $previousStatus = RegisterStatusEnum::tryFrom((string) $item->previous_register_status);
            $hasOtherErrors = $register->integrationInboxItems()->openRegisterAnalyses()
                ->where('message_type', 'cte_error')->where('id', '!=', $item->id)->exists();

            if (! $hasOtherErrors && $register->delivery_confirmed_at === null
                && ($previousStatus === null || $previousStatus === RegisterStatusEnum::ANALYSIS)) {
                throw new DomainException('O status anterior não está disponível. Altere o status do registro manualmente.');
            }

            $item->forceFill([
                'status' => 'processed',
                'failure_reason' => 'cte_error_false_positive',
                'resolved_by' => $user->id,
                'resolved_at' => now(),
                'acknowledged_by' => $item->acknowledged_by ?? $user->id,
                'acknowledged_at' => $item->acknowledged_at ?? now(),
            ])->save();

            if (! $hasOtherErrors) {
                $register->update([
                    'status' => $register->delivery_confirmed_at !== null ? RegisterStatusEnum::DELIVERED : $previousStatus,
                ]);
            }

            return $item->refresh();
        });
    }

    public function completeForRegister(Register $register): void
    {
        $register->integrationInboxItems()->openRegisterAnalyses()->get()
            ->each(function (IntegrationInboxItem $item): void {
                $item->forceFill([
                    'status' => 'processed',
                    'failure_reason' => 'analysis_resolved',
                    'resolved_at' => now(),
                    'resolved_by' => auth()->id(),
                    'acknowledged_at' => $item->acknowledged_at ?? now(),
                    'acknowledged_by' => $item->acknowledged_by ?? auth()->id(),
                ])->save();
            });
    }
}
