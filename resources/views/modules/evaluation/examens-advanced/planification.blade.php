@extends('layouts.app')

@section('title', 'Examens — Planification automatique')

@section('content')

@php
    $sessionsEnCours = $sessionsStats['open'] + $sessionsStats['ongoing'];
    $pointsAttention = $statsPreparation['sans_salle'] + $sallesSansCapacite + $epreuvesSansSurveillant;
@endphp

<div class="space-y-6" x-data="{ scenarioActif: '{{ $recommandationId ?? array_key_first($scenarios) ?? 'SCENARIO_ETALE' }}' }">
    <x-page-header
        titre="Planification automatique d'examen"
        description="Moteur de contraintes CSP : génération et comparaison de 3 scénarios (équilibré, compact, amphis prioritaires) avec respect strict du ratio anti-fraude 50% et anti-collision."
        :fil="['Évaluation' => route('admin.evaluations.index'), 'Examens' => route('admin.evaluation.examens.tableau-de-bord'), 'Planification' => '']"
    />

    @include('modules.evaluation.examens-advanced._nav-tabs')

    {{-- Sélecteur d'examen & Filtres --}}
    <div class="rounded-2xl border border-slate-200/80 bg-white p-5 shadow-xs">
        <form method="GET" action="{{ route('admin.evaluation.examens.planification') }}" class="flex flex-wrap items-end gap-4">
            @if(request('annee_id'))
                <input type="hidden" name="annee_id" value="{{ request('annee_id') }}">
            @endif

            <div class="flex-1 min-w-[320px]">
                <label class="block text-xs font-semibold text-slate-600 mb-1.5">Sélectionner un examen à planifier :</label>
                <div class="relative">
                    <select name="examen_id" onchange="this.form.submit()" class="w-full rounded-xl border border-slate-200 bg-slate-50/50 px-3.5 py-2.5 text-sm font-medium text-slate-800 focus:border-primary-500 focus:bg-white focus:outline-none transition">
                        <option value="">-- Choisir un examen ({{ $examens->count() }} disponibles) --</option>
                        @foreach($examens as $ex)
                            <option value="{{ $ex->id }}" @selected($examenId === (int)$ex->id)>
                                [{{ $ex->code }}] {{ $ex->libelle }} — Session: {{ $ex->session?->nom ?? 'Standard' }} ({{ $ex->statut }})
                            </option>
                        @endforeach
                    </select>
                </div>
            </div>

            @if($examenSelectionne)
                <div class="flex items-center gap-2 pb-1">
                    <span class="inline-flex items-center gap-1.5 rounded-xl bg-slate-100 px-3 py-2 text-xs font-semibold text-slate-700">
                        <span class="h-2 w-2 rounded-full {{ $examenSelectionne->statut === 'scheduled' ? 'bg-emerald-500' : 'bg-amber-500' }}"></span>
                        Statut : {{ ucfirst($examenSelectionne->statut) }}
                    </span>
                    <span class="inline-flex rounded-xl bg-primary-50 text-primary-800 px-3 py-2 text-xs font-semibold border border-primary-100">
                        {{ $examenSelectionne->epreuves->count() }} épreuve(s)
                    </span>
                </div>
            @endif
        </form>
    </div>

    @if($examenSelectionne)
        @if(!empty($erreurPlanification))
            <div class="rounded-2xl border border-amber-200 bg-amber-50/80 p-5 text-sm text-amber-900 shadow-2xs">
                <div class="flex items-start gap-3">
                    <svg class="h-5 w-5 shrink-0 text-amber-600 mt-0.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" />
                    </svg>
                    <div>
                        <p class="font-bold text-amber-950">Génération de scénarios bloquée pour cet examen</p>
                        <p class="mt-1 text-xs text-amber-800">{{ $erreurPlanification }}</p>
                    </div>
                </div>
            </div>
        @endif

        {{-- En-tête de comparaison des scénarios --}}
        <div class="space-y-4">
            <div class="flex items-center justify-between">
                <div>
                    <h3 class="font-bold text-lg text-slate-900">Scénarios calculés pour {{ $examenSelectionne->libelle }}</h3>
                    <p class="text-xs text-slate-500">Comparez les variantes d'ordonnancement selon vos priorités logistiques et académiques.</p>
                </div>
                @if(!empty($recommandation))
                    <span class="inline-flex items-center gap-1.5 rounded-full bg-emerald-50 border border-emerald-200 text-emerald-800 px-3 py-1 text-xs font-bold shadow-2xs">
                        <svg class="w-3.5 h-3.5 text-emerald-600" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/></svg>
                        Scénario recommandé : {{ is_array($recommandation) ? ($recommandation['nom'] ?? $recommandation['scenario_id'] ?? 'Recommandé') : $recommandation }}
                    </span>
                @endif
            </div>

            {{-- Cartes comparatives des 3 scénarios --}}
            <div class="grid grid-cols-1 md:grid-cols-3 gap-5">
                @forelse($scenarios as $cle => $sc)
                    @php
                        $estValide = (bool) ($sc['est_valide'] ?? false);
                        $score = (int) ($sc['score_qualite'] ?? 0);
                        $conflits = (int) ($sc['nb_conflits_durs'] ?? 0);
                        $estRecommande = ($cle === ($recommandationId ?? (is_array($recommandation) ? ($recommandation['scenario_id'] ?? null) : $recommandation)));
                    @endphp

                    <div 
                        @click="scenarioActif = '{{ $cle }}'"
                        :class="scenarioActif === '{{ $cle }}' ? 'ring-2 ring-primary-600 shadow-md' : 'hover:border-slate-300'"
                        class="cursor-pointer rounded-2xl border transition-all duration-200 {{ $estValide ? ($estRecommande ? 'border-primary-300 bg-white' : 'border-slate-200/90 bg-white') : 'border-rose-200 bg-rose-50/20' }} p-5 flex flex-col justify-between"
                    >
                        <div>
                            {{-- Header carte --}}
                            <div class="flex items-start justify-between gap-2">
                                <div>
                                    <div class="flex items-center gap-2">
                                        <span class="font-bold text-base text-slate-900 capitalize">{{ $sc['nom'] ?? $cle }}</span>
                                        @if($estRecommande)
                                            <span class="inline-flex rounded-full bg-primary-100 text-primary-800 px-2 py-0.5 text-[10px] font-extrabold uppercase tracking-wide">
                                                Recommandé
                                            </span>
                                        @endif
                                    </div>
                                    <p class="mt-1 text-xs text-slate-500 line-clamp-2">{{ $sc['description'] ?? 'Scénario automatique' }}</p>
                                </div>
                                @if($estValide)
                                    <span class="inline-flex shrink-0 rounded-full bg-emerald-100 text-emerald-800 px-2.5 py-1 text-xs font-bold">
                                        Conforme
                                    </span>
                                @else
                                    <span class="inline-flex shrink-0 rounded-full bg-rose-100 text-rose-800 px-2.5 py-1 text-xs font-bold">
                                        {{ $conflits }} conflit(s) dur(s)
                                    </span>
                                @endif
                            </div>

                            {{-- Score bar --}}
                            <div class="mt-4">
                                <div class="flex justify-between items-center text-xs mb-1">
                                    <span class="font-medium text-slate-600">Score de qualité CSP :</span>
                                    <span class="font-extrabold {{ $score >= 80 ? 'text-emerald-700' : ($score >= 60 ? 'text-amber-700' : 'text-rose-700') }}">{{ $score }} / 100</span>
                                </div>
                                <div class="h-2 w-full rounded-full bg-slate-100 overflow-hidden">
                                    <div class="h-full rounded-full transition-all duration-500 {{ $score >= 80 ? 'bg-emerald-500' : ($score >= 60 ? 'bg-amber-500' : 'bg-rose-500') }}" style="width: {{ max(5, min(100, $score)) }}%"></div>
                                </div>
                            </div>

                            {{-- Métriques clés --}}
                            <div class="mt-4 space-y-2 border-t border-slate-100 pt-3 text-xs">
                                <div class="flex justify-between items-center">
                                    <span class="text-slate-500">Épreuves ordonnancées :</span>
                                    <span class="font-bold text-slate-800">{{ count($sc['placements'] ?? []) }} / {{ $examenSelectionne->epreuves->count() }}</span>
                                </div>
                                <div class="flex justify-between items-center">
                                    <span class="text-slate-500">Jours mobilisés :</span>
                                    <span class="font-semibold text-slate-700">{{ $sc['metriques']['jours_mobilises'] ?? count(array_unique(array_column($sc['placements'] ?? [], 'date'))) }} j</span>
                                </div>
                                <div class="flex justify-between items-center">
                                    <span class="text-slate-500">Taux remplissage utile (50%) :</span>
                                    <span class="font-semibold text-slate-700">{{ $sc['metriques']['taux_remplissage_moyen'] ?? '—' }} %</span>
                                </div>
                            </div>
                        </div>

                        {{-- Action bouton --}}
                        <div class="mt-5 pt-3 border-t border-slate-100">
                            @if($estValide)
                                <form method="POST" action="{{ route('admin.evaluations.examens.planifier.appliquer', $examenSelectionne) }}">
                                    @csrf
                                    <input type="hidden" name="scenario_id" value="{{ $cle }}">
                                    <input type="hidden" name="scenario_data" value="{{ json_encode($sc) }}">
                                    <button type="submit" class="w-full rounded-xl bg-primary-800 hover:bg-primary-900 text-white py-2.5 text-xs font-bold shadow-xs hover:shadow-sm transition">
                                        Appliquer ce scénario
                                    </button>
                                </form>
                            @else
                                <button disabled class="w-full rounded-xl bg-slate-100 text-slate-400 py-2.5 text-xs font-bold cursor-not-allowed">
                                    Scénario non publiable (conflits)
                                </button>
                            @endif
                        </div>
                    </div>
                @empty
                    <div class="col-span-3 rounded-2xl border border-dashed border-slate-300 p-8 text-center text-sm text-slate-500">
                        Aucun scénario calculable (vérifiez que l'examen possède des épreuves avec matière et effectif requis).
                    </div>
                @endforelse
            </div>

            {{-- Détail des épreuves pour le scénario sélectionné --}}
            @foreach($scenarios as $cle => $sc)
                <div x-show="scenarioActif === '{{ $cle }}'" x-transition class="space-y-4 mt-6">
                    <div class="flex items-center justify-between">
                        <h4 class="font-bold text-sm text-slate-800">
                            Planning détaillé du scénario : <span class="capitalize text-primary-800">{{ $sc['nom'] ?? $cle }}</span>
                        </h4>
                        <span class="text-xs text-slate-500">Ratio d'espacement réglementaire 50% appliqué sur toutes les salles</span>
                    </div>

                    {{-- Table des placements --}}
                    <div class="rounded-2xl border border-slate-200/80 bg-white overflow-hidden shadow-xs">
                        <div class="overflow-x-auto">
                            <table class="w-full text-left text-sm">
                                <thead>
                                    <tr class="border-b border-slate-100 bg-slate-50/60 text-xs font-semibold text-slate-500 uppercase tracking-wider">
                                        <th class="py-3 px-4">Date & Horaire</th>
                                        <th class="py-3 px-4">Épreuve / Matière</th>
                                        <th class="py-3 px-4">Salle & Bâtiment</th>
                                        <th class="py-3 px-4">Capacité utile (50%)</th>
                                        <th class="py-3 px-4">Remplissage</th>
                                        <th class="py-3 px-4">Confort Anti-Fraude</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100">
                                    @forelse($sc['placements'] ?? [] as $p)
                                        <tr class="hover:bg-slate-50/40 transition">
                                            <td class="py-3 px-4">
                                                <div class="font-bold text-slate-900">{{ \Carbon\Carbon::parse($p['date'])->translatedFormat('l d F Y') }}</div>
                                                <div class="text-xs text-slate-500">{{ substr($p['heure_debut'], 0, 5) }} - {{ substr($p['heure_fin'], 0, 5) }}</div>
                                            </td>
                                            <td class="py-3 px-4">
                                                <div class="font-semibold text-slate-800">{{ $p['matiere_nom'] ?? 'Matière' }}</div>
                                                <div class="text-xs text-slate-400 font-mono">{{ $p['matiere_code'] ?? '—' }}</div>
                                            </td>
                                            <td class="py-3 px-4">
                                                <span class="inline-flex items-center gap-1.5 font-bold text-slate-800">
                                                    <svg class="w-4 h-4 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/></svg>
                                                    {{ $p['salle_code'] ?? 'Salle '.$p['salle_id'] }}
                                                </span>
                                                @if(!empty($p['salle_nom']))
                                                    <div class="text-xs text-slate-500">{{ $p['salle_nom'] }}</div>
                                                @endif
                                            </td>
                                            <td class="py-3 px-4">
                                                <div class="font-semibold text-primary-800">{{ $p['capacite_utile_50'] ?? '—' }} places utiles</div>
                                                <div class="text-[11px] text-slate-400">Théorique : {{ $p['capacite_nominale'] ?? '—' }}</div>
                                            </td>
                                            <td class="py-3 px-4">
                                                <div class="font-bold text-slate-800">{{ $p['effectif_requis'] ?? '—' }} candidats</div>
                                                <div class="text-xs text-slate-500">{{ $p['taux_remplissage_utile'] ?? 0 }}% utile</div>
                                            </td>
                                            <td class="py-3 px-4">
                                                @php
                                                    $confort = $p['confort_statut'] ?? 'optimal';
                                                @endphp
                                                @if($confort === 'optimal')
                                                    <span class="inline-flex rounded-full bg-emerald-50 border border-emerald-200 text-emerald-800 px-2.5 py-0.5 text-xs font-semibold">
                                                        Optimal (< 85%)
                                                    </span>
                                                @elseif($confort === 'dense')
                                                    <span class="inline-flex rounded-full bg-amber-50 border border-amber-200 text-amber-800 px-2.5 py-0.5 text-xs font-semibold">
                                                        Dense (85-100%)
                                                    </span>
                                                @else
                                                    <span class="inline-flex rounded-full bg-rose-50 border border-rose-200 text-rose-800 px-2.5 py-0.5 text-xs font-semibold">
                                                        Surcharge (> 100%)
                                                    </span>
                                                @endif
                                            </td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="6" class="py-6 text-center text-sm text-slate-400">Aucun placement calculé.</td>
                                        </tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    @else
        <div class="rounded-2xl border border-dashed border-slate-300 bg-white p-12 text-center">
            <svg class="mx-auto h-12 w-12 text-slate-400" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" /></svg>
            <h3 class="mt-3 text-sm font-bold text-slate-900">Sélectionnez un examen</h3>
            <p class="mt-1 text-xs text-slate-500">Choisissez un examen dans la liste ci-dessus pour lancer la génération instantanée des propositions d'ordonnancement CSP.</p>
        </div>
    @endif

    {{-- Zone dédiée aux documents professionnels (LOT 2) --}}
    @include('modules.evaluation.examens-advanced._zone-documents-professionnels', ['optionCourante' => 'planification'])
</div>
@endsection