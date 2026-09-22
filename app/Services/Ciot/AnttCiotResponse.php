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
     * CIOT de 12 dígitos (sem o código verificador). Na produção o número vem
     * no campo `IdOperacaoTransporte` (o ID emitido pelo servidor se torna o
     * CIOT); no DCS canônico é `CodigoIdentificacaoOperacao`; na camada
     * simplificada, `dados.ciot`.
     */
    public function identificacaoOperacao(): ?string
    {
        $idOperacao = $this->body['IdOperacaoTransporte']
            ?? $this->body['idOperacaoTransporte']
            ?? $this->body['CodigoIdentificacaoOperacao']
            ?? $this->body['codigoIdentificacaoOperacao']
            ?? null;

        if (filled($idOperacao)) {
            $idOperacao = (string) $idOperacao;

            return strlen($idOperacao) === 16 ? substr($idOperacao, 0, 12) : $idOperacao;
        }

        foreach ([$this->body['dados'] ?? [], $this->body['Dados'] ?? []] as $dados) {
            if (is_array($dados)) {
                foreach (['ciot', 'CIOT'] as $ciotKey) {
                    if (! empty($dados[$ciotKey])) {
                        $ciot = (string) $dados[$ciotKey];

                        return strlen($ciot) === 16 ? substr($ciot, 0, 12) : $ciot;
                    }
                }
            }
        }

        $protocolo = $this->protocolo();

        if ($protocolo !== null && strlen($protocolo) === 16) {
            return substr($protocolo, 0, 12);
        }

        return null;
    }

    public function codigoVerificador(): ?string
    {
        $verificador = $this->body['CodigoVerificador'] ?? $this->body['codigoVerificador'] ?? null;

        if (filled($verificador)) {
            return (string) $verificador;
        }

        $ciot = $this->ciotNumber();

        if ($ciot !== null && strlen($ciot) === 16) {
            return substr($ciot, 12, 4);
        }

        $protocolo = $this->protocolo();

        if ($protocolo !== null && strlen($protocolo) === 16) {
            return substr($protocolo, 12, 4);
        }

        return null;
    }

    /**
     * CIOT completo de 16 dígitos: o `Protocolo` de produção é o CIOT (12) +
     * verificador (4).
     */
    public function fullCiot(): ?string
    {
        $identificacao = $this->identificacaoOperacao();
        $verificador = $this->codigoVerificador();

        if ($identificacao === null || $verificador === null) {
            return null;
        }

        return $identificacao.$verificador;
    }
}
