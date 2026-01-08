<?php
/**
 * Theme System for Web-Panahan
 * Provides light/dark theme support with persistence
 */

/**
 * Get the Tailwind configuration with dark mode enabled
 * Include this in the <head> section
 */
function getThemeTailwindConfig() {
    return <<<'JS'
    tailwind.config = {
        darkMode: 'class',
        theme: {
            extend: {
                colors: {
                    'archery': {
                        50: '#f0fdf4',
                        100: '#dcfce7',
                        200: '#bbf7d0',
                        300: '#86efac',
                        400: '#4ade80',
                        500: '#22c55e',
                        600: '#16a34a',
                        700: '#15803d',
                        800: '#166534',
                        900: '#14532d',
                    }
                }
            }
        }
    }
JS;
}

/**
 * Get the theme initialization script
 * This MUST be placed in <head> or at the very start of <body> to prevent flash
 */
function getThemeInitScript() {
    return <<<'JS'
(function() {
    // Get stored theme or detect system preference
    function getTheme() {
        const stored = localStorage.getItem('theme');
        if (stored === 'dark' || stored === 'light') {
            return stored;
        }
        // Fallback to system preference
        return window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
    }

    // Apply theme immediately to prevent flash
    const theme = getTheme();
    const html = document.documentElement;
    html.classList.remove('light', 'dark');
    html.classList.add(theme);

    // Make app feel native (non-selectable)
    const style = document.createElement('style');
    style.innerHTML = `
        body { 
            -webkit-user-select: none; 
            -moz-user-select: none; 
            -ms-user-select: none; 
            user-select: none; 
        }
        input, textarea, [contenteditable="true"], .selectable { 
            -webkit-user-select: text; 
            -moz-user-select: text; 
            -ms-user-select: text; 
            user-select: text; 
        }
    `;
    document.head.appendChild(style);

    // Store for consistency
    if (!localStorage.getItem('theme')) {
        localStorage.setItem('theme', theme);
    }
})();
JS;
}

/**
 * Get the theme toggle handler script
 * Place this after the DOM is ready or at end of body
 */
function getThemeToggleScript() {
    return <<<'JS'
// Theme Toggle Functionality
(function() {
    function toggleTheme() {
        const html = document.documentElement;
        const isDark = html.classList.contains('dark');
        const newTheme = isDark ? 'light' : 'dark';

        html.classList.remove('light', 'dark');
        html.classList.add(newTheme);
        localStorage.setItem('theme', newTheme);

        // Update toggle button icons
        updateToggleIcons(newTheme);
    }

    function updateToggleIcons(theme) {
        const sunIcons = document.querySelectorAll('.theme-icon-sun');
        const moonIcons = document.querySelectorAll('.theme-icon-moon');

        sunIcons.forEach(icon => {
            icon.style.display = theme === 'dark' ? 'block' : 'none';
        });
        moonIcons.forEach(icon => {
            icon.style.display = theme === 'light' ? 'block' : 'none';
        });
    }

    // Initialize icons on load
    const currentTheme = localStorage.getItem('theme') ||
        (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
    updateToggleIcons(currentTheme);

    // Attach click handlers to all theme toggle buttons
    document.querySelectorAll('.theme-toggle-btn').forEach(btn => {
        btn.addEventListener('click', toggleTheme);
    });

    // Listen for system theme changes
    window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', (e) => {
        if (!localStorage.getItem('theme')) {
            const newTheme = e.matches ? 'dark' : 'light';
            document.documentElement.classList.remove('light', 'dark');
            document.documentElement.classList.add(newTheme);
            updateToggleIcons(newTheme);
        }
    });
})();
JS;
}

/**
 * Get the theme toggle button HTML
 * @param string $extraClasses Additional CSS classes
 */
function getThemeToggleButton($extraClasses = '') {
    return <<<HTML
<button type="button"
        class="theme-toggle-btn p-2 rounded-lg text-slate-500 dark:text-zinc-400 hover:bg-slate-100 dark:hover:bg-zinc-800 transition-colors {$extraClasses}"
        title="Toggle theme"
        aria-label="Toggle dark mode">
    <!-- Sun icon - shown in dark mode (click to go light) -->
    <svg class="theme-icon-sun w-5 h-5" style="display: none;" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364 6.364l-.707-.707M6.343 6.343l-.707-.707m12.728 0l-.707.707M6.343 17.657l-.707.707M16 12a4 4 0 11-8 0 4 4 0 018 0z" />
    </svg>
    <!-- Moon icon - shown in light mode (click to go dark) -->
    <svg class="theme-icon-moon w-5 h-5" style="display: none;" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z" />
    </svg>
</button>
HTML;
}
?>
