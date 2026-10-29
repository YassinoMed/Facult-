/**
 * Mouvement cinématique de l'espace étudiant — GSAP + ScrollTrigger + Lenis.
 *
 * Chargé uniquement sur les pages `etudiant.*` authentifiées (voir le layout),
 * ce module donne une grammaire commune à toutes les fenêtres du portail :
 * - défilement inertiel Lenis sur pointeur fin (le tactile garde son momentum
 *   natif et `prefers-reduced-motion` désactive tout) ;
 * - entrée de page sobre : titre révélé mot à mot, puis en-tête et actions ;
 * - cartes et sections révélées au défilement, une seule fois ;
 * - halo lumineux qui suit le pointeur sur les cartes (SpotlightCard) ;
 * - lignes de tableaux légèrement décalées pour guider la lecture ;
 * - parallaxe et boutons magnétiques disponibles via attributs explicites.
 *
 * Le mouvement sert la hiérarchie, jamais la tâche : aucun curseur
 * personnalisé, aucun scroll horizontal, aucune parallaxe sur un conteneur
 * qui influence la mise en page. Les formulaires, champs, alertes et zones
 * live restent stables.
 */

import 'lenis/dist/lenis.css';
import Lenis from 'lenis';
import { gsap } from 'gsap';
import { ScrollTrigger } from 'gsap/ScrollTrigger';

gsap.registerPlugin(ScrollTrigger);
ScrollTrigger.config({ ignoreMobileResize: true });

const html = document.documentElement;
const requeteReduction = window.matchMedia('(prefers-reduced-motion: reduce)');
const requetePointeurFin = window.matchMedia('(pointer: fine)');

/** Sélecteurs des blocs qui méritent une révélation (cartes, sections, en-têtes). */
const SELECTEURS_BLOCS = [
    '[data-motion-block]',
    '[data-reveal]',
    '[data-reveal-item]',
    '.ds-card',
    '.card-hover',
    'section[aria-labelledby]',
    'article',
].join(',');

/** Éléments déjà animés par leur propre module ou à ne jamais masquer. */
const EXCLUSIONS = [
    '[data-profil]',
    '.no-motion',
    '.sr-only',
    '[data-motion="off"]',
    '[data-parallax]',
    '[hidden]',
    '[x-cloak]',
    '[role="alert"]',
    '[role="status"]',
    '[role="dialog"]',
    '[aria-modal="true"]',
    '[aria-live]',
    '[inert]',
    'form',
    'nav',
    'footer',
    'table',
].join(',');

/** Cartes éligibles au halo qui suit le pointeur. */
const SELECTEURS_SPOTLIGHT = '.ds-card, .card-hover';

let contexte = null;
let elementsAnimes = [];
let motsTitre = [];
let cartesSpotlight = [];
let main = null;
let lenis = null;
let ticker = null;
let observateurVerrou = null;
let verrouCorps = false;
let mouvementSuspendu = false;
let nettoyages = [];
let premierDemarrage = true;

function estVisible(element) {
    return element.getClientRects().length > 0;
}

function estAnimable(element) {
    if (!(element instanceof HTMLElement)) return false;
    if (element.matches('script, style, link, svg, path, input, select, textarea, button')) return false;
    if (element.closest(EXCLUSIONS)) return false;
    if (element.dataset.motion === 'off') return false;

    return estVisible(element);
}

function contientFormulaire(element) {
    return element instanceof HTMLElement && element.querySelector('form') !== null;
}

/** Une carte au sens visuel : coins arrondis, surface et taille suffisante. */
function estCarte(element) {
    if (!(element instanceof HTMLElement)) return false;

    const classes = typeof element.className === 'string' ? element.className : '';

    if (! (classes.includes('rounded-lg') || classes.includes('rounded-xl') || classes.includes('rounded-2xl') || classes.includes('rounded-3xl'))) {
        return false;
    }

    if (! (classes.includes('border') || classes.includes('bg-white') || classes.includes('shadow') || classes.includes('dark:bg-slate'))) {
        return false;
    }

    const rect = element.getBoundingClientRect();

    return rect.width >= 180 && rect.height >= 64;
}

