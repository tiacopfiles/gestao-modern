<?php

namespace Tests\Feature;

use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * O fuso da aplicação decide mais do que o rótulo de hora nas telas: decide
 * fronteira de dia. `today()`, `now()->startOfMonth()` e `due_date->isPast()`
 * (o badge "Vencido" de Contas a Pagar/Receber) usam `config('app.timezone')`
 * como referência de "agora".
 *
 * O padrão do Laravel é UTC e nunca foi trocado neste projeto — o servidor
 * (Windows, MariaDB) sempre esteve corretamente em horário de Brasília, só o
 * Laravel calculava 3h à frente disso. Das 21h às 23h59 em Brasília, o
 * sistema já achava que era o dia seguinte: um painel de sincronização
 * mostrando hora errada era o sintoma visível, mas um título vencendo amanhã
 * podendo aparecer "Vencido" ainda hoje à noite era o efeito real.
 *
 * Isto não é teste de UTC vs local por preferência — é regra do negócio: o
 * financeiro decide "hoje" pelo relógio de Brasília, e é assim que a origem
 * (contas/contasareceber, ambas em Brasília) também opera.
 */
class ApplicationTimezoneTest extends TestCase
{
    public function test_fuso_da_aplicacao_e_brasilia(): void
    {
        $this->assertSame('America/Sao_Paulo', config('app.timezone'));
    }

    public function test_now_e_today_respeitam_o_fuso_configurado(): void
    {
        $this->assertSame('America/Sao_Paulo', now()->getTimezone()->getName());
        $this->assertSame('America/Sao_Paulo', Carbon::now()->getTimezone()->getName());
        $this->assertSame('America/Sao_Paulo', today()->getTimezone()->getName());
    }
}
