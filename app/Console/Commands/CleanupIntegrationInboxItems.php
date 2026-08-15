<?php

namespace App\Console\Commands;

use App\Models\IntegrationInboxItem;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class CleanupIntegrationInboxItems extends Command
{
    protected $signature = 'app:cleanup-integration-inbox-items';

    protected $description = 'Remove baixas por e-mail processadas há mais de 30 dias.';

    public function handle(): int
    {
        $cutoff = Carbon::now()->subDays(30);
        $deleted = IntegrationInboxItem::query()
            ->whereIn('status', ['processed', 'duplicate'])
            ->whereRaw('COALESCE(resolved_at, updated_at) <= ?', [$cutoff])
            ->delete();

        $this->info("{$deleted} baixa(s) por e-mail removida(s).");

        return self::SUCCESS;
    }
}
