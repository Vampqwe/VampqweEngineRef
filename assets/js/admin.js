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
function initLinkHighlighting(textarea, autoResize) {
    // Подложку создаём только если в контенте вообще есть ссылки —
    // иначе редактор работает как раньше, без изменений внешнего вида.
    const wrap = document.createElement('div');
    wrap.className = 'editor-highlight-wrap';
    textarea.parentNode.insertBefore(wrap, textarea);
    wrap.appendChild(textarea);

    const backdrop = document.createElement('div');
    backdrop.className = 'editor-highlight-backdrop';
    backdrop.setAttribute('aria-hidden', 'true');
    wrap.insertBefore(backdrop, textarea);

    let enabled = false;

    function escapeHtml(s) {
        return s.replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;');
    }

    // Ищем парные <a ...href="...">текст</a> (и одиночные открывающие теги <a>)
    const LINK_RE = /<a\b(?:"[^"]*"|'[^']*'|[^'">])*>[\s\S]*?(?:<\/a\s*>|(?=>))|<a\b(?:"[^"]*"|'[^']*'|[^'"<>])*\/?>/gi;
    const HREF_RE = /href\s*=\s*("([^"]*)"|'([^']*)'|([^\s"'>]+))/i;

    function render() {
        const text = textarea.value;

        // Пересобираем подложку по индексам совпадений: весь текст невидим,
        // ссылки <a ...>...</a> задеты маркером, URL внутри href — ярко.
        let out = '';
        let last = 0;
        let m;
        LINK_RE.lastIndex = 0;
        while ((m = LINK_RE.exec(text)) !== null) {
            out += escapeHtml(text.slice(last, m.index));
            let inner = escapeHtml(m[0]);
            // Кавычки escapeHtml не трогает, поэтому href ищем «как есть»
            inner = inner.replace(HREF_RE, function (h) {
                return '<span class="hl-href">' + h + '</span>';
            });
            out += '<mark class="link-hl">' + inner + '</mark>';
            last = m.index + m[0].length;
            if (m[0].length === 0) LINK_RE.lastIndex++;
        }
        out += escapeHtml(text.slice(last));

        const hasLinks = last > 0;
        if (!hasLinks) {
            backdrop.innerHTML = '';
            if (enabled) {
                enabled = false;
                backdrop.style.visibility = 'hidden';
                textarea.classList.remove('has-highlight');
            }
            return;
        }

        backdrop.innerHTML = out;

        if (!enabled) {
            enabled = true;
            backdrop.style.visibility = 'visible';
            textarea.classList.add('has-highlight');
        }
        syncScroll();
    }

    function syncScroll() {
        backdrop.scrollTop = textarea.scrollTop;
        backdrop.scrollLeft = textarea.scrollLeft;
    }

    // Шрифты/размеры подложки должны совпадать с textarea — берём computed-стили
    const cs = getComputedStyle(textarea);
    ['fontFamily', 'fontSize', 'lineHeight', 'padding', 'letterSpacing', 'tabSize'].forEach(function (p) {
        backdrop.style[p] = cs[p];
    });

    textarea.addEventListener('input', render);
    textarea.addEventListener('scroll', syncScroll);
    render();
}