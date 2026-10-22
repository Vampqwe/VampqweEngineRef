// FAQ Accordion
document.querySelectorAll('.faq-question').forEach(q => {
    q.addEventListener('click', () => {
        const item = q.parentElement;
        const isActive = item.classList.contains('active');
        document.querySelectorAll('.faq-item').forEach(i => i.classList.remove('active'));
        if (!isActive) item.classList.add('active');
    });
});

// Мобильное выдвижное меню (бургер + автоклон десктопного меню)
(function () {
    var burger = document.getElementById('nav-burger');
    var mobileMenu = document.getElementById('mobile-menu');
    var overlay = document.getElementById('mobile-overlay');
    if (!burger || !mobileMenu || !overlay) return;

    var mobileList = mobileMenu.querySelector('.mobile-menu-list') || null;
    var desktopMenu = document.getElementById('site-nav-menu');

    // ─── Построение мобильного меню из десктопного (без дублей разметки в HTML) ───
    function buildMobileMenu() {
        if (!desktopMenu || !mobileList) return;
        desktopMenu.querySelectorAll(':scope > li').forEach(function (li) {
            var topLink = li.querySelector(':scope > a');
            var dropdown = li.querySelector(':scope > .nav-dropdown');
            var item = document.createElement('li');

            if (dropdown) {
                // Пункт с подменю → кнопка-аккордеон + склонированные группы ссылок
                item.className = 'has-submenu';

                var btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'mobile-parent';
                btn.innerHTML = '<span>' + (topLink ? topLink.textContent.trim().replace(/[▾▴]+$/u, '').trim() : '') + '</span>'
                              + '<span class="mobile-parent-arrow">▾</span>';
                item.appendChild(btn);

                var sub = document.createElement('div');
                sub.className = 'mobile-submenu';
                var inner = document.createElement('div');
                inner.className = 'mobile-submenu-inner';
                dropdown.querySelectorAll('.dropdown-group').forEach(function (group) {
                    inner.appendChild(group.cloneNode(true));
                });
                sub.appendChild(inner);
                item.appendChild(sub);

                // Клик по кнопке: раскрыть/свернуть аккордеоном.
                // Высоту НЕ задаем inline — за неё отвечает CSS
                // (max-height: min(50vh, 420px) + внутренняя прокрутка),
                // иначе очень длинный список не получит скроллбар.
                btn.addEventListener('click', function () {
                    item.classList.toggle('open');
                });
            } else if (topLink) {
                // Обычный пункт → ссылка
                var a = document.createElement('a');
                a.href = topLink.getAttribute('href') || '#';
                a.textContent = topLink.textContent.trim();
                item.appendChild(a);
            } else {
                return;
            }
            mobileList.appendChild(item);
        });

        // Закрытие панели при переходе по ссылке
        mobileList.querySelectorAll('a').forEach(function (link) {
            link.addEventListener('click', closeMenu);
        });
    }
    buildMobileMenu();

    function openMenu() {
        burger.classList.add('active');
        mobileMenu.classList.add('active');
        overlay.classList.add('active');
        burger.setAttribute('aria-expanded', 'true');
        mobileMenu.setAttribute('aria-hidden', 'false');
        document.body.style.overflow = 'hidden';
    }
    function closeMenu() {
        burger.classList.remove('active');
        mobileMenu.classList.remove('active');
        overlay.classList.remove('active');
        burger.setAttribute('aria-expanded', 'false');
        mobileMenu.setAttribute('aria-hidden', 'true');
        document.body.style.overflow = '';
    }
    burger.addEventListener('click', function () {
        mobileMenu.classList.contains('active') ? closeMenu() : openMenu();
    });
    overlay.addEventListener('click', closeMenu);
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') closeMenu();
    });

    // Тач-устройства: открытие выпадающих меню по клику
    if ('ontouchstart' in window) {
        document.querySelectorAll('.nav-menu > li.has-dropdown > a').forEach(function (link) {
            link.addEventListener('click', function (e) {
                var li = link.parentElement;
                if (!li.classList.contains('open')) {
                    e.preventDefault();
                    document.querySelectorAll('.nav-menu > li.has-dropdown.open').forEach(function (other) {
                        if (other !== li) other.classList.remove('open');
                    });
                    li.classList.add('open');
                }
            });
        });
    }
})();