@extends('layouts.app')

@section('title', 'Maquettes — Référentiel LMD')

@section('content')
    <div class="mx-auto max-w-6xl px-4 sm:px-6 py-8">
        <x-page-header titre="Plans d'études / Maquettes" description="Versions par programme et année, statuts contrôlés."
            :fil="['Référentiel' => route('admin.referentiel.tableau-bord'), 'Maquettes' => '#']">
            <x-slot:actions>
                @can('referentiel.manage')
                    <x-btn href="{{ route('admin.referentiel.maquettes.create') }}">Nouvelle maquette</x-btn>
                @endcan
            </x-slot:actions>
        </x-page-header>

        <form method="GET" action="{{ route('admin.referentiel.maquettes.index') }}" class="mt-4 flex flex-col sm:flex-row gap-2">
            <select name="programme_id" aria-label="Filtrer par programme" class="rounded-xl border border-slate-300 px-3 py-2.5 text-sm min-h-[44px]">
                <option value="">Tous programmes</option>
                @foreach($programmes as $programme)
                    <option value="{{ $programme['id'] }}" @selected(request('programme_id') == $programme['id'])>{{ $programme['label'] }}</option>
                @endforeach
            </select>
            <select name="statut" aria-label="Filtrer par statut" class="rounded-xl border border-slate-300 px-3 py-2.5 text-sm min-h-[44px]">
                <option value="">Tous statuts</option>
                @foreach(['draft' => 'Brouillon', 'review' => 'En revue', 'approved' => 'Approuvée', 'active' => 'Active', 'archived' => 'Archivée'] as $valeur => $libelle)
                    <option value="{{ $valeur }}" @selected(request('statut') === $valeur)>{{ $libelle }}</option>
                @endforeach
            </select>
            <x-btn type="submit" variant="secondary">Filtrer</x-btn>
        </form>

        <section aria-label="Liste des maquettes" class="mt-4 overflow-x-auto rounded-2xl border border-slate-200 bg-white shadow-sm">
            @if($maquettes->isNotEmpty())
                <table class="w-full min-w-[680px] text-left text-xs sm:text-sm">
                    <thead>
                        <tr class="border-b border-slate-100 text-[11px] uppercase tracking-wider text-slate-400">
                            <th scope="col" class="px-5 py-3 font-bold">Programme</th>
                            <th scope="col" class="px-5 py-3 font-bold">Année</th>
                            <th scope="col" class="px-5 py-3 font-bold">Version</th>
                            <th scope="col" class="px-5 py-3 font-bold">Statut</th>
                            <th scope="col" class="px-5 py-3 font-bold"><span class="sr-only">Ouvrir</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach($maquettes as $maquette)
                            <tr class="hover:bg-slate-50/60">
                                <td class="px-5 py-3 font-bold text-slate-900">{{ $maquette->programme->code }}</td>
                                <td class="px-5 py-3 tabular-nums text-slate-600">{{ $maquette->anneeUniversitaire->libelle }}</td>
                                <td class="px-5 py-3 tabular-nums text-slate-700">v{{ $maquette->version }}</td>
                                <td class="px-5 py-3 text-xs font-black uppercase">{{ $maquette->statut }}</td>
                                <td class="px-5 py-3 text-right">
                                    <a href="{{ route('admin.referentiel.maquettes.show', $maquette) }}" class="font-bold text-primary-800 underline underline-offset-2">Ouvrir</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                <div class="px-5 py-3 border-t border-slate-100">{{ $maquettes->links() }}</div>
            @else
                <div class="p-5"><x-empty-state message="Aucune maquette." /></div>
            @endif
        </section>
    </div>
@endsection
