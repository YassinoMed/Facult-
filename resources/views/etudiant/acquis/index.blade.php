@extends('layouts.app')

@section('title', 'Mon Parcours & Acquis Pédagogiques — UniRegistre')
@section('meta_description', 'Consultez la synthèse officielle de vos crédits capitalisés, éléments compensés et matières restant à valider.')

@section('content')
<div class="mx-auto max-w-6xl px-4 sm:px-6 py-6 sm:py-8 space-y-6">

    <!-- En-tête principal -->
    <div class="relative overflow-hidden rounded-3xl border border-slate-200/90 bg-white p-6 sm:p-8 shadow-sm">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
            <div>
                <div class="flex flex-wrap items-center gap-2 mb-2">
                    <span class="inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-bold border border-emerald-200 bg-emerald-50 text-emerald-900">
                        <span class="h-2 w-2 rounded-full bg-emerald-500" aria-hidden="true"></span>
                        Régime légal LMD & Crédits
                    </span>
                    <span class="font-mono text-xs font-bold text-slate-500">
                        {{ $analyse['etudiant']['nom'] }} {{ $analyse['etudiant']['prenom'] }}
                        @if(!empty($analyse['etudiant']['cin']))
                            (CIN : {{ $analyse['etudiant']['cin'] }})
                        @endif
                    </span>
                </div>
                <h1 class="text-2xl sm:text-3xl font-black tracking-tight text-slate-900">
                    Mes Acquis Académiques & Progression
                </h1>
                <p class="mt-1 text-xs sm:text-sm text-slate-500">
                    Décret n° 2022-631 et Arrêté du 7 juillet 2023 — Traçabilité des crédits capitalisés, unités compensées et enseignements à rattraper.
                </p>
            </div>

            <!-- Badge statut délibération -->
            <div class="flex flex-col items-start md:items-end">
                @if($analyse['regime']['deliberation_publiee'])
                    <span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl text-xs font-bold bg-green-100 text-green-800 border border-green-200">
                        <svg class="w-4 h-4 text-green-600" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/></svg>
                        Résultats Officiellement Délibérés
                    </span>
                @else
                    <span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl text-xs font-bold bg-amber-100 text-amber-800 border border-amber-200">
                        <svg class="w-4 h-4 text-amber-600" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/></svg>
                        En attente de délibération du jury
                    </span>
                @endif
                <span class="text-xs text-slate-400 mt-1">Régime : {{ $analyse['regime']['nom'] }}</span>
            </div>
        </div>

        <!-- Cartes statistiques KPI -->
        <div class="mt-6 grid grid-cols-1 sm:grid-cols-3 gap-4">
            <div class="rounded-2xl border border-slate-100 bg-slate-50/70 p-4">
                <div class="text-xs font-semibold text-slate-500 uppercase tracking-wider">Crédits Capitalisés & Validés</div>
                <div class="mt-2 flex items-baseline gap-2">
                    <span class="text-3xl font-black text-emerald-600">{{ $analyse['totaux']['credits_acquis'] }}</span>
                    <span class="text-xs font-medium text-slate-400">ECTS définitivement acquis</span>
                </div>
            </div>

            <div class="rounded-2xl border border-slate-100 bg-slate-50/70 p-4">
                <div class="text-xs font-semibold text-slate-500 uppercase tracking-wider">Crédits Restant à Valider</div>
                <div class="mt-2 flex items-baseline gap-2">
                    <span class="text-3xl font-black {{ $analyse['totaux']['credits_a_obtenir'] > 0 ? 'text-amber-600' : 'text-slate-700' }}">
                        {{ $analyse['totaux']['credits_a_obtenir'] }}
                    </span>
                    <span class="text-xs font-medium text-slate-400">ECTS à repasser</span>
                </div>
            </div>

            <div class="rounded-2xl border border-slate-100 bg-slate-50/70 p-4">
                <div class="text-xs font-semibold text-slate-500 uppercase tracking-wider">Enseignements à Inscrire</div>
                <div class="mt-2 flex items-baseline gap-2">
                    <span class="text-3xl font-black text-indigo-600">{{ count($analyse['ecues_a_repasser']) }}</span>
                    <span class="text-xs font-medium text-slate-400">matières / éléments</span>
                </div>
            </div>
        </div>
    </div>

    @if(!$analyse['regime']['deliberation_publiee'])
        <!-- Alerte résultats provisoires -->
        <div class="rounded-2xl border border-amber-200 bg-amber-50/80 p-4 sm:p-5 flex items-start gap-4">
            <div class="p-2 rounded-xl bg-amber-100 text-amber-700">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
            </div>
            <div>
                <h2 class="text-sm font-bold text-amber-900">Procédure en attente de proclamation du jury</h2>
                <p class="mt-1 text-xs text-amber-800 leading-relaxed">
                    Conformément aux principes réglementaires, les notes présentées constituent un aperçu pédagogique provisoire.
                    L'attribution définitive des crédits et la validation des compensations demeurent subordonnées à la décision officielle et souveraine du jury de délibération.
                </p>
            </div>
        </div>
    @endif

    <!-- Détail des Unités d'Enseignement (UE) et ECUE -->
    <div class="space-y-4">
        <h2 class="text-lg font-bold text-slate-900 flex items-center gap-2">
            <span>Détail du parcours par Unité d'Enseignement (UE)</span>
        </h2>

        @forelse($analyse['ues'] as $ue)
            <div class="rounded-2xl border {{ $ue['est_validee'] ? 'border-emerald-200 bg-white' : 'border-slate-200 bg-white' }} shadow-sm overflow-hidden">
                <!-- En-tête de l'UE -->
                <div class="px-5 py-4 border-b border-slate-100 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 {{ $ue['est_validee'] ? 'bg-emerald-50/40' : 'bg-slate-50/50' }}">
                    <div class="flex items-center gap-3">
                        <span class="px-2.5 py-1 rounded-lg text-xs font-mono font-bold {{ $ue['est_validee'] ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-200 text-slate-700' }}">
                            {{ $ue['code'] }}
                        </span>
                        <div>
                            <h3 class="text-sm font-bold text-slate-900">{{ $ue['nom'] }}</h3>
                            <div class="text-xs text-slate-500">
                                Semestre : <span class="font-medium text-slate-700">{{ $ue['semestre'] }}</span>
                                • Crédits UE : <span class="font-medium text-slate-700">{{ $ue['credits'] }} ECTS</span>
                                @if($ue['moyenne'] !== null)
                                    • Moyenne : <span class="font-bold text-slate-800">{{ number_format($ue['moyenne'], 2) }}/20</span>
                                @endif
                            </div>
                        </div>
                    </div>

                    <!-- Statut UE -->
                    <div class="flex items-center gap-2">
                        @if($ue['est_capitalisee'])
                            <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-lg text-xs font-bold bg-emerald-100 text-emerald-800 border border-emerald-200">
                                <svg class="w-3.5 h-3.5 text-emerald-600" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
                                Capitalisée (≥ 10/20)
                            </span>
                        @elseif($ue['est_compensee'])
                            <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-lg text-xs font-bold bg-blue-100 text-blue-800 border border-blue-200">
                                Validée par compensation (Art. 9)
                            </span>
                        @else
                            <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-lg text-xs font-bold bg-rose-100 text-rose-800 border border-rose-200">
                                Non acquise — À repasser
                            </span>
                        @endif
                    </div>
                </div>

                <!-- Table des éléments constitutifs (ECUE / Matières) -->
                @if(!empty($ue['ecues']))
                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-xs">
                            <thead class="bg-slate-50/80 text-slate-600 border-b border-slate-100 uppercase tracking-wider font-semibold">
                                <tr>
                                    <th class="px-5 py-2.5">Code</th>
                                    <th class="px-5 py-2.5">Élément Constitutif (ECUE)</th>
                                    <th class="px-5 py-2.5 text-center">Crédits propres</th>
                                    <th class="px-5 py-2.5 text-center">Note /20</th>
                                    <th class="px-5 py-2.5">Statut académique</th>
                                    <th class="px-5 py-2.5">Règle d'application</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                @foreach($ue['ecues'] as $ecue)
                                    <tr class="hover:bg-slate-50/60 {{ $ecue['est_a_repasser'] ? 'bg-amber-50/20' : '' }}">
                                        <td class="px-5 py-3 font-mono font-medium text-slate-600">
                                            {{ $ecue['code'] }}
                                        </td>
                                        <td class="px-5 py-3 font-medium text-slate-900">
                                            {{ $ecue['nom'] }}
                                            @if($ecue['est_controle_continu_exclusif'])
                                                <span class="ml-1 inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-semibold bg-purple-100 text-purple-800">
                                                    CC Exclusif
                                                </span>
                                            @endif
                                        </td>
                                        <td class="px-5 py-3 text-center font-mono font-semibold text-slate-700">
                                            {{ $ecue['credits'] > 0 ? $ecue['credits'] . ' ECTS' : '—' }}
                                        </td>
                                        <td class="px-5 py-3 text-center font-mono font-bold">
                                            @if($ecue['note'] !== null)
                                                <span class="{{ $ecue['note'] >= 10 ? 'text-emerald-700' : 'text-slate-800' }}">
                                                    {{ number_format($ecue['note'], 2) }}
                                                </span>
                                            @else
                                                <span class="text-slate-400">—</span>
                                            @endif
                                        </td>
                                        <td class="px-5 py-3">
                                            @if($ecue['est_capitalise'])
                                                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-bold bg-emerald-100 text-emerald-800">
                                                    Capitalisé (Art. 8)
                                                </span>
                                            @elseif($ecue['est_dispense'])
                                                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-bold bg-blue-100 text-blue-800">
                                                    Dispense accordée
                                                </span>
                                            @elseif($ecue['est_equivalent'])
                                                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-bold bg-teal-100 text-teal-800">
                                                    Équivalence validée
                                                </span>
                                            @elseif($ecue['est_a_repasser'])
                                                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-bold bg-amber-100 text-amber-800">
                                                    À repasser
                                                </span>
                                            @else
                                                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-medium bg-slate-100 text-slate-700">
                                                    {{ $ecue['statut_libelle'] }}
                                                </span>
                                            @endif
                                        </td>
                                        <td class="px-5 py-3 text-slate-500 text-[11px]">
                                            {{ $ecue['motif'] }}
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        @empty
            <div class="rounded-2xl border border-dashed border-slate-300 p-8 text-center text-slate-500">
                Aucune unité d'enseignement enregistrée pour votre parcours actif.
            </div>
        @endforelse
    </div>

    <!-- Synthèse des éléments à réinscrire pour l'année de redoublement -->
    @if(!empty($analyse['ecues_a_repasser']))
        <div class="rounded-3xl border border-indigo-200 bg-indigo-50/40 p-6 shadow-sm">
            <h2 class="text-base font-bold text-indigo-950 flex items-center gap-2">
                <svg class="w-5 h-5 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
                Programme Prévisionnel de Réinscription
            </h2>
            <p class="mt-1 text-xs text-indigo-800">
                En vertu de l'article 10 de l'Arrêté du 7 juillet 2023, vos acquis officiels sont conservés sans réexamen. Votre contrat pédagogique de réinscription portera exclusivement sur les matières listées ci-dessous :
            </p>

            <div class="mt-4 grid grid-cols-1 md:grid-cols-2 gap-3">
                @foreach($analyse['ecues_a_repasser'] as $item)
                    <div class="flex items-center justify-between p-3 rounded-xl bg-white border border-indigo-100 shadow-xs">
                        <div>
                            <span class="font-mono text-xs font-bold text-indigo-600">{{ $item['code'] }}</span>
                            <span class="text-xs font-medium text-slate-800 ml-1.5">{{ $item['nom'] }}</span>
                            <div class="text-[11px] text-slate-400">{{ $item['ue_nom'] }} (Semestre {{ $item['semestre'] }})</div>
                        </div>
                        <span class="text-xs font-mono font-bold text-slate-700 bg-slate-100 px-2 py-1 rounded">
                            {{ $item['credits'] > 0 ? $item['credits'] . ' ECTS' : '0 ECTS' }}
                        </span>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

</div>
@endsection

