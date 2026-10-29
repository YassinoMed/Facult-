@extends('layouts.app')

@section('title', 'Mes surveillances — Espace Enseignant')

@section('content')
    <div class="mx-auto max-w-6xl px-4 sm:px-6 py-8">
        <x-page-header titre="Mes surveillances" description="Vos affectations : principal, adjoint, surveillant ou garde / réserve."
            :fil="['Tableau de bord' => route('enseignant.dashboard'), 'Surveillances' => '#']" />

        <section aria-label="Liste de mes surveillances" class="mt-4 overflow-x-auto rounded-2xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 shadow-sm">
            @if($gardes->isNotEmpty())
                <table class="w-full min-w-[640px] text-left text-xs sm:text-sm">
                    <thead>
                        <tr class="border-b border-slate-100 dark:border-slate-800 text-[11px] uppercase tracking-wider text-slate-700 dark:text-slate-300 font-bold">
                            <th scope="col" class="px-5 py-3 font-bold">Épreuve</th>
                            <th scope="col" class="px-5 py-3 font-bold">Date · Horaire</th>
                            <th scope="col" class="px-5 py-3 font-bold">Salle</th>
                            <th scope="col" class="px-5 py-3 font-bold">Mon rôle</th>
                            <th scope="col" class="px-5 py-3 font-bold"><span class="sr-only">Détail</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                        @foreach($gardes as $garde)
                            <tr class="hover:bg-slate-50/60 dark:hover:bg-slate-800/30">
                                <td class="px-5 py-3 font-bold text-slate-800 dark:text-slate-100">
                                    {{ $garde->epreuve->code }}
                                    <span class="block text-xs font-normal text-slate-500">{{ $garde->epreuve->matiere?->nom }} · {{ $garde->epreuve->session?->nom }}</span>
                                </td>
                                <td class="px-5 py-3 tabular-nums text-slate-700 dark:text-slate-300">{{ $garde->epreuve->date->format('d/m/Y') }} · {{ substr((string) $garde->epreuve->heure_debut, 0, 5) }}</td>
                                <td class="px-5 py-3 font-mono text-slate-700 dark:text-slate-300">{{ $garde->salle?->code ?? $garde->epreuve->salle?->code ?? '—' }}</td>
                                <td class="px-5 py-3"><x-status-badge :statut="$garde->role" /></td>
                                <td class="px-5 py-3 text-right">
                                    <a href="{{ route('enseignant.epreuves.show', $garde->epreuve) }}" class="font-bold text-indigo-700 dark:text-indigo-300 underline underline-offset-2">Détail</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                <div class="px-5 py-3 border-t border-slate-100 dark:border-slate-800">{{ $gardes->links() }}</div>
            @else
                <div class="p-5"><x-empty-state message="Aucune surveillance affectée." /></div>
            @endif
        </section>

        <p class="mt-3 text-xs text-slate-500 dark:text-slate-400">Le rôle « Garde / Réserve » signifie une astreinte de remplacement, pas une surveillance active en salle.</p>
    </div>
@endsection
