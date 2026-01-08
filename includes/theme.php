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
/**
 * Get the global confirmation modal HTML
 * Include this at the end of the body
 */
function getConfirmationModal() {
    return <<<'HTML'
<div id="confirmModal" class="fixed inset-0 z-[100] hidden" role="dialog" aria-modal="true" aria-labelledby="modal-title">
    <!-- Backdrop -->
    <div class="fixed inset-0 bg-slate-900/50 backdrop-blur-sm transition-opacity opacity-0" id="confirmBackdrop"></div>
    
    <!-- Modal Panel -->
    <div class="fixed inset-0 z-10 w-screen overflow-y-auto">
        <div class="flex min-h-full items-end justify-center p-4 text-center sm:items-center sm:p-0">
            <div class="relative transform overflow-hidden rounded-2xl bg-white dark:bg-zinc-900 text-left shadow-xl transition-all sm:my-8 sm:w-full sm:max-w-md opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95" id="confirmPanel">
                <div class="px-4 pb-4 pt-5 sm:p-6 sm:pb-4">
                    <div class="sm:flex sm:items-start">
                        <div class="mx-auto flex h-12 w-12 flex-shrink-0 items-center justify-center rounded-full bg-slate-100 dark:bg-zinc-800 sm:mx-0 sm:h-10 sm:w-10" id="confirmIconBg">
                            <i class="fas fa-exclamation-triangle text-amber-600 dark:text-amber-500" id="confirmIcon"></i>
                        </div>
                        <div class="mt-3 text-center sm:ml-4 sm:mt-0 sm:text-left">
                            <h3 class="text-base font-bold leading-6 text-slate-900 dark:text-white" id="confirmTitle">
                                Konfirmasi
                            </h3>
                            <div class="mt-2">
                                <p class="text-sm text-slate-500 dark:text-zinc-400" id="confirmMessage">
                                    Apakah Anda yakin ingin melanjutkan tindakan ini?
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="bg-slate-50 dark:bg-zinc-800/50 px-4 py-3 sm:flex sm:flex-row-reverse sm:px-6">
                    <button type="button" id="confirmBtn" class="inline-flex w-full justify-center rounded-xl bg-slate-900 dark:bg-white px-3 py-2 text-sm font-bold text-white dark:text-slate-900 shadow-sm hover:bg-slate-800 dark:hover:bg-zinc-200 sm:ml-3 sm:w-auto transition-colors">
                        Ya, Lanjutkan
                    </button>
                    <button type="button" id="cancelBtn" class="mt-3 inline-flex w-full justify-center rounded-xl bg-white dark:bg-zinc-800 px-3 py-2 text-sm font-bold text-slate-900 dark:text-white shadow-sm ring-1 ring-inset ring-slate-300 dark:ring-zinc-700 hover:bg-slate-50 dark:hover:bg-zinc-700 sm:mt-0 sm:w-auto transition-colors">
                        Batal
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Includes for Flatpickr -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/themes/dark.css">
<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
<script src="https://npmcdn.com/flatpickr/dist/l10n/id.js"></script>
HTML;
}

/**
 * Get scripts for custom UI components (Modal & Flatpickr)
 * Include at the end of body
 */
function getUiScripts() {
    return <<<'JS'
<script>
    // --- Custom Confirmation Modal ---
    let confirmCallback = null;

    function showConfirmModal(title, message, callback, type = 'warning') {
        const modal = document.getElementById('confirmModal');
        const backdrop = document.getElementById('confirmBackdrop');
        const panel = document.getElementById('confirmPanel');
        const titleEl = document.getElementById('confirmTitle');
        const msgEl = document.getElementById('confirmMessage');
        const icon = document.getElementById('confirmIcon');
        const iconBg = document.getElementById('confirmIconBg');
        const confirmBtn = document.getElementById('confirmBtn');

        titleEl.textContent = title;
        msgEl.textContent = message;
        confirmCallback = callback;

        // Type styling
        if (type === 'danger') {
             icon.className = 'fas fa-exclamation-triangle text-red-600 dark:text-red-500';
             iconBg.className = 'mx-auto flex h-12 w-12 flex-shrink-0 items-center justify-center rounded-full bg-red-100 dark:bg-red-900/30 sm:mx-0 sm:h-10 sm:w-10';
             confirmBtn.className = 'inline-flex w-full justify-center rounded-xl bg-red-600 px-3 py-2 text-sm font-bold text-white shadow-sm hover:bg-red-500 sm:ml-3 sm:w-auto transition-colors';
        } else {
             icon.className = 'fas fa-info-circle text-blue-600 dark:text-blue-500';
             iconBg.className = 'mx-auto flex h-12 w-12 flex-shrink-0 items-center justify-center rounded-full bg-blue-100 dark:bg-blue-900/30 sm:mx-0 sm:h-10 sm:w-10';
             confirmBtn.className = 'inline-flex w-full justify-center rounded-xl bg-slate-900 dark:bg-white px-3 py-2 text-sm font-bold text-white dark:text-slate-900 shadow-sm hover:bg-slate-800 dark:hover:bg-zinc-200 sm:ml-3 sm:w-auto transition-colors';
        }

        modal.classList.remove('hidden');
        
        // Animate in
        setTimeout(() => {
            backdrop.classList.remove('opacity-0');
            panel.classList.remove('opacity-0', 'translate-y-4', 'sm:translate-y-0', 'sm:scale-95');
            panel.classList.add('opacity-100', 'translate-y-0', 'sm:scale-100');
        }, 10);
    }

    function closeConfirmModal() {
        const modal = document.getElementById('confirmModal');
        const backdrop = document.getElementById('confirmBackdrop');
        const panel = document.getElementById('confirmPanel');

        backdrop.classList.add('opacity-0');
        panel.classList.remove('opacity-100', 'translate-y-0', 'sm:scale-100');
        panel.classList.add('opacity-0', 'translate-y-4', 'sm:translate-y-0', 'sm:scale-95');

        setTimeout(() => {
            modal.classList.add('hidden');
            confirmCallback = null;
        }, 300);
    }

    document.getElementById('confirmBtn').addEventListener('click', () => {
        if (confirmCallback) confirmCallback();
        closeConfirmModal();
    });

    document.getElementById('cancelBtn').addEventListener('click', closeConfirmModal);

    // --- Flatpickr Initialization ---
    document.addEventListener('DOMContentLoaded', function() {
        const isDark = document.documentElement.classList.contains('dark');
        
        flatpickr(".datepicker", {
            dateFormat: "Y-m-d",
            altInput: true,
            altFormat: "j F Y",
            locale: "id",
            disableMobile: "true", // Force custom picker on mobile
            theme: isDark ? "dark" : "light",
            onChange: function(selectedDates, dateStr, instance) {
                instance.element.dispatchEvent(new Event('change'));
            }
        });
        
        // Re-init on dynamic content if needed
        window.initDatePickers = function() {
             const isDark = document.documentElement.classList.contains('dark');
             flatpickr(".datepicker", {
                dateFormat: "Y-m-d",
                altInput: true,
                altFormat: "j F Y",
                locale: "id",
                disableMobile: "true",
                theme: isDark ? "dark" : "light",
                onChange: function(selectedDates, dateStr, instance) {
                    instance.element.dispatchEvent(new Event('change'));
                }
            });
        };
    });
</script>
JS;
}
?>
