@props(['delai' => 900, 'preavis' => 60, 'ancreServeur' => null])

{{--
    Surveillance d'inactivité (LOOP N) : avertissement puis voile opaque
    couvrant le contenu — la page et les données non enregistrées restent
    en DOM, le déverrouillage se fait par fetch SANS rechargement.
    Aucune requête réseau automatique : `prolonger` n'est émis que sur
    événement de saisie réel (debounce ≥ 60 s) ou clic « Rester connecté ».
    Horloge ancrée sur le serveur (`ancreServeur`) : une horloge client
    fausse ne retarde ni n'avance abusivement le minuteur. À l'ouverture
    d'onglet, l'activité mémorisée par un autre onglet est adoptée (sans
    jamais accorder plus que le délai). Une soumission de formulaire
    après expiration est interceptée : voile + déverrouillage, puis
    renvoi manuel — aucune saisie perdue.
    Le serveur reste seul juge (SecondaireVerrou) : 423 → rechargement
    vers l'écran serveur, session morte → connexion normale.
--}}
<div x-data="surveillanceInactivite({{ (int) $delai }}, {{ (int) $preavis }}, {{ (int) ($ancreServeur ?? time()) }})"
     x-init="demarrer()"
     @click.window="activite()" @keydown.window="activite()" @scroll.window.passive="activite()"
     @visibilitychange.window="auReveil()">
    <div x-show="avertir && !voile" x-cloak role="alert" aria-label="Verrouillage imminent"
         class="fixed inset-x-0 top-0 z-[80] flex justify-center p-4">
        <div class="rounded-2xl border border-amber-300 bg-amber-50 px-5 py-3 text-sm text-amber-900 shadow-lg">
            <p class="font-bold">Verrouillage dans <span x-text="restant" aria-live="polite"></span> s.</p>
            <button type="button" @click="resterConnecte()" class="mt-1 font-bold underline underline-offset-2">Rester connecté</button>
        </div>
    </div>

    <div x-show="voile" x-cloak x-transition.opacity role="alertdialog" aria-modal="true" aria-label="Session verrouillée"
         class="fixed inset-0 z-[100] overflow-y-auto bg-slate-50 dark:bg-slate-950"
         x-effect="document.documentElement.classList.toggle('overflow-hidden', voile)">
        <div class="mx-auto flex min-h-screen w-full max-w-md items-center px-4 py-10">
            <div class="ds-card w-full p-6 text-center sm:p-8">
                <div class="mx-auto flex h-14 w-14 items-center justify-center rounded-2xl bg-slate-900 text-white dark:bg-slate-100 dark:text-slate-900" aria-hidden="true">
                    <svg class="h-7 w-7" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 1 0-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 0 0 2.25-2.25v-6.75a2.25 2.25 0 0 0-2.25-2.25H6.75a2.25 2.25 0 0 0-2.25 2.25v6.75a2.25 2.25 0 0 0 2.25 2.25Z" /></svg>
                </div>
                <h2 class="mt-4 text-xl font-black text-slate-900 dark:text-slate-100">Session verrouillée</h2>
                <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Inactivité prolongée. Votre travail en cours est conservé sur cette page.</p>
                <p x-show="messageVoile" x-text="messageVoile" role="status" class="mt-2 text-xs font-bold text-amber-700 dark:text-amber-300"></p>
                <form class="mt-5 space-y-4 text-left" @submit.prevent="deverrouiller()">
                    <div>
                        <label class="mb-1 block text-[10px] font-bold uppercase tracking-wider text-slate-500" for="verrou-code-client">Code de sécurité *</label>
                        <input id="verrou-code-client" x-ref="code" x-model="code" :type="masquer ? 'password' : 'text'" inputmode="numeric"
                               autocomplete="off" pattern="\d{6,8}" maxlength="8" minlength="6" required
                               aria-describedby="verrou-erreur-client"
                               class="w-full rounded-xl border border-slate-200 bg-white px-3.5 py-2.5 text-center text-lg font-mono tracking-[0.3em] dark:border-slate-700 dark:bg-slate-900" />
                        <div class="mt-1 flex items-center justify-center gap-3">
                            <button type="button" @click="masquer = !masquer" class="text-[11px] font-bold text-slate-500 underline underline-offset-2" :aria-pressed="masquer.toString()">
                                <span x-text="masquer ? 'Afficher' : 'Masquer'"></span> la saisie
                            </button>
                        </div>
                        <p id="verrou-erreur-client" x-show="erreur" x-text="erreur" role="alert" class="mt-1 text-xs font-semibold text-rose-600"></p>
                    </div>
                    <button type="submit" :disabled="enCours"
                            class="w-full rounded-xl bg-primary-700 px-4 py-2.5 text-sm font-bold text-white transition hover:bg-primary-800 disabled:opacity-50">Déverrouiller</button>
                </form>
                <form method="POST" action="{{ route('admin.verrouillage.deconnecter') }}" class="mt-3">
                    @csrf
                    <button type="submit" class="text-xs font-bold text-slate-500 underline underline-offset-2 hover:text-slate-700 dark:text-slate-400">Se déconnecter</button>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