/**
 * Découpe un titre en mots masqués pour une révélation cinématique.
 *
 * Le texte d'origine reste dans `aria-label` ; les fragments visuels sont
 * `aria-hidden`. On ne touche pas aux titres qui contiennent déjà des éléments
 * (icône, badge, texte conditionnel) : leur mise en page est préservée.
 */
function preparerTitre(titre) {
    if (!titre) return [];

    if (titre.dataset.motionText === 'off') return [];

    if (titre.dataset.motionSplit === 'true') {
        return Array.from(titre.querySelectorAll('.motion-word'));
    }

    if (titre.children.length > 0) return [];

    const texte = (titre.textContent || '').trim();
    if (!texte) return [];

    const parties = texte.split(/(\s+)/);
    const mots = [];

    titre.textContent = '';
    if (!titre.hasAttribute('aria-label')) {
        titre.setAttribute('aria-label', texte);
    }

    parties.forEach((partie) => {
        if (!partie.trim()) {
            titre.appendChild(document.createTextNode(partie));

            return;
        }

        const masque = document.createElement('span');
        const mot = document.createElement('span');

        masque.className = 'motion-word-mask';
        masque.setAttribute('aria-hidden', 'true');
        mot.className = 'motion-word';
        mot.textContent = partie;

        masque.appendChild(mot);
        titre.appendChild(masque);
        mots.push(mot);
    });

    titre.dataset.motionSplit = 'true';

    return mots;
}

/**
 * Révèle tout sans animation : utilisé si le module échoue, si l'utilisateur
 * préfère réduire les animations, ou lors d'une navigation hors espace étudiant.
 */
function toutReveler() {
    contexte?.revert();
    contexte = null;

    ScrollTrigger.getAll().forEach((declencheur) => declencheur.kill());

    if (main) gsap.set(main, { clearProps: 'opacity,filter' });

    [...elementsAnimes, ...motsTitre].forEach((element) => {
        gsap.set(element, { clearProps: 'opacity,transform,filter,willChange' });
    });

    elementsAnimes = [];
    motsTitre = [];

    cartesSpotlight.forEach((carte) => {
        carte.classList.remove('motion-spotlight');
        carte.style.removeProperty('--spot-x');
        carte.style.removeProperty('--spot-y');
    });
    cartesSpotlight = [];

    nettoyages.forEach((fn) => fn());
    nettoyages = [];

    detruireLenis();

    html.classList.remove('motion-prep');
    html.classList.add('motion-ready');

    main = null;
}

function detruireLenis() {
    if (ticker) {
        gsap.ticker.remove(ticker);
        ticker = null;
    }

    observateurVerrou?.disconnect();
    observateurVerrou = null;

    lenis?.destroy();
    lenis = null;

    verrouCorps = false;
    mouvementSuspendu = false;

    gsap.ticker.lagSmoothing(500, 33);
    html.classList.remove('lenis', 'lenis-smooth', 'lenis-scrolling', 'lenis-stopped');
}

/**
 * Collecte les blocs à révéler.
 *
 * - tous les sélecteurs de cartes/sections de la zone ;
 * - les cartes des grilles (jamais les champs d'un formulaire) ;
 * - les cartes rencontrées dans les simples conteneurs de mise en page, en
 *   s'arrêtant aux formulaires, listes, tableaux et navigation pour ne pas
 *   animer chaque champ ou chaque ligne indépendamment.
 *
 * Seules les feuilles sont conservées : si une carte contient une autre
 * carte, on anime la plus interne pour éviter les doublons. Les cartes qui
 * contiennent un formulaire sont laissées stables : elles apparaissent avec
 * le fondu de `main`, jamais avec un déplacement.
 */
