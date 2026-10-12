@extends('layouts.app')

@section('title', 'Évaluations — Tableau de bord')

@section('content')
    <div class="mx-auto max-w-6xl px-4 sm:px-6 py-8">
        <x-page-header titre="Évaluation académique" description="Sessions → examens → épreuves → notes → délibérations."
            :fil="['Évaluations' => '#']">
            <x-slot:actions>
                <x-btn href="{{ route('admin.evaluations.planning') }}">Planning</x-btn>
                <x-btn variant="secondary" href="{{ route('admin.evaluations.sessions.index') }}">Sessions</x-btn>
                <x-btn variant="secondary" href="{{ route('admin.evaluations.examens.index') }}">Examens</x-btn>
                <x-btn variant="secondary" href="{{ route('admin.evaluations.epreuves.index') }}">Épreuves</x-btn>
                <x-btn variant="secondary" href="{{ route('admin.evaluations.affectations') }}">Affectations</x-btn>
                <x-btn variant="secondary" href="{{ route('admin.evaluations.surveillances.index') }}">Surveillances</x-btn>
                <x-btn variant="ghost" href="{{ route('admin.evaluations.deliberations.index') }}">Délibérations</x-btn>
            </x-slot:actions>
        </x-page-header>

        <div class="mt-4 grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-3">
            @foreach([
                ['Sessions actives', $sessionsActives],
                ['Examens planifiés', $examensPlanifies],
                ['Épreuves sans salle', $epreuvesSansSalle],
                ['Sans surveillant', $epreuvesSansSurveillant],
                ['Notes à saisir', $notesManquantes],
                ['Notes à valider', $notesAValider],
                ['Délibérations en attente', $deliberationsEnAttente],
            ] as [$libelle, $valeur])
                <div class="ds-card p-4">
                    <p class="text-2xl font-black tabular-nums text-slate-900">{{ $valeur }}</p>
                    <p class="text-[11px] font-bold uppercase tracking-wider text-slate-500">{{ $libelle }}</p>
                </div>
            @endforeach
        </div>

        @if($epreuvesSansSalle > 0 || $epreuvesSansSurveillant > 0 || $notesAValider > 0)
            <x-alert type="warning" class="mt-4">
                Alertes : {{ $epreuvesSansSalle }} épreuve(s) sans salle,
                {{ $epreuvesSansSurveillant }} sans surveillant,
                {{ $notesAValider }} note(s) à valider.
            </x-alert>
        @endif
    </div>
@endsection
