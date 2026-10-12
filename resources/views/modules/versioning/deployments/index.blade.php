@extends('layouts.app')

@section('title', 'Historique des déploiements')

@section('content')
<div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 py-8 sm:py-10">

    <div class="flex items-center gap-2 text-xs font-semibold text-slate-500 mb-2">
        <a href="{{ route('admin.versioning.index') }}" class="hover:underline">Versions</a>
        <span>/</span>
        <span class="text-slate-800">Déploiements</span>
    </div>

    <div>
        <h1 class="text-2xl sm:text-3xl font-black tracking-tight text-slate-900">
            Journal des déploiements et rollbacks
        </h1>
        <p class="mt-1 text-sm text-slate-500">
            Historique exhaustif de toutes les mises en production et retours arrière exécutés localement.
        </p>
    </div>

    <div class="mt-8 rounded-2xl border border-slate-200 bg-white shadow-sm overflow-hidden">
        @if($deploiements->isNotEmpty())
            <table class="w-full text-left text-xs sm:text-sm">
                <thead>
                    <tr class="border-b border-slate-100 text-[11px] uppercase tracking-wider text-slate-400 bg-slate-50/50">
                        <th class="px-6 py-3 font-bold">Type</th>
                        <th class="px-6 py-3 font-bold">Release</th>
                        <th class="px-6 py-3 font-bold">Environnement</th>
                        <th class="px-6 py-3 font-bold">Statut</th>
                        <th class="px-6 py-3 font-bold">Durée</th>
                        <th class="px-6 py-3 font-bold">Déclenché par</th>
                        <th class="px-6 py-3 font-bold">Date</th>
                        <th class="px-6 py-3 font-bold text-right">Détails</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach($deploiements as $dep)
                        <tr class="hover:bg-slate-50/60 transition">
                            <td class="px-6 py-4">
                                <span class="inline-flex items-center rounded-md px-2.5 py-0.5 text-xs font-bold
                                    {{ $dep->type === 'rollback' ? 'bg-orange-100 text-orange-800' : 'bg-blue-100 text-blue-800' }}">
                                    {{ $dep->type_libelle }}
                                </span>
                            </td>
                            <td class="px-6 py-4 font-mono font-bold text-slate-800">
                                <a href="{{ route('admin.versioning.releases.show', $dep->release) }}" class="hover:text-primary-700">
                                    {{ $dep->release->version }}
                                </a>
                            </td>
                            <td class="px-6 py-4 text-xs font-semibold text-slate-600">
                                {{ ucfirst($dep->environnement) }}
                            </td>
                            <td class="px-6 py-4">
                                <span class="inline-flex items-center rounded-md px-2 py-0.5 text-xs font-semibold
                                    {{ $dep->statut === 'reussi' ? 'bg-emerald-100 text-emerald-800' :
                                       ($dep->statut === 'echoue' ? 'bg-rose-100 text-rose-800' : 'bg-amber-100 text-amber-800') }}">
                                    {{ $dep->statut_libelle }}
                                </span>
                            </td>
                            <td class="px-6 py-4 text-xs text-slate-600">
                                {{ $dep->duree_secondes !== null ? "{$dep->duree_secondes}s" : '—' }}
                            </td>
                            <td class="px-6 py-4 text-xs text-slate-600">
                                {{ $dep->deployeur?->name ?? 'CLI / Système' }}
                            </td>
                            <td class="px-6 py-4 text-xs text-slate-500 whitespace-nowrap">
                                {{ $dep->started_at ? $dep->started_at->format('d/m/Y H:i:s') : $dep->created_at->format('d/m/Y H:i') }}
                            </td>
                            <td class="px-6 py-4 text-right">
                                <a href="{{ route('admin.versioning.deployments.show', $dep) }}" class="rounded-lg border border-slate-200 bg-white px-2.5 py-1 text-xs font-semibold text-slate-700 hover:bg-slate-50">
                                    Rapport
                                </a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>

            <div class="px-6 py-4 border-t border-slate-100">
                {{ $deploiements->links() }}
            </div>
        @else
            <div class="p-10 text-center text-sm text-slate-500">
                Aucun déploiement n'a encore été enregistré.
            </div>
        @endif
    </div>

</div>
@endsection
