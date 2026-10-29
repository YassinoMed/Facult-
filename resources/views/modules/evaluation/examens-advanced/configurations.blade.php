@extends('layouts.app')

@section('title', 'Examens — Configurations & Modules')

@section('content')

@php
    $sessionsEnCours = $sessionsStats['open'] + $sessionsStats['ongoing'];
    $pointsAttention = $statsPreparation['sans_salle'] + $sallesSansCapacite + $epreuvesSansSurveillant;
@endphp

<div class="space-y-6">
    <x-page-header
        titre="Configurations d'examen &amp; Association de modules"
        description="Gouvernance académique, application de la règle R-05 (1 à 3 modules maximum par configuration) et contrôle des épreuves distinctes vs combinées."
        :fil="['Évaluation' => route('admin.evaluations.index'), 'Examens' => route('admin.evaluation.examens.tableau-de-bord'), 'Configurations' => '']"
    />

    @include('modules.evaluation.examens-advanced._nav-tabs')

    {{-- Bandeau des métriques R-05 & D-02 --}}
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <div class="rounded-2xl border border-slate-200/80 bg-white p-4 shadow-xs">
            <span class="text-xs font-semibold text-slate-500 uppercase tracking-wider">Configurations totales</span>
            <p class="mt-2 text-2xl font-black text-slate-900">{{ $auditConfigurations['total_configurations'] }}</p>
            <span class="text-[11px] text-slate-500">dans le périmètre</span>
        </div>

        <div class="rounded-2xl border border-emerald-200/80 bg-emerald-50/40 p-4 shadow-xs">
            <span class="text-xs font-semibold text-emerald-800 uppercase tracking-wider">Conformes (1 à 3 modules)</span>
            <p class="mt-2 text-2xl font-black text-emerald-700">{{ $auditConfigurations['total_conformes'] }}</p>
            <span class="text-[11px] text-emerald-700 font-medium">Respect de la règle R-05</span>
        </div>

        <div class="rounded-2xl border p-4 shadow-xs {{ $auditConfigurations['total_anomalies'] > 0 ? 'border-rose-200/80 bg-rose-50/40' : 'border-slate-200/80 bg-white' }}">
            <span class="text-xs font-semibold uppercase tracking-wider {{ $auditConfigurations['total_anomalies'] > 0 ? 'text-rose-700' : 'text-slate-500' }}">Anomalies (> 3 modules)</span>
            <p class="mt-2 text-2xl font-black {{ $auditConfigurations['total_anomalies'] > 0 ? 'text-rose-600' : 'text-slate-800' }}">{{ $auditConfigurations['total_anomalies'] }}</p>
            <span class="text-[11px] {{ $auditConfigurations['total_anomalies'] > 0 ? 'text-rose-600 font-semibold' : 'text-slate-500' }}">
                {{ $auditConfigurations['total_anomalies'] > 0 ? 'Dépassement de la limite autorisée' : 'Aucun dépassement' }}
            </span>
        </div>

        <div class="rounded-2xl border border-slate-200/80 bg-white p-4 shadow-xs">
            <span class="text-xs font-semibold text-slate-500 uppercase tracking-wider">Modalités d'épreuve</span>
            <div class="mt-2 flex items-baseline gap-2">
                <span class="text-lg font-bold text-slate-800">{{ $auditConfigurations['total_distinctes'] }} distinctes</span>
                <span class="text-xs text-slate-500">/ {{ $auditConfigurations['total_combinees'] }} combinées</span>
            </div>
            <span class="text-[11px] text-slate-500">Validation explicite obligatoire pour les combinées</span>
        </div>
    </div>

    {{-- Filtre par session et année --}}
    <div class="flex flex-wrap items-center justify-between gap-4 rounded-2xl bg-white p-4 border border-slate-200/80 shadow-xs">
        <form method="GET" action="{{ route('admin.evaluation.examens.configurations') }}" class="flex flex-wrap items-center gap-3">
            <select name="annee_id" onchange="this.form.submit()" class="rounded-xl border border-slate-200 bg-white px-3 py-1.5 text-sm font-medium text-slate-800 focus:border-primary-500 focus:outline-none">
                <option value="">Toutes les années</option>
                @foreach($annees as $annee)
                    <option value="{{ $annee->id }}" @selected($anneeId === (int)$annee->id)>{{ $annee->libelle }}</option>
                @endforeach
            </select>

            <select name="session_id" onchange="this.form.submit()" class="rounded-xl border border-slate-200 bg-white px-3 py-1.5 text-sm font-medium text-slate-800 focus:border-primary-500 focus:outline-none">
                <option value="">Toutes les sessions</option>
                @foreach($sessions as $session)
                    <option value="{{ $session->id }}" @selected($sessionId === (int)$session->id)>{{ $session->nom }} ({{ $session->statut }})</option>
                @endforeach
            </select>
        </form>
        <span class="text-xs text-slate-500 font-medium">{{ $examens->total() }} configuration(s)</span>
    </div>

    {{-- Tableau des configurations d'examen --}}
    <div class="rounded-2xl border border-slate-200/80 bg-white p-6 shadow-xs">
        <div class="overflow-x-auto">
            <table class="examens-tableau w-full text-left text-sm">
                <thead>
                    <tr class="border-b border-slate-100 text-xs font-semibold text-slate-500 uppercase tracking-wider">
                        <th scope="col" class="pb-3">Examen &amp; Code</th>
                        <th scope="col" class="pb-3">Session parente</th>
                        <th scope="col" class="pb-3">Programme / Niveau</th>
                        <th scope="col" class="pb-3">Modules rattachés</th>
                        <th scope="col" class="pb-3">Modalité épreuve</th>
                        <th scope="col" class="pb-3">Conformité R-05</th>
                        <th scope="col" class="pb-3 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse($examens as $examen)
                        @php
                            $nbModules = $examen->epreuves_count;
                            $horsLimite = $nbModules > \App\Modules\Evaluation\Services\ExamSessionConfigurationService::MAX_MODULES_PAR_CONFIGURATION;
                            $modalite = $examen->observations && str_contains(strtolower($examen->observations), 'combinée') ? 'combinee' : 'distincte';
                        @endphp
                        <tr class="hover:bg-slate-50/60 transition-colors">
                            <td class="py-3.5 pr-3">
                                <span class="font-bold text-slate-900 block">{{ $examen->libelle }}</span>
                                <span class="font-mono text-xs text-slate-400">#{{ $examen->code }}</span>
                            </td>
                            <td class="py-3.5 pr-3 text-xs text-slate-600">
                                {{ $examen->session?->nom ?? '—' }}
                                @if($examen->session?->estVerrouillee())
                                    <span class="block text-[10px] text-slate-400 font-medium">Session verrouillée</span>
                                @endif
                            </td>
                            <td class="py-3.5 pr-3 text-xs text-slate-600">
                                <span class="font-semibold text-slate-800 block">{{ $examen->programme?->nom ?? 'Tous programmes' }}</span>
                                @if($examen->niveau)
                                    <span class="text-slate-500">{{ $examen->niveau->nom }}</span>
                                @endif
                            </td>
                            <td class="py-3.5 pr-3 text-xs">
                                <span class="inline-flex items-center gap-1.5 rounded-lg px-2.5 py-1 font-semibold {{ $horsLimite ? 'bg-rose-100 text-rose-800' : ($nbModules === 0 ? 'bg-amber-100 text-amber-800' : 'bg-emerald-50 text-emerald-800 border border-emerald-200') }}">
                                    <span>{{ $nbModules }} module{{ $nbModules > 1 ? 's' : '' }}</span>
                                </span>
                            </td>
                            <td class="py-3.5 pr-3 text-xs">
                                @if($modalite === 'combinee')
                                    <span class="inline-flex rounded-md bg-purple-50 text-purple-800 px-2 py-0.5 font-medium border border-purple-200">
                                        Épreuve combinée
                                    </span>
                                @else
                                    <span class="inline-flex rounded-md bg-slate-100 text-slate-700 px-2 py-0.5 font-medium">
                                        Épreuves distinctes
                                    </span>
                                @endif
                            </td>
                            <td class="py-3.5 pr-3 text-xs">
                                @if($horsLimite)
                                    <span class="inline-flex items-center gap-1 font-bold text-rose-600">
                                        <svg class="h-4 w-4" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.28 7.22a.75.75 0 00-1.06 1.06L8.94 10l-1.72 1.72a.75.75 0 101.06 1.06L10 11.06l1.72 1.72a.75.75 0 101.06-1.06L11.06 10l1.72-1.72a.75.75 0 00-1.06-1.06L10 8.94 8.28 7.22z" clip-rule="evenodd" /></svg>
                                        Non conforme (> 3)
                                    </span>
                                @else
                                    <span class="inline-flex items-center gap-1 font-semibold text-emerald-700">
                                        <svg class="h-4 w-4" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.857-9.809a.75.75 0 00-1.214-.882l-3.483 4.79-1.88-1.88a.75.75 0 10-1.06 1.061l2.5 2.5a.75.75 0 001.137-.089l4-5.5z" clip-rule="evenodd" /></svg>
                                        Conforme R-05
                                    </span>
                                @endif
                            </td>
                            <td class="py-3.5 text-right whitespace-nowrap">
                                <a href="{{ route('admin.evaluations.examens.index') }}" class="inline-flex items-center gap-1 rounded-lg bg-primary-50 px-2.5 py-1 text-xs font-bold text-primary-700 hover:bg-primary-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-500/40">
                                    Configurer &rarr;
                                </a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="py-8 text-center text-sm text-slate-500">
                                Aucune configuration d'examen répertoriée pour ce filtre.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="mt-4">
            {{ $examens->links() }}
        </div>
    </div>
</div>
@endsection