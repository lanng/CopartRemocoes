<?php

namespace App\Filament\Resources\RegisterResource\Pages;

use App\Enums\RegisterStatusEnum;
use App\Filament\Resources\RegisterResource;
use App\Models\Register;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Enums\MaxWidth;
use Illuminate\Database\Eloquent\Builder;

class ListRegisters extends ListRecords
{
    /** @var array<int, string> */
    private const OPERATIONAL_FILTER_STATUSES = [
        RegisterStatusEnum::COLLECTED->value,
        RegisterStatusEnum::PENDING->value,
        RegisterStatusEnum::AVAILABLE->value,
        RegisterStatusEnum::PENDING_DAILY_RATES->value,
    ];

    protected static string $resource = RegisterResource::class;

    protected static string $view = 'filament.resources.register-resource.pages.list-registers';

    /** @var array<string, mixed> */
    public array $columnFilters = [];

    public function getMaxContentWidth(): MaxWidth
    {
        return MaxWidth::Full;
    }

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

    public function filterTableQuery(Builder $query): Builder
    {
        return $this->applyColumnFiltersToQuery(parent::filterTableQuery($query));
    }

    public function getTableQueryForExport(): Builder
    {
        return $this->applyColumnFiltersToQuery(parent::getTableQueryForExport());
    }

    /** @return array<int, string> */
    public function getOriginCityFilterOptions(): array
    {
        $query = parent::filterTableQuery($this->getTable()->getQuery());
        $cities = $this->applyColumnFiltersToQuery($query, includeOriginCities: false)
            ->whereIn('registers.status', self::OPERATIONAL_FILTER_STATUSES)
            ->reorder()
            ->select('registers.origin_city')
            ->whereNotNull('registers.origin_city')
            ->where('registers.origin_city', '!=', '')
            ->distinct()
            ->pluck('registers.origin_city');

        $selectedCities = $this->columnFilters['origin_cities'] ?? [];

        if (is_array($selectedCities) && $selectedCities !== []) {
            $availableCities = Register::query()
                ->whereIn('status', self::OPERATIONAL_FILTER_STATUSES)
                ->whereNotNull('origin_city')
                ->where('origin_city', '!=', '')
                ->distinct()
                ->pluck('origin_city')
                ->all();
            $cities = $cities->merge(array_filter(
                $selectedCities,
                fn (mixed $city): bool => is_string($city) && in_array($city, $availableCities, true),
            ));
        }

        return $cities->unique()->sort(SORT_NATURAL | SORT_FLAG_CASE)->values()->all();
    }

    /** @return array<int, string> */
    public function getDestinationCityFilterOptions(): array
    {
        $query = parent::filterTableQuery($this->getTable()->getQuery());
        $cities = $this->applyColumnFiltersToQuery($query, includeDestinationCities: false)
            ->whereIn('registers.status', self::OPERATIONAL_FILTER_STATUSES)
            ->reorder()
            ->select('registers.destination_city')
            ->whereNotNull('registers.destination_city')
            ->where('registers.destination_city', '!=', '')
            ->distinct()
            ->pluck('registers.destination_city');

        $selectedCities = $this->columnFilters['destination_cities'] ?? [];

        if (is_array($selectedCities) && $selectedCities !== []) {
            $availableCities = Register::query()
                ->whereIn('status', self::OPERATIONAL_FILTER_STATUSES)
                ->whereNotNull('destination_city')
                ->where('destination_city', '!=', '')
                ->distinct()
                ->pluck('destination_city')
                ->all();
            $cities = $cities->merge(array_filter(
                $selectedCities,
                fn (mixed $city): bool => is_string($city) && in_array($city, $availableCities, true),
            ));
        }

        return $cities->unique()->sort(SORT_NATURAL | SORT_FLAG_CASE)->values()->all();
    }

    public function updatedColumnFilters(): void
    {
        $this->resetPage();
        $this->deselectAllTableRecords();
        $this->flushCachedTableRecords();
    }

