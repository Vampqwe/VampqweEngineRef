/**
 * diagnostika.103vet — единый оптимизированный скрипт для всех страниц.
 * Подключать так: <script src="/main.js" defer></script> перед </body>.
 *
 * Объединяет всю JS-логику, которая ранее дублировалась инлайном на страницах:
 *  1. Шапка: эффект .scrolled при прокрутке (rAF-троттлинг).
 *  2. Мобильное меню: бургер (.mobile-menu-btn) + закрытие по Escape / клику вне.
 *  3. FAQ-аккордеоны: делегирование событий, поддержка .faq-* и .blog-faq-*.
 *  4. Reveal-анимации (.reveal) через IntersectionObserver.
 *  5. Параллакс декоративных форм (.shape) с mousemove (rAF-троттлинг, off на touch).
 *
 * Все блоки null-safe: на странице без соответствующих элементов ошибок не будет.
 */
(function () {
    'use strict';

    var prefersReducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    var isCoarsePointer = window.matchMedia('(pointer: coarse)').matches;

    /* ---- 1. Шапка: класс .scrolled после 50px (trailing rAF-троттлинг) ---- */
    var nav = document.getElementById('nav');
    if (nav) {
        var scrolledTicking = false;
        var lastScrollY = window.scrollY;

        var applyScrolled = function () {
            nav.classList.toggle('scrolled', lastScrollY > 50);
            scrolledTicking = false;
        };

        window.addEventListener('scroll', function () {
            lastScrollY = window.scrollY;
            if (!scrolledTicking) {
                requestAnimationFrame(applyScrolled);
                scrolledTicking = true;
            }
        }, { passive: true });

        applyScrolled(); // корректное состояние при загрузке со смещённым скроллом
    }

    /* ---- 2. Мобильное меню ---- */
    var mobileBtn = document.querySelector('.mobile-menu-btn');
    var navLinks = document.querySelector('.nav-links-premium') || document.getElementById('navLinks');

    if (mobileBtn && navLinks) {
        var closeMobileMenu = function () {
            navLinks.classList.remove('mobile-open');
            mobileBtn.classList.remove('active');
            mobileBtn.setAttribute('aria-expanded', 'false');
        };

        var openMobileMenu = function () {
            navLinks.classList.add('mobile-open');
            mobileBtn.classList.add('active');
            mobileBtn.setAttribute('aria-expanded', 'true');
        };

        mobileBtn.setAttribute('aria-expanded', 'false');

        mobileBtn.addEventListener('click', function () {
            if (navLinks.classList.contains('mobile-open')) {
                closeMobileMenu();
            } else {
                openMobileMenu();
            }
        });

        // Закрытие при клике по любой ссылке внутри меню
        navLinks.addEventListener('click', function (e) {
            if (e.target.closest('a')) closeMobileMenu();
        });

        // Закрытие по Escape + сброс состояния при resize выше мобильной границы
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && navLinks.classList.contains('mobile-open')) {
                closeMobileMenu();
                mobileBtn.focus();
            }
        });

        window.addEventListener('resize', function () {
            if (window.innerWidth > 768) closeMobileMenu();
        }, { passive: true });
    }

    /* ---- 3. FAQ-аккордеоны (делегирование: работает для .faq-* и .blog-faq-*) ---- */
    var toggleFaqItem = function (item) {
        var prefix = item.classList.contains('blog-faq-item') ? 'blog-' : '';
        var question = item.querySelector('.' + prefix + 'faq-question');
        var answer = item.querySelector('.' + prefix + 'faq-answer');
        if (!question || !answer) return;

        var isActive = item.classList.contains('active');

        // Закрыть все открытые элементы в том же списке
        var list = item.parentElement;
        var siblings = list.querySelectorAll(':scope > .' + prefix + 'faq-item');
        siblings.forEach(function (i) {
            i.classList.remove('active');
            var q = i.querySelector('.' + prefix + 'faq-question');
            var a = i.querySelector('.' + prefix + 'faq-answer');
            if (q) q.setAttribute('aria-expanded', 'false');
            if (a) a.style.maxHeight = '0';
        });

        if (!isActive) {
            item.classList.add('active');
            question.setAttribute('aria-expanded', 'true');
            answer.style.maxHeight = answer.scrollHeight + 'px';
        }
    };

    document.addEventListener('click', function (e) {
        var question = e.target.closest('.faq-question, .blog-faq-question');
        if (!question) return;
        var item = question.closest('.faq-item, .blog-faq-item');
        if (item) toggleFaqItem(item);
    });

    // Пересчёт высоты открытого ответа при resize (текст может перестроиться)
    window.addEventListener('resize', function () {
        document.querySelectorAll('.faq-item.active .faq-answer, .blog-faq-item.active .blog-faq-answer')
            .forEach(function (a) { a.style.maxHeight = a.scrollHeight + 'px'; });
    }, { passive: true });

    /* ---- 4. Reveal-анимации ---- */
    var revealEls = document.querySelectorAll('.reveal');
    if (revealEls.length) {
        if (prefersReducedMotion || !('IntersectionObserver' in window)) {
            revealEls.forEach(function (el) { el.classList.add('revealed'); });
        } else {
            var revealObserver = new IntersectionObserver(function (entries) {
                entries.forEach(function (entry) {
                    if (entry.isIntersecting) {
                        entry.target.classList.add('revealed');
                        revealObserver.unobserve(entry.target);
                    }
                });
            }, { threshold: 0.1, rootMargin: '0px 0px -50px 0px' });

            revealEls.forEach(function (el) { revealObserver.observe(el); });
        }
    }

    /* ---- 5. Параллакс декоративных форм (.shape) ---- */
    var shapes = document.querySelectorAll('.shape');
    if (shapes.length && !isCoarsePointer && !prefersReducedMotion) {
        var moveTicking = false;
        var lastMouseEvent = null;

        var applyParallax = function () {
            var x = lastMouseEvent.clientX / window.innerWidth;
            var y = lastMouseEvent.clientY / window.innerHeight;
            shapes.forEach(function (shape, index) {
                var speed = (index + 1) * 15;
                shape.style.transform =
                    'translate(' + ((0.5 - x) * speed) + 'px, ' + ((0.5 - y) * speed) + 'px)';
            });
            moveTicking = false;
        };

        document.addEventListener('mousemove', function (e) {
            lastMouseEvent = e;
            if (!moveTicking) {
                requestAnimationFrame(applyParallax);
                moveTicking = true;
            }
        }, { passive: true });
    }
})();
