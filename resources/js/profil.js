import { gsap } from 'gsap';
import { ScrollTrigger } from 'gsap/ScrollTrigger';

gsap.registerPlugin(ScrollTrigger);

const root = document.querySelector('[data-profil]');

if (root) {
    const html = document.documentElement;
    const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    const finePointer = window.matchMedia('(pointer: fine)').matches;

    // Le sélecteur de thème est géré globalement par `etudiant-theme.js`.
    // On conserve seulement l'effet de couverture lors d'un changement.
    document.addEventListener('etudiant:theme-change', () => {
        if (reduceMotion) return;

        gsap.fromTo(
            '.profil-cover',
            { scale: 0.994 },
            {
                scale: 1,
                duration: 0.5,
                ease: 'power3.out',
                clearProps: 'transform',
                onComplete: () => ScrollTrigger.refresh(),
            },
        );
    });

    // Sans animation : le contenu reste visible et lisible.
    if (reduceMotion) {
        html.classList.remove('profil-motion');
        html.classList.add('profil-motion-ready');
        document.querySelectorAll('[data-anim]').forEach((element) => {
            element.style.opacity = '1';
            element.style.visibility = 'visible';
        });
    } else {
        // Pas de smooth scroll ici : les espaces connectés conservent le
        // défilement natif (socle `motion-app`). GSAP ne porte que l'intro,
        // les révélations et les micro-interactions de cette fiche.
        const animated = gsap.utils.toArray('[data-anim]');
        gsap.set(animated, { autoAlpha: 1, y: 0 });

        // La classe est retirée par le minuteur inline si ce module ne s'exécute pas.
        html.classList.add('profil-motion-ready');

        const intro = gsap.timeline({
            defaults: { ease: 'power3.out' },
            onComplete: () => ScrollTrigger.refresh(),
        });

        intro
            .from('[data-anim="watermark"]', { autoAlpha: 0, duration: 1.1 }, 0)
            .from('[data-anim="cover-main"]', { y: 18, autoAlpha: 0, duration: 0.8 }, 0.05)
            .from('[data-anim="name"]', { yPercent: 110, duration: 0.95, ease: 'power4.out' }, 0.16)
            .from('[data-anim="cover-side"]', { y: 14, autoAlpha: 0, duration: 0.7 }, 0.28)
            .from('[data-anim="cover-facts"] > *', {
                y: 14,
                autoAlpha: 0,
                stagger: 0.06,
                duration: 0.6,
            }, 0.38);

        // Révélations au défilement, une seule fois par élément.
        gsap.utils.toArray('[data-anim="panel"], [data-anim="notice"], [data-anim="foot"], [data-anim="rail"]').forEach((element) => {
            gsap.from(element, {
                y: 24,
                autoAlpha: 0,
                duration: 0.85,
                ease: 'power3.out',
                scrollTrigger: {
                    trigger: element,
                    start: 'top 90%',
                    once: true,
                },
            });
        });

        // Halo discret qui suit le pointeur sur les panneaux de données.
        if (finePointer) {
            document.querySelectorAll('.profil-panel').forEach((panel) => {
                panel.classList.add('has-spotlight');

                panel.addEventListener('pointermove', (event) => {
                    const rect = panel.getBoundingClientRect();
                    panel.style.setProperty('--spot-x', `${event.clientX - rect.left}px`);
                    panel.style.setProperty('--spot-y', `${event.clientY - rect.top}px`);
                });
            });

            document.querySelectorAll('[data-magnetic]').forEach((element) => {
                const xTo = gsap.quickTo(element, 'x', { duration: 0.4, ease: 'power3.out' });
                const yTo = gsap.quickTo(element, 'y', { duration: 0.4, ease: 'power3.out' });

                element.addEventListener('pointermove', (event) => {
                    const rect = element.getBoundingClientRect();
                    xTo((event.clientX - rect.left - rect.width / 2) * 0.12);
                    yTo((event.clientY - rect.top - rect.height / 2) * 0.18);
                });

                element.addEventListener('pointerleave', () => {
                    xTo(0);
                    yTo(0);
                });
            });
        }

        window.addEventListener('load', () => ScrollTrigger.refresh(), { once: true });

        window.addEventListener('beforeunload', () => {
            ScrollTrigger.killAll();
        });
    }
}
