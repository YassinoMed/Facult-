@extends('layouts.app')

@section('title', 'Parcours académique — Scolarité')

@section('content')
    <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 py-8 sm:py-10">
        <x-page-header titre="Parcours académique LMD" description="Planification, simulations sans effet officiel, crédits, dettes, risques et aménagements." :fil="['Tableau de bord' => route('admin.dashboard'), 'Parcours académique' => route('admin.parcours.index')]" />

        <div class="mt-6 grid gap-4 sm:grid-cols-4">
            <x-stat-card libelle="Alertes critiques" :valeur="count($alertes)" detail="À accompagner (sans décision auto)" />
            <x-stat-card libelle="Simulations passage" :valeur="$indicateurs['simulations_passage']" detail="Sans effet officiel" />
            <x-stat-card libelle="Équivalences validées" :valeur="$indicateurs['equivalences_validees']" detail="Historique conservé" />
            <x-stat-card libelle="Passerelles admises" :valeur="$indicateurs['passerelles_admises']" detail="Après commission" />
        </div>

        <div class="mt-8 grid gap-6 lg:grid-cols-2">
            <x-card titre="Étudiants à risque">
                @if($alertes->isEmpty())
                    <x-empty-state message="Aucune alerte élevée. Les évaluations de risque apparaissent ici." />
                @else
                    <ul class="divide-y divide-slate-100">
                        @foreach($alertes as $alerte)
                            <li class="py-2 flex items-center justify-between gap-2 text-sm">
                                <span class="font-bold text-slate-900">{{ $alerte->etudiant->nom }} {{ $alerte->etudiant->prenom }}</span>
                                <span class="flex items-center gap-2">
                                    <x-status-badge :statut="$alerte->niveau" />
                                    <a href="{{ route('admin.parcours.fiche', $alerte->etudiant) }}" class="text-primary-700 hover:underline">Fiche</a>
                                </span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-card>

            <x-card titre="Dernières simulations">
                @if($simulations->isEmpty())
                    <x-empty-state message="Aucune simulation. Lancez une simulation depuis une fiche étudiant." />
                @else
                    <ul class="divide-y divide-slate-100">
                        @foreach($simulations as $simulation)
                            <li class="py-2 text-sm flex items-center justify-between gap-2">
                                <span>{{ $simulation->etudiant->nom }} {{ $simulation->etudiant->prenom }} — {{ $simulation->scenario }}</span>
                                <a href="{{ route('admin.parcours.simulations.show', $simulation) }}" class="text-primary-700 hover:underline">{{ $simulation->resultat['resultat_final'] ?? 'voir' }}</a>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-card>
        </div>

        <x-card titre="Accès rapide" class="mt-6">
            <ul class="divide-y divide-slate-100 text-sm">
                <li class="py-2 flex justify-between gap-2"><a href="{{ route('admin.parcours.simulations.index') }}" class="text-primary-700 hover:underline font-bold">Simulations ({{ count($simulations) }} récentes)</a><span class="text-slate-500">passerelle : {{ $indicateurs['simulations_passerelle'] }}</span></li>
                <li class="py-2 flex justify-between gap-2"><a href="{{ route('admin.parcours.amenagements.index', 'dispenses') }}" class="text-primary-700 hover:underline font-bold">Dispenses validées ({{ $indicateurs['dispenses_validees'] }})</a><span class="text-slate-500">étudiants : {{ $indicateurs['etudiants'] }}</span></li>
                <li class="py-2 flex justify-between gap-2"><a href="{{ route('admin.parcours.amenagements.index', 'reorientations') }}" class="text-primary-700 hover:underline font-bold">Réorientations ({{ $indicateurs['reorientations'] }})</a><span class="text-slate-500">passerelles admises : {{ $indicateurs['passerelles_admises'] }}</span></li>
                <li class="py-2 flex justify-between gap-2"><a href="{{ route('admin.parcours.regles.index') }}" class="text-primary-700 hover:underline font-bold">Règles versionnées</a><span class="text-slate-500">Simulation ≠ décision : seule la délibération décide</span></li>
            </ul>
        </x-card>

        <x-alert type="info" class="mt-6">Une simulation ne modifie jamais notes, crédits, niveau ni décision officielle.</x-alert>
    </div>
@endsection
