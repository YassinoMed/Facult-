@extends('layouts.app')

@section('title', 'Nouvelle simulation de parcours')

@section('content')
    <div class="simulations-page mx-auto max-w-4xl px-4 sm:px-6 lg:px-8 py-8 sm:py-10">
        <x-page-header
            titre="Nouvelle simulation de parcours"
            description="Testez une hypothèse sur votre situation académique réelle. Le résultat ne modifie rien."
            :fil="['Accueil' => route('etudiant.dashboard'), 'Mes simulations' => route('etudiant.parcours.simulations.index'), 'Nouvelle' => '#']"
        />

        <x-alert type="warning" class="mt-4">
            <strong>Résultat non officiel.</strong> Le calcul utilise vos données réelles (inscriptions, notes publiées, dettes) et les règles versionnées configurées par l'établissement. Si aucune règle n'est configurée, le socle LMD est utilisé et signalé dans le résultat. Aucune décision d'admission, de redoublement ou de rattrapage ne peut résulter d'une simulation.
        </x-alert>

        @if($etatSimulation !== 'disponible')
            <x-card titre="Simulation indisponible" class="mt-4">
                @if($etatSimulation === 'sans_inscription')
                    <p class="text-sm text-slate-700">
                        Aucune inscription académique n'est rattachée à votre dossier : la simulation est
                        <strong>indisponible</strong>. Aucun calcul n'est substitué à la place des données manquantes —
                        adressez-vous à la scolarité pour régulariser votre inscription.
                    </p>
                @else
                    <p class="text-sm text-slate-700">
                        Vos résultats ne sont pas encore <strong>publiés</strong> : la simulation est
                        <strong>indisponible</strong> tant que la délibération du jury n'est pas publiée.
                        Aucune moyenne, aucun crédit et aucune décision non publiés ne sont exposés ici.
                    </p>
                @endif
            </x-card>
        @else
            <x-card titre="Hypothèse à simuler" class="mt-4">
                <form method="POST" action="{{ route('etudiant.parcours.simulations.store') }}" class="simulation-form grid gap-4">
                    @csrf

                    <div>
                        <label for="scenario" class="block text-sm font-bold text-slate-700 mb-1">Scénario</label>
                        <select name="scenario" id="scenario" required
                               aria-describedby="scenario-aide"
                               class="rounded-xl border border-slate-300 px-3 py-2.5 text-sm min-h-[44px] w-full sm:w-auto">
                            @foreach($scenarios as $valeur => $libelle)
                                <option value="{{ $valeur }}">{{ $libelle }}</option>
                            @endforeach
                        </select>
                        <p id="scenario-aide" class="text-xs text-slate-500 mt-1">
                            Exemple : « Compensation » avec une note hypothétique pour estimer un rattrapage.
                        </p>
                        @error('scenario')<p role="alert" class="text-xs text-red-700 mt-1">{{ $message }}</p>@enderror
                    </div>

                    <div>
                        <label for="hypothese-note" class="block text-sm font-bold text-slate-700 mb-1">Note hypothétique (optionnelle)</label>
                        <input type="number" name="hypotheses[note]" id="hypothese-note"
                               min="0" max="20" step="0.25"
                               aria-describedby="note-aide"
                               class="rounded-xl border border-slate-300 px-3 py-2.5 text-sm min-h-[44px] w-full sm:w-48"
                               placeholder="0 à 20" />
                        <p id="note-aide" class="text-xs text-slate-500 mt-1">
                            Utilisée pour les scénarios de validation/compensation. Jamais enregistrée comme note officielle.
                        </p>
                        @error('hypotheses.note')<p role="alert" class="text-xs text-red-700 mt-1">{{ $message }}</p>@enderror
                    </div>

                    <div>
                        <label for="hypothese-semestre" class="block text-sm font-bold text-slate-700 mb-1">Semestre ciblé (optionnel)</label>
                        <select name="hypotheses[semestre]" id="hypothese-semestre"
                                aria-label="Semestre ciblé (optionnel)"
                                class="rounded-xl border border-slate-300 px-3 py-2.5 text-sm min-h-[44px] w-full sm:w-48">
                            <option value="">Automatique</option>
                            <option value="1">Semestre 1</option>
                            <option value="2">Semestre 2</option>
                        </select>
                        @error('hypotheses.semestre')<p role="alert" class="text-xs text-red-700 mt-1">{{ $message }}</p>@enderror
                    </div>

                    <div class="flex gap-2">
                        <button type="submit" class="btn-primary">Calculer la simulation</button>
                        <a href="{{ route('etudiant.parcours.simulations.index') }}" class="btn-secondary">Annuler</a>
                    </div>
                </form>
            </x-card>
        @endif
    </div>
@endsection
