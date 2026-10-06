<?php

namespace App\Services\Dfe;

use DomainException;

class BuildMdfeInsuranceSnapshot
{
    /**
     * @param  array<int, string>  $endorsements
     * @param  array{insurer_name: string, insurer_cnpj: string, policy_number: string}|null  $homologationFixture
     * @return array{insurer_name: string, insurer_cnpj: string, policy_number: string, endorsements: array<int, string>, source: 'configured'|'homologation_fixture'}
     */
    public function handle(array $endorsements, ?array $homologationFixture = null): array
    {
        if ($homologationFixture !== null && (string) config('dfe.tp_amb') === '1') {
            throw new DomainException('Fixture sintético de seguro pode ser usado somente em homologação.');
        }

        $insurance = $homologationFixture ?? [
            'insurer_name' => (string) config('dfe.mdfe.insurer_name'),
            'insurer_cnpj' => (string) config('dfe.mdfe.insurer_cnpj'),
            'policy_number' => (string) config('dfe.mdfe.policy_number'),
        ];

        $missing = [];
        $requiredKeys = [
            'insurer_name' => 'DFE_MDFE_INSURER_NAME',
            'insurer_cnpj' => 'DFE_MDFE_INSURER_CNPJ',
            'policy_number' => 'DFE_MDFE_POLICY_NUMBER',
        ];

        foreach ($requiredKeys as $key => $environmentKey) {
            if (blank($insurance[$key] ?? null)) {
                $missing[] = $environmentKey;
            }
        }

        $normalizedEndorsements = array_values(array_unique(array_filter(
            array_map(fn (string $endorsement): string => trim($endorsement), $endorsements),
            fn (string $endorsement): bool => $endorsement !== '',
        )));

        if ($normalizedEndorsements === []) {
            $missing[] = 'nAver da operação';
        }

        if ($missing !== []) {
            throw new DomainException('Dados de seguro do MDF-e ausentes: '.implode(', ', $missing).'.');
        }

        $insurerName = trim((string) $insurance['insurer_name']);
        $insurerCnpj = (string) preg_replace('/\D/', '', (string) $insurance['insurer_cnpj']);
        $policyNumber = trim((string) $insurance['policy_number']);

        if (mb_strlen($insurerName) > 30 || strlen($insurerCnpj) !== 14 || mb_strlen($policyNumber) > 20) {
            throw new DomainException('Dados de seguro do MDF-e excedem o formato definido no MOC 3.00b.');
        }

        foreach ($normalizedEndorsements as $endorsement) {
            if (mb_strlen($endorsement) > 40) {
                throw new DomainException('Número de averbação do MDF-e excede 40 caracteres.');
            }
        }

        return [
            'insurer_name' => $insurerName,
            'insurer_cnpj' => $insurerCnpj,
            'policy_number' => $policyNumber,
            'endorsements' => $normalizedEndorsements,
            'source' => $homologationFixture === null ? 'configured' : 'homologation_fixture',
        ];
    }
}
