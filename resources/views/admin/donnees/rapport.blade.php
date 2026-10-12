@extends('layouts.app')

@section('title', 'Rapport d’import — Scolarité')

@section('content')
    <div class="mx-auto max-w-3xl px-4 sm:px-6 py-8">
        <x-page-header titre="Import terminé" :description="'Référence ' . $rapport->external_reference" :fil="['Données' => route('admin.donnees.hub'), 'Rapport' => '#']" />

        <div class="mt-4 ds-card p-6 text-sm">
            <dl class="grid grid-cols-2 gap-2 text-xs">
                <div class="rounded-xl bg-slate-50 border border-slate-200 p-3">
                    <dt class="text-slate-500">Importées</dt>
                    <dd class="text-xl font-black tabular-nums text-emerald-700">{{ $rapport->valid_rows }}</dd>
                </div>
                <div class="rounded-xl bg-slate-50 border border-slate-200 p-3">
                    <dt class="text-slate-500">Erreurs</dt>
                    <dd class="text-xl font-black tabular-nums text-rose-700">{{ $rapport->error_rows }}</dd>
                </div>
                <div class="rounded-xl bg-slate-50 border border-slate-200 p-3">
                    <dt class="text-slate-500">Fichier</dt>
                    <dd class="font-mono truncate">{{ $rapport->filename }} ({{ $rapport->format }})</dd>
                </div>
                <div class="rounded-xl bg-slate-50 border border-slate-200 p-3">
                    <dt class="text-slate-500">Durée</dt>
                    <dd class="tabular-nums">{{ $rapport->duration_ms }} ms</dd>
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

        <div class="mt-4 flex flex-col sm:flex-row gap-2">
            @if(in_array($entite, \App\Services\DataExchange\DefinitionRegistry::exportables(), true))
            <a href="{{ route('admin.donnees.voir', $entite) }}" class="inline-flex items-center justify-center gap-2 px-4 py-2.5 rounded-xl bg-primary-800 text-white font-bold text-xs hover:bg-primary-700 shadow-sm transition">
                <svg class="w-4 h-4 text-sky-300" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z" />
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                </svg>
                <span>Vérifier les données importées</span>
            </a>
            @endif
            <x-btn href="{{ route('admin.donnees.hub') }}" variant="secondary">Nouvel import</x-btn>
            <x-btn href="{{ route('admin.donnees.historique') }}" variant="ghost">Voir l’historique</x-btn>
            @if(!empty($rapport->errors))
                <x-btn href="{{ route('admin.donnees.rapport.erreurs', [$rapport, 'csv']) }}" variant="ghost">Télécharger le rapport d'erreurs (CSV)</x-btn>
            @endif
        </div>
    </div>
@endsection
