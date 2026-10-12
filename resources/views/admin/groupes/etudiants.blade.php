@extends('layouts.app')

@section('title', 'Étudiants du groupe ' . $groupe->code)

@section('content')
    <div class="mx-auto max-w-6xl px-4 sm:px-6 py-8">
        <x-page-header :titre="'Groupe ' . $groupe->code . ' — ' . $total . ' étudiant(s)'" :description="$groupe->nom . ' · ' . $groupe->affectations_actives . '/' . ($groupe->capacite ?? '∞')"
            :fil="['Groupes' => route('admin.groupes.index'), $groupe->code => route('admin.groupes.show', $groupe), 'Étudiants' => '#']">
            <x-slot:actions>
                <x-btn variant="secondary" href="{{ route('admin.groupes.show', $groupe) }}">Fiche groupe</x-btn>
                @can('data.export')
                    <x-btn variant="ghost" href="{{ route('admin.listes.exporter', ['format' => 'csv', 'groupe_id' => $groupe->id]) }}">Exporter CSV</x-btn>
                    <x-btn variant="ghost" href="{{ route('admin.listes.exporter', ['format' => 'pdf', 'groupe_id' => $groupe->id]) }}">PDF</x-btn>
                    <x-btn variant="ghost" href="{{ route('admin.listes.exporter', ['format' => 'pdf', 'groupe_id' => $groupe->id, 'regroupement' => 'sous_groupe']) }}">PDF par sous-groupe</x-btn>
                    <x-btn variant="ghost" href="{{ route('admin.listes.imprimer', ['groupe_id' => $groupe->id]) }}">Imprimer</x-btn>
                @endcan
            </x-slot:actions>
        </x-page-header>

        <form method="GET" action="{{ route('admin.groupes.etudiants', $groupe) }}" role="search" class="mt-4 flex flex-col sm:flex-row gap-2">
            <input type="search" name="search" value="{{ $filtres['search'] ?? '' }}" placeholder="Nom, matricule, CIN…"
                   aria-label="Rechercher" class="flex-1 rounded-xl border border-slate-300 px-4 py-2.5 text-sm min-h-[44px]">
            <select name="sous_groupe_id" aria-label="Sous-groupe" class="rounded-xl border border-slate-300 px-3 py-2.5 text-sm min-h-[44px]">
                <option value="">Tous sous-groupes</option>
                @foreach($sousGroupes as $sg)
                    <option value="{{ $sg->id }}" @selected((string) ($filtres['sous_groupe_id'] ?? '') === (string) $sg->id)>{{ $sg->code }}</option>
                @endforeach
            </select>
            <x-btn type="submit" variant="secondary">Chercher</x-btn>
        </form>

        <section aria-label="Étudiants du groupe" class="mt-4 overflow-x-auto rounded-2xl border border-slate-200 bg-white shadow-sm">
            @if($etudiants->isNotEmpty())
                <table class="w-full min-w-[760px] text-left text-xs sm:text-sm">
                    <thead>
                        <tr class="border-b border-slate-100 text-[11px] uppercase tracking-wider text-slate-400">
                            <th scope="col" class="px-5 py-3 font-bold">#</th>
                            <th scope="col" class="px-5 py-3 font-bold">Matricule</th>
                            <th scope="col" class="px-5 py-3 font-bold">Nom</th>
                            <th scope="col" class="px-5 py-3 font-bold">Sous-groupe</th>
                            <th scope="col" class="px-5 py-3 font-bold">Statut</th>
                            <th scope="col" class="px-5 py-3 font-bold"><span class="sr-only">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach($etudiants as $index => $etudiant)
                            <tr class="hover:bg-slate-50/60">
                                <td class="px-5 py-3 tabular-nums text-slate-400">{{ $etudiants->firstItem() + $index }}</td>
                                <td class="px-5 py-3 font-mono font-bold">{{ $etudiant->num_inscription }}</td>
                                <td class="px-5 py-3 font-bold text-slate-900">{{ $etudiant->nom_complet }}</td>
                                <td class="px-5 py-3 font-mono text-slate-600">{{ $etudiant->affectations->firstWhere('statut', 'active')?->sousGroupe?->code ?? '—' }}</td>
                                <td class="px-5 py-3"><x-status-badge :statut="$etudiant->statut" /></td>
                                <td class="px-5 py-3 text-right whitespace-nowrap">
                                    <a href="{{ route('admin.etudiants.show', $etudiant) }}" class="font-bold text-primary-800 underline underline-offset-2">Dossier</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                <div class="px-5 py-3 border-t border-slate-100">{{ $etudiants->links() }}</div>
            @else
                <div class="p-5"><x-empty-state message="Aucun étudiant dans ce groupe pour ces filtres." /></div>
            @endif
        </section>
    </div>
@endsection
