{{--
    Palette de commandes (Ctrl+K / ⌘K) : navigation + actions, filtrée
    côté client sur une liste construite serveur selon les permissions.
    Rôles supportés : Back-Office (Admin/Personnel), Enseignant, Étudiant.
    Un étudiant ou un enseignant ne voit jamais les actions admin (liste serveur).
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
    if (auth()->user()->can('tasks.view') || auth()->user()->can('archives.view')) {
        $actions[] = ['titre' => 'Centre de travail', 'detail' => 'File administrative unifiée', 'url' => route('admin.centre-travail.index')];
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
    $registry = app(\App\Support\Modules\ModuleRegistry::class);
    if ($registry->estActif('schedule') && auth()->user()->can('schedules.view') && \Illuminate\Support\Facades\Route::has('admin.schedule.index')) {
        $actions[] = ['titre' => 'Emplois du temps', 'detail' => 'Planning et créneaux', 'url' => route('admin.schedule.index')];
    }
    if ($registry->estActif('evaluation') && auth()->user()->can('notes_eval.view') && \Illuminate\Support\Facades\Route::has('admin.evaluation.tableau-bord')) {
        $actions[] = ['titre' => 'Notes & Évaluations', 'detail' => 'Examens et notes', 'url' => route('admin.evaluation.tableau-bord')];
    }
    if ($registry->estActif('evaluation') && auth()->user()->can('evaluations.view') && \Illuminate\Support\Facades\Route::has('admin.evaluations.examens.index')) {
        $actions[] = ['titre' => 'Examens', 'detail' => 'Organisation des examens et sessions', 'url' => route('admin.evaluations.examens.index')];
    }
    if ($registry->estActif('student-dossiers') && auth()->user()->can('student_dossiers.view') && \Illuminate\Support\Facades\Route::has('admin.student-dossiers.index')) {
        $actions[] = ['titre' => 'Dossiers étudiants', 'detail' => 'Pièces et validations', 'url' => route('admin.student-dossiers.index')];
    }
    if ($registry->estActif('attendance') && auth()->user()->can('attendance.view') && \Illuminate\Support\Facades\Route::has('admin.attendance.index')) {
        $actions[] = ['titre' => 'Présences et assiduité', 'detail' => 'Feuilles d\'appel', 'url' => route('admin.attendance.index')];
    }
    if ($registry->estActif('online-requests') && auth()->user()->can('demandes.view') && \Illuminate\Support\Facades\Route::has('admin.online-requests.index')) {
        $actions[] = ['titre' => 'Demandes administratives', 'detail' => 'Guichet unique', 'url' => route('admin.online-requests.index')];
    }
    if (auth()->user()->can('users.view') && \Illuminate\Support\Facades\Route::has('admin.administrateurs.index')) {
        $actions[] = ['titre' => 'Gestion des utilisateurs', 'detail' => 'Comptes et accès', 'url' => route('admin.administrateurs.index')];
    }
    if (auth()->user()->can('roles.view') && \Illuminate\Support\Facades\Route::has('admin.roles.index')) {
        $actions[] = ['titre' => 'Rôles et permissions', 'detail' => 'Matrice RBAC', 'url' => route('admin.roles.index')];
    }
} elseif (session('enseignant_auth_id')) {
    $actions[] = ['titre' => 'Mon tableau de bord', 'detail' => 'Espace enseignant', 'url' => route('enseignant.dashboard')];
    $actions[] = ['titre' => 'Mon emploi du temps', 'detail' => 'Planning et créneaux', 'url' => route('enseignant.emploi-du-temps.semaine')];

    if (\Illuminate\Support\Facades\Route::has('enseignant.notes.index')) {
        $actions[] = ['titre' => 'Mes notes & saisie', 'detail' => 'Épreuves et notes', 'url' => route('enseignant.notes.index')];
    }
    if (\Illuminate\Support\Facades\Route::has('enseignant.appel.selection')) {
        $actions[] = ['titre' => 'Feuille d\'appel / Présences', 'detail' => 'Émargement des séances', 'url' => route('enseignant.appel.selection')];
    }
    if (\Illuminate\Support\Facades\Route::has('enseignant.surveillances.index')) {
        $actions[] = ['titre' => 'Mes surveillances', 'detail' => 'Examens et créneaux', 'url' => route('enseignant.surveillances.index')];
    }
    if (\Illuminate\Support\Facades\Route::has('enseignant.ressources.index')) {
        $actions[] = ['titre' => 'Supports de cours', 'detail' => 'Ressources pédagogiques', 'url' => route('enseignant.ressources.index')];
    }
    if (\Illuminate\Support\Facades\Route::has('enseignant.documents.index')) {
        $actions[] = ['titre' => 'Mes documents', 'detail' => 'Documents administratifs', 'url' => route('enseignant.documents.index')];
    }
    if (\Illuminate\Support\Facades\Route::has('enseignant.demandes.index')) {
        $actions[] = ['titre' => 'Mes demandes', 'detail' => 'Demandes et attestations', 'url' => route('enseignant.demandes.index')];
    }
    if (\Illuminate\Support\Facades\Route::has('enseignant.reclamations.index')) {
        $actions[] = ['titre' => 'Mes réclamations', 'detail' => 'Suivi des réclamations', 'url' => route('enseignant.reclamations.index')];
    }
    if (\Illuminate\Support\Facades\Route::has('enseignant.profil.show')) {
        $actions[] = ['titre' => 'Mon profil', 'detail' => 'Compte et coordonnées', 'url' => route('enseignant.profil.show')];
    }
    if (\Illuminate\Support\Facades\Route::has('enseignant.recherche')) {
        $actions[] = ['titre' => 'Recherche dans mon espace', 'detail' => 'Étudiants, séances, épreuves', 'url' => route('enseignant.recherche')];
    }
} elseif (session('etudiant_auth_id')) {
    $actions[] = ['titre' => 'Mon tableau de bord', 'detail' => 'Espace étudiant', 'url' => route('etudiant.dashboard')];
    $actions[] = ['titre' => 'Mes notes', 'detail' => 'Résultats et moyennes', 'url' => route('etudiant.notes.index')];
    $actions[] = ['titre' => 'Mes documents', 'detail' => 'Attestations et pièces', 'url' => route('etudiant.documents.index')];
    $actions[] = ['titre' => 'Mon emploi du temps', 'detail' => 'Planning et cours', 'url' => route('etudiant.emploi-du-temps.semaine')];

    if (\Illuminate\Support\Facades\Route::has('etudiant.archives.show')) {
        $actions[] = ['titre' => 'Mon dossier', 'detail' => 'Dossier académique', 'url' => route('etudiant.archives.show')];
    }
    if (\Illuminate\Support\Facades\Route::has('etudiant.parcours')) {
        $actions[] = ['titre' => 'Mon parcours LMD', 'detail' => 'Progression et crédits', 'url' => route('etudiant.parcours')];
    }
    if (\Illuminate\Support\Facades\Route::has('etudiant.inscriptions.index')) {
        $actions[] = ['titre' => 'Mes inscriptions', 'detail' => 'Historique des inscriptions', 'url' => route('etudiant.inscriptions.index')];
    }
    if (\Illuminate\Support\Facades\Route::has('etudiant.profil.show')) {
        $actions[] = ['titre' => 'Mon profil', 'detail' => 'Coordonnées et compte', 'url' => route('etudiant.profil.show')];
    }
    if (\Illuminate\Support\Facades\Route::has('etudiant.notifications.index')) {
        $actions[] = ['titre' => 'Notifications', 'detail' => 'Alertes et messages', 'url' => route('etudiant.notifications.index')];
    }
    if (\Illuminate\Support\Facades\Route::has('etudiant.recherche')) {
        $actions[] = ['titre' => 'Recherche dans mon espace', 'detail' => 'Supports, séances, demandes', 'url' => route('etudiant.recherche')];
    }
}

$urlInstantanee = auth()->check() ? route('admin.recherche.instantanee') : null;
$urlContexte = auth()->check() ? route('admin.recherche.contexte') : null;
$urlPreferences = auth()->check() && \Illuminate\Support\Facades\Route::has('admin.preferences') ? route('admin.preferences') : null;
@endphp

@if($actions !== [])
    <div x-data="paletteCommandes(@js($actions), @js($urlInstantanee), @js($urlContexte))">
        <div x-show="ouverte" x-cloak
             class="fixed inset-0 z-[75] flex items-start justify-center p-3 pt-6 sm:p-4 sm:pt-[10vh] transition motion-reduce:transition-none"
             @keydown.escape.window="fermer()"
             role="dialog"
             aria-modal="true"
             aria-labelledby="palette-titre-accessible">
            <h2 id="palette-titre-accessible" class="sr-only">Palette de commandes</h2>

            <div class="absolute inset-0 bg-slate-900/50 backdrop-blur-xs" @click="fermer()" aria-hidden="true"></div>

            <div x-trap.noscroll="ouverte"
                 class="relative w-full max-w-lg overflow-hidden rounded-2xl bg-white shadow-xl motion-reduce:transform-none"><!-- taste-skill-ignore : ombre de dialogue (palette) -->

                {{-- Barre de saisie combobox --}}
                <div class="flex items-center gap-2 px-3 sm:px-4 border-b border-slate-100">
                    <svg class="h-5 w-5 shrink-0 text-slate-400" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z" /></svg>
                    <label for="palette-recherche" class="sr-only">Rechercher une commande ou une destination (préfixe &gt; pour les commandes, ? pour l'aide)</label>
                    <input id="palette-recherche"
                           type="text"
                           x-model="recherche"
                           x-ref="champ"
                           role="combobox"
                           aria-autocomplete="list"
                           :aria-expanded="ouverte.toString()"
                           aria-controls="palette-liste"
                           :aria-activedescendant="elements.length > 0 && curseur >= 0 && curseur < elements.length ? 'palette-opt-' + curseur : null"
                           aria-haspopup="listbox"
                           @keydown.arrow-down.prevent="suivant(1)"
                           @keydown.arrow-up.prevent="suivant(-1)"
                           @keydown.enter.prevent="aller()"
                           placeholder="Rechercher… (> commandes, ? aide)"
                           class="w-full py-3.5 text-base focus:outline-none min-h-[44px]">
                    <span class="hidden sm:inline-block admin-kbd" aria-hidden="true">esc</span>
                    <button type="button" @click="fermer()" aria-label="Fermer la palette"
                            class="flex sm:hidden h-10 w-10 shrink-0 items-center justify-center rounded-xl text-slate-400 hover:bg-slate-100 hover:text-slate-600 focus:outline-none">
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" /></svg>
                    </button>
                </div>

                {{-- Région live pour annonces d'accessibilité vocale --}}
                <div class="sr-only" aria-live="polite" aria-atomic="true" x-text="messageStatut"></div>

                {{-- Liste de résultats / options --}}
                <ul id="palette-liste"
                    role="listbox"
                    aria-label="Résultats et commandes"
                    class="max-h-[55vh] sm:max-h-72 overflow-y-auto overscroll-contain py-1.5"
                    data-lenis-prevent>
                    <template x-for="(element, index) in elements" :key="element.kind + ':' + (element.url || index)">
                        <li role="option"
                            :id="'palette-opt-' + index"
                            :aria-selected="(index === curseur).toString()">
                            <a :href="element.url"
                               @mouseenter="curseur = index"
                               :class="index === curseur ? 'bg-primary-50 text-primary-900' : 'text-slate-700'"
                               class="flex items-center justify-between gap-3 px-4 py-2.5 text-sm min-h-[44px] transition-colors rounded-lg mx-1">
                                <span class="font-bold truncate" x-text="element.titre"></span>
                                <span class="text-[13px] text-slate-500 shrink-0 text-right truncate max-w-[45%]" x-text="element.detail"></span>
                            </a>
                        </li>
                    </template>

                    <li x-show="aide" class="px-4 py-3 text-[13px] text-slate-600" role="status">
                        <p class="font-bold text-slate-900">Raccourcis clavier</p>
                        <ul class="mt-1.5 space-y-1">
                            <li><span class="admin-kbd">Ctrl/⌘ K</span> ouvrir / fermer la palette</li>
                            <li><span class="admin-kbd">&gt;</span> mode commandes (navigation uniquement)</li>
                            <li><span class="admin-kbd">?</span> cette aide</li>
                            <li><span class="admin-kbd">↑ ↓ Entrée</span> naviguer et ouvrir</li>
                            <li><span class="admin-kbd">Échap</span> fermer</li>
                            <li><span class="admin-kbd">/</span> recherche globale</li>
                        </ul>
                        @if($urlPreferences)
                            <a href="{{ $urlPreferences }}" class="mt-2 inline-block font-bold text-primary-800 underline underline-offset-2">Préférences d'affichage →</a>
                        @endif
                    </li>
                    <li x-show="chargement" class="px-4 py-3 text-sm text-slate-500" role="status">Recherche en cours…</li>
                    <li x-show="erreur" class="px-4 py-3 text-sm text-rose-600" role="status">Recherche indisponible, réessayez.</li>
                    <li x-show="!aide && !chargement && !erreur && elements.length === 0" class="px-4 py-3 text-sm text-slate-500" role="status">Aucun résultat.</li>
                </ul>

                <div class="px-4 py-2.5 bg-slate-50/80 border-t border-slate-100 text-[12px] text-slate-500 flex items-center justify-between">
                    <span>Ctrl/⌘+K palette · &gt; commandes · ? aide</span>
                    <span class="hidden sm:inline">↑↓ naviguer · ↵ ouvrir</span>
                </div>
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
                dernierElementActif: null,
                init() {
                    window.addEventListener('keydown', (e) => {
                        const saisie = ['INPUT', 'TEXTAREA', 'SELECT'].includes(document.activeElement?.tagName);
                        if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') {
                            e.preventDefault();
                            if (this.ouverte) {
                                this.fermer();
                            } else {
                                this.ouvrir();
                            }
                        } else if (e.key === 'Escape' && this.ouverte) {
                            this.fermer();
                        } else if (!saisie && !e.ctrlKey && !e.metaKey && !e.altKey && !this.ouverte) {
                            if (e.key === '/') {
                                const cible = document.getElementById('recherche-globale') || document.getElementById('recherche-portail');
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
                ouvrir() {
                    this.dernierElementActif = document.activeElement;
                    this.ouverte = true;
                    this.recherche = '';
                    this.curseur = 0;
                    if (urlContexte) {
                        this.chargerContexte();
                    } else {
                        this.elements = actions.map((a) => ({ kind: 'action', titre: a.titre, detail: a.detail, url: a.url }));
                    }
                    this.$nextTick(() => this.$refs.champ?.focus());
                },
                fermer() {
                    this.ouverte = false;
                    if (this.requete) { this.requete.abort(); this.requete = null; }
                    clearTimeout(this.minuteur);
                    this.$nextTick(() => {
                        if (this.dernierElementActif && typeof this.dernierElementActif.focus === 'function') {
                            this.dernierElementActif.focus();
                        }
                    });
                },
                get modeCommandes() {
                    return this.recherche.trimStart().startsWith('>');
                },
                get aide() {
                    return this.recherche.trim() === '?';
                },
                get messageStatut() {
                    if (this.aide) return 'Aide aux raccourcis affichée';
                    if (this.chargement) return 'Recherche en cours';
                    if (this.erreur) return 'Recherche indisponible';
                    if (this.elements.length === 0) return 'Aucun résultat';
                    return this.elements.length + (this.elements.length > 1 ? ' résultats disponibles' : ' résultat disponible');
                },
                actualiser() {
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

                    if (this.modeCommandes || !urlInstantanee || q.length < 2) {
                        this.chargement = false;
                        this.elements = versElements(commandes);
                        return;
                    }
                    this.chargement = true;
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
                    if (!urlContexte) return;
                    fetch(urlContexte, { headers: { 'Accept': 'application/json' } }).then((r) => r.ok ? r.json() : null).then((d) => {
                        if (!d || this.recherche.trim() !== '') return;
                        const elements = [];
                        (d.favoris || []).forEach((x) => elements.push({ kind: 'favori', titre: x.titre, detail: x.detail ? 'Favori · ' + x.detail : 'Favori', url: x.lien }));
                        (d.recents || []).forEach((x) => elements.push({ kind: 'recent', titre: x.titre, detail: 'Récent · ' + x.detail, url: x.lien }));
                        this.elements = elements.length > 0 ? elements : actions.map((a) => ({ kind: 'action', titre: a.titre, detail: a.detail, url: a.url }));
                    }).catch(() => {
                        this.elements = actions.map((a) => ({ kind: 'action', titre: a.titre, detail: a.detail, url: a.url }));
                    });
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
