<?php

namespace App\Filament\Resources\RegisterResource\Pages;

use App\Filament\Resources\RegisterResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

class ListRegisters extends ListRecords
{
    protected static string $resource = RegisterResource::class;

    protected static string $view = 'filament.resources.register-resource.pages.list-registers';

    protected function applyGlobalSearchToTableQuery(Builder $query): Builder
    {
        $plates = $this->extractPlateList($this->getTableSearch() ?? '');

        if ($plates === null) {
            return parent::applyGlobalSearchToTableQuery($query);
        }

        return $query->where(function (Builder $query) use ($plates): void {
            foreach ($plates as $plate) {
                $query->orWhereRaw("UPPER(REPLACE(vehicle_plate, '-', '')) = ?", [$plate]);
            }
        });
    }

    /** @return array<string>|null */
    private function extractPlateList(string $search): ?array
    {
        $tokens = preg_split('/[\\s,;]+/u', $search, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $plates = [];

        foreach ($tokens as $token) {
            $plate = strtoupper(str_replace('-', '', $token));

            if (preg_match('/^[A-Z]{3}[0-9][A-Z0-9][0-9]{2}$/', $plate) !== 1) {
                return null;
            }

            $plates[] = $plate;
        }

        return count($plates) >= 2 ? array_values(array_unique($plates)) : null;
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
