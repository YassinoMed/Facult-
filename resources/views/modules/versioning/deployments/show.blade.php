@extends('layouts.app')

@section('title', 'Rapport de déploiement #' . $deployment->id)

@section('content')
<div class="mx-auto max-w-4xl px-4 sm:px-6 lg:px-8 py-8 sm:py-10">

    <div class="flex items-center gap-2 text-xs font-semibold text-slate-500 mb-2">
        <a href="{{ route('admin.versioning.index') }}" class="hover:underline">Versions</a>
        <span>/</span>
        <a href="{{ route('admin.versioning.deployments.index') }}" class="hover:underline">Déploiements</a>
        <span>/</span>
        <span class="text-slate-800">#{{ $deployment->id }}</span>
    </div>

    {{-- En-tête statut --}}
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-2xl sm:text-3xl font-black tracking-tight text-slate-900">
                Rapport de {{ $deployment->type_libelle }} #{{ $deployment->id }}
            </h1>
            <p class="mt-1 text-xs text-slate-500">
                Cible : <a href="{{ route('admin.versioning.releases.show', $deployment->release) }}" class="font-mono font-bold text-primary-700 hover:underline">Release {{ $deployment->release->version }}</a>
                • Environnement : <span class="font-semibold text-slate-800">{{ ucfirst($deployment->environnement) }}</span>
            </p>
        </div>

        <div>
            <span class="inline-flex items-center rounded-xl px-3 py-1.5 text-sm font-bold
                {{ $deployment->statut === 'reussi' ? 'bg-emerald-100 text-emerald-800' : 'bg-rose-100 text-rose-800' }}">
                {{ $deployment->statut_libelle }}
            </span>
        </div>
    </div>

    {{-- Carte métriques --}}
    <div class="mt-8 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
        <h2 class="text-sm font-bold uppercase tracking-wider text-slate-400 mb-4">Métriques d'exécution</h2>
        <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
            <div>
                <span class="text-xs text-slate-400">Durée totale</span>
                <p class="font-mono text-xl font-black text-slate-800">{{ $deployment->duree_secondes ?? 0 }} s</p>
            </div>
            <div>
                <span class="text-xs text-slate-400">Début</span>
                <p class="text-xs font-semibold text-slate-700">
                    {{ $deployment->started_at ? $deployment->started_at->format('H:i:s') : '—' }}
                </p>
            </div>
            <div>
                <span class="text-xs text-slate-400">Fin</span>
                <p class="text-xs font-semibold text-slate-700">
                    {{ $deployment->finished_at ? $deployment->finished_at->format('H:i:s') : '—' }}
                </p>
            </div>
            <div>
                <span class="text-xs text-slate-400">Opérateur</span>
                <p class="text-xs font-semibold text-slate-700 truncate">
                    {{ $deployment->deployeur?->name ?? 'CLI / Système' }}
                </p>
            </div>
        </div>
    </div>

    {{-- Erreur si échec --}}
    @if($deployment->erreur)
        <div class="mt-6 rounded-2xl border border-rose-200 bg-rose-50 p-6 shadow-sm">
            <h3 class="text-sm font-bold text-rose-900 mb-1">Message d'erreur bloquant</h3>
            <p class="font-mono text-xs text-rose-800 whitespace-pre-wrap">{{ $deployment->erreur }}</p>
        </div>
    @endif

    {{-- Sauvegarde préalable --}}
    @if($deployment->backup_path)
        <div class="mt-6 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
            <h3 class="text-sm font-bold text-slate-900 mb-1">Sauvegarde préalable de sécurité</h3>
            <p class="text-xs text-slate-500 mb-2">Créée automatiquement avant l'exécution du déploiement via `sauvegarde:creer`.</p>
            <p class="font-mono text-xs text-slate-800 bg-slate-50 p-3 rounded-xl border border-slate-100">
                {{ $deployment->backup_path }}
            </p>
        </div>
    @endif

    {{-- Métadonnées d'exécution --}}
    @if($deployment->meta)
        <div class="mt-6 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
            <h3 class="text-sm font-bold text-slate-900 mb-2">Métadonnées d'exécution</h3>
            <div class="rounded-xl bg-slate-900 p-4 font-mono text-xs text-emerald-400 overflow-x-auto">
                <pre>{{ json_encode($deployment->meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
            </div>
        </div>
    @endif

    <div class="mt-8 flex justify-between">
        <a href="{{ route('admin.versioning.deployments.index') }}" class="rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 hover:bg-slate-50">
            &larr; Retour à la liste
        </a>
        <a href="{{ route('admin.versioning.releases.show', $deployment->release) }}" class="rounded-xl bg-primary-800 px-5 py-2.5 text-sm font-bold text-white hover:bg-primary-700">
            Fiche de la release &rarr;
        </a>
    </div>

</div>
@endsection
