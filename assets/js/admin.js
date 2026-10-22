document.addEventListener('DOMContentLoaded', function () {
    // ── Подсветка активного пункта меню ───
    const currentPath = window.location.pathname;
    document.querySelectorAll('.nav-link').forEach(function (link) {
        if (link.getAttribute('href') === currentPath) {
            link.classList.add('active');
        }
    });

    // ─── Подтверждение выхода ───
    document.querySelectorAll('form[action*="logout"]').forEach(function (form) {
        form.addEventListener('submit', function (e) {
            if (!confirm('Вы уверены, что хотите выйти?')) {
                e.preventDefault();
            }
        });
    });

    // ─── Автогенерация slug из заголовка ───
    const titleInput = document.getElementById('title');
    const slugInput = document.getElementById('slug');
    if (titleInput && slugInput && !slugInput.value) {
        titleInput.addEventListener('input', function () {
            const slug = this.value
                .toLowerCase()
                .replace(/[^a-z0-9\s-]/g, '')
                .replace(/\s+/g, '-')
                .replace(/-+/g, '-')
                .trim();
            slugInput.value = slug;
        });
    }

    // ─── Автоувеличение высоты HTML-редактора контента ───
    const contentArea = document.getElementById('content');
    if (contentArea && contentArea.tagName === 'TEXTAREA') {
        const autoResize = function () {
            contentArea.style.height = 'auto';
            contentArea.style.height = Math.min(contentArea.scrollHeight + 2, 1200) + 'px';
        };
        contentArea.addEventListener('input', autoResize);
        autoResize();

        initLinkHighlighting(contentArea, autoResize);
    }
});

/**
 * Ярко подсвечивает все ссылки <a href="..."> в HTML-редакторе.
 *
 * Принцип: под textarea размещается «подложка» (div), в которой весь текст
 * невидим, а теги <a ...>...</a> задеты жёлтым маркером и сам URL внутри
 * href выделен ярко-жёлтым. Текст textarea делается полупрозрачным, поэтому
 * подсветки видны ровно под нужными участками кода.
 */
/**
 * Оптимизированная подсветка ссылок <a href="..."> в HTML-редакторе.
 * 
 * Изменения:
 *  - Исправлено регулярное выражение (убраны лишние пробелы)
 *  - Добавлен debounce (150 мс)
 *  - requestAnimationFrame для неблокирующего рендера
 *  - Пропуск обработки если текст < 300 символов и нет '<a'
 *  - Ограничение максимального размера (50000 символов)
 */
function initLinkHighlighting(textarea, autoResize) {
    const wrap = document.createElement('div');
    wrap.className = 'editor-highlight-wrap';
    textarea.parentNode.insertBefore(wrap, textarea);
    wrap.appendChild(textarea);
    
    const backdrop = document.createElement('div');
    backdrop.className = 'editor-highlight-backdrop';
    backdrop.setAttribute('aria-hidden', 'true');
    wrap.insertBefore(backdrop, textarea);
    
    let enabled = false;
    let renderScheduled = false;
    let debounceTimer = null;
    
    function escapeHtml(s) {
        return s.replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;');
    }
    
    // Исправленное регулярное выражение (без пробелов внутри)
    const LINK_RE = /<a\b(?:\s+[^>]*)?>[\s\S]*?(?:<\/a\s*>|(?=>))|<a\b(?:\s+[^>]*)?\/?>/gi;
    const HREF_RE = /href\s*=\s*(?:"([^"]*)"|'([^']*)'|([^\s"'&>]+))/i;
    
    function render() {
        const text = textarea.value;
        
        // Оптимизация 1: пропуск если текст короткий и нет ссылок
        if (text.length < 300 && text.indexOf('<a') === -1) {
            if (enabled) {
                backdrop.innerHTML = '';
                backdrop.style.visibility = 'hidden';
                textarea.classList.remove('has-highlight');
                enabled = false;
            }
            return;
        }
        
        // Оптимизация 2: ограничение максимального размера
        if (text.length > 50000) {
            backdrop.innerHTML = '<span style="color: #ff9800;">⚠ Текст слишком большой для подсветки (>50KB)</span>';
            backdrop.style.visibility = 'visible';
            enabled = true;
            return;
        }
        
        let out = '';
        let last = 0;
        let m;
        let hasLinks = false;
        
        LINK_RE.lastIndex = 0;
        while ((m = LINK_RE.exec(text)) !== null) {
            hasLinks = true;
            out += escapeHtml(text.slice(last, m.index));
            let inner = escapeHtml(m[0]);
            inner = inner.replace(HREF_RE, function (h) {
                return '<span class="hl-href">' + h + '</span>';
            });
            out += '<mark class="link-hl">' + inner + '</mark>';
            last = m.index + m[0].length;
            if (m[0].length === 0) LINK_RE.lastIndex++;
        }
        out += escapeHtml(text.slice(last));
        
        if (!hasLinks) {
            backdrop.innerHTML = '';
            if (enabled) {
                backdrop.style.visibility = 'hidden';
                textarea.classList.remove('has-highlight');
                enabled = false;
            }
            return;
        }
        
        backdrop.innerHTML = out;
        if (!enabled) {
            backdrop.style.visibility = 'visible';
            textarea.classList.add('has-highlight');
            enabled = true;
        }
        syncScroll();
    }
    
    function scheduleRender() {
        if (!renderScheduled) {
            renderScheduled = true;
            requestAnimationFrame(function () {
                render();
                renderScheduled = false;
            });
        }
    }
    
    function debouncedRender() {
        clearTimeout(debounceTimer);
        debounceTimer = setTimeout(scheduleRender, 150);
    }
    
    function syncScroll() {
        backdrop.scrollTop = textarea.scrollTop;
        backdrop.scrollLeft = textarea.scrollLeft;
    }
    
    // Копируем стили шрифта
    const cs = getComputedStyle(textarea);
    ['fontFamily', 'fontSize', 'lineHeight', 'padding', 'letterSpacing', 'tabSize'].forEach(function (p) {
        backdrop.style[p] = cs[p];
    });
    
    // Обработчики с debounce
    textarea.addEventListener('input', debouncedRender);
    textarea.addEventListener('scroll', syncScroll);
    
    // Первоначальный рендер
    scheduleRender();
}