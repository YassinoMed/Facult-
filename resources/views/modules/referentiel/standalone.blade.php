@extends('layouts.app')

@section('title', 'Matières standalone — Référentiel LMD')

@section('content')
    <div class="mx-auto max-w-7xl px-4 sm:px-6 py-8">
        <x-page-header titre="Matières standalone ({{ $inventaire['resume']['total'] }})" description="Temps 1 — inventaire en lecture seule : usages, propositions classées, état de migration. Aucun rattachement automatique : la similarité de nom ne prouve rien, les temps 3 (validation) et 4 (application) attendent une décision humaine."
            :fil="['Référentiel' => route('admin.referentiel.tableau-bord'), 'Standalone' => '#']">
        </x-page-header>

        <div class="mt-4 grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-3">
            @foreach([
                ['Total', $inventaire['resume']['total']],
                ['Documentées', $inventaire['resume']['DOCUMENTEE']],
                ['À confirmer', $inventaire['resume']['A_CONFIRMER']],
                ['Ambiguës', $inventaire['resume']['AMBIGUE']],
                ['Sans correspondance', $inventaire['resume']['SANS_CORRESPONDANCE']],
            ] as [$libelle, $valeur])
                <div class="ds-card p-4">
                    <p class="text-2xl font-black tabular-nums text-slate-900">{{ $valeur }}</p>
                    <p class="text-[11px] font-bold uppercase tracking-wider text-slate-500">{{ $libelle }}</p>
                </div>
            @endforeach
        </div>

        <form method="GET" action="{{ route('admin.referentiel.standalone') }}" role="search" class="mt-4 flex flex-col sm:flex-row gap-2">
            <input type="search" name="search" value="{{ request('search') }}" placeholder="Code, nom…"
                   aria-label="Rechercher une matière" class="flex-1 rounded-xl border border-slate-300 px-4 py-2.5 text-sm min-h-[44px]">
            <select name="categorie" aria-label="Filtrer par catégorie" class="rounded-xl border border-slate-300 px-3 py-2.5 text-sm min-h-[44px]">
                <option value="">Toutes catégories</option>
                @foreach(['DOCUMENTEE' => 'Documentée', 'A_CONFIRMER' => 'À confirmer', 'AMBIGUE' => 'Ambiguë', 'SANS_CORRESPONDANCE' => 'Sans correspondance'] as $valeur => $libelle)
                    <option value="{{ $valeur }}" @selected(request('categorie') === $valeur)>{{ $libelle }}</option>
                @endforeach
            </select>
            <x-btn type="submit" variant="secondary">Filtrer</x-btn>
        </form>

        <section aria-label="Inventaire standalone" class="mt-4 overflow-x-auto rounded-2xl border border-slate-200 bg-white shadow-sm">
            @if($inventaire['matieres'] !== [])
                <table class="w-full min-w-[980px] text-left text-xs sm:text-sm">
                    <thead>
                        <tr class="border-b border-slate-100 text-[11px] uppercase tracking-wider text-slate-400">
                            <th scope="col" class="px-5 py-3 font-bold">Matière</th>
                            <th scope="col" class="px-5 py-3 font-bold">Crédits · Volumes</th>
                            <th scope="col" class="px-5 py-3 font-bold">Contexte legacy</th>
                            <th scope="col" class="px-5 py-3 font-bold">Usages</th>
                            <th scope="col" class="px-5 py-3 font-bold">Proposition</th>
                            <th scope="col" class="px-5 py-3 font-bold">Migration</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach($inventaire['matieres'] as $matiere)
                            <tr class="hover:bg-slate-50/60">
                                <td class="px-5 py-3">
                                    <p class="font-mono font-bold text-slate-800">{{ $matiere['code'] }}</p>
                                    <p class="text-slate-600">{{ $matiere['nom'] }}</p>
                                </td>
                                <td class="px-5 py-3 tabular-nums text-slate-700">{{ $matiere['credits'] }} crédits<br>{{ $matiere['volumes'] }} (CM/TD/TP)</td>
                                <td class="px-5 py-3 text-slate-600">{{ $matiere['niveau'] ?? '—' }} · S{{ $matiere['semestre'] ?? '—' }}</td>
                                <td class="px-5 py-3 tabular-nums">
                                    <p class="font-black text-slate-900">{{ $matiere['usages_total'] }} référence(s)</p>
                                    @if($matiere['usages_total'] > 0)
                                        <p class="text-[11px] text-slate-500">
                                            @foreach(array_filter($matiere['usages']) as $table => $nombre)
                                                {{ $table }} : {{ $nombre }}{{ !$loop->last ? ' · ' : '' }}
                                            @endforeach
                                        </p>
                                    @endif
                                </td>
                                <td class="px-5 py-3">
                                    <p class="text-[11px] font-black uppercase {{ $matiere['categorie'] === 'DOCUMENTEE' ? 'text-emerald-700' : ($matiere['categorie'] === 'SANS_CORRESPONDANCE' ? 'text-slate-500' : 'text-amber-700') }}">{{ str_replace('_', ' ', $matiere['categorie']) }}</p>
                                    <p class="text-slate-600">{{ $matiere['proposition'] }}</p>
                                </td>
                                <td class="px-5 py-3">
                                    <span class="inline-block rounded-full bg-slate-200 px-2.5 py-1 text-[11px] font-black text-slate-600">{{ str_replace('_', ' ', $matiere['etat_migration']) }}</span>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @else
                <div class="p-5"><x-empty-state message="Aucune matière standalone dans ce filtre." /></div>
            @endif
        </section>
        <p class="mt-3 text-[11px] text-slate-500">Méthode : jumeau = ECUE rattachée au nom normalisé identique (piste, pas preuve) ; UE candidate = niveau legacy + numéro de semestre. Le repli legacy reste supporté jusqu'à migration contrôlée.</p>
    </div>
@endsection
