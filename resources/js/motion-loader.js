/**
 * Socle de mouvement global — chargé une seule fois par `app.js`.
 *
 * Quatre portées, décidées côté Blade (`data-motion` sur <body>) :
 * - `cinematic` : pages publiques éditoriales (GSAP + ScrollTrigger + Lenis) ;
 * - `etudiant` : portail étudiant, module GSAP + Lenis dédié (`etudiant.js`) ;
 * - `admin` : back-office, module GSAP borné (`admin.js`) chargé à la demande.
 *   Aucun Lenis, aucune parallaxe, aucun curseur : le mouvement sert la
 *   hiérarchie du contenu, jamais la saisie ni les tableaux de travail ;
 * - `app` : authentification, espace enseignant, erreurs. Aucun JavaScript de
 *   mouvement n'y est chargé : une animation CSS courte révèle les en-têtes
 *   de page, sans smooth scroll, sans parallaxe et sans curseur personnalisé.
 *
 * Le module lourd n'est donc téléchargé que sur les pages qui en ont besoin.
 * `prefers-reduced-motion` est respecté au chargement et à chaud.
 */

const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');

let cleanup = null;
let runId = 0;

function scopeCourante() {
    const portee = document.body?.dataset.motion;

    return portee === 'cinematic' || portee === 'etudiant' || portee === 'admin'
        ? portee
        : 'app';
}

function revelerTout() {
    document.documentElement.classList.remove('motion-prep');
    document.documentElement.classList.add('motion-ready');
}

function masquerLeMouvement() {
    ++runId;
    cleanup?.();
    cleanup = null;
    revelerTout();
}

async function demarrer(estNavigation = false) {
    const id = ++runId;

    cleanup?.();
    cleanup = null;

    if (reducedMotion.matches) {
        revelerTout();
        return;
    }

    const portee = scopeCourante();

    // La portée « étudiant » possède son propre module GSAP chargé par le
    // layout : il retire `motion-prep` après avoir posé les états initiaux.
    if (portee === 'etudiant') {
        return;
    }

    // Le back-office charge un module borné à la demande. Il ne détourne
    // jamais le scroll et ne s'applique qu'au contenu principal.
    if (portee === 'admin') {
        if (estNavigation) {
            document.documentElement.classList.add('motion-prep');
        }

        try {
            const module = await import('./admin');

            // Une navigation Livewire a pu changer la page pendant le
            // chargement du chunk : on ignore l'initialisation obsolète.
            if (id !== runId) return;

            cleanup = module.initAdminMotion();
        } catch (error) {
            // Le contenu ne doit jamais rester masqué si le chunk échoue.
            revelerTout();
            console.warn('[motion admin] initialisation ignorée', error);
        }

        return;
    }

    // La portée « app » est prise en charge par CSS : rien à télécharger.
    if (portee !== 'cinematic') {
        revelerTout();
        return;
    }

    // La préparation initiale est posée par le script inline du layout. Lors
    // d'une navigation Livewire, il faut la rétablir avant de charger le
    // module pour éviter un flash de contenu.
    if (estNavigation) {
        document.documentElement.classList.add('motion-prep');
    }

    try {
        const module = await import('./motion-cinematic');

        // Une navigation Livewire a pu changer la page pendant le chargement
        // du chunk : on ignore alors cette initialisation devenue obsolète.
        if (id !== runId) return;

        cleanup = module.initMotion();
    } catch (error) {
        // Le contenu ne doit jamais rester masqué si le chunk échoue.
        revelerTout();
        console.warn('[motion] initialisation ignorée', error);
    }
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', demarrer, { once: true });
} else {
    demarrer();
}

document.addEventListener('livewire:navigated', () => demarrer(true));

window.addEventListener('pageshow', (event) => {
    if (event.persisted) demarrer(true);
});

if (typeof reducedMotion.addEventListener === 'function') {
    reducedMotion.addEventListener('change', () => {
        if (reducedMotion.matches) {
            masquerLeMouvement();
        } else {
            demarrer();
        }
    });
}
