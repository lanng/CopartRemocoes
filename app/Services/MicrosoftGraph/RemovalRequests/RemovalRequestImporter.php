<?php

namespace App\Services\MicrosoftGraph\RemovalRequests;

use App\Enums\CompanyEnum;
use App\Enums\RegisterStatusEnum;
use App\Models\IntegrationInboxItem;
use App\Models\Register;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

class RemovalRequestImporter
{
    private const REGISTER_FIELDS = [
        'vehicle_model',
        'vehicle_plate',
        'origin_city',
        'destination_city',
        'deadline_withdraw',
        'deadline_delivery',
        'vehicle_id',
        'value',
        'insurance',
        'fipe_value',
        'payment_code',
        'notes',
    ];

    private const BODY_REQUIRED_FIELDS = [
        'vehicle_id',
        'insurance',
        'destination_city',
        'deadline_withdraw',
        'deadline_delivery',
        'value',
        'fipe_value',
        'payment_code',
    ];

    private const PDF_REQUIRED_FIELDS = [
        'vehicle_model',
        'vehicle_plate',
        'origin_city',
        'destination_city',
        'vehicle_id',
        'insurance',
        'deadline_withdraw',
        'deadline_delivery',
    ];

    public function __construct(
        private readonly RemovalRequestNormalizer $normalizer,
        private readonly RemovalRequestPdfStorage $storage,
    ) {}

    public function handle(IntegrationInboxItem $item, PreparedRemovalPdf $pdf): IntegrationInboxItem
    {
        $sources = $this->sources($item, $pdf);
        $item->extracted_data = $sources;

        $canonical = $this->canonical($sources);
        $failureReason = $this->validationFailure($sources, $canonical);

        if ($failureReason !== null) {
            return $this->markPending($item, $failureReason);
        }

        return DB::transaction(function () use ($item, $pdf, $canonical): IntegrationInboxItem {
            $identity = $this->resolveIdentity($canonical['vehicle_id'], $canonical['vehicle_plate']);

            if ($identity === null) {
                return $this->markPending($item, 'identity_conflict');
            }

            if ($identity instanceof Register) {
                return $this->handleExisting($item, $pdf, $canonical, $identity);
            }

            $uploadedPath = $this->storage->store($pdf, $canonical['vehicle_id']);

            try {
                $register = Register::query()->create($this->registerAttributes($canonical, $pdf, $uploadedPath));
                $isZeroFipe = $canonical['fipe_value'] === '0.00';

                $item->forceFill([
                    'status' => $isZeroFipe ? 'alert' : 'processed',
                    'register_id' => $register->id,
                    'failure_reason' => null,
                    'proposed_changes' => null,
                    'alerts' => $isZeroFipe ? ['zero_fipe'] : null,
                    'resolved_at' => $isZeroFipe ? null : now(),
                ])->save();

                return $item->refresh();
            } catch (Throwable $exception) {
                try {
                    $this->storage->delete($uploadedPath);
                } catch (Throwable $cleanupException) {
                    throw new \RuntimeException(
                        'Falha ao compensar o upload do PDF: '.$cleanupException->getMessage(),
                        0,
                        $exception,
                    );
                }

                throw $exception;
            }
        });
    }

    /** @return array<string, mixed> */
    private function sources(IntegrationInboxItem $item, PreparedRemovalPdf $pdf): array
    {
        $data = is_array($item->extracted_data) ? $item->extracted_data : [];
        $subject = is_array($data['subject'] ?? null) ? $data['subject'] : [];
        $body = is_array($data['body'] ?? null) ? $data['body'] : [];

        return [
            'subject' => $subject,
            'body' => $body,
            'body_missing_fields' => is_array($data['body_missing_fields'] ?? null)
                ? $data['body_missing_fields']
                : [],
            'pdf' => $pdf->extractedData,
        ];
    }

