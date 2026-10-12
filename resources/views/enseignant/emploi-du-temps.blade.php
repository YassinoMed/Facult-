@extends('layouts.app')

@section('title', 'Mon emploi du temps — Espace Enseignant')

@section('content')
    <div class="mx-auto max-w-7xl px-4 sm:px-6 py-6">
        <x-page-header titre="Mon emploi du temps" description="Consultez votre planning hebdomadaire officiel de cours, travaux dirigés et travaux pratiques."
            :fil="['Espace Enseignant' => route('enseignant.dashboard'), 'Mon emploi du temps' => '#']">
            <x-slot:actions>
                <x-btn variant="secondary" onclick="window.print()">Imprimer mon planning</x-btn>
            </x-slot:actions>
        </x-page-header>

        {{-- Synthèse du volume d'enseignement --}}
        <div class="mt-6 grid grid-cols-2 sm:grid-cols-4 gap-3">
            <x-stat-card libelle="Volume hebdomadaire" :valeur="$totalHeures . ' h'" detail="Heures d'enseignement prévues" couleur="primary" />
            <x-stat-card libelle="Séances régulières" :valeur="$nombreSeances" detail="Créneaux par semaine" couleur="slate" />
            <x-stat-card libelle="Matières enseignées" :valeur="$matieresDistinctes" detail="Modules différents" couleur="slate" />
            <x-stat-card libelle="Groupes encadrés" :valeur="$groupesDistincts" detail="Promotions / sections" couleur="slate" />
        </div>

        {{-- Filtres par jour --}}
        <div class="mt-6 flex flex-wrap items-center justify-between gap-3 p-3 rounded-2xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 shadow-sm">
            <div class="flex flex-wrap items-center gap-1.5">
                <a href="{{ route('enseignant.emploi-du-temps') }}" 
                   class="px-3 py-1.5 rounded-xl text-xs font-bold transition {{ !$jourFiltre ? 'bg-primary-800 text-white shadow-sm' : 'text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800' }}">
                    Semaine entière
                </a>
                @foreach($libellesJours as $cleJour => $nomJour)
                    @if($cleJour !== 'sunday')
                        <a href="{{ route('enseignant.emploi-du-temps', ['jour' => $cleJour]) }}" 
                           class="px-3 py-1.5 rounded-xl text-xs font-bold transition flex items-center gap-1 {{ $jourFiltre === $cleJour ? 'bg-primary-800 text-white shadow-sm' : 'text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800' }}">
                            {{ $nomJour }}
                            @if($cleJour === $aujourdhuiJour)
                                <span class="w-1.5 h-1.5 rounded-full bg-emerald-500" title="Aujourd'hui"></span>
                            @endif
                        </a>
                    @endif
                @endforeach
            </div>

            <a href="{{ route('enseignant.reclamations.create', ['categorie' => 'emploi_du_temps', 'objet' => 'Signalement d\'un conflit ou erreur de planning']) }}"
               class="text-xs font-bold text-rose-600 dark:text-rose-400 hover:underline flex items-center gap-1">
                <span>⚠️ Signaler un conflit ou une erreur</span>
            </a>
        </div>

        {{-- Grille des séances par jour --}}
        @if($slots->isNotEmpty())
            <div class="mt-6 space-y-6">
                @foreach($libellesJours as $codeJour => $nomJour)
                    @if(!$jourFiltre || $jourFiltre === $codeJour)
                        @php
                            $seancesJour = $slotsParJour->get($codeJour, collect());
                        @endphp
                        @if($seancesJour->isNotEmpty() || $jourFiltre === $codeJour)
                            <div class="rounded-2xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 shadow-sm overflow-hidden">
                                <div class="px-5 py-3 border-b border-slate-100 dark:border-slate-800 bg-slate-50/50 dark:bg-slate-800/30 flex items-center justify-between">
                                    <div class="flex items-center gap-2">
                                        <h3 class="text-sm font-black text-slate-900 dark:text-slate-100 uppercase tracking-wider">{{ $nomJour }}</h3>
                                        @if($codeJour === $aujourdhuiJour)
                                            <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300">Aujourd'hui</span>
                                        @endif
                                    </div>
                                    <span class="text-xs font-bold text-slate-500">{{ $seancesJour->count() }} séance(s)</span>
                                </div>

                                @if($seancesJour->isNotEmpty())
                                    <div class="divide-y divide-slate-100 dark:divide-slate-800">
                                        @foreach($seancesJour as $slot)
                                            @php
                                                $exception = $slot->exceptions->first();
                                                $debut = substr((string) ($exception?->new_start_time ?? $slot->start_time), 0, 5);
                                                $fin = substr((string) ($exception?->new_end_time ?? $slot->end_time), 0, 5);
                                                $salle = $exception?->nouvelleSalle?->code ?? $slot->room?->code;
                                            @endphp
                                            <div class="p-5 flex flex-col md:flex-row md:items-center justify-between gap-4 hover:bg-slate-50/50 dark:hover:bg-slate-800/30 transition">
                                                <div class="flex items-start gap-4">
                                                    {{-- Horaires --}}
                                                    <div class="min-w-[110px] text-center p-2.5 rounded-xl bg-slate-100 dark:bg-slate-800 font-mono">
                                                        <span class="block text-sm font-black text-slate-900 dark:text-slate-100">{{ $debut }}</span>
                                                        <span class="block text-[11px] text-slate-400">à</span>
                                                        <span class="block text-sm font-black text-slate-900 dark:text-slate-100">{{ $fin }}</span>
                                                    </div>

                                                    {{-- Détails de la matière et séance --}}
                                                    <div class="space-y-1">
                                                        <div class="flex flex-wrap items-center gap-2">
                                                            <h4 class="text-base font-bold text-slate-900 dark:text-slate-100">
                                                                {{ $slot->matiere?->nom ?? 'Matière sans nom' }}
                                                            </h4>
                                                            <span class="px-2 py-0.5 rounded-md text-[11px] font-bold uppercase tracking-wider bg-primary-100 text-primary-800 dark:bg-primary-950 dark:text-primary-300">
                                                                {{ strtoupper($slot->type) }}
                                                            </span>
                                                            @if($exception)
                                                                <span class="px-2 py-0.5 rounded-md text-[10px] font-bold bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-300">
                                                                    Modification ponctuelle
                                                                </span>
                                                            @endif
                                                        </div>

                                                        <div class="flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-slate-500 dark:text-slate-400">
                                                            <span>Groupe : <strong class="text-slate-700 dark:text-slate-200">{{ $slot->schedule?->groupe?->nom ?? 'Groupe non assigné' }}</strong></span>
                                                            <span>Salle : <strong class="text-slate-700 dark:text-slate-200">{{ $salle ?? 'Non assignée' }}</strong></span>
                                                            @if($slot->notes)
                                                                <span class="italic">« {{ $slot->notes }} »</span>
                                                            @endif
                                                        </div>
                                                    </div>
                                                </div>

                                                {{-- Actions & Statut --}}
                                                <div class="flex items-center gap-3">
                                                    <x-status-badge :statut="$exception?->status ?? $slot->status" />
                                                    <a href="{{ route('enseignant.reclamations.create', [
                                                            'categorie' => 'emploi_du_temps',
                                                            'objet' => 'Erreur sur créneau ' . ($slot->matiere?->nom ?? '') . ' (' . $nomJour . ' ' . $debut . '-' . $fin . ')',
                                                        ]) }}"
                                                       title="Signaler un problème sur ce créneau"
                                                       class="p-2 rounded-xl text-slate-400 hover:text-rose-600 hover:bg-rose-50 dark:hover:bg-rose-950/30 transition text-xs font-semibold flex items-center gap-1">
                                                        <span>Signaler</span>
                                                    </a>
                                                </div>
                                            </div>
                                        @endforeach
                                    </div>
                                @else
                                    <div class="p-6 text-center text-xs text-slate-400">
                                        Aucune séance programmée pour ce jour.
                                    </div>
                                @endif
                            </div>
                        @endif
                    @endif
                @endforeach
            </div>
        @else
            <div class="mt-6 rounded-2xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 p-12 text-center shadow-sm">
                <x-empty-state message="Aucun créneau d'emploi du temps n'est actuellement affecté à votre profil." />
                <p class="text-xs text-slate-500 mt-2">Dès que le département ou le responsable pédagogique valide votre grille, elle apparaîtra ici automatiquement.</p>
            </div>
        @endif
    </div>
@endsection
