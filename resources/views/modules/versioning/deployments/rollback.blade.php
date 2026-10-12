@extends('layouts.app')

@section('title', 'Plan de Rollback vers ' . $release->version)

@section('content')
<div class="mx-auto max-w-4xl px-4 sm:px-6 lg:px-8 py-8 sm:py-10">

    <div class="flex items-center gap-2 text-xs font-semibold text-slate-500 mb-2">
        <a href="{{ route('admin.versioning.index') }}" class="hover:underline">Versions</a>
        <span>/</span>
        <a href="{{ route('admin.versioning.releases.show', $release) }}" class="hover:underline">Release {{ $release->version }}</a>
        <span>/</span>
        <span class="text-slate-800">Rollback</span>
    </div>

    <div>
        <h1 class="text-2xl sm:text-3xl font-black tracking-tight text-slate-900">
            Plan de retour arrière (Rollback)
        </h1>
        <p class="mt-1 text-sm text-slate-500">
            Retour de la release active <span class="font-bold font-mono text-rose-700">{{ $plan['release_actuelle']->version }}</span> vers la release antérieure <span class="font-bold font-mono text-emerald-700">{{ $release->version }}</span>.
        </p>
    </div>

    @if(session('erreur'))
        <div class="mt-6 rounded-xl bg-rose-50 border border-rose-200 p-4 text-sm font-medium text-rose-800">
            {{ session('erreur') }}
        </div>
    @endif

    {{-- Avertissement de criticité --}}
    <div class="mt-8 rounded-2xl border border-amber-200 bg-amber-50/70 p-6 shadow-sm">
        <div class="flex items-center gap-4">
            <div class="flex h-12 w-12 items-center justify-center rounded-2xl bg-amber-100 text-amber-700">
                <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                </svg>
            </div>
            <div>
                <h2 class="text-base font-bold text-amber-900">
                    Opération de retour arrière sensible
                </h2>
                <p class="text-xs text-amber-800">
                    Une sauvegarde complète sera générée automatiquement avant toute modification. Les composants applicatifs et les versions logiques seront restaurés à l'état de la release cible.
                </p>
            </div>
        </div>
    </div>

    {{-- Étapes prévues du plan --}}
    <div class="mt-6 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
        <h3 class="text-sm font-bold uppercase tracking-wider text-slate-400 mb-3">Étapes programmées</h3>
        <ol class="space-y-2.5 text-xs text-slate-700">
            @foreach($plan['plan'] as $idx => $etape)
                <li class="flex items-start gap-2.5">
                    <span class="flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-slate-100 font-mono text-[10px] font-bold text-slate-600">
                        {{ $idx + 1 }}
                    </span>
                    <span class="mt-0.5">{{ $etape }}</span>
                </li>
            @endforeach
        </ol>
    </div>

    {{-- Analyse des migrations --}}
    @if(count($plan['avertissements']) > 0 || count($plan['bloquants']) > 0)
        <div class="mt-6 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
            <h3 class="text-sm font-bold uppercase tracking-wider text-slate-400 mb-3">Analyse des migrations de base de données (§23)</h3>

            @if(count($plan['bloquants']) > 0)
                <div class="mb-4 rounded-xl bg-rose-50 p-4 border border-rose-200 text-xs text-rose-800">
                    <span class="font-bold block mb-1">Points bloquants :</span>
                    @foreach($plan['bloquants'] as $b)
                        <p>• {{ $b }}</p>
                    @endforeach
                </div>
            @endif

            @if(count($plan['avertissements']) > 0)
                <div class="rounded-xl bg-amber-50 p-4 border border-amber-200 text-xs text-amber-800">
                    <span class="font-bold block mb-1">Mises en garde :</span>
                    @foreach($plan['avertissements'] as $a)
                        <p>• {{ $a }}</p>
                    @endforeach
                </div>
            @endif
        </div>
    @endif

    {{-- Formulaire de validation avec double confirmation --}}
    @if($plan['possible'])
        <form method="POST" action="{{ route('admin.versioning.rollback.execute', $release) }}" class="mt-8 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
            @csrf

            <h3 class="text-base font-bold text-slate-900 mb-1">Double confirmation obligatoire (§42)</h3>
            <p class="text-xs text-slate-500 mb-4">
                Pour autoriser le retour arrière vers la release <span class="font-bold text-slate-800">{{ $release->version }}</span>, tapez <span class="font-mono font-bold text-rose-700 bg-rose-50 px-1 py-0.5 rounded">ROLLBACK</span> ci-dessous :
            </p>

            <div class="mb-4">
                <input type="text" name="confirmation" placeholder="Tapez ROLLBACK" required autocomplete="off"
                       class="w-full sm:w-80 rounded-xl border border-slate-300 px-3.5 py-2.5 font-mono text-sm focus:border-rose-600 focus:outline-none">
            </div>

            <div class="space-y-3 pt-4 border-t border-slate-100">
                <label class="flex items-center gap-2 cursor-pointer">
                    <input type="checkbox" name="rollback_db" value="1" class="rounded border-slate-300 text-rose-600 focus:ring-rose-500">
                    <span class="text-xs font-semibold text-slate-700">Appliquer également le rollback des tables / migrations BD (si applicable)</span>
                </label>
            </div>

            <div class="mt-6 flex items-center justify-between">
                <a href="{{ route('admin.versioning.releases.show', $release) }}" class="rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 hover:bg-slate-50">
                    Annuler
                </a>
                <button type="submit" class="rounded-xl bg-rose-600 px-6 py-2.5 text-sm font-bold text-white shadow-sm hover:bg-rose-700">
                    Confirmer le Rollback
                </button>
            </div>
        </form>
    @else
        <div class="mt-8 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm text-center">
            <p class="text-sm font-bold text-rose-700">Le rollback automatique est actuellement verrouillé pour des raisons de sécurité.</p>
            <div class="mt-4">
                <a href="{{ route('admin.versioning.releases.show', $release) }}" class="rounded-xl border border-slate-300 bg-white px-4 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-50">
                    &larr; Revenir à la fiche
                </a>
            </div>
        </div>
    @endif

</div>
@endsection