    /** @param array<string, mixed> $sources @return array<string, string|null> */
    private function canonical(array $sources): array
    {
        $subject = $sources['subject'];
        $body = $sources['body'];
        $pdf = $sources['pdf'];

        return [
            'vehicle_model' => $this->normalizer->text($pdf['vehicle_model'] ?? null),
            'vehicle_plate' => $this->normalizer->plate($pdf['vehicle_plate'] ?? null),
            'origin_city' => $this->normalizer->text($pdf['origin_city'] ?? null),
            'destination_city' => $this->normalizer->text($this->withoutState($body['destination_city'] ?? null)),
            'deadline_withdraw' => $this->normalizer->date($body['deadline_withdraw'] ?? null),
            'deadline_delivery' => $this->normalizer->date($body['deadline_delivery'] ?? null),
            'vehicle_id' => $this->normalizer->identifier($body['vehicle_id'] ?? null),
            'value' => $this->normalizer->decimal($body['value'] ?? null),
            'insurance' => $this->normalizer->insurance($subject['insurance'] ?? $body['insurance'] ?? null),
            'fipe_value' => $this->normalizer->decimal($body['fipe_value'] ?? null),
            'payment_code' => $this->normalizer->identifier($body['payment_code'] ?? null),
        ];
    }

    /** @param array<string, mixed> $sources @param array<string, string|null> $canonical */
    private function validationFailure(array $sources, array $canonical): ?string
    {
        $body = $sources['body'];
        $pdf = $sources['pdf'];
        $missingBody = array_values(array_filter(
            self::BODY_REQUIRED_FIELDS,
            fn (string $field): bool => $this->isBlankSource($body, $field),
        ));

        if ($missingBody !== [] || $sources['body_missing_fields'] !== []) {
            return 'missing_body_fields';
        }

        $missingPdf = array_values(array_filter(
            self::PDF_REQUIRED_FIELDS,
            fn (string $field): bool => $this->isBlankSource($pdf, $field),
        ));

        if ($missingPdf !== []) {
            return 'missing_pdf_fields';
        }

        foreach ([[$body, self::BODY_REQUIRED_FIELDS], [$pdf, self::PDF_REQUIRED_FIELDS]] as [$source, $fields]) {
            foreach ($fields as $field) {
                if ($this->sourceValue($field, $source) === null) {
                    return 'invalid_constraints';
                }
            }
        }

        $comparisons = [
            ['vehicle_plate', $sources['subject']['vehicle_plate'] ?? null, $pdf['vehicle_plate'] ?? null, 'vehicle_plate_mismatch'],
            ['vehicle_id', $sources['subject']['vehicle_id'] ?? null, $body['vehicle_id'] ?? null, 'vehicle_id_mismatch'],
            ['vehicle_id', $body['vehicle_id'] ?? null, $pdf['vehicle_id'] ?? null, 'vehicle_id_mismatch'],
            ['insurance', $sources['subject']['insurance'] ?? null, $body['insurance'] ?? null, 'insurance_mismatch'],
            ['insurance', $sources['subject']['insurance'] ?? null, $pdf['insurance'] ?? null, 'insurance_mismatch'],
            ['destination_city', $body['destination_city'] ?? null, $pdf['destination_city'] ?? null, 'destination_city_mismatch'],
            ['deadline_withdraw', $body['deadline_withdraw'] ?? null, $pdf['deadline_withdraw'] ?? null, 'deadline_withdraw_mismatch'],
            ['deadline_delivery', $body['deadline_delivery'] ?? null, $pdf['deadline_delivery'] ?? null, 'deadline_delivery_mismatch'],
        ];

        foreach ($comparisons as [$field, $left, $right, $failureReason]) {
            if (
                $this->sourceValue($field, [$field => $left]) === null
                || $this->sourceValue($field, [$field => $right]) === null
                || $this->sourceValue($field, [$field => $left]) !== $this->sourceValue($field, [$field => $right])
            ) {
                return $failureReason;
            }
        }

        if ($canonical['deadline_withdraw'] > $canonical['deadline_delivery']) {
            return 'invalid_constraints';
        }

        if (
            $canonical['vehicle_model'] === null
            || mb_strlen($canonical['vehicle_model']) > 30
            || $canonical['vehicle_plate'] === null
            || preg_match('/^[A-Z0-9]{7}$/', $canonical['vehicle_plate']) !== 1
            || $canonical['origin_city'] === null
            || mb_strlen($canonical['origin_city']) > 50
            || $canonical['destination_city'] === null
            || mb_strlen($canonical['destination_city']) > 50
            || $canonical['vehicle_id'] === null
            || preg_match('/^\d{1,10}$/', $canonical['vehicle_id']) !== 1
            || $this->exceedsDecimal($canonical['value'], '9999.99')
            || $this->exceedsDecimal($canonical['fipe_value'], '999999.99')
            || $canonical['payment_code'] === null
            || $canonical['insurance'] === null
        ) {
            return 'invalid_constraints';
        }

        return null;
    }

