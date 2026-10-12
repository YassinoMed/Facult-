@extends('layouts.app')

@section('title', 'Étudiants sans groupe')

@section('content')
    <div class="mx-auto max-w-6xl px-4 sm:px-6 py-8">
        <x-page-header :titre="'Sans groupe — ' . $total . ' étudiant(s)'" description="Affectation manquante : filtrer, affecter, exporter."
            :fil="['Étudiants' => route('admin.etudiants.index'), 'Sans groupe' => '#']">
            <x-slot:actions>
                <x-btn variant="secondary" href="{{ route('admin.affectations.masse') }}">Affecter en masse</x-btn>
                <x-btn variant="secondary" href="{{ route('admin.affectations.auto') }}">Auto</x-btn>
                @can('data.export')
                    <x-btn variant="ghost" href="{{ route('admin.listes.exporter', array_merge(['format' => 'csv', 'sans_groupe' => 1], $filtres)) }}">Exporter CSV</x-btn>
                @endcan
            </x-slot:actions>
        </x-page-header>

        <form method="GET" action="{{ route('admin.etudiants.sans-groupe') }}" class="mt-4 flex flex-col sm:flex-row gap-2">
            <input type="search" name="search" value="{{ $filtres['search'] ?? '' }}" placeholder="Nom, matricule, CIN…"
                   aria-label="Rechercher" class="flex-1 rounded-xl border border-slate-300 px-4 py-2.5 text-sm min-h-[44px]">
            <select name="annee_universitaire_id" aria-label="Année" class="rounded-xl border border-slate-300 px-3 py-2.5 text-sm min-h-[44px]">
                <option value="">Toutes années</option>
                @foreach($annees as $annee)
                    <option value="{{ $annee->id }}" @selected((string) ($filtres['annee_universitaire_id'] ?? '') === (string) $annee->id)>{{ $annee->libelle }}</option>
                @endforeach
            </select>
            <x-btn type="submit" variant="secondary">Filtrer</x-btn>
        </form>

        <section aria-label="Étudiants sans groupe" class="mt-4 overflow-x-auto rounded-2xl border border-slate-200 bg-white shadow-sm">
            @if($etudiants->isNotEmpty())
                <table class="w-full min-w-[760px] text-left text-xs sm:text-sm">
                    <thead>
                        <tr class="border-b border-slate-100 text-[11px] uppercase tracking-wider text-slate-400">
                            <th scope="col" class="px-5 py-3 font-bold">Matricule</th>
                            <th scope="col" class="px-5 py-3 font-bold">Nom</th>
                            <th scope="col" class="px-5 py-3 font-bold">Programme</th>
                            <th scope="col" class="px-5 py-3 font-bold">Niveau</th>
                            <th scope="col" class="px-5 py-3 font-bold"><span class="sr-only">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach($etudiants as $etudiant)
                            <tr class="hover:bg-slate-50/60">
                                <td class="px-5 py-3 font-mono font-bold">{{ $etudiant->num_inscription }}</td>
                                <td class="px-5 py-3 font-bold text-slate-900">{{ $etudiant->nom_complet }}</td>
                                <td class="px-5 py-3 text-slate-600">{{ $etudiant->programmeRef?->code ?? '—' }}</td>
                                <td class="px-5 py-3 text-slate-600">{{ $etudiant->niveau }}</td>
                                <td class="px-5 py-3 text-right whitespace-nowrap">
                                    <a href="{{ route('admin.etudiants.show', $etudiant) }}" class="font-bold text-primary-800 underline underline-offset-2">Affecter →</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                <div class="px-5 py-3 border-t border-slate-100">{{ $etudiants->links() }}</div>
            @else
                <div class="p-5"><x-empty-state message="Aucun étudiant sans groupe : toutes les affectations sont à jour." /></div>
            @endif
        </section>

        @if($groupes->isNotEmpty() && $etudiants->isNotEmpty())
            <form method="post" action="{{ route('admin.affectations.appliquer') }}" class="mt-4 ds-card p-5">
                @csrf
                <p class="text-sm font-bold">Affecter la page courante en masse</p>
                <div class="mt-2 flex flex-col sm:flex-row gap-2">
                    <select name="groupe_id" required class="rounded-xl border border-slate-200 px-3 py-2 text-sm">
                        @foreach($groupes as $groupe)
                            <option value="{{ $groupe->id }}">{{ $groupe->code }}</option>
                        @endforeach
                    </select>
                    <select name="annee_universitaire_id" required class="rounded-xl border border-slate-200 px-3 py-2 text-sm">
                        @foreach($annees as $annee)
                            <option value="{{ $annee->id }}">{{ $annee->libelle }}</option>
                        @endforeach
                    </select>
                    <input type="text" name="motif" required maxlength="255" placeholder="Motif (ex. rentrée)" class="flex-1 rounded-xl border border-slate-200 px-3 py-2 text-sm">
                </div>
                @foreach($etudiants as $etudiant)
                    <input type="hidden" name="etudiant_ids[]" value="{{ $etudiant->id }}">
                @endforeach
                <label class="mt-2 inline-flex items-center gap-1.5 text-xs"><input type="checkbox" name="confirmer" value="1" required /> Je confirme (simulation préalable recommandée via Affecter en masse).</label>
                <div class="mt-2"><x-btn type="submit">Appliquer</x-btn></div>
            </form>
        @endif
    </div>
@endsection
