@extends('layouts.app')

@section('title', 'Cockpit d\'affectation en masse')

@section('content')
<div class="mx-auto max-w-6xl px-4 sm:px-6 py-8" x-data="{
    modeCiblage: 'automatique',
    faculteChoisie: '{{ old('faculte_id', '') }}',
    programmeChoisi: '{{ old('programme_id', '') }}',
    niveauChoisi: '{{ old('niveau', '') }}',
    rechercheCandidats: '',
    resultatsRecherche: [],
    rechercheEnCours: false,
    async chercherCandidats() {
        const q = (this.rechercheCandidats || '').trim();
        if (q.length < 2) { this.resultatsRecherche = []; return; }
        this.rechercheEnCours = true;
        try {
            const reponse = await fetch('{{ route('admin.affectations.masse.recherche') }}?q=' + encodeURIComponent(q), {headers: {'Accept': 'application/json'}});
            const donnees = await reponse.json();
            this.resultatsRecherche = donnees.resultats || [];
        } catch (e) {
            this.resultatsRecherche = [];
        }
        this.rechercheEnCours = false;
    },
    ajouterId(id) {
        const zone = document.getElementById('etudiant_ids_text');
        const lignes = (zone.value || '').split(/\s+/).filter(Boolean);
        if (! lignes.includes(String(id))) {
            lignes.push(String(id));
            zone.value = lignes.join('\n');
        }
    }
}">
    {{-- En-tête de page --}}
    <x-page-header 
        titre="Cockpit d’affectation en masse" 
        description="Moteur académique intelligent d'équilibrage par niveau — Simulation sans écriture puis confirmation transactionnelle."
        :fil="['Gestion académique' => '#', 'Affectations' => '#', 'Masse par niveau' => '#']">
        <x-slot:actions>
            <a href="{{ route('admin.affectations.propositions.index') }}" class="inline-flex items-center gap-1.5 rounded-xl border border-indigo-200 bg-indigo-50 px-3.5 py-2 text-xs font-semibold text-indigo-700 shadow-sm transition hover:bg-indigo-100 dark:border-indigo-800 dark:bg-indigo-950/40 dark:text-indigo-300">
                <svg class="h-4 w-4 text-indigo-600 dark:text-indigo-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                </svg>
                Propositions semi-auto
                @if(($propositionsCount ?? 0) > 0)
                    <span class="rounded-full bg-indigo-600 px-1.5 py-0.5 text-[10px] font-black text-white">{{ $propositionsCount }}</span>
                @endif
            </a>
            <a href="{{ route('admin.affectations.auto') }}" class="inline-flex items-center gap-1.5 rounded-xl border border-slate-200 bg-white px-3.5 py-2 text-xs font-semibold text-slate-700 shadow-sm transition hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200 dark:hover:bg-slate-700">
                <svg class="h-4 w-4 text-slate-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16m-7 6h7" />
                </svg>
                Mode multi-groupes classique
            </a>
            <a href="{{ route('admin.affectations.automatique.parametres') }}" class="inline-flex items-center gap-1.5 rounded-xl border border-slate-200 bg-white px-3.5 py-2 text-xs font-semibold text-slate-700 shadow-sm transition hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200 dark:hover:bg-slate-700">
                <svg class="h-4 w-4 text-slate-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6V4m0 2a2 2 0 100 4m0-4a2 2 0 110 4m-6 8a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4m6 6v10m6-2a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4" />
                </svg>
                Simulateur avancé multi-critères
            </a>
        </x-slot:actions>
    </x-page-header>

    @if(($propositionsCount ?? 0) > 0)
        <div class="mt-4 flex items-center justify-between rounded-2xl border border-indigo-200 bg-indigo-50/80 p-4 dark:border-indigo-900/60 dark:bg-indigo-950/30">
            <div class="flex items-center gap-3">
                <span class="flex h-8 w-8 items-center justify-center rounded-xl bg-indigo-600 text-sm font-bold text-white shadow">
                    🧠
                </span>
                <div>
                    <h3 class="text-xs font-bold text-indigo-950 dark:text-indigo-200">
                        {{ $propositionsCount }} proposition(s) d'affectation en attente d'approbation
                    </h3>
                    <p class="text-[11px] text-indigo-800/80 dark:text-indigo-300/80">
                        Des affectations semi-automatiques avec scoring attendent votre vérification ou confirmation.
                    </p>
                </div>
            </div>
            <a href="{{ route('admin.affectations.propositions.index') }}" class="inline-flex items-center gap-1.5 rounded-xl bg-indigo-600 px-3.5 py-2 text-xs font-bold text-white shadow-sm transition hover:bg-indigo-700">
                Examiner les propositions →
            </a>
        </div>
    @endif

    {{-- Rapport de la dernière opération de masse (session flash, §38) --}}
    @if(session('rapport_masse'))
        @php $rapportMasse = session('rapport_masse'); @endphp
        <div class="mt-4 rounded-2xl border border-emerald-200 bg-emerald-50/80 p-4 dark:border-emerald-900/60 dark:bg-emerald-950/30" role="status">
            <div class="flex items-center gap-3">
                <span class="flex h-8 w-8 items-center justify-center rounded-xl bg-emerald-600 text-sm font-bold text-white shadow">✓</span>
                <div>
                    <h3 class="text-xs font-bold text-emerald-950 dark:text-emerald-200">
                        Opération terminée : {{ $rapportMasse['affectes'] }} affecté(s) sur {{ $rapportMasse['total'] }} demandé(s)
                        @if(($rapportMasse['ignores'] ?? 0) > 0)
                            — {{ $rapportMasse['ignores'] }} ignoré(s) ou reporté(s)
                        @endif
                    </h3>
                    <p class="text-[11px] text-emerald-800/80 dark:text-emerald-300/80">
                        Chaque affectation a été revérifiée sous verrou. L'historique est conservé par étudiant.
                        <a href="{{ route('admin.affectations.propositions.index') }}" class="font-bold underline">Voir les propositions</a>
                    </p>
                </div>
            </div>
            @if(!empty($rapportMasse['erreurs']))
                <details class="mt-2 text-[11px] text-emerald-900 dark:text-emerald-200">
                    <summary class="cursor-pointer font-bold">Détail des reports ({{ count($rapportMasse['erreurs']) }}{{ ($rapportMasse['erreurs_supplementaires'] ?? 0) > 0 ? '+'.$rapportMasse['erreurs_supplementaires'].' autres' : '' }})</summary>
                    <ul class="mt-1 list-disc list-inside space-y-0.5">
                        @foreach($rapportMasse['erreurs'] as $idEtudiant => $message)
                            <li><span class="font-mono">#{{ $idEtudiant }}</span> — {{ $message }}</li>
                        @endforeach
                    </ul>
                </details>
            @endif
        </div>
    @endif

    {{-- Stepper visuel à 4 étapes (LOOP 11) --}}
    <div class="mt-6 rounded-2xl border border-slate-200/80 bg-white p-4 shadow-sm dark:border-slate-800 dark:bg-slate-900">
        <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
            <div class="flex items-center gap-3 rounded-xl bg-primary-50/80 px-3.5 py-2.5 text-primary-900 dark:bg-primary-950/50 dark:text-primary-300 border border-primary-200 dark:border-primary-800">
                <span class="flex h-7 w-7 items-center justify-center rounded-lg bg-primary-600 text-xs font-bold text-white shadow">1</span>
                <div class="min-w-0">
                    <div class="text-xs font-bold uppercase tracking-wider">Étape 1</div>
                    <div class="truncate text-xs font-medium">Sélection & Filtres</div>
                </div>
            </div>
            <div class="flex items-center gap-3 rounded-xl bg-slate-50/60 px-3.5 py-2.5 text-slate-500 dark:bg-slate-800/40 dark:text-slate-400">
                <span class="flex h-7 w-7 items-center justify-center rounded-lg bg-slate-200 text-xs font-bold text-slate-600 dark:bg-slate-700 dark:text-slate-300">2</span>
                <div class="min-w-0">
                    <div class="text-xs font-bold uppercase tracking-wider">Étape 2</div>
                    <div class="truncate text-xs font-medium">Analyse & Anomalies</div>
                </div>
            </div>
            <div class="flex items-center gap-3 rounded-xl bg-slate-50/60 px-3.5 py-2.5 text-slate-500 dark:bg-slate-800/40 dark:text-slate-400">
                <span class="flex h-7 w-7 items-center justify-center rounded-lg bg-slate-200 text-xs font-bold text-slate-600 dark:bg-slate-700 dark:text-slate-300">3</span>
                <div class="min-w-0">
                    <div class="text-xs font-bold uppercase tracking-wider">Étape 3</div>
                    <div class="truncate text-xs font-medium">Équilibrage & Aperçu</div>
                </div>
            </div>
            <div class="flex items-center gap-3 rounded-xl bg-slate-50/60 px-3.5 py-2.5 text-slate-500 dark:bg-slate-800/40 dark:text-slate-400">
                <span class="flex h-7 w-7 items-center justify-center rounded-lg bg-slate-200 text-xs font-bold text-slate-600 dark:bg-slate-700 dark:text-slate-300">4</span>
                <div class="min-w-0">
                    <div class="text-xs font-bold uppercase tracking-wider">Étape 4</div>
                    <div class="truncate text-xs font-medium">Confirmation finale</div>
                </div>
            </div>
        </div>
    </div>

    {{-- Indicateurs rapides --}}
    <div class="mt-6 grid grid-cols-1 gap-4 sm:grid-cols-3">
        <div class="rounded-2xl border border-slate-200/80 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900">
            <div class="flex items-center justify-between">
                <span class="text-xs font-bold uppercase tracking-wider text-slate-500">Étudiants sans affectation</span>
                <span class="rounded-lg bg-amber-50 p-2 text-amber-600 dark:bg-amber-950/50 dark:text-amber-400">
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z" />
                    </svg>
                </span>
            </div>
            <div class="mt-3 flex items-baseline gap-2">
                <span class="text-3xl font-extrabold tracking-tight text-slate-900 dark:text-white tabular-nums">{{ number_format($nonAffectesCount, 0, ',', ' ') }}</span>
                <span class="text-xs text-slate-500">dossiers validés</span>
            </div>
            <p class="mt-2 text-xs text-slate-400">Prêts à être affectés pour l'année universitaire courante.</p>
        </div>

        <div class="rounded-2xl border border-slate-200/80 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900">
            <div class="flex items-center justify-between">
                <span class="text-xs font-bold uppercase tracking-wider text-slate-500">Groupes d'enseignement actifs</span>
                <span class="rounded-lg bg-emerald-50 p-2 text-emerald-600 dark:bg-emerald-950/50 dark:text-emerald-400">
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10" />
                    </svg>
                </span>
            </div>
            <div class="mt-3 flex items-baseline gap-2">
                <span class="text-3xl font-extrabold tracking-tight text-slate-900 dark:text-white tabular-nums">{{ $groupes->count() }}</span>
                <span class="text-xs text-slate-500">groupes disponibles</span>
            </div>
            <p class="mt-2 text-xs text-slate-400">Capacité totale déclarée : {{ number_format($groupes->sum('capacite'), 0, ',', ' ') }} places.</p>
        </div>

        <div class="rounded-2xl border border-slate-200/80 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900">
            <div class="flex items-center justify-between">
                <span class="text-xs font-bold uppercase tracking-wider text-slate-500">Garanties de sécurité</span>
                <span class="rounded-lg bg-blue-50 p-2 text-blue-600 dark:bg-blue-950/50 dark:text-blue-400">
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z" />
                    </svg>
                </span>
            </div>
            <div class="mt-2 space-y-1 text-xs text-slate-600 dark:text-slate-300">
                <div class="flex items-center gap-1.5"><span class="text-emerald-500 font-bold">✓</span> Règle de niveau stricte & étanche</div>
                <div class="flex items-center gap-1.5"><span class="text-emerald-500 font-bold">✓</span> Verrouillage anti-concurrence DB</div>
                <div class="flex items-center gap-1.5"><span class="text-emerald-500 font-bold">✓</span> Idempotence & traçabilité d'audit</div>
            </div>
        </div>
    </div>

    {{-- Formulaire principal de paramétrage --}}
    <form method="POST" action="{{ route('admin.affectations.masse.analyser') }}" class="mt-6 rounded-2xl border border-slate-200/80 bg-white p-6 shadow-sm dark:border-slate-800 dark:bg-slate-900 space-y-6">
        @csrf

        <div>
            <h2 class="text-base font-bold text-slate-900 dark:text-white flex items-center gap-2">
                <span class="flex h-6 w-6 items-center justify-center rounded-full bg-primary-100 text-xs font-bold text-primary-800 dark:bg-primary-900/50 dark:text-primary-300">1</span>
                Critères académiques de répartition
            </h2>
            <p class="mt-1 text-xs text-slate-500">Le moteur n'affecte les étudiants qu'aux groupes correspondant exactement à leur niveau et leur formation.</p>
        </div>

        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
            {{-- Année universitaire --}}
            <div>
                <label class="block text-xs font-bold uppercase tracking-wider text-slate-600 dark:text-slate-400 mb-1" for="annee_universitaire_id">
                    Année universitaire <span class="text-rose-500">*</span>
                </label>
                <select id="annee_universitaire_id" name="annee_universitaire_id" class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm text-slate-900 shadow-sm focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500/20 dark:border-slate-700 dark:bg-slate-800 dark:text-white" required>
                    @foreach($annees as $annee)
                        <option value="{{ $annee->id }}" @selected((int) old('annee_universitaire_id', $anneeDefaut ?? 0) === $annee->id)>
                            {{ $annee->libelle }} @if($annee->active)(Courante)@endif
                        </option>
                    @endforeach
                </select>
            </div>

            {{-- Faculté --}}
            <div>
                <label class="block text-xs font-bold uppercase tracking-wider text-slate-600 dark:text-slate-400 mb-1" for="faculte_id">
                    Faculté / Établissement
                </label>
                <select id="faculte_id" name="faculte_id" x-model="faculteChoisie" class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm text-slate-900 shadow-sm focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500/20 dark:border-slate-700 dark:bg-slate-800 dark:text-white">
                    <option value="">Toutes les facultés accessibles</option>
                    @foreach($facultes as $fac)
                        <option value="{{ $fac->id }}" @selected(old('faculte_id') == $fac->id)>{{ $fac->code }} — {{ $fac->nom }}</option>
                    @endforeach
                </select>
            </div>

            {{-- Programme / Parcours --}}
            <div>
                <label class="block text-xs font-bold uppercase tracking-wider text-slate-600 dark:text-slate-400 mb-1" for="programme_id">
                    Parcours / Programme LMD
                </label>
                <select id="programme_id" name="programme_id" x-model="programmeChoisi" class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm text-slate-900 shadow-sm focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500/20 dark:border-slate-700 dark:bg-slate-800 dark:text-white">
                    <option value="">Tous les parcours</option>
                    @foreach($programmes as $prog)
                        <option value="{{ $prog->id }}" @selected(old('programme_id') == $prog->id)>{{ $prog->code }} — {{ $prog->nom }}</option>
                    @endforeach
                </select>
            </div>

            {{-- Niveau académique --}}
            <div>
                <label class="block text-xs font-bold uppercase tracking-wider text-slate-600 dark:text-slate-400 mb-1" for="niveau">
                    Niveau d'études LMD
                </label>
                <select id="niveau" name="niveau" x-model="niveauChoisi" class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm text-slate-900 shadow-sm focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500/20 dark:border-slate-700 dark:bg-slate-800 dark:text-white">
                    <option value="">Tous les niveaux</option>
                    @foreach($niveaux as $niv)
                        <option value="{{ $niv->code ?? $niv->nom }}" @selected(old('niveau') == ($niv->code ?? $niv->nom))>
                            {{ $niv->nom ?? $niv->code }}
                        </option>
                    @endforeach
                </select>
            </div>
        </div>

        {{-- Mode de sélection des étudiants --}}
        <div class="border-t border-slate-200/80 pt-5 dark:border-slate-800">
            <h2 class="text-base font-bold text-slate-900 dark:text-white flex items-center gap-2">
                <span class="flex h-6 w-6 items-center justify-center rounded-full bg-primary-100 text-xs font-bold text-primary-800 dark:bg-primary-900/50 dark:text-primary-300">2</span>
                Population cible d'étudiants
            </h2>

            <div class="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-2">
                <label class="flex cursor-pointer items-start gap-3 rounded-xl border p-4 transition"
                       :class="modeCiblage === 'automatique' ? 'border-primary-500 bg-primary-50/30 dark:border-primary-600 dark:bg-primary-950/20' : 'border-slate-200 dark:border-slate-700 hover:bg-slate-50/50 dark:hover:bg-slate-800/50'">
                    <input type="radio" name="mode_ciblage" value="automatique" x-model="modeCiblage" class="mt-1 text-primary-600 focus:ring-primary-500" checked />
                    <div>
                        <span class="block text-sm font-bold text-slate-900 dark:text-white">Ciblage automatique (Recommandé)</span>
                        <span class="block text-xs text-slate-500 mt-0.5">Analyse automatique de tous les étudiants validés sans affectation active répondant aux filtres ci-dessus.</span>
                    </div>
                </label>

                <label class="flex cursor-pointer items-start gap-3 rounded-xl border p-4 transition"
                       :class="modeCiblage === 'manuel' ? 'border-primary-500 bg-primary-50/30 dark:border-primary-600 dark:bg-primary-950/20' : 'border-slate-200 dark:border-slate-700 hover:bg-slate-50/50 dark:hover:bg-slate-800/50'">
                    <input type="radio" name="mode_ciblage" value="manuel" x-model="modeCiblage" class="mt-1 text-primary-600 focus:ring-primary-500" />
                    <div>
                        <span class="block text-sm font-bold text-slate-900 dark:text-white">Liste personnalisée d'IDs</span>
                        <span class="block text-xs text-slate-500 mt-0.5">Coller manuellement une liste d'identifiants d'étudiants spécifiques pour une cohorte ciblée.</span>
                    </div>
                </label>
            </div>

            <div class="mt-3 flex items-center gap-2">
                <input type="checkbox" id="uniquement_non_affectes" name="uniquement_non_affectes" value="1" class="rounded border-slate-300 text-primary-600 focus:ring-primary-500" checked />
                <label for="uniquement_non_affectes" class="text-xs font-medium text-slate-700 dark:text-slate-300">
                    Ignorer les étudiants ayant déjà un groupe actif (évite les transferts involontaires)
                </label>
            </div>

            {{-- Zone de texte conditionnelle pour saisie manuelle --}}
            <div class="mt-4" x-show="modeCiblage === 'manuel'" x-cloak x-transition>
                <label class="block text-xs font-bold uppercase tracking-wider text-slate-600 dark:text-slate-400 mb-1" for="recherche_candidats">
                    Rechercher un étudiant (CIN, matricule, nom, email, téléphone)
                </label>
                <div class="flex gap-2">
                    <input type="text" id="recherche_candidats" x-model="rechercheCandidats" @input.debounce.300ms="chercherCandidats()" class="flex-1 rounded-xl border border-slate-200 px-3 py-2 text-sm text-slate-900 shadow-sm focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500/20 dark:border-slate-700 dark:bg-slate-800 dark:text-white" placeholder="Ex. 12345678, BEN ALI, INS-2026-00042…" autocomplete="off" />
                    <span x-show="rechercheEnCours" class="self-center text-xs text-slate-400">Recherche…</span>
                </div>
                <div x-show="resultatsRecherche.length > 0" class="mt-2 max-h-48 overflow-y-auto rounded-xl border border-slate-200 dark:border-slate-700">
                    <template x-for="r in resultatsRecherche" :key="r.id">
                        <div class="flex items-center justify-between gap-2 border-b border-slate-100 px-3 py-2 text-xs last:border-0 dark:border-slate-800">
                            <div class="min-w-0">
                                <span class="font-bold text-slate-900 dark:text-white" x-text="r.prenom + ' ' + r.nom"></span>
                                <span class="ml-1 font-mono text-slate-500" x-text="'#' + r.id"></span>
                                <div class="text-[11px] text-slate-500">
                                    <span x-text="'CIN ' + r.cin_masquee + ' • ' + (r.num_inscription || '—')"></span>
                                    <span x-text="' • ' + (r.niveau || '—') + ' / ' + (r.programme || 'Tronc commun')"></span>
                                </div>
                            </div>
                            <button type="button" @click="ajouterId(r.id)" class="shrink-0 rounded-lg bg-primary-50 px-2.5 py-1 text-[11px] font-bold text-primary-700 hover:bg-primary-100 dark:bg-primary-950/50 dark:text-primary-300">＋ Ajouter</button>
                        </div>
                    </template>
                </div>
                <p class="mt-1 text-[11px] text-slate-400">Tous les résultats sont affichés (les homonymes aussi) : ajoutez explicitement chaque ID. Périmètre faculté appliqué.</p>

                <label class="mt-3 block text-xs font-bold uppercase tracking-wider text-slate-600 dark:text-slate-400 mb-1" for="etudiant_ids_text">
                    Identifiants étudiants (un ID par ligne, jusqu'à 5 000 étudiants)
                </label>
                <textarea id="etudiant_ids_text" name="etudiant_ids_text" rows="5" class="w-full rounded-xl border border-slate-200 font-mono text-xs p-3 text-slate-900 shadow-sm focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500/20 dark:border-slate-700 dark:bg-slate-800 dark:text-white" placeholder="101&#10;102&#10;103">{{ old('etudiant_ids_text') }}</textarea>
                <p class="mt-1 text-[11px] text-slate-400">Les IDs collés seront strictement vérifiés dans le périmètre d'accès de votre faculté.</p>
            </div>
        </div>

        {{-- Actions --}}
        <div class="flex items-center justify-between border-t border-slate-200/80 pt-5 dark:border-slate-800">
            <span class="text-xs text-slate-400">
                🔒 <strong>Règle de sécurité :</strong> Aucune écriture en base n'a lieu lors de cette étape d'analyse.
            </span>
            <button type="submit" class="inline-flex items-center gap-2 rounded-xl bg-primary-600 px-5 py-2.5 text-sm font-bold text-white shadow-sm transition hover:bg-primary-700 focus:outline-none focus:ring-2 focus:ring-primary-500/50">
                <span>Lancer l'analyse et la simulation</span>
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3" />
                </svg>
            </button>
        </div>
    </form>
</div>
@endsection
