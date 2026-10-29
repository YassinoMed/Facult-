@extends('layouts.app')

@section('title', 'Examens — Groupes virtuels des examens finaux')

@section('content')
@php
    $f = $filtres_appliques ?? [];
    $queryActuelle = array_filter($f, fn ($v) => $v !== null && $v !== '');
@endphp

<div class="space-y-6">
    <x-page-header
        titre="Groupes virtuels des examens finaux"
        description="Consultation en lecture seule, contrôle de correspondance Matière ↔ Groupes ↔ Étudiants, exports multi-formats (XLSX, CSV, PDF, HTML) et impression professionnelle."
        :fil="[
            'Évaluation' => route('admin.evaluations.index'),
            'Examens' => route('admin.evaluation.examens.tableau-de-bord'),
            'Groupes' => route('admin.evaluation.examens.groupes'),
            'Groupes virtuels' => '',
        ]"
    />

    @include('modules.evaluation.examens-advanced._nav-tabs')

    {{-- Bandeau d'information gouvernance & lecture seule --}}
    <div class="flex flex-wrap items-center justify-between gap-4 rounded-2xl border border-indigo-200/80 bg-gradient-to-r from-indigo-50/80 via-white to-slate-50 p-4 shadow-2xs">
        <div class="flex items-start gap-3">
            <span class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-indigo-600 text-white shadow-xs">
                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M18 18.72a9.094 9.094 0 0 0 3.741-.479 3 3 0 0 0-4.682-2.72m.94 3.198.001.031c0 .225-.012.447-.037.666A11.944 11.944 0 0 1 12 21c-2.17 0-4.207-.576-5.963-1.584A6.062 6.062 0 0 1 6 18.719m12 0a5.971 5.971 0 0 0-.941-3.197m0 0A5.995 5.995 0 0 0 12 12.75a5.995 5.995 0 0 0-5.058 2.772m0 0a3 3 0 0 0-4.681 2.72 8.986 8.986 0 0 0 3.74.477m.94-3.197a5.971 5.971 0 0 0-.94 3.197M15 6.75a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
                </svg>
            </span>
            <div>
                <div class="flex flex-wrap items-center gap-2">
                    <h3 class="text-sm font-bold text-slate-900">Consultation garantie sans effet de bord (Lecture seule)</h3>
                    <span class="inline-flex items-center rounded-full bg-indigo-100 px-2.5 py-0.5 text-[11px] font-bold text-indigo-800">
                        Zéro mutation des groupes d'origine
                    </span>
                </div>
                <p class="mt-0.5 text-xs text-slate-600">
                    Chaque groupe virtuel agrège uniquement les étudiants inscrits et éligibles à la matière examinée. Une proposition non validée est signalée explicitement et ne vaut jamais publication officielle.
                </p>
            </div>
        </div>
        <div class="flex items-center gap-2">
            <form method="POST" action="{{ route('admin.evaluation.examens.groupes-virtuels.generer-automatique', request()->query()) }}">
                @csrf
                <button type="submit"
                        title="Analyser les épreuves et proposer automatiquement les regroupements virtuels"
                        class="inline-flex items-center gap-1.5 rounded-xl bg-indigo-600 hover:bg-indigo-700 px-3.5 py-2 text-xs font-bold text-white shadow-xs transition cursor-pointer">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0 3.181 3.183a8.25 8.25 0 0 0 13.803-3.7M4.031 9.865a8.25 8.25 0 0 1 13.803-3.7l3.181 3.182m0-4.991v4.99" />
                    </svg>
                    Détecter & Générer automatiquement
                </button>
            </form>
            <a href="{{ route('admin.evaluation.examens.groupes', ['mode' => 'B']) }}"
               class="inline-flex items-center gap-1.5 rounded-xl border border-purple-200 bg-purple-50 px-3 py-2 text-xs font-semibold text-purple-900 hover:bg-purple-100 transition"
               title="Accéder directement au module de décision capacitaire et permutation de salles">
                🏛️ Salles & Amphis D-04
            </a>
            <a href="{{ route('admin.evaluation.examens.groupes') }}"
               class="inline-flex items-center gap-1.5 rounded-xl border border-slate-200 bg-white px-3 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-50 transition">
                Retour aux cohortes D-04
            </a>
        </div>
    </div>

    {{-- Cartes KPI interactives (Option A : filtres cliquables) --}}
    @php
        $statutActif = $f['statut'] ?? 'tous';
        $paramsBase = request()->except(['page', 'statut']);
    @endphp
    <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3">
        <a href="{{ route('admin.evaluation.examens.groupes-virtuels.index', array_merge($paramsBase, ['statut' => 'tous'])) }}"
           title="Afficher tous les groupes virtuels"
           class="group rounded-2xl border p-4 shadow-2xs transition-all cursor-pointer block {{ $statutActif === 'tous' || empty($statutActif) ? 'border-slate-400 bg-slate-50/90 ring-2 ring-slate-400/50 shadow-xs' : 'border-slate-200/80 bg-white hover:border-slate-300 hover:shadow-xs' }}">
            <span class="text-[11px] font-bold uppercase tracking-wider text-slate-500 group-hover:text-slate-700">Groupes virtuels</span>
            <div class="mt-1 text-2xl font-black text-slate-900">{{ $kpis['total_groupes'] ?? 0 }}</div>
            <p class="mt-0.5 text-[11px] text-slate-400">Tous les statuts</p>
        </a>

        <a href="{{ route('admin.evaluation.examens.groupes-virtuels.index', array_merge($paramsBase, ['statut' => 'propose'])) }}"
           title="Filtrer sur les propositions actives"
           class="group rounded-2xl border p-4 shadow-2xs transition-all cursor-pointer block {{ $statutActif === 'propose' ? 'border-blue-500 bg-blue-50/80 ring-2 ring-blue-500/50 shadow-xs' : 'border-blue-200/80 bg-blue-50/40 hover:border-blue-300 hover:shadow-xs' }}">
            <span class="text-[11px] font-bold uppercase tracking-wider text-blue-800 group-hover:text-blue-900">Propositions actives</span>
            <div class="mt-1 text-2xl font-black text-blue-950">{{ $kpis['actifs_proposes'] ?? 0 }}</div>
            <p class="mt-0.5 text-[11px] text-blue-700">Statut « proposé »</p>
        </a>

        <a href="{{ route('admin.evaluation.examens.groupes-virtuels.index', array_merge($paramsBase, ['statut' => 'valide'])) }}"
           title="Filtrer sur les populations validées"
           class="group rounded-2xl border p-4 shadow-2xs transition-all cursor-pointer block {{ $statutActif === 'valide' ? 'border-emerald-500 bg-emerald-50/80 ring-2 ring-emerald-500/50 shadow-xs' : 'border-emerald-200/80 bg-emerald-50/40 hover:border-emerald-300 hover:shadow-xs' }}">
            <span class="text-[11px] font-bold uppercase tracking-wider text-emerald-800 group-hover:text-emerald-900">Populations validées</span>
            <div class="mt-1 text-2xl font-black text-emerald-950">{{ $kpis['valides'] ?? 0 }}</div>
            <p class="mt-0.5 text-[11px] text-emerald-700">Snapshot figé</p>
        </a>

        <a href="{{ route('admin.evaluation.examens.groupes-virtuels.index', array_merge($paramsBase, ['statut' => 'publie'])) }}"
           title="Filtrer sur les examens publiés"
           class="group rounded-2xl border p-4 shadow-2xs transition-all cursor-pointer block {{ $statutActif === 'publie' ? 'border-indigo-500 bg-indigo-50/80 ring-2 ring-indigo-500/50 shadow-xs' : 'border-indigo-200/80 bg-indigo-50/40 hover:border-indigo-300 hover:shadow-xs' }}">
            <span class="text-[11px] font-bold uppercase tracking-wider text-indigo-800 group-hover:text-indigo-900">Examens publiés</span>
            <div class="mt-1 text-2xl font-black text-indigo-950">{{ $kpis['publies'] ?? 0 }}</div>
            <p class="mt-0.5 text-[11px] text-indigo-700">Publication officielle</p>
        </a>

        <div class="rounded-2xl border border-slate-200/80 bg-white p-4 shadow-2xs">
            <span class="text-[11px] font-bold uppercase tracking-wider text-slate-500">Étudiants éligibles</span>
            <div class="mt-1 text-2xl font-black text-slate-900">{{ $kpis['total_eligibles'] ?? 0 }}</div>
            <p class="mt-0.5 text-[11px] text-slate-400">Cumul sélection</p>
        </div>

        <a href="{{ route('admin.evaluation.examens.groupes-virtuels.index', array_merge($paramsBase, ['statut' => 'derive'])) }}"
           title="Filtrer sur les groupes présentant une dérive ou une alerte"
           class="group rounded-2xl border p-4 shadow-2xs transition-all cursor-pointer block {{ $statutActif === 'derive' ? 'border-rose-500 bg-rose-50/90 ring-2 ring-rose-500/50 shadow-xs' : (($kpis['alertes_count'] ?? 0) > 0 ? 'border-rose-300 bg-rose-50/60 hover:border-rose-400 hover:shadow-xs' : 'border-slate-200/80 bg-white hover:border-slate-300 hover:shadow-xs') }}">
            <span class="text-[11px] font-bold uppercase tracking-wider {{ ($kpis['alertes_count'] ?? 0) > 0 ? 'text-rose-800' : 'text-slate-500' }} group-hover:text-rose-900">Alertes / Dérives</span>
            <div class="mt-1 text-2xl font-black {{ ($kpis['alertes_count'] ?? 0) > 0 ? 'text-rose-900' : 'text-slate-900' }}">{{ $kpis['alertes_count'] ?? 0 }}</div>
            <p class="mt-0.5 text-[11px] {{ ($kpis['alertes_count'] ?? 0) > 0 ? 'text-rose-700' : 'text-slate-400' }}">Contrôle correspondance</p>
        </a>
    </div>

    {{-- Filtres combinables et barre d'export de la sélection --}}
    <div class="rounded-2xl border border-slate-200/80 bg-white p-5 shadow-xs space-y-4">
        <form method="GET" action="{{ route('admin.evaluation.examens.groupes-virtuels.index') }}" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
            <div>
                <label class="block text-[11px] font-bold uppercase tracking-wider text-slate-600 mb-1">Année universitaire</label>
                <select name="annee_id" class="w-full rounded-xl border border-slate-200 bg-slate-50/50 px-3 py-2 text-xs font-medium text-slate-800">
                    <option value="">Toutes les années</option>
                    @foreach($referentiels['annees'] ?? [] as $annee)
                        <option value="{{ $annee->id }}" @selected(($f['annee_id'] ?? null) === (int) $annee->id)>
                            {{ $annee->libelle }}
                        </option>
                    @endforeach
                </select>
            </div>

            <div>
                <label class="block text-[11px] font-bold uppercase tracking-wider text-slate-600 mb-1">Session d'examens</label>
                <select name="session_id" class="w-full rounded-xl border border-slate-200 bg-slate-50/50 px-3 py-2 text-xs font-medium text-slate-800">
                    <option value="">Toutes les sessions</option>
                    @foreach($referentiels['sessions'] ?? [] as $sess)
                        <option value="{{ $sess->id }}" @selected(($f['session_id'] ?? null) === (int) $sess->id)>
                            [{{ $sess->code }}] {{ $sess->nom }}
                        </option>
                    @endforeach
                </select>
            </div>

            <div>
                <label class="block text-[11px] font-bold uppercase tracking-wider text-slate-600 mb-1">Faculté</label>
                <select name="faculte_id" class="w-full rounded-xl border border-slate-200 bg-slate-50/50 px-3 py-2 text-xs font-medium text-slate-800">
                    @if(auth()->user()?->faculteRestreinteId() === null)
                        <option value="">Toutes les facultés</option>
                    @endif
                    @foreach($referentiels['facultes'] ?? [] as $fac)
                        <option value="{{ $fac->id }}" @selected(($f['faculte_id'] ?? null) === (int) $fac->id)>
                            [{{ $fac->code }}] {{ $fac->nom }}
                        </option>
                    @endforeach
                </select>
            </div>

            <div>
                <label class="block text-[11px] font-bold uppercase tracking-wider text-slate-600 mb-1">Matière</label>
                <select name="matiere_id" class="w-full rounded-xl border border-slate-200 bg-slate-50/50 px-3 py-2 text-xs font-medium text-slate-800">
                    <option value="">Toutes les matières</option>
                    @foreach($referentiels['matieres'] ?? [] as $mat)
                        <option value="{{ $mat->id }}" @selected(($f['matiere_id'] ?? null) === (int) $mat->id)>
                            [{{ $mat->code }}] {{ $mat->nom }}
                        </option>
                    @endforeach
                </select>
            </div>

            <div>
                <label class="block text-[11px] font-bold uppercase tracking-wider text-slate-600 mb-1">Niveau</label>
                <select name="niveau_id" class="w-full rounded-xl border border-slate-200 bg-slate-50/50 px-3 py-2 text-xs font-medium text-slate-800">
                    <option value="">Tous les niveaux</option>
                    @foreach($referentiels['niveaux'] ?? [] as $niv)
                        <option value="{{ $niv->id }}" @selected(($f['niveau_id'] ?? null) === (int) $niv->id)>
                            [{{ $niv->code }}] {{ $niv->nom }}
                        </option>
                    @endforeach
                </select>
            </div>

            <div>
                <label class="block text-[11px] font-bold uppercase tracking-wider text-slate-600 mb-1">Statut</label>
                <select name="statut" class="w-full rounded-xl border border-slate-200 bg-slate-50/50 px-3 py-2 text-xs font-medium text-slate-800">
                    <option value="">Tous les statuts</option>
                    <option value="propose" @selected(($f['statut'] ?? null) === 'propose')>Proposition active (proposé)</option>
                    <option value="valide" @selected(($f['statut'] ?? null) === 'valide')>Population validée</option>
                    <option value="non_valide" @selected(($f['statut'] ?? null) === 'non_valide')>Proposition non validée</option>
                    <option value="publie" @selected(($f['statut'] ?? null) === 'publie')>Examen publié</option>
                    <option value="derive" @selected(($f['statut'] ?? null) === 'derive')>En dérive / Anomalie</option>
                    <option value="remplace" @selected(($f['statut'] ?? null) === 'remplace')>Remplacé (historique)</option>
                    <option value="annule" @selected(($f['statut'] ?? null) === 'annule')>Annulé</option>
                </select>
            </div>

            <div class="sm:col-span-2 flex items-end gap-2">
                <div class="flex-1">
                    <label class="block text-[11px] font-bold uppercase tracking-wider text-slate-600 mb-1">Recherche (libellé, matière, clé, filière)</label>
                    <input type="text" name="recherche" value="{{ $f['recherche'] ?? '' }}" placeholder="Ex : GV MAT-GV, Licence, Informatique…"
                           class="w-full rounded-xl border border-slate-200 bg-slate-50/50 px-3 py-2 text-xs text-slate-800 placeholder-slate-400 focus:border-primary-500 focus:bg-white focus:outline-none" />
                </div>
                <button type="submit" class="rounded-xl bg-primary-600 hover:bg-primary-700 px-4 py-2 text-xs font-bold text-white shadow-xs transition">
                    Filtrer
                </button>
                @if(!empty($queryActuelle))
                    <a href="{{ route('admin.evaluation.examens.groupes-virtuels.index') }}"
                       class="rounded-xl border border-slate-200 bg-slate-50 hover:bg-slate-100 px-3 py-2 text-xs font-semibold text-slate-600 transition">
                        Réinitialiser
                    </a>
                @endif
            </div>
        </form>

        {{-- Barre d'actions d'export global et d'impression de synthèse (selon les filtres actifs) --}}
        <div class="flex flex-wrap items-center justify-between gap-3 border-t border-slate-100 pt-3">
            <div class="text-xs text-slate-500">
                Export et impression de la sélection filtrée
                @if(!empty($f['matiere_id']))
                    <span class="font-semibold text-indigo-700">(Périmètre : Matière #{{ $f['matiere_id'] }})</span>
                @elseif(!empty($f['session_id']))
                    <span class="font-semibold text-indigo-700">(Périmètre : Session #{{ $f['session_id'] }})</span>
                @else
                    <span class="font-semibold text-slate-700">(Périmètre : Tous les groupes affichés)</span>
                @endif
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <a href="{{ route('admin.evaluation.examens.groupes-virtuels.exporter', array_merge($queryActuelle, ['format' => 'xlsx'])) }}"
                   class="inline-flex items-center gap-1 rounded-lg border border-emerald-200 bg-emerald-50 hover:bg-emerald-100 px-2.5 py-1.5 text-xs font-bold text-emerald-900 transition">
                    Export XLSX
                </a>
                <a href="{{ route('admin.evaluation.examens.groupes-virtuels.exporter', array_merge($queryActuelle, ['format' => 'csv'])) }}"
                   class="inline-flex items-center gap-1 rounded-lg border border-slate-200 bg-slate-50 hover:bg-slate-100 px-2.5 py-1.5 text-xs font-bold text-slate-800 transition">
                    Export CSV
                </a>
                <a href="{{ route('admin.evaluation.examens.groupes-virtuels.exporter', array_merge($queryActuelle, ['format' => 'pdf'])) }}"
                   class="inline-flex items-center gap-1 rounded-lg border border-rose-200 bg-rose-50 hover:bg-rose-100 px-2.5 py-1.5 text-xs font-bold text-rose-900 transition">
                    Export PDF
                </a>
                <a href="{{ route('admin.evaluation.examens.groupes-virtuels.exporter', array_merge($queryActuelle, ['format' => 'html'])) }}"
                   target="_blank"
                   class="inline-flex items-center gap-1 rounded-lg border border-indigo-200 bg-indigo-50 hover:bg-indigo-100 px-2.5 py-1.5 text-xs font-bold text-indigo-900 transition">
                    HTML imprimable
                </a>
                <a href="{{ route('admin.evaluation.examens.groupes-virtuels.pack-zip', $queryActuelle) }}"
                   title="Télécharger l'ensemble des feuilles d'émargement PDF de la sélection dans une archive ZIP"
                   class="inline-flex items-center gap-1.5 rounded-lg border border-purple-200 bg-purple-50 hover:bg-purple-100 px-2.5 py-1.5 text-xs font-bold text-purple-900 transition">
                    <svg class="h-3.5 w-3.5 text-purple-700" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M20.25 7.5l-.625 10.632a2.25 2.25 0 01-2.247 2.118H6.622a2.25 2.25 0 01-2.247-2.118L3.75 7.5M10 11.25h4M3.375 7.5h17.25c.621 0 1.125-.504 1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125z" />
                    </svg>
                    Pack émargements (ZIP)
                </a>
                <a href="{{ route('admin.evaluation.examens.groupes-virtuels.imprimer', array_merge($queryActuelle, ['document' => 'synthese_matiere'])) }}"
                   target="_blank"
                   class="inline-flex items-center gap-1 rounded-lg border border-slate-300 bg-white hover:bg-slate-50 px-2.5 py-1.5 text-xs font-semibold text-slate-700 transition">
                    Imprimer synthèse par matière
                </a>
                <a href="{{ route('admin.evaluation.examens.groupes-virtuels.imprimer', array_merge($queryActuelle, ['document' => 'synthese_session'])) }}"
                   target="_blank"
                   class="inline-flex items-center gap-1 rounded-lg border border-slate-300 bg-white hover:bg-slate-50 px-2.5 py-1.5 text-xs font-semibold text-slate-700 transition">
                    Imprimer synthèse session
                </a>
            </div>
        </div>
    </div>

    {{-- Tableau principal des groupes virtuels --}}
    <div class="rounded-2xl border border-slate-200/80 bg-white p-6 shadow-xs">
        <div class="flex items-center justify-between mb-4">
            <div>
                <h3 class="font-bold text-sm text-slate-900">Liste des groupes virtuels d'examen</h3>
                <p class="text-xs text-slate-500 mt-0.5">
                    Chaque ligne présente le périmètre académique, les groupes d'origine agrégés, l'effectif éligible et les statuts distincts de génération, validation et publication.
                </p>
            </div>
            <span class="text-xs font-semibold text-slate-600">
                {{ count($lignes) }} affiché(s) sur {{ $paginator->total() }}
            </span>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead>
                    <tr class="border-b border-slate-200 text-[11px] font-bold uppercase tracking-wider text-slate-500">
                        <th class="pb-3 pl-1">Groupe virtuel</th>
                        <th class="pb-3">Année & Session</th>
                        <th class="pb-3">Faculté / Programme / Niveau</th>
                        <th class="pb-3">Matière examinée</th>
                        <th class="pb-3">Groupes d'origine</th>
                        <th class="pb-3 text-center">Salle & Jauge D-04</th>
                        <th class="pb-3 text-center">Étudiants éligibles</th>
                        <th class="pb-3">Génération / Validation / Publication</th>
                        <th class="pb-3">Dates</th>
                        <th class="pb-3 pr-1 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse($lignes as $r)
                        <tr class="hover:bg-slate-50/70 transition align-top">
                            <td class="py-3.5 pl-1 text-xs">
                                <div class="flex items-center gap-1.5">
                                    <span class="font-mono font-bold text-indigo-900 bg-indigo-50 px-2 py-0.5 rounded-md ring-1 ring-indigo-200">
                                        #{{ $r['id'] }}
                                    </span>
                                    <a href="{{ route('admin.evaluation.examens.groupes-virtuels.show', $r['id']) }}"
                                       class="font-bold text-slate-900 hover:text-indigo-700 hover:underline">
                                        {{ $r['libelle'] }}
                                    </a>
                                </div>
                                <div class="mt-1 font-mono text-[10px] text-slate-400">
                                    Clé : {{ $r['cle_courte'] }}… · Épreuve : {{ $r['epreuve_code'] }}
                                </div>
                                @if(!$r['conforme'] || $r['en_derive'])
                                    <div class="mt-1 inline-flex items-center gap-1 rounded-md bg-rose-50 px-2 py-0.5 text-[11px] font-bold text-rose-800 ring-1 ring-rose-200">
                                        {{ count($r['alertes']) }} alerte(s) de correspondance
                                    </div>
                                @endif
                            </td>

                            <td class="py-3.5 text-xs">
                                <div class="font-semibold text-slate-900">{{ $r['annee_libelle'] }}</div>
                                <div class="text-slate-600">{{ $r['session_code'] }} — {{ $r['session_nom'] }}</div>
                            </td>

                            <td class="py-3.5 text-xs">
                                <div class="font-semibold text-slate-800">{{ $r['faculte_nom'] }}</div>
                                <div class="text-slate-500">
                                    Prog. <span class="font-mono font-semibold">{{ $r['programme_code'] }}</span>
                                    · Niv. <span class="font-mono font-semibold">{{ $r['niveau_code'] }}</span>
                                    @if(!empty($r['filiere']))
                                        · {{ $r['filiere'] }}
                                    @endif
                                </div>
                            </td>

                            <td class="py-3.5 text-xs">
                                <span class="inline-flex items-center rounded-md bg-slate-100 px-2 py-0.5 font-mono text-[11px] font-bold text-slate-800">
                                    {{ $r['matiere_code'] }}
                                </span>
                                <div class="mt-0.5 font-semibold text-slate-900">{{ $r['matiere_nom'] }}</div>
                            </td>

                            <td class="py-3.5 text-xs">
                                <div class="flex flex-wrap gap-1">
                                    @forelse($r['groupes_sources'] as $gs)
                                        <span class="inline-flex items-center rounded-md bg-indigo-50 px-2 py-0.5 font-mono text-[11px] font-semibold text-indigo-900 ring-1 ring-indigo-200"
                                              title="{{ $gs['nom'] }}">
                                            {{ $gs['code'] }}
                                        </span>
                                    @empty
                                        <span class="text-slate-400">—</span>
                                    @endforelse
                                </div>
                            </td>

                            <td class="py-3.5 text-center text-xs">
                                @if(!empty($r['salle_code']))
                                    <a href="{{ route('admin.evaluation.examens.groupes', ['mode' => 'B', 'epreuve_id' => $r['epreuve_id']]) }}"
                                       title="Salle {{ $r['salle_nom'] ?? $r['salle_code'] }} (Capacité : {{ $r['salle_capacite'] ?? '—' }} places) — Ouvrir D-04 Mode B"
                                       class="inline-flex items-center gap-1 rounded-md bg-purple-50 hover:bg-purple-100 px-2 py-0.5 font-mono text-[11px] font-bold text-purple-900 ring-1 ring-purple-200 transition">
                                        🏛️ {{ $r['salle_code'] }}
                                    </a>
                                    <div class="mt-1 text-[10px] {{ ($r['salle_capacite'] ?? 0) >= $r['effectif_eligible'] ? 'text-emerald-700 font-semibold' : 'text-rose-600 font-bold' }}">
                                        Cap. {{ $r['salle_capacite'] ?? '—' }} pl.
                                    </div>
                                @else
                                    <a href="{{ route('admin.evaluation.examens.groupes', ['mode' => 'B', 'epreuve_id' => $r['epreuve_id']]) }}"
                                       title="Aucune salle assignée — Ouvrir la répartition capacitaire D-04 Mode B"
                                       class="inline-flex items-center gap-1 rounded-md bg-amber-50 hover:bg-amber-100 px-2 py-0.5 text-[10px] font-bold text-amber-800 ring-1 ring-amber-200 transition">
                                        Non assignée →
                                    </a>
                                @endif
                            </td>

                            <td class="py-3.5 text-center text-xs">
                                <span class="inline-flex items-center rounded-lg bg-emerald-50 px-2.5 py-1 font-black text-emerald-900 ring-1 ring-emerald-200">
                                    {{ $r['effectif_eligible'] }} éligible(s)
                                </span>
                                <div class="mt-1 text-[11px] text-slate-500">
                                    Attendus : {{ $r['effectif_attendu'] }} · Exclus : {{ $r['effectif_exclu'] }}
                                </div>
                            </td>

                            <td class="py-3.5 text-xs space-y-1">
                                <div>
                                    <span class="inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-bold {{ $r['statut_generation'] === 'propose' ? 'bg-blue-100 text-blue-800' : ($r['statut_generation'] === 'remplace' ? 'bg-slate-100 text-slate-700' : 'bg-rose-100 text-rose-800') }}">
                                        {{ $r['statut_generation_libelle'] }}
                                    </span>
                                </div>
                                <div>
                                    <span class="inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-bold {{ $r['statut_validation'] === 'valide' ? 'bg-emerald-100 text-emerald-800' : ($r['statut_validation'] === 'derive' ? 'bg-rose-100 text-rose-800' : 'bg-amber-100 text-amber-800') }}">
                                        {{ $r['statut_validation_libelle'] }}
                                    </span>
                                </div>
                                <div>
                                    <span class="inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-bold {{ $r['statut_publication'] === 'publie' ? 'bg-indigo-100 text-indigo-800' : 'bg-slate-100 text-slate-600' }}">
                                        {{ $r['statut_publication_libelle'] }}
                                    </span>
                                </div>
                            </td>

                            <td class="py-3.5 text-xs text-slate-600">
                                <div><span class="text-slate-400">Généré :</span> {{ $r['date_generation'] }}</div>
                                <div>
                                    <span class="text-slate-400">Validé :</span>
                                    {{ $r['date_validation'] ?? 'Non validé' }}
                                </div>
                            </td>

                            <td class="py-3.5 pr-1 text-right text-xs">
                                <div class="flex flex-col items-end gap-1.5">
                                    <div class="flex items-center gap-1.5">
                                        @if($r['en_derive'])
                                            <form method="POST" action="{{ route('admin.evaluation.examens.groupes-virtuels.groupe.resynchroniser', $r['id']) }}" class="inline">
                                                @csrf
                                                <button type="submit"
                                                        onclick="return confirm('Régénérer et réactualiser ce groupe virtuel avec la population éligible actuelle ?')"
                                                        title="Actualiser la proposition et archiver la version obsolète"
                                                        class="inline-flex items-center gap-1 rounded-lg bg-rose-600 hover:bg-rose-700 px-2 py-1 text-[11px] font-bold text-white shadow-2xs transition cursor-pointer">
                                                    <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor">
                                                        <path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0 3.181 3.183a8.25 8.25 0 0 0 13.803-3.7M4.031 9.865a8.25 8.25 0 0 1 13.803-3.7l3.181 3.182m0-4.991v4.99" />
                                                    </svg>
                                                    Re-synchroniser
                                                </button>
                                            </form>
                                        @endif
                                        <a href="{{ route('admin.evaluation.examens.groupes', ['mode' => 'B', 'epreuve_id' => $r['epreuve_id']]) }}"
                                           title="Répartition capacitaire D-04 Mode B (Salles & Amphis)"
                                           class="inline-flex items-center gap-1 rounded-lg border border-purple-200 bg-purple-50 hover:bg-purple-100 px-2 py-1 text-[11px] font-bold text-purple-900 shadow-2xs transition">
                                            Salles D-04
                                        </a>
                                        <a href="{{ route('admin.evaluation.examens.groupes-virtuels.show', $r['id']) }}"
                                           class="inline-flex items-center gap-1 rounded-lg bg-primary-600 hover:bg-primary-700 px-2.5 py-1 text-[11px] font-bold text-white shadow-2xs transition">
                                            Détails & alertes
                                        </a>
                                    </div>
                                    <div class="flex flex-wrap justify-end gap-1">
                                        <a href="{{ route('admin.evaluation.examens.groupes-virtuels.groupe.exporter', [$r['id'], 'xlsx']) }}"
                                           title="Exporter la liste des étudiants en XLSX"
                                           class="rounded border border-emerald-200 bg-emerald-50 px-1.5 py-0.5 text-[10px] font-bold text-emerald-800 hover:bg-emerald-100">
                                            XLSX
                                        </a>
                                        <a href="{{ route('admin.evaluation.examens.groupes-virtuels.groupe.exporter', [$r['id'], 'csv']) }}"
                                           title="Exporter la liste des étudiants en CSV"
                                           class="rounded border border-slate-200 bg-slate-50 px-1.5 py-0.5 text-[10px] font-bold text-slate-700 hover:bg-slate-100">
                                            CSV
                                        </a>
                                        <a href="{{ route('admin.evaluation.examens.groupes-virtuels.groupe.exporter', [$r['id'], 'pdf']) }}"
                                           title="Exporter en PDF"
                                           class="rounded border border-rose-200 bg-rose-50 px-1.5 py-0.5 text-[10px] font-bold text-rose-800 hover:bg-rose-100">
                                            PDF
                                        </a>
                                        <a href="{{ route('admin.evaluation.examens.groupes-virtuels.groupe.imprimer', [$r['id'], 'fiche']) }}"
                                           target="_blank"
                                           title="Imprimer la fiche du groupe virtuel"
                                           class="rounded border border-indigo-200 bg-indigo-50 px-1.5 py-0.5 text-[10px] font-bold text-indigo-800 hover:bg-indigo-100">
                                            Imprimer fiche
                                        </a>
                                        <a href="{{ route('admin.evaluation.examens.groupes-virtuels.groupe.imprimer', [$r['id'], 'etudiants']) }}"
                                           target="_blank"
                                           title="Imprimer la liste nominative des étudiants"
                                           class="rounded border border-slate-300 bg-white px-1.5 py-0.5 text-[10px] font-semibold text-slate-700 hover:bg-slate-50">
                                            Imprimer étudiants
                                        </a>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="10" class="py-12 text-center text-sm text-slate-500">
                                <div class="max-w-md mx-auto text-center space-y-3">
                                    <div class="inline-flex h-12 w-12 items-center justify-center rounded-2xl bg-indigo-50 text-indigo-500">
                                        <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M18 18.72a9.094 9.094 0 0 0 3.741-.479 3 3 0 0 0-4.682-2.72m.94 3.198.001.031c0 .225-.012.447-.037.666A11.944 11.944 0 0 1 12 21c-2.17 0-4.207-.576-5.963-1.584A6.062 6.062 0 0 1 6 18.719m12 0a5.971 5.971 0 0 0-.941-3.197m0 0A5.995 5.995 0 0 0 12 12.75a5.995 5.995 0 0 0-5.058 2.772m0 0a3 3 0 0 0-4.681 2.72 8.986 8.986 0 0 0 3.74.477m.94-3.197a5.971 5.971 0 0 0-.94 3.197M15 6.75a3 3 0 1 1-6 0 3 3 0 0 1 6 0Zm6 3a2.25 2.25 0 1 1-4.5 0 2.25 2.25 0 0 1 4.5 0Zm-13.5 0a2.25 2.25 0 1 1-4.5 0 2.25 2.25 0 0 1 4.5 0Z" />
                                        </svg>
                                    </div>
                                    <h4 class="font-bold text-slate-800">Aucun groupe virtuel d'examen</h4>
                                    <p class="text-xs text-slate-500">
                                        Aucun regroupement virtuel ne correspond aux filtres actifs ou les groupes virtuels n'ont pas encore été générés pour cette session.
                                    </p>
                                    <form method="POST" action="{{ route('admin.evaluation.examens.groupes-virtuels.generer-automatique', request()->query()) }}">
                                        @csrf
                                        <button type="submit" class="inline-flex items-center gap-1.5 rounded-xl bg-indigo-600 hover:bg-indigo-700 px-4 py-2 text-xs font-bold text-white shadow-xs transition">
                                            Détecter & générer les groupes virtuels
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if($paginator->hasPages())
            <div class="mt-4 pt-3 border-t border-slate-100">
                {{ $paginator->links() }}
            </div>
        @endif
    </div>
</div>
@endsection

