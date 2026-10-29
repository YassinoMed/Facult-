/**
 * Mouvement du back-office — GSAP + ScrollTrigger, adapté au travail admin.
 *
 * Chargé à la demande par `motion-loader.js` lorsque <body> porte
 * `data-motion="admin"` (voir le layout). Le back-office est un outil de
 * travail : le mouvement sert la hiérarchie, jamais la tâche.
 *
 * - entrée de page sobre : en-tête + contenu principal ;
 * - cartes et sections révélées une seule fois à l'entrée dans le viewport ;
 * - lignes de tableaux (max. 30) légèrement décalées pour guider la lecture ;
 * - aucun Lenis, aucune parallaxe, aucun curseur, aucun scroll détourné ;
 * - formulaires : les champs restent stables & focusables, seules les cartes
 *   de formulaire entrent en opacité douce, sans déplacement (plancher 70 %) ;
 * - alertes, navigation, listes et pied de page exclus ;
 * - `prefers-reduced-motion` respecté ; sans JavaScript, le contenu reste
 *   visible (minuteur de secours du layout et nettoyage en cas d'échec).
 *
 * Données HTML : les éléments ciblés par le mouvement portent l'attribut
 * `data-motion-block`. Les formulaires sensibles comportent
 * `data-motion="off"` pour éviter toute animation invasive.
 */

import { gsap } from 'gsap';
import { ScrollTrigger } from 'gsap/ScrollTrigger';

gsap.registerPlugin(ScrollTrigger);
ScrollTrigger.config({ ignoreMobileResize: true });

/** Blocs qui méritent une révélation : cartes, sections, en-têtes de contenu.
 *  Les éléments ciblés portent l'attribut `data-motion-block` pour un ciblage
 *  opt-in au sein de chaque zone. */
const SELECTEURS_BLOCS = [
    '[data-motion-block]',
    '.ds-card',
    '.card-hover',
    'section[aria-labelledby]',
    'article',
    '[class*="rounded-2xl"][class*="border"]',
    '[class*="rounded-3xl"][class*="border"]',
    // Composants scrutins
    '.scrutin-card',
    '.scrutin-header',
    // Composants étudiants/rapports
    '.widget-principal',
    '.stat-card',
].join(',');

/** Éléments à ne jamais masquer ni animer : champs, tableaux, navigation, modales, zones live. */
const EXCLUSIONS = [
    '[data-motion="off"]',
    '.no-motion',
    '.sr-only',
    '[hidden]',
    '[x-cloak]',
    '[inert]',
    '[aria-hidden="true"]',
    '[role="dialog"]',
    '[role="alertdialog"]',
    '[aria-modal="true"]',
    'dialog',
    '[role="alert"]',
    '[role="status"]',
    '[aria-live]',
    'nav',
    'footer',
    // Blocs de formulaire sensibles : jamais d'animation qui masque les champs
    'input', 'select', 'textarea',
    'button[type="submit"]',
    // Tableaux et structure de données
    'table', 'thead', 'tbody', 'tr', 'td', 'th',
    // Listes de navigation
    'ul', 'ol',
    // Contrôles de débat
    'details', 'summary',
].join(',');

const MAX_LIGNES = 30;

function estVisible(element) {
    if (element.getClientRects().length === 0) return false;

    const style = window.getComputedStyle(element);

    return style.display !== 'none'
        && style.visibility !== 'hidden'
        && parseFloat(style.opacity) !== 0;
}

function estAnimable(element) {
    if (!(element instanceof HTMLElement)) return false;
    if (element.matches('script, style, link, svg, path')) return false;
    if (element.closest(EXCLUSIONS)) return false;
    if (element.dataset.motion === 'off') return false;

    // Un formulaire n'entre en mouvement que s'il est lui-même une carte de
    // mise en page (classe `ds-card` ou attribut explicite). Les formulaires
    // nus restent stables : aucune animation ne doit s'interposer dans la
    // saisie ou le focus.
    if (element.matches('form') && !element.matches('.ds-card, [data-motion-block], [class*="rounded-2xl"][class*="border"], [class*="rounded-3xl"][class*="border"]')) {
        return false;
    }

    return estVisible(element);
}

