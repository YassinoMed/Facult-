@extends('layouts.app')

@section('title', 'Mes notes à saisir — Espace Enseignant')

@section('content')
    <div class="mx-auto max-w-6xl px-4 sm:px-6 py-8">
        <x-page-header titre="Mes notes à saisir" description="Épreuves de vos ECUE affectés — saisie strictement anonyme par numéros de compostage."
            :fil="['Tableau de bord' => route('enseignant.dashboard'), 'Notes' => '#']" />

        <section aria-label="Liste de mes épreuves à saisir" class="mt-4 overflow-x-auto rounded-2xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 shadow-sm">
            @if($epreuves->isNotEmpty())
                <table class="w-full min-w-[720px] text-left text-xs sm:text-sm">
                    <thead>
                        <tr class="border-b border-slate-100 dark:border-slate-800 text-[11px] uppercase tracking-wider text-slate-400">
                            <th scope="col" class="px-5 py-3 font-bold">Épreuve</th>
                            <th scope="col" class="px-5 py-3 font-bold">Date · Horaire</th>
                            <th scope="col" class="px-5 py-3 font-bold">Candidats</th>
                            <th scope="col" class="px-5 py-3 font-bold">Saisies</th>
                            <th scope="col" class="px-5 py-3 font-bold">Statut</th>
                            <th scope="col" class="px-5 py-3 font-bold"><span class="sr-only">Saisir</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                        @foreach($epreuves as $epreuve)
                            <tr class="hover:bg-slate-50/60 dark:hover:bg-slate-800/30">
                                <td class="px-5 py-3 font-bold text-slate-800 dark:text-slate-100">
                                    {{ $epreuve->code }}
                                    <span class="block text-xs font-normal text-slate-500">{{ $epreuve->matiere?->nom }} · {{ $epreuve->session?->nom }} · Barème {{ $epreuve->note_max }}</span>
                                </td>
                                <td class="px-5 py-3 tabular-nums text-slate-700 dark:text-slate-300">{{ $epreuve->date->format('d/m/Y') }} · {{ substr((string) $epreuve->heure_debut, 0, 5) }}</td>
                                <td class="px-5 py-3 tabular-nums text-slate-700 dark:text-slate-300">{{ $epreuve->candidats_count }}</td>
                                <td class="px-5 py-3 tabular-nums text-slate-700 dark:text-slate-300">{{ $epreuve->notes_count }}</td>
                                <td class="px-5 py-3"><x-status-badge :statut="$epreuve->statut" /></td>
                                <td class="px-5 py-3 text-right">
                                    <a href="{{ route('enseignant.notes.saisie', $epreuve) }}" class="font-bold text-indigo-700 dark:text-indigo-300 underline underline-offset-2">Saisir</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                <div class="px-5 py-3 border-t border-slate-100 dark:border-slate-800">{{ $epreuves->links() }}</div>
            @else
                <div class="p-5"><x-empty-state message="Aucune épreuve à saisir : vos ECUE affectés n'ont pas d'épreuve ouverte." /></div>
            @endif
        </section>

        <p class="mt-3 text-xs text-slate-500 dark:text-slate-400">La saisie est anonyme : seuls les numéros de compostage (CMP-XXXXXX) sont affichés, jamais les identités. La validation et la publication relèvent de la scolarité.</p>
    </div>
@endsection
