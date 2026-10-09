<?php

namespace App\Services\Dfe;

use App\Models\CteDocument;
use DomainException;
use Illuminate\Support\Collection;

class AssertCteDocumentsEligibleForMdfe
{
    /** @param Collection<int, CteDocument> $documents */
    public function handle(Collection $documents): void
    {
        if ($documents->contains(fn (CteDocument $document): bool => ! $document->isEligibleForNewMdfe())) {
            throw new DomainException('A seleção aceita somente CT-es do fluxo controlado pelo emissor direto.');
        }
    }
}
