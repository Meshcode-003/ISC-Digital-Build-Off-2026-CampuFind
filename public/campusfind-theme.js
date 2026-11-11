(function () {
    'use strict';

    var storageKey = 'campusfind-theme';
    var root = document.documentElement;
    var theme = 'light';

    try {
        var savedTheme = window.localStorage.getItem(storageKey);
        if (savedTheme === 'light' || savedTheme === 'dark') {
            theme = savedTheme;
        }
    } catch (error) {
        console.warn('CampusFind could not read the saved theme preference.', error);
    }

    function applyTheme(nextTheme, persist) {
        theme = nextTheme === 'dark' ? 'dark' : 'light';
        root.setAttribute('data-cf-theme', theme);
        root.style.colorScheme = theme;

        if (!persist) {
            return;
        }

        try {
            window.localStorage.setItem(storageKey, theme);
        } catch (error) {
            console.warn('CampusFind could not save the theme preference.', error);
        }
    }

    applyTheme(theme, false);

    function addThemeToggle() {
        if (document.getElementById('cf-theme-toggle')) {
            return;
        }

        var toggle = document.createElement('button');
        toggle.id = 'cf-theme-toggle';
        toggle.type = 'button';

        function updateToggle() {
            var darkMode = theme === 'dark';
            var icon = darkMode
                ? '<svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle cx="12" cy="12" r="4" stroke="currentColor" stroke-width="2"/><path d="M12 2v2m0 16v2M4.93 4.93l1.42 1.42m11.3 11.3 1.42 1.42M2 12h2m16 0h2M4.93 19.07l1.42-1.42m11.3-11.3 1.42-1.42" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>'
                : '<svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M20.2 15.6A8.5 8.5 0 0 1 8.4 3.8 8.5 8.5 0 1 0 20.2 15.6Z" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/></svg>';

            toggle.innerHTML = icon + '<span>' + (darkMode ? 'Light mode' : 'Dark mode') + '</span>';
            toggle.setAttribute('aria-label', darkMode ? 'Switch to light mode' : 'Switch to dark mode');
            toggle.setAttribute('aria-pressed', darkMode ? 'true' : 'false');
            toggle.title = darkMode ? 'Switch to light mode' : 'Switch to dark mode';
        }

        updateToggle();
        toggle.addEventListener('click', function () {
            applyTheme(theme === 'dark' ? 'light' : 'dark', true);
            updateToggle();
        });

        var host = document.querySelector('.account, .header-actions, .topbar-actions, .navbar, .module-header, .notice-header, .superadmin-header, .header, .sidebar');
        if (host) {
            host.appendChild(toggle);
        } else {
            toggle.classList.add('cf-theme-toggle-floating');
            document.body.appendChild(toggle);
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', addThemeToggle, { once: true });
    } else {
        addThemeToggle();
    }
}());
