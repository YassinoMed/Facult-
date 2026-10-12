@extends('layouts.app')

@section('title', 'Lot d’affectation ' . $lot->reference)

@section('content')
<div class="mx-auto max-w-4xl px-4 sm:px-6 py-8">
    @if(in_array($lot->statut, ['en_attente', 'en_cours'], true))
        <meta http-equiv="refresh" content="5">
    @endif

    <x-page-header
        titre="Lot {{ $lot->reference }}"
        description="Exécution par tranches : {{ $lot->total }} sélection(s) — {{ $lot->anneeUniversitaire?->libelle }}. Actualisation automatique pendant l'exécution."
        :fil="['Gestion académique' => '#', 'Affectations' => route('admin.affectations.masse'), 'Lot' => '#']">
        <x-slot:actions>
            <a href="{{ route('admin.affectations.masse') }}" class="inline-flex items-center gap-1.5 rounded-xl border border-slate-200 bg-white px-3.5 py-2 text-xs font-semibold text-slate-700 shadow-sm transition hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200">
                Retour au cockpit
            </a>
            @if($lot->estModifiable())
                <form method="POST" action="{{ route('admin.affectations.masse.lots.annuler', $lot) }}" class="inline" onsubmit="return confirm('Annuler ce lot ? Les affectations déjà appliquées sont conservées.')">
                    @csrf
                    <button type="submit" class="inline-flex items-center gap-1.5 rounded-xl border border-rose-200 bg-rose-50 px-3.5 py-2 text-xs font-bold text-rose-700 shadow-sm transition hover:bg-rose-100 dark:border-rose-900/60 dark:bg-rose-950/40 dark:text-rose-300">
                        Annuler le lot
                    </button>
                </form>
            @endif
        </x-slot:actions>
    </x-page-header>

    <div class="mt-6 grid grid-cols-2 gap-4 sm:grid-cols-4">
        <div class="rounded-2xl border border-slate-200/80 bg-white p-4 shadow-sm dark:border-slate-800 dark:bg-slate-900">
            <p class="text-[11px] font-bold uppercase tracking-wider text-slate-400">Statut</p>
            <p class="mt-1 text-lg font-black text-slate-900 dark:text-white">{{ $lot->statut }}</p>
        </div>
        <div class="rounded-2xl border border-slate-200/80 bg-white p-4 shadow-sm dark:border-slate-800 dark:bg-slate-900">
            <p class="text-[11px] font-bold uppercase tracking-wider text-slate-400">Progression</p>
            <p class="mt-1 text-lg font-black tabular-nums text-slate-900 dark:text-white">{{ $progression['processed'] }} / {{ $progression['total'] }} ({{ $progression['percentage'] }} %)</p>
        </div>
        <div class="rounded-2xl border border-slate-200/80 bg-white p-4 shadow-sm dark:border-slate-800 dark:bg-slate-900">
            <p class="text-[11px] font-bold uppercase tracking-wider text-emerald-600">Affectés</p>
            <p class="mt-1 text-lg font-black tabular-nums text-emerald-600">{{ $progression['success'] }}</p>
        </div>
        <div class="rounded-2xl border border-slate-200/80 bg-white p-4 shadow-sm dark:border-slate-800 dark:bg-slate-900">
            <p class="text-[11px] font-bold uppercase tracking-wider text-amber-500">Ignorés / reportés</p>
            <p class="mt-1 text-lg font-black tabular-nums text-amber-500">{{ $progression['blocked'] }}</p>
        </div>
    </div>

    <div class="mt-4 h-2.5 overflow-hidden rounded-full bg-slate-200 dark:bg-slate-700" role="progressbar" aria-valuenow="{{ $progression['percentage'] }}" aria-valuemin="0" aria-valuemax="100" aria-label="Progression du lot">
        <div class="h-full rounded-full bg-primary-600 transition-all" style="width: {{ $progression['percentage'] }}%"></div>
    </div>

    @if($lot->statut === 'termine')
        <div class="mt-4 rounded-2xl border border-emerald-200 bg-emerald-50/80 p-4 text-xs text-emerald-900 dark:border-emerald-900/60 dark:bg-emerald-950/30 dark:text-emerald-200" role="status">
            ✓ Lot terminé : {{ $lot->affectes }} affectation(s), {{ $lot->ignores }} ignorée(s).
            <a href="{{ route('admin.affectations.propositions.index') }}" class="font-bold underline">Voir les propositions</a>
        </div>
    @endif

    @if($lot->statut === 'echoue')
        <div class="mt-4 rounded-2xl border border-rose-200 bg-rose-50/80 p-4 text-xs text-rose-900 dark:border-rose-900/60 dark:bg-rose-950/30 dark:text-rose-200" role="alert">
            ✕ Lot en échec : {{ $lot->message ?? 'erreur technique' }}. Relancez un nouveau lot (les lignes déjà traitées sont idempotentes).
        </div>
    @endif

    @if(!empty($groupes))
        <p class="mt-4 text-xs text-slate-500">Groupes concernés : {{ implode(', ', $groupes) }}</p>
    @endif

    @if(!empty($lot->erreurs))
        <details class="mt-4 rounded-2xl border border-slate-200/80 bg-white p-4 text-xs dark:border-slate-800 dark:bg-slate-900">
            <summary class="cursor-pointer font-bold text-slate-700 dark:text-slate-300">Détail des reports ({{ count($lot->erreurs) }})</summary>
            <ul class="mt-2 list-disc list-inside space-y-0.5 text-slate-600 dark:text-slate-400">
                @foreach(array_slice($lot->erreurs, 0, 20, true) as $idEtudiant => $message)
                    <li><span class="font-mono">#{{ $idEtudiant }}</span> — {{ $message }}</li>
                @endforeach
            </ul>
        </details>
    @endif
</div>
@endsection
