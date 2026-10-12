@extends('layouts.app')

@section('title', 'Référentiel LMD — Tableau de bord')

@section('content')
    <div class="mx-auto max-w-6xl px-4 sm:px-6 py-8">
        <x-page-header titre="Référentiel académique LMD" description="Hiérarchie établissement → maquettes : compteurs et alertes qualité calculés côté serveur."
            :fil="['Référentiel' => '#']">
            <x-slot:actions>
                <x-btn variant="secondary" href="{{ route('admin.referentiel.pilotage') }}">Pilotage</x-btn>
                <x-btn variant="secondary" href="{{ route('admin.referentiel.arbre') }}">Vue hiérarchique</x-btn>
                <x-btn variant="ghost" href="{{ route('admin.referentiel.qualite') }}">Qualité</x-btn>
            </x-slot:actions>
        </x-page-header>

        <div class="mt-4 grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-3">
            @foreach([
                ['Établissements', $compteurs['etablissements']],
                ['Programmes', $compteurs['programmes']],
                ['Niveaux', $compteurs['niveaux']],
                ['UE', $compteurs['ues']],
                ['ECUE rattachées', $compteurs['ecues']],
                ['Matières standalone', $compteurs['standalone']],
                ['Maquettes actives', $compteurs['maquettes_actives']],
                ['Maquettes en brouillon', $compteurs['maquettes_brouillon']],
                ['Maquettes approuvées', $compteurs['maquettes_approuvees']],
                ['Maquettes archivées', $compteurs['maquettes_archivees']],
            ] as [$libelle, $valeur])
                <div class="ds-card p-4">
                    <p class="text-2xl font-black tabular-nums text-slate-900">{{ $valeur }}</p>
                    <p class="text-[11px] font-bold uppercase tracking-wider text-slate-500">{{ $libelle }}</p>
                </div>
            @endforeach
        </div>

        @if($facultes !== [])
            <form method="GET" action="{{ route('admin.referentiel.tableau-bord') }}" class="mt-4 flex flex-col sm:flex-row gap-2">
                <select name="faculte_id" aria-label="Filtrer par établissement" class="rounded-xl border border-slate-300 px-3 py-2.5 text-sm min-h-[44px] flex-1" onchange="this.form.submit()">
                    <option value="">Tous établissements (comparaison ci-dessous)</option>
                    @foreach($facultes as $faculte)
                        <option value="{{ is_array($faculte) ? $faculte['id'] : $faculte->id }}" @selected($filtreFaculte === (is_array($faculte) ? $faculte['id'] : $faculte->id))>{{ is_array($faculte) ? $faculte['nom'] : $faculte->nom }}</option>
                    @endforeach
                </select>
                @if($filtreFaculte !== null)
                    <a href="{{ route('admin.referentiel.tableau-bord') }}" class="inline-flex items-center text-xs font-bold text-slate-500 underline underline-offset-2 min-h-[44px]">Réinitialiser</a>
                @endif
            </form>

            @if($filtreFaculte === null)
                <section aria-label="Comparaison par établissement" class="mt-4 overflow-x-auto rounded-2xl border border-slate-200 bg-white shadow-sm">
                    <table class="w-full min-w-[720px] text-left text-xs sm:text-sm">
                        <thead>
                            <tr class="border-b border-slate-100 text-[11px] uppercase tracking-wider text-slate-400">
                                <th scope="col" class="px-5 py-3 font-bold">Établissement</th>
                                <th scope="col" class="px-5 py-3 font-bold">Programmes</th>
                                <th scope="col" class="px-5 py-3 font-bold">Niveaux</th>
                                <th scope="col" class="px-5 py-3 font-bold">UE</th>
                                <th scope="col" class="px-5 py-3 font-bold">ECUE</th>
                                <th scope="col" class="px-5 py-3 font-bold">Maquettes actives</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach($parFaculte as $ligne)
                                <tr class="hover:bg-slate-50/60">
                                    <td class="px-5 py-3 font-bold text-slate-900">{{ $ligne['nom'] }}</td>
                                    <td class="px-5 py-3 tabular-nums">{{ $ligne['programmes'] }}</td>
                                    <td class="px-5 py-3 tabular-nums">{{ $ligne['niveaux'] }}</td>
                                    <td class="px-5 py-3 tabular-nums">{{ $ligne['ues'] }}</td>
                                    <td class="px-5 py-3 tabular-nums">{{ $ligne['ecues'] }}</td>
                                    <td class="px-5 py-3 tabular-nums font-black {{ $ligne['maquettes_actives'] > 0 ? 'text-emerald-700' : 'text-slate-400' }}">{{ $ligne['maquettes_actives'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </section>
            @endif
        @endif

        <div class="mt-4 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
            @foreach([
                ['Départements', 'admin.referentiel.entites.index', 'departements'],
                ['Domaines', 'admin.referentiel.entites.index', 'domaines'],
                ['Mentions', 'admin.referentiel.entites.index', 'mentions'],
                ['Spécialités', 'admin.referentiel.entites.index', 'specialites'],
                ['Programmes', 'admin.referentiel.entites.index', 'programmes'],
                ['Types de diplômes', 'admin.referentiel.entites.index', 'types-diplomes'],
                ['Niveaux', 'admin.referentiel.entites.index', 'niveaux'],
                ['Semestres', 'admin.referentiel.entites.index', 'semestres'],
                ['UE', 'admin.referentiel.entites.index', 'ues'],
                ['ECUE', 'admin.referentiel.ecues.index', null],
                ['Maquettes', 'admin.referentiel.maquettes.index', null],
                ['Pilotage', 'admin.referentiel.pilotage', null],
                ['Prévalidation', 'admin.referentiel.prevalidation.index', null],
                ['Standalone', 'admin.referentiel.standalone', null],
                ['Charge pédagogique', 'admin.referentiel.charges', null],
                ['Prérequis', 'admin.referentiel.prerequis', null],
            ] as [$libelle, $route, $param])
                <a href="{{ $param ? route($route, $param) : route($route) }}"
                   class="ds-card p-4 hover:border-primary-300 hover:shadow-md transition flex items-center justify-between gap-2">
                    <span class="text-sm font-black text-slate-900">{{ $libelle }}</span>
                    <span aria-hidden="true" class="text-slate-400">→</span>
                </a>
            @endforeach
        </div>

        @if($anomalies !== [])
            <section aria-label="Alertes qualité" class="mt-4 ds-card p-5">
                <div class="flex items-center justify-between gap-2">
                    <h2 class="text-base font-black text-slate-900">Alertes qualité ({{ count($anomalies) }})</h2>
                    <a href="{{ route('admin.referentiel.qualite') }}" class="text-xs font-bold text-primary-800 underline underline-offset-2">Tout voir →</a>
                </div>
                <ul class="mt-3 space-y-1.5 text-sm">
                    @foreach($anomalies as $anomalie)
                        <li class="flex items-start gap-2">
                            <span aria-hidden="true">{{ $anomalie['gravite'] === 'erreur' ? '🔴' : ($anomalie['gravite'] === 'avertissement' ? '🟡' : '🔵') }}</span>
                            <span><strong>{{ $anomalie['entite'] }}</strong> — {{ $anomalie['probleme'] }}</span>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif
    </div>
@endsection
