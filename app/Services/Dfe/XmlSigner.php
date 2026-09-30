<?php

namespace App\Services\Dfe;

use DOMDocument;
use Throwable;

/**
 * Assinador XML-DSig dos DF-e (MOC CT-e 4.00 §3.2.4 / MDF-e 3.00b): C14N 1.0
 * inclusivo, RSA-SHA1, SHA-1, transforms enveloped-signature + C14N (spec §2.1).
 *
 * O fragmento assinado é recortado CRU do documento (bytes originais, sem
 * re-serializar) e reparseado como documento standalone com o namespace
 * herdado declarado no ápice: o C14N de subárvore do libxml injeta xmlns=""
 * espúrio nos filhos e corrompe o digest (armadilha mortal da spec §2.1).
 */
class XmlSigner
{
    public const DSIG_XMLNS = 'http://www.w3.org/2000/09/xmldsig#';

    public const C14N_ALGO = 'http://www.w3.org/TR/2001/REC-xml-c14n-20010315';

    public const RSA_SHA1_ALGO = 'http://www.w3.org/2000/09/xmldsig#rsa-sha1';

    public const SHA1_ALGO = 'http://www.w3.org/2000/09/xmldsig#sha1';

    public const ENVELOPED_ALGO = 'http://www.w3.org/2000/09/xmldsig#enveloped-signature';

    public function __construct(protected SefazCertificate $certificate) {}

    /**
     * Digest SHA-1 (base64) do fragmento do elemento assinado.
     */
    public function digest(string $xml, string $element, string $xmlns): string
    {
        return base64_encode(sha1($this->canonicalFragment($xml, $element, $xmlns), true));
    }

    /**
     * Assina o documento inteiro e injeta o <Signature> logo antes do
     * fechamento da raiz — ordem xs:sequence do MOC (infCte → infCTeSupl →
     * Signature; o QR fica fora da área assinada). Nunca re-serializa o
     * documento: cirurgia por bytes, preservando o digest já calculado.
     */
    public function sign(string $xml, string $element, string $xmlns, string $id): string
    {
        $digest = $this->digest($xml, $element, $xmlns);

        $canonical = $this->canonicalizeStandalone($this->signedInfo($digest, $id));

        $signatureValue = '';

        if (! openssl_sign($canonical, $signatureValue, $this->certificate->privateKeyPem(), OPENSSL_ALGO_SHA1)) {
            throw new SefazException('Falha ao assinar o XML: '.(string) openssl_error_string());
        }

        $block = '<Signature xmlns="'.self::DSIG_XMLNS.'">'
            .$this->embeddedSignedInfo($id, $digest)
            .'<SignatureValue>'.base64_encode($signatureValue).'</SignatureValue>'
            .'<KeyInfo><X509Data><X509Certificate>'.$this->certificate->certificateDerBase64().'</X509Certificate></X509Data></KeyInfo>'
            .'</Signature>';

        $root = $this->rootElementName($xml);
        $closing = '</'.$root.'>';
        $position = strrpos($xml, $closing);

        if ($position === false) {
            throw new SefazException("Documento sem a tag de fechamento </{$root}>.");
        }

        return substr_replace($xml, $block, $position, 0);
    }

    /**
     * Espelho de verificação para testes (validado com verify=1 nos gabaritos):
     * reconstrói o digest do fragmento no XML assinado e confere a assinatura
     * com a chave pública do certificado embutido no KeyInfo.
     */
    public function verify(string $signedXml, string $element, string $xmlns, string $id): bool
    {
        $declaredDigest = $this->firstTagValue($signedXml, 'DigestValue');

        if ($declaredDigest === null || $this->digest($signedXml, $element, $xmlns) !== $declaredDigest) {
            return false;
        }

        $uri = $this->firstTagAttribute($signedXml, 'Reference', 'URI');

        if ($uri !== '#'.$id) {
            return false;
        }

        $start = strpos($signedXml, '<SignedInfo>');
        $end = $start === false ? false : strpos($signedXml, '</SignedInfo>', $start);

        if ($start === false || $end === false) {
            return false;
        }

        $embedded = substr($signedXml, $start, $end + strlen('</SignedInfo>') - $start);
        $standalone = str_replace('<SignedInfo>', '<SignedInfo xmlns="'.self::DSIG_XMLNS.'">', $embedded);

        $signatureValue = base64_decode((string) $this->firstTagValue($signedXml, 'SignatureValue'), true);

        if ($signatureValue === false) {
            return false;
        }

        $publicKey = openssl_pkey_get_public($this->certificate->certificatePem());

        return openssl_verify($this->canonicalizeStandalone($standalone), $signatureValue, $publicKey, OPENSSL_ALGO_SHA1) === 1;
    }

