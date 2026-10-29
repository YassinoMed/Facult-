@extends('layouts.app')

@section('title', 'Emplois du temps — Scolarité')

@section('content')
    <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 py-8 sm:py-10">
        <x-page-header titre="Emplois du temps" description="Plannings semestriels par groupe — créneaux récurrents + exceptions."
            :fil="['Emplois du temps' => '#']">
            <x-slot:actions>
                <x-btn variant="secondary" href="{{ route('admin.emplois-du-temps.explorer') }}">Explorer</x-btn>
                <x-btn variant="ghost" href="{{ route('admin.emplois-du-temps.disponibilites') }}">Disponibilités</x-btn>
                <x-btn variant="ghost" href="{{ route('admin.emplois-du-temps.indisponibilites.index') }}">Indisponibilités</x-btn>
                @can('schedules.manage')
                    <x-btn href="{{ route('admin.emplois-du-temps.create') }}">Nouvel emploi du temps</x-btn>
                    <x-btn variant="secondary" href="{{ route('admin.emplois-du-temps.import.form') }}">Import CSV/JSON/XML/XLSX</x-btn>
                @endcan
            </x-slot:actions>
        </x-page-header>

        <form method="GET" action="{{ route('admin.emplois-du-temps.index') }}" class="mt-6 flex flex-col sm:flex-row gap-3 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
            <select name="semestre" aria-label="Filtrer par semestre" class="rounded-xl border border-slate-300 px-3.5 py-2.5 text-sm">
                <option value="">Tous semestres</option>
                <option value="1" @selected(request('semestre') === '1')>Semestre 1</option>
                <option value="2" @selected(request('semestre') === '2')>Semestre 2</option>
            </select>
            <button type="submit" class="rounded-xl border border-slate-300 px-5 py-2.5 text-sm font-bold text-slate-700 hover:bg-slate-50 min-h-[44px]">Filtrer</button>
        </form>

        <section aria-label="Registre des emplois du temps" class="mt-6 overflow-x-auto rounded-2xl border border-slate-200 bg-white shadow-sm">
            @if($plannings->isNotEmpty())
                <table class="w-full min-w-[680px] text-left text-xs sm:text-sm">
                    <thead>
                        <tr class="border-b border-slate-100 text-[11px] uppercase tracking-wider text-slate-400">
                            <th scope="col" class="px-5 py-3 font-bold">Nom</th>
                            <th scope="col" class="px-5 py-3 font-bold">Groupe</th>
                            <th scope="col" class="px-5 py-3 font-bold">Année</th>
                            <th scope="col" class="px-5 py-3 font-bold">Semestre</th>
                            <th scope="col" class="px-5 py-3 font-bold">Créneaux</th>
                            <th scope="col" class="px-5 py-3 font-bold"><span class="sr-only">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach($plannings as $planning)
                            <tr class="hover:bg-slate-50/60">
                                <td class="px-5 py-3 font-bold text-slate-900">{{ $planning->nom }}</td>
                                <td class="px-5 py-3 font-mono text-slate-700">{{ $planning->groupe->code }}</td>
                                <td class="px-5 py-3 text-slate-600">{{ $planning->anneeUniversitaire->libelle }}</td>
                                <td class="px-5 py-3 text-slate-600">S{{ $planning->semestre }}</td>
                                <td class="px-5 py-3 tabular-nums text-slate-700">{{ $planning->slots_count }}</td>
                                <td class="px-5 py-3 text-right">
                                    <a href="{{ route('admin.emplois-du-temps.show', $planning) }}" class="font-bold text-primary-800 underline underline-offset-2">Voir</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                <div class="px-5 py-3 border-t border-slate-100">{{ $plannings->links() }}</div>
            @else
                <div class="p-5">
                    <x-empty-state message="Aucun emploi du temps dans ce périmètre." />
                </div>
            @endif
        </section>
    </div>
@endsection
