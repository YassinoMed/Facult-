@extends('layouts.app')

@section('title', 'Examens — Sessions d\'examen & Gouvernance')

@section('content')

@php
    $sessionsEnCours = $sessionsStats['open'] + $sessionsStats['ongoing'];
    $pointsAttention = $statsPreparation['sans_salle'] + $sallesSansCapacite + $epreuvesSansSurveillant;
@endphp

<div class="space-y-6">
    <x-page-header
        titre="Sessions d'examen &amp; Cycles académiques"
        description="Configuration et gouvernance des cycles de session (principale, rattrapage, exceptionnelle), modes A/B et validation de readiness."
        :fil="['Évaluation' => route('admin.evaluations.index'), 'Examens' => route('admin.evaluation.examens.tableau-de-bord'), 'Sessions' => '']"
    >
        <x-slot:actions>
            <a href="{{ route('admin.evaluations.sessions.create') }}" class="inline-flex items-center gap-2 rounded-xl bg-primary-800 px-4 py-2 text-sm font-semibold text-white shadow-xs hover:bg-primary-900 transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-500/50">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                Nouvelle session
            </a>
        </x-slot:actions>
    </x-page-header>

    @include('modules.evaluation.examens-advanced._nav-tabs')

    {{-- Filtres & Périmètre --}}
    <div class="flex flex-wrap items-center justify-between gap-4 rounded-2xl bg-white p-4 border border-slate-200/80 shadow-xs">
        <form method="GET" action="{{ route('admin.evaluation.examens.sessions') }}" class="flex flex-wrap items-center gap-3">
            <div class="relative">
                <label for="filtre-annee" class="sr-only">Filtrer par année</label>
                <select id="filtre-annee" name="annee_id" onchange="this.form.submit()" class="rounded-xl border border-slate-200 bg-white py-1.5 pl-3 pr-8 text-sm font-medium text-slate-800 focus:border-primary-500 focus:outline-none">
                    <option value="">Toutes les années</option>
                    @foreach($annees as $annee)
                        <option value="{{ $annee->id }}" @selected($anneeId === (int)$annee->id)>{{ $annee->libelle }} @if($annee->active) (En cours) @endif</option>
                    @endforeach
                </select>
            </div>

            <div class="relative">
                <label for="filtre-statut" class="sr-only">Filtrer par statut</label>
                <select id="filtre-statut" name="statut" onchange="this.form.submit()" class="rounded-xl border border-slate-200 bg-white py-1.5 pl-3 pr-8 text-sm font-medium text-slate-800 focus:border-primary-500 focus:outline-none">
                    <option value="">Tous les statuts</option>
                    @foreach($statuts as $st)
                        <option value="{{ $st }}" @selected($statutSelectionne === $st)>{{ ucfirst($st) }}</option>
                    @endforeach
                </select>
            </div>
        </form>
        <span class="text-xs text-slate-500 font-medium">{{ $sessions->total() }} session(s) répertoriée(s)</span>
    </div>

    {{-- Liste des sessions avec readiness & Mode A/B --}}
    <div class="rounded-2xl border border-slate-200/80 bg-white p-6 shadow-xs">
        <div class="overflow-x-auto">
            <table class="examens-tableau w-full text-left text-sm">
                <thead>
                    <tr class="border-b border-slate-100 text-xs font-semibold text-slate-500 uppercase tracking-wider">
                        <th scope="col" class="pb-3">Code &amp; Nom</th>
                        <th scope="col" class="pb-3">Cycle &amp; Type</th>
                        <th scope="col" class="pb-3">Mode d'organisation</th>
                        <th scope="col" class="pb-3">Période</th>
                        <th scope="col" class="pb-3 text-center">Examens / Épreuves</th>
                        <th scope="col" class="pb-3">Statut &amp; Readiness</th>
                        <th scope="col" class="pb-3 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse($sessions as $session)
                        @php
                            $audit = $auditsSessions[$session->id] ?? null;
                            $modeOrg = $session->modeOrganisation();
                        @endphp
                        <tr class="hover:bg-slate-50/60 transition-colors">
                            <td class="py-3.5 pr-3">
                                <span class="font-bold text-slate-900 block">{{ $session->nom }}</span>
                                <span class="font-mono text-xs text-slate-400">#{{ $session->code }}</span>
                            </td>
                            <td class="py-3.5 pr-3 text-xs">
                                <span class="inline-flex rounded-md font-semibold px-2 py-0.5 capitalize {{ $session->type === 'principale' ? 'bg-blue-50 text-blue-700' : ($session->type === 'rattrapage' ? 'bg-amber-50 text-amber-800' : 'bg-purple-50 text-purple-700') }}">
                                    {{ $session->type }}
                                </span>
                                @if($session->origine)
                                    <span class="block mt-1 text-[11px] text-slate-500">
                                        Origine : <a href="{{ route('admin.evaluations.sessions.show', $session->origine) }}" class="underline hover:text-primary-700">{{ $session->origine->nom }}</a>
                                    </span>
                                @endif
                            </td>
                            <td class="py-3.5 pr-3 text-xs">
                                <span class="inline-flex rounded-lg px-2.5 py-1 font-medium {{ $modeOrg === 'mode_b' ? 'bg-purple-50 text-purple-800 border border-purple-200' : 'bg-slate-100 text-slate-800' }}">
                                    {{ $session->modeOrganisationLabel() }}
                                </span>
                            </td>
                            <td class="py-3.5 pr-3 text-xs text-slate-600 whitespace-nowrap">
                                {{ $session->date_debut?->format('d/m/Y') }} &rarr; {{ $session->date_fin?->format('d/m/Y') }}
                            </td>
                            <td class="py-3.5 pr-3 text-center text-xs font-semibold text-slate-800">
                                <span>{{ $session->examens_count }} examens</span>
                                <span class="text-slate-400 block text-[11px] font-normal">{{ $session->epreuves_count }} épreuves</span>
                            </td>
                            <td class="py-3.5 pr-3 text-xs">
                                <div class="flex flex-col gap-1">
                                    <span class="inline-flex rounded-full px-2.5 py-0.5 font-bold w-fit {{ $session->statut === 'open' ? 'bg-emerald-100 text-emerald-800' : ($session->statut === 'locked' ? 'bg-slate-800 text-white' : 'bg-slate-100 text-slate-700') }}">
                                        {{ ucfirst($session->statut) }}
                                    </span>
                                    @if($session->estVerrouillee())
                                        <span class="text-[10px] text-slate-400 font-medium">Verrouillée</span>
                                    @elseif($audit && $audit['conforme'])
                                        <span class="text-[10px] font-semibold text-emerald-600">✓ Prête pour ouverture</span>
                                    @elseif($audit && !empty($audit['bloquants']))
                                        <span class="text-[10px] font-semibold text-rose-600" title="{{ implode(' | ', $audit['bloquants']) }}">
                                            ⚠ {{ count($audit['bloquants']) }} point(s) bloquant(s)
                                        </span>
                                    @endif
                                </div>
                            </td>
                            <td class="py-3.5 text-right whitespace-nowrap">
                                <a href="{{ route('admin.evaluations.sessions.show', $session) }}" class="inline-flex items-center gap-1 rounded-lg bg-primary-50 px-2.5 py-1 text-xs font-bold text-primary-700 hover:bg-primary-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-500/40">
                                    Détails &rarr;
                                </a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="py-8 text-center text-sm text-slate-500">
                                Aucune session d'examen répertoriée pour ce périmètre.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="mt-4">
            {{ $sessions->links() }}
        </div>
    </div>
</div>
@endsection
