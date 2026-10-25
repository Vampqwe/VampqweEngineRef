/**
 * Интерактивность редактора config.env
 */
(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        const searchInput = document.getElementById('config-search');
        const form = document.getElementById('config-form');
        if (!form) return;

        // ─── Поиск по ключам ──
        if (searchInput) {
            searchInput.addEventListener('input', function () {
                const query = this.value.toLowerCase().trim();
                const rows = form.querySelectorAll('.config-key-row');
                const sections = form.querySelectorAll('.config-section');

                rows.forEach(function (row) {
                    const key = (row.dataset.key || '').toLowerCase();
                    const input = row.querySelector('input, select');
                    const val = input ? input.value.toLowerCase() : '';
                    const match = !query || key.includes(query) || val.includes(query);
                    row.style.display = match ? '' : 'none';
                });

                // Сворачиваем секции без видимых ключей
                sections.forEach(function (section) {
                    const visibleRows = section.querySelectorAll('.config-key-row:not([style*="display: none"])');
                    if (query && visibleRows.length === 0) {
                        section.removeAttribute('open');
                    } else if (query) {
                        section.setAttribute('open', '');
                    }
                });
            });
        }

        // ── Toggle видимости паролей ───
        form.querySelectorAll('.config-toggle-visibility').forEach(function (btn) {
            btn.addEventListener('click', function () {
                const input = this.parentElement.querySelector('input');
                if (!input) return;
                if (input.type === 'password') {
                    input.type = 'text';
                    this.textContent = '🙈';
                } else {
                    input.type = 'password';
                    this.textContent = '👁️';
                }
            });
        });

        // ─── Обновление текста чекбокса bool ───
        form.querySelectorAll('input[type="checkbox"][name^="cfg_"]').forEach(function (cb) {
            cb.addEventListener('change', function () {
                const label = this.parentElement.querySelector('.config-bool-text');
                if (label) label.textContent = this.checked ? 'включено' : 'выключено';
            });
        });

        // ─── Подсветка изменённых полей ───
        form.querySelectorAll('.config-input').forEach(function (input) {
            const original = input.defaultValue;
            input.addEventListener('input', function () {
                if (this.value !== original) {
                    this.classList.add('config-changed');
                } else {
                    this.classList.remove('config-changed');
                }
            });
        });

        // ─── Ctrl+S для сохранения ───
        document.addEventListener('keydown', function (e) {
            if ((e.ctrlKey || e.metaKey) && e.key === 's') {
                e.preventDefault();
                if (confirm('Сохранить изменения в config.env?')) {
                    form.submit();
                }
            }
        });
    });
})();