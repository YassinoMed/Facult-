@extends('layouts.app')

@section('title', 'Mon emploi du temps — Espace Étudiant')

@php
$joursFr = ['monday' => 'Lundi', 'tuesday' => 'Mardi', 'wednesday' => 'Mercredi', 'thursday' => 'Jeudi', 'friday' => 'Vendredi', 'saturday' => 'Samedi', 'sunday' => 'Dimanche'];
$typesFr = ['course' => 'Cours', 'td' => 'TD', 'tp' => 'TP', 'exam' => 'Examen', 'meeting' => 'Réunion', 'workshop' => 'Atelier', 'other' => 'Autre'];
@endphp

@section('content')
    <div class="mx-auto max-w-6xl px-4 sm:px-6 py-8 sm:py-12"
         x-data="planningReactif(@js($version), {{ $delaiSondage }}, @js(route('etudiant.emploi-du-temps.actualisations')))"
         x-init="demarrer()">
        <p class="text-[11px] font-bold uppercase tracking-wider text-slate-400">Espace étudiant · {{ $planning->groupe->code }}</p>
        <h1 class="mt-1 text-2xl sm:text-3xl font-black tracking-tight text-slate-900">Mon emploi du temps</h1>
        @if($planning->starts_at || $planning->ends_at)
            <p class="mt-1 text-xs text-slate-500">Valable du {{ $planning->starts_at?->format('d/m/Y') ?? '—' }} au {{ $planning->ends_at?->format('d/m/Y') ?? '—' }} — créneaux récurrents chaque semaine, sauf exception signalée.</p>
        @endif

        {{-- Navigation semaines --}}
        <nav aria-label="Navigation des semaines" class="mt-4 flex items-center justify-between gap-2 rounded-2xl border border-slate-200 bg-white p-2 shadow-sm">
            <a href="{{ route('etudiant.emploi-du-temps.semaine', ['semaine' => $decalage - 1]) }}" aria-label="Semaine précédente"
               class="inline-flex items-center gap-1 rounded-xl px-4 py-2.5 text-sm font-bold text-slate-700 hover:bg-slate-100 min-h-[44px]">
                <span aria-hidden="true">←</span><span class="hidden sm:inline">Précédente</span>
            </a>
            <p class="text-xs sm:text-sm font-bold text-slate-900 text-center">
                Semaine du {{ $lundi->format('d/m/Y') }}
                <span x-show="changements" class="ml-1 rounded-full bg-amber-100 border border-amber-300 px-2 py-0.5 text-[10px] font-bold text-amber-800">Planning mis à jour</span>
            </p>
            <a href="{{ route('etudiant.emploi-du-temps.semaine', ['semaine' => $decalage + 1]) }}" aria-label="Semaine suivante"
               class="inline-flex items-center gap-1 rounded-xl px-4 py-2.5 text-sm font-bold text-slate-700 hover:bg-slate-100 min-h-[44px]">
                <span class="hidden sm:inline">Suivante</span><span aria-hidden="true">→</span>
            </a>
        </nav>
        <p class="sr-only" aria-live="polite" x-text="messageStatut"></p>

        {{-- Desktop : grille hebdomadaire --}}
        <div class="mt-4 hidden md:block overflow-x-auto rounded-2xl border border-slate-200 bg-white shadow-sm">
            <table class="w-full min-w-[760px] text-left text-xs">
                <thead>
                    <tr class="border-b border-slate-100 text-[11px] uppercase tracking-wider text-slate-400">
                        <th scope="col" class="px-4 py-3 font-bold">Horaire</th>
                        @foreach(['monday', 'tuesday', 'wednesday', 'thursday', 'friday'] as $jour)
                            @php $dateColonne = $lundi->copy()->addDays($loop->index)->format('Y-m-d'); @endphp
                            <th scope="col" class="px-4 py-3 font-bold {{ $dateColonne === $aujourdhui ? 'bg-primary-50 text-primary-900' : '' }}">
                                {{ $joursFr[$jour] }}
                                <span class="block text-[10px] font-semibold {{ $dateColonne === $aujourdhui ? 'text-primary-700' : 'text-slate-400' }}">
                                    {{ $lundi->copy()->addDays($loop->index)->format('d/m') }}{{ $dateColonne === $aujourdhui ? ' · aujourd’hui' : '' }}
                                </span>
                            </th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @for($h = 8; $h < 18; $h++)
                        <tr class="border-b border-slate-50 align-top">
                            <th scope="row" class="px-4 py-2 tabular-nums text-slate-400 font-bold whitespace-nowrap">{{ sprintf('%02d:00', $h) }}</th>
                            @foreach(['monday', 'tuesday', 'wednesday', 'thursday', 'friday'] as $jour)
                                <td class="px-2 py-1.5">
                                    @foreach(($jours[$jour] ?? []) as $creneau)
                                        @if((int) substr($creneau['debut'], 0, 2) === $h)
                                            @include('modules.schedule.creneau', ['creneau' => $creneau, 'typesFr' => $typesFr, 'jourEstAujourdhui' => $lundi->copy()->addDays(array_search($jour, ['monday', 'tuesday', 'wednesday', 'thursday', 'friday']))->format('Y-m-d') === $aujourdhui])
                                        @endif
                                    @endforeach
                                </td>
                            @endforeach
                        </tr>
                    @endfor
                </tbody>
            </table>
        </div>

        {{-- Mobile / tablette : liste par jour --}}
        <div class="mt-4 md:hidden space-y-4">
            @foreach($jours as $codeJour => $creneaux)
                @php $dateSection = $lundi->copy()->addDays($loop->index)->format('Y-m-d'); @endphp
                <section aria-label="{{ $joursFr[$codeJour] }}" class="rounded-2xl border {{ $dateSection === $aujourdhui ? 'border-primary-300' : 'border-slate-200' }} bg-white shadow-sm overflow-hidden">
                    <h2 class="px-4 py-2.5 {{ $dateSection === $aujourdhui ? 'bg-primary-50 text-primary-900' : 'bg-slate-50 text-slate-900' }} border-b border-slate-100 text-sm font-black">
                        {{ $joursFr[$codeJour] }}
                        <span class="font-semibold {{ $dateSection === $aujourdhui ? 'text-primary-700' : 'text-slate-400' }}">{{ $lundi->copy()->addDays($loop->index)->format('d/m') }}{{ $dateSection === $aujourdhui ? ' · aujourd’hui' : '' }}</span>
                    </h2>
                    @if(count($creneaux) > 0)
                        <ul class="divide-y divide-slate-100">
                            @foreach($creneaux as $creneau)
                                <li class="px-4 py-3">
                                    @include('modules.schedule.creneau', ['creneau' => $creneau, 'typesFr' => $typesFr, 'jourEstAujourdhui' => $dateSection === $aujourdhui])
                                </li>
                            @endforeach
                        </ul>
                    @else
                        <p class="px-4 py-3 text-xs text-slate-400">Aucun cours.</p>
                    @endif
                </section>
            @endforeach
        </div>
    </div>

    <script>
        function planningReactif(versionInitiale, delai, urlActualisations) {
            return {
                version: versionInitiale,
                changements: false,
                messageStatut: '',
                minuterie: null,
                demarrer() {
                    this.minuterie = setInterval(() => this.sonder(), delai * 1000);
                },
                async sonder() {
                    try {
                        const params = new URLSearchParams({
                            depuis: this.version,
                            semaine: new URLSearchParams(window.location.search).get('semaine') || '0',
                        });
                        const reponse = await fetch(urlActualisations + '?' + params.toString(), {
                            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                        });
                        if (!reponse.ok) return;
                        const donnees = await reponse.json();
                        this.version = donnees.version;
                        if (donnees.updated) {
                            this.changements = true;
                            this.messageStatut = 'Votre emploi du temps a été mis à jour. Rechargez la page pour voir les changements.';
                        }
                    } catch (e) { /* silencieux : prochaine tentative */ }
                },
            };
        }
    </script>
@endsection