function collecterBlocs(zone, ancetresIntro) {
    const candidats = new Set();

    zone.querySelectorAll(SELECTEURS_BLOCS).forEach((element) => {
        if (estAnimable(element) && ! contientFormulaire(element)) candidats.add(element);
    });

    // Les cartes d'une grille sont animées ensemble, jamais les champs d'un formulaire.
    zone.querySelectorAll('.grid, [class*="grid-cols"]').forEach((grille) => {
        if (grille.closest('form')) return;

        Array.from(grille.children).forEach((enfant) => {
            if (estAnimable(enfant) && ! contientFormulaire(enfant)
                && (estCarte(enfant) || enfant.matches(SELECTEURS_BLOCS))) {
                candidats.add(enfant);
            }
        });
    });

    const conteneursTerminaux = 'form, table, nav, footer, details, ul, ol';

    const parcourir = (conteneur) => {
        Array.from(conteneur.children).forEach((enfant) => {
            const estBloc = enfant.matches(SELECTEURS_BLOCS) || estCarte(enfant);

            if (estBloc && estAnimable(enfant) && ! contientFormulaire(enfant)) candidats.add(enfant);

            if (! enfant.matches(conteneursTerminaux)) {
                parcourir(enfant);
            }
        });
    };

    parcourir(zone);

    const liste = [...candidats];

    return liste.filter((element) => {
        if (ancetresIntro.has(element)) return false;
        if (liste.some((autre) => autre !== element && element.contains(autre))) return false;

        return true;
    });
}

/**
 * Sépare les blocs déjà passés (rechargement au milieu de page) de ceux qui
 * sont visibles et de ceux qui attendent le défilement. Aucun bloc ne doit
 * rester invisible parce qu'il était au-dessus du viewport au chargement.
 */
function classerBlocs(blocs) {
    const auDessus = [];
    const dansVue = [];
    const sousVue = [];
    const hauteur = window.innerHeight;

    blocs.forEach((element) => {
        const rect = element.getBoundingClientRect();

        if (rect.bottom < 0) {
            auDessus.push(element);
        } else if (rect.top < hauteur * 0.92) {
            dansVue.push(element);
        } else {
            sousVue.push(element);
        }
    });

    return { auDessus, dansVue, sousVue };
}

/**
 * Révèle les lignes d'un tableau quand sa carte entre dans le viewport.
 * Plafonné pour ne jamais transformer une page dense en feu d'artifice.
 */
function revelerLignes(bloc) {
    if (!estVisible(bloc)) return;

    const lignes = bloc.querySelectorAll('[data-motion-row], tbody tr');

    if (lignes.length === 0 || lignes.length > 40) return;

    gsap.from(lignes, {
        opacity: 0,
        y: 6,
        duration: 0.35,
        stagger: 0.025,
        ease: 'power2.out',
        clearProps: 'transform,opacity',
        overwrite: true,
    });
}

/**
 * Lenis : défilement inertiel uniquement sur pointeur fin. Le moteur est
 * piloté par le ticker GSAP pour que ScrollTrigger et le scroll restent
 * synchronisés. Un verrou de scroll (modale Alpine `x-trap.noscroll`) ou un
 * événement `mouvement:suspendre` met le moteur en pause.
 */
