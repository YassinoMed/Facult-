@extends('layouts.app')

@section('title', 'Examens — Groupes d\'examen & Cohortes')

@section('content')

@php
    $mode = $mode ?? 'A';

    // Données Mode A (Groupes pédagogiques)
    $totalGroupes = $kpisGroupes['total_groupes'] ?? $groupes->total();
    $effectifTotal = $kpisGroupes['effectif_total'] ?? 0;
    $capaciteTotale = $kpisGroupes['capacite_totale'] ?? 0;
    $groupesSansCapacite = $kpisGroupes['groupes_sans_capacite'] ?? 0;
    $groupesSurcharges = $kpisGroupes['groupes_surcharges'] ?? 0;
    $tauxMoyen = $kpisGroupes['taux_occupation_moyen'] ?? 0;

    // Données Mode B (Amphithéâtres & Décision D-04)
    $kpisModeB = $donneesModeB['kpis'] ?? [];
    $totalAmphis = $kpisModeB['total_amphis'] ?? 0;
    $capBruteAmphis = $kpisModeB['capacite_brute_amphis'] ?? 0;
    $capUtileD04 = $kpisModeB['capacite_utile_d04'] ?? 0;
    $cohortesEstimees = $kpisModeB['cohortes_estimees'] ?? 0;
    $amphisBloquants = $kpisModeB['amphis_bloquants'] ?? 0;
    $tauxMoyenD04 = $kpisModeB['taux_occupation_moyen_d04'] ?? 0;
    $amphitheatres = $donneesModeB['amphitheatres'] ?? [];
    $cohortesModeB = $donneesModeB['cohortes_mode_b'] ?? [];
@endphp

