@extends('layouts.app')

@section('title', 'Enseignants — Scolarité')

@section('content')
    <div class="mx-auto max-w-6xl px-4 sm:px-6 py-8">
        <x-page-header titre="Enseignants" description="Référentiel des enseignants — affectations via les créneaux."
            :fil="['Tableau de bord' => route('admin.dashboard'), 'Enseignants' => '#']">
            <x-slot:actions>
                @can('enseignants.manage')
                    <x-btn href="{{ route('admin.enseignants.create') }}">Nouvel enseignant</x-btn>
                @endcan
                @can('data.import')
                    <x-btn href="{{ route('admin.donnees.import.form', 'enseignants') }}" variant="secondary">Importer</x-btn>
                @endcan
                @can('data.export')
                    <x-btn href="{{ route('admin.donnees.export', ['enseignants', 'csv']) }}" variant="ghost">Exporter CSV</x-btn>
                @endcan
            </x-slot:actions>
        </x-page-header>

        <form method="GET" action="{{ route('admin.enseignants.index') }}" role="search" class="mt-4 flex flex-col gap-2">
            <div class="flex flex-col sm:flex-row gap-2">
                <input type="search" name="search" value="{{ $filtres['search'] ?? '' }}" placeholder="Nom, prénom, email, matricule…"
                       aria-label="Rechercher un enseignant"
                       class="flex-1 rounded-xl border border-slate-300 px-4 py-2.5 text-sm focus:border-primary-700 focus:outline-none min-h-[44px]">
                <x-btn type="submit" variant="secondary">Chercher</x-btn>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-2">
                <x-select id="filtre-departement" name="departement_id" label="Département"
                          :valeur="$filtres['departement_id'] ?? null" vide="Tous"
                          :options="$departements->mapWithKeys(fn ($d) => [$d->id => $d->code . ' — ' . $d->nom])->all()">
                </x-select>
                <x-select id="filtre-grade" name="grade_id" label="Grade"
                          :valeur="$filtres['grade_id'] ?? null" vide="Tous"
                          :options="$grades->mapWithKeys(fn ($g) => [$g->id => $g->nom])->all()">
                </x-select>
                <x-select id="filtre-statut" name="statut" label="Statut"
                          :valeur="$filtres['statut'] ?? null" vide="Tous"
                          :options="['actif' => 'Actif', 'inactif' => 'Inactif', 'suspendu' => 'Suspendu', 'retraite' => 'Retraité']">
                </x-select>
            </div>
        </form>

        <section aria-label="Liste des enseignants" class="mt-4 overflow-x-auto rounded-2xl border border-slate-200 bg-white shadow-sm">
            @if($enseignants->isNotEmpty())
                <table class="w-full min-w-[560px] text-left text-xs sm:text-sm">
                    <thead>
                        <tr class="border-b border-slate-100 text-[11px] uppercase tracking-wider text-slate-400">
                            <th scope="col" class="px-5 py-3 font-bold">Nom</th>
                            <th scope="col" class="px-5 py-3 font-bold">Email</th>
                            <th scope="col" class="px-5 py-3 font-bold">Créneaux</th>
                            <th scope="col" class="px-5 py-3 font-bold">Statut</th>
                            <th scope="col" class="px-5 py-3 font-bold"><span class="sr-only">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach($enseignants as $enseignant)
                            <tr class="hover:bg-slate-50/60">
                                <td class="px-5 py-3 font-bold text-slate-900">{{ $enseignant->nomComplet() }}</td>
                                <td class="px-5 py-3 text-slate-600">{{ $enseignant->email ?? '—' }}</td>
                                <td class="px-5 py-3 tabular-nums text-slate-700">{{ $enseignant->slots_count }}</td>
                                <td class="px-5 py-3">
                                    <span class="inline-flex items-center gap-1.5 rounded-full border border-slate-200 px-2.5 py-0.5 text-[11px] font-bold text-slate-700">
                                        <span class="h-1.5 w-1.5 rounded-full {{ $enseignant->actif ? 'bg-emerald-600' : 'bg-slate-400' }}" aria-hidden="true"></span>{{ $enseignant->actif ? 'Actif' : 'Inactif' }}
                                    </span>
                                </td>
                                <td class="px-5 py-3 text-right">
                                    @can('enseignants.manage')
                                        <a href="{{ route('admin.enseignants.edit', $enseignant) }}" class="font-bold text-primary-800 underline underline-offset-2">Modifier</a>
                                    @endcan
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                <div class="px-5 py-3 border-t border-slate-100">{{ $enseignants->links() }}</div>
            @else
                <div class="p-5"><x-empty-state message="Aucun enseignant enregistré." /></div>
            @endif
        </section>
    </div>
@endsection
