@extends('layouts.app')

@section('title', 'Détail du créneau — Espace Enseignant')

@section('content')
    <div class="mx-auto max-w-3xl px-4 sm:px-6 py-8">
        <x-page-header :titre="($slot->matiere?->nom ?? 'Créneau') . ' · ' . ucfirst($slot->day_of_week)" description="Détail pédagogique en lecture seule."
            :fil="['Emploi du temps' => route('enseignant.emploi-du-temps.semaine'), 'Détail séance' => '#']">
            <x-slot:actions>
                <x-btn variant="secondary" onclick="window.print()">Imprimer</x-btn>
                <x-btn variant="secondary" href="{{ route('enseignant.emploi-du-temps.semaine') }}">Retour à l'emploi du temps</x-btn>
            </x-slot:actions>
        </x-page-header>

        <section aria-label="Détail du créneau" class="mt-4 rounded-2xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 shadow-sm overflow-hidden">
            <dl class="divide-y divide-slate-100 dark:divide-slate-800 text-sm">
                <div class="px-5 py-3 flex justify-between gap-4">
                    <dt class="text-slate-500">Horaire & Durée</dt>
                    <dd class="font-semibold tabular-nums text-slate-900 dark:text-slate-100">
                        {{ substr((string) $slot->start_time, 0, 5) }} → {{ substr((string) $slot->end_time, 0, 5) }}
                        <span class="ml-1 text-xs text-slate-500 font-normal">({{ $dureeMinutes }} min · {{ floor($dureeMinutes / 60) }} h{{ $dureeMinutes % 60 > 0 ? sprintf(' %02d', $dureeMinutes % 60) : '' }})</span>
                    </dd>
                </div>
                <div class="px-5 py-3 flex justify-between gap-4">
                    <dt class="text-slate-500">Matière / ECUE</dt>
                    <dd class="font-semibold text-slate-900 dark:text-slate-100">
                        {{ $slot->matiere?->nom ?? '—' }}
                        @if($slot->matiere?->code)
                            <span class="text-xs font-mono text-slate-500">({{ $slot->matiere->code }})</span>
                        @endif
                    </dd>
                </div>
                @if($slot->matiere?->coefficient !== null || $slot->matiere?->credits !== null)
                    <div class="px-5 py-3 flex justify-between gap-4">
                        <dt class="text-slate-500">Coefficient / Crédits</dt>
                        <dd class="font-semibold text-slate-900 dark:text-slate-100">
                            {{ $slot->matiere?->coefficient ?? '—' }} coeff · {{ $slot->matiere?->credits ?? '—' }} crédits ECTS
                        </dd>
                    </div>
                @endif
                <div class="px-5 py-3 flex justify-between gap-4">
                    <dt class="text-slate-500">Groupe concerné</dt>
                    <dd class="font-semibold text-slate-900 dark:text-slate-100">
                        {{ $slot->schedule?->groupe?->nom ?? '—' }}
                        @if($slot->schedule?->groupe?->code)
                            <span class="text-xs text-slate-500">({{ $slot->schedule->groupe->code }})</span>
                        @endif
                    </dd>
                </div>
                <div class="px-5 py-3 flex justify-between gap-4">
                    <dt class="text-slate-500">Salle</dt>
                    <dd class="font-semibold font-mono text-slate-900 dark:text-slate-100">
                        {{ $slot->room?->code ?? '— Non assignée' }}
                        @if($slot->room?->capacite)
                            <span class="text-xs font-sans text-slate-500 font-normal">· Capacité : {{ $slot->room->capacite }} places</span>
                        @endif
                    </dd>
                </div>
                <div class="px-5 py-3 flex justify-between gap-4">
                    <dt class="text-slate-500">Type d'enseignement</dt>
                    <dd><x-status-badge :statut="$slot->type" /></dd>
                </div>
                <div class="px-5 py-3 flex justify-between gap-4">
                    <dt class="text-slate-500">Statut de la séance</dt>
                    <dd><x-status-badge :statut="$slot->status" /></dd>
                </div>
            </dl>
        </section>

        @if($exceptions->isNotEmpty())
            <section aria-label="Exceptions ponctuelles" class="mt-4 rounded-2xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 shadow-sm overflow-hidden">
                <div class="px-5 py-3 border-b border-slate-100 dark:border-slate-800 flex items-center justify-between">
                    <h3 class="text-sm font-black text-slate-900 dark:text-slate-100">Changements et exceptions ({{ $exceptions->count() }})</h3>
                    <span class="text-xs text-slate-500">Modifications ponctuelles de calendrier</span>
                </div>
                <ul class="divide-y divide-slate-100 dark:divide-slate-800">
                    @foreach($exceptions as $exception)
                        <li class="px-5 py-3 text-sm">
                            <div class="flex items-center justify-between gap-2">
                                <p class="font-bold tabular-nums text-slate-900 dark:text-slate-100">
                                    {{ \Carbon\Carbon::parse($exception->exception_date)->format('d/m/Y') }}
                                    @if($exception->new_start_time)
                                        <span class="text-xs text-slate-500 font-normal">({{ substr((string) $exception->new_start_time, 0, 5) }} → {{ substr((string) $exception->new_end_time, 0, 5) }})</span>
                                    @endif
                                    @if($exception->nouvelleSalle)
                                        <span class="text-xs text-slate-500 font-normal">· Salle {{ $exception->nouvelleSalle->code }}</span>
                                    @endif
                                </p>
                                <x-status-badge :statut="$exception->status" />
                            </div>
                            @if($exception->reason)
                                <p class="mt-1 text-xs text-slate-600 dark:text-slate-300 bg-slate-50 dark:bg-slate-800 p-2 rounded-lg">
                                    Motif : {{ $exception->reason }}
                                </p>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif

        @if($historique->isNotEmpty())
            <section aria-label="Historique des changements" class="mt-4 rounded-2xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 shadow-sm overflow-hidden">
                <div class="px-5 py-3 border-b border-slate-100 dark:border-slate-800">
                    <h3 class="text-sm font-black text-slate-900 dark:text-slate-100">Historique des modifications administratives ({{ $historique->count() }})</h3>
                </div>
                <ul class="divide-y divide-slate-100 dark:divide-slate-800">
                    @foreach($historique as $changement)
                        <li class="px-5 py-2.5 text-xs text-slate-600 dark:text-slate-300">
                            <div class="flex items-center justify-between">
                                <div>
                                    <x-status-badge :statut="$changement->change_type" />
                                    <span class="ml-2 tabular-nums text-slate-500">{{ $changement->created_at->format('d/m/Y H:i') }}</span>
                                </div>
                            </div>
                            @if(! empty($changement->reason))
                                <span class="block mt-1 text-slate-500 italic">{{ $changement->reason }}</span>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif

        <div class="mt-4 rounded-2xl border border-amber-200 dark:border-amber-800 bg-amber-50/60 dark:bg-amber-950/30 p-4 text-xs text-amber-900 dark:text-amber-200 flex flex-col sm:flex-row sm:items-center justify-between gap-3">
            <div>
                <p class="font-bold">Information de gestion pédagogique :</p>
                <p class="mt-0.5 text-amber-800 dark:text-amber-300">Ce planning est en lecture seule. Toute modification d'horaire ou de salle doit être validée par la scolarité.</p>
            </div>
            <a href="{{ route('enseignant.reclamations.create', [
                'categorie' => 'emploi_du_temps',
                'objet' => 'Signalement d\'une erreur de créneau sur ' . ($slot->matiere?->nom ?? 'cours') . ' (' . ucfirst($slot->day_of_week) . ' ' . substr((string) $slot->start_time, 0, 5) . ')'
            ]) }}" class="inline-flex items-center justify-center font-bold text-rose-700 dark:text-rose-300 bg-white dark:bg-slate-900 px-3 py-2 rounded-xl border border-rose-300 dark:border-rose-700 hover:bg-rose-50 shadow-sm shrink-0">
                Signaler une anomalie →
            </a>
        </div>
    </div>
@endsection
