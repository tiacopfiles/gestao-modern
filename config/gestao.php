<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Interface da base legada
    |--------------------------------------------------------------------------
    |
    | O Gestão nasceu para rodar em cima do banco do sistema antigo: as telas de
    | Contas a pagar / Contas a receber / Movimentos / Conciliação leem as
    | tabelas herdadas `lancamentos`, `recebimentos`, `movimentos` e
    | `conciliacoes`, que não têm migration neste projeto.
    |
    | Numa instalação alimentada por sincronização com os sistemas de origem,
    | essa camada não tem uso: os títulos reais ficam em `financial_titles`.
    | Pior, ela vira armadilha — um título cadastrado ali não aparece na
    | conciliação, no extrato nem nos totais, e ninguém o encontra depois.
    |
    | Desligar esconde essas telas do menu. As rotas e as tabelas continuam
    | existindo, então nada quebra e a volta é imediata.
    |
    */

    'legacy_ui' => (bool) env('GESTAO_LEGACY_UI', true),

    /*
    |--------------------------------------------------------------------------
    | Telas que dependem do extrato bancário (OFX)
    |--------------------------------------------------------------------------
    |
    | `Extrato` e `Extratos bancários` leem `bank_transactions`, alimentada
    | exclusivamente pela importação de OFX. Enquanto o OFX não entrar, as duas
    | ficam permanentemente vazias — e `Extrato` é pior do que vazia: repete as
    | colunas da Conciliação (data, histórico, entrada, saída, saldo) sem nenhum
    | dado, então quem abre conclui que o sistema perdeu os lançamentos.
    |
    | Ligar traz as duas de volta ao menu, sob "Recursos futuros". Rotas,
    | controllers, serviços e testes seguem existindo com a flag desligada.
    |
    */

    'recursos_futuros' => (bool) env('GESTAO_RECURSOS_FUTUROS', false),

];
