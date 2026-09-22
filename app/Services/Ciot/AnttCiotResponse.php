<?php

namespace App\Services\Ciot;

class AnttCiotResponse
{
    /**
     * @param  array<string, mixed>  $body
     */
    public function __construct(
        public readonly int $httpStatus,
        public readonly array $body,
    ) {}

    public function codigo(): ?string
    {
        $codigo = $this->body['Codigo'] ?? $this->body['codigo'] ?? null;

        return $codigo === null ? null : (string) $codigo;
    }

    public function mensagem(): ?string
    {
        $mensagem = $this->body['Mensagem'] ?? $this->body['mensagem'] ?? $this->body['Message'] ?? null;

        if ($mensagem === null) {
            return null;
        }

        if (is_array($mensagem)) {
            $mensagem = implode('; ', array_map(
                fn ($part): string => is_scalar($part) ? (string) $part : json_encode($part, JSON_UNESCAPED_UNICODE),
                $mensagem,
            ));
        }

        return (string) $mensagem;
    }

    /**
     * Envelope atual do /gerar em homologação: {"Sucesso": true, "Mensagem": ..., "Dados": ..., "Erros": ...}.
     */
    public function sucesso(): ?bool
    {
        $sucesso = $this->body['Sucesso'] ?? $this->body['sucesso'] ?? null;

        return $sucesso === null ? null : (bool) $sucesso;
    }

    public function avisoTransportador(): ?string
    {
        $aviso = $this->body['AvisoTransportador'] ?? $this->body['avisoTransportador'] ?? null;

        return $aviso === null ? null : (string) $aviso;
    }

    /**
     * Data de efetivação do cancelamento — nula em rejeição. O encerramento/
     * cancelamento pode devolver Protocolo mesmo rejeitando (N98…), então a
     * decisão de sucesso usa Codigo + estas datas (spec §2.1).
     */
    public function dataCancelamento(): ?string
    {
        $data = $this->body['DataCancelamento'] ?? $this->body['dataCancelamento'] ?? null;

        return filled($data) ? (string) $data : null;
    }

    public function dataEncerramento(): ?string
    {
        $data = $this->body['DataEncerramento'] ?? $this->body['dataEncerramento'] ?? null;

        return filled($data) ? (string) $data : null;
    }

    public function isSuccess(): bool
    {
        if ($this->sucesso() === true) {
            return true;
        }

        return in_array($this->codigo(), ['110', '111'], true);
    }

    /**
     * Número do CIOT como veio na resposta: `dados.ciot`, `Dados.CIOT` ou os campos
     * canônicos do DCS (`CodigoIdentificacaoOperacao` + `CodigoVerificador`).
     */
    public function ciotNumber(): ?string
    {
        foreach (['dados', 'Dados'] as $key) {
            $dados = $this->body[$key] ?? null;

            if (is_array($dados)) {
                foreach (['ciot', 'CIOT'] as $ciotKey) {
                    if (! empty($dados[$ciotKey])) {
                        return (string) $dados[$ciotKey];
                    }
                }
            }
        }

        $identificacao = $this->body['CodigoIdentificacaoOperacao']
            ?? $this->body['codigoIdentificacaoOperacao']
            ?? ($this->body['dados']['CodigoIdentificacaoOperacao'] ?? null)
            ?? ($this->body['Dados']['CodigoIdentificacaoOperacao'] ?? null);
        $verificador = $this->body['CodigoVerificador']
            ?? $this->body['codigoVerificador']
            ?? ($this->body['dados']['CodigoVerificador'] ?? null)
            ?? ($this->body['Dados']['CodigoVerificador'] ?? null);

        if ($identificacao !== null && $verificador !== null) {
            return ((string) $identificacao).((string) $verificador);
        }

        return null;
    }

    public function protocolo(): ?string
    {
        foreach ([$this->body, $this->body['dados'] ?? [], $this->body['Dados'] ?? []] as $source) {
            $protocolo = $source['Protocolo'] ?? $source['protocolo'] ?? null;

            if (! empty($protocolo)) {
                return (string) $protocolo;
            }
        }

        return null;
    }

    /**
     * CIOT de 12 dígitos (sem o código verificador).
     */
    public function identificacaoOperacao(): ?string
    {
        $ciot = $this->ciotNumber();

        if ($ciot === null) {
            return null;
        }

        return strlen($ciot) === 16 ? substr($ciot, 0, 12) : $ciot;
    }

    public function codigoVerificador(): ?string
    {
        $ciot = $this->ciotNumber();

        if ($ciot === null || strlen($ciot) !== 16) {
            return null;
        }

        return substr($ciot, 12, 4);
    }
}
