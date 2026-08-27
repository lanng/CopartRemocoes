<?php

namespace App\Console\Commands;

use App\Models\IntegrationInboxItem;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

class CleanupIntegrationInboxItems extends Command
{
    protected $signature = 'app:cleanup-integration-inbox-items';

    protected $description = 'Remove integrações por e-mail resolvidas há mais de 30 dias.';

    public function handle(): int
    {
        $cutoff = Carbon::now()->subDays(30);
        $deleted = 0;

        IntegrationInboxItem::query()
            ->whereIn('status', ['processed', 'duplicate', 'no_changes', 'rejected'])
            ->where(function (Builder $query): void {
                $query->whereNull('delivery_alert')->orWhereNotNull('acknowledged_at');
            })
            ->whereRaw('COALESCE(resolved_at, updated_at) <= ?', [$cutoff])
            ->chunkById(100, function ($items) use (&$deleted): void {
                foreach ($items as $item) {
                    if ($item->candidate_pdf_path) {
                        Storage::disk('s3')->delete($item->candidate_pdf_path);
                    }

                    $item->delete();
                    $deleted++;
                }
            });

        $this->info("{$deleted} integração(ões) por e-mail removida(s).");

        return self::SUCCESS;
    }
}
