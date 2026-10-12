@extends('layouts.app')

@section('title', 'Prévisualisation liste')

@section('content')
    <div class="mx-auto max-w-6xl px-4 sm:px-6 py-8">
        <x-page-header titre="Prévisualisation" :description="$total . ' résultat(s) — ' . $contexte"
            :fil="['Listes' => route('admin.listes.index'), 'Prévisualisation' => '#']">
            <x-slot:actions>
                <x-btn variant="ghost" href="{{ route('admin.listes.exports') }}">Mes exports</x-btn>
            </x-slot:actions>
        </x-page-header>

        <div class="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-4">
            <x-stat-card libelle="Total" :valeur="$statistiques['total']" />
            <x-stat-card libelle="Actifs" :valeur="$statistiques['actifs']" couleur="emerald" />
            <x-stat-card libelle="Sans groupe" :valeur="$statistiques['sans_groupe']" couleur="amber" />
            <x-stat-card libelle="Groupés" :valeur="$statistiques['groupes']" />
        </div>

        @if($regroupement !== null)
            <p class="mt-3 text-xs font-bold text-slate-500">Regroupement : {{ \App\Services\Exports\ListeEtudiantsService::libelleRegroupement($regroupement) }} (appliqué au PDF/CSV/XLSX via le même jeu de résultats).</p>
        @endif

        @if($errors->any())
            <div class="mt-4"><x-alert type="error">{{ $errors->first() }}</x-alert></div>
        @endif

        @if($total === 0)
            <div class="mt-4"><x-empty-state message="Aucun étudiant ne correspond aux critères sélectionnés." /></div>
        @else
            <x-export-actions class="mt-4" />
            <form method="post" action="{{ route('admin.listes.profils.store', request()->query()) }}" class="mt-3 flex flex-wrap items-end gap-2">
                @csrf
                <div>
                    <label class="text-xs font-bold uppercase tracking-wider text-slate-500" for="profil-nom">Enregistrer comme profil</label>
                    <input id="profil-nom" name="name" maxlength="120" required placeholder="Liste standard scolarité" class="mt-1 rounded-xl border border-slate-200 px-3 py-2 text-sm" />
                </div>
                <input type="hidden" name="format" value="pdf" />
                @foreach(request()->except(['_token', 'page']) as $cle => $valeur)
                    @if(is_array($valeur))
                        @foreach($valeur as $item)
                            <input type="hidden" name="{{ $cle }}[]" value="{{ $item }}" />
                        @endforeach
                    @else
                        <input type="hidden" name="{{ $cle }}" value="{{ $valeur }}" />
                    @endif
                @endforeach
                <x-btn size="sm" variant="secondary" type="submit">Enregistrer le profil</x-btn>
            </form>
            @if($profils->isNotEmpty())
                <div class="mt-2 flex flex-wrap gap-1.5">
                    @foreach($profils as $profil)
                        <a href="{{ route('admin.listes.preview', array_merge($profil->filters ?? [], ['colonnes' => $profil->columns])) }}" class="rounded-full border border-slate-200 bg-slate-50 px-2.5 py-1 text-xs font-bold text-slate-600">{{ $profil->name }}</a>
                    @endforeach
                </div>
            @endif
            <div class="mt-4 ds-card overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-slate-50 text-left text-[11px] uppercase tracking-wider text-slate-500">
                        <tr>
                            <th class="px-4 py-2">Matricule</th><th class="px-4 py-2">Nom</th>
                            <th class="px-4 py-2">Prénom</th><th class="px-4 py-2">Groupe</th>
                            <th class="px-4 py-2">Niveau</th><th class="px-4 py-2">Statut</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach($etudiants as $etudiant)
                            <tr>
                                <td class="px-4 py-2 font-mono">{{ $etudiant->num_inscription }}</td>
                                <td class="px-4 py-2 font-bold">{{ $etudiant->nom }}</td>
                                <td class="px-4 py-2">{{ $etudiant->prenom }}</td>
                                <td class="px-4 py-2">{{ $etudiant->groupe?->code ?? '—' }}</td>
                                <td class="px-4 py-2">{{ $etudiant->niveau }}</td>
                                <td class="px-4 py-2"><x-status-badge :statut="$etudiant->statut" /></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                <div class="px-4 py-3">{{ $etudiants->links() }}</div>
            </div>
        @endif
    </div>
@endsection
