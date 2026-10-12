@extends('layouts.app')

@section('title', 'Planning des Examens')

@section('content')
    <div class="mx-auto max-w-7xl px-4 sm:px-6 py-8">
        <x-page-header titre="Planning des examens" description="Calendrier transverse et détection des conflits : sessions, ECUE, salles, horaires et surveillants."
            :fil="['Évaluations' => route('admin.evaluations.index'), 'Planning' => '#']">
            <x-slot:actions>
                <x-btn variant="secondary" href="{{ route('admin.evaluations.epreuves.index') }}">Liste des épreuves</x-btn>
                <x-btn variant="secondary" href="{{ route('admin.evaluations.surveillances.cockpit') }}">Cockpit Gardes</x-btn>
                <x-btn variant="secondary" href="{{ route('admin.evaluations.surveillances.index') }}">Surveillances</x-btn>
                <x-btn href="{{ route('admin.evaluations.sessions.index') }}">Sessions</x-btn>
            </x-slot:actions>
        </x-page-header>

        <!-- Filtres avancés (§7) -->
        <div class="mt-6 ds-card p-5 bg-white shadow-xs rounded-xl border border-slate-200">
            <form method="get" action="{{ route('admin.evaluations.planning') }}" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 text-sm">
                <div>
                    <label class="block text-xs font-bold uppercase tracking-wider text-slate-600 mb-1">Session</label>
                    <select name="session_id" class="w-full rounded-lg border border-slate-300 px-3 py-1.5 text-sm bg-white focus:border-indigo-500">
                        <option value="">Toutes les sessions</option>
                        @foreach($sessions as $session)
                            <option value="{{ $session->id }}" @selected(request('session_id') == $session->id)>
                                {{ $session->nom }} ({{ $session->type }})
                            </option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-bold uppercase tracking-wider text-slate-600 mb-1">Programme / Parcours</label>
                    <select name="programme_id" class="w-full rounded-lg border border-slate-300 px-3 py-1.5 text-sm bg-white focus:border-indigo-500">
                        <option value="">Tous les programmes</option>
                        @foreach($programmes as $programme)
                            <option value="{{ $programme->id }}" @selected(request('programme_id') == $programme->id)>
                                {{ $programme->code }} — {{ $programme->nom }}
                            </option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-bold uppercase tracking-wider text-slate-600 mb-1">Niveau</label>
                    <select name="niveau" class="w-full rounded-lg border border-slate-300 px-3 py-1.5 text-sm bg-white focus:border-indigo-500">
                        <option value="">Tous les niveaux</option>
                        @foreach(['L1', 'L2', 'L3', 'M1', 'M2', 'D1', 'D2', 'D3'] as $niv)
                            <option value="{{ $niv }}" @selected(request('niveau') == $niv)>{{ $niv }}</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-bold uppercase tracking-wider text-slate-600 mb-1">Matière / ECUE</label>
                    <select name="matiere_id" class="w-full rounded-lg border border-slate-300 px-3 py-1.5 text-sm bg-white focus:border-indigo-500">
                        <option value="">Toutes les matières</option>
                        @foreach($matieres as $matiere)
                            <option value="{{ $matiere->id }}" @selected(request('matiere_id') == $matiere->id)>
                                {{ $matiere->code }} — {{ $matiere->nom }}
                            </option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-bold uppercase tracking-wider text-slate-600 mb-1">Salle</label>
                    <select name="salle_id" class="w-full rounded-lg border border-slate-300 px-3 py-1.5 text-sm bg-white focus:border-indigo-500">
                        <option value="">Toutes les salles</option>
                        @foreach($salles as $salle)
                            <option value="{{ $salle->id }}" @selected(request('salle_id') == $salle->id)>
                                {{ $salle->code }} (cap. {{ $salle->capacite ?? '—' }})
                            </option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-bold uppercase tracking-wider text-slate-600 mb-1">Enseignant surveillant</label>
                    <select name="teacher_id" class="w-full rounded-lg border border-slate-300 px-3 py-1.5 text-sm bg-white focus:border-indigo-500">
                        <option value="">Tous les enseignants</option>
                        @foreach($enseignants as $enseignant)
                            <option value="{{ $enseignant->id }}" @selected(request('teacher_id') == $enseignant->id)>
                                {{ $enseignant->nomComplet() }}
                            </option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-bold uppercase tracking-wider text-slate-600 mb-1">Date début</label>
                    <input type="date" name="date_debut" value="{{ request('date_debut') }}" class="w-full rounded-lg border border-slate-300 px-3 py-1.5 text-sm bg-white focus:border-indigo-500" />
                </div>

                <div>
                    <label class="block text-xs font-bold uppercase tracking-wider text-slate-600 mb-1">Date fin</label>
                    <input type="date" name="date_fin" value="{{ request('date_fin') }}" class="w-full rounded-lg border border-slate-300 px-3 py-1.5 text-sm bg-white focus:border-indigo-500" />
                </div>

                <div class="sm:col-span-2 lg:col-span-4 flex items-center justify-between pt-2 border-t border-slate-100">
                    <span class="text-xs text-slate-500 font-medium"><strong>{{ $totalEpreuves }}</strong> épreuve(s) trouvée(s)</span>
                    <div class="flex items-center gap-2">
                        <a href="{{ route('admin.evaluations.planning') }}" class="text-xs font-semibold text-slate-500 hover:text-slate-700 px-3 py-1.5">Réinitialiser</a>
                        <x-btn type="submit">Filtrer</x-btn>
                    </div>
                </div>
            </form>
        </div>

        <!-- Vue Calendrier chronologique -->
        <div class="mt-6 space-y-6">
            @forelse($parDate as $date => $epreuvesDuJour)
                <div class="ds-card overflow-hidden bg-white rounded-xl border border-slate-200">
                    <div class="bg-slate-50/80 px-5 py-3 border-b border-slate-200 flex items-center justify-between">
                        <div class="flex items-center gap-3">
                            <span class="inline-flex items-center justify-center w-8 h-8 rounded-lg bg-indigo-600 text-white font-black text-sm">
                                {{ \Carbon\Carbon::parse($date)->format('d') }}
                            </span>
                            <div>
                                <h3 class="text-sm font-bold text-slate-900 capitalize">
                                    {{ \Carbon\Carbon::parse($date)->locale('fr')->isoFormat('dddd D MMMM YYYY') }}
                                </h3>
                                <p class="text-[11px] text-slate-500">{{ $epreuvesDuJour->count() }} épreuve(s) programmée(s)</p>
                            </div>
                        </div>
                    </div>

                    <div class="divide-y divide-slate-100">
                        @foreach($epreuvesDuJour as $epreuve)
                            <div class="p-4 hover:bg-slate-50/50 transition-colors flex flex-col md:flex-row md:items-center justify-between gap-4">
                                <div class="flex items-start gap-4">
                                    <div class="text-center min-w-[75px] pt-1">
                                        <span class="text-xs font-bold text-indigo-700 bg-indigo-50 px-2.5 py-1 rounded-md tabular-nums block">
                                            {{ substr($epreuve->heure_debut, 0, 5) }}
                                        </span>
                                        <span class="text-[10px] text-slate-500 tabular-nums block mt-1">
                                            à {{ substr($epreuve->heure_fin, 0, 5) }}
                                        </span>
                                    </div>

                                    <div>
                                        <div class="flex items-center gap-2">
                                            <span class="font-black text-slate-900 text-sm">{{ $epreuve->matiere->code }}</span>
                                            <span class="text-slate-400">·</span>
                                            <span class="text-sm text-slate-700 font-semibold">{{ $epreuve->matiere->nom }}</span>
                                            <x-status-badge :statut="$epreuve->statut" />
                                        </div>

                                        <div class="mt-1 flex flex-wrap items-center gap-3 text-xs text-slate-500">
                                            <span class="inline-flex items-center gap-1 font-medium">
                                                <span class="text-slate-400">Session :</span> {{ $epreuve->session?->nom ?? '—' }}
                                            </span>
                                            <span class="inline-flex items-center gap-1 font-medium">
                                                <span class="text-slate-400">Salle :</span>
                                                <strong class="{{ $epreuve->salle ? 'text-slate-800' : 'text-amber-600 font-black' }}">
                                                    {{ $epreuve->salle?->code ?? 'Non assignée' }}
                                                </strong>
                                            </span>
                                            <span class="inline-flex items-center gap-1 font-medium">
                                                <span class="text-slate-400">Groupe :</span> {{ $epreuve->groupe?->code ?? 'Tous' }}
                                            </span>
                                            <span class="inline-flex items-center gap-1 font-medium">
                                                <span class="text-slate-400">Candidats :</span> {{ $epreuve->candidats->count() }}
                                            </span>
                                            <span class="inline-flex items-center gap-1 font-medium">
                                                <span class="text-slate-400">Surveillants :</span>
                                                <span class="{{ $epreuve->surveillants->isEmpty() ? 'text-amber-600 font-bold' : 'text-slate-700' }}">
                                                    {{ $epreuve->surveillants->count() }}
                                                </span>
                                            </span>
                                        </div>
                                    </div>
                                </div>

                                <div class="flex items-center gap-2 self-end md:self-center">
                                    @can('notes_eval.view')
                                        <x-btn variant="ghost" size="sm" href="{{ route('admin.evaluations.notes.saisie', $epreuve) }}">
                                            Notes
                                        </x-btn>
                                    @endcan
                                    @can('epreuves.view')
                                        <x-btn variant="secondary" size="sm" href="{{ route('admin.evaluations.epreuves.emargement', $epreuve) }}">
                                            Émargement
                                        </x-btn>
                                        <x-btn size="sm" href="{{ route('admin.evaluations.epreuves.show', $epreuve) }}">
                                            Détails
                                        </x-btn>
                                    @endcan
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            @empty
                <div class="ds-card p-8">
                    <x-empty-state message="Aucune épreuve ne correspond aux critères sélectionnés." />
                </div>
            @endforelse
        </div>
    </div>
@endsection
