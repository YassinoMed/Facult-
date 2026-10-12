@extends('layouts.app')

@section('title', 'Mes surveillances d\'examen — Espace Enseignant')

@section('content')
    <div class="mx-auto max-w-7xl px-4 sm:px-6 py-6">
        <x-page-header titre="Mes surveillances d'examen" description="Consultez vos convocations officielles et vos affectations aux épreuves d'examen."
            :fil="['Espace Enseignant' => route('enseignant.dashboard'), 'Mes surveillances' => '#']">
            <x-slot:actions>
                <x-btn variant="secondary" onclick="window.print()">Imprimer mes convocations</x-btn>
            </x-slot:actions>
        </x-page-header>

        {{-- Statistiques réelles des surveillances --}}
        <div class="mt-6 grid grid-cols-2 sm:grid-cols-4 gap-3">
            <x-stat-card libelle="Total affectations" :valeur="$totalAffectations" detail="Convocations enregistrées" couleur="slate" />
            <x-stat-card libelle="Surveillances actives" :valeur="$actives" detail="En salle (titulaire/adjoint)" couleur="primary" />
            <x-stat-card libelle="Gardes de réserve" :valeur="$gardesReserve" detail="En astreinte / disponibilité" couleur="{{ $gardesReserve > 0 ? 'amber' : 'slate' }}" />
            <x-stat-card libelle="Épreuves à venir" :valeur="$aVenir" detail="Sessions futures" couleur="{{ $aVenir > 0 ? 'emerald' : 'slate' }}" />
        </div>

        {{-- Filtres --}}
        <div class="mt-6 flex flex-wrap items-center justify-between gap-3 p-3 rounded-2xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 shadow-sm">
            <div class="flex flex-wrap items-center gap-1.5">
                <a href="{{ route('enseignant.surveillances.index', ['periode' => 'toutes', 'role' => $filtreRole]) }}" 
                   class="px-3 py-1.5 rounded-xl text-xs font-bold transition {{ $periode === 'toutes' ? 'bg-primary-800 text-white shadow-sm' : 'text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800' }}">
                    Toutes
                </a>
                <a href="{{ route('enseignant.surveillances.index', ['periode' => 'a_venir', 'role' => $filtreRole]) }}" 
                   class="px-3 py-1.5 rounded-xl text-xs font-bold transition {{ $periode === 'a_venir' ? 'bg-primary-800 text-white shadow-sm' : 'text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800' }}">
                    À venir
                </a>
                <a href="{{ route('enseignant.surveillances.index', ['periode' => 'passees', 'role' => $filtreRole]) }}" 
                   class="px-3 py-1.5 rounded-xl text-xs font-bold transition {{ $periode === 'passees' ? 'bg-primary-800 text-white shadow-sm' : 'text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800' }}">
                    Historique / Passées
                </a>
            </div>

            <a href="{{ route('enseignant.reclamations.create', ['categorie' => 'surveillance', 'objet' => 'Indisponibilité ou conflit de surveillance d\'examen']) }}"
               class="text-xs font-bold text-rose-600 dark:text-rose-400 hover:underline flex items-center gap-1">
                <span>⚠️ Signaler une indisponibilité ou un conflit</span>
            </a>
        </div>

        {{-- Liste des surveillances --}}
        <div class="mt-6 rounded-2xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 shadow-sm overflow-hidden">
            <div class="px-5 py-3.5 border-b border-slate-100 dark:border-slate-800 flex items-center justify-between">
                <h3 class="text-sm font-black text-slate-900 dark:text-slate-100">Liste des épreuves assignées</h3>
                <span class="text-xs text-slate-500">{{ $surveillances->total() }} affectation(s)</span>
            </div>

            @if($surveillances->isNotEmpty())
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-xs">
                        <thead class="bg-slate-50 dark:bg-slate-800/50 text-slate-400 uppercase font-black tracking-wider text-[10px]">
                            <tr>
                                <th class="px-5 py-3">Date & Horaires</th>
                                <th class="px-5 py-3">Épreuve & Session</th>
                                <th class="px-5 py-3">Salle assignée</th>
                                <th class="px-5 py-3">Rôle attribué</th>
                                <th class="px-5 py-3">Statut</th>
                                <th class="px-5 py-3 text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 dark:divide-slate-800 text-slate-700 dark:text-slate-300">
                            @foreach($surveillances as $s)
                                @php
                                    $estFuture = $s->epreuve && $s->epreuve->date?->greaterThanOrEqualTo(\Carbon\Carbon::today());
                                @endphp
                                <tr class="hover:bg-slate-50/50 dark:hover:bg-slate-800/30 transition {{ $estFuture ? 'bg-primary-50/20' : '' }}">
                                    <td class="px-5 py-3.5 whitespace-nowrap">
                                        <p class="font-bold text-slate-900 dark:text-slate-100">{{ $s->epreuve?->date?->format('d/m/Y') }}</p>
                                        <p class="text-[11px] text-slate-400 font-mono">{{ substr((string) ($s->epreuve?->heure_debut ?? ''), 0, 5) }} → {{ substr((string) ($s->epreuve?->heure_fin ?? ''), 0, 5) }}</p>
                                    </td>
                                    <td class="px-5 py-3.5">
                                        <p class="font-bold text-slate-800 dark:text-slate-100">{{ $s->epreuve?->code }} · {{ $s->epreuve?->matiere?->nom ?? 'Épreuve' }}</p>
                                        <p class="text-[11px] text-slate-500">Session : {{ $s->epreuve?->session?->nom ?? 'Session officielle' }}</p>
                                    </td>
                                    <td class="px-5 py-3.5 whitespace-nowrap font-semibold">
                                        {{ $s->salle?->code ?? ($s->epreuve?->salle?->code ?? 'Poste de réserve') }}
                                    </td>
                                    <td class="px-5 py-3.5 whitespace-nowrap">
                                        @if($s->role === 'reserve')
                                            <div class="inline-flex flex-col">
                                                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-black bg-amber-100 text-amber-900 dark:bg-amber-950 dark:text-amber-300 border border-amber-300 dark:border-amber-700">
                                                    Garde / Réserve
                                                </span>
                                                <span class="text-[10px] text-amber-700 dark:text-amber-400 mt-0.5">Astreinte en cas de désistement</span>
                                            </div>
                                        @else
                                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-bold bg-primary-100 text-primary-800 dark:bg-primary-950 dark:text-primary-300">
                                                {{ ucfirst($s->role) }}
                                            </span>
                                        @endif
                                    </td>
                                    <td class="px-5 py-3.5 whitespace-nowrap">
                                        <x-status-badge :statut="$s->statut" />
                                    </td>
                                    <td class="px-5 py-3.5 text-right whitespace-nowrap">
                                        <a href="{{ route('enseignant.reclamations.create', [
                                                'categorie' => 'surveillance',
                                                'objet' => 'Contestation surveillance épreuve ' . ($s->epreuve?->code ?? '') . ' du ' . ($s->epreuve?->date?->format('d/m/Y') ?? ''),
                                            ]) }}"
                                           class="text-[11px] font-bold text-primary-700 dark:text-primary-300 hover:underline">
                                            Signaler empêchement
                                        </a>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="p-4 border-t border-slate-100 dark:border-slate-800">
                    {{ $surveillances->links() }}
                </div>
            @else
                <div class="p-8 text-center">
                    <x-empty-state message="Aucune surveillance d'examen trouvée pour cette période." />
                </div>
            @endif
        </div>
    </div>
@endsection
