<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Gestão Financeira') · Acop Files</title>
    {{--
        A folha de estilo vai carimbada com a data de modificação do arquivo.
        Sem isso o navegador serve a cópia em cache e uma correção de estilo
        publicada no servidor simplesmente não aparece para quem já usou o
        sistema — o que é pior que não publicar, porque parece que subiu.
        `@filemtime` porque um arquivo ausente não pode derrubar a página
        inteira: sem carimbo o CSS ainda carrega.
    --}}
    <link rel="stylesheet" href="{{ asset('assets/gestao.css') }}?v={{ @filemtime(public_path('assets/gestao.css')) ?: '1' }}">
</head>
<body class="app-body">
<div class="app-shell" data-shell>
    <aside class="sidebar" data-sidebar>
        <a class="brand" href="{{ route('dashboard') }}">
            <span class="brand-mark">G+</span>
            <span class="brand-copy"><strong>Gestão Financeira</strong><small>Acop Files</small></span>
        </a>

        <nav class="nav" aria-label="Navegação principal">
            <a class="nav-link {{ request()->routeIs('dashboard') ? 'active' : '' }}" href="{{ route('dashboard') }}"><span class="nav-icon">⌂</span><span>Dashboard</span></a>

            {{--
                Duas bases convivem no sistema e isso ja confundiu na pratica:
                quem entrou clicou em "Contas a pagar" e viu tela vazia, porque a
                base legada nao tem lancamentos aqui — os titulos sincronizados
                ficam em Titulos. Por isso os itens principais apontam para os
                dados reais, e o bloco legado vem depois e rotulado.

                O menu diario e SO isto: pagar, receber, todos, conciliacao. Cada
                item a mais aqui e um item que a funcionaria precisa descartar
                antes de achar o que procura.
            --}}
            @if(config('reconciliation.v2_enabled', false))
                @can('reconciliation:view')
                    <span class="nav-section">Financeiro</span>
                    <a class="nav-link {{ request()->routeIs('titles.*') && request()->input('type') === 'PAYABLE' ? 'active' : '' }}" href="{{ route('titles.index', ['type' => 'PAYABLE']) }}"><span class="nav-icon">↗</span><span>Contas a pagar</span></a>
                    <a class="nav-link {{ request()->routeIs('titles.*') && request()->input('type') === 'RECEIVABLE' ? 'active' : '' }}" href="{{ route('titles.index', ['type' => 'RECEIVABLE']) }}"><span class="nav-icon">↙</span><span>Contas a receber</span></a>
                    <a class="nav-link {{ request()->routeIs('titles.*') && ! request()->filled('type') ? 'active' : '' }}" href="{{ route('titles.index') }}"><span class="nav-icon">◈</span><span>Todos os títulos</span></a>
                    <a class="nav-link {{ request()->routeIs('period-statements.*') || request()->routeIs('reconciliation-v2.*') ? 'active' : '' }}" href="{{ route('period-statements.index') }}"><span class="nav-icon">✓</span><span>Conciliação</span></a>

                    {{--
                        Extrato e Extratos bancarios dependem de `bank_transactions`,
                        que so e alimentada pela importacao OFX — fora do escopo desta
                        versao. Com a tabela vazia as duas telas so sabem dizer "importe
                        um extrato", e Extrato ainda repete as colunas da Conciliacao
                        com dado nenhum: o usuario conclui que o sistema perdeu os
                        lancamentos. Ficam atras de uma flag ate o OFX entrar; o codigo,
                        as rotas e os testes continuam onde estavam.
                    --}}
                    @if(config('gestao.recursos_futuros', false))
                        <span class="nav-section">Recursos futuros</span>
                        <a class="nav-link {{ request()->routeIs('ledger.*') ? 'active' : '' }}" href="{{ route('ledger.index') }}"><span class="nav-icon">≡</span><span>Extrato bancário (OFX)</span></a>
                        <a class="nav-link {{ request()->routeIs('banking.*') ? 'active' : '' }}" href="{{ route('banking.index') }}"><span class="nav-icon">▤</span><span>Importar extratos</span></a>
                    @endif
                @endcan
            @endif

            {{--
                Base legada: some quando as tabelas nem existem e quando
                `gestao.legacy_ui` esta desligado. Fluxo de caixa entra aqui —
                as telas novas respondem melhor a mesma pergunta. O codigo
                continua no lugar: esconder do menu nao e remover implementacao.
            --}}
            @php($temBaseLegada = config('gestao.legacy_ui', true) && \Illuminate\Support\Facades\Schema::hasTable('lancamentos'))

            @if($temBaseLegada)
                <span class="nav-section">Base legada</span>
                <a class="nav-link {{ request()->routeIs('payables.*') ? 'active' : '' }}" href="{{ route('payables.index') }}"><span class="nav-icon">↗</span><span>A pagar (legado)</span></a>
                <a class="nav-link {{ request()->routeIs('receivables.*') ? 'active' : '' }}" href="{{ route('receivables.index') }}"><span class="nav-icon">↙</span><span>A receber (legado)</span></a>
                <a class="nav-link {{ request()->routeIs('movements.*') ? 'active' : '' }}" href="{{ route('movements.index') }}"><span class="nav-icon">⇄</span><span>Movimentos</span></a>
                <a class="nav-link {{ request()->routeIs('reconciliations.*') ? 'active' : '' }}" href="{{ route('reconciliations.index') }}"><span class="nav-icon">✓</span><span>Conciliação antiga</span></a>
                <a class="nav-link {{ request()->routeIs('cash-flow.*') ? 'active' : '' }}" href="{{ route('cash-flow.index') }}"><span class="nav-icon">≋</span><span>Fluxo de caixa</span></a>
            @endif

            @can('commercial')
            {{--
                Cadastros sincronizados das origens: SO CONSULTA. Quem cadastra e
                o sistema de origem; manter duas manutencoes do mesmo cadastro
                criaria duas verdades sobre o mesmo fornecedor.

                Sete itens de consulta ocupavam metade do menu competindo com as
                quatro telas que se usa todo dia. Recolhidos num grupo que abre
                sozinho quando a pessoa esta dentro de um deles.
            --}}
            @php($emCadastros = request()->routeIs('registries.*'))
            <details class="nav-group" @if($emCadastros) open @endif>
                <summary class="nav-section nav-section-toggle">Cadastros (origem)</summary>
                @foreach([
                    'clientes' => ['◎', 'Clientes'],
                    'fornecedores' => ['◇', 'Fornecedores'],
                    'categorias' => ['◫', 'Categorias'],
                    'centros-custo' => ['⌗', 'Centros de custo'],
                    'tipos' => ['◌', 'Tipos'],
                    'situacoes' => ['●', 'Situações'],
                    'contas' => ['▣', 'Contas bancárias'],
                ] as $tipoCadastro => [$icone, $rotulo])
                    <a class="nav-link {{ $emCadastros && request()->route('kind') === $tipoCadastro ? 'active' : '' }}" href="{{ route('registries.index', $tipoCadastro) }}"><span class="nav-icon">{{ $icone }}</span><span>{{ $rotulo }}</span></a>
                @endforeach
            </details>

            <span class="nav-section">Administração</span>
            <a class="nav-link {{ request()->routeIs('users.*') ? 'active' : '' }}" href="{{ route('users.index') }}"><span class="nav-icon">♙</span><span>Usuários</span></a>
            <a class="nav-link {{ request()->routeIs('audit.*') ? 'active' : '' }}" href="{{ route('audit.index') }}"><span class="nav-icon">≣</span><span>Auditoria</span></a>
            @endcan
        </nav>

        <div class="sidebar-foot"><span class="status-dot"></span><div><strong>Central conectada</strong><small>Base financeira do Gestão</small></div></div>
    </aside>

    <header class="topbar">
        <div class="topbar-left">
            <button class="icon-button menu-button" type="button" data-menu-toggle aria-label="Alternar menu">☰</button>
            <div><small class="breadcrumb">Gestão Financeira / @yield('section', 'Visão geral')</small><h1>@yield('page-title', 'Dashboard')</h1></div>
        </div>
        <div class="topbar-right">
            <div class="environment-pill"><span></span>Gestão 220</div>
            <div class="profile">
                <span class="avatar">{{ mb_strtoupper(mb_substr(auth()->user()->nome ?: auth()->user()->username, 0, 1)) }}</span>
                <div><strong>{{ auth()->user()->nome ?: auth()->user()->username }}</strong><small>{{ auth()->user()->empresa ?: 'Operação financeira' }}</small></div>
                <form action="{{ route('logout') }}" method="post">@csrf<button class="logout-button" type="submit">Sair</button></form>
            </div>
        </div>
    </header>

    <main class="content">
        @if(session('success'))<div class="alert success">{{ session('success') }}</div>@endif
        {{-- Aviso não é erro: a ação deu certo, mas mudou alguma coisa que quem clicou precisa saber. --}}
        @if(session('warning'))<div class="alert" style="border-color:#ecd9aa;background:#fff9e8;color:#815d12">{{ session('warning') }}</div>@endif
        @if($errors->any())<div class="alert danger">{{ $errors->first() }}</div>@endif
        @yield('content')
    </main>
</div>
<script src="{{ asset('assets/gestao.js') }}" defer></script>
</body>
</html>
