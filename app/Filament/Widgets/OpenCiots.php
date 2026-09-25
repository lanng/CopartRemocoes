<?php

namespace App\Filament\Widgets;

use App\Enums\CiotStatusEnum;
use App\Models\Ciot;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class OpenCiots extends BaseWidget
{
    protected static ?int $sort = -1;

    protected function getStats(): array
    {
        return [
            $this->openCiotsStat(),
            $this->certificateStat(),
        ];
    }

    protected function openCiotsStat(): Stat
    {
        $open = Ciot::query()
            ->where('status', CiotStatusEnum::ISSUED->value)
            ->orderBy('issued_at')
            ->get();

        $stat = Stat::make('CIOTs abertos', $open->count())
            ->description('Emitidos e aguardando encerramento')
            ->descriptionIcon('heroicon-m-ticket')
            ->color($open->isNotEmpty() ? 'warning' : 'success');

        $oldest = $open->first();

        if ($oldest !== null) {
            $stat->description(sprintf(
                'Mais antigo: %s (%s)',
                $oldest->fullNumber() ?? '?',
                $oldest->issued_at?->timezone('America/Sao_Paulo')->format('d/m/Y'),
            ));
        }

        return $stat;
    }

    protected function certificateStat(): Stat
    {
        $expiry = $this->certificateExpiry();

        if ($expiry === null) {
            return Stat::make('Certificado A1', 'Não configurado')
                ->description('Defina CIOT_CERT_PATH e CIOT_CERT_PASSWORD')
                ->descriptionIcon('heroicon-m-exclamation-triangle')
                ->color('gray');
        }

        $days = (int) now()->startOfDay()->diffInDays($expiry, false);

        $color = match (true) {
            $days <= 0 => 'danger',
            $days <= 60 => 'warning',
            default => 'success',
        };

        return Stat::make('Certificado A1 ANTT', $expiry->format('d/m/Y'))
            ->description($days > 0 ? "Vence em {$days} dias" : 'Vencido')
            ->descriptionIcon('heroicon-m-identification')
            ->color($color);
    }

    /**
     * Validade real do certificado (lida do .pfx quando a senha está configurada),
     * com fallback para CIOT_CERT_VALID_TO.
     */
    protected function certificateExpiry(): ?\Illuminate\Support\Carbon
    {
        $fallback = config('ciot.cert_valid_to');

        $certPath = config('ciot.cert_path');
        $password = config('ciot.cert_password');

        if (filled($certPath) && filled($password) && is_file((string) $certPath) && extension_loaded('openssl')) {
            $pkcs12 = file_get_contents((string) $certPath);

            $certs = [];

            if ($pkcs12 !== false && openssl_pkcs12_read($pkcs12, $certs, (string) $password)) {
                $parsed = openssl_x509_parse($certs['cert'] ?? '');

                if (isset($parsed['validTo_time_t'])) {
                    return \Illuminate\Support\Carbon::createFromTimestamp((int) $parsed['validTo_time_t'])->startOfDay();
                }
            }
        }

        if (filled($fallback)) {
            return \Illuminate\Support\Carbon::parse((string) $fallback)->endOfDay();
        }

        return null;
    }
}
