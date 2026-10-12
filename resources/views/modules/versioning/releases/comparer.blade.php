@extends('layouts.app')

@section('title', 'Comparateur de releases')

@section('content')
<div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 py-8 sm:py-10">

    <div class="flex items-center gap-2 text-xs font-semibold text-slate-500 mb-2">
        <a href="{{ route('admin.versioning.index') }}" class="hover:underline">Versions</a>
        <span>/</span>
        <a href="{{ route('admin.versioning.releases.index') }}" class="hover:underline">Releases</a>
        <span>/</span>
        <span class="text-slate-800">Comparateur</span>
    </div>

    <div>
        <h1 class="text-2xl sm:text-3xl font-black tracking-tight text-slate-900">
            Comparateur de releases
        </h1>
        <p class="mt-1 text-sm text-slate-500">
            Analyse différentielle entre deux versions : code, base de données, configuration et dépendances.
        </p>
    </div>

    {{-- Sélecteur de comparaison --}}
    <form method="GET" action="{{ route('admin.versioning.releases.comparer') }}" class="mt-8 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
        <div class="grid grid-cols-1 sm:grid-cols-5 gap-4 items-end">
            <div class="sm:col-span-2">
                <label for="source_id" class="mb-1 block text-xs font-bold text-slate-700">Release Source (Référence)</label>
                <select id="source_id" name="source_id" class="w-full rounded-xl border border-slate-300 px-3.5 py-2.5 text-sm focus:border-primary-700 focus:outline-none">
                    @foreach($releases as $rel)
                        <option value="{{ $rel->id }}" @selected(($source?->id ?? null) === $rel->id)>
                            Release {{ $rel->version }} ({{ $rel->statut_libelle }})
                        </option>
                    @endforeach
                </select>
            </div>

            <div class="sm:col-span-2">
                <label for="cible_id" class="mb-1 block text-xs font-bold text-slate-700">Release Cible (Comparée)</label>
                <select id="cible_id" name="cible_id" class="w-full rounded-xl border border-slate-300 px-3.5 py-2.5 text-sm focus:border-primary-700 focus:outline-none">
                    @foreach($releases as $rel)
                        <option value="{{ $rel->id }}" @selected(($cible?->id ?? null) === $rel->id)>
                            Release {{ $rel->version }} ({{ $rel->statut_libelle }})
                        </option>
                    @endforeach
                </select>
            </div>

            <div>
                <button type="submit" class="w-full rounded-xl bg-primary-800 px-4 py-2.5 text-sm font-bold text-white hover:bg-primary-700 min-h-[44px]">
                    Comparer
                </button>
            </div>
        </div>
    </form>

    @if($resultat)
        {{-- Résumé de l'évolution --}}
        <div class="mt-8 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                <div>
                    <span class="text-xs font-bold uppercase tracking-wider text-slate-400">Nature du changement</span>
                    <h2 class="text-xl font-bold text-slate-900 mt-1">
                        Évolution : <span class="capitalize text-primary-700 font-black">{{ str_replace('_', ' ', $resultat['type_evolution']) }}</span>
                    </h2>
                    <p class="text-xs text-slate-500">
                        De la release <span class="font-mono font-bold">{{ $resultat['source']->version }}</span> vers <span class="font-mono font-bold">{{ $resultat['cible']->version }}</span>
                    </p>
                </div>

                <div class="flex items-center gap-3">
                    <span class="inline-flex items-center rounded-lg px-3 py-1.5 text-xs font-bold {{ $resultat['diff_config'] ? 'bg-amber-100 text-amber-800' : 'bg-slate-100 text-slate-700' }}">
                        Config : {{ $resultat['diff_config'] ? 'Modifiée' : 'Identique' }}
                    </span>
                    <span class="inline-flex items-center rounded-lg px-3 py-1.5 text-xs font-bold {{ $resultat['diff_dependances'] ? 'bg-amber-100 text-amber-800' : 'bg-slate-100 text-slate-700' }}">
                        Dépendances : {{ $resultat['diff_dependances'] ? 'Modifiées' : 'Identiques' }}
                    </span>
                </div>
            </div>
        </div>

        {{-- Tableau comparatif des composants --}}
        <div class="mt-6 rounded-2xl border border-slate-200 bg-white shadow-sm overflow-hidden">
            <div class="px-6 py-4 border-b border-slate-100 bg-slate-50/50">
                <h3 class="text-base font-bold text-slate-900">Différentiel des composants</h3>
            </div>
            <table class="w-full text-left text-xs sm:text-sm">
                <thead>
                    <tr class="border-b border-slate-100 text-[11px] uppercase tracking-wider text-slate-400 bg-slate-50/20">
                        <th class="px-6 py-3 font-bold">Composant</th>
                        <th class="px-6 py-3 font-bold">Source ({{ $resultat['source']->version }})</th>
                        <th class="px-6 py-3 font-bold">Cible ({{ $resultat['cible']->version }})</th>
                        <th class="px-6 py-3 font-bold text-right">Statut</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach($resultat['versions'] as $cle => $diff)
                        <tr class="hover:bg-slate-50/60 transition">
                            <td class="px-6 py-4 font-mono font-bold text-slate-800">
                                {{ strtoupper(str_replace('_', ' ', $cle)) }}
                            </td>
                            <td class="px-6 py-4 font-mono text-xs text-slate-600">
                                {{ $diff['source'] ?? '—' }}
                            </td>
                            <td class="px-6 py-4 font-mono text-xs font-bold {{ $diff['modifie'] ? 'text-primary-700 bg-primary-50/30' : 'text-slate-800' }}">
                                {{ $diff['cible'] ?? '—' }}
                            </td>
                            <td class="px-6 py-4 text-right">
                                @if($diff['modifie'])
                                    <span class="inline-flex items-center rounded-md bg-amber-100 px-2 py-0.5 text-xs font-bold text-amber-800">
                                        Modifié
                                    </span>
                                @else
                                    <span class="inline-flex items-center rounded-md bg-slate-100 px-2 py-0.5 text-xs font-semibold text-slate-600">
                                        Inchangé
                                    </span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                    {{-- Ligne Migrations BD --}}
                    <tr class="hover:bg-slate-50/60 transition bg-slate-50/30">
                        <td class="px-6 py-4 font-mono font-bold text-slate-800">
                            MIGRATIONS APPLIQUÉES
                        </td>
                        <td class="px-6 py-4 font-mono text-xs text-slate-600">
                            {{ $resultat['delta_migrations']['source'] }}
                        </td>
                        <td class="px-6 py-4 font-mono text-xs font-bold text-slate-800">
                            {{ $resultat['delta_migrations']['cible'] }}
                        </td>
                        <td class="px-6 py-4 text-right">
                            <span class="font-mono text-xs font-bold {{ $resultat['delta_migrations']['diff'] > 0 ? 'text-emerald-700' : 'text-slate-600' }}">
                                {{ $resultat['delta_migrations']['diff'] >= 0 ? '+' : '' }}{{ $resultat['delta_migrations']['diff'] }} migration(s)
                            </span>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        {{-- Notes côte à côte --}}
        <div class="mt-6 grid grid-cols-1 md:grid-cols-2 gap-6">
            <div class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                <h3 class="text-sm font-bold text-slate-900 mb-2">Changelog Source ({{ $resultat['source']->version }})</h3>
                <div class="prose max-w-none text-xs text-slate-600 whitespace-pre-line bg-slate-50 rounded-xl p-4 min-h-[120px]">
                    {{ $resultat['source']->notes ?: 'Aucune note.' }}
                </div>
            </div>

            <div class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                <h3 class="text-sm font-bold text-slate-900 mb-2">Changelog Cible ({{ $resultat['cible']->version }})</h3>
                <div class="prose max-w-none text-xs text-slate-700 whitespace-pre-line bg-primary-50/30 rounded-xl p-4 min-h-[120px]">
                    {{ $resultat['cible']->notes ?: 'Aucune note.' }}
                </div>
            </div>
        </div>
    @endif

</div>
@endsection
