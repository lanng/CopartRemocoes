<?php

namespace App\Console\Commands;

use App\Models\CteEmissionBatch;
use App\Models\Register;
use Carbon\Carbon;
use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

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

        $registersToDelete = Register::whereIn('status', ['paid', 'cancelled'])->where('updated_at', '<=', $cutoffDate)->get();

        if ($registersToDelete->isEmpty()) {
            $this->info('No old registers to clean up. All done!');

            return 0;
        }

        $this->info("Found {$registersToDelete->count()} register(s) to delete.");

        $deletedCount = 0;

        foreach ($registersToDelete as $register) {
            $batchIds = $register->cteDocuments()->pluck('cte_emission_batch_id')->unique();

            try {
                DB::transaction(function () use ($batchIds, $register): void {
                    $register->cteDocuments()->delete();
                    $register->delete();

                    CteEmissionBatch::query()
                        ->whereIn('id', $batchIds)
                        ->doesntHave('documents')
                        ->delete();
                });

                $deletedCount++;
                $this->line("Deleted register #{$register->id} (Plate: {$register->vehicle_plate}, Status: {$register->status->value})");
            } catch (Exception $e) {
                $this->error("Failed to delete register #{$register->id} ".$e->getMessage());
            }
        }

        $this->info("Cleanup complete. Successfully deleted {$deletedCount} register(s).");

        return 0;
    }
}
