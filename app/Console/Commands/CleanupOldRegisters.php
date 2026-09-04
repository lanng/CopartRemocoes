<?php

namespace App\Console\Commands;

use App\Models\CteEmissionBatch;
use App\Models\Register;
use App\Services\Payments\DetachPaymentBatchItemsFromRegister;
use Carbon\Carbon;
use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class CleanupOldRegisters extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:cleanup-old-registers';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Deletes registers with "paid" or "cancelled" status older than 15 days and their S3 files.';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('Starting cleanup of old paid and cancelled registers...');

        $cutoffDate = Carbon::now()->subDays(15);
        Log::info('cleanup-old-registers: iniciando.', ['cutoff' => $cutoffDate->toDateTimeString()]);

        try {
            $registersToDelete = Register::whereIn('status', ['paid', 'cancelled'])->where('updated_at', '<=', $cutoffDate)->get();

            if ($registersToDelete->isEmpty()) {
                Log::info('cleanup-old-registers: nada a limpar, concluído.');
                $this->info('No old registers to clean up. All done!');

                return 0;
            }

            Log::info("cleanup-old-registers: {$registersToDelete->count()} registro(s) elegível(eis).");
            $this->info("Found {$registersToDelete->count()} register(s) to delete.");

            $deletedCount = 0;

            foreach ($registersToDelete as $register) {
                try {
                    $wasDeleted = DB::transaction(function () use ($cutoffDate, $register): bool {
                        $lockedRegister = Register::query()
                            ->whereKey($register->id)
                            ->whereIn('status', ['paid', 'cancelled'])
                            ->where('updated_at', '<=', $cutoffDate)
                            ->lockForUpdate()
                            ->first();

                        if (! $lockedRegister) {
                            return false;
                        }

                        $batchIds = $lockedRegister->cteDocuments()->pluck('cte_emission_batch_id')->unique();

                        app(DetachPaymentBatchItemsFromRegister::class)->handle($lockedRegister);
                        $lockedRegister->cteDocuments()->delete();
                        $lockedRegister->delete();

                        CteEmissionBatch::query()
                            ->whereIn('id', $batchIds)
                            ->doesntHave('documents')
                            ->delete();

                        return true;
                    });

                    if (! $wasDeleted) {
                        continue;
                    }

                    $deletedCount++;
                    $this->line("Deleted register #{$register->id} (Plate: {$register->vehicle_plate}, Status: {$register->status->value})");
                } catch (Exception $e) {
                    Log::error("cleanup-old-registers: falha ao deletar o registro #{$register->id}.", [
                        'plate' => $register->vehicle_plate,
                        'exception' => (string) $e,
                    ]);
                    $this->error("Failed to delete register #{$register->id} ".$e->getMessage());
                }
            }

            Log::info('cleanup-old-registers: concluído.', [
                'found' => $registersToDelete->count(),
                'deleted' => $deletedCount,
            ]);
            $this->info("Cleanup complete. Successfully deleted {$deletedCount} register(s).");

            return 0;
        } catch (Throwable $e) {
            Log::error('cleanup-old-registers: execução interrompida por exceção.', ['exception' => (string) $e]);

            return self::FAILURE;
        }
    }
}
