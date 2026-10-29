@extends('layouts.app')

@section('title', 'Résultat de simulation')

@section('content')
    <div class="mx-auto max-w-4xl px-4 sm:px-6 lg:px-8 py-8 sm:py-10">
        <x-page-header
            :titre="'Simulation : ' . ($simulation->resultat['scenario_propose'] ?? $simulation->scenario)"
            description="Résultat hypothétique calculé sur VOS données officielles. Sans aucun effet sur votre dossier."
            :fil="['Accueil' => route('etudiant.dashboard'), 'Mes simulations' => route('etudiant.parcours.simulations.index'), 'Résultat' => '#']"
        />

        <x-alert type="warning" class="mt-4">
            {{ $simulation->resultat['avertissement'] ?? 'Simulation sans effet officiel : notes, crédits, niveau et décisions inchangés. Seule la délibération rend une décision officielle.' }}
        </x-alert>

        <x-card titre="Résultat" class="mt-4">
            <dl class="text-sm grid gap-2">
                <div><dt class="font-bold">Résultat final (hypothétique)</dt><dd>{{ $simulation->resultat['resultat_final'] ?? '—' }}</dd></div>
                <div><dt class="font-bold">Justification</dt><dd>{{ $simulation->resultat['justification'] ?? '—' }}</dd></div>
                <div><dt class="font-bold">Décision officielle de référence</dt><dd>{{ $simulation->resultat['decision_officielle'] ?? 'aucune décision publiée' }}</dd></div>
                <div><dt class="font-bold">Crédits obtenus / manquants</dt><dd>{{ $simulation->resultat['credits_obtenus'] ?? '—' }} / {{ $simulation->resultat['credits_manquants'] ?? '—' }}</dd></div>
                <div><dt class="font-bold">Méthode et règles appliquées</dt><dd>{{ implode(', ', $simulation->resultat['regles'] ?? ['socle LMD (aucune règle configurée)']) }}</dd></div>
                @if(!empty($simulation->hypotheses))
                    <div><dt class="font-bold">Hypothèses saisies</dt>
                        <dd>
                            @foreach($simulation->hypotheses as $cle => $valeur)
                                <span class="rounded bg-slate-100 px-1.5 py-0.5 text-xs mr-1">{{ $cle }} : {{ $valeur }}</span>
                            @endforeach
                        </dd>
                    </div>
                @endif
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

        <div class="mt-6 flex gap-2">
            <a href="{{ route('etudiant.parcours.simulations.create') }}" class="btn-primary">Nouvelle simulation</a>
            <a href="{{ route('etudiant.parcours.simulations.index') }}" class="btn-secondary">Mes simulations</a>
        </div>
    </div>
@endsection
