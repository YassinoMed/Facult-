@extends('layouts.app')

@section('title', 'Supports de cours — Espace Étudiant')

@section('content')
    <div class="mx-auto max-w-6xl px-4 sm:px-6 py-8">
        <x-page-header titre="Supports de cours" description="Documents publiés par vos enseignants pour vos matières et votre groupe."
            :fil="['Tableau de bord' => route('etudiant.dashboard'), 'Supports' => '#']" />

        <section aria-label="Liste des supports publiés" class="mt-4 overflow-x-auto rounded-2xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 shadow-sm">
            @if($ressources->isNotEmpty())
                <table class="w-full min-w-[640px] text-left text-xs sm:text-sm">
                    <thead>
                        <tr class="border-b border-slate-100 dark:border-slate-800 text-[11px] uppercase tracking-wider text-slate-400">
                            <th scope="col" class="px-5 py-3 font-bold">Titre</th>
                            <th scope="col" class="px-5 py-3 font-bold">Matière · Groupe</th>
                            <th scope="col" class="px-5 py-3 font-bold">Enseignant</th>
                            <th scope="col" class="px-5 py-3 font-bold"><span class="sr-only">Télécharger</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                        @foreach($ressources as $ressource)
                            <tr class="hover:bg-slate-50/60 dark:hover:bg-slate-800/30">
                                <td class="px-5 py-3 font-bold text-slate-800 dark:text-slate-100">
                                    {{ $ressource->titre }}
                                    <span class="block text-xs font-normal text-slate-500">{{ $ressource->nom_fichier }}</span>
                                </td>
                                <td class="px-5 py-3 text-slate-600 dark:text-slate-300">{{ $ressource->matiere?->code }} · {{ $ressource->groupe?->code ?? 'Tous groupes' }}</td>
                                <td class="px-5 py-3 text-slate-600 dark:text-slate-300">{{ $ressource->enseignant?->nomComplet() }}</td>
                                <td class="px-5 py-3 text-right">
                                    <a href="{{ route('etudiant.ressources.telecharger', $ressource) }}" class="font-bold text-indigo-700 dark:text-indigo-300 underline underline-offset-2">Télécharger</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                <div class="px-5 py-3 border-t border-slate-100 dark:border-slate-800">{{ $ressources->links() }}</div>
            @else
                <div class="p-5"><x-empty-state message="Aucun support publié pour vos matières pour le moment." /></div>
            @endif
        </section>
    </div>
@endsection