function initialiserLenis() {
    if (!requetePointeurFin.matches) return;

    lenis = new Lenis({
        lerp: 0.08,
        smoothWheel: true,
        wheelMultiplier: 0.9,
        anchors: true,
        autoRaf: false,
    });

    lenis.on('scroll', ScrollTrigger.update);

    ticker = (time) => lenis?.raf(time * 1000);
    gsap.ticker.add(ticker);
    gsap.ticker.lagSmoothing(0);

    const appliquerEtat = () => {
        if (!lenis) return;

        if (verrouCorps || mouvementSuspendu) {
            lenis.stop();
        } else {
            lenis.start();
        }
    };

    const verifierVerrou = () => {
        const styleCorps = window.getComputedStyle(document.body);
        const styleHtml = window.getComputedStyle(html);

        verrouCorps = styleCorps.overflow === 'hidden'
            || styleCorps.overflowY === 'hidden'
            || styleHtml.overflow === 'hidden'
            || styleHtml.overflowY === 'hidden';

        appliquerEtat();
    };

    observateurVerrou = new MutationObserver(verifierVerrou);
    observateurVerrou.observe(document.body, { attributes: true, attributeFilter: ['style', 'class'] });
    // On n'observe que le style de <html> : Lenis ajoute ses propres classes
    // sur <html> et un observer de classe créerait une boucle start/stop.
    observateurVerrou.observe(html, { attributes: true, attributeFilter: ['style'] });

    verifierVerrou();

    const suspendre = () => {
        mouvementSuspendu = true;
        appliquerEtat();
    };

    const reprendre = () => {
        mouvementSuspendu = false;
        appliquerEtat();
    };

    window.addEventListener('mouvement:suspendre', suspendre);
    window.addEventListener('mouvement:reprendre', reprendre);

    nettoyages.push(() => {
        window.removeEventListener('mouvement:suspendre', suspendre);
        window.removeEventListener('mouvement:reprendre', reprendre);
    });
}

/**
 * Halo lumineux qui suit le pointeur sur les cartes opaques (motif
 * SpotlightCard transposé en CSS/GSAP, sans React). Désactivé au tactile et
 * lorsque l'utilisateur réduit les animations.
 */
function initialiserSpotlight(zone) {
    if (!requetePointeurFin.matches) return;

    cartesSpotlight = Array.from(zone.querySelectorAll(SELECTEURS_SPOTLIGHT)).filter((carte) => {
        if (!estVisible(carte) || carte.closest(EXCLUSIONS) || contientFormulaire(carte)) return false;

        // On ne remplace jamais un dégradé existant (bannière, carte colorée).
        return window.getComputedStyle(carte).backgroundImage === 'none';
    });

    if (cartesSpotlight.length === 0) return;

    cartesSpotlight.forEach((carte) => carte.classList.add('motion-spotlight'));

    let frame = 0;
    let cible = null;
    let positionX = 0;
    let positionY = 0;

    const appliquer = () => {
        frame = 0;

        if (!cible) return;

        cible.style.setProperty('--spot-x', `${positionX}px`);
        cible.style.setProperty('--spot-y', `${positionY}px`);
    };

    const deplacer = (event) => {
        const carte = event.target instanceof Element
            ? event.target.closest('.motion-spotlight')
            : null;

        if (!carte || !zone.contains(carte)) return;

        const rect = carte.getBoundingClientRect();

        cible = carte;
        positionX = event.clientX - rect.left;
        positionY = event.clientY - rect.top;

        if (!frame) frame = requestAnimationFrame(appliquer);
    };

    zone.addEventListener('pointermove', deplacer, { passive: true });

    nettoyages.push(() => {
        zone.removeEventListener('pointermove', deplacer);

        if (frame) cancelAnimationFrame(frame);

        cartesSpotlight.forEach((carte) => {
            carte.classList.remove('motion-spotlight');
            carte.style.removeProperty('--spot-x');
            carte.style.removeProperty('--spot-y');
        });

        cartesSpotlight = [];
    });
}

/** Boutons/liens magnétiques explicitement marqués (`data-magnetic`). */
function initialiserMagnetiques(zone) {
    if (!requetePointeurFin.matches) return;

    zone.querySelectorAll('[data-magnetic]').forEach((element) => {
        if (!(element instanceof HTMLElement)) return;
        if (element.matches('button:disabled, [aria-disabled="true"]')) return;
        if (element.closest(EXCLUSIONS)) return;

        const force = Number(element.dataset.magnetic) || 0.14;
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

        nettoyages.push(() => {
            element.removeEventListener('pointermove', deplacer);
            element.removeEventListener('pointerleave', quitter);
            gsap.set(element, { clearProps: 'transform' });
        });
    });
}

