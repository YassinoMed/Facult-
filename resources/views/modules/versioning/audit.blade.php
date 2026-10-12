@extends('layouts.app')

@section('title', 'Journal d\'audit — Versioning')

@section('content')
<div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 py-8 sm:py-10">

    <div class="flex items-center gap-2 text-xs font-semibold text-slate-500 mb-2">
        <a href="{{ route('admin.versioning.index') }}" class="hover:underline">Versions</a>
        <span>/</span>
        <span class="text-slate-800">Journal d'audit</span>
    </div>

    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h1 class="text-2xl sm:text-3xl font-black tracking-tight text-slate-900">
                Audit des opérations de versionnage
            </h1>
            <p class="mt-1 text-sm text-slate-500">
                Journal immuable (append-only) de toutes les actions : création, transitions, déploiements, rollbacks et dérives.
            </p>
        </div>
    </div>

    {{-- Filtres --}}
    <form method="GET" action="{{ route('admin.versioning.audit') }}" class="mt-6 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
            <div>
                <label for="filtre-action" class="mb-1 block text-[11px] font-bold uppercase tracking-wider text-slate-500">Action</label>
                <input type="text" id="filtre-action" name="action" value="{{ request('action') }}" placeholder="ex: deployment_started, release_created…"
                       class="w-full rounded-xl border border-slate-300 px-3.5 py-2 text-sm focus:border-primary-700 focus:outline-none">
            </div>
            <div>
                <label for="filtre-entite" class="mb-1 block text-[11px] font-bold uppercase tracking-wider text-slate-500">Entité</label>
                <select id="filtre-entite" name="entite" class="w-full rounded-xl border border-slate-300 px-3.5 py-2 text-sm focus:border-primary-700 focus:outline-none">
                    <option value="">Toutes les entités</option>
                    <option value="release" @selected(request('entite') === 'release')>Release</option>
                    <option value="deployment" @selected(request('entite') === 'deployment')>Déploiement</option>
                    <option value="composant" @selected(request('entite') === 'composant')>Composant</option>
                </select>
            </div>
            <div class="flex items-end">
                <button type="submit" class="w-full rounded-xl bg-primary-800 px-4 py-2 text-sm font-bold text-white hover:bg-primary-700 min-h-[42px]">
                    Filtrer l'audit
                </button>
            </div>
        </div>
    </form>

    {{-- Tableau d'audit --}}
    <div class="mt-6 rounded-2xl border border-slate-200 bg-white shadow-sm overflow-hidden">
        @if($audits->isNotEmpty())
            <table class="w-full text-left text-xs sm:text-sm">
                <thead>
                    <tr class="border-b border-slate-100 text-[11px] uppercase tracking-wider text-slate-400 bg-slate-50/50">
                        <th class="px-6 py-3 font-bold">Horodatage</th>
                        <th class="px-6 py-3 font-bold">Action</th>
                        <th class="px-6 py-3 font-bold">Entité & ID</th>
                        <th class="px-6 py-3 font-bold">Utilisateur / IP</th>
                        <th class="px-6 py-3 font-bold">Données / Diff</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach($audits as $audit)
                        <tr class="hover:bg-slate-50/60 transition">
                            <td class="px-6 py-3.5 text-xs text-slate-500 whitespace-nowrap">
                                {{ $audit->created_at->format('d/m/Y H:i:s') }}
                            </td>
                            <td class="px-6 py-3.5 font-mono text-xs font-bold text-slate-800">
                                <span class="inline-flex items-center rounded-md px-2 py-0.5 text-xs font-mono
                                    {{ str_contains($audit->action, 'failed') ? 'bg-rose-100 text-rose-800' :
                                       (str_contains($audit->action, 'succeeded') ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-100 text-slate-700') }}">
                                    {{ $audit->action }}
                                </span>
                            </td>
                            <td class="px-6 py-3.5 text-xs text-slate-600">
                                <span class="font-semibold">{{ ucfirst($audit->entite) }}</span>
                                @if($audit->entite_id)
                                    <span class="text-slate-400">#{{ $audit->entite_id }}</span>
                                @endif
                            </td>
                            <td class="px-6 py-3.5 text-xs text-slate-600">
                                <div>{{ $audit->utilisateur?->name ?? 'Système / CLI' }}</div>
                                <div class="font-mono text-[11px] text-slate-400">{{ $audit->adresse_ip ?? '127.0.0.1' }}</div>
                            </td>
                            <td class="px-6 py-3.5 text-xs">
                                @if($audit->nouvelle_valeur)
                                    <details class="cursor-pointer">
                                        <summary class="text-primary-700 font-semibold hover:underline">Voir les détails</summary>
                                        <div class="mt-2 rounded bg-slate-50 p-2 font-mono text-[11px] text-slate-700 max-w-md overflow-x-auto">
                                            @if($audit->ancienne_valeur)
                                                <div class="text-slate-400">Avant : {{ json_encode($audit->ancienne_valeur) }}</div>
                                            @endif
                                            <div class="text-slate-900">Après : {{ json_encode($audit->nouvelle_valeur) }}</div>
                                        </div>
                                    </details>
                                @else
                                    <span class="text-slate-400">—</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>

            <div class="px-6 py-4 border-t border-slate-100">
                {{ $audits->links() }}
            </div>
        @else
            <div class="p-8 text-center text-sm text-slate-500">
                Aucune entrée d'audit enregistrée pour le moment.
            </div>
        @endif
    </div>

</div>
@endsection