/** Vrai si le bloc est dans un formulaire ou en contient un (carte de filtre). */
function estDansFormulaire(element) {
    return Boolean(element.closest('form') || element.querySelector('form'));
}

/** Une carte au sens visuel : coins arrondis + bordure, fond ou ombre. */
function estCarte(element) {
    if (!(element instanceof HTMLElement)) return false;
    const classes = typeof element.className === 'string' ? element.className : '';

    return classes.toLowerCase().includes('rounded')
        && (classes.toLowerCase().includes('border') || classes.toLowerCase().includes('bg-white') || classes.toLowerCase().includes('shadow') || classes.toLowerCase().includes('dark:bg-slate'));
}

/**
 * Collecte les blocs à révéler.
 *
 * On ne conserve que les cartes/sections feuilles, pour éviter d'animer deux
 * fois la même zone. Les champs, tableaux, listes et navigations ne sont
 * jamais des cibles : ils restent stables à l'intérieur de leur carte.
 *
 * Le module marque les blocs collectés avec `data-motion-block` pour rendre le
 * ciblage visible dans l'inspecteur ; l'attribut peut aussi être posé côté
 * Blade pour un ciblage explicite.
 */
function collecterBlocs(zone, ancetresIntro) {
    const candidats = new Set();

    zone.querySelectorAll(SELECTEURS_BLOCS).forEach((element) => {
        if (estAnimable(element)) {
            candidats.add(element);
            // Marquage optionnel : trace explicite du bloc dans l'inspecteur.
            if (!element.dataset.motionBlock) {
                element.dataset.motionBlock = '1';
            }
        }
    });

    // Les grilles de cartes : on ne révèle que les cartes filles, pas les grilles
    zone.querySelectorAll('.grid, [class*="grid-cols"]').forEach((grille) => {
        if (grille.closest(EXCLUSIONS)) return;

        Array.from(grille.children).forEach((enfant) => {
            if (estAnimable(enfant) && (estCarte(enfant) || enfant.matches(SELECTEURS_BLOCS))) {
                candidats.add(enfant);
                if (!enfant.dataset.motionBlock) {
                    enfant.dataset.motionBlock = '1';
                }
            }
        });
    });

    const liste = [...candidats];

    return liste.filter((element) => {
        if (ancetresIntro.has(element)) return false;
        if (liste.some((autre) => autre !== element && element.contains(autre))) return false;

        return true;
    });
}

/**
 * Révèle les lignes d'un tableau quand sa carte entre dans le viewport.
 * Plafonné : un registre de plusieurs centaines de lignes reste instantané.
 * Les lignes sont décalées légèrement (y=4px) avec opacité 0, easing power2.out.
 * Plafonné à MAX_LIGNES = 30 pour éviter tout impact de performance.
 */
function revelerLignes(bloc, suivies) {
    if (bloc.matches('form, table') || bloc.closest('form') || bloc.querySelector('form')) return;

    const lignes = bloc.querySelectorAll('[data-motion-row], tbody tr');

    if (lignes.length === 0 || lignes.length > MAX_LIGNES) return;

    // Ne révéler que le nombre maximal de lignes pour garder le tableau rapide
    const lignesAReveler = lignes.length <= MAX_LIGNES ? lignes : lignes.slice(0, MAX_LIGNES);

    lignesAReveler.forEach((ligne) => suivies.add(ligne));

    gsap.from(lignesAReveler, {
        opacity: 0,
        y: 4,
        duration: 0.3,
        stagger: 0.02,
        ease: 'power2.out',
        clearProps: 'transform',
        overwrite: true,
    });
}

