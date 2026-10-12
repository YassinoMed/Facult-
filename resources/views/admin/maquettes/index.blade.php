@extends('layouts.app')

@section('title', 'Maquettes documentaires — Scolarité')

@section('content')
    <div class="mx-auto max-w-6xl px-4 sm:px-6 py-8">
        <x-page-header titre="Maquettes documentaires" description="Modèles versionnés : toute modification crée une version immuable, jamais d'écrasement."
            :fil="['Documents académiques' => route('admin.documents-center.index'), 'Maquettes' => '#']">
            <x-slot:actions>
            @can('document_template.edit')
                <x-btn href="{{ route('admin.maquettes.create') }}">Nouvelle maquette</x-btn>
            @endcan
            </x-slot:actions>
        </x-page-header>

        <form method="GET" action="{{ route('admin.maquettes.index') }}" class="mt-4 flex flex-col sm:flex-row gap-2" role="search">
            <x-input id="recherche-maquette" label="Recherche" name="q" :value="$search" placeholder="Nom ou type…" />
            <x-select id="filtre-statut" label="Statut" name="statut" :options="['brouillon' => 'Brouillon', 'actif' => 'Publiée', 'archive' => 'Archivée']" :valeur="request('statut')" vide="Tous" />
            <div class="flex items-end">
                <x-btn type="submit" variant="secondary">Filtrer</x-btn>
            </div>
        </form>

        <div class="mt-4 overflow-x-auto rounded-2xl border border-slate-200 bg-white shadow-sm">
            @if($maquettes->isNotEmpty())
                <table class="w-full min-w-[720px] text-left text-xs sm:text-sm">
                    <thead>
                        <tr class="border-b border-slate-100 text-[11px] uppercase tracking-wider text-slate-400">
                            <th scope="col" class="px-4 py-3 font-bold">Nom</th>
                            <th scope="col" class="px-4 py-3 font-bold">Type</th>
                            <th scope="col" class="px-4 py-3 font-bold">Statut</th>
                            <th scope="col" class="px-4 py-3 font-bold">Version</th>
                            <th scope="col" class="px-4 py-3 font-bold text-right">Action</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach($maquettes as $maquette)
                            <tr class="hover:bg-slate-50/60">
                                <td class="px-4 py-3 font-bold text-slate-900">{{ $maquette->nom }}</td>
                                <td class="px-4 py-3 font-mono text-[11px]">{{ $maquette->type }}</td>
                                <td class="px-4 py-3"><x-status-badge :statut="$maquette->statut" /></td>
                                <td class="px-4 py-3 tabular-nums">v{{ $maquette->version_courante_id ? $maquette->versions->firstWhere('id', $maquette->version_courante_id)?->version : '—' }}</td>
                                <td class="px-4 py-3 text-right">
                                    <a class="underline" href="{{ route('admin.maquettes.show', $maquette) }}">Ouvrir</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                <div class="px-5 py-3 border-t border-slate-100">{{ $maquettes->links() }}</div>
            @else
                <div class="p-5"><x-empty-state message="Aucune maquette : importez un modèle ou créez-le depuis l'éditeur." /></div>
            @endif
        </div>
    </div>
@endsection
