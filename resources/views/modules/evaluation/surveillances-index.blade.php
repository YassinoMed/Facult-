@extends('layouts.app')

@section('title', 'Gestion des Surveillances — DS & Examens')

@section('content')
    <div class="mx-auto max-w-7xl px-4 sm:px-6 py-8">
        <x-page-header titre="Gestion des surveillances — DS & Examens" description="Affectation des surveillants, gestion des gardes de salles, suivi des présences et remplacements."
            :fil="['Évaluations' => route('admin.evaluations.index'), 'Surveillances' => '#']">
            <x-slot:actions>
                <x-btn variant="secondary" href="{{ route('admin.evaluations.surveillances.cockpit') }}">Cockpit & Charges</x-btn>
                <x-btn variant="secondary" href="{{ route('admin.evaluations.surveillances.export', request()->query()) }}">Exporter CSV</x-btn>
                <x-btn variant="secondary" href="{{ route('admin.evaluations.affectations') }}">Affectations enseignants</x-btn>
                <x-btn variant="secondary" href="{{ route('admin.evaluations.planning') }}">Planning Examens</x-btn>
                <x-btn href="{{ route('admin.evaluations.epreuves.index') }}">Épreuves</x-btn>
            </x-slot:actions>
        </x-page-header>

        <!-- KPI Cockpit Rapides (§15) -->
        <div class="mt-6 grid grid-cols-2 sm:grid-cols-4 gap-4">
            <x-stat-card libelle="Total gardes" :valeur="$cockpit['total_gardes'] ?? 0" detail="Gardes actives" couleur="primary" />
            <x-stat-card libelle="Confirmées" :valeur="$cockpit['gardes_confirmees'] ?? 0" detail="Surveillances validées" couleur="emerald" />
            <x-stat-card libelle="Absents / Remplacés" :valeur="($cockpit['surveillants_absents'] ?? 0) + ($cockpit['remplacements'] ?? 0)" detail="Incidents de garde" couleur="amber" />
            <x-stat-card libelle="Sans surveillant" :valeur="$cockpit['epreuves_sans_surveillant'] ?? 0" detail="Épreuves à couvrir" couleur="rose" />
        </div>

        <!-- Filtres Avancés -->
        <div class="mt-6 ds-card p-4 bg-white rounded-xl border border-slate-200">
            <form method="get" action="{{ route('admin.evaluations.surveillances.index') }}" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-4 text-sm">
                <div>
                    <label class="block text-xs font-bold uppercase tracking-wider text-slate-600 mb-1">Session</label>
                    <select name="session_id" class="w-full rounded-lg border border-slate-300 px-3 py-1.5 text-sm bg-white focus:border-indigo-500">
                        <option value="">Toutes les sessions</option>
                        @foreach($sessions as $session)
                            <option value="{{ $session->id }}" @selected(request('session_id') == $session->id)>
                                {{ $session->nom }}
                            </option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-bold uppercase tracking-wider text-slate-600 mb-1">Type d'épreuve</label>
                    <select name="type_evaluation" class="w-full rounded-lg border border-slate-300 px-3 py-1.5 text-sm bg-white focus:border-indigo-500">
                        <option value="">Tous les types (DS + Examens)</option>
                        <option value="examen" @selected(request('type_evaluation') === 'examen')>Examen officiel</option>
                        <option value="ds" @selected(request('type_evaluation') === 'ds')>Devoir Surveillé (DS)</option>
                        <option value="controle_continu" @selected(request('type_evaluation') === 'controle_continu')>Contrôle Continu</option>
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-bold uppercase tracking-wider text-slate-600 mb-1">Enseignant</label>
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
                    <label class="block text-xs font-bold uppercase tracking-wider text-slate-600 mb-1">Statut Garde</label>
                    <select name="statut" class="w-full rounded-lg border border-slate-300 px-3 py-1.5 text-sm bg-white focus:border-indigo-500">
                        <option value="">Tous les statuts</option>
                        <option value="assigne" @selected(request('statut') === 'assigne')>Assigné</option>
                        <option value="confirme" @selected(request('statut') === 'confirme')>Confirmé</option>
                        <option value="remplace" @selected(request('statut') === 'remplace')>Remplacé</option>
                        <option value="annule" @selected(request('statut') === 'annule')>Annulé</option>
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-bold uppercase tracking-wider text-slate-600 mb-1">Présence / Émargement</label>
                    <select name="presence" class="w-full rounded-lg border border-slate-300 px-3 py-1.5 text-sm bg-white focus:border-indigo-500">
                        <option value="">Tous les états</option>
                        <option value="present" @selected(request('presence') === 'present')>Présent</option>
                        <option value="retard" @selected(request('presence') === 'retard')>En retard</option>
                        <option value="absent" @selected(request('presence') === 'absent')>Absent</option>
                        <option value="absent_justifie" @selected(request('presence') === 'absent_justifie')>Absent justifié</option>
                    </select>
                </div>

                <div class="sm:col-span-2 lg:col-span-5 flex justify-end gap-2 pt-2 border-t border-slate-100">
                    <a href="{{ route('admin.evaluations.surveillances.index') }}" class="text-xs font-semibold text-slate-500 px-3 py-1.5">Réinitialiser</a>
                    <x-btn type="submit">Filtrer</x-btn>
                </div>
            </form>
        </div>

        <!-- Table des surveillances -->
        <div class="mt-6 ds-card overflow-hidden bg-white rounded-xl border border-slate-200">
            @if($surveillances->isEmpty())
                <div class="p-8">
                    <x-empty-state message="Aucune surveillance enregistrée correspondant aux critères." />
                </div>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-slate-50 text-left text-[11px] uppercase tracking-wider text-slate-500 border-b border-slate-200">
                            <tr>
                                <th class="px-4 py-3">Surveillant</th>
                                <th class="px-4 py-3">Épreuve / ECUE</th>
                                <th class="px-4 py-3">Type</th>
                                <th class="px-4 py-3">Date & Horaire</th>
                                <th class="px-4 py-3">Salle</th>
                                <th class="px-4 py-3">Rôle</th>
                                <th class="px-4 py-3">Statut</th>
                                <th class="px-4 py-3">Émargement</th>
                                <th class="px-4 py-3 text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach($surveillances as $surveillance)
                                <tr class="hover:bg-slate-50/50">
                                    <td class="px-4 py-3 font-semibold text-slate-900">
                                        {{ $surveillance->libelleSurveillant() }}
                                        @if($surveillance->remplace_surveillance_id)
                                            <span class="block text-[11px] font-normal text-amber-600">
                                                ↳ Remplacement (#{{ $surveillance->remplace_surveillance_id }})
                                            </span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-3 font-medium text-slate-800">
                                        <a href="{{ route('admin.evaluations.epreuves.show', $surveillance->epreuve) }}" class="text-indigo-600 hover:underline">
                                            {{ $surveillance->epreuve->matiere->code }} — {{ $surveillance->epreuve->matiere->nom }}
                                        </a>
                                    </td>
                                    <td class="px-4 py-3">
                                        <span class="inline-flex items-center px-2 py-0.5 rounded text-[11px] font-bold uppercase tracking-wider {{ $surveillance->epreuve->type_evaluation === 'ds' ? 'bg-amber-100 text-amber-800' : 'bg-indigo-100 text-indigo-800' }}">
                                            {{ $surveillance->epreuve->type_evaluation ?? 'Examen' }}
                                        </span>
                                    </td>
                                    <td class="px-4 py-3 text-xs text-slate-600 tabular-nums">
                                        {{ $surveillance->epreuve->date->format('d/m/Y') }}
                                        <span class="block text-slate-400">
                                            {{ substr((string) $surveillance->epreuve->heure_debut, 0, 5) }} → {{ substr((string) $surveillance->epreuve->heure_fin, 0, 5) }}
                                        </span>
                                    </td>
                                    <td class="px-4 py-3 font-bold text-slate-700">
                                        {{ $surveillance->salle?->code ?? $surveillance->epreuve->salle?->code ?? '—' }}
                                    </td>
                                    <td class="px-4 py-3 text-xs font-semibold capitalize text-slate-600">
                                        {{ $surveillance->role }}
                                    </td>
                                    <td class="px-4 py-3">
                                        <x-status-badge :statut="$surveillance->statut" />
                                    </td>
                                    <td class="px-4 py-3 text-xs">
                                        @if($surveillance->presence === 'present')
                                            <span class="inline-flex items-center gap-1 font-bold text-emerald-700 bg-emerald-50 px-2 py-0.5 rounded">
                                                ✓ Présent
                                                @if($surveillance->heure_arrivee)
                                                    <span class="font-normal text-[10px]">({{ substr($surveillance->heure_arrivee, 0, 5) }})</span>
                                                @endif
                                            </span>
                                        @elseif($surveillance->presence === 'retard')
                                            <span class="inline-flex items-center gap-1 font-bold text-amber-700 bg-amber-50 px-2 py-0.5 rounded">
                                                ⏱ Retard
                                            </span>
                                        @elseif(in_array($surveillance->presence, ['absent', 'absent_justifie'], true))
                                            <span class="inline-flex items-center gap-1 font-bold text-rose-700 bg-rose-50 px-2 py-0.5 rounded">
                                                ✗ {{ $surveillance->presence === 'absent_justifie' ? 'Excusé' : 'Absent' }}
                                            </span>
                                        @else
                                            <span class="text-slate-400 text-xs italic">En attente</span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-3 text-right whitespace-nowrap space-x-1.5">
                                        @can('surveillances.assign')
                                            @if($surveillance->statut === 'assigne')
                                                <form method="post" action="{{ route('admin.evaluations.surveillants.confirmer', $surveillance) }}" class="inline">
                                                    @csrf
                                                    <button type="submit" class="text-xs font-bold text-emerald-600 hover:text-emerald-800">
                                                        Confirmer
                                                    </button>
                                                </form>
                                            @endif

                                            <!-- Menu déroulant ou actions rapides d'émargement -->
                                            <form method="post" action="{{ route('admin.evaluations.surveillants.emargement', $surveillance) }}" class="inline">
                                                @csrf
                                                <input type="hidden" name="presence" value="retard">
                                                <button type="submit" class="text-xs font-semibold text-amber-600 hover:text-amber-800" title="Pointer en retard">
                                                    Retard
                                                </button>
                                            </form>

                                            <form method="post" action="{{ route('admin.evaluations.surveillants.emargement', $surveillance) }}" class="inline">
                                                @csrf
                                                <input type="hidden" name="presence" value="absent">
                                                <button type="submit" class="text-xs font-semibold text-rose-600 hover:text-rose-800" title="Pointer absent">
                                                    Absent
                                                </button>
                                            </form>
                                        @endcan

                                        @can('surveillances.manage')
                                            @unless($surveillance->statut === 'annule' || $surveillance->epreuve->estVerrouillee())
                                                <form method="post" action="{{ route('admin.evaluations.surveillants.desaffecter', $surveillance) }}" class="inline" onsubmit="return confirm('Confirmer la désaffectation de ce surveillant ?');">
                                                    @csrf
                                                    <button type="submit" class="text-xs font-semibold text-slate-400 hover:text-rose-600" title="Désaffecter">
                                                        Désaffecter
                                                    </button>
                                                </form>
                                            @endunless
                                        @endcan
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="px-4 py-3 border-t border-slate-100">{{ $surveillances->links() }}</div>
            @endif
        </div>
    </div>
@endsection
