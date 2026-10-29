@extends('layouts.app')

@section('title', 'Examens — Enterprise Command Center')

@section('content')
@php
    $sessionsEnCours = $sessionsStats['open'] + $sessionsStats['ongoing'];
    $totalConflitsCritiques = $matrice_conflits['totaux']['critiques'] ?? 0;
    $totalConflitsAvertissements = $matrice_conflits['totaux']['avertissements'] ?? 0;
    $pointsAttention = $totalConflitsCritiques + $totalConflitsAvertissements + $sallesSansCapacite + $epreuvesSansSurveillant;
@endphp

<div class="space-y-6">
    {{-- En-tête de page Enterprise --}}
    <x-page-header
        titre="Centre de Commandement des Examens"
        description="Pilotage Enterprise des sessions, gouvernance à 4 yeux, détection des conflits et gouvernance des 12 menus."
        :fil="['Évaluation' => route('admin.evaluations.index'), 'Examens' => route('admin.evaluation.examens.tableau-de-bord'), 'Command Center' => '']"
    >
        <x-slot:actions>
            <div class="flex flex-wrap items-center gap-2">
                <a href="{{ route('admin.evaluation.examens.simulation', ['annee_id' => $anneeId]) }}" class="inline-flex items-center gap-2 rounded-xl border border-slate-200 bg-white px-3.5 py-2 text-sm font-semibold text-slate-700 shadow-xs transition-colors hover:bg-slate-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-500/50">
                    <svg class="h-4 w-4 text-sky-600" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9.75 3.104v5.714a2.25 2.25 0 0 1-.659 1.591L5 14.5M9.75 3.104c-.251.023-.501.05-.75.082m.75-.082a24.301 24.301 0 0 1 4.5 0m0 0v5.714c0 .597.237 1.17.659 1.591L19.8 15.3M14.25 3.104c.251.023.501.05.75.082M19.8 15.3l-1.57.393A9.065 9.065 0 0 1 12 15a9.065 9.065 0 0 1-6.23-.693L5 14.5m14.8.8 1.402 1.402c1.232 1.232.65 3.318-1.067 3.611A48.309 48.309 0 0 1 12 21c-2.773 0-5.491-.235-8.135-.687-1.718-.293-2.3-2.379-1.067-3.61L5 14.5" /></svg>
                    Simulateur de scénarios
                </a>
                <a href="{{ route('admin.evaluation.examens.sessions', ['annee_id' => $anneeId]) }}" class="inline-flex items-center gap-2 rounded-xl bg-primary-800 px-4 py-2 text-sm font-semibold text-white shadow-xs transition-colors hover:bg-primary-900 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-500/50">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                    Gérer les sessions
                </a>
            </div>
        </x-slot:actions>
    </x-page-header>

    {{-- Barre de navigation des 12 menus --}}
    @include('modules.evaluation.examens-advanced._nav-tabs')

    {{-- Bandeau de pilotage & Métadonnées de fiabilité --}}
    <div class="examens-card flex flex-wrap items-center justify-between gap-4 rounded-2xl border border-slate-200/80 bg-white p-4 shadow-xs">
        <div class="flex flex-wrap items-center gap-4">
            <span class="text-sm font-bold text-slate-800">Année universitaire</span>
            <form method="GET"
                  action="{{ route('admin.evaluation.examens.tableau-de-bord') }}"
                  class="flex items-center gap-2"
                  x-data="{ actualisation: false }"
                  @submit="actualisation = true">
                <div class="relative">
                    <label for="filtre-annee" class="sr-only">Filtrer par année universitaire</label>
                    <select id="filtre-annee"
                            name="annee_id"
                            onchange="this.form.requestSubmit ? this.form.requestSubmit() : this.form.submit()"
                            :class="actualisation ? 'opacity-60' : ''"
                            class="appearance-none rounded-xl border border-slate-200 bg-white py-1.5 pl-3 pr-9 text-sm font-medium text-slate-800 transition-colors hover:border-slate-300 focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500/20">
                        @foreach($annees as $annee)
                            <option value="{{ $annee->id }}" @selected($anneeId === (int)$annee->id)>
                                {{ $annee->libelle }} @if($annee->active) (En cours) @endif
                            </option>
                        @endforeach
                    </select>
                    <svg class="pointer-events-none absolute right-2.5 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" />
                    </svg>
                </div>
                <span x-show="actualisation" hidden class="text-xs font-medium text-slate-500">Actualisation…</span>
            </form>

            {{-- Badge Indice de Fiabilité --}}
            <div class="inline-flex items-center gap-2 rounded-xl px-3 py-1 text-xs font-semibold {{ $fiabilite['statut'] === 'fiable' ? 'bg-emerald-50 text-emerald-800 border border-emerald-200' : ($fiabilite['statut'] === 'partiel' ? 'bg-amber-50 text-amber-800 border border-amber-200' : 'bg-rose-50 text-rose-800 border border-rose-200') }}">
                <span class="inline-block h-2 w-2 rounded-full {{ $fiabilite['statut'] === 'fiable' ? 'bg-emerald-500' : ($fiabilite['statut'] === 'partiel' ? 'bg-amber-500' : 'bg-rose-500') }}"></span>
                <span>Fiabilité données : {{ $fiabilite['indice'] }}% ({{ ucfirst($fiabilite['statut']) }})</span>
            </div>
        </div>

        <div class="flex items-center gap-3 text-xs text-slate-500">
            <span class="examens-pulse inline-flex h-2 w-2 shrink-0 rounded-full bg-emerald-500" aria-hidden="true"></span>
            <span>Calculé à : <time datetime="{{ $horodatage->toIso8601String() }}">{{ $horodatage->format('d/m/Y H:i') }}</time></span>
        </div>
    </div>

    {{-- Synthèse des métriques clés Enterprise --}}
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
        {{-- KPI 1 : Sessions totales --}}
        <article class="examens-card examens-kpi rounded-2xl border border-slate-200/80 bg-white p-5 shadow-xs" data-motion-block>
            <div class="flex items-center justify-between gap-3">
                <span class="text-xs font-semibold uppercase tracking-wider text-slate-500">Sessions d'examen</span>
                <span class="inline-flex h-8 w-8 items-center justify-center rounded-xl bg-blue-50 text-blue-600 ring-1 ring-inset ring-blue-100" aria-hidden="true">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 0 1 2.25-2.25h13.5A2.25 2.25 0 0 1 21 7.5v11.25m-18 0A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75m-18 0v-7.5A2.25 2.25 0 0 1 5.25 9h13.5A2.25 2.25 0 0 1 21 11.25v7.5" /></svg>
                </span>
            </div>
            <div class="mt-3 flex items-baseline gap-2">
                <span class="examens-kpi-valeur text-3xl font-black text-slate-900" data-motion-count="{{ $sessionsStats['total'] }}" aria-hidden="true">{{ $sessionsStats['total'] }}</span>
                <span class="sr-only">{{ $sessionsStats['total'] }} sessions</span>
                <span class="text-xs text-slate-500">dont {{ $sessionsEnCours }} actives</span>
            </div>
            <div class="mt-3 flex flex-wrap gap-2 text-[11px] text-slate-600">
                <span class="rounded bg-slate-100 px-2 py-0.5">{{ $sessionsStats['draft'] }} brouillons</span>
                <span class="rounded bg-emerald-50 text-emerald-700 px-2 py-0.5">{{ $sessionsStats['open'] + $sessionsStats['ongoing'] }} en cours</span>
                <span class="rounded bg-slate-100 px-2 py-0.5">{{ $sessionsStats['locked'] }} closes</span>
            </div>
        </article>

        {{-- KPI 2 : Épreuves & Planification --}}
        <article class="examens-card examens-kpi rounded-2xl border border-slate-200/80 bg-white p-5 shadow-xs" data-motion-block>
            <div class="flex items-center justify-between gap-3">
                <span class="text-xs font-semibold uppercase tracking-wider text-slate-500">Épreuves &amp; Planning</span>
                <span class="inline-flex h-8 w-8 items-center justify-center rounded-xl bg-indigo-50 text-indigo-600 ring-1 ring-inset ring-indigo-100" aria-hidden="true">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12h3.75M9 15h3.75M9 18h3.75m3 .75H18a2.25 2.25 0 0 0 2.25-2.25V6.108c0-1.135-.845-2.098-1.976-2.192a48.424 48.424 0 0 0-1.123-.08m-5.801 0c-.065.21-.1.433-.1.664 0 .414.336.75.75.75h4.5a.75.75 0 0 0 .75-.75 2.25 2.25 0 0 0-.1-.664m-5.8 0A2.251 2.251 0 0 1 13.5 2.25H15c1.012 0 1.867.668 2.15 1.586m-5.8 0c-.376.023-.75.05-1.124.08C9.095 4.01 8.25 4.973 8.25 6.108V8.25m0 0H4.875c-.621 0-1.125.504-1.125 1.125v11.25c0 .621.504 1.125 1.125 1.125h9.75c.621 0 1.125-.504 1.125-1.125V9.375c0-.621-.504-1.125-1.125-1.125H8.25ZM6.75 12h.008v.008H6.75V12Zm0 3h.008v.008H6.75V15Zm0 3h.008v.008H6.75V18Z" /></svg>
                </span>
            </div>
            <div class="mt-3 flex items-baseline gap-2">
                <span class="examens-kpi-valeur text-3xl font-black text-slate-900" data-motion-count="{{ $kpis['epreuves']['total'] }}" aria-hidden="true">{{ $kpis['epreuves']['total'] }}</span>
                <span class="sr-only">{{ $kpis['epreuves']['total'] }} épreuves</span>
                <span class="text-xs text-slate-500">épreuves au total</span>
            </div>
            <div class="mt-3 flex flex-wrap gap-2 text-[11px] text-slate-600">
                <span class="rounded bg-emerald-50 px-2 py-0.5 text-emerald-700">{{ $kpis['epreuves']['planifiees'] }} planifiées</span>
                <span class="rounded bg-rose-50 px-2 py-0.5 text-rose-700">{{ $kpis['epreuves']['sans_salle'] }} sans salle</span>
            </div>
        </article>

        {{-- KPI 3 : Candidatures & Salles --}}
        <article class="examens-card examens-kpi rounded-2xl border border-slate-200/80 bg-white p-5 shadow-xs" data-motion-block>
            <div class="flex items-center justify-between gap-3">
                <span class="text-xs font-semibold uppercase tracking-wider text-slate-500">Candidatures &amp; Capacité</span>
                <span class="inline-flex h-8 w-8 items-center justify-center rounded-xl bg-violet-50 text-violet-600 ring-1 ring-inset ring-violet-100" aria-hidden="true">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 0 0 2.625.372 9.337 9.337 0 0 0 4.121-.952 4.125 4.125 0 0 0-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 0 1 8.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0 1 11.964-3.07M12 6.375a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0Zm8.25 2.25a2.625 2.625 0 1 1-5.25 0 2.625 2.625 0 0 1 5.25 0Z" /></svg>
                </span>
            </div>
            <div class="mt-3 flex items-baseline gap-2">
                <span class="examens-kpi-valeur text-3xl font-black text-slate-900" data-motion-count="{{ $kpis['candidats']['total_candidatures'] }}" aria-hidden="true">{{ number_format($kpis['candidats']['total_candidatures'], 0, ',', ' ') }}</span>
                <span class="sr-only">{{ number_format($kpis['candidats']['total_candidatures'], 0, ',', ' ') }} candidatures</span>
                <span class="text-xs text-slate-500">candidatures</span>
            </div>
            <div class="mt-3 flex flex-wrap gap-2 text-[11px] text-slate-600">
                <span class="rounded bg-slate-100 px-2 py-0.5">{{ $kpis['salles']['total'] }} salles</span>
                <span class="rounded bg-sky-50 text-sky-700 px-2 py-0.5">{{ $diagnostic_salles['capacite_utile_totale'] }} places utiles</span>
            </div>
        </article>

        {{-- KPI 4 : Alertes & Points de blocage --}}
        <article class="examens-card examens-kpi rounded-2xl border p-5 shadow-xs {{ $totalConflitsCritiques > 0 ? 'border-rose-200/80 bg-rose-50/40' : ($pointsAttention > 0 ? 'border-amber-200/80 bg-amber-50/30' : 'border-emerald-200/70 bg-emerald-50/30') }}" data-motion-block>
            <div class="flex items-center justify-between gap-3">
                <span class="text-xs font-semibold uppercase tracking-wider {{ $totalConflitsCritiques > 0 ? 'text-rose-700' : ($pointsAttention > 0 ? 'text-amber-700' : 'text-emerald-700') }}">Gouvernance &amp; Alertes</span>
                <span class="inline-flex h-8 w-8 items-center justify-center rounded-xl ring-1 ring-inset {{ $totalConflitsCritiques > 0 ? 'bg-rose-100 text-rose-600 ring-rose-200' : ($pointsAttention > 0 ? 'bg-amber-100 text-amber-700 ring-amber-200' : 'bg-emerald-100 text-emerald-700 ring-emerald-200') }}" aria-hidden="true">
                    @if($totalConflitsCritiques > 0)
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z" /></svg>
                    @elseif($pointsAttention > 0)
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 7.5h.008v.008H12v-.008Z" /></svg>
                    @else
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" /></svg>
                    @endif
                </span>
            </div>
            <div class="mt-3 flex items-baseline gap-2">
                <span class="examens-kpi-valeur text-3xl font-black {{ $totalConflitsCritiques > 0 ? 'text-rose-600' : ($pointsAttention > 0 ? 'text-amber-700' : 'text-emerald-700') }}" data-motion-count="{{ $totalConflitsCritiques }}" aria-hidden="true">{{ $totalConflitsCritiques }}</span>
                <span class="sr-only">{{ $totalConflitsCritiques }} conflits bloquants</span>
                <span class="text-xs font-medium {{ $totalConflitsCritiques > 0 ? 'text-rose-600' : 'text-slate-600' }}">
                    {{ $totalConflitsCritiques > 0 ? "conflit(s) bloquant(s)" : ($pointsAttention > 0 ? "avertissement(s) opérationnel(s)" : "aucun blocage") }}
                </span>
            </div>
            <div class="mt-3 text-[11px] {{ $totalConflitsCritiques > 0 ? 'text-rose-700/90 font-medium' : 'text-slate-500' }}">
                @if($totalConflitsCritiques > 0)
                    Publication interdite tant que les contraintes dures ne sont pas résolues.
                @else
                    Workflow 4 yeux actif : validation administrative requise.
                @endif
            </div>
        </article>
    </div>

    {{-- SECTION 1 : SCORE DE PRÉPARATION DES SESSIONS (LOT 2) --}}
    <section class="examens-card rounded-2xl border border-slate-200/80 bg-white p-6 shadow-xs" aria-labelledby="titre-scores-preparation">
        <div class="mb-5 flex flex-wrap items-center justify-between gap-3">
            <div>
                <h2 id="titre-scores-preparation" class="flex items-center gap-2 text-base font-bold text-slate-900">
                    <svg class="h-5 w-5 text-primary-600" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 13.5l10.5-11.25L12 10.5h8.25L9.75 21.75 12 13.5H3.75z" /></svg>
                    Scores de Préparation des Sessions &amp; Éligibilité Opérationnelle
                </h2>
                <p class="mt-1 text-xs text-slate-500">Évaluation multi-dimensionnelle (D1-D7) garantissant qu'aucun planning non conforme ne peut être publié.</p>
            </div>
            <span class="rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-600">
                {{ count($scores_sessions) }} session(s) évaluée(s)
            </span>
        </div>

        <div class="space-y-4">
            @forelse($scores_sessions as $itemScore)
                @php
                    $session = $itemScore['session'];
                    $score = $itemScore['score_global'];
                    $statut = $itemScore['statut_eligibilite'];
                @endphp
                <div class="rounded-xl border border-slate-200/90 bg-slate-50/40 p-4 transition-all hover:bg-white hover:shadow-xs">
                    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 pb-3">
                        <div class="flex items-center gap-3">
                            <span class="text-sm font-bold text-slate-900">{{ $session->nom }}</span>
                            <span class="rounded bg-slate-200/80 px-2 py-0.5 text-xs uppercase font-medium text-slate-700">{{ $session->type }}</span>
                            <span class="text-xs text-slate-500">
                                Du {{ $session->date_debut?->format('d/m/Y') }} au {{ $session->date_fin?->format('d/m/Y') }}
                            </span>
                        </div>
                        <div class="flex items-center gap-2">
                            {{-- Badge d'éligibilité opérationnelle --}}
                            @if($statut === 'ELIGIBLE')
                                <span class="inline-flex items-center gap-1.5 rounded-full bg-emerald-100 px-3 py-0.5 text-xs font-bold text-emerald-800">
                                    <svg class="h-3.5 w-3.5 text-emerald-600" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.857-9.809a.75.75 0 00-1.214-.882l-3.483 4.79-1.88-1.88a.75.75 0 10-1.06 1.061l2.5 2.5a.75.75 0 001.137-.089l4-5.5z" clip-rule="evenodd" /></svg>
                                    ÉLIGIBLE À LA PUBLICATION
                                </span>
                            @elseif($statut === 'BLOQUE')
                                <span class="inline-flex items-center gap-1.5 rounded-full bg-rose-100 px-3 py-0.5 text-xs font-bold text-rose-800">
                                    <svg class="h-3.5 w-3.5 text-rose-600" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.28 7.22a.75.75 0 00-1.06 1.06L8.94 10l-1.72 1.72a.75.75 0 101.06 1.06L10 11.06l1.72 1.72a.75.75 0 101.06-1.06L11.06 10l1.72-1.72a.75.75 0 00-1.06-1.06L10 8.94 8.28 7.22z" clip-rule="evenodd" /></svg>
                                    BLOQUÉ (CONTRAINTES DURES)
                                </span>
                            @else
                                <span class="inline-flex items-center gap-1.5 rounded-full bg-amber-100 px-3 py-0.5 text-xs font-bold text-amber-800">
                                    <svg class="h-3.5 w-3.5 text-amber-600" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M8.485 2.495c.673-1.167 2.357-1.167 3.03 0l6.28 10.875c.673 1.167-.17 2.625-1.516 2.625H3.72c-1.347 0-2.189-1.458-1.515-2.625L8.485 2.495zM10 5a.75.75 0 01.75.75v3.5a.75.75 0 01-1.5 0v-3.5A.75.75 0 0110 5zm0 9a1 1 0 100-2 1 1 0 000 2z" clip-rule="evenodd" /></svg>
                                    À COMPLÉTER
                                </span>
                            @endif

                            {{-- Jauge de score global --}}
                            <span class="rounded-lg bg-white px-2.5 py-1 text-xs font-black text-slate-800 ring-1 ring-slate-200">
                                Score : {{ $score }}/100
                            </span>
                        </div>
                    </div>

                    {{-- Dimensions D1 à D7 --}}
                    <div class="mt-3 grid grid-cols-2 gap-2 sm:grid-cols-4 lg:grid-cols-7 text-xs">
                        @foreach($itemScore['dimensions'] as $dimCode => $dim)
                            <div class="rounded-lg bg-white p-2.5 border border-slate-200/60 shadow-2xs">
                                <div class="flex items-center justify-between text-slate-500 font-medium">
                                    <span>{{ $dimCode }}</span>
                                    <span class="font-bold text-slate-800">{{ $dim['score'] }}/{{ $dim['max'] }}</span>
                                </div>
                                <div class="mt-1 h-1.5 w-full rounded-full bg-slate-100 overflow-hidden">
                                    <div class="h-full rounded-full {{ $dim['score'] === $dim['max'] ? 'bg-emerald-500' : ($dim['score'] > 0 ? 'bg-indigo-500' : 'bg-rose-400') }}" style="width: {{ $dim['max'] > 0 ? ($dim['score'] / $dim['max']) * 100 : 0 }}%"></div>
                                </div>
                                <p class="mt-1.5 truncate text-[10px] text-slate-500" title="{{ $dim['libelle'] }}">{{ $dim['libelle'] }}</p>
                            </div>
                        @endforeach
                    </div>

                    {{-- Anomalies bloquantes & Recommandations --}}
                    @if(!empty($itemScore['bloquants']) || !empty($itemScore['avertissements']))
                        <div class="mt-3 flex flex-wrap items-center gap-2 pt-2 border-t border-slate-100 text-xs">
                            @foreach($itemScore['bloquants'] as $bloquant)
                                <span class="inline-flex items-center gap-1 rounded bg-rose-50 px-2 py-0.5 font-medium text-rose-700">
                                    <svg class="h-3 w-3 shrink-0" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.28 7.22a.75.75 0 00-1.06 1.06L8.94 10l-1.72 1.72a.75.75 0 101.06 1.06L10 11.06l1.72 1.72a.75.75 0 101.06-1.06L11.06 10l1.72-1.72a.75.75 0 00-1.06-1.06L10 8.94 8.28 7.22z" clip-rule="evenodd" /></svg>
                                    {{ $bloquant }}
                                </span>
                            @endforeach
                            @foreach($itemScore['avertissements'] as $avertissement)
                                <span class="inline-flex items-center gap-1 rounded bg-amber-50 px-2 py-0.5 font-medium text-amber-800">
                                    <svg class="h-3 w-3 shrink-0" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M8.485 2.495c.673-1.167 2.357-1.167 3.03 0l6.28 10.875c.673 1.167-.17 2.625-1.516 2.625H3.72c-1.347 0-2.189-1.458-1.515-2.625L8.485 2.495zM10 5a.75.75 0 01.75.75v3.5a.75.75 0 01-1.5 0v-3.5A.75.75 0 0110 5zm0 9a1 1 0 100-2 1 1 0 000 2z" clip-rule="evenodd" /></svg>
                                    {{ $avertissement }}
                                </span>
                            @endforeach
                        </div>
                    @endif
                </div>
            @empty
                <div class="py-8 text-center text-sm text-slate-500">
                    Aucune session enregistrée pour l'année universitaire sélectionnée.
                </div>
            @endforelse
        </div>
    </section>

    {{-- SECTION 2 : MATRICE DE DÉTECTION DES CONFLITS (LOT 3) --}}
    <section class="examens-card rounded-2xl border border-slate-200/80 bg-white p-6 shadow-xs"
             aria-labelledby="titre-matrice-conflits"
             x-data="{ ongletFamille: 'tous' }">
        <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
            <div>
                <h2 id="titre-matrice-conflits" class="flex items-center gap-2 text-base font-bold text-slate-900">
                    <svg class="h-5 w-5 text-rose-600" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z" /></svg>
                    Matrice Intelligente des Conflits de Planification
                </h2>
                <p class="mt-1 text-xs text-slate-500">Détection croisée en 4 familles : Salles, Candidats, Surveillance et Calendrier.</p>
            </div>

            {{-- Filtres par famille de conflit --}}
            <div class="flex flex-wrap items-center gap-1 rounded-xl bg-slate-100 p-1 text-xs font-semibold">
                <button type="button" @click="ongletFamille = 'tous'" :class="ongletFamille === 'tous' ? 'bg-white text-slate-900 shadow-2xs' : 'text-slate-600 hover:text-slate-900'" class="rounded-lg px-2.5 py-1 transition-colors">
                    Tous ({{ $matrice_conflits['totaux']['total'] }})
                </button>
                <button type="button" @click="ongletFamille = 'salles'" :class="ongletFamille === 'salles' ? 'bg-white text-slate-900 shadow-2xs' : 'text-slate-600 hover:text-slate-900'" class="rounded-lg px-2.5 py-1 transition-colors">
                    Salles ({{ $matrice_conflits['totaux']['salles'] }})
                </button>
                <button type="button" @click="ongletFamille = 'candidats'" :class="ongletFamille === 'candidats' ? 'bg-white text-slate-900 shadow-2xs' : 'text-slate-600 hover:text-slate-900'" class="rounded-lg px-2.5 py-1 transition-colors">
                    Candidats ({{ $matrice_conflits['totaux']['candidats'] }})
                </button>
                <button type="button" @click="ongletFamille = 'surveillants'" :class="ongletFamille === 'surveillants' ? 'bg-white text-slate-900 shadow-2xs' : 'text-slate-600 hover:text-slate-900'" class="rounded-lg px-2.5 py-1 transition-colors">
                    Surveillance ({{ $matrice_conflits['totaux']['surveillants'] }})
                </button>
                <button type="button" @click="ongletFamille = 'planning'" :class="ongletFamille === 'planning' ? 'bg-white text-slate-900 shadow-2xs' : 'text-slate-600 hover:text-slate-900'" class="rounded-lg px-2.5 py-1 transition-colors">
                    Planning ({{ $matrice_conflits['totaux']['planning'] }})
                </button>
            </div>
        </div>

        <div class="overflow-x-auto">
            <table class="examens-tableau w-full text-left text-sm">
                <thead>
                    <tr class="border-b border-slate-200 text-xs font-semibold uppercase tracking-wider text-slate-500">
                        <th scope="col" class="pb-3">Sévérité</th>
                        <th scope="col" class="pb-3">Famille &amp; Règle</th>
                        <th scope="col" class="pb-3">Diagnostic &amp; Description</th>
                        <th scope="col" class="pb-3">Objet concerné</th>
                        <th scope="col" class="pb-3 text-right">Résolution</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse($matrice_conflits['liste_complete'] as $conflit)
                        <tr x-show="ongletFamille === 'tous' || ongletFamille === '{{ $conflit['famille'] }}'" class="hover:bg-slate-50/60 transition-colors">
                            <td class="py-3 pr-3 whitespace-nowrap">
                                @if($conflit['gravite'] === 'CRITICAL')
                                    <span class="inline-flex items-center gap-1 rounded-full bg-rose-100 px-2 py-0.5 text-xs font-bold text-rose-800">
                                        BLOQUANT
                                    </span>
                                @else
                                    <span class="inline-flex items-center gap-1 rounded-full bg-amber-100 px-2 py-0.5 text-xs font-bold text-amber-800">
                                        AVERTISSEMENT
                                    </span>
                                @endif
                            </td>
                            <td class="py-3 pr-3 text-xs">
                                <span class="font-bold text-slate-800 capitalize">{{ $conflit['famille'] }}</span>
                                <span class="block text-[10px] text-slate-500 font-mono">{{ $conflit['regle_violee'] }}</span>
                            </td>
                            <td class="py-3 pr-3">
                                <span class="font-semibold text-slate-900 text-xs block">{{ $conflit['titre'] }}</span>
                                <span class="text-xs text-slate-600">{{ $conflit['description'] }}</span>
                            </td>
                            <td class="py-3 pr-3 text-xs font-mono text-slate-600 whitespace-nowrap">
                                #{{ $conflit['epreuve_code'] ?? $conflit['epreuve_id'] }}
                            </td>
                            <td class="py-3 text-right whitespace-nowrap">
                                <a href="{{ route($conflit['menu_resolution'], ['annee_id' => $anneeId]) }}" class="inline-flex items-center gap-1 rounded-lg bg-primary-50 px-2.5 py-1 text-xs font-bold text-primary-700 hover:bg-primary-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-500/40">
                                    {{ $conflit['action_label'] }}
                                    <span aria-hidden="true">&rarr;</span>
                                </a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="py-8 text-center text-sm text-slate-500">
                                <svg class="mx-auto h-8 w-8 text-emerald-500" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" /></svg>
                                <p class="mt-2 font-semibold text-slate-700">Aucun conflit détecté</p>
                                <p class="text-xs text-slate-500">Toutes les contraintes d'horaires, salles, capacités et surveillances sont satisfaites.</p>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    {{-- SECTION 3 : DIAGNOSTIC D'ALLOCATION & SATURATION DES SALLES (LOT 4) --}}
    <section class="examens-card rounded-2xl border border-slate-200/80 bg-white p-6 shadow-xs" aria-labelledby="titre-diagnostic-salles">
        <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
            <div>
                <h2 id="titre-diagnostic-salles" class="flex items-center gap-2 text-base font-bold text-slate-900">
                    <svg class="h-5 w-5 text-indigo-600" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 21h16.5M4.5 3h15M5.25 3v18m13.5-18v18M9 6.75h1.5m-1.5 3h1.5m-1.5 3h1.5m3-6H15m-1.5 3H15m-1.5 3H15M9 21v-3.375c0-.621.504-1.125 1.125-1.125h3.75c.621 0 1.125.504 1.125 1.125V21" /></svg>
                    Capacité d'Accueil Examen &amp; Répartition par Bâtiment
                </h2>
                <p class="mt-1 text-xs text-slate-500">Capacités calculées avec ratio d'espacement réglementaire (50% de la capacité nominale).</p>
            </div>
            <a href="{{ route('admin.evaluation.examens.salles', ['annee_id' => $anneeId]) }}" class="inline-flex items-center gap-1 text-xs font-semibold text-primary-700 hover:underline">
                Gérer le parc de salles
                <span aria-hidden="true">&rarr;</span>
            </a>
        </div>

        <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
            @forelse($diagnostic_salles['par_batiment'] as $batiment)
                <div class="rounded-xl border border-slate-200/90 bg-slate-50/50 p-4">
                    <div class="flex items-center justify-between gap-2 border-b border-slate-200/60 pb-2">
                        <span class="font-bold text-slate-900 text-sm">{{ $batiment['nom'] }}</span>
                        <span class="rounded bg-slate-200/80 px-2 py-0.5 text-xs text-slate-700 font-semibold">{{ $batiment['actives'] }}/{{ $batiment['total_salles'] }} salles actives</span>
                    </div>
                    <div class="mt-3 space-y-1.5 text-xs text-slate-600">
                        <div class="flex justify-between">
                            <span>Capacité examen utile :</span>
                            <span class="font-bold text-slate-800">{{ $batiment['capacite_utile'] }} places</span>
                        </div>
                        <div class="flex justify-between">
                            <span>Capacité nominale brute :</span>
                            <span>{{ $batiment['capacite_nominale'] }} places</span>
                        </div>
                        @if($batiment['sans_capacite'] > 0)
                            <div class="flex justify-between text-amber-700 font-medium">
                                <span>Capacités non vérifiées :</span>
                                <span>{{ $batiment['sans_capacite'] }} salle(s)</span>
                            </div>
                        @endif
                    </div>
                </div>
            @empty
                <div class="col-span-3 py-6 text-center text-sm text-slate-500">
                    Aucun bâtiment configuré pour ce périmètre.
                </div>
            @endforelse
        </div>
    </section>

    {{-- SECTION 4 : GOUVERNANCE À 4 YEUX & WORKFLOW (LOT 6) --}}
    <section class="examens-card rounded-2xl border border-slate-200/80 bg-white p-6 shadow-xs" aria-labelledby="titre-gouvernance-workflow">
        <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
            <div>
                <h2 id="titre-gouvernance-workflow" class="flex items-center gap-2 text-base font-bold text-slate-900">
                    <svg class="h-5 w-5 text-teal-600" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75m-2.18-4.99-2.222 2.222m0 0a9 9 0 1 0 12.728 12.728A9 9 0 0 0 12 3v0z" /></svg>
                    Gouvernance des Plannings &amp; Workflow à 4 Yeux
                </h2>
                <p class="mt-1 text-xs text-slate-500">Règle impérative : une proposition calculée n'est jamais publiée sans double validation administrative.</p>
            </div>
            <span class="inline-flex items-center gap-1 rounded-full bg-teal-50 px-3 py-1 text-xs font-bold text-teal-800 border border-teal-200">
                <span class="h-1.5 w-1.5 rounded-full bg-teal-500"></span>
                Anti-auto-approbation active
            </span>
        </div>

        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <div class="rounded-xl border border-slate-200 bg-slate-50/50 p-4">
                <span class="text-xs font-medium text-slate-500">Propositions en brouillon</span>
                <p class="mt-2 text-2xl font-black text-slate-800">{{ $gouvernance['propositions_en_cours']['epreuves_brouillon'] }}</p>
                <span class="mt-1 block text-[11px] text-slate-500">En cours de préparation</span>
            </div>
            <div class="rounded-xl border border-slate-200 bg-slate-50/50 p-4">
                <span class="text-xs font-medium text-slate-500">Ordonnancées (en attente)</span>
                <p class="mt-2 text-2xl font-black text-amber-600">{{ $gouvernance['propositions_en_cours']['epreuves_planifiees_a_valider'] }}</p>
                <span class="mt-1 block text-[11px] text-slate-500">Prêtes pour la 2nde validation</span>
            </div>
            <div class="rounded-xl border border-slate-200 bg-slate-50/50 p-4">
                <span class="text-xs font-medium text-slate-500">Épreuves publiées</span>
                <p class="mt-2 text-2xl font-black text-emerald-600">{{ $gouvernance['propositions_en_cours']['epreuves_publiees'] }}</p>
                <span class="mt-1 block text-[11px] text-slate-500">Visibles sur le portail étudiant</span>
            </div>
            <div class="rounded-xl border border-slate-200 bg-slate-50/50 p-4">
                <span class="text-xs font-medium text-slate-500">Sessions en projet</span>
                <p class="mt-2 text-2xl font-black text-slate-700">{{ $gouvernance['propositions_en_cours']['sessions_brouillon'] }}</p>
                <span class="mt-1 block text-[11px] text-slate-500">À valider formellement</span>
            </div>
        </div>
    </section>

    {{-- SECTION 5 : HUB D'INTÉGRATION DES 12 MENUS EXAMENS ADVANCED (LOT 7) --}}
    <section class="examens-card rounded-2xl border border-slate-200/80 bg-white p-6 shadow-xs" aria-labelledby="titre-hub-12-menus">
        <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
            <div>
                <h2 id="titre-hub-12-menus" class="flex items-center gap-2 text-base font-bold text-slate-900">
                    <svg class="h-5 w-5 text-primary-600" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6A2.25 2.25 0 0 1 6 3.75h2.25A2.25 2.25 0 0 1 10.5 6v2.25a2.25 2.25 0 0 1-2.25 2.25H6a2.25 2.25 0 0 1-2.25-2.25V6ZM3.75 15.75A2.25 2.25 0 0 1 6 13.5h2.25a2.25 2.25 0 0 1 2.25 2.25V18a2.25 2.25 0 0 1-2.25 2.25H6A2.25 2.25 0 0 1 3.75 18v-2.25ZM13.5 6a2.25 2.25 0 0 1 2.25-2.25H18A2.25 2.25 0 0 1 20.25 6v2.25A2.25 2.25 0 0 1 18 10.5h-2.25a2.25 2.25 0 0 1-2.25-2.25V6ZM13.5 15.75a2.25 2.25 0 0 1 2.25-2.25H18a2.25 2.25 0 0 1 2.25 2.25V18A2.25 2.25 0 0 1 18 20.25h-2.25A2.25 2.25 0 0 1 13.5 18v-2.25Z" /></svg>
                    Hub de Navigation Contextuelle des 12 Menus Examens Advanced
                </h2>
                <p class="mt-1 text-xs text-slate-500">Accès direct aux fonctionnalités spécialisées avec conservation automatique du contexte d'année universitaire.</p>
            </div>
        </div>

        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 md:grid-cols-3 xl:grid-cols-4">
            @foreach($grille_menus as $menu)
                <a href="{{ $menu['route'] }}" class="group relative rounded-xl border border-slate-200/80 bg-white p-4 transition-all hover:border-primary-300 hover:shadow-xs focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-500/40">
                    <div class="flex items-center justify-between gap-2">
                        <span class="inline-flex h-6 w-6 items-center justify-center rounded-lg bg-slate-100 text-xs font-black text-slate-700">
                            {{ $menu['numero'] }}
                        </span>
                        <span class="rounded bg-slate-100 px-2 py-0.5 text-[11px] font-semibold text-slate-600">
                            {{ $menu['statut_badge'] }}
                        </span>
                    </div>
                    <h3 class="mt-2.5 text-sm font-bold text-slate-900 group-hover:text-primary-700 transition-colors">
                        {{ $menu['nom'] }}
                    </h3>
                    <p class="mt-1 text-xs text-slate-500 line-clamp-2">
                        {{ $menu['description'] }}
                    </p>
                    <div class="mt-3 flex items-center justify-between border-t border-slate-100 pt-2 text-[11px] text-slate-600">
                        <span class="font-medium text-slate-700">{{ $menu['indicateur'] }}</span>
                        <span class="font-semibold text-primary-700 group-hover:translate-x-0.5 transition-transform" aria-hidden="true">&rarr;</span>
                    </div>
                </a>
            @endforeach
        </div>
    </section>
</div>
@endsection
