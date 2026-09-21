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
        $mensagem = $this->body['Mensagem'] ?? $this->body['mensagem'] ?? null;

        return $mensagem === null ? null : (string) $mensagem;
    }

    public function avisoTransportador(): ?string
    {
        $aviso = $this->body['AvisoTransportador'] ?? $this->body['avisoTransportador'] ?? null;

        return $aviso === null ? null : (string) $aviso;
    }

    public function isSuccess(): bool
    {
        return in_array($this->codigo(), ['110', '111'], true);
    }

    /**
     * Aceito pelo gateway (HTTP 2xx/3xx). Usado em operações cujo código de
     * sucesso de negócio (cancelar/encerrar) ainda será confirmado na homologação.
     */
    public function isAccepted(): bool
    {
        return $this->httpStatus < 400;
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
