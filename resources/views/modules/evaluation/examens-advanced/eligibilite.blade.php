@extends('layouts.app')

@section('title', 'Examens — Éligibilité & Candidats')

@section('content')

@php
    $sessionsEnCours = $sessionsStats['open'] + $sessionsStats['ongoing'];
    $pointsAttention = $statsPreparation['sans_salle'] + $sallesSansCapacite + $epreuvesSansSurveillant;
@endphp

<div class="space-y-6">
    <x-page-header
        titre="Éligibilité des candidats & Anonymat"
        description="Contrôle nominatif par module et épreuve : détection de la dérive de population, attribution des numéros anonymes stables et figeage du snapshot officiel."
        :fil="['Évaluation' => route('admin.evaluations.index'), 'Examens' => route('admin.evaluation.examens.tableau-de-bord'), 'Éligibilité' => '']"
    />

    @include('modules.evaluation.examens-advanced._nav-tabs')

    {{-- Sélecteur d'épreuve & Filtres --}}
    <div class="rounded-2xl border border-slate-200/80 bg-white p-5 shadow-xs">
        <form method="GET" action="{{ route('admin.evaluation.examens.eligibilite') }}" class="flex flex-wrap items-end gap-4">
            @if(request('annee_id'))
                <input type="hidden" name="annee_id" value="{{ request('annee_id') }}">
            @endif

            <div class="flex-1 min-w-[320px]">
                <label class="block text-xs font-semibold text-slate-600 mb-1.5">Sélectionner une épreuve d'examen :</label>
                <div class="relative">
                    <select name="epreuve_id" onchange="this.form.submit()" class="w-full rounded-xl border border-slate-200 bg-slate-50/50 px-3.5 py-2.5 text-sm font-medium text-slate-800 focus:border-primary-500 focus:bg-white focus:outline-none transition">
                        <option value="">-- Choisir une épreuve pour vérifier l'éligibilité ({{ $epreuves->count() }} disponibles) --</option>
                        @foreach($epreuves as $ep)
                            <option value="{{ $ep->id }}" @selected($epreuveId === (int)$ep->id)>
                                [{{ $ep->code }}] {{ $ep->matiere?->nom ?? 'Matière' }} — {{ $ep->date?->format('d/m/Y') }} ({{ $ep->statut }})
                            </option>
                        @endforeach
                    </select>
                </div>
            </div>

            @if($epreuveSelectionnee)
                <div class="flex items-center gap-2 pb-1">
                    <span class="inline-flex rounded-xl bg-slate-100 px-3 py-2 text-xs font-semibold text-slate-700">
                        {{ $epreuveSelectionnee->session?->nom ?? 'Session' }}
                    </span>
                    <a href="{{ route('admin.examens.preparation.epreuves.emargement', $epreuveSelectionnee) }}" target="_blank" class="inline-flex items-center gap-1.5 rounded-xl bg-rose-50 border border-rose-200 text-rose-800 hover:bg-rose-100 px-3 py-2 text-xs font-bold transition shadow-2xs">
                        <svg class="h-3.5 w-3.5 text-rose-600" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M4 4a2 2 0 012-2h4.586A2 2 0 0112 2.586L15.414 6A2 2 0 0116 7.414V16a2 2 0 01-2 2H6a2 2 0 01-2-2V4zm2 6a1 1 0 011-1h6a1 1 0 110 2H7a1 1 0 01-1-1zm1 3a1 1 0 100 2h6a1 1 0 100-2H7z" clip-rule="evenodd"/></svg>
                        Émargement PDF
                    </a>
                </div>
            @endif
        </form>
    </div>

    @if($epreuveSelectionnee)
        @php
            $correspondance = $auditEligibilite['correspondance'] ?? app(\App\Modules\Evaluation\Services\CorrespondanceExamenService::class)->analyserEpreuve($epreuveSelectionnee);
            $perimetre = $correspondance['perimetre'] ?? [];
            $compteurs = $correspondance['compteurs'] ?? ['inscrits' => 0, 'eligibles' => 0, 'planifies' => 0, 'convoques' => 0, 'exclus' => 0];
            $ecarts = $correspondance['ecarts'] ?? [];
        @endphp

        {{-- Alerte de discordance matière ↔ groupes ↔ étudiants --}}
        @if(!($correspondance['conforme'] ?? true))
            <div class="rounded-2xl border border-rose-300 bg-rose-50/80 p-5 shadow-xs flex flex-wrap items-center justify-between gap-4">
                <div class="space-y-1">
                    <h4 class="font-bold text-sm text-rose-900">Publication bloquée : la correspondance matière/groupes n'est pas validée.</h4>
                    <ul class="list-disc pl-4 text-xs text-rose-800 space-y-0.5">
                        @foreach($correspondance['alertes'] ?? [] as $alerte)
                            <li class="font-semibold">{{ $alerte }}</li>
                        @endforeach
                    </ul>
                </div>
                @can('epreuves.update')
                    @unless($epreuveSelectionnee->estVerrouillee())
                        <form method="POST" action="{{ route('admin.evaluations.epreuves.candidats', [$epreuveSelectionnee->examen_id, $epreuveSelectionnee->id]) }}">
                            @csrf
                            <button type="submit" class="rounded-xl bg-rose-700 hover:bg-rose-800 text-white px-4 py-2 text-xs font-bold transition shadow-xs">
                                Synchroniser la liste des candidats éligibles
                            </button>
                        </form>
                    @endunless
                @endcan
            </div>
        @endif

        {{-- Alerte de dérive de population le cas échéant --}}
        @if(isset($auditEligibilite) && $auditEligibilite['est_derivee'])
            <div class="rounded-2xl border border-amber-300 bg-amber-50/70 p-5 shadow-xs flex flex-wrap items-center justify-between gap-4">
                <div class="flex items-start gap-3">
                    <span class="inline-flex rounded-full bg-amber-100 p-2 text-amber-700 shrink-0 mt-0.5">
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z" /></svg>
                    </span>
                    <div>
                        <h4 class="font-bold text-sm text-amber-900">Dérive de population détectée (G4 / Audit Sécurité)</h4>
                        <p class="text-xs text-amber-800 mt-0.5">
                            La population étudiante a varié depuis la dernière validation du snapshot officiel : <strong>{{ $auditEligibilite['effectif_valide'] }}</strong> candidats validés vs <strong>{{ $auditEligibilite['total_candidats'] }}</strong> candidats actuels. Cette anomalie bloque formellement l'ouverture de la session tant qu'elle n'est pas revalidée.
                        </p>
                    </div>
                </div>
                <form method="POST" action="{{ route('admin.examens.preparation.epreuves.population', $epreuveSelectionnee) }}">
                    @csrf
                    <button type="submit" class="rounded-xl bg-amber-600 hover:bg-amber-700 text-white px-4 py-2 text-xs font-bold transition shadow-xs">
                        Revalider et figer la population
                    </button>
                </form>
            </div>
        @endif

        {{-- Cartes de synthèse de l'épreuve --}}
        <div class="grid grid-cols-1 sm:grid-cols-4 gap-4">
            <div class="rounded-2xl border border-slate-200/80 bg-white p-4 shadow-xs">
                <span class="text-xs font-semibold uppercase tracking-wider text-slate-500">Matière & Périmètre</span>
                <p class="font-bold text-slate-900 mt-1 truncate">{{ $epreuveSelectionnee->matiere?->nom ?? '—' }}</p>
                <span class="text-xs font-mono text-slate-500">{{ $epreuveSelectionnee->matiere?->code }} (ID #{{ $epreuveSelectionnee->matiere_id }})</span>
                <p class="text-[11px] text-slate-500 mt-1">
                    Niveau : <strong>{{ $perimetre['niveau_code'] ?? '—' }}</strong> · Filière : <strong>{{ $perimetre['filiere'] ?? '—' }}</strong>
                </p>
                <p class="text-[11px] text-slate-500">
                    Parcours : <strong>{{ $perimetre['programme_code'] ?? '—' }}</strong> · Groupes :
                    @forelse($perimetre['groupes_concernes'] ?? [] as $grp)
                        <span class="font-mono font-bold text-indigo-700">{{ $grp['code'] }}</span>@if(!$loop->last), @endif
                    @empty
                        <span>Tous groupes</span>
                    @endforelse
                </p>
            </div>

            <div class="rounded-2xl border border-slate-200/80 bg-white p-4 shadow-xs">
                <span class="text-xs font-semibold uppercase tracking-wider text-slate-500">Date, Horaire & Session</span>
                <p class="font-bold text-slate-900 mt-1">{{ $epreuveSelectionnee->date?->format('d/m/Y') }}</p>
                <span class="text-xs text-slate-500">{{ substr($epreuveSelectionnee->heure_debut, 0, 5) }} → {{ substr($epreuveSelectionnee->heure_fin, 0, 5) }}</span>
                <p class="text-[11px] text-slate-500 mt-1">Session : <strong>{{ $perimetre['session_nom'] ?? '—' }}</strong></p>
            </div>

            <div class="rounded-2xl border border-slate-200/80 bg-white p-4 shadow-xs">
                <span class="text-xs font-semibold uppercase tracking-wider text-slate-500">Effectifs de Correspondance</span>
                <p class="font-bold text-slate-900 mt-1">
                    Inscrits : {{ $compteurs['inscrits'] ?? 0 }} · Éligibles : {{ $compteurs['eligibles'] ?? 0 }}
                </p>
                <span class="text-xs text-slate-500 block">
                    Planifiés : {{ $compteurs['planifies'] ?? 0 }} · Convoqués : {{ $compteurs['convoques'] ?? 0 }} · Exclus : {{ $compteurs['exclus'] ?? 0 }}
                </span>
                <span class="text-[11px] text-slate-400">{{ $auditEligibilite['candidats_avec_anonymat'] ?? $candidats->total() }} numéros anonymes</span>
            </div>

            <div class="rounded-2xl border border-slate-200/80 bg-white p-4 shadow-xs flex flex-col justify-between">
                <div>
                    <span class="text-xs font-semibold uppercase tracking-wider text-slate-500">État du Snapshot</span>
                    <div class="mt-1">
                        @if(isset($auditEligibilite) && $auditEligibilite['est_derivee'])
                            <span class="inline-flex rounded-full bg-amber-100 text-amber-800 px-2.5 py-0.5 text-xs font-bold">
                                Dérive détectée
                            </span>
                        @elseif($epreuveSelectionnee->population_validated_at)
                            <span class="inline-flex rounded-full bg-emerald-100 text-emerald-800 px-2.5 py-0.5 text-xs font-bold">
                                Validé ({{ $epreuveSelectionnee->population_effectif }} c.)
                            </span>
                        @else
                            <span class="inline-flex rounded-full bg-slate-100 text-slate-600 px-2.5 py-0.5 text-xs font-bold">
                                Non validé
                            </span>
                        @endif
                    </div>
                </div>

                @if(!$epreuveSelectionnee->population_validated_at)
                    <form method="POST" action="{{ route('admin.examens.preparation.epreuves.population', $epreuveSelectionnee) }}" class="mt-2">
                        @csrf
                        <button type="submit" class="w-full rounded-lg bg-primary-800 hover:bg-primary-900 text-white py-1.5 text-[11px] font-bold transition">
                            Figer le snapshot
                        </button>
                    </form>
                @endif
            </div>
        </div>

        {{-- Table des candidats et décision d'éligibilité --}}
        <div class="rounded-2xl border border-slate-200/80 bg-white p-6 shadow-xs">
            <div class="flex items-center justify-between mb-4">
                <h3 class="font-bold text-base text-slate-900">Liste nominative des candidats évalués</h3>
                <span class="text-xs text-slate-500">{{ $candidats->total() }} candidat(s) répertorié(s)</span>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead>
                        <tr class="border-b border-slate-100 bg-slate-50/50 text-xs font-semibold text-slate-500 uppercase tracking-wider">
                            <th class="py-3 px-3">Candidat (Matricule)</th>
                            <th class="py-3 px-3">N° Anonyme (G5)</th>
                            <th class="py-3 px-3">Groupe</th>
                            <th class="py-3 px-3">Inscription Pédagogique</th>
                            <th class="py-3 px-3">Décision Éligibilité</th>
                            <th class="py-3 px-3">Salle & Place</th>
                            <th class="py-3 px-3 text-right">Convocation</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @forelse($candidats as $candidat)
                            <tr class="hover:bg-slate-50/40 transition">
                                <td class="py-3 px-3">
                                    <span class="font-semibold text-slate-900">{{ $candidat->etudiant->nom ?? '' }} {{ $candidat->etudiant->prenom ?? '' }}</span>
                                    <span class="block text-xs font-mono text-slate-400">#{{ $candidat->etudiant->num_inscription ?? '—' }}</span>
                                </td>
                                <td class="py-3 px-3">
                                    @if($candidat->numero_anonyme)
                                        <span class="inline-flex rounded-lg bg-slate-100 font-mono text-xs font-bold text-slate-800 px-2 py-0.5 border border-slate-200">
                                            {{ $candidat->numero_anonyme }}
                                        </span>
                                    @else
                                        <span class="text-xs text-slate-400 italic">En attente snapshot</span>
                                    @endif
                                </td>
                                <td class="py-3 px-3 text-slate-600 font-medium">{{ $candidat->etudiant->groupe?->code ?? '—' }}</td>
                                <td class="py-3 px-3">
                                    @if($candidat->inscription_pedagogique_id)
                                        <span class="inline-flex items-center gap-1 text-emerald-700 text-xs font-semibold">
                                            <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" /></svg>
                                            Inscrit ECUE
                                        </span>
                                    @else
                                        <span class="inline-flex items-center gap-1 text-amber-700 text-xs font-semibold">
                                            Rattachement groupe
                                        </span>
                                    @endif
                                </td>
                                <td class="py-3 px-3">
                                    <span class="inline-flex rounded-full bg-emerald-100 text-emerald-800 px-2.5 py-0.5 text-xs font-bold">
                                        Admissible
                                    </span>
                                </td>
                                <td class="py-3 px-3">
                                    <div class="font-medium text-slate-800">{{ $candidat->salleEffective()?->code ?? $epreuveSelectionnee->salle?->code ?? 'Non assignée' }}</div>
                                    <div class="text-[11px] text-slate-400">Place : {{ $candidat->place ?? '—' }}</div>
                                </td>
                                <td class="py-3 px-3 text-right">
                                    <a href="{{ route('admin.evaluations.candidats.convocation', $candidat) }}" class="inline-flex items-center gap-1 rounded-lg bg-slate-100 hover:bg-slate-200 text-slate-700 px-2.5 py-1 text-xs font-semibold transition">
                                        <svg class="h-3.5 w-3.5 text-slate-500" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M16.5 12L12 16.5m0 0L7.5 12m4.5 4.5V3" /></svg>
                                        PDF
                                    </a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="py-8 text-center text-sm text-slate-500">Aucun candidat généré pour cette épreuve.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="mt-4">
                {{ $candidats->links() }}
            </div>
        </div>

        {{-- Étudiants exclus et écarts détectés --}}
        @if(!empty($correspondance['etudiants_exclus']) || !empty($ecarts['eligibles_omis']) || !empty($ecarts['candidats_non_eligibles']))
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
                <div class="rounded-2xl border border-amber-200 bg-white p-5 shadow-xs">
                    <h4 class="font-bold text-sm text-amber-900 mb-3">
                        Étudiants exclus ({{ count($correspondance['etudiants_exclus'] ?? []) }}) & Raison métier d'exclusion
                    </h4>
                    <div class="max-h-60 overflow-y-auto divide-y divide-slate-100 text-xs">
                        @forelse($correspondance['etudiants_exclus'] ?? [] as $exclu)
                            <div class="py-2 flex items-start justify-between gap-2">
                                <div>
                                    <span class="font-semibold text-slate-900">{{ $exclu['nom'] }} {{ $exclu['prenom'] }}</span>
                                    <span class="block font-mono text-[11px] text-slate-400">#{{ $exclu['num_inscription'] }} · {{ $exclu['groupe_code'] }}</span>
                                </div>
                                <span class="rounded bg-amber-50 border border-amber-200 px-2 py-0.5 text-amber-800 font-semibold text-right">
                                    {{ $exclu['motif'] }}
                                </span>
                            </div>
                        @empty
                            <p class="py-2 text-slate-500">Aucun étudiant exclu dans ce périmètre.</p>
                        @endforelse
                    </div>
                </div>

                <div class="rounded-2xl border border-rose-200 bg-white p-5 shadow-xs">
                    <h4 class="font-bold text-sm text-rose-900 mb-3">
                        Écarts détectés entre inscriptions de référence et planning
                    </h4>
                    <div class="max-h-60 overflow-y-auto divide-y divide-slate-100 text-xs">
                        @foreach($ecarts['candidats_non_eligibles'] ?? [] as $nonElig)
                            <div class="py-2 flex items-start justify-between gap-2 text-rose-800">
                                <span><strong>Planifié non inscrit / non éligible :</strong> {{ $nonElig['nom'] }} {{ $nonElig['prenom'] }} (#{{ $nonElig['num_inscription'] }})</span>
                                <span class="font-semibold">{{ $nonElig['motif'] }}</span>
                            </div>
                        @endforeach
                        @foreach($ecarts['eligibles_omis'] ?? [] as $omis)
                            <div class="py-2 flex items-start justify-between gap-2 text-amber-900">
                                <span><strong>Éligible omis du planning :</strong> {{ $omis['nom'] }} {{ $omis['prenom'] }} (#{{ $omis['num_inscription'] }})</span>
                                <span class="font-semibold">Absent de la planification</span>
                            </div>
                        @endforeach
                        @if(empty($ecarts['candidats_non_eligibles']) && empty($ecarts['eligibles_omis']))
                            <p class="py-2 text-emerald-700 font-semibold">Aucun écart entre la liste de référence et le planning.</p>
                        @endif
                    </div>
                </div>
            </div>
        @endif
    @else
        <div class="rounded-2xl border border-dashed border-slate-300 bg-white p-12 text-center">
            <svg class="mx-auto h-12 w-12 text-slate-400" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 0 0 2.625.372 9.337 9.337 0 0 0 4.121-.952 4.125 4.125 0 0 0-7.533-2.493" /></svg>
            <h3 class="mt-3 text-sm font-bold text-slate-900">Aucune épreuve sélectionnée</h3>
            <p class="mt-1 text-xs text-slate-500">Veuillez choisir une épreuve dans la liste déroulante ci-dessus pour inspecter l'éligibilité des candidats et le statut du snapshot.</p>
        </div>
    @endif
</div>
@endsection