    /**
     * Forma canônica (C14N 1.0 inclusivo) do fragmento assinado — exposto para
     * diagnóstico do F2 (conferência de digest contra gabaritos).
     */
    public function canonicalFragment(string $xml, string $element, string $xmlns): string
    {
        $fragment = $this->extractFragment($xml, $element);

        $fragment = str_replace(
            "<{$element} ",
            "<{$element} xmlns=\"{$xmlns}\" ",
            $fragment,
        );

        return $this->canonicalizeStandalone($fragment);
    }

    protected function signedInfo(string $digest, string $id): string
    {
        return '<SignedInfo xmlns="'.self::DSIG_XMLNS.'">'
            .'<CanonicalizationMethod Algorithm="'.self::C14N_ALGO.'"></CanonicalizationMethod>'
            .'<SignatureMethod Algorithm="'.self::RSA_SHA1_ALGO.'"></SignatureMethod>'
            .'<Reference URI="#'.$id.'"><Transforms>'
            .'<Transform Algorithm="'.self::ENVELOPED_ALGO.'"></Transform>'
            .'<Transform Algorithm="'.self::C14N_ALGO.'"></Transform></Transforms>'
            .'<DigestMethod Algorithm="'.self::SHA1_ALGO.'"></DigestMethod>'
            .'<DigestValue>'.$digest.'</DigestValue>'
            .'</Reference></SignedInfo>';
    }

    /**
     * SignedInfo embutido no documento: sem o xmlns (herda do <Signature>) —
     * com C14N inclusivo a canonicalização reproduz exatamente os bytes
     * assinados na forma standalone.
     */
    protected function embeddedSignedInfo(string $id, string $digest): string
    {
        return str_replace('<SignedInfo xmlns="'.self::DSIG_XMLNS.'">', '<SignedInfo>', $this->signedInfo($digest, $id));
    }

    /**
     * @param  string  $xml  Documento standalone (namespace no ápice já presente).
     */
    protected function canonicalizeStandalone(string $xml): string
    {
        $document = new DOMDocument;

        try {
            $loaded = $document->loadXML($xml);
        } catch (Throwable) {
            $loaded = false;
        }

        $canonical = $loaded && $document->documentElement !== null
            ? $document->documentElement->C14N(false, false)
            : false;

        if ($canonical === false || $canonical === '') {
            throw new SefazException('Falha ao canonicalizar (C14N) o fragmento XML.');
        }

        return $canonical;
    }

    /**
     * Recorte cru do elemento assinado (do `<{$element} ` ao `</{$element}>`),
     * sem re-serialização — os bytes têm de permanecer idênticos aos originais.
     */
    protected function extractFragment(string $xml, string $element): string
    {
        $start = strpos($xml, "<{$element} ");

        if ($start === false) {
            $start = strpos($xml, "<{$element}>");
        }

        $endTag = "</{$element}>";
        $end = $start === false ? false : strpos($xml, $endTag, $start);

        if ($start === false || $end === false) {
            throw new SefazException("Elemento <{$element}> não encontrado no XML para assinatura.");
        }

        return substr($xml, $start, $end + strlen($endTag) - $start);
    }

    protected function rootElementName(string $xml): string
    {
        if (preg_match('/<([A-Za-z_][\w.-]*)/', $xml, $matches) === 1) {
            return $matches[1];
        }

        throw new SefazException('XML sem elemento raiz reconhecível.');
    }

    protected function firstTagValue(string $xml, string $tag): ?string
    {
        if (preg_match('/<'.$tag.'>([^<]*)<\/'.$tag.'>/', $xml, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }

    protected function firstTagAttribute(string $xml, string $tag, string $attribute): ?string
    {
        if (preg_match('/<'.$tag.'\b[^>]*\b'.$attribute.'="([^"]*)"/', $xml, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }
}
