@extends('layouts.app')

@section('title', 'Rapport import — ' . $epreuve->matiere->code)

@section('content')
    <div class="mx-auto max-w-5xl px-4 sm:px-6 py-8">
        <x-page-header :titre="'Rapport d’import — ' . $epreuve->matiere->libelle_complet" :description="'Référence ' . ($rapport->external_reference ?? '—')"
            :fil="['Épreuve' => route('admin.evaluations.epreuves.show', $epreuve), 'Import' => '#']" />

        <div class="mt-4 grid grid-cols-2 gap-4 sm:grid-cols-4">
            <div class="ds-card p-4">
                <p class="text-[11px] font-bold uppercase tracking-wider text-slate-400">Statut</p>
                <p class="mt-1 text-lg font-black">{{ $rapport->status }}</p>
            </div>
            <div class="ds-card p-4">
                <p class="text-[11px] font-bold uppercase tracking-wider text-slate-400">Lignes totales</p>
                <p class="mt-1 text-lg font-black tabular-nums">{{ $rapport->total_rows }}</p>
            </div>
            <div class="ds-card p-4">
                <p class="text-[11px] font-bold uppercase tracking-wider text-slate-400">Importées</p>
                <p class="mt-1 text-lg font-black tabular-nums text-emerald-700">{{ $rapport->valid_rows }}</p>
            </div>
            <div class="ds-card p-4">
                <p class="text-[11px] font-bold uppercase tracking-wider text-slate-400">Durée</p>
                <p class="mt-1 text-lg font-black tabular-nums">{{ $rapport->duration_ms }} ms</p>
            </div>
        </div>

        @if(! empty($rapport->errors))
            <div class="mt-4 overflow-x-auto rounded-2xl border border-rose-200 bg-white shadow-sm">
                <table class="w-full min-w-[640px] text-left text-sm">
                    <thead>
                        <tr class="border-b border-rose-100 text-[11px] uppercase tracking-wider text-rose-400">
                            <th scope="col" class="px-4 py-3 font-bold">Ligne</th>
                            <th scope="col" class="px-4 py-3 font-bold">Champ</th>
                            <th scope="col" class="px-4 py-3 font-bold">Erreur</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-rose-100">
                        @foreach($rapport->errors as $erreur)
                            <tr class="hover:bg-rose-50/60">
                                <td class="px-4 py-3 tabular-nums">{{ $erreur['ligne'] ?? '—' }}</td>
                                <td class="px-4 py-3 font-mono text-xs">{{ $erreur['champ'] ?? '—' }}</td>
                                <td class="px-4 py-3 text-xs">{{ $erreur['message'] ?? '' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        <div class="mt-4">
            <a href="{{ route('admin.evaluations.notes.saisie', $epreuve) }}" class="font-bold text-primary-800 underline underline-offset-2">← Retour à la saisie</a>
        </div>
    </div>
@endsection
