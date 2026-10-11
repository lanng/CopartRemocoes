<?php

namespace Tests\Feature\Services\Dfe;

use App\Services\Dfe\XsdValidator;
use DomainException;
use Tests\TestCase;

class XsdValidatorTest extends TestCase
{
    protected XsdValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();

        config(['dfe.xsd_path' => resource_path('xsds/cte')]);

        $this->validator = app(XsdValidator::class);
    }

    public function test_validates_the_real_authorized_gabarito_proc(): void
    {
        $proc = (string) file_get_contents(__DIR__.'/../../../Fixtures/Dfe/cte-2962.xml');

        $this->validator->validateProcCte($proc);

        $this->assertTrue(true);
    }

    public function test_validates_the_real_authorized_homologacao_cte(): void
    {
        $cte = (string) file_get_contents(__DIR__.'/../../../Fixtures/Dfe/cte-homolog-autorizado.xml');

        $this->validator->validateCte($cte);

        $this->assertTrue(true);
    }

    public function test_rejects_a_cte_with_an_unknown_element(): void
    {
        $cte = (string) file_get_contents(__DIR__.'/../../../Fixtures/Dfe/cte-homolog-autorizado.xml');
        $tampered = str_replace('<natOp>PREST SERV TRANSPORTE</natOp>', '<natOp>PREST SERV TRANSPORTE</natOp><grupoInexistente>x</grupoInexistente>', $cte);

        try {
            $this->validator->validateCte($tampered);
            $this->fail('Expected DomainException.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('cte_v4.00.xsd', $exception->getMessage());
            $this->assertStringContainsString('linha', $exception->getMessage());
        }
    }
}
