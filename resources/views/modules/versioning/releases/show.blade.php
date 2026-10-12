@extends('layouts.app')

@section('title', 'Release ' . $release->version)

@section('content')
<div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 py-8 sm:py-10">

    <div class="flex items-center gap-2 text-xs font-semibold text-slate-500 mb-2">
        <a href="{{ route('admin.versioning.index') }}" class="hover:underline">Versions</a>
        <span>/</span>
        <a href="{{ route('admin.versioning.releases.index') }}" class="hover:underline">Releases</a>
        <span>/</span>
        <span class="text-slate-800">{{ $release->version }}</span>
    </div>

    {{-- En-tête release --}}
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <div class="flex items-center gap-3">
                <h1 class="font-mono text-2xl sm:text-3xl font-black tracking-tight text-slate-900">
                    Release {{ $release->version }}
                </h1>
                <span class="inline-flex items-center rounded-md px-3 py-1 text-xs font-bold
                    {{ $release->statut === 'deployee' ? 'bg-emerald-100 text-emerald-800' :
                       ($release->statut === 'approuvee' ? 'bg-purple-100 text-purple-800' :
                       ($release->statut === 'preparee' ? 'bg-blue-100 text-blue-800' :
                       ($release->statut === 'echouee' ? 'bg-rose-100 text-rose-800' :
                       ($release->statut === 'rollbackee' ? 'bg-pink-100 text-pink-800' : 'bg-slate-100 text-slate-700')))) }}">
                    {{ $release->statut_libelle }}
                </span>
            </div>
            <p class="mt-1 text-xs text-slate-500">
                Build: <span class="font-mono font-semibold text-slate-700">{{ $release->build_number }}</span> •
                Environnement: <span class="font-semibold text-slate-700">{{ ucfirst($release->environnement) }}</span> •
                Créée le {{ $release->created_at->format('d/m/Y à H:i') }} par {{ $release->createur?->name ?? 'Système' }}
            </p>
        </div>

        {{-- Actions rapides selon l'état --}}
        <div class="flex flex-wrap items-center gap-2">
            @if(in_array($release->statut, ['preparee', 'testee', 'approuvee']))
                <a href="{{ route('admin.versioning.deployments.create', $release) }}" class="inline-flex items-center gap-1.5 rounded-xl bg-emerald-600 px-4 py-2.5 text-sm font-bold text-white shadow-sm hover:bg-emerald-700 min-h-[42px]">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                    </svg>
                    Déployer cette release
                </a>
            @endif

            @if($release->estDeployee() && $release->releasePrecedente)
                <a href="{{ route('admin.versioning.rollback.plan', $release->releasePrecedente) }}" class="inline-flex items-center gap-1.5 rounded-xl border border-rose-300 bg-rose-50 px-3.5 py-2 text-sm font-bold text-rose-700 hover:bg-rose-100 min-h-[42px]">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12.066 11.2a1 1 0 000 1.6l5.334 4A1 1 0 0019 16V8a1 1 0 00-1.6-.8l-5.333 4zM4.066 11.2a1 1 0 000 1.6l5.334 4A1 1 0 0011 16V8a1 1 0 00-1.6-.8l-5.334 4z" />
                    </svg>
                    Rollback vers v{{ $release->releasePrecedente->version }}
                </a>
            @endif

            @if($manifest)
                <a href="{{ route('admin.versioning.manifest', $release->version) }}" class="inline-flex items-center gap-1.5 rounded-xl border border-slate-300 bg-white px-3.5 py-2 text-sm font-semibold text-slate-700 shadow-sm hover:bg-slate-50 min-h-[42px]">
                    <svg class="h-4 w-4 text-slate-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
                    </svg>
                    Manifest JSON
                </a>
            @endif
        </div>
    </div>

    {{-- Messages flash --}}
    @if(session('succes'))
        <div class="mt-6 rounded-xl bg-emerald-50 border border-emerald-200 p-4 text-sm font-medium text-emerald-800">
            {{ session('succes') }}
        </div>
    @endif
    @if(session('erreur'))
        <div class="mt-6 rounded-xl bg-rose-50 border border-rose-200 p-4 text-sm font-medium text-rose-800">
            {{ session('erreur') }}
        </div>
    @endif

    {{-- Bandeau transitions de statut contrôlées --}}
    @php
        $transitions = \App\Modules\Versioning\Models\Release::TRANSITIONS[$release->statut] ?? [];
    @endphp
    @if(count($transitions) > 0)
        <div class="mt-6 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm flex flex-col sm:flex-row sm:items-center justify-between gap-3">
            <div>
                <span class="text-xs font-bold uppercase tracking-wider text-slate-400">Cycle de validation</span>
                <p class="text-xs text-slate-600">Transitions formelles autorisées depuis l'état actuel :</p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                @foreach($transitions as $cible)
                    <form method="POST" action="{{ route('admin.versioning.releases.transition', $release) }}">
                        @csrf
                        <input type="hidden" name="statut" value="{{ $cible }}">
                        <button type="submit" class="rounded-xl border border-slate-300 bg-slate-50 px-3 py-1.5 text-xs font-bold text-slate-700 hover:bg-slate-100 hover:border-slate-400 transition">
                            Passer à &laquo; {{ ucfirst(str_replace('_', ' ', $cible)) }} &raquo;
                        </button>
                    </form>
                @endforeach
            </div>
        </div>
    @endif

    {{-- Grille des composants et métadonnées --}}
    <div class="mt-6 grid grid-cols-1 lg:grid-cols-3 gap-6">

        {{-- Composants --}}
        <div class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
            <h3 class="text-sm font-bold uppercase tracking-wider text-slate-500 mb-4">Composants de la version</h3>
            <div class="space-y-3">
                <div class="flex items-center justify-between border-b border-slate-100 pb-2">
                    <span class="text-xs text-slate-500">Version Application (APP)</span>
                    <span class="font-mono text-sm font-bold text-slate-900">{{ $release->app_version }}</span>
                </div>
                <div class="flex items-center justify-between border-b border-slate-100 pb-2">
                    <span class="text-xs text-slate-500">DB Release (Logique SemVer)</span>
                    <span class="font-mono text-sm font-bold text-slate-900">{{ $release->db_release ?? $release->db_version }}</span>
                </div>
                <div class="flex items-center justify-between border-b border-slate-100 pb-2">
                    <span class="text-xs text-slate-500">DB Schema (État technique)</span>
                    <span class="font-mono text-sm font-semibold text-slate-700">{{ $release->db_schema_texte }}</span>
                </div>
                <div class="flex items-center justify-between border-b border-slate-100 pb-2">
                    <span class="text-xs text-slate-500">Version DEV</span>
                    <span class="font-mono text-sm font-semibold text-slate-700">{{ $release->dev_version ?? '—' }}</span>
                </div>
                <div class="flex items-center justify-between">
                    <span class="text-xs text-slate-500">Version PROD cible</span>
                    <span class="font-mono text-sm font-semibold text-slate-700">{{ $release->prod_version ?? '—' }}</span>
                </div>
            </div>
        </div>

        {{-- Intégrité & Empreintes --}}
        <div class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
            <h3 class="text-sm font-bold uppercase tracking-wider text-slate-500 mb-4">Empreintes & Sécurité</h3>
            <div class="space-y-3">
                <div>
                    <span class="text-xs text-slate-400">Hash Manifest (SHA-256)</span>
                    <p class="font-mono text-xs text-slate-800 truncate" title="{{ $release->manifest_hash }}">
                        {{ $release->manifest_hash ?? 'Généré au déploiement' }}
                    </p>
                </div>
                <div>
                    <span class="text-xs text-slate-400">Hash Compatibilité</span>
                    <p class="font-mono text-xs text-slate-800 truncate" title="{{ $release->compatibility_hash }}">
                        {{ $release->compatibility_hash ?? 'Calculé au déploiement' }}
                    </p>
                </div>
                <div>
                    <span class="text-xs text-slate-400">Release précédente de référence</span>
                    <p class="text-xs font-semibold text-slate-800">
                        @if($release->releasePrecedente)
                            <a href="{{ route('admin.versioning.releases.show', $release->releasePrecedente) }}" class="text-primary-700 hover:underline">
                                v{{ $release->releasePrecedente->version }}
                            </a>
                        @else
                            <span class="text-slate-400">Première release (Racine)</span>
                        @endif
                    </p>
                </div>
            </div>
        </div>

        {{-- Acteurs & Dates --}}
        <div class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
            <h3 class="text-sm font-bold uppercase tracking-wider text-slate-500 mb-4">Traçabilité & Acteurs</h3>
            <div class="space-y-3 text-xs">
                <div class="flex items-center justify-between border-b border-slate-100 pb-2">
                    <span class="text-slate-500">Créé par</span>
                    <span class="font-semibold text-slate-800">{{ $release->createur?->name ?? 'Système' }}</span>
                </div>
                <div class="flex items-center justify-between border-b border-slate-100 pb-2">
                    <span class="text-slate-500">Approuvé par</span>
                    <span class="font-semibold text-slate-800">{{ $release->approbateur?->name ?? 'En attente' }}</span>
                </div>
                <div class="flex items-center justify-between border-b border-slate-100 pb-2">
                    <span class="text-slate-500">Déployé par</span>
                    <span class="font-semibold text-slate-800">{{ $release->deployeur?->name ?? 'Non déployé' }}</span>
                </div>
                <div class="flex items-center justify-between">
                    <span class="text-slate-500">Déployé le</span>
                    <span class="font-semibold text-slate-800">
                        {{ $release->deployed_at ? $release->deployed_at->format('d/m/Y H:i') : '—' }}
                    </span>
                </div>
            </div>
        </div>

    </div>

    {{-- Notes de release --}}
    <div class="mt-6 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
        <h3 class="text-base font-bold text-slate-900 mb-2">Notes de release & Changelog</h3>
        @if($release->notes)
            <div class="prose max-w-none text-sm text-slate-700 whitespace-pre-line bg-slate-50 rounded-xl p-4">
                {{ $release->notes }}
            </div>
        @else
            <p class="text-xs text-slate-400 italic">Aucune note renseignée pour cette release.</p>
        @endif
    </div>

    {{-- Comparaison rapide avec une autre release --}}
    @if($autresReleases->isNotEmpty())
        <div class="mt-6 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
            <h3 class="text-base font-bold text-slate-900 mb-2">Comparer avec une autre release</h3>
            <form method="GET" action="{{ route('admin.versioning.releases.comparer') }}" class="flex items-center gap-3">
                <input type="hidden" name="source_id" value="{{ $release->id }}">
                <select name="cible_id" class="rounded-xl border border-slate-300 px-3.5 py-2 text-sm focus:border-primary-700 focus:outline-none">
                    @foreach($autresReleases as $ar)
                        <option value="{{ $ar->id }}">Release {{ $ar->version }} ({{ $ar->statut_libelle }})</option>
                    @endforeach
                </select>
                <button type="submit" class="rounded-xl bg-slate-800 px-4 py-2 text-sm font-bold text-white hover:bg-slate-700">
                    Lancer la comparaison
                </button>
            </form>
        </div>
    @endif

</div>
@endsection