/** Parallaxe optionnelle, réservée aux calques décoratifs (`data-parallax`). */
function initialiserParallaxe(zone) {
    zone.querySelectorAll('[data-parallax]').forEach((calque) => {
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
}

function initialiser() {
    main = document.querySelector('main#contenu');

    if (!main) {
        toutReveler();

        return;
    }

    const zone = main.querySelector('[data-motion-zone]') ?? main;
    const hero = zone.querySelector('[data-motion-hero]');
    const titre = hero?.querySelector('h1') ?? zone.querySelector('h1');
    const titreMots = preparerTitre(titre);
    const ancetresIntro = new Set();
    const elementsIntro = [];

    if (titreMots.length > 0) {
        motsTitre = titreMots;
    } else if (titre && estAnimable(titre)) {
        elementsIntro.push(titre);
    }

    // En-tête : les enfants du hero explicite, sinon les frères du titre.
    const groupeIntro = hero ?? titre?.parentElement;

    if (groupeIntro) {
        const candidats = hero
            ? Array.from(hero.querySelectorAll('[data-motion-hero-item]'))
            : Array.from(groupeIntro.children);

        candidats.forEach((enfant) => {
            if (enfant === titre) return;
            if (elementDejaPresent(elementsIntro, enfant)) return;
            if (enfant.matches(SELECTEURS_BLOCS) || estCarte(enfant)) return;
            if (contientFormulaire(enfant)) return;
            if (enfant.matches('form, table, nav, footer, details, ul, ol')) return;
            if (estAnimable(enfant)) elementsIntro.push(enfant);
        });
    }

    if (titre) {
        let parent = titre;

        while (parent && parent !== main) {
            ancetresIntro.add(parent);
            parent = parent.parentElement;
        }
    }

    if (hero) ancetresIntro.add(hero);

    // Les éléments de l'intro et leurs ancêtres sont exclus des blocs :
    // l'intro s'en charge pour éviter une double révélation.
    elementsIntro.forEach((element) => {
        ancetresIntro.add(element);

        let parent = element.parentElement;
        while (parent && parent !== main) {
            ancetresIntro.add(parent);
            parent = parent.parentElement;
        }
    });

    const blocs = collecterBlocs(zone, ancetresIntro);
    const { auDessus, dansVue, sousVue } = classerBlocs(blocs);

    elementsAnimes = [...new Set([...elementsIntro, ...blocs])];

    // On prépare les états pendant que `motion-prep` masque encore le contenu :
    // aucune frame avec les éléments visibles puis brutalement repositionnés.
    gsap.set(main, { opacity: 0 });

    if (motsTitre.length > 0) {
        gsap.set(motsTitre, { opacity: 0, yPercent: 110, filter: 'blur(6px)', willChange: 'transform, opacity, filter' });
    }

    if (elementsIntro.length > 0) {
        gsap.set(elementsIntro, { opacity: 0, y: 14 });
    }

    if (dansVue.length > 0) {
        gsap.set(dansVue, { opacity: 0, y: 22 });
    }

    if (sousVue.length > 0) {
        gsap.set(sousVue, { opacity: 0, y: 22 });
    }

    if (auDessus.length > 0) {
        gsap.set(auDessus, { clearProps: 'opacity,transform,filter' });
    }

    initialiserLenis();
    initialiserSpotlight(zone);

    html.classList.remove('motion-prep');
    html.classList.add('motion-ready');

    contexte = gsap.context(() => {
        gsap.to(main, {
            opacity: 1,
            duration: 0.3,
            ease: 'power2.out',
            clearProps: 'opacity',
        });

        if (motsTitre.length > 0) {
            gsap.to(motsTitre, {
                opacity: 1,
                yPercent: 0,
                filter: 'blur(0px)',
                duration: 0.9,
                ease: 'power4.out',
                stagger: 0.055,
                delay: 0.05,
                clearProps: 'transform,opacity,filter',
                onComplete: () => gsap.set(motsTitre, { willChange: 'auto' }),
            });
        }

        if (elementsIntro.length > 0) {
            gsap.to(elementsIntro, {
                opacity: 1,
                y: 0,
                duration: 0.65,
                ease: 'power3.out',
                stagger: 0.07,
                delay: 0.05,
                clearProps: 'transform,opacity',
            });
        }

        if (dansVue.length > 0) {
            gsap.to(dansVue, {
                opacity: 1,
                y: 0,
                duration: 0.8,
                ease: 'power3.out',
                stagger: 0.06,
                delay: 0.15,
                clearProps: 'transform,opacity',
                overwrite: true,
            });

            dansVue.forEach(revelerLignes);
        }

        if (sousVue.length > 0) {
            ScrollTrigger.batch(sousVue, {
                start: 'top 92%',
                once: true,
                interval: 0.1,
                batchMax: 5,
                onEnter: (lot) => {
                    gsap.to(lot, {
                        opacity: 1,
                        y: 0,
                        duration: 0.8,
                        ease: 'power3.out',
                        stagger: 0.06,
                        overwrite: true,
                        clearProps: 'transform,opacity',
                    });

                    lot.forEach(revelerLignes);
                },
            });
        }

        initialiserMagnetiques(zone);
        initialiserParallaxe(zone);
    }, main);

    const rafraichir = () => {
        if (!main) return;

        ScrollTrigger.refresh();

        // Page plus courte que le viewport : aucun scroll ne déclenchera le
        // batch, on révèle donc immédiatement les derniers blocs.
        if (document.documentElement.scrollHeight <= window.innerHeight + 4) {
            gsap.set([...dansVue, ...sousVue], {
                opacity: 1,
                y: 0,
                clearProps: 'transform,opacity',
            });
        }
    };

    const avantImpression = () => {
        if (!main) return;

        gsap.set(main, { opacity: 1 });
        gsap.set([...elementsAnimes, ...motsTitre], {
            opacity: 1,
            y: 0,
            filter: 'none',
        });
    };

    window.addEventListener('load', rafraichir, { once: true });
    window.addEventListener('beforeprint', avantImpression);

    nettoyages.push(() => {
        window.removeEventListener('load', rafraichir);
        window.removeEventListener('beforeprint', avantImpression);
    });

    if (document.fonts?.ready) {
        document.fonts.ready.then(() => {
            if (main) rafraichir();
        }).catch(() => {});
    }

    requestAnimationFrame(rafraichir);
}

/** Petite aide : évite les doublons sans dépendre de `Array.includes` sur DOM. */
function elementDejaPresent(liste, element) {
    return liste.some((candidat) => candidat === element);
}

function demarrer() {
    // Le minuteur de secours du layout retire `motion-prep` après 1,8 s. Si le
    // chunk arrive après ce délai, le contenu est déjà visible : on ne le
    // remasque pas pour rejouer une entrée. Les navigations suivantes ne sont
    // pas concernées.
    const minuteurAPasse = premierDemarrage && !html.classList.contains('motion-prep');

    premierDemarrage = false;

    toutReveler();

    if (document.body?.dataset.motion !== 'etudiant') {
        return;
    }

    if (requeteReduction.matches || minuteurAPasse) {
        return;
    }

    html.classList.add('motion-prep');

    try {
        initialiser();
    } catch (error) {
        toutReveler();
        console.warn('[mouvement étudiant] initialisation ignorée', error);
    }
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', demarrer, { once: true });
} else {
    demarrer();
}

document.addEventListener('livewire:navigated', demarrer);

window.addEventListener('pageshow', (event) => {
    if (event.persisted) demarrer();
});

if (typeof requeteReduction.addEventListener === 'function') {
    requeteReduction.addEventListener('change', () => {
        if (requeteReduction.matches) {
            toutReveler();
        } else {
            demarrer();
        }
    });
}
