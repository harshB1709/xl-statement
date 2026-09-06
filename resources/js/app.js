document.addEventListener('DOMContentLoaded', () => {
    const toggle = document.getElementById('theme-toggle');

    if (! toggle) {
        return;
    }

    toggle.addEventListener('click', () => {
        const root = document.documentElement;
        const next = root.classList.contains('dark') ? 'light' : 'dark';
        root.classList.toggle('dark', next === 'dark');

        try {
            localStorage.setItem('xl-theme', next);
        } catch (e) {}
    });
});
