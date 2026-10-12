@extends('layouts.app')

@section('title', 'Enseignements — Scolarité')

@section('content')
    <div class="mx-auto max-w-6xl px-4 sm:px-6 py-8">
        <x-page-header titre="Enseignements" description="Filtres combinables : ECUE, type, programme, année, statut."
            :fil="['Enseignements' => route('admin.enseignements.tableau-bord'), 'Liste' => '#']">
            <x-slot:actions>
                @can('enseignements.manage')
                    <x-btn href="{{ route('admin.enseignements.create') }}">Nouvel enseignement</x-btn>
                @endcan
            </x-slot:actions>
        </x-page-header>

        <form method="GET" action="{{ route('admin.enseignements.index') }}" role="search" class="mt-4 ds-card p-4 sm:p-5">
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
                <input type="search" name="search" value="{{ request('search') }}" placeholder="Code ou nom ECUE…" aria-label="Rechercher" class="rounded-xl border border-slate-300 px-4 py-2.5 text-sm min-h-[44px]">
                <select name="matiere_id" aria-label="ECUE" class="rounded-xl border border-slate-300 px-3 py-2.5 text-sm min-h-[44px]">
                    <option value="">Toutes ECUE</option>
                    @foreach($matieres as $matiere)
                        <option value="{{ $matiere->id }}" @selected(request('matiere_id') == $matiere->id)>{{ $matiere->code }}</option>
                    @endforeach
                </select>
                <select name="type_id" aria-label="Type" class="rounded-xl border border-slate-300 px-3 py-2.5 text-sm min-h-[44px]">
                    <option value="">Tous types</option>
                    @foreach($types as $type)
                        <option value="{{ $type->id }}" @selected(request('type_id') == $type->id)>{{ $type->code }}</option>
                    @endforeach
                </select>
                <select name="statut" aria-label="Statut" class="rounded-xl border border-slate-300 px-3 py-2.5 text-sm min-h-[44px]">
                    <option value="">Tous statuts</option>
                    @foreach(['draft' => 'Brouillon', 'planned' => 'Planifié', 'validated' => 'Validé', 'active' => 'Actif', 'completed' => 'Terminé', 'cancelled' => 'Annulé', 'archived' => 'Archivé'] as $v => $l)
                        <option value="{{ $v }}" @selected(request('statut') === $v)>{{ $l }}</option>
                    @endforeach
                </select>
            </div>
            <div class="mt-3 flex flex-wrap gap-2">
                <x-btn type="submit">Rechercher</x-btn>
                @if(request()->hasAny(['search', 'matiere_id', 'type_id', 'statut']))
                    <a href="{{ route('admin.enseignements.index') }}" class="inline-flex items-center text-xs font-bold text-slate-500 underline underline-offset-2 min-h-[44px]">Réinitialiser</a>
                @endif
            </div>
        </form>

        <section aria-label="Liste des enseignements" class="mt-4 overflow-x-auto rounded-2xl border border-slate-200 bg-white shadow-sm">
            @if($enseignements->isNotEmpty())
                <table class="w-full min-w-[720px] text-left text-xs sm:text-sm">
                    <thead>
                        <tr class="border-b border-slate-100 text-[11px] uppercase tracking-wider text-slate-400">
                            <th scope="col" class="px-5 py-3 font-bold">ECUE</th>
                            <th scope="col" class="px-5 py-3 font-bold">Type</th>
                            <th scope="col" class="px-5 py-3 font-bold">Année</th>
                            <th scope="col" class="px-5 py-3 font-bold">Volume</th>
                            <th scope="col" class="px-5 py-3 font-bold">Enseignants</th>
                            <th scope="col" class="px-5 py-3 font-bold">Statut</th>
                            <th scope="col" class="px-5 py-3 font-bold"><span class="sr-only">Ouvrir</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach($enseignements as $ens)
                            <tr class="hover:bg-slate-50/60">
                                <td class="px-5 py-3"><strong class="font-mono">{{ $ens->matiere->code }}</strong> {{ $ens->matiere->nom }}</td>
                                <td class="px-5 py-3 text-slate-600">{{ $ens->type->code }}</td>
                                <td class="px-5 py-3 tabular-nums text-slate-600">{{ $ens->anneeUniversitaire->libelle }}</td>
                                <td class="px-5 py-3 tabular-nums text-slate-700">{{ $ens->volumeTotal() }}h</td>
                                <td class="px-5 py-3 text-slate-600">{{ $ens->affectations->where('statut', 'active')->count() }}</td>
                                <td class="px-5 py-3 text-xs font-black uppercase">{{ $ens->statut }}</td>
                                <td class="px-5 py-3 text-right"><a href="{{ route('admin.enseignements.show', $ens) }}" class="font-bold text-primary-800 underline underline-offset-2 min-h-[32px] inline-flex items-center">Ouvrir</a></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                <div class="px-5 py-3 border-t border-slate-100">{{ $enseignements->links() }}</div>
            @else
                <div class="p-5"><x-empty-state message="Aucun enseignement." /></div>
            @endif
        </section>
    </div>
@endsection
