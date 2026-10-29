/**
 * Thème du portail étudiant — V1 classique / V2 par défaut.
 *
 * Le thème initial est posé avant peinture par le script inline du layout
 * (`resources/views/layouts/app.blade.php`). Ce module prend le relais pour :
 * - synchroniser tous les sélecteurs (barre latérale, fiche) ;
 * - basculer sans rechargement quand JavaScript est disponible ;
 * - persister la préférence locale et conserver une URL partageable ;
 * - laisser les liens fonctionner normalement sans JavaScript.
 *
 * Aucun appel serveur : le choix est purement visuel. Les autorisations,
 * les données et les formulaires restent inchangés.
 */
(function () {
    'use strict';

    const STORAGE_KEY = 'etudiant-theme';
    const LEGACY_STORAGE_KEY = 'profil-theme';
    const OPTION_SELECTOR = '[data-etudiant-theme-option]';

    const racine = () => document.documentElement;

    const themeCourant = () => (
        racine().getAttribute('data-etudiant-theme') === 'v1' ? 'v1' : 'v2'
    );

    const options = () => Array.from(document.querySelectorAll(OPTION_SELECTOR));

    const mouvementReduit = () => (
        window.matchMedia('(prefers-reduced-motion: reduce)').matches
    );

    const synchroniser = (theme) => {
        const valeur = theme === 'v1' ? 'v1' : 'v2';

        racine().setAttribute('data-etudiant-theme', valeur);

        if (document.body) {
            document.body.setAttribute('data-etudiant-page-theme', valeur);
        }

        options().forEach((option) => {
            if (option.getAttribute('data-etudiant-theme-option') === valeur) {
                option.setAttribute('aria-current', 'true');
            } else {
                option.removeAttribute('aria-current');
            }
        });
    };

    const mettreAJourUrl = (theme) => {
        try {
            const url = new URL(window.location.href);

            if (theme === 'v2') {
                // V2 est le défaut : l'URL reste propre.
                url.searchParams.delete('theme');
            } else {
                url.searchParams.set('theme', 'v1');
            }

            window.history.replaceState({}, '', url);
        } catch (error) {
            // URL indisponible : le thème reste appliqué pour la page.
        }
    };

    const appliquer = (theme) => {
        const valeur = theme === 'v1' ? 'v1' : 'v2';

        if (!mouvementReduit()) {
            racine().classList.add('etudiant-theme-transition');
            window.setTimeout(() => {
                racine().classList.remove('etudiant-theme-transition');
            }, 420);
        }

        synchroniser(valeur);
        mettreAJourUrl(valeur);

        try {
            window.localStorage.setItem(STORAGE_KEY, valeur);
            window.localStorage.removeItem(LEGACY_STORAGE_KEY);
        } catch (error) {
            // Stockage indisponible : le thème reste actif pour la page.
        }

        document.dispatchEvent(new CustomEvent('etudiant:theme-change', {
            detail: { theme: valeur },
        }));
    };

    const changer = (theme) => {
        if (theme === themeCourant()) {
            synchroniser(theme);
            mettreAJourUrl(theme);
            return;
        }

        if (typeof document.startViewTransition === 'function' && !mouvementReduit()) {
            try {
                document.startViewTransition(() => appliquer(theme));
                return;
            } catch (error) {
                // API View Transition indisponible : repli instantané.
            }
        }

        appliquer(theme);
    };

    const initialiser = () => {
        // Hors portail étudiant, aucun attribut n'est posé : ne rien faire.
        if (!racine().hasAttribute('data-etudiant-theme')) {
            return;
        }

        // Corrige l'état du body et des libellés si le script inline a
        // appliqué une préférence locale différente du rendu serveur.
        synchroniser(themeCourant());

        document.addEventListener('click', (event) => {
            const option = event.target.closest(OPTION_SELECTOR);

            if (!option) {
                return;
            }

            if (
                event.defaultPrevented
                || event.button !== 0
                || event.metaKey
                || event.ctrlKey
                || event.shiftKey
                || event.altKey
            ) {
                return;
            }

            event.preventDefault();

            const theme = option.getAttribute('data-etudiant-theme-option') === 'v1'
                ? 'v1'
                : 'v2';

            changer(theme);
        });
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initialiser, { once: true });
    } else {
        initialiser();
    }
})();
