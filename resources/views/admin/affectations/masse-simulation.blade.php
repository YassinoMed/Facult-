@extends('layouts.app')

@section('title', 'Simulation & Équilibrage — Affectation en masse')

@section('content')
<div class="mx-auto max-w-7xl px-4 sm:px-6 py-8" x-data="masseSimulationApp({{ Js::from($analyse['propositions']) }}, {{ Js::from($analyse['groups_map'] ?? []) }})">
    {{-- En-tête de page --}}
    <x-page-header 
        titre="Simulation & Équilibrage par niveau" 
        description="Analyse prédictive sans écriture en base. Validez l'équilibrage des groupes et ajustez les propositions avant confirmation transactionnelle."
        :fil="['Gestion académique' => '#', 'Affectations' => route('admin.affectations.masse'), 'Simulation' => '#']">
        <x-slot:actions>
            <a href="{{ route('admin.affectations.masse') }}" class="inline-flex items-center gap-1.5 rounded-xl border border-slate-200 bg-white px-3.5 py-2 text-xs font-semibold text-slate-700 shadow-sm transition hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200 dark:hover:bg-slate-700">
                <svg class="h-4 w-4 text-slate-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
                </svg>
                Modifier les filtres
            </a>
            @can('data.export')
                <form method="POST" action="{{ route('admin.affectations.masse.exporter') }}" class="inline">
                    @csrf
                    <input type="hidden" name="annee_universitaire_id" value="{{ $criteres['annee_universitaire_id'] }}">
                    <input type="hidden" name="faculte_id" value="{{ $criteres['faculte_id'] }}">
                    <input type="hidden" name="programme_id" value="{{ $criteres['programme_id'] }}">
                    <input type="hidden" name="niveau" value="{{ $criteres['niveau'] }}">
                    <input type="hidden" name="uniquement_non_affectes" value="{{ ! empty($criteres['uniquement_non_affectes']) ? 1 : 0 }}">
                    <input type="hidden" name="etudiant_ids_text" value="{{ $criteres['etudiant_ids_text'] ?? '' }}">
                    <button type="submit" title="Rejoue l'analyse avec les mêmes critères et télécharge propositions + blocages (données au moment de l'export)" class="inline-flex items-center gap-1.5 rounded-xl border border-slate-200 bg-white px-3.5 py-2 text-xs font-semibold text-slate-700 shadow-sm transition hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200 dark:hover:bg-slate-700">
                        <svg class="h-4 w-4 text-slate-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
                        </svg>
                        Exporter CSV
                    </button>
                </form>
            @endcan
            <button type="button" @click="ouvrirPropositions()" :disabled="nbSelectionnes === 0" class="inline-flex items-center gap-2 rounded-xl border border-indigo-200 bg-indigo-50 px-3.5 py-2 text-xs font-bold text-indigo-700 shadow-sm transition hover:bg-indigo-100 disabled:opacity-50 disabled:cursor-not-allowed dark:border-indigo-800 dark:bg-indigo-950/40 dark:text-indigo-300">
                <svg class="h-4 w-4 text-indigo-600 dark:text-indigo-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                </svg>
                <span>Générer propositions semi-auto</span>
                <span class="rounded-full bg-indigo-200 px-1.5 py-0.5 text-[10px] font-black text-indigo-800 dark:bg-indigo-800 dark:text-indigo-200" x-text="nbSelectionnes"></span>
            </button>
            <button type="button" @click="ouvrirConfirmation()" :disabled="nbSelectionnes === 0" class="inline-flex items-center gap-2 rounded-xl bg-primary-600 px-4 py-2 text-xs font-bold text-white shadow-sm transition hover:bg-primary-700 disabled:opacity-50 disabled:cursor-not-allowed">
                <span>Affecter directement</span>
                <span class="rounded-full bg-primary-500 px-1.5 py-0.5 text-[10px] font-black" x-text="nbSelectionnes"></span>
                <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3" />
                </svg>
            </button>
        </x-slot:actions>
    </x-page-header>

    {{-- Stepper visuel à 4 étapes (LOOP 11) --}}
    <div class="mt-6 rounded-2xl border border-slate-200/80 bg-white p-4 shadow-sm dark:border-slate-800 dark:bg-slate-900">
        <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
            <div class="flex items-center gap-3 rounded-xl bg-slate-50/60 px-3.5 py-2.5 text-slate-600 dark:bg-slate-800/40 dark:text-slate-400">
                <span class="flex h-7 w-7 items-center justify-center rounded-lg bg-emerald-600 text-xs font-bold text-white shadow">✓</span>
                <div class="min-w-0">
                    <div class="text-xs font-bold uppercase tracking-wider">Étape 1</div>
                    <div class="truncate text-xs font-medium">Sélection & Filtres</div>
                </div>
            </div>
            <div class="flex items-center gap-3 rounded-xl bg-primary-50/80 px-3.5 py-2.5 text-primary-900 dark:bg-primary-950/50 dark:text-primary-300 border border-primary-200 dark:border-primary-800">
                <span class="flex h-7 w-7 items-center justify-center rounded-lg bg-primary-600 text-xs font-bold text-white shadow">2</span>
                <div class="min-w-0">
                    <div class="text-xs font-bold uppercase tracking-wider">Étape 2</div>
                    <div class="truncate text-xs font-medium">Analyse & Anomalies</div>
                </div>
            </div>
            <div class="flex items-center gap-3 rounded-xl bg-primary-50/80 px-3.5 py-2.5 text-primary-900 dark:bg-primary-950/50 dark:text-primary-300 border border-primary-200 dark:border-primary-800">
                <span class="flex h-7 w-7 items-center justify-center rounded-lg bg-primary-600 text-xs font-bold text-white shadow">3</span>
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

    {{-- Rappel des filtres analysés --}}
    <div class="mt-4 flex flex-wrap items-center gap-2 text-xs text-slate-600 dark:text-slate-400">
        <span class="font-bold text-slate-700 dark:text-slate-300">Périmètre analysé :</span>
        <span class="inline-flex items-center gap-1 rounded-lg bg-slate-100 px-2.5 py-1 text-slate-700 dark:bg-slate-800 dark:text-slate-300 font-medium">
            Année : {{ $annee->libelle }}
        </span>
        @if(!empty($criteres['niveau']))
            <span class="inline-flex items-center gap-1 rounded-lg bg-primary-50 px-2.5 py-1 text-primary-700 dark:bg-primary-950/50 dark:text-primary-300 font-medium">
                Niveau : {{ $criteres['niveau'] }}
            </span>
        @endif
        @if(!empty($criteres['programme_id']))
            <span class="inline-flex items-center gap-1 rounded-lg bg-slate-100 px-2.5 py-1 text-slate-700 dark:bg-slate-800 dark:text-slate-300 font-medium">
                Programme #{{ $criteres['programme_id'] }}
            </span>
        @endif
        @if(!empty($criteres['faculte_id']))
            <span class="inline-flex items-center gap-1 rounded-lg bg-slate-100 px-2.5 py-1 text-slate-700 dark:bg-slate-800 dark:text-slate-300 font-medium">
                Faculté #{{ $criteres['faculte_id'] }}
            </span>
        @endif
        <span class="inline-flex items-center gap-1 rounded-lg bg-slate-100 px-2.5 py-1 text-slate-700 dark:bg-slate-800 dark:text-slate-300 font-medium">
            {{ !empty($criteres['uniquement_non_affectes']) ? 'Uniquement sans affectation active' : 'Tous dossiers' }}
        </span>
    </div>

    {{-- Indicateurs clés (LOOP 5 & 6) --}}
    <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <div class="rounded-2xl border border-slate-200/80 bg-white p-4 shadow-sm dark:border-slate-800 dark:bg-slate-900">
            <p class="flex items-center gap-1.5 text-[11px] font-bold uppercase tracking-wider text-slate-400">
                <span class="h-2 w-2 rounded-full bg-slate-500" aria-hidden="true"></span>Étudiants analysés
            </p>
            <p class="mt-1 text-3xl font-black tabular-nums text-slate-900 dark:text-white">{{ number_format($analyse['total_students'], 0, ',', ' ') }}</p>
            <p class="text-[11px] text-slate-500 mt-1">Dossiers répondant aux filtres</p>
        </div>

        <div class="rounded-2xl border border-slate-200/80 bg-white p-4 shadow-sm dark:border-slate-800 dark:bg-slate-900">
            <p class="flex items-center gap-1.5 text-[11px] font-bold uppercase tracking-wider text-emerald-600 dark:text-emerald-400">
                <span class="h-2 w-2 rounded-full bg-emerald-600" aria-hidden="true"></span>Affectables éligibles
            </p>
            <p class="mt-1 text-3xl font-black tabular-nums text-emerald-600 dark:text-emerald-400">{{ number_format($analyse['assigned_count'], 0, ',', ' ') }}</p>
            <p class="text-[11px] text-slate-500 mt-1">Propositions équilibrées générées</p>
        </div>

        <div class="rounded-2xl border border-slate-200/80 bg-white p-4 shadow-sm dark:border-slate-800 dark:bg-slate-900">
            <p class="flex items-center gap-1.5 text-[11px] font-bold uppercase tracking-wider text-rose-600 dark:text-rose-400">
                <span class="h-2 w-2 rounded-full bg-rose-600" aria-hidden="true"></span>Anomalies bloquantes
            </p>
            @php $bloquants = array_filter($analyse['anomalies'], fn($a) => $a['type'] === 'bloquant'); @endphp
            <p class="mt-1 text-3xl font-black tabular-nums text-rose-600 dark:text-rose-400">{{ count($bloquants) }}</p>
            <p class="text-[11px] text-slate-500 mt-1">Non affectables (motifs détaillés ci-dessous)</p>
        </div>

        <div class="rounded-2xl border border-slate-200/80 bg-white p-4 shadow-sm dark:border-slate-800 dark:bg-slate-900">
            <p class="flex items-center gap-1.5 text-[11px] font-bold uppercase tracking-wider text-amber-500">
                <span class="h-2 w-2 rounded-full bg-amber-500" aria-hidden="true"></span>Avertissements
            </p>
            <p class="mt-1 text-3xl font-black tabular-nums text-amber-500">{{ $analyse['warnings_count'] }}</p>
            <p class="text-[11px] text-slate-500 mt-1">Capacité tendue ou transferts</p>
        </div>
    </div>

    {{-- SECTION : RAPPORT DES ANOMALIES (LOOP 6) --}}
    @if(!empty($analyse['anomalies']))
        <div class="mt-6 rounded-2xl border border-rose-200 bg-rose-50/50 p-5 dark:border-rose-900/60 dark:bg-rose-950/20"
             x-data="centreExceptionsApp({{ Js::from($analyse['anomalies']) }})">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div class="flex items-center gap-2.5">
                    <span class="flex h-7 w-7 items-center justify-center rounded-lg bg-rose-600 text-white font-bold text-xs">!</span>
                    <div>
                        <h2 class="text-sm font-bold text-rose-950 dark:text-rose-200">
                            Centre d'exceptions académiques ({{ count($analyse['anomalies']) }})
                        </h2>
                        <p class="text-xs text-rose-800/80 dark:text-rose-300/80">
                            Étudiants non affectables nécessitant une régularisation de scolarité avant réaffectation.
                        </p>
                    </div>
                </div>
                <div class="flex items-center gap-2">
                    <input type="text" x-model="recherche" placeholder="Filtrer étudiant, code..." class="rounded-xl border border-rose-200 bg-white px-3 py-1 text-xs text-slate-900 shadow-sm focus:border-rose-500 focus:outline-none dark:border-rose-900/40 dark:bg-slate-900 dark:text-white w-48" />
                    <button type="button" @click="replie = !replie" class="text-xs font-semibold text-rose-700 hover:text-rose-900 dark:text-rose-300">
                        <span x-text="replie ? 'Afficher le détail' : 'Masquer'"></span>
                    </button>
                </div>
            </div>

            @if(!empty($analyse['categories']['Inscription']))
                <div class="mt-3 flex flex-col gap-2 rounded-xl bg-white/90 p-3 ring-1 ring-rose-200 sm:flex-row sm:items-center sm:justify-between dark:bg-slate-900/90 dark:ring-rose-900/60" x-show="!replie">
                    <div class="flex items-center gap-2 text-xs text-rose-900 dark:text-rose-200">
                        <span class="inline-flex h-2 w-2 rounded-full bg-rose-500 animate-pulse"></span>
                        <span><strong>{{ $analyse['categories']['Inscription'] }}</strong> étudiant(s) sans inscription active pour <strong>{{ $annee->libelle }}</strong>.</span>
                    </div>
                    <form method="POST" action="{{ route('admin.affectations.masse.regulariser-inscriptions') }}" onsubmit="return confirm('Régulariser automatiquement les inscriptions académiques de ces étudiants pour {{ $annee->libelle }} ?');">
                        @csrf
                        <input type="hidden" name="annee_universitaire_id" value="{{ $annee->id }}">
                        @if(!empty($criteres['faculte_id']))
                            <input type="hidden" name="faculte_id" value="{{ $criteres['faculte_id'] }}">
                        @endif
                        <button type="submit" class="inline-flex items-center gap-1.5 rounded-lg bg-rose-700 px-3 py-1.5 text-xs font-semibold text-white shadow-sm transition hover:bg-rose-800 dark:bg-rose-600 dark:hover:bg-rose-500">
                            <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z" />
                            </svg>
                            <span>Régulariser tout pour {{ $annee->libelle }}</span>
                        </button>
                    </form>
                </div>
            @endif

            {{-- Filtres interactifs par catégorie d'action (chips) --}}
            <div class="mt-3 flex flex-wrap items-center gap-1.5" x-show="!replie">
                <button type="button" @click="categorieActive = 'Toutes'"
                        class="inline-flex items-center gap-1 rounded-lg px-2.5 py-1 text-[11px] font-bold transition"
                        :class="categorieActive === 'Toutes' ? 'bg-rose-700 text-white shadow' : 'bg-white text-rose-800 ring-1 ring-rose-200 hover:bg-rose-100/50 dark:bg-slate-900 dark:text-rose-300 dark:ring-rose-900/60'">
                    Toutes ({{ count($analyse['anomalies']) }})
                </button>
                @foreach($analyse['categories'] as $categorie => $nombre)
                    <button type="button" @click="categorieActive = '{{ $categorie }}'"
                            class="inline-flex items-center gap-1 rounded-lg px-2.5 py-1 text-[11px] font-bold transition"
                            :class="categorieActive === '{{ $categorie }}' ? 'bg-rose-700 text-white shadow' : 'bg-white text-rose-800 ring-1 ring-rose-200 hover:bg-rose-100/50 dark:bg-slate-900 dark:text-rose-300 dark:ring-rose-900/60'">
                        {{ $categorie }} ({{ $nombre }})
                    </button>
                @endforeach
                <span class="ml-auto text-[11px] font-medium text-rose-700 dark:text-rose-300" x-text="anomaliesFiltrees.length + ' affichée(s)'"></span>
            </div>

            <div class="mt-4 overflow-hidden rounded-xl border border-rose-200/80 bg-white dark:border-rose-900/40 dark:bg-slate-900" x-show="!replie" x-transition>
                <div class="max-h-64 overflow-y-auto">
                    <table class="min-w-full divide-y divide-rose-100 text-left text-xs dark:divide-slate-800">
                        <thead class="bg-rose-100/50 font-bold uppercase tracking-wider text-rose-900 dark:bg-rose-950/40 dark:text-rose-200">
                            <tr>
                                <th class="px-3.5 py-2">Étudiant</th>
                                <th class="px-3.5 py-2">Catégorie</th>
                                <th class="px-3.5 py-2">Code anomalie</th>
                                <th class="px-3.5 py-2">Diagnostic</th>
                                <th class="px-3.5 py-2 text-right">Action guidée</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                            <template x-for="anom in anomaliesFiltrees" :key="anom.student_id + '-' + anom.code">
                                <tr class="hover:bg-rose-50/30 dark:hover:bg-rose-950/10">
                                    <td class="px-3.5 py-2 font-medium text-slate-900 dark:text-white">
                                        <div x-text="anom.student_nom"></div>
                                        <div class="text-[10px] text-slate-400">
                                            CIN: <span class="font-mono" x-text="anom.student_cin"></span> • #<span x-text="anom.student_id"></span>
                                        </div>
                                    </td>
                                    <td class="px-3.5 py-2">
                                        <span class="inline-flex rounded-md bg-slate-100 px-2 py-0.5 text-[10px] font-bold text-slate-700 dark:bg-slate-800 dark:text-slate-300" x-text="anom.categorie"></span>
                                    </td>
                                    <td class="px-3.5 py-2">
                                        <span class="inline-flex rounded-md bg-rose-100 px-2 py-0.5 text-[10px] font-bold text-rose-800 dark:bg-rose-950/60 dark:text-rose-300" x-text="anom.code"></span>
                                    </td>
                                    <td class="px-3.5 py-2 text-rose-700 dark:text-rose-300" x-text="anom.message"></td>
                                    <td class="px-3.5 py-2 text-right">
                                        <a :href="anom.url_action" target="_blank" rel="noopener noreferrer" class="inline-flex items-center gap-1 rounded-lg border border-slate-200 bg-white px-2 py-1 text-[11px] font-bold text-slate-700 shadow-sm transition hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200">
                                            <span>Régulariser</span>
                                            <span class="text-[10px]">↗</span>
                                        </a>
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    @endif

    {{-- SECTION : SYNTHÈSE D'ÉQUILIBRAGE DES GROUPES (LOOP 4 & 11) --}}
    <div class="mt-6 rounded-2xl border border-slate-200/80 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900">
        <div class="flex items-center justify-between mb-4">
            <div>
                <h2 class="text-base font-bold text-slate-900 dark:text-white flex items-center gap-2">
                    <span class="flex h-6 w-6 items-center justify-center rounded-full bg-primary-100 text-xs font-bold text-primary-800 dark:bg-primary-900/50 dark:text-primary-300">3</span>
                    Synthèse de charge et équilibrage des groupes
                </h2>
                <p class="text-xs text-slate-500 mt-0.5">Le moteur applique l'algorithme d'équilibrage pour minimiser l'écart d'effectifs entre groupes de même niveau.</p>
            </div>
            <span class="rounded-lg bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-600 dark:bg-slate-800 dark:text-slate-300">
                {{ count($analyse['groups_summary']) }} groupe(s) ciblé(s)
            </span>
        </div>

        <div class="overflow-x-auto rounded-xl border border-slate-200/70 dark:border-slate-800">
            <table class="min-w-full divide-y divide-slate-200 text-left text-xs dark:divide-slate-800">
                <thead class="bg-slate-50 font-bold uppercase tracking-wider text-slate-600 dark:bg-slate-800 dark:text-slate-300">
                    <tr>
                        <th class="px-4 py-3">Groupe</th>
                        <th class="px-4 py-3">Niveau & Programme</th>
                        <th class="px-4 py-3 text-center">Capacité</th>
                        <th class="px-4 py-3 text-center">Avant</th>
                        <th class="px-4 py-3 text-center">Ajoutés</th>
                        <th class="px-4 py-3 text-center">Après (simulé)</th>
                        <th class="px-4 py-3">Remplissage prévisionnel</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 dark:divide-slate-800/60">
                    @forelse($analyse['groups_summary'] as $g)
                        <tr class="hover:bg-slate-50/50 dark:hover:bg-slate-800/40">
                            <td class="px-4 py-2.5 font-bold text-slate-900 dark:text-white">
                                <span class="rounded-lg bg-slate-100 px-2 py-1 font-mono text-xs dark:bg-slate-800">{{ $g['code'] }}</span>
                                <span class="ml-1.5 text-slate-500 font-normal">{{ $g['nom'] }}</span>
                            </td>
                            <td class="px-4 py-2.5 text-slate-600 dark:text-slate-300">
                                <span class="font-semibold text-primary-700 dark:text-primary-300">{{ $g['niveau'] ?? '—' }}</span>
                                <span class="text-slate-400">/</span>
                                <span>{{ $g['programme'] ?? 'Général' }}</span>
                            </td>
                            <td class="px-4 py-2.5 text-center font-semibold text-slate-700 dark:text-slate-300">
                                {{ $g['capacity'] ?? 'Illimité' }}
                            </td>
                            <td class="px-4 py-2.5 text-center text-slate-500 tabular-nums">
                                {{ $g['before'] }}
                            </td>
                            <td class="px-4 py-2.5 text-center tabular-nums">
                                @if($g['assigned'] > 0)
                                    <span class="inline-flex items-center rounded-md bg-emerald-50 px-2 py-0.5 font-bold text-emerald-700 dark:bg-emerald-950/50 dark:text-emerald-400">
                                        +{{ $g['assigned'] }}
                                    </span>
                                @else
                                    <span class="text-slate-400">0</span>
                                @endif
                            </td>
                            <td class="px-4 py-2.5 text-center font-bold text-slate-900 dark:text-white tabular-nums">
                                {{ $g['after'] }}
                            </td>
                            <td class="px-4 py-2.5">
                                <div class="flex items-center gap-2">
                                    <div class="h-2 w-28 overflow-hidden rounded-full bg-slate-200 dark:bg-slate-700">
                                        <div class="h-full rounded-full transition-all duration-300 @if($g['fill_rate'] >= 95) bg-rose-500 @elseif($g['fill_rate'] >= 80) bg-amber-500 @else bg-emerald-500 @endif" style="width: {{ min(100, $g['fill_rate']) }}%"></div>
                                    </div>
                                    <span class="tabular-nums font-semibold text-xs @if($g['fill_rate'] >= 95) text-rose-600 @elseif($g['fill_rate'] >= 80) text-amber-600 @else text-emerald-600 @endif">
                                        {{ $g['fill_rate'] }}%
                                    </span>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-4 py-4 text-center text-slate-500">Aucun groupe actif trouvé pour ces critères.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- SECTION : PRÉVISUALISATION INTERACTIVE DES PROPOSITIONS (LOOP 7 & 11) --}}
    <div class="mt-6 rounded-2xl border border-slate-200/80 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between mb-4">
            <div>
                <h2 class="text-base font-bold text-slate-900 dark:text-white flex items-center gap-2">
                    <span class="flex h-6 w-6 items-center justify-center rounded-full bg-primary-100 text-xs font-bold text-primary-800 dark:bg-primary-900/50 dark:text-primary-300">4</span>
                    Prévisualisation des affectations proposées
                </h2>
                <p class="text-xs text-slate-500 mt-0.5">
                    Vous pouvez sélectionner/désélectionner individuellement ou ajuster le groupe cible (seuls les groupes strictement compatibles sont autorisés).
                </p>
            </div>

            {{-- Filtres interactifs client --}}
            <div class="flex flex-wrap items-center gap-2">
                <input type="text" x-model="recherche" placeholder="Rechercher nom, CIN..." class="rounded-xl border border-slate-200 bg-white px-3 py-1.5 text-xs text-slate-900 shadow-sm focus:border-primary-500 focus:outline-none dark:border-slate-700 dark:bg-slate-800 dark:text-white w-48" />

                <button type="button" @click="toutSelectionner(true)" class="rounded-xl border border-slate-200 bg-white px-2.5 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200">
                    Tout cocher
                </button>
                <button type="button" @click="toutSelectionner(false)" class="rounded-xl border border-slate-200 bg-white px-2.5 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200">
                    Tout décocher
                </button>
                <button type="button" @click="neGarderQueCasSurs()" :disabled="nbCasSurs === 0" class="rounded-xl border border-emerald-200 bg-emerald-50 px-2.5 py-1.5 text-xs font-bold text-emerald-700 hover:bg-emerald-100 disabled:opacity-50 disabled:cursor-not-allowed dark:border-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-300" :title="'Cas sûrs : certitude élevée et aucune alerte'">
                    ✓ Cas sûrs (<span x-text="nbCasSurs"></span>)
                </button>
            </div>
        </div>

        {{-- Table interactive --}}
        <div class="overflow-x-auto rounded-xl border border-slate-200/70 dark:border-slate-800">
            <table class="min-w-full divide-y divide-slate-200 text-left text-xs dark:divide-slate-800">
                <thead class="bg-slate-50 font-bold uppercase tracking-wider text-slate-600 dark:bg-slate-800 dark:text-slate-300">
                    <tr>
                        <th class="px-3.5 py-3 w-10 text-center">
                            <input type="checkbox" :checked="tousCoches" @change="basculerTousCoches($event.target.checked)" class="rounded border-slate-300 text-primary-600 focus:ring-primary-500" />
                        </th>
                        <th class="px-3.5 py-3">Étudiant</th>
                        <th class="px-3.5 py-3">Niveau & Programme</th>
                        <th class="px-3.5 py-3">Groupe actuel</th>
                        <th class="px-3.5 py-3">Groupe proposé (Ajustable)</th>
                        <th class="px-3.5 py-3 text-center">Score</th>
                        <th class="px-3.5 py-3">Alertes / Diagnostic</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 dark:divide-slate-800/60">
                    <template x-for="(p, index) in propositionsFiltrees" :key="p.student_id">
                        <tr class="hover:bg-slate-50/50 dark:hover:bg-slate-800/40" :class="{'bg-primary-50/20 dark:bg-primary-950/10': p.selected}">
                            <td class="px-3.5 py-2.5 text-center">
                                <input type="checkbox" x-model="p.selected" class="rounded border-slate-300 text-primary-600 focus:ring-primary-500" />
                            </td>
                            <td class="px-3.5 py-2.5">
                                <div class="font-bold text-slate-900 dark:text-white" x-text="p.student_nom + ' ' + p.student_prenom"></div>
                                <div class="text-[11px] text-slate-500">
                                    CIN: <span class="font-mono" x-text="p.student_cin"></span> 
                                    • N°: <span class="font-mono" x-text="p.student_numero"></span>
                                </div>
                            </td>
                            <td class="px-3.5 py-2.5 text-slate-700 dark:text-slate-300">
                                <span class="font-bold text-primary-700 dark:text-primary-300" x-text="p.niveau"></span>
                                <span class="text-slate-400">/</span>
                                <span x-text="p.programme"></span>
                            </td>
                            <td class="px-3.5 py-2.5 text-slate-500">
                                <template x-if="p.current_group">
                                    <span class="inline-flex rounded bg-amber-50 px-2 py-0.5 text-[11px] font-semibold text-amber-700 dark:bg-amber-950/50 dark:text-amber-300" x-text="p.current_group"></span>
                                </template>
                                <template x-if="!p.current_group">
                                    <span class="text-slate-400 italic">Aucun</span>
                                </template>
                            </td>
                            <td class="px-3.5 py-2.5">
                                <select x-model="p.proposed_group_id" class="rounded-lg border border-slate-200 bg-white px-2.5 py-1 text-xs text-slate-900 shadow-sm focus:border-primary-500 focus:outline-none dark:border-slate-700 dark:bg-slate-800 dark:text-white font-medium">
                                    <template x-for="gid in getCompatibleGroupIds(p)" :key="gid">
                                        <option :value="parseInt(gid)" x-text="getGroupLabel(gid, p)" :selected="parseInt(gid) === parseInt(p.proposed_group_id)"></option>
                                    </template>
                                </select>
                                <template x-if="p.alternatives && p.alternatives.length > 0">
                                    <details class="mt-1 text-[11px] text-slate-500">
                                        <summary class="cursor-pointer font-semibold hover:text-slate-700 dark:hover:text-slate-300">Autres groupes possibles</summary>
                                        <template x-for="alt in p.alternatives" :key="alt.group_id">
                                            <div class="mt-0.5 flex items-center justify-between gap-2 rounded bg-slate-50 px-1.5 py-0.5 dark:bg-slate-800/60">
                                                <span class="font-mono font-bold" x-text="alt.code"></span>
                                                <button type="button" @click="p.proposed_group_id = alt.group_id" class="font-bold text-primary-600 hover:underline" x-text="alt.score + '/100 → choisir'"></button>
                                            </div>
                                        </template>
                                    </details>
                                </template>
                            </td>
                            <td class="px-3.5 py-2.5 text-center">
                                <span class="inline-flex rounded-md px-2 py-0.5 text-[11px] font-black tabular-nums" :class="(p.score ?? 0) >= 85 ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/50 dark:text-emerald-400' : ((p.score ?? 0) >= 70 ? 'bg-amber-50 text-amber-700 dark:bg-amber-950/50 dark:text-amber-400' : 'bg-rose-50 text-rose-700 dark:bg-rose-950/50 dark:text-rose-300')" x-text="(p.score ?? '—') + (p.score !== undefined ? '/100' : '')"></span>
                                <div class="mt-0.5 text-[10px] font-semibold uppercase tracking-wide text-slate-400" x-text="p.certitude === 'elevee' ? '🤖 sûr' : '👤 à valider'"></div>
                            </td>
                            <td class="px-3.5 py-2.5 text-xs">
                                <template x-if="p.warnings.length > 0">
                                    <div class="space-y-0.5">
                                        <template x-for="w in p.warnings" :key="w">
                                            <span class="inline-block rounded bg-amber-50 px-1.5 py-0.5 text-[10px] font-medium text-amber-700 dark:bg-amber-950/40 dark:text-amber-300" x-text="w"></span>
                                        </template>
                                    </div>
                                </template>
                                <template x-if="p.warnings.length === 0">
                                    <span class="text-emerald-600 dark:text-emerald-400 font-semibold text-[11px]">✓ Conforme</span>
                                </template>
                                <template x-if="p.reasons && p.reasons.length > 0">
                                    <details class="mt-1 text-[11px] text-slate-500">
                                        <summary class="cursor-pointer font-semibold hover:text-slate-700 dark:hover:text-slate-300">Pourquoi ce groupe ?</summary>
                                        <template x-for="r in p.reasons" :key="r">
                                            <div class="mt-0.5" x-text="'✓ ' + r"></div>
                                        </template>
                                    </details>
                                </template>
                            </td>
                        </tr>
                    </template>
                    <tr x-show="propositionsFiltrees.length === 0">
                        <td colspan="7" class="px-4 py-8 text-center text-slate-500">
                            Aucune proposition ne correspond à votre filtre de recherche.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        {{-- Barre inférieure d'action --}}
        <div class="mt-5 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between border-t border-slate-200/80 pt-4 dark:border-slate-800">
            <div class="text-xs text-slate-600 dark:text-slate-400">
                <span class="font-bold text-slate-900 dark:text-white" x-text="nbSelectionnes"></span>
                étudiant(s) sélectionné(s) sur <span x-text="propositions.length"></span> propositions
            </div>

            <button type="button" @click="ouvrirConfirmation()" :disabled="nbSelectionnes === 0" class="inline-flex items-center justify-center gap-2 rounded-xl bg-primary-600 px-5 py-2.5 text-sm font-bold text-white shadow-sm transition hover:bg-primary-700 focus:outline-none focus:ring-2 focus:ring-primary-500/50 disabled:opacity-50 disabled:cursor-not-allowed">
                <span>Passer à la confirmation définitive</span>
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3" />
                </svg>
            </button>
        </div>
    </div>

    {{-- MODALE DE CONFIRMATION TRANSACTIONNELLE (LOOP 8, 9 & 10) --}}
    <div x-show="modalConfirmation" x-cloak
         @keydown.escape.window="modalConfirmation = false"
         x-transition:enter="transition ease-out duration-150" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
         x-transition:leave="transition ease-in duration-100" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0"
         class="fixed inset-0 z-[70] flex items-center justify-center p-4" role="dialog" aria-modal="true">
        <div class="absolute inset-0 bg-slate-900/60 backdrop-blur-xs" @click="modalConfirmation = false" aria-hidden="true"></div>

        <div class="relative w-full max-w-lg rounded-2xl border border-slate-200 bg-white p-6 shadow-2xl dark:border-slate-700 dark:bg-slate-900">
            <div class="flex items-start justify-between">
                <div>
                    <h3 class="text-lg font-black text-slate-900 dark:text-white">Confirmation de l'affectation en masse</h3>
                    <p class="text-xs text-slate-500 mt-0.5">Exécution transactionnelle avec verrouillage des capacités en base.</p>
                </div>
                <button type="button" @click="modalConfirmation = false" class="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 hover:text-slate-600 dark:hover:bg-slate-800">✕</button>
            </div>

            <form method="POST" action="{{ route('admin.affectations.masse.confirmer') }}" class="mt-5 space-y-4">
                @csrf
                <input type="hidden" name="annee_universitaire_id" value="{{ $criteres['annee_universitaire_id'] }}">
                <input type="hidden" name="selections_json" :value="JSON.stringify(getSelectionsPayload())">

                {{-- Encart récapitulatif --}}
                <div class="rounded-xl border border-primary-200 bg-primary-50/50 p-4 dark:border-primary-900/60 dark:bg-primary-950/20">
                    <div class="flex items-center gap-2">
                        <svg class="h-5 w-5 text-primary-600 dark:text-primary-400 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                        <span class="text-xs font-bold text-primary-900 dark:text-primary-200">
                            Volume à affecter : <span class="text-sm font-extrabold" x-text="nbSelectionnes"></span> étudiant(s)
                        </span>
                    </div>
                    <p class="mt-1 text-[11px] text-primary-800/80 dark:text-primary-300/80">
                        Chaque affectation sera vérifiée sous verrou <code class="font-mono">lockForUpdate()</code>. Si un groupe a été complété entre-temps par un autre administrateur, la place sera automatiquement protégée.
                    </p>
                </div>

                {{-- Motif d'affectation obligatoire --}}
                <div>
                    <label class="block text-xs font-bold uppercase tracking-wider text-slate-600 dark:text-slate-400 mb-1" for="motif">
                        Motif d'affectation (Tracé dans l'audit) <span class="text-rose-500">*</span>
                    </label>
                    <input type="text" id="motif" name="motif" value="Affectation automatique intelligente par niveau — {{ $annee->libelle }}" required class="w-full rounded-xl border border-slate-200 px-3 py-2 text-xs text-slate-900 shadow-sm focus:border-primary-500 focus:outline-none dark:border-slate-700 dark:bg-slate-800 dark:text-white font-medium" />
                </div>

                {{-- Option administrateur pour forcer la capacité --}}
                @if(auth()->user()->hasPermission('groupes.manage'))
                    <div class="flex items-start gap-2.5 rounded-xl border border-amber-200 bg-amber-50/50 p-3 dark:border-amber-900/50 dark:bg-amber-950/20">
                        <input type="checkbox" id="force" name="force" value="1" class="mt-0.5 rounded border-amber-300 text-amber-600 focus:ring-amber-500" />
                        <label for="force" class="text-xs text-amber-900 dark:text-amber-200">
                            <strong>Forcer en cas de dépassement exceptionnel</strong> (requiert le droit <code class="font-mono text-[10px]">groupes.manage</code>)
                        </label>
                    </div>
                @endif

                {{-- Engagement de confirmation --}}
                <div class="flex items-start gap-2.5">
                    <input type="checkbox" id="confirmer" name="confirmer" value="1" required class="mt-0.5 rounded border-slate-300 text-primary-600 focus:ring-primary-500" />
                    <label for="confirmer" class="text-xs font-medium text-slate-700 dark:text-slate-300">
                        Je confirme vouloir appliquer ces affectations de manière effective et irréversible.
                    </label>
                </div>

                {{-- Boutons d'action --}}
                <div class="mt-5 flex justify-end gap-2 border-t border-slate-200/80 pt-4 dark:border-slate-800">
                    <button type="button" @click="modalConfirmation = false" class="rounded-xl border border-slate-200 px-4 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-50 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-800">
                        Annuler
                    </button>
                    <button type="submit" class="inline-flex items-center gap-1.5 rounded-xl bg-primary-600 px-5 py-2 text-xs font-bold text-white shadow-sm hover:bg-primary-700 focus:outline-none focus:ring-2 focus:ring-primary-500">
                        <span>Exécuter l'affectation transactionnelle</span>
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                        </svg>
                    </button>
                </div>
            </form>
        </div>
    </div>

    {{-- MODALE DE GÉNÉRATION DES PROPOSITIONS SEMI-AUTOMATIQUES (LOOP 7 & 11) --}}
    <div x-cloak x-show="modalPropositions" class="fixed inset-0 z-50 flex items-center justify-center p-4" role="dialog" aria-modal="true">
        <div class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm transition-opacity" @click="modalPropositions = false"></div>
        <div class="relative w-full max-w-lg rounded-2xl bg-white p-6 shadow-2xl dark:bg-slate-900 border border-slate-200 dark:border-slate-800">
            <div class="flex items-center justify-between border-b border-slate-200/80 pb-4 dark:border-slate-800">
                <div class="flex items-center gap-2.5">
                    <span class="flex h-8 w-8 items-center justify-center rounded-xl bg-indigo-600 text-sm font-bold text-white shadow">
                        🧠
                    </span>
                    <div>
                        <h3 class="text-lg font-black text-slate-900 dark:text-white">Générer les propositions semi-automatiques</h3>
                        <p class="text-xs text-slate-500 mt-0.5">Enregistre des propositions avec scoring /100 soumises à validation ultérieure.</p>
                    </div>
                </div>
                <button type="button" @click="modalPropositions = false" class="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 hover:text-slate-600 dark:hover:bg-slate-800">✕</button>
            </div>

            <form method="POST" action="{{ route('admin.affectations.masse.proposer') }}" class="mt-5 space-y-4">
                @csrf
                <input type="hidden" name="annee_universitaire_id" value="{{ $criteres['annee_universitaire_id'] }}">
                <input type="hidden" name="selections_json" :value="JSON.stringify(getSelectionsPayload())">

                <div class="rounded-xl border border-indigo-200 bg-indigo-50/50 p-4 dark:border-indigo-900/60 dark:bg-indigo-950/20">
                    <div class="flex items-center gap-2">
                        <svg class="h-5 w-5 text-indigo-600 dark:text-indigo-400 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                        <span class="text-xs font-bold text-indigo-900 dark:text-indigo-200">
                            Propositions à générer : <span class="text-sm font-extrabold" x-text="nbSelectionnes"></span> étudiant(s)
                        </span>
                    </div>
                    <p class="mt-1 text-[11px] text-indigo-800/80 dark:text-indigo-300/80">
                        Ces propositions resteront <strong>en attente</strong> jusqu’à validation, modification ou rejet par l'administrateur dans l’onglet dédié aux propositions.
                    </p>
                </div>

                <div>
                    <label class="block text-xs font-bold uppercase tracking-wider text-slate-600 dark:text-slate-400 mb-1" for="motif_propositions">
                        Motif ou description du lot <span class="text-rose-500">*</span>
                    </label>
                    <input type="text" id="motif_propositions" name="motif" value="Génération semi-automatique par niveau — {{ $annee->libelle }}" required class="w-full rounded-xl border border-slate-200 px-3 py-2 text-xs text-slate-900 shadow-sm focus:border-indigo-500 focus:outline-none dark:border-slate-700 dark:bg-slate-800 dark:text-white font-medium" />
                </div>

                <div class="mt-5 flex justify-end gap-2 border-t border-slate-200/80 pt-4 dark:border-slate-800">
                    <button type="button" @click="modalPropositions = false" class="rounded-xl border border-slate-200 px-4 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-50 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-800">
                        Annuler
                    </button>
                    <button type="submit" class="inline-flex items-center gap-1.5 rounded-xl bg-indigo-600 px-5 py-2 text-xs font-bold text-white shadow-sm hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-500">
                        <span>Enregistrer les propositions</span>
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                        </svg>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function masseSimulationApp(initialPropositions, initialGroupsMap) {
    return {
        propositions: initialPropositions || [],
        groupsMap: initialGroupsMap || {},
        recherche: '',
        tousCoches: true,
        modalConfirmation: false,
        modalPropositions: false,

        getCompatibleGroupIds(p) {
            if (!p.compatible_groups) return [];
            return Array.isArray(p.compatible_groups) ? p.compatible_groups : Object.keys(p.compatible_groups);
        },

        getGroupLabel(gid, p) {
            const id = parseInt(gid);
            return this.groupsMap[id] || (p.compatible_groups && !Array.isArray(p.compatible_groups) ? p.compatible_groups[gid] : String(gid));
        },

        getCompatibleGroupsForPayload(p) {
            if (!p.compatible_groups) return {};
            if (!Array.isArray(p.compatible_groups)) return p.compatible_groups;
            const res = {};
            for (const gid of p.compatible_groups) {
                res[gid] = this.groupsMap[gid] || String(gid);
            }
            return res;
        },

        get nbSelectionnes() {
            return this.propositions.filter(p => p.selected).length;
        },

        get nbCasSurs() {
            return this.propositions.filter(p => p.certitude === 'elevee' && (p.warnings || []).length === 0).length;
        },

        neGarderQueCasSurs() {
            this.propositions.forEach(p => p.selected = (p.certitude === 'elevee' && (p.warnings || []).length === 0));
        },

        get propositionsFiltrees() {
            if (!this.recherche || this.recherche.trim() === '') {
                return this.propositions;
            }
            const q = this.recherche.toLowerCase().trim();
            return this.propositions.filter(p => {
                const nom = (p.student_nom + ' ' + p.student_prenom).toLowerCase();
                const cin = (p.student_cin || '').toLowerCase();
                const num = (p.student_numero || '').toLowerCase();
                const grp = (p.proposed_group_code || '').toLowerCase();
                return nom.includes(q) || cin.includes(q) || num.includes(q) || grp.includes(q);
            });
        },

        toutSelectionner(etat) {
            this.propositions.forEach(p => p.selected = etat);
            this.tousCoches = etat;
        },

        basculerTousCoches(etat) {
            this.toutSelectionner(etat);
        },

        getSelectionsPayload() {
            return this.propositions
                .filter(p => p.selected && p.proposed_group_id)
                .map(p => ({
                    student_id: p.student_id,
                    groupe_id: parseInt(p.proposed_group_id),
                    score: p.score ?? 85,
                    certitude: p.certitude || 'moyenne',
                    reasons: p.reasons || ['Équilibrage de niveau'],
                    alternatives: p.alternatives || [],
                    groupes_compatibles: this.getCompatibleGroupsForPayload(p)
                }));
        },

        ouvrirConfirmation() {
            if (this.nbSelectionnes === 0) return;
            this.modalConfirmation = true;
        },

        ouvrirPropositions() {
            if (this.nbSelectionnes === 0) return;
            this.modalPropositions = true;
        }
    };
}

function centreExceptionsApp(initialAnomalies) {
    return {
        anomalies: initialAnomalies || [],
        categorieActive: 'Toutes',
        recherche: '',
        replie: false,

        get anomaliesFiltrees() {
            return this.anomalies.filter(a => {
                const matchCat = this.categorieActive === 'Toutes' || a.categorie === this.categorieActive;
                if (!matchCat) return false;
                if (!this.recherche || this.recherche.trim() === '') return true;
                const q = this.recherche.toLowerCase().trim();
                const nom = (a.student_nom || '').toLowerCase();
                const cin = (a.student_cin || '').toLowerCase();
                const code = (a.code || '').toLowerCase();
                const msg = (a.message || '').toLowerCase();
                return nom.includes(q) || cin.includes(q) || code.includes(q) || msg.includes(q);
            });
        }
    };
}
</script>
@endsection