    private function exceedsDecimal(?string $value, string $maximum): bool
    {
        if ($value === null) {
            return true;
        }

        [$valueInteger, $valueFraction] = array_pad(explode('.', $value, 2), 2, '00');
        [$maximumInteger, $maximumFraction] = array_pad(explode('.', $maximum, 2), 2, '00');
        $valueInteger = ltrim($valueInteger, '0') ?: '0';
        $maximumInteger = ltrim($maximumInteger, '0') ?: '0';

        if (strlen($valueInteger) !== strlen($maximumInteger)) {
            return strlen($valueInteger) > strlen($maximumInteger);
        }

        return ($valueInteger.$valueFraction) > ($maximumInteger.$maximumFraction);
    }

    /** @param array<string, mixed> $source */
    private function isBlankSource(array $source, string $field): bool
    {
        $value = $source[$field] ?? null;

        return $value === null || (is_string($value) && trim($value) === '');
    }

    private function sourceValue(string $field, mixed $source): mixed
    {
        $value = is_array($source) ? ($source[$field] ?? null) : null;

        return match ($field) {
            'vehicle_plate' => $this->normalizer->plate(is_scalar($value) ? (string) $value : null),
            'vehicle_id', 'payment_code' => $this->normalizer->identifier(is_scalar($value) ? (string) $value : null),
            'insurance' => $this->normalizer->insurance(is_scalar($value) ? (string) $value : null),
            'destination_city' => $this->normalizer->text($this->withoutState(is_scalar($value) ? (string) $value : null)),
            'deadline_withdraw', 'deadline_delivery' => $this->normalizer->date(is_scalar($value) ? (string) $value : null),
            'value', 'fipe_value' => $this->normalizer->decimal(is_scalar($value) ? (string) $value : null),
            default => $this->normalizer->text(is_scalar($value) ? (string) $value : null),
        };
    }

    private function withoutState(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return preg_replace('/\s+-\s+[A-Z]{2}\s*$/iu', '', $value) ?: $value;
    }

    private function resolveIdentity(string $vehicleId, string $plate): Register|false|null
    {
        $registers = Register::query()
            ->where('company', CompanyEnum::COPART->value)
            ->where(function ($query) use ($vehicleId, $plate): void {
                $query
                    ->where('vehicle_id', $vehicleId)
                    ->orWhereRaw("REPLACE(REPLACE(UPPER(vehicle_plate), '-', ''), ' ', '') = ?", [$plate]);
            })
            ->lockForUpdate()
            ->get();

        if ($registers->isEmpty()) {
            return false;
        }

        if ($registers->count() !== 1) {
            return null;
        }

        $register = $registers->sole();
        $sameId = $this->normalizer->identifier($register->vehicle_id) === $vehicleId;
        $samePlate = $this->normalizer->plate($register->vehicle_plate) === $plate;

        return $sameId && $samePlate ? $register : null;
    }

