@extends('layouts.app')

@section('title', 'Mes supports de cours — Espace Enseignant')

@section('content')
    <div class="mx-auto max-w-6xl px-4 sm:px-6 py-8">
        <x-page-header titre="Mes supports de cours" description="Publiez des supports pour vos ECUE affectés — cycle brouillon → publié → archivé."
            :fil="['Tableau de bord' => route('enseignant.dashboard'), 'Supports' => '#']">
            <x-slot:actions>
                <x-btn href="{{ route('enseignant.ressources.create') }}">Nouveau support</x-btn>
            </x-slot:actions>
        </x-page-header>

        @if(session('success'))
            <div class="mt-4"><x-alert type="success" :message="session('success')" /></div>
        @endif

        <section aria-label="Liste de mes supports" class="mt-4 overflow-x-auto rounded-2xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 shadow-sm">
            @if($ressources->isNotEmpty())
                <table class="w-full min-w-[720px] text-left text-xs sm:text-sm">
                    <thead>
                        <tr class="border-b border-slate-100 dark:border-slate-800 text-[11px] uppercase tracking-wider text-slate-400">
                            <th scope="col" class="px-5 py-3 font-bold">Titre</th>
                            <th scope="col" class="px-5 py-3 font-bold">Matière · Groupe</th>
                            <th scope="col" class="px-5 py-3 font-bold">Fichier</th>
                            <th scope="col" class="px-5 py-3 font-bold">Statut</th>
                            <th scope="col" class="px-5 py-3 font-bold"><span class="sr-only">Détail</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                        @foreach($ressources as $ressource)
                            <tr class="hover:bg-slate-50/60 dark:hover:bg-slate-800/30">
                                <td class="px-5 py-3 font-bold text-slate-800 dark:text-slate-100">{{ $ressource->titre }}</td>
                                <td class="px-5 py-3 text-slate-600 dark:text-slate-300">{{ $ressource->matiere?->code }} · {{ $ressource->groupe?->code ?? 'Tous groupes' }}</td>
                                <td class="px-5 py-3 font-mono text-xs text-slate-600 dark:text-slate-300">{{ $ressource->nom_fichier }}</td>
                                <td class="px-5 py-3"><x-status-badge :statut="$ressource->statut" /></td>
                                <td class="px-5 py-3 text-right">
                                    <a href="{{ route('enseignant.ressources.show', $ressource) }}" class="font-bold text-indigo-700 dark:text-indigo-300 underline underline-offset-2">Détail</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                <div class="px-5 py-3 border-t border-slate-100 dark:border-slate-800">{{ $ressources->links() }}</div>
            @else
                <div class="p-5"><x-empty-state message="Aucun support publié pour le moment." /></div>
            @endif
        </section>
    </div>
@endsection