/** Format français d'un compteur, selon les décimales déclarées côté Blade. */
function formaterCompteur(element, valeur) {
    const decimales = Math.min(2, Math.max(0, Number(element.dataset.motionCountDecimales ?? 0)));

    return new Intl.NumberFormat('fr-FR', {
        minimumFractionDigits: decimales,
        maximumFractionDigits: decimales,
    }).format(valeur);
}

/**
 * Initialise le mouvement du back-office.
 *
 * Contrat identique à `motion-cinematic.js` : retourne une fonction de
 * nettoyage. `motion-loader.js` gère le cycle de vie (chargement, navigation
 * Livewire, réduction du mouvement) et n'appelle ce module qu'en portée
 * `admin`.
 */
export function initAdminMotion() {
    const html = document.documentElement;
    const main = document.querySelector('main#contenu');

    if (document.body?.dataset.motion !== 'admin') {
        html.classList.add('motion-ready');

        return () => {};
    }

    if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
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

    if (!main) {
        html.classList.remove('motion-prep');
        html.classList.add('motion-ready');

        return () => {};
    }

    const zone = main.querySelector('[data-motion-zone]') ?? main;
    const titre = zone.querySelector('h1');

    let groupeIntro = titre
        ? (titre.closest('[data-reveal]') ?? titre.parentElement)
        : null;

    // Garde-fou : ne jamais prendre un conteneur de page entier ni un
    // formulaire de saisie pour l'en-tête.
    if (groupeIntro && (
        groupeIntro === zone
        || groupeIntro === main
        || !zone.contains(groupeIntro)
        || groupeIntro.closest('form')
    )) {
        groupeIntro = titre;
    }

    // Les enfants du groupe d'en-tête qui portent un formulaire ou un champ
    // restent stables : on n'anime que les titres/textes et les liens.
    const elementsIntro = groupeIntro
        ? (groupeIntro === titre
            ? [titre]
            : Array.from(groupeIntro.children).filter(
                (element) => estAnimable(element)
                    && !element.querySelector('form, input, select, textarea'),
            ))
        : [];

    const ancetresIntro = new Set();

    if (groupeIntro) {
        let parent = groupeIntro;

        while (parent && parent !== main) {
            ancetresIntro.add(parent);
            parent = parent.parentElement;
        }

        // Tout le groupe d'en-tête est animé d'un bloc : ses descendants ne
        // doivent pas être collectés une seconde fois comme cartes.
        groupeIntro.querySelectorAll('*').forEach((element) => ancetresIntro.add(element));
    }

    elementsIntro.forEach((element) => ancetresIntro.add(element));

    const blocs = collecterBlocs(zone, ancetresIntro);
    const blocsFormulaire = blocs.filter(estDansFormulaire);
    const blocsStandard = blocs.filter((bloc) => !estDansFormulaire(bloc));
    const elementsAnimes = [...new Set([...elementsIntro, ...blocs])];

    const auDessus = [];
    const dansLeViewport = [];
    const sousLeViewport = [];

    blocs.forEach((bloc) => {
        const rect = bloc.getBoundingClientRect();

        if (rect.bottom < 0) {
            auDessus.push(bloc);
        } else if (rect.top < window.innerHeight * 0.94) {
            dansLeViewport.push(bloc);
        } else {
            sousLeViewport.push(bloc);
        }
    });

    // Une page plus courte que le viewport ne déclenchera jamais le batch :
    // les derniers blocs doivent être révélés sans attendre un scroll.
    const pageDefilable = document.documentElement.scrollHeight > window.innerHeight + 4;

    const cleanups = [];
    const lignesAnimees = new Set();
    const compteursAnimes = new Set();
    let contexte = null;
    let detruit = false;

    const revelerLignesAnimees = () => {
        lignesAnimees.forEach((ligne) => {
            gsap.killTweensOf(ligne);
            gsap.set(ligne, { clearProps: 'opacity,transform' });
        });
    };

    /**
     * Compteurs opt-in : `data-motion-count` porte la valeur finale.
     *
     * Le compteur est décoratif (le texte accessible porte toujours la
     * valeur finale, cf. `sr-only` côté Blade). Il ne démarre qu'une fois,
     * quand la carte qui le contient est révélée. `immediat` couvre les
     * blocs déjà passés, l'impression et le filet de sécurité.
     */
    const animerCompteurs = (racine, { immediat = false } = {}) => {
        const cibles = [];

        if (racine.matches?.('[data-motion-count]')) cibles.push(racine);
        racine.querySelectorAll?.('[data-motion-count]').forEach((element) => cibles.push(element));

        cibles.forEach((element) => {
            if (element.dataset.motionCountFini === '1') return;

            const valeurFinale = Number(element.dataset.motionCount);

            if (! Number.isFinite(valeurFinale)) return;

            element.dataset.motionCountFini = '1';

            const formater = (valeur) => formaterCompteur(element, valeur);

            if (immediat) {
                element.textContent = formater(valeurFinale);

                return;
            }

            const proxy = { valeur: 0 };

            element.textContent = formater(0);

            const tween = gsap.to(proxy, {
                valeur: valeurFinale,
                duration: 0.9,
                ease: 'power2.out',
                onUpdate: () => {
                    element.textContent = formater(proxy.valeur);
                },
            });

            tween.eventCallback('onComplete', () => {
                compteursAnimes.delete(tween);
                element.textContent = formater(valeurFinale);
            });

            compteursAnimes.add(tween);
        });
    };

    const nettoyer = () => {
        if (detruit) return;

        detruit = true;
        cleanups.forEach((fn) => fn());
        contexte?.revert();
        contexte = null;
        compteursAnimes.forEach((tween) => tween.kill());
        compteursAnimes.clear();
        ScrollTrigger.getAll().forEach((declencheur) => declencheur.kill());
        elementsAnimes.forEach((element) => gsap.set(element, { clearProps: 'opacity,transform' }));
        revelerLignesAnimees();
        gsap.set(main, { clearProps: 'opacity' });
    };

    const toutReveler = () => {
        nettoyer();
        html.classList.remove('motion-prep');
        html.classList.add('motion-ready');
    };

    try {
        // On prépare les états pendant que `motion-prep` masque encore le
        // contenu : aucune frame avec les éléments visibles puis repositionnés.
        // Les blocs de formulaire restent à 70 % d'opacité : les champs sont
        // lisibles et focusables immédiatement, seule la fin de l'entrée est
        // animée. Les autres cartes partent de 0.
        gsap.set(main, { opacity: 0 });
        if (elementsIntro.length > 0) gsap.set(elementsIntro, { opacity: 0, y: 10 });
        if (blocsStandard.length > 0) gsap.set(blocsStandard, { opacity: 0, y: 14 });
        if (blocsFormulaire.length > 0) gsap.set(blocsFormulaire, { opacity: 0.7, y: 0 });

        html.classList.remove('motion-prep');
        html.classList.add('motion-ready');

        contexte = gsap.context(() => {
            gsap.to(main, { opacity: 1, duration: 0.25, ease: 'power2.out' });

            if (elementsIntro.length > 0) {
                gsap.to(elementsIntro, {
                    opacity: 1,
                    y: 0,
                    duration: 0.5,
                    ease: 'power2.out',
                    stagger: 0.05,
                    delay: 0.04,
                    clearProps: 'transform',
                });
            }

            const reveler = (lot) => {
                if (lot.length === 0) return;

                const standards = lot.filter((element) => !estDansFormulaire(element));
                const formulaires = lot.filter(estDansFormulaire);

                if (standards.length > 0) {
                    gsap.to(standards, {
                        opacity: 1,
                        y: 0,
                        duration: 0.5,
                        ease: 'power2.out',
                        stagger: 0.05,
                        overwrite: true,
                        clearProps: 'transform',
                    });
                }

                // Entrée douce, sans déplacement, des cartes/sections de formulaire.
                if (formulaires.length > 0) {
                    gsap.to(formulaires, {
                        opacity: 1,
                        duration: 0.3,
                        ease: 'power2.out',
                        stagger: 0.03,
                        overwrite: true,
                        clearProps: 'transform',
                    });
                }

                lot.forEach((bloc) => {
                    revelerLignes(bloc, lignesAnimees);
                    animerCompteurs(bloc);
                });
            };

            // Rechargement en milieu de page : les blocs déjà passés restent
            // visibles immédiatement, sans attendre un scroll qui ne viendra pas.
            if (auDessus.length > 0) {
                gsap.set(auDessus, { opacity: 1, y: 0, clearProps: 'transform' });
                auDessus.forEach((bloc) => {
                    revelerLignes(bloc, lignesAnimees);
                    animerCompteurs(bloc, { immediat: true });
                });
            }

            if (dansLeViewport.length > 0) {
                reveler(dansLeViewport);
            }

            if (sousLeViewport.length > 0) {
                if (!pageDefilable) {
                    reveler(sousLeViewport);
                } else {
                    ScrollTrigger.batch(sousLeViewport, {
                        start: 'top 94%',
                        once: true,
                        interval: 0.1,
                        batchMax: 6,
                        onEnter: reveler,
                    });
                }
            }
        }, main);

        const rafraichir = () => {
            if (detruit) return;

            ScrollTrigger.refresh();
        };

        const surLoad = () => rafraichir();
        window.addEventListener('load', surLoad, { once: true });
        cleanups.push(() => window.removeEventListener('load', surLoad));

        if (document.fonts?.ready) {
            document.fonts.ready.then(rafraichir).catch(() => {});
        }

        const rafraichirId = requestAnimationFrame(rafraichir);
        cleanups.push(() => cancelAnimationFrame(rafraichirId));

        // Filet de sécurité : un compteur visible qui n'aurait pas été révélé
        // (mise en page inattendue) affiche sa valeur finale, jamais « 0 ».
        const filetCompteurs = window.setTimeout(() => {
            if (detruit) return;

            zone.querySelectorAll('[data-motion-count]').forEach((element) => {
                if (element.dataset.motionCountFini === '1') return;

                const rect = element.getBoundingClientRect();

                if (rect.top < window.innerHeight && rect.bottom > 0) {
                    animerCompteurs(element);
                }
            });
        }, 2600);
        cleanups.push(() => window.clearTimeout(filetCompteurs));

        // Impression : ne jamais sortir une page avec des blocs encore masqués.
        const revelerPourImpression = () => {
            gsap.killTweensOf(main);
            gsap.set(main, { opacity: 1 });

            elementsAnimes.forEach((element) => {
                gsap.killTweensOf(element);
                gsap.set(element, { opacity: 1, y: 0 });
            });

            // Un compteur à mi-course ne doit jamais s'imprimer sur une valeur
            // intermédiaire : on force la valeur finale, sans animation.
            compteursAnimes.forEach((tween) => tween.kill());
            compteursAnimes.clear();

            zone.querySelectorAll('[data-motion-count]').forEach((element) => {
                const valeurFinale = Number(element.dataset.motionCount);

                if (Number.isFinite(valeurFinale)) {
                    element.textContent = formaterCompteur(element, valeurFinale);
                }
            });

            revelerLignesAnimees();
        };

        window.addEventListener('beforeprint', revelerPourImpression);
        cleanups.push(() => window.removeEventListener('beforeprint', revelerPourImpression));

        return () => {
            nettoyer();
            html.classList.remove('motion-ready');
        };
    } catch (error) {
        toutReveler();

        throw error;
    }
}