function surveillanceInactivite(delai, preavis, ancreServeur) {
    return {
        derniere: Date.now(),
        decalage: 0,
        avertir: false,
        restant: 0,
        voile: false,
        messageVoile: '',
        code: '',
        erreur: '',
        enCours: false,
        masquer: true,
        minuteur: null,
        dernierPing: 0,
        csrf: document.querySelector('meta[name="csrf-token"]')?.content ?? '',

        // Horloge ancrée serveur : neutralise une horloge client fausse.
        maintenant() { return Date.now() + this.decalage; },

        demarrer() {
            this.decalage = ancreServeur * 1000 - Date.now();
            // Nouvel onglet : adopte l'activité mémorisée par un autre
            // onglet si elle est plus ancienne (jamais de délai offert),
            // ignore les valeurs fossiles (autre session, déconnexion).
            try {
                const memorisee = parseInt(localStorage.getItem('secondaire-derniere-activite') ?? '', 10);
                const maintenant = this.maintenant();
                if (!isNaN(memorisee) && memorisee <= maintenant && maintenant - memorisee < delai * 1000) {
                    this.derniere = memorisee;
                } else {
                    this.derniere = maintenant;
                }
            } catch (e) { this.derniere = this.maintenant(); }
            // Soumission après expiration : interceptée (capture) avant
            // tout gestionnaire — voile + renvoi manuel, saisie intacte.
            document.addEventListener('submit', (e) => this.intercepterSoumission(e), true);
            this.minuteur = setInterval(() => this.tic(), 1000);
            this.synchroniserOnglets();
            this.tic();
        },

        activite() {
            if (this.voile) return;
            this.derniere = this.maintenant();
            try { localStorage.setItem('secondaire-derniere-activite', String(this.derniere)); } catch (e) {}
            this.avertir = false;
            // Prolongation serveur uniquement sur saisie réelle, debouncée
            // à 60 s — jamais de heartbeat automatique (aucun setInterval).
            if (Date.now() - this.dernierPing >= 60000) this.prolonger();
        },

        intercepterSoumission(e) {
            if (this.voile) return;
            if (this.maintenant() - this.derniere < delai * 1000) return;
            e.preventDefault();
            if (typeof e.stopImmediatePropagation === 'function') e.stopImmediatePropagation();
            this.messageVoile = 'Session expirée : déverrouillez, puis renvoyez le formulaire — aucune saisie n’est perdue.';
            this.verrouiller();
        },

        resterConnecte() {
            this.prolonger().then((ok) => {
                // Seul un accord serveur (204) réarme : un 423 tardif
                // pose le voile avec consigne au lieu d'un reset aveugle.
                if (ok === true) {
                    this.derniere = this.maintenant();
                    this.avertir = false;
                    return;
                }
                if (ok === false) {
                    this.messageVoile = 'Verrouillage déjà actif : déverrouillez pour continuer.';
                    this.verrouiller();
                }
            });
        },

        async prolonger() {
            if (this.dernierPing && Date.now() - this.dernierPing < 10000) return undefined;
            this.dernierPing = Date.now();
            try {
                const rep = await fetch("{{ route('admin.verrouillage.prolonger') }}", {
                    method: 'POST',
                    headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': this.csrf, 'X-Requested-With': 'XMLHttpRequest' },
                    redirect: 'manual',
                    keepalive: true,
                });
                if (rep.type === 'opaqueredirect') { window.location.reload(); return undefined; }
                if (rep.status === 423) { return false; }
                return rep.ok;
            } catch (e) { /* réseau indisponible : le serveur tranchera à la prochaine requête */ }
            return undefined;
        },

        tic() {
            if (this.voile) return;
            const ecoule = Math.floor((this.maintenant() - this.derniere) / 1000);
            const restant = delai - ecoule;
            if (restant <= 0) { this.verrouiller(); return; }
            if (restant <= preavis) { this.avertir = true; this.restant = restant; }
            else { this.avertir = false; }
        },

        auReveil() {
            // Retour de veille / onglet re-visible : tranche immédiatement
            // (les minuteurs d'onglets en arrière-plan sont throttlés).
            if (document.visibilityState === 'visible') this.tic();
        },

        verrouiller() {
            this.voile = true;
            this.avertir = false;
            clearInterval(this.minuteur);
            try { localStorage.setItem('secondaire-etat', 'verrouille'); } catch (e) {}
            this.$nextTick(() => this.$refs.code?.focus());
        },

        deverrouiller() {
            this.erreur = '';
            this.enCours = true;
            const donnees = new FormData();
            donnees.append('code', this.code);
            donnees.append('_token', this.csrf);
            fetch("{{ route('admin.verrouillage.verifier') }}", {
                method: 'POST',
                body: donnees,
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                redirect: 'manual',
            }).then((rep) => {
                this.enCours = false;
                if (rep.type === 'opaqueredirect') { window.location.reload(); return; }
                if (rep.ok) { this.deverrouille(); return; }
                // 423 (verrou acquis côté serveur) ou 419 (session/CSRF
                // expirée) : rechargement vers l'écran serveur (ou la
                // connexion) — réessayer le code serait sans issue.
                if (rep.status === 423 || rep.status === 419) { window.location.reload(); return; }
                // 422 (code incorrect / temporisation) ou 429 (throttle) :
                // message générique, écran conservé, aucune indice sur le code.
                this.erreur = rep.status === 429
                    ? 'Trop de tentatives. Réessayez plus tard.'
                    : 'Code incorrect.';
                this.code = '';
                this.$refs.code?.focus();
            }).catch(() => {
                this.enCours = false;
                this.erreur = 'Vérification impossible. Vérifiez votre connexion.';
            });
        },

        deverrouille() {
            this.voile = false;
            this.messageVoile = '';
            this.code = '';
            this.erreur = '';
            this.derniere = this.maintenant();
            this.avertir = false;
            this.minuteur = setInterval(() => this.tic(), 1000);
            try { localStorage.setItem('secondaire-etat', 'actif'); } catch (e) {}
        },

        async reprendreApresVeille() {
            // Un autre onglet signale une activité réelle pendant que
            // celui-ci est voilé : on tente une prolongation serveur
            // (action humaine avérée). 204 → voile levé ; 423 → maintenu.
            const ok = await this.prolonger();
            if (ok === true) this.deverrouille();
        },

        synchroniserOnglets() {
            window.addEventListener('storage', (e) => {
                if (e.key === 'secondaire-derniere-activite' && e.newValue) {
                    const t = parseInt(e.newValue, 10);
                    if (!isNaN(t) && t > this.derniere) {
                        if (this.voile) { this.reprendreApresVeille(); return; }
                        this.derniere = t; this.avertir = false;
                    }
                }
                if (e.key === 'secondaire-etat') {
                    if (e.newValue === 'verrouille' && !this.voile) { this.verrouiller(); }
                    if (e.newValue === 'actif' && this.voile) { this.deverrouille(); }
                }
            });
        },
    };
}
</script>
