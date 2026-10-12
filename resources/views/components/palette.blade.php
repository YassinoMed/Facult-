{{--
    Palette de commandes (Ctrl+K / ⌘K) : navigation + actions, filtrée
    côté client sur une liste construite serveur selon les permissions.
    Un étudiant ne voit jamais les actions admin (liste serveur).
    Usage : <x-palette /> dans le layout (utilisateurs connectés).
--}}
@php
$actions = [];
if (auth()->check()) {
    $actions[] = ['titre' => 'Tableau de bord', 'detail' => 'Pilotage', 'url' => route('admin.dashboard')];
    $actions[] = ['titre' => 'Registre des étudiants', 'detail' => 'Scolarité', 'url' => route('admin.etudiants.index')];
    $actions[] = ['titre' => 'Archives', 'detail' => 'Dossiers, notes, documents', 'url' => route('admin.archives.index')];
    $actions[] = ['titre' => 'Recherche globale', 'detail' => 'Étudiants, cours, salles', 'url' => route('admin.recherche')];
    if (auth()->user()->can('cockpit.view')) {
        $actions[] = ['titre' => 'Cockpit', 'detail' => 'À traiter, alertes, activité', 'url' => route('admin.cockpit.index')];
    }
    if (auth()->user()->can('tasks.view')) {
        $actions[] = ['titre' => 'Mes tâches', 'detail' => 'Centre de tâches', 'url' => route('admin.taches.index')];
    }
    if (auth()->user()->can('statistiques.view')) {
        $actions[] = ['titre' => 'Statistiques', 'detail' => 'Indicateurs', 'url' => route('admin.statistiques.index')];
    }
    if (auth()->user()->can('data.import')) {
        $actions[] = ['titre' => 'Importer des données', 'detail' => 'Référentiels', 'url' => route('admin.donnees.hub')];
    }
    if (auth()->user()->can('data.export')) {
        $actions[] = ['titre' => 'Exporter des données', 'detail' => 'CSV, XLSX, JSON', 'url' => route('admin.donnees.hub')];
    }
    if (auth()->user()->can('schedules.manage')) {
        $actions[] = ['titre' => 'Importer un planning', 'detail' => 'Emplois du temps', 'url' => route('admin.emplois-du-temps.import.form')];
    }
} elseif (session('etudiant_auth_id')) {
    $actions[] = ['titre' => 'Mon tableau de bord', 'detail' => 'Espace étudiant', 'url' => route('etudiant.dashboard')];
    $actions[] = ['titre' => 'Mes notes', 'detail' => 'Résultats', 'url' => route('etudiant.notes.index')];
    $actions[] = ['titre' => 'Mes documents', 'detail' => 'Pièces', 'url' => route('etudiant.documents.index')];
    $actions[] = ['titre' => 'Mon emploi du temps', 'detail' => 'Planning', 'url' => route('etudiant.emploi-du-temps.semaine')];
}
@endphp

