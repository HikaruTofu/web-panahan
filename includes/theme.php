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
    <!-- Backdrop with enhanced blur -->
    <div class="fixed inset-0 bg-slate-900/60 backdrop-blur-md transition-opacity opacity-0" id="confirmBackdrop"></div>
    
    <!-- Modal Panel -->
    <div class="fixed inset-0 z-10 w-screen overflow-y-auto">
        <div class="flex min-h-full items-center justify-center p-4 text-center sm:p-0">
            <div class="relative transform overflow-hidden rounded-2xl bg-white dark:bg-zinc-900 text-left shadow-2xl transition-all sm:my-8 sm:w-full sm:max-w-md opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95" id="confirmPanel">
                <!-- Header with Gradient -->
                <div id="confirmHeader" class="bg-gradient-to-br from-slate-700 to-slate-900 text-white px-6 py-4 flex items-center justify-between">
                    <h3 class="font-bold text-lg flex items-center gap-2" id="confirmTitle">
                        <i id="confirmIcon" class="fas fa-exclamation-triangle"></i>
                        Confirm
                    </h3>
                    <button type="button" onclick="closeConfirmModal()" class="p-2 rounded-lg hover:bg-white/10 transition-colors">
                        <i class="fas fa-times"></i>
                    </button>
                </div>

                <!-- Content Area -->
                <div class="p-6">
                    <div id="confirmMessage" class="text-slate-700 dark:text-zinc-300 text-sm leading-relaxed">
                        Apakah Anda yakin ingin melanjutkan tindakan ini?
                    </div>
                </div>

                <!-- Footer with Buttons -->
                <div class="bg-slate-50 dark:bg-zinc-800/50 px-6 py-4 border-t border-slate-200 dark:border-zinc-700 flex flex-row-reverse gap-3">
                    <button type="button" id="confirmBtn" class="flex-1 inline-flex justify-center rounded-xl px-4 py-2.5 text-sm font-bold text-white shadow-sm transition-all active:scale-95">
                        Ya, Lanjutkan
                    </button>
                    <button type="button" id="cancelBtn" class="flex-1 inline-flex justify-center rounded-xl bg-white dark:bg-zinc-800 px-4 py-2.5 text-sm font-bold text-slate-700 dark:text-zinc-300 shadow-sm ring-1 ring-inset ring-slate-300 dark:ring-zinc-700 hover:bg-slate-50 dark:hover:bg-zinc-700 transition-all active:scale-95">
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
        const header = document.getElementById('confirmHeader');
        const titleEl = document.getElementById('confirmHeader').querySelector('h3'); // Select the H3 inside header
        const msgEl = document.getElementById('confirmMessage');
        const icon = document.getElementById('confirmIcon');
        const confirmBtn = document.getElementById('confirmBtn');

        // Allow HTML in title and message as requested
        titleEl.innerHTML = `<i id="confirmIcon" class="fas"></i> ${title}`;
        msgEl.innerHTML = message;
        confirmCallback = callback;

        const iconEl = document.getElementById('confirmIcon');

        // Type styling aligned with users.php standards
        if (type === 'danger') {
             header.className = 'bg-gradient-to-br from-red-600 to-red-800 text-white px-6 py-4 flex items-center justify-between';
             iconEl.className = 'fas fa-trash-alt';
             confirmBtn.className = 'flex-1 inline-flex justify-center rounded-xl bg-red-600 px-4 py-2.5 text-sm font-bold text-white shadow-sm hover:bg-red-700 transition-all active:scale-95';
        } else if (type === 'warning') {
             header.className = 'bg-gradient-to-br from-amber-500 to-amber-700 text-white px-6 py-4 flex items-center justify-between';
             iconEl.className = 'fas fa-exclamation-triangle';
             confirmBtn.className = 'flex-1 inline-flex justify-center rounded-xl bg-amber-600 px-4 py-2.5 text-sm font-bold text-white shadow-sm hover:bg-amber-700 transition-all active:scale-95';
        } else {
             header.className = 'bg-gradient-to-br from-blue-600 to-blue-800 text-white px-6 py-4 flex items-center justify-between';
             iconEl.className = 'fas fa-info-circle';
             confirmBtn.className = 'flex-1 inline-flex justify-center rounded-xl bg-blue-600 px-4 py-2.5 text-sm font-bold text-white shadow-sm hover:bg-blue-700 transition-all active:scale-95';
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
