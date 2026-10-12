@extends('layouts.app')

@section('title', 'Rapport d\'import — Scolarité')

@section('content')
    <div class="mx-auto max-w-3xl px-4 sm:px-6 py-8 sm:py-12">
        <h1 class="text-2xl font-black text-slate-900">Import terminé</h1>

        <div class="mt-4 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm text-sm">
            @if($planning !== null)
                <p>✓ Emploi du temps : <strong>{{ $planning->groupe->code }}</strong></p>
                <p>✓ Semestre : <strong>S{{ $planning->semestre }}</strong></p>
                <p>✓ Année : <strong>{{ $planning->anneeUniversitaire->libelle }}</strong></p>
            @endif
            <dl class="mt-3 grid grid-cols-2 gap-2 text-xs">
                <div class="rounded-xl bg-slate-50 border border-slate-200 p-3">
                    <dt class="text-slate-500">Créneaux importés</dt>
                    <dd class="text-xl font-black tabular-nums">{{ $rapport->successful_rows }}</dd>
                </div>
                <div class="rounded-xl bg-slate-50 border border-slate-200 p-3">
                    <dt class="text-slate-500">Erreurs</dt>
                    <dd class="text-xl font-black tabular-nums">{{ $rapport->failed_rows }}</dd>
                </div>
                <div class="rounded-xl bg-slate-50 border border-slate-200 p-3">
                    <dt class="text-slate-500">Fichier</dt>
                    <dd class="font-mono truncate">{{ $rapport->filename }} ({{ $rapport->format }})</dd>
                </div>
                <div class="rounded-xl bg-slate-50 border border-slate-200 p-3">
                    <dt class="text-slate-500">Statut</dt>
                    <dd class="font-bold">{{ $rapport->status }}</dd>
                </div>
            </dl>
            @if(!empty($rapport->errors))
                <ul class="mt-3 space-y-1 text-xs text-rose-700">
                    @foreach($rapport->errors as $erreur)
                        <li>Ligne {{ $erreur['ligne'] ?? '—' }} — {{ $erreur['message'] ?? '' }}</li>
                    @endforeach
                </ul>
            @endif
        </div>

        <div class="mt-4 flex flex-col sm:flex-row gap-3">
            @if($planning !== null)
                <a href="{{ route('admin.emplois-du-temps.show', $planning) }}"
                   class="inline-flex items-center justify-center rounded-xl bg-primary-800 px-5 py-3 text-sm font-bold text-white hover:bg-primary-700 min-h-[48px]">Voir le planning</a>
            @endif
            <a href="{{ route('admin.emplois-du-temps.index') }}"
               class="inline-flex items-center justify-center rounded-xl border border-slate-300 px-5 py-3 text-sm font-bold text-slate-700 hover:bg-slate-50 min-h-[48px]">Registre</a>
        </div>
    </div>
@endsection
