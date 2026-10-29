@extends('layouts.app')

@section('title', 'Mon agenda')

@section('content')
    <div class="agenda-page mx-auto max-w-4xl px-4 sm:px-6 py-8">
        <x-page-header titre="Mon agenda" :description="'Du ' . $debut . ' au ' . $fin" :fil="['Agenda' => '#']" />

        <div class="mt-4 space-y-2">
            @forelse($elements as $element)
                <div class="agenda-item ds-card flex items-center justify-between p-4">
                    <div>
                        <p class="font-bold text-slate-900">{{ $element['titre'] }}</p>
                        <p class="text-xs text-slate-500">{{ $element['source'] === 'calendrier' ? 'Événement' : 'Session d’examen' }}</p>
                    </div>
                    <p class="agenda-date text-sm font-bold tabular-nums">{{ $element['date'] }}</p>
                </div>
            @empty
                <x-empty-state :message="'Agenda vide — Aucun événement sur cette période.'" />
            @endforelse
        </div>
    </div>
@endsection
