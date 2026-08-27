<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Acessar · Gestão Financeira Acop</title>
    {{-- Mesmo carimbo do layout interno: ver o comentário em layouts/app.blade.php. --}}
    <link rel="stylesheet" href="{{ asset('assets/gestao.css') }}?v={{ @filemtime(public_path('assets/gestao.css')) ?: '1' }}">
</head>
<body class="login-body">
<div class="login-shell">
    <section class="login-brand-panel">
        <div class="login-logo"><span class="brand-mark">G+</span><span><strong>Gestão Financeira</strong><small>Acop Files</small></span></div>
        <div class="login-message"><span class="eyebrow">Central financeira</span><h1>Clareza para decidir.<br>Controle para avançar.</h1><p>Acompanhe pagamentos, recebimentos, movimentos e conciliações em uma única visão operacional.</p></div>
        <div class="login-features"><span>Fluxo financeiro</span><span>Conciliação rastreável</span><span>Operação segura</span></div>
    </section>
    <main class="login-main">
        <form class="login-card" action="{{ route('login.store') }}" method="post">
            @csrf
            <span class="eyebrow">Acesso seguro</span><h2>Boas-vindas</h2><p>Use o mesmo usuário e senha do sistema Gestão existente.</p>
            @if($errors->any())<div class="alert danger">{{ $errors->first() }}</div>@endif
            <label class="field"><span>Usuário</span><input name="username" value="{{ old('username') }}" autocomplete="username" required autofocus placeholder="Digite seu usuário"></label>
            <label class="field"><span>Senha</span>
                <span class="password-field-wrap">
                    <input id="password" name="password" type="password" autocomplete="current-password" required placeholder="Digite sua senha">
                    <button type="button" class="password-toggle" data-password-toggle aria-label="Mostrar senha" aria-pressed="false">
                        <svg class="icon-eye" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8Z"/><circle cx="12" cy="12" r="3"/></svg>
                        <svg class="icon-eye-off" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:none"><path d="M17.94 17.94A10.94 10.94 0 0 1 12 20c-7 0-11-8-11-8a21.8 21.8 0 0 1 5.06-6.94M9.9 4.24A10.94 10.94 0 0 1 12 4c7 0 11 8 11 8a21.8 21.8 0 0 1-2.16 3.19M14.12 14.12a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
                    </button>
                </span>
            </label>
            <button class="button primary large" type="submit">Entrar na plataforma <span>→</span></button>
            <small class="login-help">Acesso restrito a usuários autorizados · Acop Files</small>
        </form>
    </main>
</div>
<script>
(() => {
    const toggle = document.querySelector('[data-password-toggle]');
    const input = document.getElementById('password');
    if (!toggle || !input) return;
    toggle.addEventListener('click', () => {
        const isVisible = input.type === 'text';
        input.type = isVisible ? 'password' : 'text';
        toggle.setAttribute('aria-pressed', String(!isVisible));
        toggle.setAttribute('aria-label', isVisible ? 'Mostrar senha' : 'Ocultar senha');
        toggle.querySelector('.icon-eye').style.display = isVisible ? '' : 'none';
        toggle.querySelector('.icon-eye-off').style.display = isVisible ? 'none' : '';
    });
})();
</script>
</body>
</html>
