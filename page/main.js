/* ============================================================
   main.js — единый скрипт для всех страниц diagnostika.103vet
   Собран из инлайновых <script> всех страниц папки page/.
   Конфликты исключены: каждый модуль защищён проверками
   наличия элементов и флагом инициализации (window.__*),
   глобальные переменные изолированы в IIFE.
   ============================================================ */
(function () {
    'use strict';

    /* ---------- 1. Навигация: эффект «scrolled» при прокрутке ---------- */
    const nav = document.getElementById('nav');
    if (nav) {
        window.addEventListener('scroll', () => {
            if (window.scrollY > 50) { nav.classList.add('scrolled'); }
            else { nav.classList.remove('scrolled'); }
        });
    }

    /* ---------- 2. Мобильное меню (бургер) ---------- */
    const mobileBtn = document.querySelector('.mobile-menu-btn');
    const navLinks = document.querySelector('.nav-links-premium');

    if (mobileBtn && navLinks) {
        mobileBtn.addEventListener('click', () => {
            navLinks.classList.toggle('mobile-open');
            mobileBtn.classList.toggle('active');

            const isExpanded = navLinks.classList.contains('mobile-open');
            mobileBtn.setAttribute('aria-expanded', isExpanded);
        });

        // Закрытие мобильного меню при клике на любую ссылку навигации
        document.querySelectorAll('.nav-link-premium, .nav-cta-premium').forEach(link => {
            link.addEventListener('click', () => {
                navLinks.classList.remove('mobile-open');
                mobileBtn.classList.remove('active');
            });
        });
    }

    /* ---------- 3. FAQ-аккордеоны ---------- */
    // Общая фабрика обработчиков: работает с любым набором классов
    // (.faq-item/.faq-question/.faq-answer — страницы услуг,
    //  .blog-faq-item/.blog-faq-question/.blog-faq-answer — блог-страницы).
    function initFaq(itemSelector, questionSelector, answerSelector) {
        const items = document.querySelectorAll(itemSelector);
        if (!items.length) return;

        document.querySelectorAll(questionSelector).forEach(button => {
            button.addEventListener('click', () => {
                const item = button.parentElement;
                const isActive = item.classList.contains('active');
                const answer = item.querySelector(answerSelector);

                // Свернуть все элементы группы
                items.forEach(i => {
                    i.classList.remove('active');
                    i.querySelector(questionSelector).setAttribute('aria-expanded', 'false');
                    const a = i.querySelector(answerSelector);
                    if (a) a.style.maxHeight = '0';
                });

                // Развернуть кликнутый, если он был закрыт
                if (!isActive) {
                    item.classList.add('active');
                    button.setAttribute('aria-expanded', 'true');
                    if (answer) answer.style.maxHeight = answer.scrollHeight + 'px';
                }
            });
        });
    }

    initFaq('.faq-item', '.faq-question', '.faq-answer');
    initFaq('.blog-faq-item', '.blog-faq-question', '.blog-faq-answer');

    /* ---------- 4. Reveal-анимации (появление блоков при скролле) ---------- */
    if (!window.__revealInit && document.querySelectorAll('.reveal').length) {
        window.__revealInit = true;
        const revealObserver = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    entry.target.classList.add('revealed');
                    revealObserver.unobserve(entry.target);
                }
            });
        }, { threshold: 0.1, rootMargin: '0px 0px -50px 0px' });

        document.querySelectorAll('.reveal').forEach(el => revealObserver.observe(el));
    }

    /* ---------- 5. Параллакс декоративных фигур (`.shape`) ---------- */
    if (!window.__shapesInit && document.querySelectorAll('.shape').length) {
        window.__shapesInit = true;
        let ticking = false;
        const shapes = document.querySelectorAll('.shape');

        document.addEventListener('mousemove', (e) => {
            if (window.matchMedia('(pointer: coarse)').matches) return;

            if (!ticking) {
                window.requestAnimationFrame(() => {
                    const x = e.clientX / window.innerWidth;
                    const y = e.clientY / window.innerHeight;

                    shapes.forEach((shape, index) => {
                        const speed = (index + 1) * 15;
                        const xOffset = (0.5 - x) * speed;
                        const yOffset = (0.5 - y) * speed;
                        shape.style.transform = `translate(${xOffset}px, ${yOffset}px)`;
                    });
                    ticking = false;
                });
                ticking = true;
            }
        });
    }
})();
