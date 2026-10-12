@extends('layouts.app')

@section('title', 'Contrôle de compatibilité')

@section('content')
<div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 py-8 sm:py-10">

    <div class="flex items-center gap-2 text-xs font-semibold text-slate-500 mb-2">
        <a href="{{ route('admin.versioning.index') }}" class="hover:underline">Versions</a>
        <span>/</span>
        <span class="text-slate-800">Compatibilité</span>
    </div>

    <div>
        <h1 class="text-2xl sm:text-3xl font-black tracking-tight text-slate-900">
            Contrôle de compatibilité sémantique
        </h1>
        <p class="mt-1 text-sm text-slate-500">
            Règles formelles de synchronisation entre l'application, les schémas de base de données et les environnements.
        </p>
    </div>

    {{-- État global --}}
    <div class="mt-8 rounded-2xl border {{ $compat['statut_global'] === 'compatible' ? 'border-emerald-200 bg-emerald-50/50' : ($compat['statut_global'] === 'compatible_avec_avertissement' ? 'border-amber-200 bg-amber-50/50' : 'border-rose-200 bg-rose-50/50') }} p-6 shadow-sm">
        <div class="flex items-center gap-4">
            <div class="flex h-12 w-12 items-center justify-center rounded-2xl {{ $compat['statut_global'] === 'compatible' ? 'bg-emerald-100 text-emerald-700' : ($compat['statut_global'] === 'compatible_avec_avertissement' ? 'bg-amber-100 text-amber-700' : 'bg-rose-100 text-rose-700') }}">
                <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
            </div>
            <div>
                <h2 class="text-lg font-bold text-slate-900">
                    Statut global : {{ mb_strtoupper(str_replace('_', ' ', $compat['statut_global'])) }}
                </h2>
                <p class="text-xs text-slate-600">
                    Tolérance de correctif (patch) configurée : <span class="font-bold">{{ config('versioning.compatibilite.tolerance_patch', 1) }}</span> niveau(x).
                </p>
            </div>
        </div>
    </div>

    {{-- Détails des contrôles par paire --}}
    <div class="mt-6 grid grid-cols-1 md:grid-cols-2 gap-6">
        @foreach($compat['controles'] as $cle => $ctrl)
            <div class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                <div class="flex items-center justify-between">
                    <h3 class="text-sm font-bold uppercase tracking-wider text-slate-500">
                        {{ strtoupper(str_replace('_', ' ', $cle)) }}
                    </h3>
                    <span class="inline-flex items-center rounded-md px-2.5 py-0.5 text-xs font-semibold
                        {{ $ctrl['statut'] === 'compatible' ? 'bg-emerald-100 text-emerald-800' :
                           ($ctrl['statut'] === 'compatible_avec_avertissement' ? 'bg-amber-100 text-amber-800' : 'bg-rose-100 text-rose-800') }}">
                        {{ ucfirst(str_replace('_', ' ', $ctrl['statut'])) }}
                    </span>
                </div>

                <p class="mt-4 text-sm font-medium text-slate-800">
                    {{ $ctrl['message'] }}
                </p>

                <div class="mt-4 rounded-xl bg-slate-50 p-3 text-xs font-mono text-slate-600">
                    <pre>{{ json_encode($ctrl['details'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
                </div>
            </div>
        @endforeach
    </div>

    {{-- Règles de compatibilité appliquées --}}
    <div class="mt-8 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
        <h3 class="text-base font-bold text-slate-900 mb-3">Règles de compatibilité formelles (§15)</h3>
        <div class="space-y-3 text-xs text-slate-600">
            <div class="flex items-start gap-2">
                <span class="font-bold text-emerald-600">✔ APP X.Y.Z + DB X.Y.Z :</span>
                <span>Parfaitement synchronisés (aucun décalage de schéma ni de logique).</span>
            </div>
            <div class="flex items-start gap-2">
                <span class="font-bold text-amber-600">⚠ APP X.Y.1 + DB X.Y.0 :</span>
                <span>Toléré avec avertissement si le delta de patch est &le; tolérance configurée (pas de migration requise).</span>
            </div>
            <div class="flex items-start gap-2">
                <span class="font-bold text-rose-600">✖ APP X.2.0 + DB X.1.0 :</span>
                <span>Incompatible (écart mineur) : des migrations doivent être appliquées avant que le code ne puisse fonctionner.</span>
            </div>
            <div class="flex items-start gap-2">
                <span class="font-bold text-rose-600">✖ APP 2.0.0 + DB 1.9.0 :</span>
                <span>Incompatible (écart majeur) : rupture de schéma interdisant l'exécution sans mise à niveau complète.</span>
            </div>
        </div>
    </div>

</div>
@endsection