<div class="space-y-6">
    <x-page-header
        titre="Groupes d'examen & Cohortes"
        description="Organisation des candidats : Mode A (groupes pédagogiques de référence) et Mode B (regroupement spécifique aux examens selon les capacités de salles)."
        :fil="['Évaluation' => route('admin.evaluations.index'), 'Examens' => route('admin.evaluation.examens.tableau-de-bord'), 'Groupes' => '']"
    />

    @include('modules.evaluation.examens-advanced._nav-tabs')

    {{-- Accès direct aux groupes virtuels d'examen calculés --}}
    <div class="rounded-2xl border-2 border-indigo-300 bg-gradient-to-r from-indigo-50/90 via-white to-indigo-50/50 p-5 shadow-xs flex flex-wrap items-center justify-between gap-4">
        <div class="flex items-start gap-3.5">
            <span class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-indigo-600 text-white shadow-xs">
                <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M18 18.72a9.094 9.094 0 0 0 3.741-.479 3 3 0 0 0-4.682-2.72m.94 3.198.001.031c0 .225-.012.447-.037.666A11.944 11.944 0 0 1 12 21c-2.17 0-4.207-.576-5.963-1.584A6.062 6.062 0 0 1 6 18.719m12 0a5.971 5.971 0 0 0-.941-3.197m0 0A5.995 5.995 0 0 0 12 12.75a5.995 5.995 0 0 0-5.058 2.772m0 0a3 3 0 0 0-4.681 2.72 8.986 8.986 0 0 0 3.74.477m.94-3.197a5.971 5.971 0 0 0-.94 3.197M15 6.75a3 3 0 1 1-6 0 3 3 0 0 1 6 0Zm6 3a2.25 2.25 0 1 1-4.5 0 2.25 2.25 0 0 1 4.5 0Zm-13.5 0a2.25 2.25 0 1 1-4.5 0 2.25 2.25 0 0 1 4.5 0Z" />
                </svg>
            </span>
            <div>
                <div class="flex flex-wrap items-center gap-2">
                    <h3 class="text-sm font-bold text-indigo-950">Groupes virtuels des examens finaux</h3>
                    <span class="inline-flex rounded-full bg-indigo-100 text-indigo-800 px-2.5 py-0.5 text-[11px] font-bold">
                        Correspondance stricte Matière ↔ Groupes ↔ Étudiants
                    </span>
                </div>
                <p class="mt-1 text-xs text-slate-600 max-w-3xl">
                    Consultez les regroupements virtuels calculés pour les examens, affichez leurs étudiants éligibles, exportez les données en multi-formats (XLSX, CSV, PDF, HTML) et imprimez les fiches officielles et feuilles d'émargement.
                </p>
            </div>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <a href="{{ route('admin.evaluation.examens.groupes-virtuels.index', request()->query()) }}"
               class="inline-flex items-center gap-1.5 rounded-xl bg-indigo-600 hover:bg-indigo-700 px-4 py-2.5 text-xs font-bold text-white shadow-xs hover:shadow-sm transition">
                Accéder aux groupes virtuels →
            </a>
            <a href="{{ route('admin.evaluation.examens.groupes-virtuels.exporter', array_merge(request()->query(), ['format' => 'xlsx'])) }}"
               class="inline-flex items-center gap-1 rounded-xl border border-emerald-200 bg-emerald-50 hover:bg-emerald-100 px-3 py-2 text-xs font-bold text-emerald-900 transition">
                Export XLSX
            </a>
            <a href="{{ route('admin.evaluation.examens.groupes-virtuels.exporter', array_merge(request()->query(), ['format' => 'csv'])) }}"
               class="inline-flex items-center gap-1 rounded-xl border border-slate-200 bg-slate-50 hover:bg-slate-100 px-3 py-2 text-xs font-bold text-slate-800 transition">
                Export CSV
            </a>
        </div>
    </div>

    {{-- Sélecteur interactif Mode A / Mode B --}}
    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        {{-- Carte Mode A --}}
        <a href="{{ route('admin.evaluation.examens.groupes', array_merge(request()->query(), ['mode' => 'A'])) }}"
           class="group relative block rounded-2xl border-2 transition-all duration-200 p-5 shadow-xs hover:shadow-md {{ $mode === 'A' ? 'border-primary-600 bg-gradient-to-br from-primary-50/60 via-white to-white ring-4 ring-primary-500/10' : 'border-slate-200/90 bg-white hover:border-slate-300' }}">
            <div class="flex items-center justify-between">
                <span class="inline-flex items-center gap-1.5 rounded-lg px-2.5 py-1 text-xs font-bold tracking-wide {{ $mode === 'A' ? 'bg-primary-600 text-white shadow-xs' : 'bg-slate-100 text-slate-700' }}">
                    @if($mode === 'A')
                        <span class="h-1.5 w-1.5 rounded-full bg-white animate-pulse"></span>
                    @endif
                    Mode A Actif
                </span>
                <span class="text-xs font-semibold {{ $mode === 'A' ? 'text-primary-700' : 'text-slate-400' }}">Par défaut</span>
            </div>
            <h3 class="mt-3 font-bold text-base text-slate-900 group-hover:text-primary-700 transition">Groupes pédagogiques de référence</h3>
            <p class="mt-1 text-xs leading-relaxed text-slate-600">
                Les groupes de travaux dirigés / cours magistraux servent de structure de convocation nominale.
                Les candidats de chaque épreuve sont validés individuellement par rapport à leur inscription au module.
            </p>
            <div class="mt-4 flex items-center gap-2 text-xs font-semibold {{ $mode === 'A' ? 'text-primary-700' : 'text-slate-500' }}">
                <span>{{ $totalGroupes }} groupes répertoriés</span>
                <span>•</span>
                <span>{{ $effectifTotal }} étudiants</span>
            </div>
        </a>

        {{-- Carte Mode B (Amphithéâtres & Décision D-04) --}}
        <a href="{{ route('admin.evaluation.examens.groupes', array_merge(request()->query(), ['mode' => 'B'])) }}"
           class="group relative block rounded-2xl border-2 transition-all duration-200 p-5 shadow-xs hover:shadow-md {{ $mode === 'B' ? 'border-indigo-600 bg-gradient-to-br from-indigo-50/60 via-white to-white ring-4 ring-indigo-500/10' : 'border-slate-200/90 bg-white hover:border-slate-300' }}">
            <div class="flex items-center justify-between">
                <span class="inline-flex items-center gap-1.5 rounded-lg px-2.5 py-1 text-xs font-bold tracking-wide {{ $mode === 'B' ? 'bg-indigo-600 text-white shadow-xs' : 'bg-slate-100 text-slate-700' }}">
                    @if($mode === 'B')
                        <span class="h-1.5 w-1.5 rounded-full bg-white animate-pulse"></span>
                    @endif
                    Mode B Optionnel
                </span>
                <span class="text-xs font-semibold {{ $mode === 'B' ? 'text-indigo-700' : 'text-slate-400' }}">Amphithéâtres</span>
            </div>
            <h3 class="mt-3 font-bold text-base text-slate-900 group-hover:text-indigo-700 transition">Regroupement spécifique aux examens (Décision D-04)</h3>
            <p class="mt-1 text-xs leading-relaxed text-slate-600">
                Fusionne les étudiants éligibles de plusieurs groupes pour constituer des cohortes temporaires optimisées,
                réparties selon la capacité utile d'examen (ratio d'espacement standard à 50% anti-fraude).
            </p>
            <div class="mt-4 flex items-center gap-2 text-xs font-semibold {{ $mode === 'B' ? 'text-indigo-700' : 'text-slate-500' }}">
                <span>{{ $totalAmphis }} amphithéâtres</span>
                <span>•</span>
                <span class="text-indigo-700 font-bold">{{ $capUtileD04 }} places utiles (D-04)</span>
            </div>
        </a>
    </div>

    @if($mode === 'B')
        {{-- ========================================================================= --}}
        {{-- VUE MODE B : AMPHITHÉÂTRES & REGROUPEMENT D-04 (50% JAUGE ANTI-FRAUDE)   --}}
        {{-- ========================================================================= --}}

        {{-- Bandeau réglementaire Décision D-04 --}}
        <div class="relative overflow-hidden rounded-2xl border border-indigo-200 bg-gradient-to-r from-indigo-50 via-white to-indigo-50/40 p-5 shadow-xs">
            <div class="flex items-start gap-4">
                <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-indigo-600 text-white shadow-sm ring-4 ring-indigo-100">
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m0-10.036A11.959 11.959 0 0 1 3.598 6 11.99 11.99 0 0 0 3 9.75c0 5.592 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.57-.598-3.75h-.002A11.959 11.959 0 0 1 12 2.714ZM12 15.75h.007v.008H12v-.008Z" />
                    </svg>
                </div>
                <div class="flex-1">
                    <div class="flex flex-wrap items-center gap-2">
                        <h4 class="font-bold text-sm text-indigo-950">Règle de capacité opposable (Décision D-04 & Règles R-17/R-18)</h4>
                        <span class="inline-flex items-center rounded-full bg-indigo-100 text-indigo-800 px-2 py-0.5 text-[11px] font-bold">
                            Ratio 50% Anti-Fraude
                        </span>
                        <span class="inline-flex items-center rounded-full bg-emerald-100 text-emerald-800 px-2 py-0.5 text-[11px] font-semibold">
                            Espacement Opposable
                        </span>
                    </div>
                    <p class="mt-1 text-xs leading-relaxed text-slate-700">
                        En Mode B, les cohortes d'examen sont dimensionnées exclusivement selon la <strong>capacité utile d'examen</strong> :
                        <code class="bg-indigo-100/70 text-indigo-900 px-1.5 py-0.5 rounded font-mono font-bold text-xs">Capacité Utile = ⌊Capacité Physique × 0.50⌋</code> (un siège libre entre deux candidats).
                        Toute salle sans capacité nominale déclarée est bloquante et interdite d'affectation.
                    </p>
                </div>
            </div>
        </div>

        {{-- Cartes KPI Mode B --}}
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
            {{-- KPI 1 : Amphithéâtres Mobilisables --}}
            <div class="relative overflow-hidden rounded-2xl border border-indigo-200/80 bg-gradient-to-br from-indigo-50/70 via-white to-white p-5 shadow-xs transition hover:shadow-sm">
                <div class="flex items-center justify-between">
                    <span class="text-[11px] font-bold uppercase tracking-wider text-indigo-800">Amphithéâtres</span>
                    <span class="inline-flex h-8 w-8 items-center justify-center rounded-xl bg-indigo-100 text-indigo-800 ring-4 ring-indigo-50">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 21h16.5M4.5 3h15M5.25 3v18m13.5-18v18M9 6.75h1.5m-1.5 3h1.5m-1.5 3h1.5m3-6H15m-1.5 3H15m-1.5 3H15M9 21v-3.375c0-.621.504-1.125 1.125-1.125h3.75c.621 0 1.125.504 1.125 1.125V21" />
                        </svg>
                    </span>
                </div>
                <div class="mt-3 flex items-baseline gap-2">
                    <span class="text-3xl font-black tracking-tight text-slate-900">{{ $totalAmphis }}</span>
                    <span class="text-xs font-semibold text-indigo-800">amphis</span>
                </div>
                <p class="mt-1 text-xs text-slate-500">Capacité brute totale : {{ $capBruteAmphis }} places</p>
            </div>

            {{-- KPI 2 : Capacité Utile Examen (D-04) --}}
            <div class="relative overflow-hidden rounded-2xl border border-emerald-200/80 bg-gradient-to-br from-emerald-50/70 via-white to-white p-5 shadow-xs transition hover:shadow-sm">
                <div class="flex items-center justify-between">
                    <span class="text-[11px] font-bold uppercase tracking-wider text-emerald-800">Capacité Utile (D-04)</span>
                    <span class="inline-flex h-8 w-8 items-center justify-center rounded-xl bg-emerald-100 text-emerald-800 ring-4 ring-emerald-50">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                        </svg>
                    </span>
                </div>
                <div class="mt-3 flex items-baseline gap-2">
                    <span class="text-3xl font-black tracking-tight text-slate-900">{{ $capUtileD04 }}</span>
                    <span class="text-xs font-semibold text-emerald-700">places examen</span>
                </div>
                <p class="mt-1 text-xs text-slate-500">1 place sur 2 opposable (50% jauge)</p>
            </div>

            {{-- KPI 3 : Cohortes Spécifiques Constitués --}}
            <div class="relative overflow-hidden rounded-2xl border border-primary-200/80 bg-gradient-to-br from-primary-50/70 via-white to-white p-5 shadow-xs transition hover:shadow-sm">
                <div class="flex items-center justify-between">
                    <span class="text-[11px] font-bold uppercase tracking-wider text-primary-800">Cohortes Amphi</span>
                    <span class="inline-flex h-8 w-8 items-center justify-center rounded-xl bg-primary-100 text-primary-800 ring-4 ring-primary-50">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M18 18.72a9.094 9.094 0 0 0 3.741-.479 3 3 0 0 0-4.682-2.72m.94 3.198.001.031c0 .225-.012.447-.037.666A11.944 11.944 0 0 1 12 21c-2.17 0-4.207-.576-5.963-1.584A6.062 6.062 0 0 1 6 18.719m12 0a5.971 5.971 0 0 0-.941-3.197m0 0A5.995 5.995 0 0 0 12 12.75a5.995 5.995 0 0 0-5.058 2.772m0 0a3 3 0 0 0-4.681 2.72 8.986 8.986 0 0 0 3.74.477m.94-3.197a5.971 5.971 0 0 0-.94 3.197M15 6.75a3 3 0 1 1-6 0 3 3 0 0 1 6 0Zm6 3a2.25 2.25 0 1 1-4.5 0 2.25 2.25 0 0 1 4.5 0Zm-13.5 0a2.25 2.25 0 1 1-4.5 0 2.25 2.25 0 0 1 4.5 0Z" />
                        </svg>
                    </span>
                </div>
                <div class="mt-3 flex items-baseline gap-2">
                    <span class="text-3xl font-black tracking-tight text-slate-900">{{ $cohortesEstimees }}</span>
                    <span class="text-xs font-semibold text-primary-800">cohortes D-04</span>
                </div>
                <p class="mt-1 text-xs text-slate-500">Regroupements multi-TD optimisés</p>
            </div>

            {{-- KPI 4 : Points d'attention D-04 --}}
            <div class="relative overflow-hidden rounded-2xl border {{ $amphisBloquants > 0 ? 'border-rose-300 bg-rose-50/60' : 'border-slate-200 bg-white' }} p-5 shadow-xs transition hover:shadow-sm">
                <div class="flex items-center justify-between">
                    <span class="text-[11px] font-bold uppercase tracking-wider {{ $amphisBloquants > 0 ? 'text-rose-800' : 'text-slate-600' }}">Conformité D-04</span>
                    <span class="inline-flex h-8 w-8 items-center justify-center rounded-xl {{ $amphisBloquants > 0 ? 'bg-rose-100 text-rose-800 ring-4 ring-rose-50' : 'bg-slate-100 text-slate-700 ring-4 ring-slate-50' }}">
                        @if($amphisBloquants > 0)
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" /></svg>
                        @else
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" /></svg>
                        @endif
                    </span>
                </div>
                <div class="mt-3 flex items-baseline gap-2">
                    <span class="text-3xl font-black tracking-tight {{ $amphisBloquants > 0 ? 'text-rose-900' : 'text-slate-900' }}">{{ $amphisBloquants }}</span>
                    <span class="text-xs font-semibold {{ $amphisBloquants > 0 ? 'text-rose-800' : 'text-slate-500' }}">amphi bloquant</span>
                </div>
                <p class="mt-1 text-xs text-slate-500">{{ $amphisBloquants > 0 ? 'Capacité nulle interdisant la planification' : 'Tous les amphithéâtres sont étalonnés' }}</p>
            </div>
        </div>

        {{-- Barre de recherche Mode B --}}
        <div class="flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-slate-200/80 bg-white p-3.5 shadow-xs">
            <form method="GET" action="{{ route('admin.evaluation.examens.groupes') }}" class="flex flex-wrap items-center gap-2.5 flex-1 min-w-[280px]">
                <input type="hidden" name="mode" value="B" />
                <div class="relative flex-1 min-w-[200px] max-w-sm">
                    <input type="text" name="recherche" value="{{ $recherche }}" placeholder="Rechercher amphithéâtre, code, bâtiment…" 
                           class="w-full rounded-xl border border-slate-200 bg-white pl-8 pr-3 py-1.5 text-xs text-slate-800 placeholder-slate-400 focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500" />
                    <svg class="pointer-events-none absolute left-2.5 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-slate-400" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z" />
                    </svg>
                </div>

                <button type="submit" class="rounded-xl bg-indigo-600 hover:bg-indigo-700 px-3.5 py-1.5 text-xs font-semibold text-white shadow-xs transition">
                    Filtrer Amphis
                </button>

                @if($recherche)
                    <a href="{{ route('admin.evaluation.examens.groupes', ['mode' => 'B']) }}" class="text-xs font-medium text-rose-600 hover:underline px-1">
                        Réinitialiser
                    </a>
                @endif
            </form>

            <span class="text-xs font-medium text-slate-500">
                <span class="font-bold tabular-nums text-slate-800">{{ count($amphitheatres) }}</span> amphithéâtre{{ count($amphitheatres) > 1 ? 's' : '' }}
            </span>
        </div>

        {{-- Tableau 1 : Amphithéâtres et Jauges Utiles Opposables (D-04) --}}
        <div class="rounded-2xl border border-slate-200/80 bg-white p-6 shadow-xs">
            <div class="flex items-center justify-between mb-4">
                <div>
                    <h3 class="font-bold text-sm text-slate-900">Amphithéâtres mobilisables & Jauges Utiles (Décision D-04)</h3>
                    <p class="text-xs text-slate-500 mt-0.5">Application systématique de la règle anti-fraude à 50% sur l'ensemble des grandes salles d'examen.</p>
                </div>
                <span class="inline-flex items-center gap-1.5 rounded-lg bg-indigo-50 text-indigo-800 px-2.5 py-1 text-xs font-bold ring-1 ring-indigo-200">
                    <svg class="h-3.5 w-3.5 text-indigo-600" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" /></svg>
                    Capacité utile examen (50% D-04)
                </span>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead>
                        <tr class="border-b border-slate-100 text-[11px] font-bold uppercase tracking-wider text-slate-500">
                            <th class="pb-3 pl-1">Amphithéâtre</th>
                            <th class="pb-3">Localisation</th>
                            <th class="pb-3 text-center">Capacité Physique</th>
                            <th class="pb-3 text-center bg-indigo-50/50 rounded-t-lg">Capacité Utile Examen (D-04)</th>
                            <th class="pb-3 text-center">Espacement Anti-Fraude</th>
                            <th class="pb-3 pr-1 text-right">Statut Réglementaire</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @forelse($amphitheatres as $amphi)
                            <tr class="hover:bg-slate-50/60 transition">
                                <td class="py-3.5 pl-1 font-mono font-bold text-slate-900 text-xs">
                                    <span class="inline-flex items-center rounded-lg bg-indigo-50 text-indigo-900 px-2.5 py-1 text-xs font-bold ring-1 ring-indigo-200">
                                        {{ $amphi['code'] }}
                                    </span>
                                    <span class="block mt-0.5 font-sans font-medium text-slate-800 text-xs">{{ $amphi['nom'] }}</span>
                                </td>
                                <td class="py-3.5 text-slate-600 text-xs font-medium">
                                    <span class="text-slate-900 font-semibold">{{ $amphi['batiment'] }}</span>
                                    <span class="block text-[11px] text-slate-400">Étage {{ $amphi['etage'] }}</span>
                                </td>
                                <td class="py-3.5 text-center text-xs text-slate-600">
                                    <span class="font-semibold text-slate-800">{{ $amphi['capacite_nominale'] }}</span> places brutes
                                </td>
                                <td class="py-3.5 text-center text-xs bg-indigo-50/30">
                                    <span class="inline-flex items-center gap-1.5 rounded-lg bg-emerald-100 text-emerald-900 px-3 py-1 font-black text-xs shadow-2xs">
                                        {{ $amphi['capacite_utile_d04'] }} places opposables
                                    </span>
                                </td>
                                <td class="py-3.5 text-center text-xs">
                                    <span class="inline-flex items-center rounded-md bg-slate-100 px-2 py-0.5 text-[11px] font-semibold text-slate-700">
                                        1 place / 2 (50%)
                                    </span>
                                </td>
                                <td class="py-3.5 pr-1 text-right text-xs">
                                    @if($amphi['est_bloquante'])
                                        <span class="inline-flex items-center gap-1 text-rose-700 font-bold">
                                            <svg class="h-3.5 w-3.5 text-rose-500" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008Z" /></svg>
                                            Bloquante (0 place)
                                        </span>
                                    @else
                                        <span class="inline-flex items-center gap-1 text-emerald-700 font-bold">
                                            <svg class="h-3.5 w-3.5 text-emerald-500" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" /></svg>
                                            Conforme D-04
                                        </span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="py-8 text-center text-sm text-slate-500">
                                    Aucun amphithéâtre n'est répertorié ou ne correspond à la recherche.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        {{-- Tableau 2 : Cohortes Virtuelles d'Amphithéâtre (Regroupements D-04) --}}
        <div class="rounded-2xl border border-slate-200/80 bg-white p-6 shadow-xs">
            <div class="flex items-center justify-between mb-4">
                <div>
                    <h3 class="font-bold text-sm text-slate-900">Cohortes d'examen spécifiques en Amphithéâtre (Regroupement D-04)</h3>
                    <p class="text-xs text-slate-500 mt-0.5">Fusion des groupes de référence (TD/TP) calibrée sur la capacité utile de chaque amphithéâtre.</p>
                </div>
                <span class="text-xs font-semibold text-slate-600">
                    {{ count($cohortesModeB) }} cohorte{{ count($cohortesModeB) > 1 ? 's' : '' }} optimisée{{ count($cohortesModeB) > 1 ? 's' : '' }}
                </span>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead>
                        <tr class="border-b border-slate-100 text-[11px] font-bold uppercase tracking-wider text-slate-500">
                            <th class="pb-3 pl-1">Code Cohorte Mode B</th>
                            <th class="pb-3">Amphithéâtre Assigné</th>
                            <th class="pb-3">Groupes TD Sources Fusionnés</th>
                            <th class="pb-3 text-center">Effectif Convoqué</th>
                            <th class="pb-3 text-center">Capacité Utile (D-04)</th>
                            <th class="pb-3">Remplissage Utile</th>
                            <th class="pb-3 pr-1 text-right">Statut Cohorte</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @forelse($cohortesModeB as $cohorte)
                            <tr class="hover:bg-slate-50/60 transition">
                                <td class="py-3.5 pl-1 font-mono font-bold text-indigo-900 text-xs">
                                    <span class="inline-flex items-center rounded-lg bg-indigo-50 text-indigo-950 px-2 py-0.5 text-xs font-bold ring-1 ring-indigo-200">
                                        {{ $cohorte['code_cohorte'] }}
                                    </span>
                                </td>
                                <td class="py-3.5 text-xs font-semibold text-slate-900">
                                    {{ $cohorte['amphi_nom'] }}
                                    <span class="block font-mono text-[11px] text-slate-400">{{ $cohorte['amphi_code'] }}</span>
                                </td>
                                <td class="py-3.5 text-xs text-slate-700">
                                    <span class="inline-flex items-center rounded-md bg-slate-100 px-2 py-0.5 text-[11px] font-medium text-slate-800">
                                        {{ $cohorte['groupes_sources'] }}
                                    </span>
                                </td>
                                <td class="py-3.5 text-center text-xs font-bold text-slate-900">
                                    {{ $cohorte['effectif_convoque'] }} étudiants
                                </td>
                                <td class="py-3.5 text-center text-xs font-bold text-emerald-800">
                                    {{ $cohorte['capacite_utile_d04'] }} places D-04
                                </td>
                                <td class="py-3.5">
                                    <div class="flex items-center gap-2 max-w-xs">
                                        <div class="w-24 bg-slate-100 rounded-full h-2 overflow-hidden">
                                            <div class="h-2 rounded-full {{ $cohorte['taux_remplissage'] > 100 ? 'bg-rose-500' : ($cohorte['taux_remplissage'] >= 80 ? 'bg-emerald-500' : 'bg-primary-500') }}"
                                                 style="width: {{ min(100, $cohorte['taux_remplissage']) }}%"></div>
                                        </div>
                                        <span class="text-xs font-semibold text-slate-700">{{ $cohorte['taux_remplissage'] }}%</span>
                                    </div>
                                </td>
                                <td class="py-3.5 pr-1 text-right text-xs">
                                    <span class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[11px] font-bold {{ $cohorte['statut'] === 'Surcharge D-04' ? 'bg-rose-100 text-rose-800' : 'bg-emerald-100 text-emerald-800' }}">
                                        {{ $cohorte['statut'] }}
                                    </span>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="py-8 text-center text-sm text-slate-500">
                                    Aucune cohorte spécifique d'amphithéâtre n'a encore été constituée. Créez des amphithéâtres étalonnés pour lancer la simulation.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        {{-- ========================================================================= --}}
        {{-- DÉCISION D-04 V2 : CAPACITÉ UTILE, PERMUTATIONS ET RÉAFFECTATIONS         --}}
        {{-- ========================================================================= --}}
        <div class="rounded-2xl border border-slate-200/80 bg-white p-6 shadow-xs">
            @php
                $capDisponible = $capacite['disponible'] ?? false;
                $capKpis = $capacite['kpis'] ?? [];
                $capLignes = $capacite['lignes'] ?? [];
                $capConflits = $capacite['conflits'] ?? [];
                $capPropositions = $capacite['propositions_recentes'] ?? [];
                $capEmpreinte = $capacite['empreinte'] ?? null;
                $capMotif = $capacite['motif_indisponibilite'] ?? null;
                $capPeutAgir = $capacite['peut_agir'] ?? false;
                $capRatio = $capacite['ratio'] ?? 0.50;
            @endphp

            <div class="flex items-center justify-between mb-4">
                <div>
                    <h3 class="font-bold text-sm text-slate-900">Décision de capacité et permutations (D-04 V2)</h3>
                    <p class="text-xs text-slate-500 mt-0.5">
                        Jauge utile opposable = ⌊Capacité nominale × {{ number_format($capRatio * 100, 0) }}%⌋.
                        Aucune proposition n’est officielle : une décision humaine, une justification et une
                        revalidation serveur sont requises avant toute modification.
                    </p>
                </div>
                @if($capDisponible && $capPeutAgir)
                    <div class="flex gap-2">
                        <form action="{{ route('admin.evaluation.examens.groupes.capacite.analyser') }}" method="POST" class="inline" onsubmit="return this.checkValidity()">
                            @csrf
                            <input type="hidden" name="annee_id" value="{{ request('annee_id') }}">
                            <input type="hidden" name="faculte_id" value="{{ $capacite['faculte_id'] ?? '' }}">
                            <button type="submit" class="px-3 py-1.5 text-xs font-semibold bg-primary-600 text-white rounded-lg hover:bg-primary-700 transition">
                                Analyser les capacités
                            </button>
                        </form>
                        <form action="{{ route('admin.evaluation.examens.groupes.capacite.generer') }}" method="POST" class="inline" onsubmit="return this.checkValidity()">
                            @csrf
                            <input type="hidden" name="annee_id" value="{{ request('annee_id') }}">
                            <input type="hidden" name="faculte_id" value="{{ $capacite['faculte_id'] ?? '' }}">
                            <button type="submit" class="px-3 py-1.5 text-xs font-semibold bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 transition">
                                Générer des permutations
                            </button>
                        </form>
                    </div>
                @endif
            </div>

            @if(!$capDisponible)
                <div class="rounded-xl bg-amber-50 border border-amber-100/50 p-4 mb-6">
                    <p class="text-sm text-amber-800">{{ $capMotif ?? 'Sélectionnez une année pour lancer l’analyse de capacité.' }}</p>
                </div>
            @endif

            {{-- KPIs synthétiques --}}
            @if($capDisponible)
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-6 gap-3 mb-6">
                    <div class="rounded-xl border border-slate-200/80 bg-white p-3">
                        <span class="text-[10px] font-bold uppercase tracking-wider text-slate-500">Candidats</span>
                        <div class="mt-1 text-xl font-bold text-slate-900">{{ $capKpis['total_candidats'] ?? 0 }}</div>
                    </div>
                    <div class="rounded-xl border border-indigo-200/80 bg-indigo-50 p-3">
                        <span class="text-[10px] font-bold uppercase tracking-wider text-indigo-500">Capacité utile totale</span>
                        <div class="mt-1 text-xl font-bold text-indigo-900">{{ $capKpis['capacite_utile_totale'] ?? 0 }}</div>
                    </div>
                    <div class="rounded-xl border border-slate-200/80 bg-white p-3">
                        <span class="text-[10px] font-bold uppercase tracking-wider text-slate-500">Places restantes</span>
                        <div class="mt-1 text-xl font-bold text-slate-900">{{ $capKpis['places_restantes'] ?? 0 }}</div>
                    </div>
                    <div class="rounded-xl border {{ $capKpis['salles_en_surcharge'] > 0 ? 'border-rose-200/90 bg-rose-50' : 'border-emerald-200/80 bg-emerald-50' }} p-3">
                        <span class="text-[10px] font-bold uppercase tracking-wider {{ $capKpis['salles_en_surcharge'] > 0 ? 'text-rose-700' : 'text-emerald-700' }}">Salles en surcharge</span>
                        <div class="mt-1 text-xl font-bold {{ $capKpis['salles_en_surcharge'] > 0 ? 'text-rose-900' : 'text-emerald-900' }}">{{ $capKpis['salles_en_surcharge'] ?? 0 }}</div>
                    </div>
                    <div class="rounded-xl border border-primary-200/80 bg-primary-50 p-3">
                        <span class="text-[10px] font-bold uppercase tracking-wider text-primary-600">Salles libres</span>
                        <div class="mt-1 text-xl font-bold text-primary-900">{{ $capKpis['salles_libres'] ?? 0 }}</div>
                    </div>
                    <div class="rounded-xl border border-slate-200/80 bg-white p-3">
                        <span class="text-[10px] font-bold uppercase tracking-wider text-slate-500">Non affectables</span>
                        <div class="mt-1 text-xl font-bold text-slate-900">{{ $capKpis['epreuves_non_affectables'] ?? 0 }}</div>
                    </div>
                </div>

                {{-- Conflits bloquants --}}
                @if(count($capConflits) > 0)
                    <div class="rounded-xl border border-rose-200/90 bg-rose-50 p-4 mb-6">
                        <h4 class="text-xs font-bold text-rose-900 uppercase tracking-wider mb-2">Conflits détectés</h4>
                        <ul class="list-disc list-inside text-sm text-rose-800 space-y-1">
                            @foreach($capConflits as $conflit)
                                <li>{{ $conflit['message'] }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                {{-- Tableau de décision --}}
                <div class="overflow-x-auto mb-6">
                    <table class="w-full text-left text-sm">
                        <thead class="border-b border-slate-100 text-[11px] font-bold uppercase tracking-wider text-slate-500">
                            <tr>
                                <th class="pb-3 pl-1">Épreuve</th>
                                <th class="pb-3">Matière</th>
                                <th class="pb-3">Groupe</th>
                                <th class="pb-3">Salle</th>
                                <th class="pb-3 text-center">Effectif</th>
                                <th class="pb-3 text-center">Capacité utile</th>
                                <th class="pb-3 text-center">Places restantes</th>
                                <th class="pb-3 text-center">Dépassement</th>
                                <th class="pb-3 pr-1 text-right">Statut</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @forelse($capLignes as $ligne)
                                <tr class="hover:bg-slate-50/60 transition {{ $ligne['en_surcharge'] ? 'bg-rose-50' : '' }}">
                                    <td class="py-3.5 pl-1 font-mono text-xs">{{ $ligne['code'] }}</td>
                                    <td class="py-3.5 text-xs">{{ $ligne['matiere'] }}</td>
                                    <td class="py-3.5 text-xs">{{ $ligne['groupe'] ?? '—' }}</td>
                                    <td class="py-3.5 text-xs">{{ $ligne['salle'] }}</td>
                                    <td class="py-3.5 text-center text-xs font-bold">{{ $ligne['effectif_total'] }}</td>
                                    <td class="py-3.5 text-center text-xs">{{ $ligne['capacite_utile'] }}</td>
                                    <td class="py-3.5 text-center text-xs">{{ $ligne['places_restantes'] }}</td>
                                    <td class="py-3.5 text-center text-xs font-bold {{ $ligne['depassement'] > 0 ? 'text-rose-600' : 'text-slate-400' }}">{{ $ligne['depassement'] }}</td>
                                    <td class="py-3.5 pr-1 text-right text-xs">
                                        <span class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[10px] font-bold {{ $ligne['en_surcharge'] ? 'bg-rose-100 text-rose-800' : 'bg-emerald-100 text-emerald-800' }}">
                                            {{ $ligne['en_surcharge'] ? 'Surcharge D-04' : 'Conforme D-04' }}
                                        </span>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="9" class="py-8 text-center text-sm text-slate-500">
                                        Aucune épreuve dans le périmètre sélectionné.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                {{-- Propositions récentes --}}
                @if(count($capPropositions) > 0)
                    <div class="rounded-xl border border-indigo-200/80 bg-indigo-50 p-4 mb-6">
                        <h4 class="text-xs font-bold text-indigo-900 uppercase tracking-wider mb-3">Propositions de permutations récentes</h4>
                        <div class="overflow-x-auto">
                            <table class="w-full text-left text-sm">
                                <thead class="border-b border-indigo-200 text-[11px] font-bold uppercase tracking-wider text-indigo-600">
                                    <tr>
                                        <th class="pb-3 pl-1">Type</th>
                                        <th class="pb-3">Épreuve</th>
                                        <th class="pb-3">Salle cible</th>
                                        <th class="pb-3 text-center">Capacité utile</th>
                                        <th class="pb-3 text-center">Places restantes</th>
                                        <th class="pb-3 text-center">Score</th>
                                        <th class="pb-3 pr-1 text-right">État</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-indigo-100">
                                    @foreach($capPropositions as $prop)
                                        <tr>
                                            <td class="py-3 pl-1 text-xs font-semibold">{{ $prop['type'] }}</td>
                                            <td class="py-3 text-xs">{{ $prop['epreuve_id'] }}</td>
                                            <td class="py-3 text-xs">{{ $prop['code_cible'] ?? ($prop['salles_cibles'] ?? [])[0] ?? '—' }}</td>
                                            <td class="py-3 text-center text-xs">{{ $prop['capacite_utile_cible'] ?? 0 }}</td>
                                            <td class="py-3 text-center text-xs">{{ $prop['places_restantes_apres'] ?? 0 }}</td>
                                            <td class="py-3 text-center text-xs">{{ $prop['score'] }}</td>
                                            <td class="py-3 pr-1 text-right text-xs">
                                                <span class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[10px] font-bold {{ $prop['obsolete'] ? 'bg-rose-100 text-rose-800' : 'bg-emerald-100 text-emerald-800' }}">
                                                    {{ $prop['obsolete'] ? 'Obsolète' : 'Accepvable' }}
                                                </span>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                        @if($capacite['tronque'] ?? false)
                            <p class="mt-2 text-xs text-slate-500">Le périmètre a été tronqué pour la performance ({{ $capacite['max_epreuves'] ?? 60 }} épreuves max).</p>
                        @endif
                    </div>
                @endif

                {{-- Actions de décision --}}
                @if($capDisponible && $capPeutAgir)
                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
                        <form action="{{ route('admin.evaluation.examens.groupes.capacite.accepter') }}" method="POST" class="p-3 rounded-xl border border-emerald-200 bg-emerald-50">
                            @csrf
                            <input type="hidden" name="annee_id" value="{{ request('annee_id') }}">
                            <input type="hidden" name="epreuve_id" value="">
                            <input type="hidden" name="proposition" value="">
                            <textarea name="justification" rows="2" class="w-full text-xs mb-2" placeholder="Justification de la décision…" required></textarea>
                            <button type="submit" class="w-full py-2 text-xs font-semibold bg-emerald-600 text-white rounded-lg hover:bg-emerald-700">Accepter</button>
                        </form>
                        <form action="{{ route('admin.evaluation.examens.groupes.capacite.refuser') }}" method="POST" class="p-3 rounded-xl border border-rose-200 bg-rose-50">
                            @csrf
                            <textarea name="justification" rows="2" class="w-full text-xs mb-2" placeholder="Motif du refus…" required></textarea>
                            <button type="submit" class="w-full py-2 text-xs font-semibold bg-rose-600 text-white rounded-lg hover:bg-rose-700">Refuser</button>
                        </form>
                        <form action="{{ route('admin.evaluation.examens.groupes.capacite.annuler') }}" method="POST" class="p-3 rounded-xl border border-slate-200 bg-white">
                            @csrf
                            <input type="hidden" name="epreuve_id" value="">
                            <button type="submit" class="w-full py-2 text-xs font-semibold bg-slate-600 text-white rounded-lg hover:bg-slate-700">Annuler (non publiée)</button>
                        </form>
                    </div>
                @endif

                {{-- Historique --}}
                @if(count($capacite['historiographie'] ?? []) > 0)
                    <div class="mt-6 rounded-xl border border-slate-200/80 bg-white p-4">
                        <h4 class="text-xs font-bold text-slate-900 uppercase tracking-wider mb-3">Historique des décisions</h4>
                        <div class="overflow-x-auto">
                            <table class="w-full text-left text-xs">
                                <thead class="border-b border-slate-100 text-[10px] font-bold uppercase tracking-wider text-slate-500">
                                    <tr>
                                        <th class="pb-2 pl-1">Action</th>
                                        <th class="pb-2">Épreuve</th>
                                        <th class="pb-2">Utilisateur</th>
                                        <th class="pb-2 pr-1 text-right">Date</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100">
                                    @foreach($capacite['historiographie'] as $histo)
                                        <tr>
                                            <td class="py-2 pl-1">{{ $histo['action'] }}</td>
                                            <td class="py-2">#{{ $histo['epreuve_id'] }}</td>
                                            <td class="py-2">{{ $histo['utilisateur'] }}</td>
                                            <td class="py-2 pr-1 text-right">{{ $histo['cree_a'] }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                @endif
            @endif
        </div>

    @else
        {{-- ========================================================================= --}}
        {{-- VUE MODE A : GROUPES PÉDAGOGIQUES DE RÉFÉRENCE (PAR DÉFAUT)               --}}
        {{-- ========================================================================= --}}

        {{-- Cartes KPI Mode A --}}
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
            {{-- KPI 1 : Total Groupes --}}
            <div class="relative overflow-hidden rounded-2xl border border-primary-200/80 bg-gradient-to-br from-primary-50/70 via-white to-white p-5 shadow-xs transition hover:shadow-sm">
                <div class="flex items-center justify-between">
                    <span class="text-[11px] font-bold uppercase tracking-wider text-primary-800">Groupes répertoriés</span>
                    <span class="inline-flex h-8 w-8 items-center justify-center rounded-xl bg-primary-100 text-primary-800 ring-4 ring-primary-50">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M18 18.72a9.094 9.094 0 0 0 3.741-.479 3 3 0 0 0-4.682-2.72m.94 3.198.001.031c0 .225-.012.447-.037.666A11.944 11.944 0 0 1 12 21c-2.17 0-4.207-.576-5.963-1.584A6.062 6.062 0 0 1 6 18.719m12 0a5.971 5.971 0 0 0-.941-3.197m0 0A5.995 5.995 0 0 0 12 12.75a5.995 5.995 0 0 0-5.058 2.772m0 0a3 3 0 0 0-4.681 2.72 8.986 8.986 0 0 0 3.74.477m.94-3.197a5.971 5.971 0 0 0-.94 3.197M15 6.75a3 3 0 1 1-6 0 3 3 0 0 1 6 0Zm6 3a2.25 2.25 0 1 1-4.5 0 2.25 2.25 0 0 1 4.5 0Zm-13.5 0a2.25 2.25 0 1 1-4.5 0 2.25 2.25 0 0 1 4.5 0Z" />
                        </svg>
                    </span>
                </div>
                <div class="mt-3 flex items-baseline gap-2">
                    <span class="text-3xl font-black tracking-tight text-slate-900">{{ $totalGroupes }}</span>
                    <span class="text-xs font-semibold text-primary-800">cohortes</span>
                </div>
                <p class="mt-1 text-xs text-slate-500">Groupes actifs de la composante</p>
            </div>

            {{-- KPI 2 : Effectif Total Inscrits --}}
            <div class="relative overflow-hidden rounded-2xl border border-indigo-200/80 bg-gradient-to-br from-indigo-50/70 via-white to-white p-5 shadow-xs transition hover:shadow-sm">
                <div class="flex items-center justify-between">
                    <span class="text-[11px] font-bold uppercase tracking-wider text-indigo-700">Étudiants rattachés</span>
                    <span class="inline-flex h-8 w-8 items-center justify-center rounded-xl bg-indigo-100 text-indigo-700 ring-4 ring-indigo-50">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 0 0 2.625.372 9.337 9.337 0 0 0 4.121-.952 4.125 4.125 0 0 0-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 0 1 8.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0 1 11.964-3.07M12 6.375a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0Zm8.25 2.25a2.625 2.625 0 1 1-5.25 0 2.625 2.625 0 0 1 5.25 0Z" />
                        </svg>
                    </span>
                </div>
                <div class="mt-3 flex items-baseline gap-2">
                    <span class="text-3xl font-black tracking-tight text-slate-900">{{ $effectifTotal }}</span>
                    <span class="text-xs font-semibold text-indigo-700">étudiants</span>
                </div>
                <p class="mt-1 text-xs text-slate-500">Inscriptions pédagogiques validées</p>
            </div>

            {{-- KPI 3 : Taux moyen de saturation --}}
            <div class="relative overflow-hidden rounded-2xl border border-slate-200/80 bg-white p-5 shadow-xs transition hover:shadow-sm">
                <div class="flex items-center justify-between">
                    <span class="text-[11px] font-bold uppercase tracking-wider text-slate-600">Remplissage moyen</span>
                    <span class="inline-flex h-8 w-8 items-center justify-center rounded-xl bg-slate-100 text-slate-700 ring-4 ring-slate-50">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 0 1 3 19.875v-6.75ZM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V8.625ZM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V4.125Z" />
                        </svg>
                    </span>
                </div>
                <div class="mt-3 flex items-baseline gap-2">
                    <span class="text-3xl font-black tracking-tight text-slate-900">{{ $tauxMoyen }}%</span>
                    <span class="text-xs font-medium text-slate-500">d'occupation</span>
                </div>
                <p class="mt-1 text-xs text-slate-500">Capacité globale : {{ $capaciteTotale }} places</p>
            </div>

            {{-- KPI 4 : Groupes à risque --}}
            <div class="relative overflow-hidden rounded-2xl border {{ $groupesSansCapacite > 0 || $groupesSurcharges > 0 ? 'border-amber-300 bg-gradient-to-br from-amber-50/70 via-white to-white' : 'border-emerald-200 bg-emerald-50/60' }} p-5 shadow-xs transition hover:shadow-sm">
                <div class="flex items-center justify-between">
                    <span class="text-[11px] font-bold uppercase tracking-wider {{ $groupesSansCapacite > 0 || $groupesSurcharges > 0 ? 'text-amber-800' : 'text-emerald-700' }}">Points d'attention</span>
                    <span class="inline-flex h-8 w-8 items-center justify-center rounded-xl {{ $groupesSansCapacite > 0 || $groupesSurcharges > 0 ? 'bg-amber-100 text-amber-800 ring-4 ring-amber-50' : 'bg-emerald-100 text-emerald-700 ring-4 ring-emerald-50' }}">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" />
                        </svg>
                    </span>
                </div>
                <div class="mt-3 flex items-baseline gap-2">
                    <span class="text-3xl font-black tracking-tight {{ $groupesSansCapacite > 0 || $groupesSurcharges > 0 ? 'text-amber-900' : 'text-emerald-800' }}">{{ $groupesSansCapacite + $groupesSurcharges }}</span>
                    <span class="text-xs font-semibold {{ $groupesSansCapacite > 0 || $groupesSurcharges > 0 ? 'text-amber-800' : 'text-emerald-700' }}">groupe{{ ($groupesSansCapacite + $groupesSurcharges) > 1 ? 's' : '' }}</span>
                </div>
                <p class="mt-1 text-xs text-slate-500">{{ $groupesSansCapacite }} sans capacité, {{ $groupesSurcharges }} en sur-effectif</p>
            </div>
        </div>

        {{-- Barre d'outils et de recherche Mode A --}}
        <div class="flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-slate-200/80 bg-white p-3.5 shadow-xs">
            <form method="GET" action="{{ route('admin.evaluation.examens.groupes') }}" class="flex flex-wrap items-center gap-2.5 flex-1 min-w-[280px]">
                <input type="hidden" name="mode" value="A" />
                <div class="relative flex-1 min-w-[200px] max-w-sm">
                    <input type="text" name="recherche" value="{{ $recherche }}" placeholder="Rechercher code groupe, nom…" 
                           class="w-full rounded-xl border border-slate-200 bg-white pl-8 pr-3 py-1.5 text-xs text-slate-800 placeholder-slate-400 focus:border-primary-500 focus:outline-none focus:ring-1 focus:ring-primary-500" />
                    <svg class="pointer-events-none absolute left-2.5 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-slate-400" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z" />
                    </svg>
                </div>

                <button type="submit" class="rounded-xl bg-slate-100 hover:bg-slate-200 px-3 py-1.5 text-xs font-semibold text-slate-700 transition">
                    Filtrer
                </button>

                @if($recherche)
                    <a href="{{ route('admin.evaluation.examens.groupes', ['mode' => 'A']) }}" class="text-xs font-medium text-rose-600 hover:underline px-1">
                        Réinitialiser
                    </a>
                @endif
            </form>

            <span class="text-xs font-medium text-slate-500">
                <span class="font-bold tabular-nums text-slate-800">{{ $groupes->total() }}</span> groupe{{ $groupes->total() > 1 ? 's' : '' }}
            </span>
        </div>

        {{-- Liste des groupes et effectifs Mode A --}}
        <div class="rounded-2xl border border-slate-200/80 bg-white p-6 shadow-xs">
            <h3 class="font-bold text-sm text-slate-900 mb-4">Groupes de référence et effectifs d'étudiants (TD / TP)</h3>
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead>
                        <tr class="border-b border-slate-100 text-[11px] font-bold uppercase tracking-wider text-slate-500">
                            <th class="pb-3 pl-1">Code Groupe</th>
                            <th class="pb-3">Nom du groupe</th>
                            <th class="pb-3 text-center">Capacité nominale</th>
                            <th class="pb-3 text-center">Étudiants rattachés</th>
                            <th class="pb-3">Taux de saturation</th>
                            <th class="pb-3 pr-1 text-right">Statut de conformité</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @forelse($groupes as $groupe)
                            @php
                                $cap = (int) ($groupe->capacite ?? 0);
                                $inscrits = (int) ($groupe->etudiants_count ?? 0);
                                $pct = $cap > 0 ? min(100, round(($inscrits / $cap) * 100)) : 0;
                                $enSurcharge = $cap > 0 && $inscrits > $cap;
                            @endphp
                            <tr class="hover:bg-slate-50/60 transition">
                                <td class="py-3.5 pl-1 font-mono font-bold text-slate-900 text-xs">
                                    <span class="inline-flex items-center rounded-lg bg-slate-100 px-2 py-0.5 text-xs font-semibold text-slate-800">
                                        {{ $groupe->code }}
                                    </span>
                                </td>
                                <td class="py-3.5 text-slate-800 font-medium text-xs">
                                    {{ $groupe->nom ?? '—' }}
                                </td>
                                <td class="py-3.5 text-center text-xs text-slate-600">
                                    @if($cap > 0)
                                        <span class="font-semibold text-slate-900">{{ $cap }}</span> places
                                    @else
                                        <span class="inline-flex items-center rounded-md bg-amber-50 px-2 py-0.5 text-[11px] font-medium text-amber-700 ring-1 ring-amber-200">
                                            Non définie
                                        </span>
                                    @endif
                                </td>
                                <td class="py-3.5 text-center text-xs font-bold text-slate-900">
                                    {{ $inscrits }} étudiant{{ $inscrits > 1 ? 's' : '' }}
                                </td>
                                <td class="py-3.5">
                                    @if($cap > 0)
                                        <div class="flex items-center gap-2 max-w-xs">
                                            <div class="w-28 bg-slate-100 rounded-full h-2 overflow-hidden">
                                                <div class="h-2 rounded-full {{ $enSurcharge ? 'bg-rose-500' : ($pct > 85 ? 'bg-amber-500' : 'bg-emerald-500') }}" style="width: {{ $pct }}%"></div>
                                            </div>
                                            <span class="text-xs font-semibold {{ $enSurcharge ? 'text-rose-600' : 'text-slate-700' }}">{{ $pct }}%</span>
                                        </div>
                                    @else
                                        <span class="text-xs text-slate-400">—</span>
                                    @endif
                                </td>
                                <td class="py-3.5 pr-1 text-right text-xs">
                                    @if($cap <= 0)
                                        <span class="inline-flex items-center gap-1 text-amber-700 font-medium">
                                            <svg class="h-3.5 w-3.5 text-amber-500" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008Z" /></svg>
                                            À calibrer
                                        </span>
                                    @elseif($enSurcharge)
                                        <span class="inline-flex items-center gap-1 text-rose-700 font-bold">
                                            <svg class="h-3.5 w-3.5 text-rose-500" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008Z" /></svg>
                                            Sur-effectif (+{{ $inscrits - $cap }})
                                        </span>
                                    @else
                                        <span class="inline-flex items-center gap-1 text-emerald-700 font-medium">
                                            <svg class="h-3.5 w-3.5 text-emerald-500" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" /></svg>
                                            Conforme
                                        </span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="py-8 text-center text-sm text-slate-500">
                                    Aucun groupe d'examen ne correspond à la recherche.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if($groupes->hasPages())
                <div class="mt-4 pt-3 border-t border-slate-100">
                    {{ $groupes->links() }}
                </div>
            @endif
        </div>
    @endif

    {{-- Zone dédiée aux documents professionnels (LOT 2) --}}
    @include('modules.evaluation.examens-advanced._zone-documents-professionnels', ['optionCourante' => 'groupes'])
</div>
@endsection