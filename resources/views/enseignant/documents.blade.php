@extends('layouts.app')

@section('title', 'Mes documents — Espace Enseignant')

@section('content')
    <div class="mx-auto max-w-6xl px-4 sm:px-6 py-8">
        <x-page-header titre="Mes documents" description="Documents générés depuis vos données : listes d'émargement, relevés."
            :fil="['Tableau de bord' => route('enseignant.dashboard'), 'Documents' => '#']">
            <x-slot:actions>
                <x-btn variant="secondary" href="{{ route('enseignant.surveillances.export') }}">Relevé surveillances (CSV)</x-btn>
                <x-btn variant="secondary" href="{{ route('enseignant.presences.export') }}">Relevé présences (CSV)</x-btn>
            </x-slot:actions>
        </x-page-header>

        <section aria-label="Listes d'émargement de mes épreuves" class="mt-4 overflow-x-auto rounded-2xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 shadow-sm">
            @if($epreuves->isNotEmpty())
                <table class="w-full min-w-[560px] text-left text-xs sm:text-sm">
                    <thead>
                        <tr class="border-b border-slate-100 dark:border-slate-800 text-[11px] uppercase tracking-wider text-slate-400">
                            <th scope="col" class="px-5 py-3 font-bold">Épreuve</th>
                            <th scope="col" class="px-5 py-3 font-bold">Date</th>
                            <th scope="col" class="px-5 py-3 font-bold"><span class="sr-only">Télécharger</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                        @foreach($epreuves as $epreuve)
                            <tr class="hover:bg-slate-50/60 dark:hover:bg-slate-800/30">
                                <td class="px-5 py-3 font-bold text-slate-800 dark:text-slate-100">
                                    {{ $epreuve->code }}
                                    <span class="block text-xs font-normal text-slate-500">{{ $epreuve->matiere?->nom }} · {{ $epreuve->session?->nom }}</span>
                                </td>
                                <td class="px-5 py-3 tabular-nums text-slate-700 dark:text-slate-300">{{ $epreuve->date->format('d/m/Y') }}</td>
                                <td class="px-5 py-3 text-right">
                                    <a href="{{ route('enseignant.epreuves.emargement', $epreuve) }}" target="_blank" rel="noopener" class="font-bold text-indigo-700 dark:text-indigo-300 underline underline-offset-2">Émargement (PDF)</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                <div class="px-5 py-3 border-t border-slate-100 dark:border-slate-800">{{ $epreuves->links() }}</div>
            @else
                <div class="p-5"><x-empty-state message="Aucune épreuve surveillée." /></div>
            @endif
        </section>
    </div>
@endsection
