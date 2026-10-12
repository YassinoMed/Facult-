@extends('layouts.app')

@section('title', 'Déploiement de la release ' . $release->version)

@section('content')
<div class="mx-auto max-w-4xl px-4 sm:px-6 lg:px-8 py-8 sm:py-10">

    <div class="flex items-center gap-2 text-xs font-semibold text-slate-500 mb-2">
        <a href="{{ route('admin.versioning.index') }}" class="hover:underline">Versions</a>
        <span>/</span>
        <a href="{{ route('admin.versioning.releases.show', $release) }}" class="hover:underline">Release {{ $release->version }}</a>
        <span>/</span>
        <span class="text-slate-800">Déploiement</span>
    </div>

    <div>
        <h1 class="text-2xl sm:text-3xl font-black tracking-tight text-slate-900">
            Contrôle pré-déploiement : Release {{ $release->version }}
        </h1>
        <p class="mt-1 text-sm text-slate-500">
            Vérification formelle des 10 points de conformité (§16) avant bascule locale.
        </p>
    </div>

    @if(session('erreur'))
        <div class="mt-6 rounded-xl bg-rose-50 border border-rose-200 p-4 text-sm font-medium text-rose-800">
            {{ session('erreur') }}
        </div>
    @endif

    {{-- Bannière statut autorisation --}}
    <div class="mt-8 rounded-2xl border {{ $checklist['autorise'] ? 'border-emerald-200 bg-emerald-50/60' : 'border-rose-200 bg-rose-50/60' }} p-6 shadow-sm">
        <div class="flex items-center gap-4">
            <div class="flex h-12 w-12 items-center justify-center rounded-2xl {{ $checklist['autorise'] ? 'bg-emerald-100 text-emerald-700' : 'bg-rose-100 text-rose-700' }}">
                @if($checklist['autorise'])
                    <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                    </svg>
                @else
                    <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                @endif
            </div>
            <div>
                <h2 class="text-lg font-bold {{ $checklist['autorise'] ? 'text-emerald-900' : 'text-rose-900' }}">
                    {{ $checklist['autorise'] ? 'DÉPLOIEMENT AUTORISÉ' : 'DÉPLOIEMENT BLOQUÉ — Incompatibilités détectées' }}
                </h2>
                <p class="text-xs {{ $checklist['autorise'] ? 'text-emerald-700' : 'text-rose-700' }}">
                    Statut : {{ ucfirst(str_replace('_', ' ', $checklist['statut'])) }} • {{ count($checklist['bloquants']) }} point(s) bloquant(s), {{ count($checklist['avertissements']) }} avertissement(s).
                </p>
            </div>
        </div>
    </div>

    {{-- Les 10 points de contrôle --}}
    <div class="mt-6 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
        <h3 class="text-sm font-bold uppercase tracking-wider text-slate-400 mb-4">Grille des 10 points de contrôle (§16)</h3>

        <div class="divide-y divide-slate-100">
            @foreach($checklist['points_controle'] as $cle => $point)
                <div class="py-3 flex items-start justify-between gap-3">
                    <div class="flex items-start gap-2.5">
                        <span class="mt-0.5 flex h-5 w-5 shrink-0 items-center justify-center rounded-full {{ $point['succes'] ? 'bg-emerald-100 text-emerald-700' : 'bg-rose-100 text-rose-700' }}">
                            @if($point['succes'])
                                <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7" /></svg>
                            @else
                                <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M6 18L18 6M6 6l12 12" /></svg>
                            @endif
                        </span>
                        <div>
                            <span class="text-xs font-bold text-slate-800">{{ ucwords(str_replace('_', ' ', $cle)) }}</span>
                            <p class="text-xs text-slate-500">{{ $point['message'] }}</p>
                        </div>
                    </div>

                    <span class="text-[11px] font-semibold uppercase {{ $point['succes'] ? 'text-emerald-700' : 'text-rose-700' }}">
                        {{ $point['succes'] ? 'Conforme' : 'Échec' }}
                    </span>
                </div>
            @endforeach
        </div>
    </div>

    {{-- Formulaire de déploiement et double confirmation --}}
    <form method="POST" action="{{ route('admin.versioning.deployments.store', $release) }}" class="mt-8 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
        @csrf

        <h3 class="text-base font-bold text-slate-900 mb-1">Double confirmation obligatoire (§42)</h3>
        <p class="text-xs text-slate-500 mb-4">
            Pour valider le déploiement sur l'environnement <span class="font-bold text-slate-800">{{ ucfirst($release->environnement) }}</span>, tapez <span class="font-mono font-bold text-rose-700 bg-rose-50 px-1 py-0.5 rounded">DEPLOYER</span> ci-dessous :
        </p>

        <div class="mb-4">
            <input type="text" name="confirmation" placeholder="Tapez DEPLOYER" required autocomplete="off"
                   class="w-full sm:w-80 rounded-xl border border-slate-300 px-3.5 py-2.5 font-mono text-sm focus:border-rose-600 focus:outline-none">
        </div>

        <div class="space-y-3 pt-4 border-t border-slate-100">
            <label class="flex items-center gap-2 cursor-pointer">
                <input type="checkbox" name="sans_backup" value="1" class="rounded border-slate-300 text-rose-600 focus:ring-rose-500">
                <span class="text-xs font-medium text-slate-700">Ignorer la sauvegarde de sécurité préalable (Déconseillé)</span>
            </label>

            @if(! $checklist['autorise'])
                <label class="flex items-center gap-2 cursor-pointer bg-rose-50 p-2.5 rounded-xl border border-rose-200">
                    <input type="checkbox" name="forcer" value="1" required class="rounded border-rose-300 text-rose-600 focus:ring-rose-500">
                    <span class="text-xs font-bold text-rose-900">Forcer le déploiement malgré les points de contrôle bloquants</span>
                </label>
            @endif
        </div>

        <div class="mt-6 flex items-center justify-between">
            <a href="{{ route('admin.versioning.releases.show', $release) }}" class="rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 hover:bg-slate-50">
                Annuler
            </a>
            <button type="submit" class="rounded-xl bg-emerald-600 px-6 py-2.5 text-sm font-bold text-white shadow-sm hover:bg-emerald-700">
                Exécuter le déploiement
            </button>
        </div>
    </form>

</div>
@endsection