    /** @param array<string, string|null> $canonical */
    private function handleExisting(
        IntegrationInboxItem $item,
        PreparedRemovalPdf $pdf,
        array $canonical,
        Register $register,
    ): IntegrationInboxItem {
        $proposed = $this->registerAttributes($canonical, $pdf, $register->pdf_path);
        $proposed['notes'] = $this->mergePhoneLine(
            $this->comparableRegisterValue($register, 'notes'),
            $pdf->extractedData['origin_phones'] ?? null,
        );
        $changes = [];

        foreach (self::REGISTER_FIELDS as $field) {
            $current = $this->comparableRegisterValue($register, $field);
            $next = $proposed[$field] ?? null;

            if (! $this->normalizer->equivalent($this->normalizationField($field), $current, $next)) {
                $changes[$field] = ['current' => $current, 'proposed' => $next];
            }
        }

        if ($register->pdf_sha256 === null || ! hash_equals($register->pdf_sha256, $pdf->sha256)) {
            $changes['pdf_path'] = [
                'current' => [
                    'path' => $register->pdf_path,
                    'sha256' => $register->pdf_sha256,
                ],
                'proposed' => [
                    'file_name' => $pdf->fileName,
                    'sha256' => $pdf->sha256,
                ],
            ];
        }

        if ($register->pdf_sha256 !== null && hash_equals($register->pdf_sha256, $pdf->sha256) && $changes === []) {
            $item->forceFill([
                'status' => 'no_changes',
                'register_id' => $register->id,
                'proposed_changes' => [],
                'failure_reason' => null,
                'resolved_at' => now(),
            ])->save();

            return $item->refresh();
        }

        $item->forceFill([
            'status' => 'pending',
            'register_id' => $register->id,
            'proposed_changes' => $changes,
            'failure_reason' => 'update_required',
            'resolved_at' => null,
        ])->save();

        return $item->refresh();
    }

    /** @param array<string, string|null> $canonical @return array<string, mixed> */
    private function registerAttributes(array $canonical, PreparedRemovalPdf $pdf, ?string $pdfPath): array
    {
        return [
            'company' => CompanyEnum::COPART,
            'vehicle_model' => $canonical['vehicle_model'],
            'vehicle_plate' => $canonical['vehicle_plate'],
            'origin_city' => $canonical['origin_city'],
            'destination_city' => $canonical['destination_city'],
            'deadline_withdraw' => $canonical['deadline_withdraw'],
            'deadline_delivery' => $canonical['deadline_delivery'],
            'vehicle_id' => $canonical['vehicle_id'],
            'value' => $canonical['value'],
            'status' => RegisterStatusEnum::PENDING,
            'pdf_path' => $pdfPath,
            'pdf_sha256' => $pdf->sha256,
            'notes' => $this->phoneLine($pdf->extractedData['origin_phones'] ?? null),
            'insurance' => $canonical['insurance'],
            'fipe_value' => $canonical['fipe_value'],
            'payment_code' => $canonical['payment_code'],
        ];
    }

    private function normalizationField(string $field): string
    {
        return match ($field) {
            'vehicle_model', 'origin_city', 'notes' => 'text',
            'vehicle_plate' => 'plate',
            'vehicle_id', 'payment_code' => 'identifier',
            'deadline_withdraw', 'deadline_delivery' => 'date',
            'value', 'fipe_value' => 'decimal',
            default => $field,
        };
    }

    private function comparableRegisterValue(Register $register, string $field): mixed
    {
        $value = $register->getAttribute($field);

        if ($value instanceof Carbon) {
            return $value->toDateString();
        }

        if ($value instanceof \BackedEnum) {
            return $value->value;
        }

        return $value;
    }

    private function phoneLine(mixed $phones): ?string
    {
        if (! is_array($phones)) {
            return null;
        }

        $phones = array_values(array_filter(array_map(
            fn (mixed $phone): ?string => is_scalar($phone) ? $this->normalizer->text((string) $phone) : null,
            $phones,
        )));

        return $phones === [] ? null : 'Telefones Origem: '.implode(' / ', $phones);
    }

    private function mergePhoneLine(?string $notes, mixed $phones): ?string
    {
        $phoneLine = $this->phoneLine($phones);

        if ($phoneLine === null) {
            return $notes;
        }

        $lines = preg_split('/\R/u', (string) $notes) ?: [];

        foreach ($lines as $line) {
            if ($this->normalizer->equivalent('text', $line, $phoneLine)) {
                return $notes;
            }
        }

        return trim((string) $notes) === '' ? $phoneLine : trim((string) $notes).PHP_EOL.$phoneLine;
    }

    private function markPending(IntegrationInboxItem $item, string $failureReason): IntegrationInboxItem
    {
        $item->forceFill([
            'status' => 'pending',
            'register_id' => null,
            'failure_reason' => $failureReason,
            'proposed_changes' => null,
            'alerts' => null,
            'resolved_at' => null,
        ])->save();

        return $item->refresh();
    }
}
