@extends('layouts.app')

@section('title', 'Prévalidation des maquettes — Référentiel LMD')

@section('content')
    <div class="mx-auto max-w-6xl px-4 sm:px-6 py-8">
        <x-page-header titre="Prévalidation des maquettes" description="Rapport de simulation en lecture seule (aucune écriture) : BLOCKER = activation interdite, WARNING = vérification humaine, INFO = contrôle réussi. L'activation reste un geste manuel sur la fiche maquette."
            :fil="['Référentiel' => route('admin.referentiel.tableau-bord'), 'Pilotage' => route('admin.referentiel.pilotage'), 'Prévalidation' => '#']">
        </x-page-header>

        <div class="mt-4 grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3">
            @foreach([
                ['Programmes', $rapport['resume']['programmes']],
                ['Activables', $rapport['resume']['activables']],
                ['Déjà actives', $rapport['resume']['actives_deja']],
                ['En attente', $rapport['resume']['en_attente']],
                ['Bloquants', $rapport['resume']['blocages']],
                ['Avertissements', $rapport['resume']['avertissements']],
            ] as [$libelle, $valeur])
                <div class="ds-card p-4">
                    <p class="text-2xl font-black tabular-nums text-slate-900">{{ $valeur }}</p>
                    <p class="text-[11px] font-bold uppercase tracking-wider text-slate-500">{{ $libelle }}</p>
                </div>
            @endforeach
        </div>

        <section aria-label="Prévalidation par programme" class="mt-4 overflow-x-auto rounded-2xl border border-slate-200 bg-white shadow-sm">
            @if($rapport['programmes'] !== [])
                <table class="w-full min-w-[720px] text-left text-xs sm:text-sm">
                    <thead>
                        <tr class="border-b border-slate-100 text-[11px] uppercase tracking-wider text-slate-400">
                            <th scope="col" class="px-5 py-3 font-bold">Programme</th>
                            <th scope="col" class="px-5 py-3 font-bold">Recommandation</th>
                            <th scope="col" class="px-5 py-3 font-bold">Bloquants</th>
                            <th scope="col" class="px-5 py-3 font-bold">Avertissements</th>
                            <th scope="col" class="px-5 py-3 font-bold"><span class="sr-only">Détail</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach($rapport['programmes'] as $programme)
                            <tr class="hover:bg-slate-50/60">
                                <td class="px-5 py-3">
                                    <p class="font-mono font-bold text-slate-800">{{ $programme['programme'] }}</p>
                                    <p class="text-[12px] text-slate-600">{{ $programme['programme_nom'] }}</p>
                                </td>
                                <td class="px-5 py-3 text-[11px] font-black uppercase">
                                    @if($programme['recommandation'] === 'ACTIVE_DEJA')
                                        <span class="inline-block rounded-full bg-emerald-100 px-2.5 py-1 text-emerald-800">Active déjà</span>
                                    @elseif($programme['recommandation'] === 'ACTIVABLE')
                                        <span class="inline-block rounded-full bg-amber-100 px-2.5 py-1 text-amber-800">Activable</span>
                                    @else
                                        <span class="inline-block rounded-full bg-red-100 px-2.5 py-1 text-red-800">En attente</span>
                                    @endif
                                </td>
                                <td class="px-5 py-3 tabular-nums font-black text-slate-900">{{ count($programme['blocages']) }}</td>
                                <td class="px-5 py-3 tabular-nums text-slate-700">{{ count($programme['avertissements']) }}</td>
                                <td class="px-5 py-3 text-right">
                                    <a href="{{ route('admin.referentiel.prevalidation.show', $programme['programme_id']) }}" class="font-bold text-primary-800 underline underline-offset-2">Détail</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @else
                <div class="p-5"><x-empty-state message="Aucun programme dans ce périmètre." /></div>
            @endif
        </section>

        <p class="mt-3 text-[11px] text-slate-500">Référentiel legacy : {{ $rapport['resume']['matieres_rattachees'] }} ECUE rattachées / {{ $rapport['resume']['matieres_standalone'] }} standalone · {{ $rapport['resume']['inscriptions_sans_programme'] }} inscriptions sans programme (hors périmètre d'activation, traitées en migration contrôlée).</p>
    </div>
@endsection
