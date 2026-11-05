@extends('layouts.app')

@section('title', 'Examens — Centre des anomalies des groupes virtuels')

@section('content')
@php
    $f = $filtres_appliques ?? [];
    $compteurs = $compteurs ?? ['total' => 0, 'bloquants' => 0, 'avertissements' => 0, 'derives' => 0, 'capacites' => 0];
@endphp

<div class="space-y-6">
    <x-page-header
        titre="Centre des anomalies des groupes virtuels"
        description="Supervision proactive, détection des dérives de population, contrôles capacitaires D-04 et actions correctives guidées."
        :fil="[
            'Évaluation' => route('admin.evaluations.index'),
            'Examens' => route('admin.evaluation.examens.tableau-de-bord'),
            'Groupes virtuels' => route('admin.evaluation.examens.groupes-virtuels.index'),
            'Centre des anomalies' => '',
        ]"
    />

    @include('modules.evaluation.examens-advanced._nav-tabs')

    {{-- Bandeau d'information et bascule rapide --}}
    <div class="flex flex-wrap items-center justify-between gap-4 rounded-2xl border border-slate-200 bg-white p-4 shadow-2xs">
        <div class="flex items-center gap-3">
            <span class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-rose-100 text-rose-700">
                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" />
                </svg>
            </span>
            <div>
                <h3 class="text-sm font-bold text-slate-900">Diagnostic centralisé des blocages & dérives</h3>
                <p class="text-xs text-slate-500">
                    Les anomalies bloquantes empêchent la certification et la publication officielle. Les avertissements signalent des ressources à compléter.
                </p>
            </div>
        </div>
        <div class="flex items-center gap-2">
            <a href="{{ route('admin.evaluation.examens.groupes-virtuels.index') }}"
               class="inline-flex items-center gap-1.5 rounded-xl border border-slate-200 bg-white px-3.5 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-50 shadow-2xs transition">
                ← Retour au tableau des groupes
            </a>
            <a href="{{ route('admin.evaluation.examens.groupes', ['mode' => 'B']) }}"
               class="inline-flex items-center gap-1.5 rounded-xl bg-purple-50 text-purple-900 border border-purple-200 px-3.5 py-2 text-xs font-bold hover:bg-purple-100 shadow-2xs transition">
                🏛️ Planificateur D-04
            </a>
        </div>
    </div>

    {{-- KPIs du Centre des anomalies --}}
    <div class="grid grid-cols-2 sm:grid-cols-5 gap-3">
        <a href="{{ route('admin.evaluation.examens.groupes-virtuels.anomalies', array_merge(request()->except('gravite'), [])) }}"
           class="rounded-2xl border p-4 shadow-2xs transition {{ empty($f['gravite']) ? 'border-slate-400 bg-slate-50/80 ring-2 ring-slate-400/40' : 'border-slate-200 bg-white hover:border-slate-300' }}">
            <span class="text-[11px] font-bold uppercase tracking-wider text-slate-500">Total anomalies</span>
            <div class="mt-1 text-2xl font-black text-slate-900">{{ $compteurs['total'] }}</div>
            <p class="mt-0.5 text-[11px] text-slate-400">Toutes gravités</p>
        </a>

        <a href="{{ route('admin.evaluation.examens.groupes-virtuels.anomalies', array_merge(request()->except('gravite'), ['gravite' => 'bloquant'])) }}"
           class="rounded-2xl border p-4 shadow-2xs transition {{ ($f['gravite'] ?? '') === 'bloquant' ? 'border-rose-500 bg-rose-50/90 ring-2 ring-rose-500/50' : 'border-rose-200 bg-rose-50/40 hover:border-rose-300' }}">
            <span class="text-[11px] font-bold uppercase tracking-wider text-rose-800">Bloquants majeurs</span>
            <div class="mt-1 text-2xl font-black text-rose-950">{{ $compteurs['bloquants'] }}</div>
            <p class="mt-0.5 text-[11px] text-rose-700">Publication interdite</p>
        </a>

        <a href="{{ route('admin.evaluation.examens.groupes-virtuels.anomalies', array_merge(request()->except('gravite'), ['gravite' => 'avertissement'])) }}"
           class="rounded-2xl border p-4 shadow-2xs transition {{ ($f['gravite'] ?? '') === 'avertissement' ? 'border-amber-500 bg-amber-50/90 ring-2 ring-amber-500/50' : 'border-amber-200 bg-amber-50/40 hover:border-amber-300' }}">
            <span class="text-[11px] font-bold uppercase tracking-wider text-amber-800">Avertissements</span>
            <div class="mt-1 text-2xl font-black text-amber-950">{{ $compteurs['avertissements'] }}</div>
            <p class="mt-0.5 text-[11px] text-amber-700">Ressources à pourvoir</p>
        </a>

        <div class="rounded-2xl border border-orange-200 bg-orange-50/40 p-4 shadow-2xs">
            <span class="text-[11px] font-bold uppercase tracking-wider text-orange-800">Dérives détectées</span>
            <div class="mt-1 text-2xl font-black text-orange-950">{{ $compteurs['derives'] }}</div>
            <p class="mt-0.5 text-[11px] text-orange-700">Changement effectif</p>
        </div>

        <div class="rounded-2xl border border-purple-200 bg-purple-50/40 p-4 shadow-2xs">
            <span class="text-[11px] font-bold uppercase tracking-wider text-purple-800">Capacités & Salles</span>
            <div class="mt-1 text-2xl font-black text-purple-950">{{ $compteurs['capacites'] }}</div>
            <p class="mt-0.5 text-[11px] text-purple-700">Déficits D-04</p>
        </div>
    </div>

    {{-- Liste détaillée des anomalies --}}
    <div class="rounded-2xl border border-slate-200 bg-white shadow-2xs overflow-hidden">
        <div class="border-b border-slate-100 bg-slate-50/60 p-4 flex flex-wrap items-center justify-between gap-3">
            <div>
                <h3 class="text-sm font-bold text-slate-900">Anomalies détectées sur le périmètre</h3>
                <p class="text-xs text-slate-500">Chaque anomalie précise sa cause, la règle enfreinte, son impact et l'action corrective disponible.</p>
            </div>
            <span class="text-xs font-semibold text-slate-500">
                {{ count($anomalies) }} anomalie(s) répertoriée(s)
            </span>
        </div>

        @if(empty($anomalies))
            <div class="p-12 text-center">
                <span class="inline-flex h-12 w-12 items-center justify-center rounded-2xl bg-emerald-100 text-emerald-700 mb-3">
                    <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                    </svg>
                </span>
                <h4 class="text-base font-bold text-slate-900">Aucune anomalie détectée</h4>
                <p class="mt-1 text-xs text-slate-500 max-w-md mx-auto">
                    Tous les groupes virtuels analysés respectent scrupuleusement la correspondance académique, les effectifs éligibles et les jauges de salle.
                </p>
            </div>
        @else
            <div class="divide-y divide-slate-100">
                @foreach($anomalies as $a)
                    <div class="p-5 hover:bg-slate-50/50 transition">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div class="flex items-start gap-3">
                                <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-bold {{ ($a['type'] ?? '') === 'bloquant' ? 'bg-rose-100 text-rose-800 border border-rose-200' : 'bg-amber-100 text-amber-800 border border-amber-200' }}">
                                    {{ ($a['type'] ?? '') === 'bloquant' ? '🔴 Bloquant' : '🟡 Avertissement' }}
                                </span>
                                <div>
                                    <h4 class="text-sm font-bold text-slate-900">{{ $a['titre'] }}</h4>
                                    <div class="mt-1 flex flex-wrap items-center gap-2 text-xs text-slate-500">
                                        <a href="{{ route('admin.evaluation.examens.groupes-virtuels.show', $a['groupe_id']) }}" class="font-bold text-indigo-600 hover:text-indigo-800 hover:underline">
                                            {{ $a['groupe_libelle'] }}
                                        </a>
                                        <span>•</span>
                                        <span>Matière : {{ $a['matiere_nom'] }}</span>
                                        <span>•</span>
                                        <span>Faculté : {{ $a['faculte_nom'] }}</span>
                                    </div>
                                </div>
                            </div>

                            {{-- Actions correctives --}}
                            <div class="flex items-center gap-2">
                                @if(($a['action_type'] ?? '') === 'resynchroniser')
                                    <form method="POST" action="{{ route('admin.evaluation.examens.groupes-virtuels.groupe.resynchroniser', $a['groupe_id']) }}">
                                        @csrf
                                        <button type="submit"
                                                class="rounded-xl bg-amber-600 hover:bg-amber-700 px-3 py-1.5 text-xs font-bold text-white shadow-2xs transition">
                                            Actualiser la proposition
                                        </button>
                                    </form>
                                @elseif(($a['action_type'] ?? '') === 'salle_d04' && !empty($a['epreuve_id']))
                                    <a href="{{ route('admin.evaluation.examens.groupes', ['mode' => 'B', 'epreuve_id' => $a['epreuve_id'], 'examen_id' => $a['examen_id']]) }}"
                                       class="rounded-xl bg-purple-600 hover:bg-purple-700 px-3 py-1.5 text-xs font-bold text-white shadow-2xs transition">
                                        🏛️ Ajuster la salle (D-04)
                                    </a>
                                @endif
                                <a href="{{ route('admin.evaluation.examens.groupes-virtuels.show', $a['groupe_id']) }}"
                                   class="rounded-xl border border-slate-200 bg-white hover:bg-slate-50 px-3 py-1.5 text-xs font-semibold text-slate-700 shadow-2xs transition">
                                    Voir la fiche
                                </a>
                            </div>
                        </div>

                        {{-- Grille des 6 attributs d'analyse formelle --}}
                        <div class="mt-4 grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3 rounded-xl bg-slate-50 p-3.5 text-xs border border-slate-100">
                            <div>
                                <span class="font-bold text-slate-700">1. Détection :</span>
                                <p class="mt-0.5 text-slate-600">{{ $a['detecte'] }}</p>
                            </div>
                            <div>
                                <span class="font-bold text-slate-700">2. Règle concernée :</span>
                                <p class="mt-0.5 text-slate-600">{{ $a['regle'] }}</p>
                            </div>
                            <div>
                                <span class="font-bold text-slate-700">3. Impact sur l'examen :</span>
                                <p class="mt-0.5 text-slate-600">{{ $a['impact'] }}</p>
                            </div>
                            <div>
                                <span class="font-bold text-slate-700">4. Caractère :</span>
                                <p class="mt-0.5 font-semibold {{ ($a['type'] ?? '') === 'bloquant' ? 'text-rose-700' : 'text-amber-700' }}">
                                    {{ ($a['type'] ?? '') === 'bloquant' ? 'Bloquant (Visa impossible)' : 'Informatif / Avertissement' }}
                                </p>
                            </div>
                            <div>
                                <span class="font-bold text-slate-700">5. Action corrective autorisée :</span>
                                <p class="mt-0.5 text-slate-600">{{ $a['action_corrective'] }}</p>
                            </div>
                            <div>
                                <span class="font-bold text-slate-700">6. Conditions de revalidation :</span>
                                <p class="mt-0.5 text-slate-600">{{ $a['conditions_revalidation'] }}</p>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </div>
</div>
@endsection

