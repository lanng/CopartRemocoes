<?php

namespace App\Console\Commands;

use App\Models\Register;
use Carbon\Carbon;
use Exception;
use Illuminate\Console\Command;

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
    public function handle()
    {
        $this->info('Starting cleanup of old paid and cancelled registers...');

        $cutoffDate = Carbon::now()->subDays(15);

        $registersToDelete = Register::whereIn('status', ['paid', 'cancelled'])->where('updated_at', '<=', $cutoffDate)->get();

        if ($registersToDelete->isEmpty()) {
            $this->info('No old registers to clean up. All done!');

            return 0;
        }

        $count = $registersToDelete->count();
        $this->info("Found {$count} register(s) to delete.");

        foreach ($registersToDelete as $register) {
            $this->line("Deleting register #{$register->id} (Plate: {$register->vehicle_plate}, Status: {$register->status->value}");

            try {
                $register->delete();
            } catch (Exception $e) {
                $this->error("Failed to delete register #{$register->id} ".$e->getMessage());
            }
        }

        $this->info("Cleanup complete. Successfully deleted {$count} register(s).");

        return 0;
    }
}