    public function hasActiveColumnFilters(): bool
    {
        foreach ($this->columnFilters as $filter) {
            if (is_array($filter) && collect($filter)->contains(fn (mixed $value): bool => filled($value))) {
                return true;
            }

            if (! is_array($filter) && filled($filter)) {
                return true;
            }
        }

        return false;
    }

    public function clearColumnFilters(): void
    {
        $this->columnFilters = [];
        $this->updatedColumnFilters();
    }

    public function clearColumnFilter(string $column): void
    {
        $colorAttribute = RegisterResource::spreadsheetColorAttributeForColumn($column);

        if ($column === 'origin_city') {
            $this->columnFilters['origin_city_color'] = null;
            $this->columnFilters['origin_cities'] = [];
        } elseif ($column === 'destination_city') {
            $this->columnFilters['destination_city_color'] = null;
            $this->columnFilters['destination_cities'] = [];
        } elseif ($colorAttribute !== null) {
            $this->columnFilters[$colorAttribute] = null;
        } else {
            match ($column) {
                'status', 'tow_yard' => $this->columnFilters[$column] = null,
                'deadline_withdraw', 'deadline_delivery' => $this->columnFilters[$column] = [
                    'from' => null,
                    'until' => null,
                ],
                'value' => $this->columnFilters[$column] = [
                    'minimum' => null,
                    'maximum' => null,
                ],
                default => null,
            };
        }

        $this->updatedColumnFilters();
    }

    public function toggleOriginCityFilter(string $city): void
    {
        $this->toggleCityFilter('origin_cities', $city, RegisterResource::spreadsheetOriginCityFilterOptions());
    }

    public function toggleDestinationCityFilter(string $city): void
    {
        $this->toggleCityFilter('destination_cities', $city, RegisterResource::spreadsheetDestinationCityFilterOptions());
    }

