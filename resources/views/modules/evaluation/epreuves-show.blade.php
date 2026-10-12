@extends('layouts.app')

@section('title', 'Épreuve — ' . $epreuve->matiere->code)

@section('content')
    <div class="mx-auto max-w-6xl px-4 sm:px-6 py-8">
        <x-page-header :titre="$epreuve->matiere->libelle_complet" :description="'Le ' . $epreuve->date->format('d/m/Y') . ' · ' . substr($epreuve->heure_debut, 0, 5) . ' → ' . substr($epreuve->heure_fin, 0, 5) . ' · Session : ' . ($epreuve->session?->nom ?? '—')"
            :fil="['Épreuves' => route('admin.evaluations.epreuves.index'), $epreuve->matiere->code => '#']">
            <x-slot:actions>
                @can('notes_eval.view')
                    <x-btn href="{{ route('admin.evaluations.notes.saisie', $epreuve) }}">Saisir les notes</x-btn>
                @endcan
                @can('epreuves.view')
                    <x-btn variant="secondary" href="{{ route('admin.evaluations.epreuves.emargement', $epreuve) }}">Émargement Web</x-btn>
                    <x-btn variant="secondary" href="{{ route('admin.evaluations.epreuves.convocation', $epreuve) }}">Émargement PDF</x-btn>
                    <x-btn variant="secondary" href="{{ route('admin.listes.exporter', array_filter(['format' => 'csv', 'groupe_id' => $epreuve->groupe_id, 'matiere_id' => $epreuve->groupe_id ? null : $epreuve->matiere_id, 'annee_universitaire_id' => $epreuve->session?->annee_universitaire_id])) }}">Candidats CSV</x-btn>
                    <x-btn variant="secondary" href="{{ route('admin.evaluations.epreuves.incidents.index', $epreuve) }}">Incidents</x-btn>
                @endcan
                @can('epreuves.update')
                    @unless($epreuve->estVerrouillee())
                        <form method="post" action="{{ route('admin.evaluations.epreuves.candidats', [$epreuve->examen_id, $epreuve->id]) }}" class="inline">
                            @csrf
                            <x-btn variant="secondary" type="submit">Convoquer candidats</x-btn>
                        </form>
                    @endunless
                @endcan
            </x-slot:actions>
        </x-page-header>

        <!-- Statut & Cycle de vie (§6, §17) -->
        <div class="mt-4 ds-card p-4 bg-white rounded-xl border border-slate-200 flex flex-wrap items-center justify-between gap-4">
            <div class="flex items-center gap-3">
                <span class="text-xs font-bold uppercase tracking-wider text-slate-500">Statut actuel :</span>
                <x-status-badge :statut="$epreuve->statut" />
                @if($epreuve->estVerrouillee())
                    <span class="inline-flex items-center gap-1 text-xs font-bold text-slate-600 bg-slate-100 px-2 py-0.5 rounded">
                        🔒 Verrouillée
                    </span>
                @endif
            </div>

            @can('epreuves.update')
                @unless($epreuve->estVerrouillee())
                    <div class="flex items-center gap-2">
                        @if($epreuve->peutTransiterVers('published'))
                            <form method="post" action="{{ route('admin.evaluations.epreuves.transiter', [$epreuve, 'published']) }}" class="inline">
                                @csrf
                                <x-btn size="sm" variant="secondary" type="submit">Publier l'épreuve</x-btn>
                            </form>
                        @endif

                        @if($epreuve->peutTransiterVers('ongoing'))
                            <form method="post" action="{{ route('admin.evaluations.epreuves.transiter', [$epreuve, 'ongoing']) }}" class="inline">
                                @csrf
                                <x-btn size="sm" variant="secondary" type="submit">Démarrer</x-btn>
                            </form>
                        @endif

                        @if($epreuve->peutTransiterVers('completed'))
                            <form method="post" action="{{ route('admin.evaluations.epreuves.transiter', [$epreuve, 'completed']) }}" class="inline">
                                @csrf
                                <x-btn size="sm" variant="secondary" type="submit">Terminer</x-btn>
                            </form>
                        @endif

                        @if($epreuve->statut === 'completed')
                            <form method="post" action="{{ route('admin.evaluations.epreuves.verrouiller', $epreuve) }}" class="inline" onsubmit="return confirm('Verrouiller définitivement cette épreuve ?');">
                                @csrf
                                <x-btn size="sm" variant="danger" type="submit">Verrouiller l'épreuve</x-btn>
                            </form>
                        @endif
                    </div>
                @endunless
            @endcan
        </div>

        <!-- Section Affectation des salles (§8, §10) -->
        <div class="mt-4 ds-card p-5 bg-white rounded-xl border border-slate-200">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 pb-3">
                <div>
                    <h2 class="text-sm font-black uppercase tracking-wider text-slate-700">Affectation des Salles</h2>
                    <p class="text-xs text-slate-500 mt-0.5">
                        Salle principale : <strong>{{ $epreuve->salle?->code ?? 'Non assignée' }}</strong>
                        @if($epreuve->salle) (capacité : {{ $epreuve->salle->capacite ?? '—' }}) @endif
                        · Candidats assignés : {{ $epreuve->candidats->whereNotNull('salle_id')->count() }} / {{ $epreuve->candidats->count() }}
                    </p>
                </div>

                @can('epreuves.update')
                    @unless($epreuve->estVerrouillee())
                        <div class="flex items-center gap-2">
                            <!-- Formulaire Affectation Salle Principale -->
                            <form method="post" action="{{ route('admin.evaluations.epreuves.repartir-salles', $epreuve) }}" class="flex items-center gap-2">
                                @csrf
                                <input type="hidden" name="action" value="unique" />
                                <select name="salle_id" class="rounded-lg border border-slate-300 px-2.5 py-1 text-xs bg-white focus:border-indigo-500">
                                    <option value="">Sélectionner une salle...</option>
                                    @foreach($sallesDisponibles as $salle)
                                        <option value="{{ $salle->id }}" @selected($epreuve->salle_id === $salle->id)>
                                            {{ $salle->code }} (cap. {{ $salle->capacite ?? '—' }})
                                        </option>
                                    @endforeach
                                </select>
                                <x-btn size="sm" variant="secondary" type="submit">Assigner</x-btn>
                            </form>
                        </div>
                    @endunless
                @endcan
            </div>

            @if(session('simulation_repartition'))
                <div class="mt-3 p-3 bg-indigo-50 rounded-lg border border-indigo-200 text-xs">
                    <p class="font-bold text-indigo-900">Proposition de répartition multi-salles :</p>
                    <p class="text-indigo-700 mt-1">
                        {{ session('simulation_repartition')['total_candidats'] }} candidats répartis sur {{ count(session('simulation_repartition')['repartition']) }} places.
                    </p>
                    <form method="post" action="{{ route('admin.evaluations.epreuves.repartir-salles', $epreuve) }}" class="mt-2">
                        @csrf
                        <input type="hidden" name="action" value="appliquer" />
                        @foreach(session('simulation_repartition')['repartition'] as $rep)
                            <input type="hidden" name="salles[]" value="{{ $rep['salle_id'] }}" />
                        @endforeach
                        <x-btn size="sm" type="submit">Confirmer et appliquer la répartition</x-btn>
                    </form>
                </div>
            @endif
        </div>

        <div class="mt-4 grid gap-4 lg:grid-cols-2">
            <!-- Surveillants -->
            <section class="ds-card p-5 bg-white rounded-xl border border-slate-200">
                <div class="flex items-center justify-between border-b border-slate-100 pb-3">
                    <h2 class="text-sm font-black uppercase tracking-wider text-slate-700">Surveillants ({{ $epreuve->surveillants->count() }})</h2>
                    <a href="{{ route('admin.evaluations.surveillances.index', ['session_id' => $epreuve->session_id]) }}" class="text-xs text-indigo-600 hover:underline">
                        Gérer les gardes →
                    </a>
                </div>
                <ul class="mt-3 divide-y divide-slate-100 text-sm">
                    @forelse($epreuve->surveillants as $surveillance)
                        <li class="flex items-center justify-between py-2 text-xs">
                            <div>
                                <div class="flex items-center gap-1.5">
                                    <span class="font-semibold text-slate-900">{{ $surveillance->libelleSurveillant() }}</span>
                                    @if($surveillance->role === 'principal')
                                        <span class="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-bold bg-blue-100 text-blue-800 border border-blue-200">Principal</span>
                                    @elseif(in_array($surveillance->role, ['adjoint', 'surveillant'], true))
                                        <span class="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-bold bg-purple-100 text-purple-800 border border-purple-200">Adjoint</span>
                                    @elseif($surveillance->role === 'reserve')
                                        <span class="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-bold bg-amber-100 text-amber-800 border border-amber-200">Garde / Réserve</span>
                                    @endif
                                </div>
                                @if($surveillance->salle)
                                    <span class="text-slate-600 font-medium font-mono text-[11px] block mt-0.5">Salle : {{ $surveillance->salle->code }}</span>
                                @endif
                            </div>
                            <div class="flex items-center gap-2">
                                @if($surveillance->presence)
                                    <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-bold {{ $surveillance->presence === 'present' ? 'bg-emerald-100 text-emerald-800' : ($surveillance->presence === 'retard' ? 'bg-amber-100 text-amber-800' : 'bg-rose-100 text-rose-800') }}">
                                        {{ ucfirst($surveillance->presence) }}
                                    </span>
                                @endif
                                <x-status-badge :statut="$surveillance->statut" />
                                @can('update', $epreuve)
                                    @unless($epreuve->estVerrouillee() || $surveillance->statut === 'annule')
                                        <form method="post" action="{{ route('admin.evaluations.surveillants.desaffecter', $surveillance) }}" class="inline" onsubmit="return confirm('Retirer ce surveillant ?');">
                                            @csrf
                                            <button type="submit" class="text-slate-400 hover:text-rose-600 font-bold px-1 text-xs" title="Désaffecter">✕</button>
                                        </form>
                                    @endunless
                                @endcan
                            </div>
                        </li>
                    @empty
                        <li class="py-2 text-slate-500 text-xs">Aucun surveillant — <strong>anomalie bloquante pour la tenue de l’épreuve.</strong></li>
                    @endforelse
                </ul>

                @can('surveillances.assign')
                    @unless($epreuve->estVerrouillee() || $epreuve->statut === 'cancelled')
                        <form method="post" action="{{ route('admin.evaluations.surveillants.store', $epreuve) }}" class="mt-4 pt-3 border-t border-slate-100 flex flex-wrap items-center gap-2">
                            @csrf
                            <select name="teacher_id" class="rounded-lg border border-slate-200 text-xs px-2 py-1.5 flex-1 min-w-[160px]" required>
                                <option value="">-- Choisir un enseignant --</option>
                                @foreach($enseignantsDisponibles ?? [] as $ens)
                                    <option value="{{ $ens->id }}">
                                        @if(($ens->statut_dispo ?? '') === 'conflit')
                                            ✕ Conflit — {{ $ens->nomComplet() }}
                                        @elseif(($ens->statut_dispo ?? '') === 'charge_elevee')
                                            ⚠ Charge élevée — {{ $ens->nomComplet() }}
                                        @else
                                            ✓ Disponible — {{ $ens->nomComplet() }}
                                        @endif
                                    </option>
                                @endforeach
                            </select>
                            <select name="role" class="rounded-lg border border-slate-200 text-xs px-2 py-1.5" required>
                                <option value="principal">Principal</option>
                                <option value="adjoint">Adjoint</option>
                                <option value="reserve">Garde / Réserve</option>
                            </select>
                            <x-btn size="sm" type="submit">+ Affecter</x-btn>
                        </form>
                    @endunless
                @endcan
            </section>

            <!-- Candidats -->
            <section class="ds-card p-5 bg-white rounded-xl border border-slate-200">
                <div class="flex items-center justify-between border-b border-slate-100 pb-3">
                    <h2 class="text-sm font-black uppercase tracking-wider text-slate-700">Candidats ({{ $epreuve->candidats->count() }})</h2>
                    <span class="text-xs text-slate-400">Salle & Place</span>
                </div>
                <ul class="mt-3 max-h-72 divide-y divide-slate-100 overflow-auto text-sm">
                    @forelse($epreuve->candidats as $candidat)
                        <li class="flex items-center justify-between py-2 text-xs">
                            <div>
                                <span class="font-semibold text-slate-900">{{ $candidat->etudiant->nom_complet }}</span>
                                <span class="text-slate-400 font-mono text-[11px] block">{{ $candidat->etudiant->num_inscription }}</span>
                            </div>
                            <div class="flex items-center gap-2">
                                <span class="text-slate-600 font-medium">
                                    {{ $candidat->salleEffective()?->code ?? '—' }}
                                    @if($candidat->place)
                                        <span class="text-indigo-600 font-bold">({{ $candidat->place }})</span>
                                    @endif
                                </span>
                                <x-status-badge :statut="$candidat->statut" />
                            </div>
                        </li>
                    @empty
                        <li class="py-2 text-slate-500 text-xs">Aucun candidat convoqué pour cette épreuve.</li>
                    @endforelse
                </ul>
            </section>
        </div>
    </div>
@endsection
