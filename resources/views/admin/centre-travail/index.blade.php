@extends('layouts.app')

@section('title', 'Centre de travail administratif')

@section('content')
<div class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
    <x-page-header
        titre="Centre de travail administratif"
        description="File de travail unifiée en temps réel : priorisation intelligente des dossiers, indicateurs de pilotage et accès direct aux actes de scolarité.">
        <x-slot:actions>
            <div class="flex items-center gap-2">
                @can('cockpit.view')
                <a href="{{ route('admin.cockpit.index') }}" class="inline-flex items-center gap-1.5 rounded-xl border border-slate-200 bg-white px-3.5 py-2 text-xs font-bold text-slate-700 hover:bg-slate-50 transition focus:outline-none focus:ring-2 focus:ring-primary-500">
                    <svg class="h-4 w-4 text-slate-500" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3.75 13.5l10.5-11.25L12 10.5h8.25L9.75 21.75 12 13.5H3.75z"/></svg>
                    Cockpit
                </a>
                @endcan
                @can('archives.view')
                <a href="{{ route('admin.inscriptions.file-traitement') }}" class="inline-flex items-center gap-1.5 rounded-xl border border-slate-200 bg-white px-3.5 py-2 text-xs font-bold text-slate-700 hover:bg-slate-50 transition focus:outline-none focus:ring-2 focus:ring-primary-500">
                    <svg class="h-4 w-4 text-slate-500" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                    File des inscriptions
                </a>
                @endcan
            </div>
        </x-slot:actions>
    </x-page-header>

    @if(session('status'))
        <div class="mt-4"><x-alert type="info">{{ session('status') }}</x-alert></div>
    @endif
    @if(session('success'))
        <div class="mt-4"><x-alert type="success">{{ session('success') }}</x-alert></div>
    @endif
    @if($errors->any())
        <div class="mt-4"><x-alert type="error" titre="Erreur de validation">{{ $errors->first() }}</x-alert></div>
    @endif

    @if(session('simulation'))
        @php $sim = session('simulation'); @endphp
        <div class="mt-4 rounded-2xl border-2 border-indigo-300 bg-indigo-50/90 p-4 shadow-sm text-xs">
            <div class="flex items-center justify-between pb-3 border-b border-indigo-200">
                <div class="flex items-center gap-2">
                    <span class="inline-flex h-6 w-6 items-center justify-center rounded-full bg-indigo-600 text-white font-bold text-xs">ℹ</span>
                    <h4 class="text-sm font-black text-indigo-950">Aperçu de simulation d'action groupée (Dry-Run sans écriture)</h4>
                </div>
                <span class="rounded-full bg-indigo-200 px-2.5 py-0.5 text-[11px] font-bold text-indigo-900">
                    Action : {{ strtoupper($sim['action']) }}
                </span>
            </div>
            <div class="mt-3 grid grid-cols-1 sm:grid-cols-3 gap-3">
                <div class="rounded-xl bg-white p-3 border border-indigo-100">
                    <p class="text-slate-500 font-semibold">Tâches sélectionnées</p>
                    <p class="text-xl font-black text-slate-900">{{ $sim['total_demande'] }}</p>
                </div>
                <div class="rounded-xl bg-emerald-50 p-3 border border-emerald-200">
                    <p class="text-emerald-800 font-semibold">Éligibles à l'exécution</p>
                    <p class="text-xl font-black text-emerald-900">{{ $sim['total_eligibles'] }}</p>
                </div>
                <div class="rounded-xl bg-amber-50 p-3 border border-amber-200">
                    <p class="text-amber-800 font-semibold">Exclues / Incompatibles</p>
                    <p class="text-xl font-black text-amber-900">{{ $sim['total_exclus'] }}</p>
                </div>
            </div>

            @if(!empty($sim['exclus']))
                <div class="mt-3 rounded-xl bg-amber-100/60 p-2.5 text-amber-900">
                    <p class="font-bold mb-1">Motifs d'exclusion détectés :</p>
                    <ul class="list-disc list-inside space-y-0.5 text-[11px]">
                        @foreach($sim['exclus'] as $item)
                            <li><strong>{{ $item['titre'] ?? "Tâche #{$item['id']}" }}</strong> : {{ $item['motif'] }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            @if(!empty($sim['eligibles']))
                <div class="mt-3 rounded-xl bg-emerald-100/60 p-2.5 text-emerald-900">
                    <p class="font-bold mb-1">Changements qui seront appliqués :</p>
                    <ul class="list-disc list-inside space-y-0.5 text-[11px]">
                        @foreach(array_slice($sim['eligibles'], 0, 5) as $item)
                            <li><strong>{{ $item['titre'] }}</strong> : {{ $item['changement'] }}</li>
                        @endforeach
                        @if(count($sim['eligibles']) > 5)
                            <li class="italic font-semibold">... et {{ count($sim['eligibles']) - 5 }} autre(s) tâche(s).</li>
                        @endif
                    </ul>
                </div>
            @endif

            <form method="POST" action="{{ route('admin.centre-travail.actions-groupees') }}" class="mt-3.5 flex items-center justify-end gap-2">
                @csrf
                @foreach(array_column($sim['eligibles'], 'id') as $idEligible)
                    <input type="hidden" name="ids[]" value="{{ $idEligible }}">
                @endforeach
                <input type="hidden" name="action" value="{{ $sim['action'] }}">
                <input type="hidden" name="mode" value="execution">
                @if(request('priorite'))
                    <input type="hidden" name="priorite" value="{{ request('priorite') }}">
                @endif
                @if(request('assigne_a_id'))
                    <input type="hidden" name="assigne_a" value="{{ request('assigne_a_id') }}">
                @endif
                <a href="{{ route('admin.centre-travail.index') }}" class="rounded-xl border border-slate-300 bg-white px-3 py-1.5 font-bold text-slate-700 hover:bg-slate-50 transition">
                    Annuler la simulation
                </a>
                @if($sim['total_eligibles'] > 0)
                <button type="submit" onclick="return confirm('Confirmer l\'application définitive de ces changements ?')" class="rounded-xl bg-indigo-700 px-4 py-1.5 font-bold text-white hover:bg-indigo-800 transition shadow-xs">
                    Confirmer et appliquer ({{ $sim['total_eligibles'] }})
                </button>
                @endif
            </form>
        </div>
    @endif

    {{-- ── 1. Tableau de bord de pilotage (KPIs en temps réel) ── --}}
    <div class="mt-6">
        <p class="mb-2 text-[11px] font-semibold uppercase tracking-wider text-slate-500">
            Pilotage opérationnel &amp; Gestion des risques
            @if($faculteRestreinteId !== null)
                <span class="text-primary-700 font-bold">(Périmètre Faculté restreint)</span>
            @else
                <span class="text-slate-600 font-normal">— Ensemble des facultés</span>
            @endif
        </p>

        {{-- Synthèse Inscriptions (Lot 1 Cockpit Inscriptions) --}}
        @if(!empty($inscriptionsKpis))
        <div class="mb-4 rounded-2xl border border-slate-200/80 bg-linear-to-r from-slate-50 via-white to-primary-50/20 p-3 shadow-2xs">
            <div class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-100 pb-2 mb-2 text-xs">
                <span class="font-bold text-slate-800 flex items-center gap-1.5">
                    <svg class="h-4 w-4 text-primary-700" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"/></svg>
                    Registre des inscriptions académiques
                </span>
                <span class="text-[11px] text-slate-500 font-medium">Temps réel &middot; scoping strict</span>
            </div>
            <div class="grid grid-cols-2 gap-2 sm:grid-cols-4 lg:grid-cols-7 text-center">
                <div class="rounded-xl bg-white p-2 border border-slate-100">
                    <p class="text-[10px] uppercase font-bold text-slate-400">Total dossiers</p>
                    <p class="text-base font-black text-slate-900 tabular-nums">{{ number_format($inscriptionsKpis['total']) }}</p>
                </div>
                <div class="rounded-xl bg-white p-2 border border-slate-100">
                    <p class="text-[10px] uppercase font-bold text-slate-400">En cours</p>
                    <p class="text-base font-black text-amber-700 tabular-nums">{{ number_format($inscriptionsKpis['en_cours']) }}</p>
                </div>
                <div class="rounded-xl bg-white p-2 border border-slate-100">
                    <p class="text-[10px] uppercase font-bold text-slate-400">Validées</p>
                    <p class="text-base font-black text-emerald-700 tabular-nums">{{ number_format($inscriptionsKpis['valides']) }}</p>
                </div>
                <div class="rounded-xl bg-white p-2 border border-slate-100">
                    <p class="text-[10px] uppercase font-bold text-slate-400">Rejetées</p>
                    <p class="text-base font-black text-slate-600 tabular-nums">{{ number_format($inscriptionsKpis['rejetes']) }}</p>
                </div>
                <div class="rounded-xl bg-white p-2 border border-slate-100">
                    <p class="text-[10px] uppercase font-bold text-slate-400">Bloquées</p>
                    <p class="text-base font-black text-purple-700 tabular-nums">{{ number_format($inscriptionsKpis['bloquees']) }}</p>
                </div>
                <div class="rounded-xl bg-white p-2 border border-slate-100">
                    <p class="text-[10px] uppercase font-bold text-slate-400">Paiements att.</p>
                    <p class="text-base font-black text-blue-700 tabular-nums">{{ number_format($inscriptionsKpis['paiements_attente']) }}</p>
                </div>
                <div class="rounded-xl bg-white p-2 border border-slate-100">
                    <p class="text-[10px] uppercase font-bold text-slate-400">4-Yeux att.</p>
                    <p class="text-base font-black text-indigo-700 tabular-nums">{{ number_format($inscriptionsKpis['corrections_attente']) }}</p>
                </div>
            </div>
        </div>
        @endif

        {{-- Ligne 1 : File opérationnelle --}}
        <div class="grid grid-cols-2 gap-3.5 sm:grid-cols-3 lg:grid-cols-5" role="region" aria-label="Indicateurs de file opérationnelle">
            {{-- En attente --}}
            <a href="{{ route('admin.centre-travail.index', ['statut' => 'pending']) }}"
                class="block rounded-2xl border border-slate-200 bg-white p-3.5 shadow-xs transition hover:border-primary-300 hover:bg-primary-50/40 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500"
                aria-label="À traiter : {{ number_format($kpis['en_attente']) }} tâche(s) en attente">
                <p class="text-[10px] font-black uppercase tracking-wider text-slate-500">À traiter</p>
                <p class="mt-1 text-2xl font-black tabular-nums text-slate-900">{{ number_format($kpis['en_attente']) }}</p>
                <p class="mt-0.5 text-[11px] text-slate-500">Statut en attente</p>
            </a>

            {{-- En cours --}}
            <a href="{{ route('admin.centre-travail.index', ['statut' => 'in_progress']) }}"
                class="block rounded-2xl border border-slate-200 bg-white p-3.5 shadow-xs transition hover:border-indigo-300 hover:bg-indigo-50/40 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500"
                aria-label="En cours : {{ number_format($kpis['en_cours']) }} tâche(s)">
                <p class="text-[10px] font-black uppercase tracking-wider text-slate-500">En cours</p>
                <p class="mt-1 text-2xl font-black tabular-nums text-indigo-700">{{ number_format($kpis['en_cours']) }}</p>
                <p class="mt-0.5 text-[11px] text-slate-500">Prises en charge</p>
            </a>

            {{-- Mes tâches --}}
            <a href="{{ route('admin.centre-travail.index', ['assignation' => 'miennes']) }}"
                class="block rounded-2xl border border-slate-200 bg-white p-3.5 shadow-xs transition hover:border-blue-300 hover:bg-blue-50/40 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500"
                aria-label="Mes tâches : {{ number_format($kpis['mes_taches'] ?? 0) }} tâche(s)">
                <p class="text-[10px] font-black uppercase tracking-wider text-slate-500">Mes tâches</p>
                <p class="mt-1 text-2xl font-black tabular-nums text-blue-700">{{ number_format($kpis['mes_taches'] ?? 0) }}</p>
                <p class="mt-0.5 text-[11px] text-slate-500">Assignées à moi</p>
            </a>

            {{-- Non assignées --}}
            <a href="{{ route('admin.centre-travail.index', ['assignation' => 'non_assignees']) }}"
                class="block rounded-2xl border border-slate-200 bg-white p-3.5 shadow-xs transition hover:border-amber-300 hover:bg-amber-50/40 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500"
                aria-label="Non assignées : {{ number_format($kpis['non_assignees'] ?? 0) }} tâche(s)">
                <p class="text-[10px] font-black uppercase tracking-wider text-slate-500">Non assignées</p>
                <p class="mt-1 text-2xl font-black tabular-nums text-slate-700">{{ number_format($kpis['non_assignees'] ?? 0) }}</p>
                <p class="mt-0.5 text-[11px] text-slate-500">File à distribuer</p>
            </a>

            {{-- Clôturées 7j --}}
            <a href="{{ route('admin.centre-travail.index', ['statut' => 'completed']) }}"
                class="block rounded-2xl border border-slate-200 bg-white p-3.5 shadow-xs transition hover:border-emerald-300 hover:bg-emerald-50/40">
                <p class="text-[10px] font-black uppercase tracking-wider text-slate-500">Clôturées (7j)</p>
                <p class="mt-1 text-2xl font-black tabular-nums text-emerald-700">{{ number_format($kpis['cloturees_recemment']) }}</p>
                <p class="mt-0.5 text-[11px] text-slate-500">Traitées récemment</p>
            </a>
        </div>

        {{-- Ligne 2 : Risques et alertes décisionnelles --}}
        <div class="mt-3.5 grid grid-cols-2 gap-3.5 sm:grid-cols-3 lg:grid-cols-5" role="region" aria-label="Indicateurs de risques et alertes">
            {{-- Prioritaires --}}
            <a href="{{ route('admin.centre-travail.index', ['statut' => 'actives', 'priorite' => 'critical_high']) }}"
                class="block rounded-2xl border p-3.5 shadow-xs transition hover:bg-amber-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 {{ $kpis['prioritaires'] > 0 ? 'border-amber-300 bg-amber-50/60' : 'border-slate-200 bg-white' }}"
                aria-label="Prioritaires : {{ number_format($kpis['prioritaires']) }} tâche(s)">
                <p class="text-[10px] font-black uppercase tracking-wider {{ $kpis['prioritaires'] > 0 ? 'text-amber-800' : 'text-slate-500' }}">Prioritaires</p>
                <p class="mt-1 text-2xl font-black tabular-nums {{ $kpis['prioritaires'] > 0 ? 'text-amber-900' : 'text-slate-900' }}">{{ number_format($kpis['prioritaires']) }}</p>
                <p class="mt-0.5 text-[11px] {{ $kpis['prioritaires'] > 0 ? 'text-amber-700' : 'text-slate-500' }}">Urgence haute ou critique</p>
            </a>

            {{-- En souffrance > 48h --}}
            <a href="{{ route('admin.centre-travail.index', ['souffrance' => 1]) }}"
                class="block rounded-2xl border p-3.5 shadow-xs transition hover:bg-rose-50 {{ $kpis['souffrance_48h'] > 0 ? 'border-rose-300 bg-rose-50/60' : 'border-slate-200 bg-white' }}">
                <p class="text-[10px] font-black uppercase tracking-wider {{ $kpis['souffrance_48h'] > 0 ? 'text-rose-800' : 'text-slate-500' }}">Souffrance &gt; 48h</p>
                <p class="mt-1 text-2xl font-black tabular-nums {{ $kpis['souffrance_48h'] > 0 ? 'text-rose-700' : 'text-slate-900' }}">{{ number_format($kpis['souffrance_48h']) }}</p>
                <p class="mt-0.5 text-[11px] {{ $kpis['souffrance_48h'] > 0 ? 'text-rose-700' : 'text-slate-500' }}">Dépassement de prise en charge</p>
            </a>

            {{-- En retard SLA --}}
            <a href="{{ route('admin.centre-travail.index', ['en_retard' => 1]) }}"
                class="block rounded-2xl border p-3.5 shadow-xs transition hover:bg-rose-50 {{ ($kpis['en_retard'] ?? 0) > 0 ? 'border-rose-300 bg-rose-50/60' : 'border-slate-200 bg-white' }}">
                <p class="text-[10px] font-black uppercase tracking-wider {{ ($kpis['en_retard'] ?? 0) > 0 ? 'text-rose-800' : 'text-slate-500' }}">En retard (SLA)</p>
                <p class="mt-1 text-2xl font-black tabular-nums {{ ($kpis['en_retard'] ?? 0) > 0 ? 'text-rose-700' : 'text-slate-900' }}">{{ number_format($kpis['en_retard'] ?? 0) }}</p>
                <p class="mt-0.5 text-[11px] {{ ($kpis['en_retard'] ?? 0) > 0 ? 'text-rose-700' : 'text-slate-500' }}">Échéance dépassée</p>
            </a>

            {{-- Bloquées --}}
            <a href="{{ route('admin.centre-travail.index', ['bloque' => 1]) }}"
                class="block rounded-2xl border p-3.5 shadow-xs transition hover:bg-purple-50 {{ $kpis['bloquees'] > 0 ? 'border-purple-300 bg-purple-50/60' : 'border-slate-200 bg-white' }}">
                <p class="text-[10px] font-black uppercase tracking-wider {{ $kpis['bloquees'] > 0 ? 'text-purple-800' : 'text-slate-500' }}">Dossiers bloqués</p>
                <p class="mt-1 text-2xl font-black tabular-nums {{ $kpis['bloquees'] > 0 ? 'text-purple-900' : 'text-slate-900' }}">{{ number_format($kpis['bloquees']) }}</p>
                <p class="mt-0.5 text-[11px] {{ $kpis['bloquees'] > 0 ? 'text-purple-700' : 'text-slate-500' }}">Anomalie bloquante</p>
            </a>

            {{-- Corrections 4-yeux --}}
            <a href="{{ route('admin.centre-travail.index', ['corrections_attente' => 1]) }}"
                class="block rounded-2xl border p-3.5 shadow-xs transition hover:bg-indigo-50 {{ ($kpis['corrections_4yeux'] ?? 0) > 0 ? 'border-indigo-300 bg-indigo-50/60' : 'border-slate-200 bg-white' }}">
                <p class="text-[10px] font-black uppercase tracking-wider {{ ($kpis['corrections_4yeux'] ?? 0) > 0 ? 'text-indigo-800' : 'text-slate-500' }}">4-Yeux à décider</p>
                <p class="mt-1 text-2xl font-black tabular-nums {{ ($kpis['corrections_4yeux'] ?? 0) > 0 ? 'text-indigo-900' : 'text-slate-900' }}">{{ number_format($kpis['corrections_4yeux'] ?? 0) }}</p>
                <p class="mt-0.5 text-[11px] {{ ($kpis['corrections_4yeux'] ?? 0) > 0 ? 'text-indigo-700' : 'text-slate-500' }}">Double regard requis</p>
            </a>
        </div>
    </div>

    {{-- ── 2. Filtres de recherche et sélecteurs ── --}}
    <div class="mt-6 rounded-2xl border border-slate-200 bg-white p-5 shadow-xs">
        <form method="GET" action="{{ route('admin.centre-travail.index') }}" class="space-y-4">
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                {{-- Recherche textuelle --}}
                <div class="lg:col-span-2">
                    <label for="search" class="block text-xs font-bold text-slate-700 mb-1">Recherche</label>
                    <div class="relative">
                        <input type="text"
                               name="search"
                               id="search"
                               value="{{ $filtres['search'] ?? '' }}"
                               placeholder="Titre, description, matricule, CIN, clé..."
                               class="w-full rounded-xl border border-slate-200 bg-slate-50/50 px-3.5 py-2 text-sm text-slate-900 placeholder-slate-400 focus:border-primary-500 focus:bg-white focus:outline-none focus:ring-2 focus:ring-primary-500/20">
                    </div>
                </div>

                {{-- Catégorie / Source --}}
                <div>
                    <label for="source" class="block text-xs font-bold text-slate-700 mb-1">Catégorie</label>
                    <select name="source" id="source" class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm text-slate-900 focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500/20">
                        <option value="">Toutes les catégories</option>
                        @foreach($sources as $codeSource => $libelleSource)
                            <option value="{{ $codeSource }}" @selected(($filtres['source'] ?? '') === $codeSource)>{{ $libelleSource }}</option>
                        @endforeach
                    </select>
                </div>

                {{-- Statut --}}
                <div>
                    <label for="statut" class="block text-xs font-bold text-slate-700 mb-1">Statut</label>
                    <select name="statut" id="statut" class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm text-slate-900 focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500/20">
                        <option value="actives" @selected(($statutFiltre ?? 'actives') === 'actives')>Tâches actives (défaut)</option>
                        <option value="pending" @selected(($statutFiltre ?? '') === 'pending')>En attente (pending)</option>
                        <option value="in_progress" @selected(($statutFiltre ?? '') === 'in_progress')>En cours (in progress)</option>
                        <option value="waiting" @selected(($statutFiltre ?? '') === 'waiting')>En suspension (waiting)</option>
                        <option value="completed" @selected(($statutFiltre ?? '') === 'completed')>Terminées (completed)</option>
                        <option value="cancelled" @selected(($statutFiltre ?? '') === 'cancelled')>Annulées (cancelled)</option>
                        <option value="toutes" @selected(($statutFiltre ?? '') === 'toutes')>Tous les statuts</option>
                    </select>
                </div>

                {{-- Priorité --}}
                <div>
                    <label for="priorite" class="block text-xs font-bold text-slate-700 mb-1">Priorité</label>
                    <select name="priorite" id="priorite" class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm text-slate-900 focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500/20">
                        <option value="">Toutes les priorités</option>
                        <option value="critical" @selected(($filtres['priorite'] ?? '') === 'critical')>Critique (Critical)</option>
                        <option value="high" @selected(($filtres['priorite'] ?? '') === 'high')>Haute (High)</option>
                        <option value="normal" @selected(($filtres['priorite'] ?? '') === 'normal')>Normale (Normal)</option>
                        <option value="low" @selected(($filtres['priorite'] ?? '') === 'low')>Basse (Low)</option>
                    </select>
                </div>

                {{-- Faculté (uniquement si non restreint) --}}
                @if($faculteRestreinteId === null)
                <div>
                    <label for="faculte_id" class="block text-xs font-bold text-slate-700 mb-1">Faculté</label>
                    <select name="faculte_id" id="faculte_id" class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm text-slate-900 focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500/20">
                        <option value="">Toutes les facultés</option>
                        @foreach($facultes as $fac)
                            <option value="{{ $fac->id }}" @selected(($filtres['faculte_id'] ?? '') == $fac->id)>{{ $fac->code }} — {{ $fac->nom }}</option>
                        @endforeach
                    </select>
                </div>
                @endif

                {{-- Assignation --}}
                <div>
                    <label for="assignation" class="block text-xs font-bold text-slate-700 mb-1">Assignation</label>
                    <select name="assignation" id="assignation" class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm text-slate-900 focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500/20">
                        <option value="toutes">Toutes les assignations</option>
                        <option value="miennes" @selected(($filtres['assignation'] ?? '') === 'miennes')>Mes tâches assignées</option>
                        <option value="non_assignees" @selected(($filtres['assignation'] ?? '') === 'non_assignees')>Non assignées</option>
                    </select>
                </div>

                {{-- Date début et fin --}}
                <div>
                    <label for="date_debut" class="block text-xs font-bold text-slate-700 mb-1">Créée à partir du</label>
                    <input type="date" name="date_debut" id="date_debut" value="{{ $filtres['date_debut'] ?? '' }}" class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-xs text-slate-900 focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500/20">
                </div>
                <div>
                    <label for="date_fin" class="block text-xs font-bold text-slate-700 mb-1">Jusqu'au</label>
                    <input type="date" name="date_fin" id="date_fin" value="{{ $filtres['date_fin'] ?? '' }}" class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-xs text-slate-900 focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500/20">
                </div>

                {{-- Filtres rapides (checkboxes combinables) --}}
                <div class="flex flex-wrap items-center gap-4 pt-2 sm:col-span-2 lg:col-span-4">
                    <label class="inline-flex items-center gap-2 cursor-pointer">
                        <input type="checkbox" name="souffrance" value="1" @checked(!empty($filtres['souffrance'])) class="rounded text-primary-600 focus:ring-primary-500 border-slate-300">
                        <span class="text-xs font-semibold text-slate-700">En souffrance &gt; 48h</span>
                    </label>
                    <label class="inline-flex items-center gap-2 cursor-pointer">
                        <input type="checkbox" name="en_retard" value="1" @checked(!empty($filtres['en_retard'])) class="rounded text-rose-600 focus:ring-rose-500 border-slate-300">
                        <span class="text-xs font-semibold text-slate-700">En retard (SLA dépassé)</span>
                    </label>
                    <label class="inline-flex items-center gap-2 cursor-pointer">
                        <input type="checkbox" name="bloque" value="1" @checked(!empty($filtres['bloque'])) class="rounded text-purple-600 focus:ring-purple-500 border-slate-300">
                        <span class="text-xs font-semibold text-slate-700">Dossiers bloqués</span>
                    </label>
                    <label class="inline-flex items-center gap-2 cursor-pointer">
                        <input type="checkbox" name="corrections_attente" value="1" @checked(!empty($filtres['corrections_attente'])) class="rounded text-indigo-600 focus:ring-indigo-500 border-slate-300">
                        <span class="text-xs font-semibold text-slate-700">4-Yeux en attente</span>
                    </label>
                </div>
            </div>

            {{-- Boutons d'action du formulaire --}}
            <div class="flex items-center justify-between border-t border-slate-100 pt-3">
                <span class="text-xs text-slate-500">
                    {{ $taches->total() }} tâche(s) correspondante(s)
                </span>
                <div class="flex items-center gap-2">
                    <a href="{{ route('admin.centre-travail.index') }}" class="rounded-xl border border-slate-200 bg-white px-3 py-1.5 text-xs font-semibold text-slate-600 hover:bg-slate-50 transition">
                        Réinitialiser
                    </a>
                    <button type="submit" class="rounded-xl bg-primary-800 px-4 py-1.5 text-xs font-bold text-white hover:bg-primary-900 transition shadow-xs focus:ring-2 focus:ring-primary-500 focus:outline-none">
                        Filtrer
                    </button>
                </div>
            </div>
        </form>
    </div>

    {{-- ── 3. Table unifiée des tâches administratives & Actions groupées ── --}}
    <form id="form-actions-groupees" method="POST" action="{{ route('admin.centre-travail.actions-groupees') }}"
          x-data="{
              selected: [],
              allSelected: false,
              toggleAll() {
                  const checkboxes = Array.from(document.querySelectorAll('input.task-checkbox:not(:disabled)'));
                  if (this.allSelected) {
                      this.selected = [];
                      this.allSelected = false;
                  } else {
                      this.selected = checkboxes.map(el => el.value);
                      this.allSelected = true;
                  }
              }
          }"
          class="mt-6">
        @csrf

        {{-- Barre d'actions groupées (visible dès qu'au moins 1 tâche est cochée) --}}
        <div x-show="selected.length > 0"
             x-cloak
             x-transition:enter="transition ease-out duration-200"
             x-transition:enter-start="opacity-0 -translate-y-2"
             x-transition:enter-end="opacity-100 translate-y-0"
             class="mb-3 flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-primary-200 bg-primary-50/90 p-3.5 shadow-sm text-xs">
            <div class="flex items-center gap-2">
                <span class="inline-flex h-6 w-6 items-center justify-center rounded-full bg-primary-800 text-white font-bold text-[11px]" x-text="selected.length"></span>
                <span class="font-bold text-primary-950">tâche(s) sélectionnée(s)</span>
                <button type="button" @click="selected = []; allSelected = false" class="text-primary-700 underline hover:text-primary-900 ml-2">Désélectionner tout</button>
            </div>

            <div class="flex flex-wrap items-center gap-2">
                {{-- Démarrer en lot --}}
                <button type="submit" name="action" value="demarrer"
                        onclick="return confirm('Prendre en charge les tâches sélectionnées ?')"
                        class="inline-flex items-center gap-1.5 rounded-xl border border-slate-300 bg-white px-3 py-1.5 font-bold text-slate-800 hover:bg-slate-50 transition shadow-xs">
                    <svg class="h-3.5 w-3.5 text-blue-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14.752 11.168l-3.197-2.132A1 1 0 0010 9.87v4.263a1 1 0 001.555.832l3.197-2.132a1 1 0 000-1.664z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    <span>Prendre en charge</span>
                </button>

                {{-- Changer priorité --}}
                <div class="inline-flex items-center gap-1.5 rounded-xl border border-slate-300 bg-white px-2 py-1 shadow-xs">
                    <select name="priorite" class="border-0 bg-transparent py-0.5 text-xs font-semibold text-slate-800 focus:ring-0">
                        <option value="critical">Critique</option>
                        <option value="high">Haute</option>
                        <option value="normal" selected>Normale</option>
                        <option value="low">Basse</option>
                    </select>
                    <button type="submit" name="action" value="changer_priorite"
                            class="rounded-lg bg-slate-800 px-2.5 py-1 font-bold text-white hover:bg-slate-900 transition">
                        Prioriser
                    </button>
                </div>

                {{-- Assigner en lot --}}
                @can('tasks.assign')
                <div class="inline-flex items-center gap-1.5 rounded-xl border border-slate-300 bg-white px-2 py-1 shadow-xs">
                    <select name="assigne_a_id" class="border-0 bg-transparent py-0.5 text-xs font-semibold text-slate-800 focus:ring-0 max-w-[180px]">
                        <option value="">Sélectionner un agent...</option>
                        @foreach($agentsDisponibles as $agent)
                            <option value="{{ $agent->id }}">{{ $agent->name }}</option>
                        @endforeach
                    </select>
                    <button type="submit" name="action" value="assigner"
                            class="rounded-lg bg-primary-800 px-2.5 py-1 font-bold text-white hover:bg-primary-900 transition">
                        Assigner
                    </button>
                </div>
                @endcan

                {{-- Clôturer en lot --}}
                <button type="submit" name="action" value="cloturer"
                        onclick="return confirm('Clôturer définitivement les tâches sélectionnées ?')"
                        class="inline-flex items-center gap-1.5 rounded-xl border border-emerald-300 bg-emerald-50 px-3 py-1.5 font-bold text-emerald-900 hover:bg-emerald-100 transition shadow-xs">
                    <svg class="h-3.5 w-3.5 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                    <span>Clôturer</span>
                </button>

                {{-- Simuler l'impact --}}
                <button type="submit" name="simuler" value="1"
                        class="inline-flex items-center gap-1.5 rounded-xl border border-indigo-300 bg-indigo-50/80 px-3 py-1.5 font-bold text-indigo-900 hover:bg-indigo-100 transition shadow-xs"
                        title="Simuler l'impact de l'action sélectionnée avant application définitive">
                    <svg class="h-3.5 w-3.5 text-indigo-700" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                    <span>Simuler l'impact</span>
                </button>
            </div>
        </div>

        <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-xs">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-200 text-left text-sm" aria-label="Liste unifiée des tâches du Centre de travail">
                    <thead class="bg-slate-50 text-[11px] font-bold uppercase tracking-wider text-slate-600">
                        <tr>
                            <th scope="col" class="w-10 px-4 py-3.5 text-center">
                                <input type="checkbox" @change="toggleAll()" :checked="allSelected" aria-label="Sélectionner toutes les tâches" class="rounded border-slate-300 text-primary-600 focus:ring-primary-500">
                            </th>
                            <th scope="col" class="px-4 py-3.5">Priorité &amp; Source</th>
                            <th scope="col" class="px-4 py-3.5">Dossier / Objet</th>
                            <th scope="col" class="px-4 py-3.5">Faculté</th>
                            <th scope="col" class="px-4 py-3.5">Assigné à</th>
                            <th scope="col" class="px-4 py-3.5">Échéance &amp; Ancienneté</th>
                            <th scope="col" class="px-4 py-3.5">Statut</th>
                            <th scope="col" class="px-4 py-3.5 text-right">Action</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                    @forelse($taches as $tache)
                        <tr class="hover:bg-slate-50/80 transition-colors">
                            {{-- Checkbox de sélection en lot --}}
                            <td class="w-10 whitespace-nowrap px-4 py-3.5 text-center">
                                @if(in_array($tache->statut, ['pending', 'in_progress', 'waiting']))
                                    <input type="checkbox" name="tache_ids[]" value="{{ $tache->id }}" x-model="selected" class="task-checkbox rounded border-slate-300 text-primary-600 focus:ring-primary-500" aria-label="Sélectionner la tâche {{ $tache->cle_unique }}">
                                @else
                                    <span class="text-slate-300 text-xs">—</span>
                                @endif
                            </td>

                            {{-- Priorité & Source --}}
                            <td class="whitespace-nowrap px-4 py-3.5">
                                <div class="flex flex-col gap-1 items-start">
                                    @php
                                        $badgePriorite = match($tache->priorite) {
                                            'critical' => ['bg' => 'bg-rose-100 text-rose-800 border-rose-200', 'libelle' => 'Critique'],
                                            'high' => ['bg' => 'bg-amber-100 text-amber-800 border-amber-200', 'libelle' => 'Haute'],
                                            'normal' => ['bg' => 'bg-slate-100 text-slate-800 border-slate-200', 'libelle' => 'Normale'],
                                            default => ['bg' => 'bg-slate-50 text-slate-600 border-slate-200', 'libelle' => 'Basse'],
                                        };
                                        $nomSource = $sources[$tache->source] ?? $tache->source;
                                    @endphp
                                    <span class="inline-flex items-center rounded-md border px-2 py-0.5 text-[10px] font-black uppercase tracking-wider {{ $badgePriorite['bg'] }}">
                                        {{ $badgePriorite['libelle'] }}
                                    </span>
                                    <span class="text-[11px] font-medium text-slate-500">{{ $nomSource }}</span>
                                </div>
                            </td>

                            {{-- Titre, Description, Badges contextuels --}}
                            <td class="px-4 py-3.5">
                                <div class="max-w-md">
                                    <p class="font-bold text-slate-900 line-clamp-1">{{ $tache->titre }}</p>
                                    @if($tache->description)
                                        <p class="mt-0.5 text-xs text-slate-600 line-clamp-2">{{ $tache->description }}</p>
                                    @endif
                                    <div class="mt-1 flex flex-wrap items-center gap-1.5">
                                        @if(!empty($tache->data['est_en_souffrance']))
                                            <span class="inline-flex items-center rounded-md bg-rose-50 border border-rose-200 px-1.5 py-0.5 text-[10px] font-bold text-rose-700">
                                                En attente &gt; 48h
                                            </span>
                                        @endif
                                        @if(!empty($tache->data['bloque']))
                                            <span class="inline-flex items-center rounded-md bg-purple-50 border border-purple-200 px-1.5 py-0.5 text-[10px] font-bold text-purple-700">
                                                Blocage détecté
                                            </span>
                                        @endif
                                        <span class="text-[10px] font-mono text-slate-400">#{{ $tache->cle_unique }}</span>
                                    </div>
                                </div>
                            </td>

                            {{-- Faculté --}}
                            <td class="whitespace-nowrap px-4 py-3.5 text-xs text-slate-700">
                                @if($tache->faculte)
                                    <span class="font-semibold text-slate-900">{{ $tache->faculte->code }}</span>
                                    <span class="block text-[11px] text-slate-500">{{ $tache->faculte->nom }}</span>
                                @else
                                    <span class="text-slate-400">Transverse</span>
                                @endif
                            </td>

                            {{-- Assignation --}}
                            <td class="whitespace-nowrap px-4 py-3.5 text-xs">
                                @if($tache->assigne)
                                    <span class="font-semibold text-slate-900">{{ $tache->assigne->name }}</span>
                                    <span class="block text-[10px] text-slate-500">{{ $tache->assigne->role_libelle }}</span>
                                @else
                                    <span class="text-slate-400 italic">Non assignée</span>
                                @endif
                            </td>

                            {{-- Échéance & Ancienneté --}}
                            <td class="whitespace-nowrap px-4 py-3.5 text-xs">
                                @if($tache->echeance_a)
                                    @php
                                        $estEchue = $tache->echeance_a->isPast();
                                        $estUrgente = !$estEchue && $tache->echeance_a->diffInHours(now()) <= 48;
                                    @endphp
                                    <div class="{{ $estEchue ? 'text-rose-700 font-bold' : ($estUrgente ? 'text-amber-700 font-bold' : 'text-slate-900') }}">
                                        Échéance : {{ $tache->echeance_a->format('d/m/Y H:i') }}
                                        @if($estEchue)
                                            <span class="block text-[10px] text-rose-600">Période échue</span>
                                        @elseif($estUrgente)
                                            <span class="block text-[10px] text-amber-600">Expire sous 48h</span>
                                        @endif
                                    </div>
                                @else
                                    <span class="text-slate-500">Sans date limite</span>
                                @endif
                                <span class="block text-[11px] text-slate-400 mt-0.5">
                                    Créée {{ $tache->created_at?->diffForHumans() }}
                                </span>
                            </td>

                            {{-- Statut --}}
                            <td class="whitespace-nowrap px-4 py-3.5">
                                @php
                                    $badgeStatut = match($tache->statut) {
                                        'pending' => ['bg' => 'bg-amber-50 text-amber-800 border-amber-200', 'nom' => 'En attente'],
                                        'in_progress' => ['bg' => 'bg-blue-50 text-blue-800 border-blue-200', 'nom' => 'En cours'],
                                        'waiting' => ['bg' => 'bg-purple-50 text-purple-800 border-purple-200', 'nom' => 'En suspension'],
                                        'completed' => ['bg' => 'bg-emerald-50 text-emerald-800 border-emerald-200', 'nom' => 'Terminée'],
                                        'cancelled' => ['bg' => 'bg-slate-100 text-slate-700 border-slate-200', 'nom' => 'Annulée'],
                                        'expired' => ['bg' => 'bg-rose-50 text-rose-800 border-rose-200', 'nom' => 'Expirée'],
                                        default => ['bg' => 'bg-slate-50 text-slate-700 border-slate-200', 'nom' => $tache->statut],
                                    };
                                @endphp
                                <span class="inline-flex items-center rounded-full border px-2.5 py-0.5 text-xs font-semibold {{ $badgeStatut['bg'] }}">
                                    {{ $badgeStatut['nom'] }}
                                </span>
                            </td>

                            {{-- Action --}}
                            <td class="whitespace-nowrap px-4 py-3.5 text-right text-xs">
                                @if($tache->source === 'inscription' && !empty($tache->data['inscription_id']))
                                    <a href="{{ route('admin.centre-travail.show', $tache->data['inscription_id']) }}"
                                       class="inline-flex items-center gap-1 rounded-xl bg-primary-800 text-white px-3 py-1.5 font-bold hover:bg-primary-900 transition focus:outline-none focus:ring-2 focus:ring-primary-500 shadow-xs">
                                        <span>Contrôler</span>
                                        <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                                    </a>
                                @elseif($tache->lien)
                                    <a href="{{ $tache->lien }}"
                                       class="inline-flex items-center gap-1 rounded-xl bg-slate-100 px-3 py-1.5 font-bold text-slate-800 hover:bg-primary-800 hover:text-white transition focus:outline-none focus:ring-2 focus:ring-primary-500">
                                        <span>Consulter</span>
                                        <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.5 4.5L21 12m0 0l-7.5 7.5M21 12H3"/></svg>
                                    </a>
                                @else
                                    <span class="text-slate-400">—</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="px-6 py-12 text-center">
                                <div class="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-slate-100 text-slate-500 mb-3">
                                    <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                                </div>
                                <p class="text-sm font-bold text-slate-900">Aucune tâche administrative trouvée</p>
                                <p class="mt-1 text-xs text-slate-500 max-w-sm mx-auto">Toutes les tâches correspondant à ces critères sont traitées, ou aucun dossier ne nécessite d'intervention.</p>
                                @if(!empty(array_filter($filtres)))
                                    <div class="mt-4">
                                        <a href="{{ route('admin.centre-travail.index') }}" class="inline-flex items-center rounded-xl bg-slate-100 px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-200 transition">
                                            Effacer les filtres
                                        </a>
                                    </div>
                                @endif
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{-- Pagination conservant les filtres --}}
        @if($taches->hasPages())
            <div class="border-t border-slate-200 px-4 py-3 sm:px-6">
                {{ $taches->links() }}
            </div>
        @endif
    </div>
    </form>
</div>
@endsection
