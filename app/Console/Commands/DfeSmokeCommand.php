<?php

namespace App\Console\Commands;

use App\Services\Cte\BuildCteXml;
use App\Services\Dfe\SefazCertificate;
use App\Services\Dfe\SefazException;
use App\Services\Dfe\SefazSoapClient;
use App\Services\Dfe\XmlSigner;
use App\Services\Dfe\XsdValidator;
use Illuminate\Console\Command;
use Throwable;

class DfeSmokeCommand extends Command
{
    protected $signature = 'dfe:smoke
        {--line=vehicle_removal : Linha do snapshot sintético (vehicle_removal ou tank_alcohol)}
        {--force : Permite rodar mesmo com DFE_TP_AMB=1}';

    protected $description = 'Ciclo completo do emissor direto: numeração → build → assinar → validar XSD → transmitir à SEFAZ (aceite F3: cStat 100 na homologação)';

    public function handle(SefazSoapClient $client, BuildCteXml $builder, XsdValidator $validator): int
    {
        if ((string) config('dfe.tp_amb') === '1' && ! $this->option('force')) {
            $this->error('DFE_TP_AMB=1. Use --force para transmitir em produção (não recomendado).');

            return self::FAILURE;
        }

        try {
            $this->info('=== StatusServico ===');

            $status = $client->status();

            if (! $status->isServiceOnline()) {
                $this->error('StatusServico não retornou 107 (cStat '.($status->cStat ?? '—').'): '.($status->xMotivo ?? 'sem xMotivo'));

                return self::FAILURE;
            }

            $this->info('✔ SEFAZ online (cStat 107).');

            $this->info('=== CT-e sintético ('.$this->option('line').') ===');

            $built = $builder->handle($this->snapshot());

            $this->line("nCT {$built->number}, série {$built->serie}, cCT {$built->cct}");
            $this->line("Chave: {$built->accessKey}");

            $certificate = new SefazCertificate;
            $signedXml = (new XmlSigner($certificate))->sign(
                $built->xml,
                'infCte',
                'http://www.portalfiscal.inf.br/cte',
                'CTe'.$built->accessKey,
            );

            $validator->validateCte($signedXml);
            $this->info('✔ Assinado e validado contra cte_v4.00.xsd.');

            $response = $client->transmit($signedXml);

            $this->line("Resposta: cStat {$response->cStat} — {$response->xMotivo}");

            if ($response->isAuthorized()) {
                $this->info("✔ CT-e AUTORIZADO (protocolo {$response->protocol}) — aceite F3 cumprido.");

                return self::SUCCESS;
            }

            if ($response->digestValue !== null && $response->digestValue !== $this->digestOf($signedXml)) {
                $this->error('⚠ digVal divergente do DigestValue assinado — reconciliação necessária.');
            }

            $this->error('CT-e não autorizado (cStat '.$response->cStat.').');

            return self::FAILURE;
        } catch (SefazException $exception) {
            $this->error('[SEFAZ] '.$exception->getMessage());

            return self::FAILURE;
        } catch (Throwable $exception) {
            $this->error('['.class_basename($exception).'] '.$exception->getMessage());

            return self::FAILURE;
        }
    }

    protected function digestOf(string $signedXml): ?string
    {
        return preg_match('/<DigestValue>([^<]*)<\/DigestValue>/', $signedXml, $matches) === 1
            ? $matches[1]
            : null;
    }

    /**
     * Snapshot v2 sintético com os dados dos gabaritos autorizados
     * (2962 veículos / 2963 tanque).
     *
     * @return array<string, mixed>
     */
    protected function snapshot(): array
    {
        if ($this->option('line') === 'tank_alcohol') {
            return [
                'schema_version' => 2,
                'line' => 'tank_alcohol',
                'register_id' => 0,
                'value' => '3725.81',
                'cargo_value' => '54294.71',
                'cargo_liters' => '21879',
                'nfe_key' => '35260945483450002164550010004300881365784943',
                'dprev' => now()->addDay()->format('Y-m-d'),
                'origin' => ['ibge' => '3541802', 'name' => 'Queiroz', 'uf' => 'SP'],
                'destination' => ['ibge' => '3525102', 'name' => 'Jardinópolis', 'uf' => 'SP'],
                'rem' => [
                    'cnpj' => '45483450002164',
                    'ie' => '566051683117',
                    'xNome' => 'CLEALCO ACUCAR E ALCOOL S/A EM RECUPERACAO JUDICIAL',
                    'xFant' => 'CLEALCO ACUCAR E ALCOOL S/A EM RECUPERAC',
                    'xLgr' => 'FAZENDA POUSO ALEGRE,',
                    'nro' => 'S/N',
                    'xBairro' => 'ZONA RURAL',
                    'cMun' => '3541802',
                    'xMun' => 'Queiroz',
                    'cep' => '17590000',
                    'uf' => 'SP',
                ],
                'dest' => [
                    'cnpj' => '48580847000208',
                    'ie' => '399116523112',
                    'xNome' => 'AVANT COMBUSTIVEIS LTDA',
                    'xLgr' => 'R BRASIL ALTO FURQUINI',
                    'nro' => '401',
                    'xBairro' => 'BAIRRO DISTRITO INDUSTRIAL ADI',
                    'cMun' => '3525102',
                    'xMun' => 'Jardinópolis',
                    'cep' => '14690000',
                    'uf' => 'SP',
                ],
            ];
        }

        return [
            'schema_version' => 2,
            'line' => 'vehicle_removal',
            'register_id' => 0,
            'value' => '898.45',
            'fipe_value' => '139329.00',
            'vehicle_model' => 'AMAROK',
            'vehicle_plate' => 'BDA4E98',
            'payment_code' => 'T718089',
            'origin' => ['ibge' => '3504008', 'name' => 'Assis', 'uf' => 'SP'],
            'destination' => ['ibge' => '3523107', 'name' => 'Itaquaquecetuba', 'uf' => 'SP'],
            'rem' => [
                'cnpj' => '33164021000100',
                'ie' => '110794302111',
                'xNome' => 'TOKIO MARINE SEGURADORA S.A',
                'xLgr' => 'RUA SAMPAIO VIANA',
                'nro' => '44',
                'xBairro' => 'PARAISO',
                'cMun' => '3550308',
                'xMun' => 'Sao Paulo',
                'cep' => '04004902',
                'uf' => 'SP',
            ],
            'dest' => [
                'cnpj' => '14517191000178',
                'ie' => '379121178113',
                'xNome' => 'Copart do Brasil Organizacao de Leiloes Ltda',
                'xLgr' => 'Estrada Muranaka',
                'nro' => '199',
                'xBairro' => 'Jd. Novo Horizonte',
                'cMun' => '3523107',
                'xMun' => 'Itaquaquecetuba',
                'cep' => '08597230',
                'uf' => 'SP',
            ],
        ];
    }
}
