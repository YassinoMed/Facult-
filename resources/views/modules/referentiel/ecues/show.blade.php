@extends('layouts.app')

@section('title', $ecue->code . ' — ECUE')

@section('content')
    <div class="mx-auto max-w-4xl px-4 sm:px-6 py-8">
        <x-page-header :titre="$ecue->code . ' — ' . $ecue->nom" :description="'ECUE · UE ' . $ecue->ue->code . ' · ' . $ecue->volumeTotal() . 'h'"
            :fil="['Référentiel' => route('admin.referentiel.tableau-bord'), 'ECUE' => route('admin.referentiel.ecues.index'), $ecue->code => '#']">
            <x-slot:actions>
                <x-btn variant="secondary" href="{{ route('admin.listes.preview', ['matiere_id' => $ecue->id]) }}">Étudiants inscrits</x-btn>
                @can('data.export')
                    <x-btn variant="ghost" href="{{ route('admin.listes.exporter', ['format' => 'pdf', 'matiere_id' => $ecue->id, 'regroupement' => 'groupe']) }}">PDF par groupe</x-btn>
                @endcan
                @can('referentiel.manage')
                    <x-btn variant="secondary" href="{{ route('admin.referentiel.ecues.edit', $ecue) }}">Modifier (volumes, évaluation)</x-btn>
                @endcan
            </x-slot:actions>
        </x-page-header>

        <div class="mt-4 grid grid-cols-1 sm:grid-cols-2 gap-3">
            <div class="ds-card p-5">
                <h2 class="text-sm font-black uppercase tracking-wider text-slate-500">Volumes horaires</h2>
                <dl class="mt-2 text-sm tabular-nums">
                    <div class="flex justify-between py-1 border-b border-slate-100"><dt>CM</dt><dd class="font-bold">{{ $ecue->volume_cm }}h</dd></div>
                    <div class="flex justify-between py-1 border-b border-slate-100"><dt>TD</dt><dd class="font-bold">{{ $ecue->volume_td }}h</dd></div>
                    <div class="flex justify-between py-1 border-b border-slate-100"><dt>TP</dt><dd class="font-bold">{{ $ecue->volume_tp }}h</dd></div>
                    <div class="flex justify-between py-1"><dt class="font-black">Total</dt><dd class="font-black">{{ $ecue->volumeTotal() }}h</dd></div>
                </dl>
                <p class="mt-2 text-xs text-slate-500">Évaluation : {{ $ecue->type_evaluation ?? '—' }}</p>
                @if($ecue->description)
                    <p class="mt-2 text-xs text-slate-600">{{ $ecue->description }}</p>
                @endif
            </div>
            <div class="ds-card p-5">
                <h2 class="text-sm font-black uppercase tracking-wider text-slate-500">Prérequis ({{ $ecue->prerequis->count() }})</h2>
                @if($ecue->prerequis->isNotEmpty())
                    <ul class="mt-2 space-y-1.5 text-sm">
                        @foreach($ecue->prerequis as $prerequis)
                            <li class="flex items-center justify-between gap-2 rounded-xl border border-slate-100 px-3 py-2">
                                <span class="font-mono font-bold">{{ $prerequis->code }}</span>
                                @can('referentiel.manage')
                                    <form method="POST" action="{{ route('admin.referentiel.ecues.prerequis.destroy', [$ecue, $prerequis]) }}" class="inline">
                                        @csrf @method('DELETE')
                                        <button type="submit" aria-label="Retirer {{ $prerequis->code }}" class="text-rose-700 font-bold min-h-[32px] min-w-[32px]">✕</button>
                                    </form>
                                @endcan
                            </li>
                        @endforeach
                    </ul>
                @else
                    <p class="mt-2 text-xs text-slate-500">Aucun prérequis.</p>
                @endif
                @can('referentiel.manage')
                    <form method="POST" action="{{ route('admin.referentiel.ecues.prerequis.store', $ecue) }}" class="mt-3 flex gap-2">
                        @csrf
                        <label for="prerequis_id" class="sr-only">ECUE prérequise</label>
                        <input id="prerequis_id" name="prerequis_id" type="number" required placeholder="ID matière" class="flex-1 rounded-xl border border-slate-300 px-3 py-2 text-sm min-h-[44px]">
                        <x-btn type="submit" variant="secondary">Ajouter</x-btn>
                    </form>
                    @error('prerequis')
                        <p class="mt-1 text-xs font-semibold text-rose-700" role="alert">⚠ {{ $message }}</p>
                    @enderror
                @endcan
                @if($ecue->requisePar->isNotEmpty())
                    <p class="mt-3 text-[11px] text-slate-500">Exigée par : {{ $ecue->requisePar->pluck('code')->implode(', ') }}</p>
                @endif
            </div>
        </div>
    </div>
@endsection
