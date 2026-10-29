/**
 * Garde anti double-soumission (LOOP-UNIREGISTRE-UX-UI-PREMIUM-FLUIDITE-01, §4.3 / U1).
 *
 * Portée globale par délégation d'événements : aucun changement de
 * markup, aucune donnée lue ni stockée, aucune requête supplémentaire.
 * À la soumission d'un formulaire classique, les boutons de soumission
 * sont désactivés et le formulaire marqué `aria-busy` — l'utilisateur
 * voit un état « en cours » et un double clic ne re-déclenche rien.
 *
 * Opt-out : `data-garde="off"` sur le formulaire. Les formulaires
 * Livewire (`wire:submit`) sont exclus : ils gèrent leurs propres états
 * (`wire:loading`). Le retour depuis le cache bfcache (bouton
 * « Précédent ») réactive les boutons pour éviter un formulaire figé.
 *
 * Sécurité : purement présentationnel — la protection serveur (CSRF,
 * idempotence, verrous, optimistic locking) reste la seule autorité.
 */
const formulairesArmes = new WeakSet();

function desactiverSoumissions(formulaire) {
    formulaire.setAttribute('aria-busy', 'true');

    formulaire
        .querySelectorAll('button[type="submit"], button:not([type]), input[type="submit"]')
        .forEach((bouton) => {
            if (bouton.disabled) {
                return;
            }

            bouton.disabled = true;
            bouton.setAttribute('data-garde-armer', 'true');
        });
}

function reactiverSoumissions() {
    document.querySelectorAll('[data-garde-armer]').forEach((bouton) => {
        bouton.disabled = false;
        bouton.removeAttribute('data-garde-armer');
    });

    formulairesArmes.clear();
}

document.addEventListener(
    'submit',
    (event) => {
        const formulaire = event.target;

        if (!(formulaire instanceof HTMLFormElement)) {
            return;
        }

        if (formulaire.dataset.garde === 'off' || formulaire.hasAttribute('wire:submit')) {
            return;
        }

        if (formulairesArmes.has(formulaire)) {
            return;
        }

        formulairesArmes.add(formulaire);

        // Frame suivant : laisse le navigateur lire la valeur du bouton
        // cliqué (submitter) avant la désactivation.
        requestAnimationFrame(() => desactiverSoumissions(formulaire));
    },
    true,
);

// Retour bfcache (« Précédent ») : réarmer pour un nouvel envoi.
window.addEventListener('pageshow', (event) => {
    if (event.persisted) {
        reactiverSoumissions();
    }
});
