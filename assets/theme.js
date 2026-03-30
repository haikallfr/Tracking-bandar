(() => {
    const storageKey = 'tracking_bandar_theme';

    function preferredTheme() {
        const saved = localStorage.getItem(storageKey);
        if (saved === 'dark' || saved === 'light') {
            return saved;
        }

        return window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
    }

    function applyTheme(theme) {
        if (theme === 'dark') {
            document.documentElement.dataset.theme = 'dark';
        } else {
            delete document.documentElement.dataset.theme;
        }
    }

    function syncButton(button, theme) {
        button.textContent = theme === 'dark' ? '☀' : '☾';
        button.setAttribute('aria-label', theme === 'dark' ? 'Aktifkan mode terang' : 'Aktifkan mode gelap');
        button.setAttribute('title', theme === 'dark' ? 'Mode terang' : 'Mode gelap');
    }

    const button = document.getElementById('theme-toggle');
    if (!button) return;

    let theme = preferredTheme();
    applyTheme(theme);
    syncButton(button, theme);

    button.addEventListener('click', () => {
        theme = theme === 'dark' ? 'light' : 'dark';
        localStorage.setItem(storageKey, theme);
        applyTheme(theme);
        syncButton(button, theme);
    });
})();
