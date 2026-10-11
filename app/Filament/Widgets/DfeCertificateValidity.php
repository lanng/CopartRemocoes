<?php

namespace App\Filament\Widgets;

use App\Services\Dfe\SefazCertificate;
use App\Services\Dfe\SefazException;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Validade do A1 usado pelo emissor direto (mesmo certificado do CIOT —
 * spec dfe §7). Widget do painel; leitura real do arquivo PFX.
 */
class DfeCertificateValidity extends BaseWidget
{
    protected static ?int $sort = -1;

    protected function getStats(): array
    {
        return [
            $this->validityStat(),
        ];
    }

    protected function validityStat(): Stat
    {
        try {
            $certificate = new SefazCertificate;

            $expiresAt = $certificate->expiresAt()->timezone('America/Sao_Paulo');
            $days = $certificate->daysUntilExpiry();

            $color = $days <= 30 ? 'danger' : ($days <= 60 ? 'warning' : 'success');

            return Stat::make('Certificado A1 (DFe)', "{$days} dia".($days === 1 ? '' : 's'))
                ->description("Vence em {$expiresAt->format('d/m/Y')} — {$certificate->subjectCommonName()}")
                ->descriptionIcon($days <= 30 ? 'heroicon-m-exclamation-triangle' : 'heroicon-m-shield-check')
                ->color($color);
        } catch (SefazException) {
            return Stat::make('Certificado A1 (DFe)', 'Não configurado')
                ->description('Defina DFE_CERT_PATH/CIOT_CERT_PATH e CIOT_CERT_PASSWORD')
                ->descriptionIcon('heroicon-m-exclamation-triangle')
                ->color('warning');
        }
    }
}
