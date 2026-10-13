@props(['delai' => 900, 'preavis' => 60])

{{--
    Surveillance d'inactivité (LOOP N) : overlay + avertissement, aucune
    requête réseau (un heartbeat ne vaut pas activité humaine). Le serveur
    reste seul juge (middleware SecondaireVerrou) : à expiration, la
    prochaine requête reçoit l'écran de verrouillage ou une 423.
--}}
<div x-data="surveillanceInactivite({{ (int) $delai }}, {{ (int) $preavis }})"
     x-init="demarrer()"
     @click.window="activite()" @keydown.window="activite()" @scroll.window="activite()">
    <div x-show="avertir" x-cloak role="alertdialog" aria-label="Verrouillage imminent"
         class="fixed inset-x-0 top-0 z-[80] flex justify-center p-4">
        <div class="rounded-2xl border border-amber-300 bg-amber-50 px-5 py-3 text-sm text-amber-900 shadow-lg">
            <p class="font-bold">Verrouillage dans <span x-text="restant"></span> s.</p>
            <button type="button" @click="activite()" class="mt-1 font-bold underline underline-offset-2">Rester connecté</button>
        </div>
    </div>
</div>

<script>
function surveillanceInactivite(delai, preavis) {
    return {
        derniere: Date.now(),
        avertir: false,
        restant: 0,
        minuteur: null,
        demarrer() {
            this.minuteur = setInterval(() => this.tic(), 1000);
            this.synchroniserOnglets();
        },
        activite() {
            this.derniere = Date.now();
            try {
                localStorage.setItem('secondaire-derniere-activite', String(this.derniere));
            } catch (e) {}
            this.avertir = false;
        },
        synchroniserOnglets() {
            window.addEventListener('storage', (e) => {
                if (e.key === 'secondaire-derniere-activite' && e.newValue) {
                    const t = parseInt(e.newValue, 10);
                    if (!isNaN(t) && t > this.derniere) {
                        this.derniere = t;
                        this.avertir = false;
                    }
                }
            });
        },
        tic() {
            const ecoule = Math.floor((Date.now() - this.derniere) / 1000);
            const restant = delai - ecoule;
            if (restant <= 0) {
                // Le serveur tranche : la requête suivante reçoit l'écran
                // de verrouillage (ou une 423). Pas de voile local : un
                // rechargement en boucle est exclu (l'écran n'embarque
                // pas la surveillance).
                clearInterval(this.minuteur);
                window.location.reload();
                return;
            }
            if (restant <= preavis) {
                this.avertir = true;
                this.restant = restant;
            } else {
                this.avertir = false;
            }
        },
    };
}
</script>
