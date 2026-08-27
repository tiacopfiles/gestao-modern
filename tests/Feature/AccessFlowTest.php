<?php

namespace Tests\Feature;

use Tests\TestCase;

class AccessFlowTest extends TestCase
{
    public function test_login_page_is_available(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertSee('Gestão Financeira')
            ->assertSee('Entrar na plataforma');
    }

    public function test_dashboard_requires_authentication(): void
    {
        $this->get('/')->assertRedirect('/login');
    }

    public function test_financial_modules_require_authentication(): void
    {
        foreach (['/contas-a-pagar', '/contas-a-receber', '/movimentos', '/conciliacoes'] as $uri) {
            $this->get($uri)->assertRedirect('/login');
        }
    }
}
