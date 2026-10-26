const nav = document.getElementById('nav');
        window.addEventListener('scroll', () => {
            if (window.scrollY > 50) {
                nav.classList.add('scrolled');
            } else {
                nav.classList.remove('scrolled');
            }
        });

        const mobileBtn = document.querySelector('.mobile-menu-btn');
        const navLinks = document.querySelector('.nav-links-premium');
        
        mobileBtn.addEventListener('click', () => {
            navLinks.classList.toggle('mobile-open');
            mobileBtn.classList.toggle('active');
            
            const isExpanded = navLinks.classList.contains('mobile-open');
            mobileBtn.setAttribute('aria-expanded', isExpanded);
        });

        document.querySelectorAll('.nav-link-premium, .nav-cta-premium').forEach(link => {
            link.addEventListener('click', () => {
                navLinks.classList.remove('mobile-open');
                mobileBtn.classList.remove('active');
            });
        });

        document.querySelectorAll('.faq-question').forEach(button => {
            button.addEventListener('click', () => {
                const item = button.parentElement;
                const isActive = item.classList.contains('active');
                
                document.querySelectorAll('.faq-item').forEach(i => {
                    i.classList.remove('active');
                    i.querySelector('.faq-question').setAttribute('aria-expanded', 'false');
                    i.querySelector('.faq-answer').style.maxHeight = '0';
                });
                
                if (!isActive) {
                    item.classList.add('active');
                    button.setAttribute('aria-expanded', 'true');
                    item.querySelector('.faq-answer').style.maxHeight = item.querySelector('.faq-answer').scrollHeight + 'px';
                }
            });
        });

        const revealObserver = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    entry.target.classList.add('revealed');
                    revealObserver.unobserve(entry.target);
                }
            });
        }, { threshold: 0.1, rootMargin: '0px 0px -50px 0px' });

        document.querySelectorAll('.reveal').forEach(el => revealObserver.observe(el));

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