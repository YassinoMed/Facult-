/**
 * Bandeau des menus d'examens — repérage et défilement horizontal.
 *
 * Douze menus ne tiennent pas sur un écran de portable : ce module garde
 * l'onglet courant visible et n'estompe un bord que du côté où du contenu
 * reste réellement à parcourir.
 *
 * Volontairement sans dépendance et chargé par `app.js` : ce n'est pas une
 * animation mais une aide de navigation. Elle doit donc fonctionner aussi
 * quand `prefers-reduced-motion` empêche le chargement du socle GSAP.
 * Aucun défilement animé : le recadrage est instantané.
 */
const MARGE_VISIBLE = 24;
const TOLERANCE = 4;
const initialises = new WeakSet();

let observateur = null;
let frame = 0;

/** État de défilement : 'aucun', 'droite', 'gauche' ou 'deux'. */
function etatDebordement(bandeau) {
    const debordement = bandeau.scrollWidth - bandeau.clientWidth;

    if (debordement <= TOLERANCE) return 'aucun';
    if (bandeau.scrollLeft <= TOLERANCE) return 'droite';
    if (bandeau.scrollLeft >= debordement - TOLERANCE) return 'gauche';

    return 'deux';
}

function actualiserDebordement(bandeau) {
    const etat = etatDebordement(bandeau);

    if (bandeau.dataset.tabsDebordement !== etat) {
        bandeau.dataset.tabsDebordement = etat;
    }
}

function rafraichir() {
    frame = 0;

    document.querySelectorAll('[data-motion-tabs]').forEach((bandeau) => {
        if (bandeau.isConnected) actualiserDebordement(bandeau);
    });
}

function planifier() {
    if (frame !== 0) return;

    frame = window.requestAnimationFrame(rafraichir);
}

/**
 * Recadre sur l'onglet courant, uniquement s'il n'est pas déjà lisible avec
 * une marge confortable. Un onglet déjà visible ne justifie pas de déplacer
 * la bande (et de masquer les premiers menus).
 */
function centrerOngletActif(bandeau) {
    const actif = bandeau.querySelector('[aria-current="page"]');

    if (!actif || bandeau.clientWidth === 0) return;

    const cadre = bandeau.getBoundingClientRect();
    const cible = actif.getBoundingClientRect();

    const visible = cible.left >= cadre.left + MARGE_VISIBLE
        && cible.right <= cadre.right - MARGE_VISIBLE;

    if (!visible) {
        const gauche = bandeau.scrollLeft
            + (cible.left - cadre.left)
            - (cadre.width - cible.width) / 2;

        bandeau.scrollLeft = Math.max(
            0,
            Math.min(gauche, bandeau.scrollWidth - bandeau.clientWidth),
        );
    }

    actualiserDebordement(bandeau);
}

function preparer(bandeau) {
    if (initialises.has(bandeau)) return;

    initialises.add(bandeau);
    bandeau.addEventListener('scroll', planifier, { passive: true });
    centrerOngletActif(bandeau);

    // Les largeurs d'onglets dépendent de la police : un second passage après
    // le chargement des polices évite un recadrage approximatif.
    if (document.fonts?.ready) {
        document.fonts.ready.then(() => centrerOngletActif(bandeau)).catch(() => {});
    }
}

function demarrer() {
    observateur?.disconnect();
    observateur = typeof ResizeObserver === 'function'
        ? new ResizeObserver(planifier)
        : null;

    document.querySelectorAll('[data-motion-tabs]').forEach((bandeau) => {
        preparer(bandeau);
        observateur?.observe(bandeau);
    });
}

window.addEventListener('resize', planifier);

window.addEventListener('load', () => {
    document.querySelectorAll('[data-motion-tabs]').forEach(centrerOngletActif);
}, { once: true });

document.addEventListener('livewire:navigated', demarrer);

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', demarrer, { once: true });
} else {
    demarrer();
}
