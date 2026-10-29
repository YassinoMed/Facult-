@extends('layouts.app')

@section('title', 'Évaluations — Tableau de bord')

@section('content')
    <div class="mx-auto max-w-6xl px-4 sm:px-6 py-8 space-y-6">
        <x-page-header titre="Évaluation académique" description="Sessions → examens → épreuves → notes → délibérations."
            :fil="['Évaluations' => '#']">
            <x-slot:actions>
                <x-btn href="{{ route('admin.evaluations.planning') }}">
                    <svg class="w-4 h-4 mr-1.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                    </svg>
                    Planning
                </x-btn>
                <x-btn variant="secondary" href="{{ route('admin.evaluations.epreuves.index') }}">Épreuves</x-btn>
                <x-btn variant="secondary" href="{{ route('admin.evaluations.anomalies.index') }}">Anomalies</x-btn>
            </x-slot:actions>
        </x-page-header>

        {{-- Alertes opérationnelles --}}
        @if($epreuvesSansSalle > 0 || $epreuvesSansSurveillant > 0 || $notesAValider > 0)
            <x-alert type="warning" :dismissible="true">
                <strong>Attention opérationnelle :</strong>
                {{ $epreuvesSansSalle }} épreuve(s) sans salle,
                {{ $epreuvesSansSurveillant }} sans surveillant assigné,
                {{ $notesAValider }} note(s) en attente de validation.
            </x-alert>
        @endif

        {{-- Grille des indicateurs clés --}}
        <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-3">
            @foreach([
                ['libelle' => 'Sessions actives', 'valeur' => $sessionsActives, 'type' => 'neutre', 'icone' => 'M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10'],
                ['libelle' => 'Examens planifiés', 'valeur' => $examensPlanifies, 'type' => 'neutre', 'icone' => 'M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2'],
                ['libelle' => 'Épreuves sans salle', 'valeur' => $epreuvesSansSalle, 'type' => $epreuvesSansSalle > 0 ? 'rose' : 'neutre', 'icone' => 'M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4'],
                ['libelle' => 'Sans surveillant', 'valeur' => $epreuvesSansSurveillant, 'type' => $epreuvesSansSurveillant > 0 ? 'rose' : 'neutre', 'icone' => 'M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z'],
                ['libelle' => 'Notes à saisir', 'valeur' => $notesManquantes, 'type' => 'neutre', 'icone' => 'M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z'],
                ['libelle' => 'Notes à valider', 'valeur' => $notesAValider, 'type' => $notesAValider > 0 ? 'amber' : 'neutre', 'icone' => 'M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z'],
                ['libelle' => 'Délibérations en attente', 'valeur' => $deliberationsEnAttente, 'type' => $deliberationsEnAttente > 0 ? 'amber' : 'neutre', 'icone' => 'M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z'],
            ] as $stat)
                <div class="rounded-2xl border p-4 bg-white shadow-xs transition-shadow hover:shadow-sm
                    {{ $stat['type'] === 'rose' ? 'border-rose-200 bg-rose-50/30' : ($stat['type'] === 'amber' ? 'border-amber-200 bg-amber-50/30' : 'border-slate-200') }}">
                    <div class="flex items-center justify-between">
                        <span class="text-[11px] font-bold uppercase tracking-wider
                            {{ $stat['type'] === 'rose' ? 'text-rose-600' : ($stat['type'] === 'amber' ? 'text-amber-600' : 'text-slate-500') }}">
                            {{ $stat['libelle'] }}
                        </span>
                        <svg class="w-4 h-4 {{ $stat['type'] === 'rose' ? 'text-rose-400' : ($stat['type'] === 'amber' ? 'text-amber-400' : 'text-slate-400') }}" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="{{ $stat['icone'] }}" />
                        </svg>
                    </div>
                    <p class="mt-2 text-2xl font-black tabular-nums
                        {{ $stat['type'] === 'rose' ? 'text-rose-700' : ($stat['type'] === 'amber' ? 'text-amber-700' : 'text-slate-900') }}">
                        {{ $stat['valeur'] }}
                    </p>
                </div>
            @endforeach
        </div>

        {{-- Accès direct aux modules opérationnels --}}
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <h3 class="text-xs font-bold uppercase tracking-wider text-slate-500 mb-3 flex items-center gap-2">
                    <svg class="w-4 h-4 text-primary-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                    </svg>
                    Sessions & Organisation des examens
                </h3>
                <div class="grid grid-cols-2 gap-2 text-xs">
                    <a href="{{ route('admin.evaluations.sessions.index') }}" class="p-3 rounded-xl border border-slate-100 hover:border-primary-200 hover:bg-primary-50/40 transition-colors block">
                        <strong class="block text-slate-800">Sessions</strong>
                        <span class="text-[11px] text-slate-400">Périodes et calendriers</span>
                    </a>
                    <a href="{{ route('admin.evaluations.examens.index') }}" class="p-3 rounded-xl border border-slate-100 hover:border-primary-200 hover:bg-primary-50/40 transition-colors block">
                        <strong class="block text-slate-800">Examens</strong>
                        <span class="text-[11px] text-slate-400">Groupements d'épreuves</span>
                    </a>
                    <a href="{{ route('admin.evaluations.affectations') }}" class="p-3 rounded-xl border border-slate-100 hover:border-primary-200 hover:bg-primary-50/40 transition-colors block">
                        <strong class="block text-slate-800">Affectations</strong>
                        <span class="text-[11px] text-slate-400">Répartition candidats/salles</span>
                    </a>
                    <a href="{{ route('admin.evaluations.surveillances.index') }}" class="p-3 rounded-xl border border-slate-100 hover:border-primary-200 hover:bg-primary-50/40 transition-colors block">
                        <strong class="block text-slate-800">Surveillances</strong>
                        <span class="text-[11px] text-slate-400">Plannings enseignants</span>
                    </a>
                </div>
            </div>

            <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <h3 class="text-xs font-bold uppercase tracking-wider text-slate-500 mb-3 flex items-center gap-2">
                    <svg class="w-4 h-4 text-primary-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z" />
                    </svg>
                    Contrôle, Documents & Délibérations
                </h3>
                <div class="grid grid-cols-2 gap-2 text-xs">
                    <a href="{{ route('admin.evaluations.anomalies.index') }}" class="p-3 rounded-xl border border-slate-100 hover:border-primary-200 hover:bg-primary-50/40 transition-colors block">
                        <strong class="block text-slate-800">Centre d'anomalies</strong>
                        <span class="text-[11px] text-slate-400">Incohérences & barèmes</span>
                    </a>
                    <a href="{{ route('admin.evaluations.documents-batch.index') }}" class="p-3 rounded-xl border border-slate-100 hover:border-primary-200 hover:bg-primary-50/40 transition-colors block">
                        <strong class="block text-slate-800">Documents groupés</strong>
                        <span class="text-[11px] text-slate-400">Relevés & attestations ZIP</span>
                    </a>
                    <a href="{{ route('admin.evaluations.historique-notes.index') }}" class="p-3 rounded-xl border border-slate-100 hover:border-primary-200 hover:bg-primary-50/40 transition-colors block">
                        <strong class="block text-slate-800">Historique des notes</strong>
                        <span class="text-[11px] text-slate-400">Audit et traçabilité</span>
                    </a>
                    <a href="{{ route('admin.evaluations.deliberations.index') }}" class="p-3 rounded-xl border border-slate-100 hover:border-primary-200 hover:bg-primary-50/40 transition-colors block">
                        <strong class="block text-slate-800">Délibérations</strong>
                        <span class="text-[11px] text-slate-400">Jury & procès-verbaux</span>
                    </a>
                </div>
            </div>
        </div>
    </div>
@endsection
