import 'lenis/dist/lenis.css';
import Lenis from 'lenis';
import { gsap } from 'gsap';
import { ScrollTrigger } from 'gsap/ScrollTrigger';

gsap.registerPlugin(ScrollTrigger);

/**
 * Mouvement cinématique des pages publiques.
 *
 * Principes :
 * - Lenis uniquement sur pointeur fin (le tactile garde son momentum natif) ;
 * - les révélations sont jouées une fois, jamais rejouées au scroll arrière ;
 * - les éléments déjà passés (rechargement en milieu de page) restent visibles ;
 * - aucune propriété de layout n'est animée : uniquement transform/opacité.
 */
export function initMotion() {
    const html = document.documentElement;
    const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    const finePointer = window.matchMedia('(pointer: fine)').matches;

    if (reduceMotion) {
        html.classList.remove('motion-prep');
        html.classList.add('motion-ready');

        return () => {};
    }

    // Le minuteur de secours a déjà rendu le contenu visible (chunk lent ou
    // bloqué) : on ne le masque pas une seconde fois pour le ré-animer.
    if (!html.classList.contains('motion-prep')) {
        html.classList.add('motion-ready');

        return () => {};
    }

    const cleanups = [];
    let lenis = null;
    let ticker = null;

    if (finePointer) {
        lenis = new Lenis({
            lerp: 0.08,
            smoothWheel: true,
            wheelMultiplier: 0.9,
            anchors: true,
        });

        lenis.on('scroll', ScrollTrigger.update);

        ticker = (time) => lenis.raf(time * 1000);
        gsap.ticker.add(ticker);
        gsap.ticker.lagSmoothing(0);
    }

    // Un panneau modal peut geler la page : Lenis programme `window.scrollTo`,
    // qu'`overflow: hidden` sur <body> ne bloque pas. Le panneau défilant porte
    // `data-lenis-prevent` pour conserver son défilement natif.
    const suspendre = () => lenis?.stop();
    const reprendre = () => lenis?.start();

    window.addEventListener('mouvement:suspendre', suspendre);
    window.addEventListener('mouvement:reprendre', reprendre);

    cleanups.push(() => {
        window.removeEventListener('mouvement:suspendre', suspendre);
        window.removeEventListener('mouvement:reprendre', reprendre);
    });

    const contexte = gsap.context(() => {
        // ── Intro du hero ────────────────────────────────────────────────
        const hero = document.querySelector('[data-motion-hero]');

        if (hero) {
            const items = Array.from(hero.querySelectorAll('[data-motion-hero-item]'));
            const cibles = items.length > 0 ? items : [hero];

            gsap.fromTo(cibles, {
                y: 28,
                opacity: 0,
            }, {
                y: 0,
                opacity: 1,
                duration: 0.95,
                ease: 'power4.out',
                stagger: items.length > 0 ? 0.08 : 0,
                delay: 0.05,
                clearProps: 'transform',
            });
        }

        // ── Révélations au défilement ────────────────────────────────────
        const revelations = gsap.utils.toArray('[data-reveal]')
            .filter((element) => !element.closest('[data-reveal-group]'));

        const dejaAuDessus = [];
        const dansLeViewport = [];
        const sousLeViewport = [];

        revelations.forEach((element) => {
            const rect = element.getBoundingClientRect();

            if (rect.bottom < 0) {
                dejaAuDessus.push(element);
            } else if (rect.top < window.innerHeight * 0.88) {
                dansLeViewport.push(element);
            } else {
                sousLeViewport.push(element);
            }
        });

        if (dejaAuDessus.length > 0) {
            gsap.set(dejaAuDessus, { opacity: 1 });
        }

        if (dansLeViewport.length > 0) {
            gsap.fromTo(dansLeViewport, {
                y: 26,
                opacity: 0,
            }, {
                y: 0,
                opacity: 1,
                duration: 0.9,
                ease: 'power3.out',
                stagger: 0.07,
                overwrite: true,
                clearProps: 'transform',
            });
        }

        if (sousLeViewport.length > 0) {
            gsap.set(sousLeViewport, { y: 28, opacity: 0 });

            ScrollTrigger.batch(sousLeViewport, {
                start: 'top 88%',
                once: true,
                onEnter: (batch) => gsap.to(batch, {
                    y: 0,
                    opacity: 1,
                    duration: 0.9,
                    ease: 'power3.out',
                    stagger: 0.07,
                    overwrite: true,
                    clearProps: 'transform',
                }),
            });
        }

        // ── Groupes de cartes / listes ───────────────────────────────────
        gsap.utils.toArray('[data-reveal-group]').forEach((groupe) => {
            const items = Array.from(groupe.querySelectorAll('[data-reveal-item]'));

            if (items.length === 0) return;

            const rect = groupe.getBoundingClientRect();

            if (rect.bottom < 0) {
                gsap.set(items, { opacity: 1 });
                return;
            }

            gsap.set(items, { y: 30, opacity: 0 });

            const jouer = () => gsap.to(items, {
                y: 0,
                opacity: 1,
                duration: 0.9,
                ease: 'power3.out',
                stagger: 0.075,
                overwrite: true,
                clearProps: 'transform',
            });

            if (rect.top < window.innerHeight * 0.88) {
                jouer();
            } else {
                ScrollTrigger.create({
                    trigger: groupe,
                    start: 'top 86%',
                    once: true,
                    onEnter: jouer,
                });
            }
        });

        // ── Parallaxe optionnelle (images / calques décoratifs) ──────────
        gsap.utils.toArray('[data-parallax]').forEach((calque) => {
            const vitesse = Number(calque.dataset.parallax || 0.14);

            if (!Number.isFinite(vitesse) || vitesse === 0) return;

            gsap.to(calque, {
                y: () => -window.innerHeight * vitesse,
                ease: 'none',
                scrollTrigger: {
                    trigger: calque.closest('[data-parallax-section]') || calque,
                    start: 'top bottom',
                    end: 'bottom top',
                    scrub: 1.2,
                    invalidateOnRefresh: true,
                },
            });
        });

        // ── Boutons magnétiques (pointeur fin uniquement) ────────────────
        if (finePointer) {
            gsap.utils.toArray('[data-magnetic]').forEach((element) => {
                if (element.matches('button:disabled, [aria-disabled="true"]')) return;

                const force = Number(element.dataset.magnetic || 0.14);
                const xTo = gsap.quickTo(element, 'x', { duration: 0.45, ease: 'power3.out' });
                const yTo = gsap.quickTo(element, 'y', { duration: 0.45, ease: 'power3.out' });

                const deplacer = (event) => {
                    const rect = element.getBoundingClientRect();

                    xTo((event.clientX - rect.left - rect.width / 2) * force);
                    yTo((event.clientY - rect.top - rect.height / 2) * force);
                };

                const quitter = () => {
                    xTo(0);
                    yTo(0);
                };

                element.addEventListener('pointermove', deplacer);
                element.addEventListener('pointerleave', quitter);

                cleanups.push(() => {
                    element.removeEventListener('pointermove', deplacer);
                    element.removeEventListener('pointerleave', quitter);
                    gsap.set(element, { clearProps: 'transform' });
                });
            });
        }
    });

    html.classList.remove('motion-prep');
    html.classList.add('motion-ready');

    const rafraichir = () => ScrollTrigger.refresh();

    if (document.fonts?.ready) {
        document.fonts.ready.then(rafraichir).catch(() => {});
    }

    window.addEventListener('load', rafraichir, { once: true });
    requestAnimationFrame(rafraichir);

    return () => {
        cleanups.forEach((fn) => fn());
        contexte.revert();

        window.removeEventListener('load', rafraichir);

        if (ticker) {
            gsap.ticker.remove(ticker);
        }

        gsap.ticker.lagSmoothing(500, 33);
        lenis?.destroy();

        html.classList.remove('motion-ready');
    };
}