    /** @param array<int, string> $availableCities */
    private function toggleCityFilter(string $filter, string $city, array $availableCities): void
    {

        if (! in_array($city, $availableCities, true)) {
            return;
        }

        $selectedCities = $this->columnFilters[$filter] ?? [];
        $selectedCities = is_array($selectedCities)
            ? array_values(array_filter(
                $selectedCities,
                fn (mixed $selectedCity): bool => is_string($selectedCity) && in_array($selectedCity, $availableCities, true),
            ))
            : [];

        if (in_array($city, $selectedCities, true)) {
            $selectedCities = array_values(array_filter(
                $selectedCities,
                fn (string $selectedCity): bool => $selectedCity !== $city,
            ));
        } else {
            $selectedCities[] = $city;
        }

        $this->columnFilters[$filter] = $selectedCities;
        $this->updatedColumnFilters();
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

    private function applyColumnFiltersToQuery(
        Builder $query,
        bool $includeOriginCities = true,
        bool $includeDestinationCities = true,
    ): Builder {
        $deadlineAlertDate = today('America/Sao_Paulo')->toDateString();
        $hasActiveColorFilter = false;

        foreach (RegisterResource::SPREADSHEET_COLUMN_COLOR_ATTRIBUTES as $column => $attribute) {
            $color = $this->columnFilters[$attribute] ?? null;

            if (! is_string($color) || $color === '') {
                continue;
            }

            $options = RegisterResource::spreadsheetColumnColorOptions($column);

            if (! array_key_exists($color, $options)) {
                continue;
            }

            $hasActiveColorFilter = true;

            $deadlineAttribute = RegisterResource::SPREADSHEET_DEADLINE_COLOR_ATTRIBUTES[$attribute] ?? null;

            if ($color === 'automatic') {
                $query->where(function (Builder $query) use ($attribute, $deadlineAttribute, $deadlineAlertDate): void {
                    if ($deadlineAttribute === null) {
                        $query->whereNull($attribute)->orWhere($attribute, 'black');

                        return;
                    }

                    $query->where($attribute, 'black')
                        ->orWhere(function (Builder $query) use ($attribute, $deadlineAttribute, $deadlineAlertDate): void {
                            $query->whereNull($attribute)
                                ->where(function (Builder $query) use ($deadlineAttribute, $deadlineAlertDate): void {
                                    $query->whereNull($deadlineAttribute)
                                        ->orWhereDate($deadlineAttribute, '>', $deadlineAlertDate)
                                        ->orWhereNotIn('status', RegisterResource::SPREADSHEET_DEADLINE_ALERT_STATUSES);
                                });
                        });
                });

                continue;
            }

            if ($deadlineAttribute !== null && $color === 'red') {
                $query->where(function (Builder $query) use ($attribute, $deadlineAttribute, $deadlineAlertDate): void {
                    $query->where($attribute, 'red')
                        ->orWhere(function (Builder $query) use ($attribute, $deadlineAttribute, $deadlineAlertDate): void {
                            $query->whereNull($attribute)
                                ->whereDate($deadlineAttribute, '<=', $deadlineAlertDate)
                                ->whereIn('status', RegisterResource::SPREADSHEET_DEADLINE_ALERT_STATUSES);
                        });
                });

                continue;
            }

            $query->where($attribute, $color);
        }

        $hasActiveCityFilter = false;
        $originCities = $this->columnFilters['origin_cities'] ?? [];

        if ($includeOriginCities && is_array($originCities) && $originCities !== []) {
            $availableCities = RegisterResource::spreadsheetOriginCityFilterOptions();
            $originCities = array_values(array_filter(
                $originCities,
                fn (mixed $city): bool => is_string($city) && in_array($city, $availableCities, true),
            ));

            if ($originCities !== []) {
                $query->whereIn('origin_city', $originCities);
                $hasActiveCityFilter = true;
            }
        }

        $destinationCities = $this->columnFilters['destination_cities'] ?? [];

        if ($includeDestinationCities && is_array($destinationCities) && $destinationCities !== []) {
            $availableCities = RegisterResource::spreadsheetDestinationCityFilterOptions();
            $destinationCities = array_values(array_filter(
                $destinationCities,
                fn (mixed $city): bool => is_string($city) && in_array($city, $availableCities, true),
            ));

            if ($destinationCities !== []) {
                $query->whereIn('destination_city', $destinationCities);
                $hasActiveCityFilter = true;
            }
        }

        if ($hasActiveColorFilter || $hasActiveCityFilter) {
            $query->whereIn('status', self::OPERATIONAL_FILTER_STATUSES);
        }

        $status = $this->columnFilters['status'] ?? null;

        if (is_string($status) && RegisterStatusEnum::tryFrom($status) !== null) {
            $query->where('status', $status);
        }

        $towYard = $this->columnFilters['tow_yard'] ?? null;

        if (is_string($towYard) && trim($towYard) !== '') {
            $query->where('tow_yard', trim($towYard));
        }

        foreach (['deadline_withdraw', 'deadline_delivery'] as $column) {
            $range = $this->columnFilters[$column] ?? [];

            if (! is_array($range)) {
                continue;
            }

            $from = $this->validDateFilter($range['from'] ?? null);
            $until = $this->validDateFilter($range['until'] ?? null);

            if ($from !== null) {
                $query->whereDate($column, '>=', $from);
            }

            if ($until !== null) {
                $query->whereDate($column, '<=', $until);
            }
        }

        $valueRange = $this->columnFilters['value'] ?? [];

        if (is_array($valueRange)) {
            $minimum = $this->validNumericFilter($valueRange['minimum'] ?? null);
            $maximum = $this->validNumericFilter($valueRange['maximum'] ?? null);

            if ($minimum !== null) {
                $query->where('value', '>=', $minimum);
            }

            if ($maximum !== null) {
                $query->where('value', '<=', $maximum);
            }
        }

        return $query;
    }

    private function validDateFilter(mixed $value): ?string
    {
        if (! is_string($value) || preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $matches) !== 1) {
            return null;
        }

        return checkdate((int) $matches[2], (int) $matches[3], (int) $matches[1]) ? $value : null;
    }

    private function validNumericFilter(mixed $value): int|float|null
    {
        if (! is_numeric($value)) {
            return null;
        }

        return (float) $value;
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
