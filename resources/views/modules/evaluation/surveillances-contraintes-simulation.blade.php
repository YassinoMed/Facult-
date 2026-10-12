@extends('layouts.app')

@section('title', 'Simulation des contraintes')

@section('content')
    <div class="mx-auto max-w-7xl px-4 sm:px-6 py-8">
        <x-page-header
            titre="Simulation — {{ $session->nom }}"
            description="Résultat purement indicatif avec les valeurs testées : aucune écriture, aucune affectation."
            :fil="['Contraintes' => route('admin.evaluations.surveillances.contraintes.index'), 'Simulation' => '#']">
            <x-slot:actions>
                <x-btn variant="secondary" href="{{ route('admin.evaluations.surveillances.contraintes.index') }}">Retour contraintes</x-btn>
            </x-slot:actions>
        </x-page-header>

        <div class="mt-4 grid grid-cols-2 sm:grid-cols-4 gap-3">
            <x-stat-card libelle="Statut" :valeur="$simulation['statut']" detail="Plan calculé" couleur="primary" />
            <x-stat-card libelle="Épreuves" :valeur="$simulation['total_epreuves']" detail="Analysées" couleur="slate" />
            <x-stat-card libelle="Actifs proposés" :valeur="$simulation['total_surveillants_actifs']" detail="Postes" couleur="primary" />
            <x-stat-card libelle="Gardes proposées" :valeur="$simulation['total_gardes_reserve']" detail="Réserves" couleur="slate" />
            <x-stat-card libelle="Charge min/max" :valeur="($simulation['indicateurs_charge']['min'] ?? 0).' / '.($simulation['indicateurs_charge']['max'] ?? 0)" detail="Écart : {{ $simulation['indicateurs_charge']['ecart'] ?? 0 }}" couleur="slate" />
            <x-stat-card libelle="Conflits" :valeur="$simulation['conflits_detectes']" detail="Détectés" couleur="{{ ($simulation['conflits_detectes'] ?? 0) > 0 ? 'amber' : 'emerald' }}" />
            <x-stat-card libelle="Capacité" :valeur="$simulation['problemes_capacite']" detail="Problèmes" couleur="{{ ($simulation['problemes_capacite'] ?? 0) > 0 ? 'rose' : 'emerald' }}" />
            <x-stat-card libelle="Couverture" :valeur="($simulation['epreuves_couvertes_pct'] ?? 0).' %'" detail="Gardes : {{ $simulation['gardes_couvertes_pct'] ?? 0 }} %" couleur="emerald" />
        </div>

        @if(($simulation['contraintes_souples_degradees'] ?? []) !== [])
            <div class="mt-4">
                <x-alert type="warning" :message="'Dégradations contrôlées : '.implode(' ', $simulation['contraintes_souples_degradees'])" />
            </div>
        @endif

        @if(! empty($simulation['statistiques_enseignement']['actif']))
            <div class="mt-4 p-4 rounded-2xl bg-indigo-50/50 dark:bg-indigo-950/30 border border-indigo-200 dark:border-indigo-800">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <div class="flex items-center gap-2">
                        <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-bold bg-indigo-100 text-indigo-800 dark:bg-indigo-900/60 dark:text-indigo-200">
                            Critère charge d'enseignement ACTIF
                        </span>
                        <span class="text-xs text-indigo-700 dark:text-indigo-300">
                            Mode : {{ $simulation['statistiques_enseignement']['mode_calcul'] === 'hetd' ? 'Équivalent TD (HETD)' : 'Heures brutes' }} ·
                            Poids : {{ $simulation['statistiques_enseignement']['poids'] }}
                            @if($simulation['statistiques_enseignement']['seuil_surcharge'])
                                · Seuil surcharge : {{ $simulation['statistiques_enseignement']['seuil_surcharge'] }} h
                            @endif
                            @if($simulation['statistiques_enseignement']['max_surveillances'])
                                · Plafond : {{ $simulation['statistiques_enseignement']['max_surveillances'] }} max/ens.
                            @endif
                        </span>
                    </div>
                    <span class="text-xs text-slate-500 dark:text-slate-400">
                        Couverture données : {{ round(($simulation['statistiques_enseignement']['couverture'] ?? 0) * 100) }} %
                        ({{ $simulation['statistiques_enseignement']['sans_donnees'] ?? 0 }} sans affectation)
                    </span>
                </div>
            </div>
        @endif

        @if(($simulation['bilan_enseignants'] ?? []) !== [])
            <div class="mt-4 ds-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 shadow-sm overflow-hidden">
                <div class="px-5 py-3 border-b border-slate-100 dark:border-slate-800 flex items-center justify-between">
                    <h3 class="text-sm font-black text-slate-900 dark:text-slate-100">Bilan prévisionnel par enseignant ({{ count($simulation['bilan_enseignants']) }})</h3>
                    <span class="text-xs text-slate-500">Heuristique déterministe d'aide à la décision</span>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-xs text-slate-600 dark:text-slate-300">
                        <thead class="bg-slate-50 dark:bg-slate-800/50 uppercase text-[10px] tracking-wider text-slate-500">
                            <tr>
                                <th class="px-4 py-2.5">Enseignant</th>
                                <th class="px-3 py-2.5 text-center">Examens</th>
                                <th class="px-3 py-2.5 text-center">Gardes</th>
                                <th class="px-3 py-2.5 text-center">Jours</th>
                                <th class="px-3 py-2.5 text-center">Charge surv.</th>
                                <th class="px-3 py-2.5 text-center">Charge cours</th>
                                <th class="px-3 py-2.5 text-center">Détail (CM/TD/TP)</th>
                                <th class="px-3 py-2.5 text-center">Norme relative</th>
                                <th class="px-3 py-2.5 text-center">Statut</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                            @foreach($simulation['bilan_enseignants'] as $ens)
                                <tr class="hover:bg-slate-50/50 dark:hover:bg-slate-800/30">
                                    <td class="px-4 py-2.5 font-medium text-slate-900 dark:text-slate-100">
                                        {{ $ens['nom'] }}
                                        <span class="block text-[10px] text-slate-400">{{ $ens['matricule'] }}</span>
                                    </td>
                                    <td class="px-3 py-2.5 text-center font-bold">{{ $ens['examens'] }}</td>
                                    <td class="px-3 py-2.5 text-center">{{ $ens['gardes'] }}</td>
                                    <td class="px-3 py-2.5 text-center">{{ $ens['jours'] }}</td>
                                    <td class="px-3 py-2.5 text-center font-semibold text-indigo-600 dark:text-indigo-400">{{ $ens['charge'] }}</td>
                                    <td class="px-3 py-2.5 text-center font-semibold">
                                        {{ $ens['charge_enseignement_retenue'] ?? $ens['heures_enseignement'] ?? 0 }} h
                                        @if(($ens['heures_enseignement_hetd'] ?? 0) > 0 && ($ens['heures_enseignement_hetd'] ?? 0) !== ($ens['heures_enseignement'] ?? 0))
                                            <span class="block text-[10px] text-slate-400">({{ $ens['heures_enseignement_hetd'] }} HETD)</span>
                                        @endif
                                    </td>
                                    <td class="px-3 py-2.5 text-center text-slate-500 text-[11px]">
                                        @if(! empty($ens['charge_enseignement_detail']))
                                            CM: {{ $ens['charge_enseignement_detail']['cm'] }} · TD: {{ $ens['charge_enseignement_detail']['td'] }} · TP: {{ $ens['charge_enseignement_detail']['tp'] }}
                                        @else
                                            —
                                        @endif
                                    </td>
                                    <td class="px-3 py-2.5 text-center text-slate-500">
                                        {{ isset($ens['norme_enseignement']) ? round($ens['norme_enseignement'] * 100).'%' : '—' }}
                                    </td>
                                    <td class="px-3 py-2.5 text-center">
                                        @if(! empty($ens['en_surcharge_enseignement']))
                                            <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-bold bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-300">
                                                Surcharge cours
                                            </span>
                                        @elseif(! ($ens['donnees_charge_completes'] ?? true))
                                            <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-medium bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-400">
                                                Sans affectation
                                            </span>
                                        @else
                                            <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-medium bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-300">
                                                Normal
                                            </span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endif

        @if(! empty($simulation['propositions_epreuves']))
            <div class="mt-4 ds-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 shadow-sm overflow-hidden">
                <div class="px-5 py-3 border-b border-slate-100 dark:border-slate-800 flex items-center justify-between">
                    <h3 class="text-sm font-black text-slate-900 dark:text-slate-100">Besoins par salle ({{ count($simulation['propositions_epreuves']) }} épreuve(s))</h3>
                    <span class="text-xs text-slate-500">Calculé par salle, jamais sur le seul total</span>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-xs text-slate-600 dark:text-slate-300">
                        <thead class="bg-slate-50 dark:bg-slate-800/50 uppercase text-[10px] tracking-wider text-slate-500">
                            <tr>
                                <th class="px-4 py-2.5">Épreuve</th>
                                <th class="px-4 py-2.5">Salle</th>
                                <th class="px-3 py-2.5 text-center">Capacité</th>
                                <th class="px-3 py-2.5 text-center">Candidats</th>
                                <th class="px-3 py-2.5 text-center">Requis</th>
                                <th class="px-3 py-2.5 text-center">Règle appliquée</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                            @foreach($simulation['propositions_epreuves'] as $prop)
                                @forelse(($prop['besoins_salles'] ?? []) as $besoinSalle)
                                    <tr class="hover:bg-slate-50/50 dark:hover:bg-slate-800/30">
                                        <td class="px-4 py-2.5 font-medium text-slate-900 dark:text-slate-100">{{ $prop['code'] }}</td>
                                        <td class="px-4 py-2.5 font-mono">{{ $besoinSalle['code'] }}</td>
                                        <td class="px-3 py-2.5 text-center tabular-nums text-slate-500">{{ $besoinSalle['capacite'] ?? '—' }}</td>
                                        <td class="px-3 py-2.5 text-center tabular-nums font-semibold">{{ $besoinSalle['candidats'] }}</td>
                                        <td class="px-3 py-2.5 text-center font-bold text-indigo-600 dark:text-indigo-400">{{ $besoinSalle['besoin'] }}</td>
                                        <td class="px-3 py-2.5 text-center">
                                            <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-medium {{ $besoinSalle['source_regle'] === 'salle' ? 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-300' : ($besoinSalle['source_regle'] === 'grande_salle' ? 'bg-purple-100 text-purple-800 dark:bg-purple-900/40 dark:text-purple-300' : 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300') }}">
                                                {{ $besoinSalle['source_regle'] === 'salle' ? 'Exception salle' : ($besoinSalle['source_regle'] === 'grande_salle' ? 'Grande salle' : 'Générale') }}
                                            </span>
                                            <span class="text-[11px] text-slate-400 ml-1">min {{ $besoinSalle['minimum_applique'] }} · 1/{{ $besoinSalle['ratio_applique'] }}</span>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td class="px-4 py-2.5 font-medium text-slate-900 dark:text-slate-100">{{ $prop['code'] }}</td>
                                        <td colspan="5" class="px-4 py-2.5 text-slate-500">Sans ventilation par salle — besoin global {{ $prop['besoin_surveillants_actifs'] ?? '—' }} actif(s).</td>
                                    </tr>
                                @endforelse
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endif
    </div>
@endsection
