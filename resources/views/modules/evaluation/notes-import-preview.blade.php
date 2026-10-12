@extends('layouts.app')

@section('title', 'Prévisualisation import — ' . $epreuve->matiere->code)

@section('content')
    <div class="mx-auto max-w-5xl px-4 sm:px-6 py-8">
        <x-page-header :titre="'Import multi-format — ' . $epreuve->matiere->libelle_complet" :description="'Prévisualisation sans écriture · format détecté : ' . $format"
            :fil="['Épreuve' => route('admin.evaluations.epreuves.show', $epreuve), 'Import' => '#']" />

        <div class="mt-4 grid grid-cols-2 gap-4 sm:grid-cols-4">
            <div class="ds-card p-4">
                <p class="text-[11px] font-bold uppercase tracking-wider text-slate-400">Lignes</p>
                <p class="mt-1 text-lg font-black tabular-nums">{{ $resultat->total() }}</p>
            </div>
            <div class="ds-card p-4">
                <p class="text-[11px] font-bold uppercase tracking-wider text-slate-400">Valides</p>
                <p class="mt-1 text-lg font-black tabular-nums text-emerald-700">{{ count($resultat->valides) }}</p>
            </div>
            <div class="ds-card p-4">
                <p class="text-[11px] font-bold uppercase tracking-wider text-slate-400">Erreurs</p>
                <p class="mt-1 text-lg font-black tabular-nums text-rose-700">{{ count($resultat->erreurs) }}</p>
            </div>
            <div class="ds-card p-4">
                <p class="text-[11px] font-bold uppercase tracking-wider text-slate-400">Avertissements</p>
                <p class="mt-1 text-lg font-black tabular-nums">{{ count($resultat->avertissements) }}</p>
            </div>
        </div>

        @if($resultat->avertissements !== [])
            <div class="mt-4 rounded-2xl border border-amber-200 bg-amber-50 p-4 text-xs text-amber-900">
                <ul class="list-disc pl-5 space-y-1">
                    @foreach($resultat->avertissements as $avertissement)
                        <li>{{ $avertissement }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        @if($resultat->apercu !== [])
            <div class="mt-4 overflow-x-auto rounded-2xl border border-slate-200 bg-white shadow-sm">
                <table class="w-full min-w-[640px] text-left text-sm">
                    <thead>
                        <tr class="border-b border-slate-100 text-[11px] uppercase tracking-wider text-slate-400">
                            <th scope="col" class="px-4 py-3 font-bold">Ligne</th>
                            <th scope="col" class="px-4 py-3 font-bold">Compostage</th>
                            <th scope="col" class="px-4 py-3 font-bold text-right">Note</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach($resultat->apercu as $ligne)
                            <tr class="hover:bg-slate-50/60">
                                <td class="px-4 py-3 tabular-nums">{{ $ligne['ligne'] ?? '—' }}</td>
                                <td class="px-4 py-3 font-mono font-bold">{{ $ligne['compostage'] ?? '—' }}</td>
                                <td class="px-4 py-3 text-right tabular-nums">{{ $ligne['note'] ?? '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        @if($resultat->erreurs !== [])
            <div class="mt-4 overflow-x-auto rounded-2xl border border-rose-200 bg-white shadow-sm">
                <table class="w-full min-w-[640px] text-left text-sm">
                    <thead>
                        <tr class="border-b border-rose-100 text-[11px] uppercase tracking-wider text-rose-400">
                            <th scope="col" class="px-4 py-3 font-bold">Ligne</th>
                            <th scope="col" class="px-4 py-3 font-bold">Champ</th>
                            <th scope="col" class="px-4 py-3 font-bold">Erreur</th>
                            <th scope="col" class="px-4 py-3 font-bold">Code</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-rose-100">
                        @foreach($resultat->erreurs as $erreur)
                            <tr class="hover:bg-rose-50/60">
                                <td class="px-4 py-3 tabular-nums">{{ $erreur['ligne'] }}</td>
                                <td class="px-4 py-3 font-mono text-xs">{{ $erreur['champ'] }}</td>
                                <td class="px-4 py-3 text-xs">{{ $erreur['message'] }}</td>
                                <td class="px-4 py-3 font-mono text-xs font-bold">{{ $erreur['code'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        <div class="mt-4 flex flex-wrap items-center gap-3">
            <form method="post" action="{{ route('admin.evaluations.notes.import-multi-annuler', $epreuve) }}">
                @csrf
                <input type="hidden" name="token" value="{{ $token }}" />
                <x-btn type="submit" variant="secondary">Annuler</x-btn>
            </form>
            @if($resultat->valides !== [])
                <form method="post" action="{{ route('admin.evaluations.notes.import-multi-confirmer', $epreuve) }}" class="flex flex-wrap items-center gap-3">
                    @csrf
                    <input type="hidden" name="token" value="{{ $token }}" />
                    <label class="text-xs font-bold text-slate-600">Mode
                        <select name="mode" class="ml-1 rounded-xl border border-slate-200 px-2 py-1.5 text-xs">
                            <option value="upsert">Mettre à jour l’existant</option>
                            <option value="creer">Créer uniquement</option>
                        </select>
                    </label>
                    <x-btn type="submit">Confirmer l’import ({{ count($resultat->valides) }} ligne(s))</x-btn>
                </form>
            @else
                <p class="text-xs font-bold text-rose-700">Aucune ligne valide : corrigez le fichier puis recommencez.</p>
            @endif
        </div>
    </div>
@endsection
