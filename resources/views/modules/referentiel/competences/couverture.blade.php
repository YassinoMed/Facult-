@extends('layouts.app')

@section('title', 'Couverture — ' . ($rapport['maquette']['programme'] ?? 'Maquette'))

@section('content')
    <div class="mx-auto max-w-6xl px-4 sm:px-6 py-8">
        <x-page-header
            :titre="'Couverture pédagogique — ' . ($rapport['maquette']['programme'] ?? 'Maquette')"
            :description="'Maquette v' . $rapport['maquette']['version'] . ' · ' . ($rapport['maquette']['annee'] ?? '') . ' · statut ' . $rapport['maquette']['statut']"
            :fil="['Référentiel' => route('admin.referentiel.tableau-bord'), 'Compétences' => route('admin.referentiel.competences.index'), 'Couverture' => '#']">
            <x-slot:actions>
                <x-btn variant="ghost" href="{{ route('admin.referentiel.competences.index') }}">Catalogue</x-btn>
            </x-slot:actions>
        </x-page-header>

        @if(session('success'))
            <div class="mt-4"><x-alert type="success">{{ session('success') }}</x-alert></div>
        @endif

        <div class="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
            <x-stat-card libelle="UE" :valeur="$rapport['totaux']['ues']" />
            <x-stat-card libelle="UE renseignées" :valeur="$rapport['totaux']['ues_renseignees']" couleur="emerald" />
            <x-stat-card libelle="ECUE" :valeur="$rapport['totaux']['ecues']" />
            <x-stat-card libelle="ECUE renseignés" :valeur="$rapport['totaux']['ecues_renseignes']" couleur="emerald" />
            <x-stat-card libelle="Compétences" :valeur="$rapport['totaux']['competences_perimetre']" />
            <x-stat-card libelle="Couvertes" :valeur="$rapport['totaux']['competences_couvertes']" couleur="emerald" />
        </div>

        <div class="mt-4 grid gap-3 lg:grid-cols-2">
            <x-alert type="warning" titre="Absence de donnée">
                {{ $rapport['legende']['non_renseigne'] }}
            </x-alert>
            <x-alert type="error" titre="Absence de couverture">
                {{ $rapport['legende']['non_couverte'] }}
            </x-alert>
        </div>

        <section aria-label="Couverture par UE" class="mt-4 ds-card p-5">
            <h2 class="text-base font-black text-slate-900">Unités d’enseignement</h2>
            <div class="mt-3 overflow-x-auto">
                <table class="w-full min-w-[560px] text-sm">
                    <thead class="text-left text-[11px] uppercase tracking-wider text-slate-500">
                        <tr>
                            <th scope="col" class="px-3 py-2">UE</th>
                            <th scope="col" class="px-3 py-2">État</th>
                            <th scope="col" class="px-3 py-2">Compétences</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @forelse($rapport['ues'] as $ue)
                            <tr>
                                <td class="px-3 py-2 font-mono font-bold">{{ $ue['code'] ?? '—' }}</td>
                                <td class="px-3 py-2"><x-status-badge :statut="$ue['etat']" /></td>
                                <td class="px-3 py-2 text-xs text-slate-600">
                                    {{ collect($ue['competences'])->pluck('code')->implode(', ') ?: '—' }}
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="3" class="px-3 py-4 text-center text-slate-500">Aucune UE dans ce plan.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>

        <section aria-label="Couverture par ECUE" class="mt-4 ds-card p-5">
            <h2 class="text-base font-black text-slate-900">ECUE</h2>
            <div class="mt-3 overflow-x-auto">
                <table class="w-full min-w-[620px] text-sm">
                    <thead class="text-left text-[11px] uppercase tracking-wider text-slate-500">
                        <tr>
                            <th scope="col" class="px-3 py-2">ECUE</th>
                            <th scope="col" class="px-3 py-2">UE</th>
                            <th scope="col" class="px-3 py-2">État</th>
                            <th scope="col" class="px-3 py-2">Compétences</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @forelse($rapport['ecues'] as $ecue)
                            <tr>
                                <td class="px-3 py-2 font-mono font-bold">{{ $ecue['code'] ?? '—' }}</td>
                                <td class="px-3 py-2 font-mono text-xs text-slate-500">{{ $ecue['ue'] ?? '—' }}</td>
                                <td class="px-3 py-2"><x-status-badge :statut="$ecue['etat']" /></td>
                                <td class="px-3 py-2 text-xs text-slate-600">
                                    {{ collect($ecue['competences'])->pluck('code')->implode(', ') ?: '—' }}
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="px-3 py-4 text-center text-slate-500">Aucun ECUE dans ce plan.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>

        <section aria-label="Couverture par compétence" class="mt-4 ds-card p-5">
            <h2 class="text-base font-black text-slate-900">Compétences du périmètre</h2>
            <div class="mt-3 overflow-x-auto">
                <table class="w-full min-w-[520px] text-sm">
                    <thead class="text-left text-[11px] uppercase tracking-wider text-slate-500">
                        <tr>
                            <th scope="col" class="px-3 py-2">Code</th>
                            <th scope="col" class="px-3 py-2">Libellé</th>
                            <th scope="col" class="px-3 py-2">État</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @forelse($rapport['competences'] as $competence)
                            <tr>
                                <td class="px-3 py-2 font-mono font-bold">{{ $competence['code'] }}</td>
                                <td class="px-3 py-2">{{ $competence['libelle'] }}</td>
                                <td class="px-3 py-2"><x-status-badge :statut="$competence['etat']" /></td>
                            </tr>
                        @empty
                            <tr><td colspan="3" class="px-3 py-4 text-center text-slate-500">Catalogue approuvé vide pour ce périmètre.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>

        <section aria-label="Snapshots du rapport" class="mt-4 ds-card p-5">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 class="text-base font-black text-slate-900">Rapports figés</h2>
                    <p class="mt-1 text-xs text-slate-500">Un snapshot est immuable : la couverture publiée reste consultable même après dissociation.</p>
                </div>
                @can('referentiel.export')
                    <form method="post" action="{{ route('admin.referentiel.maquettes.couverture.figer', $maquette) }}">
                        @csrf
                        <x-btn type="submit">Figer le rapport courant</x-btn>
                    </form>
                @endcan
            </div>

            @if($snapshots->isNotEmpty())
                <ul class="mt-3 divide-y divide-slate-100 text-sm">
                    @foreach($snapshots as $snapshot)
                        <li class="flex flex-wrap items-center justify-between gap-2 py-2">
                            <span class="font-mono text-xs text-slate-700">#{{ $snapshot->id }} · {{ substr($snapshot->empreinte, 0, 16) }}…</span>
                            <span class="text-xs text-slate-500">{{ $snapshot->created_at?->format('d/m/Y H:i') }}</span>
                        </li>
                    @endforeach
                </ul>
            @else
                <p class="mt-3 text-sm text-slate-500">Aucun rapport figé pour cette maquette.</p>
            @endif
        </section>
    </div>
@endsection
