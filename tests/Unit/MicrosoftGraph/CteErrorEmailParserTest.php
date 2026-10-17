<?php

namespace Tests\Unit\MicrosoftGraph;

use App\Services\MicrosoftGraph\CteErrorEmailParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class CteErrorEmailParserTest extends TestCase
{
    public function test_it_extracts_the_document_number_and_complete_error_excerpt(): void
    {
        $result = (new CteErrorEmailParser)->parse($this->message());

        $this->assertTrue($result['valid']);
        $this->assertSame('3014', $result['cte_number']);
        $this->assertSame('Comitente inválido. Favor corrigir o CNPJ.', $result['error_message']);
        $this->assertSame("Identificamos um problema na validação dos dados do documento de transporte número:\n\n3014\n- Mensagem de Erro:\n\nComitente inválido. Favor corrigir o CNPJ.", $result['notes']);
    }

    public function test_it_accepts_html_case_accents_and_whitespace_and_any_error_text(): void
    {
        $message = $this->message();
        $message['sender'] = ' REMOCAO@COPART.COM.BR ';
        $message['subject'] = ' copart  - ERRO NO DOCUMENTO NUMERO 3014 ';
        $message['body'] = '<p>Documento de Transporte NÚMERO&nbsp;<b>3014</b></p><p>MENSAGEM DE ERRO:</p><div>Valor do frete<br> divergente &amp; inválido.</div><p>Solicitamos o CANCELAMENTO do documento e nova emissão.</p>';

        $result = (new CteErrorEmailParser)->parse($message);

        $this->assertTrue($result['valid']);
        $this->assertSame('Valor do frete divergente & inválido.', $result['error_message']);
    }

    #[DataProvider('excerptEndingProvider')]
    public function test_it_accepts_the_error_through_eof_or_until_a_known_footer(string $ending): void
    {
        $message = $this->message();
        $message['body'] = '<p>Documento de transporte número: 3014</p><p>Mensagem de Erro:</p><p>Valor do frete<br>divergente &amp; inválido.</p>'.$ending;

        $result = (new CteErrorEmailParser)->parse($message);

        $this->assertTrue($result['valid']);
        $this->assertSame('Valor do frete divergente & inválido.', $result['error_message']);
        $this->assertSame("Identificamos um problema na validação dos dados do documento de transporte número:\n\n3014\n- Mensagem de Erro:\n\nValor do frete divergente & inválido.", $result['notes']);
    }

    public static function excerptEndingProvider(): array
    {
        return [
            'eof' => [''],
            'cancellation' => ['<p>Solicitamos o cancelamento do documento e nova emissão.</p>'],
            'notification' => ['<p>Esta mensagem de e-mail foi enviada a partir de um endereço de notificação que não pode receber mensagens, por favor não responder a este e-mail</p><p>Connect with Copart</p>'],
            'connect' => ['<p>CONNECT WITH&nbsp;COPART</p><a href="https://example.com">Redes sociais</a>'],
        ];
    }

    public function test_notification_words_in_an_error_are_preserved(): void
    {
        $message = $this->message();
        $message['body'] = 'Documento de transporte número 3014. Mensagem de Erro: Notificação automática falhou. Corrigir documento.';

        $result = (new CteErrorEmailParser)->parse($message);

        $this->assertTrue($result['valid']);
        $this->assertSame('Notificação automática falhou. Corrigir documento.', $result['error_message']);
    }

    #[DataProvider('invalidMessageProvider')]
    public function test_it_rejects_untrusted_or_incomplete_or_conflicting_messages(array $overrides, string $reason): void
    {
        $result = (new CteErrorEmailParser)->parse(array_replace($this->message(), $overrides));

        $this->assertFalse($result['valid']);
        $this->assertSame($reason, $result['reason']);
    }

    public static function invalidMessageProvider(): array
    {
        return [
            'sender' => [['sender' => 'other@example.com'], 'untrusted_sender'],
            'subject' => [['subject' => 'RE: COPART - Erro no Documento Número 3014'], 'invalid_subject'],
            'body' => [['body' => 'Mensagem de Erro: Falha sem identificação.'], 'invalid_body'],
            'empty error' => [['body' => 'Documento de Transporte Número 3014. Mensagem de Erro: Solicitamos o cancelamento do documento.'], 'invalid_body'],
            'empty error at eof' => [['body' => 'Documento de Transporte Número 3014. Mensagem de Erro:'], 'invalid_body'],
            'different number' => [['body' => 'Documento de Transporte Número 3015. Mensagem de Erro: Falha. Solicitamos o cancelamento do documento.'], 'cte_number_mismatch'],
        ];
    }

    /** @return array{sender: string, subject: string, body: string} */
    private function message(): array
    {
        return [
            'sender' => 'remocao@copart.com.br',
            'subject' => 'COPART - Erro no Documento Número 3014',
            'body' => 'Identificamos um problema na validação dos dados do documento de transporte número: 3014 - Mensagem de Erro: Comitente inválido. Favor corrigir o CNPJ. Solicitamos o cancelamento do documento e emissão de outro.',
        ];
    }
}
