@extends('layouts.app')

@section('title', 'Détection de dérive (Drift)')

@section('content')
<div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 py-8 sm:py-10">

    <div class="flex items-center gap-2 text-xs font-semibold text-slate-500 mb-2">
        <a href="{{ route('admin.versioning.index') }}" class="hover:underline">Versions</a>
        <span>/</span>
        <span class="text-slate-800">Dérive (Drift)</span>
    </div>

    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h1 class="text-2xl sm:text-3xl font-black tracking-tight text-slate-900">
                Détection de dérive (Drift Detection)
            </h1>
            <p class="mt-1 text-sm text-slate-500">
                Comparaison rigoureuse entre l'état déclaré dans le manifest et l'état physique constaté sur ce serveur.
            </p>
        </div>
        <div>
            <a href="{{ route('admin.versioning.drift') }}" class="inline-flex items-center gap-1.5 rounded-xl border border-slate-300 bg-white px-3.5 py-2 text-sm font-semibold text-slate-700 shadow-sm hover:bg-slate-50">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                </svg>
                Réanalyser maintenant
            </a>
        </div>
    </div>

    {{-- Bannière statut score d'intégrité --}}
    <div class="mt-8 rounded-2xl border {{ $drift['drift_detecte'] ? 'border-rose-200 bg-rose-50/60' : 'border-emerald-200 bg-emerald-50/60' }} p-6 shadow-sm">
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
            <div class="flex items-center gap-4">
                <div class="flex h-14 w-14 items-center justify-center rounded-2xl {{ $drift['drift_detecte'] ? 'bg-rose-100 text-rose-700' : 'bg-emerald-100 text-emerald-700' }}">
                    @if($drift['drift_detecte'])
                        <svg class="h-8 w-8" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                        </svg>
                    @else
                        <svg class="h-8 w-8" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z" />
                        </svg>
                    @endif
                </div>
                <div>
                    <h2 class="text-xl font-bold {{ $drift['drift_detecte'] ? 'text-rose-900' : 'text-emerald-900' }}">
                        {{ $drift['drift_detecte'] ? 'DRIFT DÉTECTÉ — Le système a divergé' : 'AUCUN DRIFT — Système intègre et certifié' }}
                    </h2>
                    <p class="text-xs {{ $drift['drift_detecte'] ? 'text-rose-700' : 'text-emerald-700' }}">
                        Release de référence : <span class="font-bold">{{ $drift['release_reference'] ?? 'Non définie' }}</span> • Analysé le {{ \Carbon\Carbon::parse($drift['verifie_le'])->format('d/m/Y H:i:s') }}
                    </p>
                </div>
            </div>

            <div class="flex items-center gap-3">
                <div class="text-right">
                    <span class="text-xs font-semibold text-slate-500 uppercase">Indice de confiance</span>
                    <p class="font-mono text-3xl font-black {{ $drift['score_integrite'] >= 80 ? 'text-emerald-700' : ($drift['score_integrite'] >= 50 ? 'text-amber-700' : 'text-rose-700') }}">
                        {{ $drift['score_integrite'] }}%
                    </p>
                </div>
            </div>
        </div>
    </div>

    {{-- Tableau des écarts --}}
    <div class="mt-8 rounded-2xl border border-slate-200 bg-white shadow-sm overflow-hidden">
        <div class="px-6 py-4 border-b border-slate-100 bg-slate-50/50">
            <h3 class="text-base font-bold text-slate-900">Éléments analysés</h3>
            <p class="text-xs text-slate-500">Contrôle des composantes APP, DB, Migrations, Fichiers de verrou et Configuration</p>
        </div>

        @if($drift['drift_detecte'])
            <table class="w-full text-left text-xs sm:text-sm">
                <thead>
                    <tr class="border-b border-slate-100 text-[11px] uppercase tracking-wider text-slate-400">
                        <th class="px-6 py-3 font-bold">Composant</th>
                        <th class="px-6 py-3 font-bold">Valeur Déclarée (Manifest)</th>
                        <th class="px-6 py-3 font-bold">État Réel Constaté</th>
                        <th class="px-6 py-3 font-bold">Gravité</th>
                        <th class="px-6 py-3 font-bold">Explication</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach($drift['ecarts'] as $cle => $ecart)
                        <tr class="hover:bg-slate-50/60 transition">
                            <td class="px-6 py-4 font-mono font-bold text-slate-800">
                                {{ $cle }}
                            </td>
                            <td class="px-6 py-4 font-mono text-xs text-slate-600 bg-slate-50/50">
                                {{ is_scalar($ecart['declare']) ? $ecart['declare'] : json_encode($ecart['declare']) }}
                            </td>
                            <td class="px-6 py-4 font-mono text-xs font-bold {{ $ecart['critique'] ? 'text-rose-700 bg-rose-50/40' : 'text-amber-700 bg-amber-50/40' }}">
                                {{ is_scalar($ecart['reel']) ? $ecart['reel'] : json_encode($ecart['reel']) }}
                            </td>
                            <td class="px-6 py-4">
                                @if($ecart['critique'])
                                    <span class="inline-flex items-center rounded-md bg-rose-100 px-2.5 py-0.5 text-xs font-bold text-rose-800">
                                        Critique
                                    </span>
                                @else
                                    <span class="inline-flex items-center rounded-md bg-amber-100 px-2.5 py-0.5 text-xs font-bold text-amber-800">
                                        Mineur
                                    </span>
                                @endif
                            </td>
                            <td class="px-6 py-4 text-xs text-slate-700">
                                {{ $ecart['message'] }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @else
            <div class="p-10 text-center">
                <div class="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-emerald-100 text-emerald-600">
                    <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                    </svg>
                </div>
                <h4 class="mt-3 text-sm font-bold text-slate-800">Aucune divergence détectée</h4>
                <p class="mt-1 text-xs text-slate-500">Toutes les versions, dépendances et migrations correspondent exactement aux spécifications du manifest.</p>
            </div>
        @endif
    </div>

    {{-- Empreinte manifest actuel --}}
    @if($manifest)
        <div class="mt-8 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
            <h3 class="text-sm font-bold text-slate-900 mb-2">Manifest actif</h3>
            <div class="rounded-xl bg-slate-900 p-4 font-mono text-xs text-emerald-400 overflow-x-auto max-h-80">
                <pre>{{ json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
            </div>
        </div>
    @endif

</div>
@endsection
