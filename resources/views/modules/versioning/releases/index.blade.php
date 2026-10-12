@extends('layouts.app')

@section('title', 'Toutes les releases')

@section('content')
<div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 py-8 sm:py-10">

    <div class="flex items-center gap-2 text-xs font-semibold text-slate-500 mb-2">
        <a href="{{ route('admin.versioning.index') }}" class="hover:underline">Versions</a>
        <span>/</span>
        <span class="text-slate-800">Releases</span>
    </div>

    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h1 class="text-2xl sm:text-3xl font-black tracking-tight text-slate-900">
                Registre des releases
            </h1>
            <p class="mt-1 text-sm text-slate-500">
                Cycle complet des versions : préparation, tests, approbation, déploiement et archivage.
            </p>
        </div>

        <div class="flex items-center gap-2">
            <a href="{{ route('admin.versioning.releases.create') }}" class="inline-flex items-center gap-1.5 rounded-xl bg-primary-800 px-4 py-2 text-sm font-bold text-white shadow-sm hover:bg-primary-700 min-h-[42px]">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                </svg>
                Nouvelle release
            </a>
        </div>
    </div>

    {{-- Filtres --}}
    <form method="GET" action="{{ route('admin.versioning.releases.index') }}" class="mt-6 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
        <div class="grid grid-cols-1 sm:grid-cols-4 gap-3">
            <div>
                <label for="recherche" class="mb-1 block text-[11px] font-bold uppercase tracking-wider text-slate-500">Version</label>
                <input type="search" id="recherche" name="recherche" value="{{ $filtres['version'] ?? '' }}" placeholder="ex: 1.0.0, 2.1…"
                       class="w-full rounded-xl border border-slate-300 px-3.5 py-2 text-sm focus:border-primary-700 focus:outline-none">
            </div>
            <div>
                <label for="statut" class="mb-1 block text-[11px] font-bold uppercase tracking-wider text-slate-500">Statut</label>
                <select id="statut" name="statut" class="w-full rounded-xl border border-slate-300 px-3.5 py-2 text-sm focus:border-primary-700 focus:outline-none">
                    <option value="">Tous les statuts</option>
                    @foreach($statuts as $st)
                        <option value="{{ $st }}" @selected(($filtres['statut'] ?? '') === $st)>{{ ucfirst(str_replace('_', ' ', $st)) }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="environnement" class="mb-1 block text-[11px] font-bold uppercase tracking-wider text-slate-500">Environnement</label>
                <select id="environnement" name="environnement" class="w-full rounded-xl border border-slate-300 px-3.5 py-2 text-sm focus:border-primary-700 focus:outline-none">
                    <option value="">Tous</option>
                    <option value="local" @selected(($filtres['environnement'] ?? '') === 'local')>Local</option>
                    <option value="recette" @selected(($filtres['environnement'] ?? '') === 'recette')>Recette</option>
                    <option value="production" @selected(($filtres['environnement'] ?? '') === 'production')>Production</option>
                </select>
            </div>
            <div class="flex items-end">
                <button type="submit" class="w-full rounded-xl bg-primary-800 px-4 py-2 text-sm font-bold text-white hover:bg-primary-700 min-h-[42px]">
                    Filtrer
                </button>
            </div>
        </div>
    </form>

    {{-- Tableau --}}
    <div class="mt-6 rounded-2xl border border-slate-200 bg-white shadow-sm overflow-hidden">
        @if($releases->isNotEmpty())
            <table class="w-full text-left text-xs sm:text-sm">
                <thead>
                    <tr class="border-b border-slate-100 text-[11px] uppercase tracking-wider text-slate-400 bg-slate-50/50">
                        <th class="px-6 py-3 font-bold">Version</th>
                        <th class="px-6 py-3 font-bold">Statut</th>
                        <th class="px-6 py-3 font-bold">APP / DB</th>
                        <th class="px-6 py-3 font-bold">Build Number</th>
                        <th class="px-6 py-3 font-bold">Environnement</th>
                        <th class="px-6 py-3 font-bold">Date création</th>
                        <th class="px-6 py-3 font-bold text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach($releases as $rel)
                        <tr class="hover:bg-slate-50/60 transition">
                            <td class="px-6 py-4 font-mono font-bold text-slate-900">
                                <a href="{{ route('admin.versioning.releases.show', $rel) }}" class="hover:text-primary-700">
                                    {{ $rel->version }}
                                </a>
                            </td>
                            <td class="px-6 py-4">
                                <span class="inline-flex items-center rounded-md px-2.5 py-0.5 text-xs font-semibold
                                    {{ $rel->statut === 'deployee' ? 'bg-emerald-100 text-emerald-800' :
                                       ($rel->statut === 'approuvee' ? 'bg-purple-100 text-purple-800' :
                                       ($rel->statut === 'preparee' ? 'bg-blue-100 text-blue-800' :
                                       ($rel->statut === 'echouee' ? 'bg-rose-100 text-rose-800' :
                                       ($rel->statut === 'rollbackee' ? 'bg-pink-100 text-pink-800' : 'bg-slate-100 text-slate-700')))) }}">
                                    {{ $rel->statut_libelle }}
                                </span>
                            </td>
                            <td class="px-6 py-4 font-mono text-xs text-slate-600">
                                APP: {{ $rel->app_version }} • DB: {{ $rel->db_version }}
                            </td>
                            <td class="px-6 py-4 font-mono text-xs text-slate-500">
                                {{ $rel->build_number }}
                            </td>
                            <td class="px-6 py-4 text-xs font-semibold text-slate-600">
                                {{ ucfirst($rel->environnement) }}
                            </td>
                            <td class="px-6 py-4 text-xs text-slate-500 whitespace-nowrap">
                                {{ $rel->created_at->format('d/m/Y H:i') }}
                            </td>
                            <td class="px-6 py-4 text-right">
                                <div class="flex items-center justify-end gap-2">
                                    <a href="{{ route('admin.versioning.releases.show', $rel) }}" class="rounded-lg border border-slate-200 bg-white px-2.5 py-1 text-xs font-semibold text-slate-700 hover:bg-slate-50">
                                        Fiche
                                    </a>
                                    @if(in_array($rel->statut, ['preparee', 'testee', 'approuvee']))
                                        <a href="{{ route('admin.versioning.deployments.create', $rel) }}" class="rounded-lg bg-emerald-600 px-2.5 py-1 text-xs font-bold text-white hover:bg-emerald-700">
                                            Déployer
                                        </a>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>

            <div class="px-6 py-4 border-t border-slate-100">
                {{ $releases->links() }}
            </div>
        @else
            <div class="p-10 text-center text-sm text-slate-500">
                Aucune release ne correspond aux critères de recherche.
            </div>
        @endif
    </div>

</div>
@endsection
