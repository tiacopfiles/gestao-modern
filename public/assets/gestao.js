(() => {
    const shell = document.querySelector('[data-shell]');
    const toggle = document.querySelector('[data-menu-toggle]');
    if (!shell || !toggle) return;
    toggle.addEventListener('click', () => shell.classList.toggle('menu-open'));
    document.addEventListener('click', (event) => {
        if (window.innerWidth > 820 || !shell.classList.contains('menu-open')) return;
        if (!event.target.closest('[data-sidebar]') && !event.target.closest('[data-menu-toggle]')) {
            shell.classList.remove('menu-open');
        }
    });
})();