@if($actions !== [])
    <div x-data="paletteCommandes(@js($actions), @js(route('admin.recherche.instantanee')), @js(route('admin.recherche.contexte')))">
        <div x-show="ouverte" x-cloak class="fixed inset-0 z-[75] flex items-start justify-center p-4 pt-[12vh]"
             @keydown.escape.window="ouverte = false" role="dialog" aria-modal="true" aria-label="Palette de commandes">
            <div class="absolute inset-0 bg-slate-900/50" @click="ouverte = false" aria-hidden="true"></div>
            <div class="relative w-full max-w-lg overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-xl">
                <div class="flex items-center gap-2 border-b border-slate-100 px-4">
                    <span aria-hidden="true">🔎</span>
                    <label for="palette-recherche" class="sr-only">Rechercher (préfixe &gt; pour les commandes, ? pour l'aide)</label>
                    <input id="palette-recherche" type="text" x-model="recherche" x-ref="champ"
                           @keydown.arrow-down.prevent="suivant(1)" @keydown.arrow-up.prevent="suivant(-1)"
                           @keydown.enter.prevent="aller()"
                           placeholder="Rechercher… (> commandes, ? aide)"
                           class="w-full py-3.5 text-sm focus:outline-none min-h-[44px]">
                    <span class="admin-kbd" aria-hidden="true">esc</span>
                </div>
                <ul role="listbox" aria-label="Résultats" class="max-h-72 overflow-y-auto py-1.5">
                    <template x-for="(element, index) in elements" :key="element.kind + ':' + (element.url || index)">
                        <li role="option" :aria-selected="(index === curseur).toString()">
                            <a :href="element.url" @mouseenter="curseur = index"
                               :class="index === curseur ? 'bg-primary-50' : ''"
                               class="flex items-center justify-between gap-3 px-4 py-2.5 text-sm min-h-[44px]">
                                <span class="font-bold text-slate-900" x-text="element.titre"></span>
                                <span class="text-[11px] text-slate-500" x-text="element.detail"></span>
                            </a>
                        </li>
                    </template>
                    <li x-show="aide" class="px-4 py-3 text-xs text-slate-600">
                        <p class="font-bold text-slate-900">Raccourcis clavier</p>
                        <ul class="mt-1.5 space-y-1">
                            <li><span class="admin-kbd">Ctrl/⌘ K</span> ouvrir la palette</li>
                            <li><span class="admin-kbd">&gt;</span> mode commandes (navigation uniquement)</li>
                            <li><span class="admin-kbd">?</span> cette aide</li>
                            <li><span class="admin-kbd">↑ ↓ Entrée</span> naviguer et ouvrir</li>
                            <li><span class="admin-kbd">Échap</span> fermer</li>
                            <li><span class="admin-kbd">/</span> recherche globale</li>
                        </ul>
                        <a href="{{ route('admin.preferences') }}" class="mt-2 inline-block font-bold text-primary-800 underline underline-offset-2">Préférences d'affichage →</a>
                    </li>
                    <li x-show="chargement" class="px-4 py-3 text-sm text-slate-500">Recherche en cours…</li>
                    <li x-show="erreur" class="px-4 py-3 text-sm text-rose-600">Recherche indisponible, réessayez.</li>
                    <li x-show="!aide && !chargement && !erreur && elements.length === 0" class="px-4 py-3 text-sm text-slate-500">Aucun résultat.</li>
                </ul>
                <p class="border-t border-slate-100 px-4 py-2 text-[11px] text-slate-400">Ctrl/⌘+K palette · &gt; commandes · ? aide · ≥2 lettres = recherche d'entités</p>
            </div>
        </div>
    </div>
    <script>
        function paletteCommandes(actions, urlInstantanee, urlContexte) {
            return {
                ouverte: false,
                recherche: '',
                curseur: 0,
                elements: [],
                chargement: false,
                erreur: false,
                minuteur: null,
                requete: null,
                init() {
                    window.addEventListener('keydown', (e) => {
                        const saisie = ['INPUT', 'TEXTAREA', 'SELECT'].includes(document.activeElement?.tagName);
                        if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') {
                            e.preventDefault();
                            this.ouverte = !this.ouverte;
                            this.recherche = '';
                            this.curseur = 0;
                            if (this.ouverte) { this.chargerContexte(); this.$nextTick(() => this.$refs.champ?.focus()); }
                        } else if (e.key === 'Escape') {
                            this.ouverte = false;
                        } else if (!saisie && !e.ctrlKey && !e.metaKey && !e.altKey) {
                            if (e.key === '/') {
                                const cible = document.getElementById('recherche-globale');
                                if (cible) { e.preventDefault(); cible.focus(); }
                            } else {
                                const raccourcis = { n: 'nouveau', e: 'exporter', i: 'importer' };
                                const nom = raccourcis[e.key.toLowerCase()];
                                const cible = nom ? document.querySelector(`[data-raccourci="${nom}"]`) : null;
                                if (cible) { e.preventDefault(); cible.click(); }
                            }
                        }
                    });
                    this.$watch('recherche', () => this.actualiser());
                },
                get modeCommandes() {
                    return this.recherche.trimStart().startsWith('>');
                },
                get aide() {
                    return this.recherche.trim() === '?';
                },
                actualiser() {
                    // Annule la requête obsolète avant d'en lancer une autre.
                    if (this.requete) { this.requete.abort(); this.requete = null; }
                    clearTimeout(this.minuteur);
                    const q = this.modeCommandes ? this.recherche.trimStart().slice(1).trim().toLowerCase() : this.recherche.trim().toLowerCase();
                    this.curseur = 0;
                    this.erreur = false;
                    if (this.aide) { this.elements = []; this.chargement = false; return; }
                    const commandes = q === ''
                        ? actions
                        : actions.filter((a) => (a.titre + ' ' + a.detail).toLowerCase().includes(q));
                    const versElements = (liste) => liste.map((a) => ({ kind: 'action', titre: a.titre, detail: a.detail, url: a.url }));
                    if (this.modeCommandes || q.length < 2) {
                        this.chargement = false;
                        this.elements = versElements(commandes);
                        return;
                    }
                    this.chargement = true;
                    // Recherche d'entités serveur (mêmes permissions que la
                    // page) + commandes filtrées, curseur unifié.
                    this.minuteur = setTimeout(() => {
                        const controleur = new AbortController();
                        this.requete = controleur;
                        fetch(urlInstantanee + '?q=' + encodeURIComponent(this.recherche.trim()), {
                            headers: { 'Accept': 'application/json' },
                            signal: controleur.signal,
                        }).then((r) => r.ok ? r.json() : Promise.reject()).then((d) => {
                            const resultats = (Array.isArray(d) ? d.slice(0, 12) : []).map((x) => ({
                                kind: 'resultat', titre: x.titre,
                                detail: x.section + ' · ' + x.detail, url: x.lien,
                            }));
                            this.elements = versElements(commandes).concat(resultats);
                            this.chargement = false;
                        }).catch((e) => {
                            if (e?.name === 'AbortError') return;
                            this.erreur = true;
                            this.chargement = false;
                            this.elements = versElements(commandes);
                        });
                    }, 250);
                },
                chargerContexte() {
                    // Requête vide : récents + favoris personnels.
                    fetch(urlContexte, { headers: { 'Accept': 'application/json' } }).then((r) => r.ok ? r.json() : null).then((d) => {
                        if (!d || this.recherche.trim() !== '') return;
                        const elements = [];
                        (d.favoris || []).forEach((x) => elements.push({ kind: 'favori', titre: '★ ' + x.titre, detail: x.detail, url: x.lien }));
                        (d.recents || []).forEach((x) => elements.push({ kind: 'recent', titre: x.titre, detail: 'Récent · ' + x.detail, url: x.lien }));
                        this.elements = elements;
                    }).catch(() => {});
                },
                suivant(delta) {
                    const n = this.elements.length;
                    if (n === 0) return;
                    this.curseur = (this.curseur + delta + n) % n;
                    this.$nextTick(() => {
                        this.$el.querySelectorAll('[role=option] a')[this.curseur]?.scrollIntoView({ block: 'nearest' });
                    });
                },
                aller() {
                    const cible = this.elements[this.curseur];
                    if (cible && cible.url) window.location.href = cible.url;
                },
            };
        }
    </script>
@endif
