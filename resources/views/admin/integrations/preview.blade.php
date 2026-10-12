@extends('layouts.app')

@section('title', 'Prévisualisation d\'import — Intégrations')
@section('meta_description', 'Confirmation d\'un import : lignes valides, doublons et erreurs détectés.')

@section('content')
<div class="mx-auto max-w-3xl px-4 sm:px-6 lg:px-8 py-10 sm:py-14">

    <div class="mb-8">
        <a href="{{ route('admin.integrations.index') }}"
           class="inline-flex items-center gap-1.5 text-xs font-bold text-slate-500 hover:text-primary-600 transition-smooth">
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2.2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5 3 12m0 0 7.5-7.5M3 12h18" /></svg>
            Intégrations
        </a>
        <h1 class="mt-3 text-2xl sm:text-3xl font-black tracking-tight text-slate-900">Prévisualisation de l'import</h1>
        <p class="mt-2 text-sm text-slate-500">
            Rapport <span class="font-mono font-bold text-slate-700">{{ $rapport->reference }}</span> —
            {{ $rapport->type === 'students' ? 'étudiants' : 'notes' }} · {{ strtoupper($rapport->format) }} ·
            « {{ $rapport->filename }} ».
            <strong>Rien n'a encore été écrit en base.</strong>
        </p>
    </div>

    {{-- Résumé chiffré (§37) --}}
    @php($preview = $rapport->preview ?? [])
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-6">
        <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-xs">
            <p class="text-[10px] font-bold uppercase tracking-[0.18em] text-slate-400">Lignes analysées</p>
            <p class="mt-1 text-3xl font-black text-slate-900">{{ $preview['total'] ?? 0 }}</p>
        </div>
        <div class="rounded-2xl border border-emerald-200 bg-emerald-50/40 p-5 shadow-xs">
            <p class="text-[10px] font-bold uppercase tracking-[0.18em] text-emerald-600">Valides</p>
            <p class="mt-1 text-3xl font-black text-emerald-700">{{ $preview['valides'] ?? 0 }}</p>
        </div>
        <div class="rounded-2xl border border-amber-200 bg-amber-50/40 p-5 shadow-xs">
            <p class="text-[10px] font-bold uppercase tracking-[0.18em] text-amber-600">Doublons</p>
            <p class="mt-1 text-3xl font-black text-amber-700">{{ $preview['doublons'] ?? 0 }}</p>
        </div>
        <div class="rounded-2xl border border-rose-200 bg-rose-50/40 p-5 shadow-xs">
            <p class="text-[10px] font-bold uppercase tracking-[0.18em] text-rose-600">Erreurs</p>
            <p class="mt-1 text-3xl font-black text-rose-700">{{ $preview['erreurs'] ?? 0 }}</p>
        </div>
    </div>

    {{-- Erreurs détaillées --}}
    @if(!empty($preview['detail_erreurs']))
        <div class="mb-6 rounded-2xl border border-rose-200 bg-rose-50/60 p-5">
            <h3 class="mb-3 text-xs font-bold uppercase tracking-wider text-rose-700">Détail des erreurs ({{ count($preview['detail_erreurs']) }} affichée(s))</h3>
            <ul class="space-y-1.5 text-xs text-rose-800">
                @foreach(array_slice($preview['detail_erreurs'], 0, 10) as $erreur)
                    <li class="font-mono">Ligne {{ $erreur['ligne'] }} · {{ $erreur['champ'] }} : {{ $erreur['message'] }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    {{-- Avertissements / doublons --}}
    @if(!empty($preview['avertissements']))
        <div class="mb-6 rounded-2xl border border-amber-200 bg-amber-50/60 p-5">
            <h3 class="mb-3 text-xs font-bold uppercase tracking-wider text-amber-700">Doublons détectés ({{ count($preview['avertissements']) }})</h3>
            <ul class="space-y-1.5 text-xs text-amber-800 max-h-40 overflow-y-auto">
                @foreach(array_slice($preview['avertissements'], 0, 10) as $avertissement)
                    <li>{{ $avertissement }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    {{-- Confirmation (§39 : choix explicite du mode) --}}
    @if($rapport->status === 'pending')
        <div class="rounded-3xl border border-slate-200/90 bg-white p-6 sm:p-8 shadow-xl">
            <h3 class="text-sm font-bold text-slate-900">Confirmer l'import de {{ $preview['valides'] ?? 0 }} ligne(s)</h3>
            <p class="mt-1.5 text-xs text-slate-500 leading-relaxed">
                <strong class="text-slate-700">Mode atomique</strong> : si une erreur survient pendant l'exécution, tout est annulé (rollback complet).
                <br>
                <strong class="text-slate-700">Mode partiel</strong> : seules les lignes valides sont importées, les autres sont ignorées et rapportées.
            </p>

            <div class="mt-5 flex flex-col sm:flex-row gap-3">
                <a href="{{ route('admin.integrations.index') }}"
                   class="rounded-xl border border-slate-200 px-5 py-3 text-center text-sm font-bold text-slate-600 hover:bg-slate-50 transition-smooth">Annuler</a>
                <form method="POST" action="{{ route('admin.integrations.confirm', $rapport) }}" class="flex-1 flex flex-col sm:flex-row gap-3">
                    @csrf
                    <button type="submit" name="mode" value="atomique"
                            class="flex-1 rounded-xl bg-slate-900 px-5 py-3 text-sm font-bold text-white hover:bg-slate-800 transition-smooth">
                        Importer {{ $preview['valides'] ?? 0 }} ligne(s) — Mode atomique
                    </button>
                    <button type="submit" name="mode" value="partiel"
                            class="flex-1 rounded-xl border border-slate-300 px-5 py-3 text-sm font-bold text-slate-700 hover:bg-slate-50 transition-smooth">
                        Mode partiel (ignorer les invalides)
                    </button>
                </form>
            </div>
        </div>
    @else
        <div class="rounded-2xl border border-slate-200 bg-white p-5 text-sm text-slate-500">
            Ce rapport n'est plus en attente (statut : <strong>{{ $rapport->statut_libelle }}</strong>).
        </div>
    @endif
</div>
@endsection
