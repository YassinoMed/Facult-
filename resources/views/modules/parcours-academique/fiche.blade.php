@extends('layouts.app')

@section('title', 'Fiche parcours — Scolarité')

@section('content')
    <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 py-8 sm:py-10">
        <x-page-header :titre="'Parcours — ' . $etudiant->nom . ' ' . $etudiant->prenom" :description="'Niveau : ' . ($analyse['niveau_actuel'] ?? '—') . ' · Programme : ' . ($analyse['programme'] ?? '—')" :fil="['Parcours' => route('admin.parcours.index'), 'Fiche' => route('admin.parcours.fiche', $etudiant)]" />

        <div class="mt-6 grid gap-4 sm:grid-cols-4">
            <x-stat-card libelle="Crédits obtenus" :valeur="$credits['obtenus'] . '/' . $credits['requis']" :detail="'Restants : ' . $credits['restants']" />
            <x-stat-card libelle="Moyenne générale" :valeur="$credits['moyenne_generale'] ?? '—'" detail="Pondérée crédits" />
            <x-stat-card libelle="Dettes" :valeur="count($dettes)" detail="Matières à repasser" />
            <x-stat-card libelle="Risque" :valeur="$risque['niveau']" :detail="'Score ' . $risque['score']" />
        </div>

        @if(($risque['motifs'] ?? []) !== [])
            <x-alert type="warning" class="mt-4">Risque {{ $risque['niveau'] }} : {{ implode(' ', $risque['motifs']) }} (informatif, aucune décision automatique).</x-alert>
        @endif

        <x-tabs :onglets="['synthese' => 'Synthèse', 'dettes' => 'Dettes (' . count($dettes) . ')', 'simulations' => 'Simulations', 'historique' => 'Historique', 'amenagements' => 'Aménagements', 'documents' => 'Documents']" class="mt-6">
            <x-slot:panneau_synthese>
                <x-card titre="Propositions de parcours">
                    @forelse($analyse['propositions'] as $proposition)
                        <div class="py-2 text-sm border-b border-slate-100 last:border-0">
                            <p class="font-bold">{{ $proposition['scenario'] }} @if(!empty($proposition['niveau_cible'])) → {{ $proposition['niveau_cible'] }} @endif</p>
                            @if(!empty($proposition['conditions_non_satisfaites']))
                                <p class="text-slate-600">{{ implode(' · ', $proposition['conditions_non_satisfaites']) }}</p>
                            @endif
                        </div>
                    @empty
                        <x-empty-state message="Aucune proposition. En attente d'une décision de jury publiée." />
                    @endforelse
                </x-card>
            </x-slot:panneau_synthese>

            <x-slot:panneau_dettes>
                <x-card titre="Dettes académiques">
                    @if(empty($dettes))
                        <x-empty-state message="Aucune dette. Toutes les matières évaluées sont validées." />
                    @else
                        <ul class="divide-y divide-slate-100 text-sm">
                            @foreach($dettes as $dette)
                                <li class="py-2 flex justify-between gap-2">
                                    <span><strong>{{ $dette['matiere'] ?? 'Matière' }}</strong> ({{ $dette['annee'] ?? '' }}, note {{ $dette['note'] ?? '—' }})</span>
                                    <x-status-badge :statut="$dette['statut']" />
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </x-card>
            </x-slot:panneau_dettes>

            <x-slot:panneau_simulations>
                <x-card titre="Simuler en 1 clic (sans effet officiel)">
                    <div class="flex flex-wrap gap-2">
                        @foreach(['passage' => 'Passage', 'redoublement' => 'Redoublement', 'compensation' => 'Compensation', 'passerelle_master' => 'Passerelle Master', 'reorientation' => 'Réorientation'] as $val => $lib)
                            <form method="POST" action="{{ route('admin.parcours.simulations.store') }}">
                                @csrf
                                <input type="hidden" name="etudiant_id" value="{{ $etudiant->id }}">
                                <input type="hidden" name="scenario" value="{{ $val }}">
                                <x-btn type="submit" variant="secondary">{{ $lib }}</x-btn>
                            </form>
                        @endforeach
                    </div>
                    <details class="mt-3 text-sm">
                        <summary class="cursor-pointer font-bold text-primary-700 hover:underline min-h-[44px] inline-block py-2">Simulation avancée (semestre, matière, hypothèse)</summary>
                        <form method="POST" action="{{ route('admin.parcours.simulations.store') }}" class="mt-2 flex flex-col sm:flex-row gap-2">
                            @csrf
                            <input type="hidden" name="etudiant_id" value="{{ $etudiant->id }}">
                            <select name="scenario" class="rounded-xl border border-slate-300 px-3 py-2.5 text-sm min-h-[44px]" required>
                                @foreach(['validation_semestre' => 'Validation semestre', 'validation_matiere' => 'Validation matière', 'conditionnel' => 'Conditionnel', 'conservation_dettes' => 'Conservation dettes', 'equivalence' => 'Équivalence', 'dispense' => 'Dispense'] as $val => $lib)
                                    <option value="{{ $val }}">{{ $lib }}</option>
                                @endforeach
                            </select>
                            <select name="hypotheses[semestre]" class="rounded-xl border border-slate-300 px-3 py-2.5 text-sm min-h-[44px]" aria-label="Semestre (si validation semestre)">
                                <option value="">Semestre auto</option>
                                <option value="1">Semestre 1</option>
                                <option value="2">Semestre 2</option>
                            </select>
                            <x-btn type="submit">Simuler</x-btn>
                        </form>
                    </details>
                </x-card>

                <x-card titre="Dernières simulations" class="mt-4">
                    <div class="flex flex-wrap gap-2 text-sm">
                        <a href="{{ route('admin.parcours.simulations.index') }}" class="text-primary-700 hover:underline">Toutes les simulations</a>
                        <form method="POST" action="{{ route('admin.parcours.risque.store', $etudiant) }}">@csrf <button class="text-primary-700 hover:underline" type="submit">Recalculer le risque</button></form>
                    </div>
                    @forelse($simulations as $simulation)
                        <p class="text-sm py-1"><a href="{{ route('admin.parcours.simulations.show', $simulation) }}" class="text-primary-700 hover:underline">{{ $simulation->scenario }} → {{ $simulation->resultat['resultat_final'] ?? '?' }}</a></p>
                    @empty
                        <x-empty-state message="Aucune simulation pour cet étudiant." />
                    @endforelse
                </x-card>
            </x-slot:panneau_simulations>

            <x-slot:panneau_historique>
                <x-card titre="Historique chronologique">
                    @if(empty($chronologie))
                        <x-empty-state message="Aucun événement." />
                    @else
                        <ol class="text-sm divide-y divide-slate-100 max-h-96 overflow-auto">
                            @foreach($chronologie as $evenement)
                                <li class="py-2"><strong>{{ $evenement['type'] }}</strong> — {{ $evenement['libelle'] }}</li>
                            @endforeach
                        </ol>
                    @endif
                </x-card>
            </x-slot:panneau_historique>

            <x-slot:panneau_amenagements>
                <x-card titre="Aménagements">
                    <p class="text-xs text-slate-500">Équivalences : {{ $equivalences->count() }} · Dispenses : {{ $dispenses->count() }} · Réorientations : {{ $reorientations->count() }} · Passerelles : {{ $passerelles->count() }}</p>
                    <div class="mt-2 flex flex-wrap gap-3 text-sm">
                        <a href="{{ route('admin.parcours.amenagements.index', 'equivalences') }}" class="text-primary-700 hover:underline">Équivalences</a>
                        <a href="{{ route('admin.parcours.amenagements.index', 'dispenses') }}" class="text-primary-700 hover:underline">Dispenses</a>
                        <a href="{{ route('admin.parcours.amenagements.index', 'reorientations') }}" class="text-primary-700 hover:underline">Réorientations</a>
                        <a href="{{ route('admin.parcours.amenagements.index', 'passerelles') }}" class="text-primary-700 hover:underline">Passerelles</a>
                    </div>
                </x-card>
            </x-slot:panneau_amenagements>

            <x-slot:panneau_documents>
                <x-card titre="Documents officiels (GED existante)">
                    <p class="text-sm text-slate-600">Relevés, attestations et décisions : générés depuis les données officielles via le centre documentaire (snapshot + hash + version). Aucun document n'est produit depuis une simulation.</p>
                    <div class="mt-2 flex flex-wrap gap-3 text-sm">
                        <a href="{{ route('admin.documents-center.index') }}" class="text-primary-700 hover:underline">Centre documentaire</a>
                        <a href="{{ route('admin.parcours.simulations.index') }}" class="text-primary-700 hover:underline">Simulations (non officielles)</a>
                    </div>
                </x-card>
            </x-slot:panneau_documents>
        </x-tabs>
    </div>
@endsection
