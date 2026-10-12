@extends('layouts.app')

@section('title', 'Simulation — Parcours')

@section('content')
    <div class="mx-auto max-w-4xl px-4 sm:px-6 lg:px-8 py-8 sm:py-10">
        <x-page-header :titre="'Simulation ' . $simulation->scenario" :description="'Étudiant : ' . $simulation->etudiant->nom . ' ' . $simulation->etudiant->prenom" :fil="['Simulations' => route('admin.parcours.simulations.index'), 'Détail' => route('admin.parcours.simulations.show', $simulation)]" />
        <x-alert type="info" class="mt-4">{{ $simulation->resultat['avertissement'] ?? 'Sans effet officiel.' }}</x-alert>

        <x-card titre="Résultat" class="mt-4">
            <dl class="text-sm grid gap-2">
                <div><dt class="font-bold">Résultat final</dt><dd>{{ $simulation->resultat['resultat_final'] ?? '—' }}</dd></div>
                <div><dt class="font-bold">Justification</dt><dd>{{ $simulation->resultat['justification'] ?? '—' }}</dd></div>
                <div><dt class="font-bold">Décision officielle de référence</dt><dd>{{ $simulation->resultat['decision_officielle'] ?? 'aucune' }}</dd></div>
                <div><dt class="font-bold">Crédits obtenus / manquants</dt><dd>{{ $simulation->resultat['credits_obtenus'] ?? '—' }} / {{ $simulation->resultat['credits_manquants'] ?? '—' }}</dd></div>
                <div><dt class="font-bold">Règles</dt><dd>{{ implode(', ', $simulation->resultat['regles'] ?? []) }}</dd></div>
            </dl>
        </x-card>

        <div class="mt-4 grid gap-4 sm:grid-cols-2">
            <x-card titre="Conditions satisfaites">
                <ul class="text-sm list-disc pl-5">@forelse($simulation->resultat['conditions_satisfaites'] ?? [] as $c)<li>{{ $c }}</li>@empty<li>Aucune</li>@endforelse</ul>
            </x-card>
            <x-card titre="Conditions non satisfaites">
                <ul class="text-sm list-disc pl-5">@forelse($simulation->resultat['conditions_non_satisfaites'] ?? [] as $c)<li>{{ $c }}</li>@empty<li>Aucune</li>@endforelse</ul>
            </x-card>
        </div>
    </div>
@endsection
