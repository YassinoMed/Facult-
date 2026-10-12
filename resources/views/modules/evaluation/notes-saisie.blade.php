@extends('layouts.app')

@section('title', 'Saisie des notes — ' . $epreuve->matiere->code)

@section('content')
    <div class="mx-auto max-w-5xl px-4 sm:px-6 py-8 space-y-6">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
            <x-page-header :titre="'Saisie anonyme — ' . $epreuve->matiere->libelle_complet"
                :description="'Barème /' . $epreuve->note_max . ' · Navigation Tab/Entrée · Données strictement anonymisées (CMP-XXXXXX)'"
                :fil="['Épreuve' => route('admin.evaluations.epreuves.show', $epreuve), 'Notes' => '#']" />

            <div class="flex items-center gap-2 flex-wrap">
                @can('notes_eval.enter')
                    <a href="{{ route('admin.evaluations.notes.export-anonyme', $epreuve) }}"
                       class="inline-flex items-center gap-1.5 rounded-xl border border-slate-300 bg-white px-3 py-1.5 text-xs font-bold text-slate-700 shadow-sm hover:bg-slate-50 transition-colors">
                        <svg class="h-3.5 w-3.5 text-slate-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
                        </svg>
                        <span>Export CSV</span>
                    </a>
                @endcan

                @can('notes.compostage.resolve')
                    <a href="{{ route('admin.evaluations.epreuves.correspondance', $epreuve) }}"
                       class="inline-flex items-center gap-1.5 rounded-xl border border-indigo-200 bg-indigo-50 px-3 py-1.5 text-xs font-bold text-indigo-700 shadow-sm hover:bg-indigo-100 transition-colors"
                       title="Accès réservé et audité à la table de correspondance">
                        <span>Correspondance (Sécurisée) →</span>
                    </a>
                @endcan
            </div>
        </div>

        @if(session('succes'))
            <x-alert type="success">{{ session('succes') }}</x-alert>
        @endif

        @if($errors->any())
            <x-alert type="error">{{ $errors->first() }}</x-alert>
        @endif

        {{-- Import rapide CSV anonyme --}}
        @can('notes_eval.import')
            <div x-data="{ ouvert: false }" class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                <button type="button" @click="ouvert = !ouvert" class="flex items-center justify-between w-full text-left text-xs font-bold text-slate-700">
                    <span class="flex items-center gap-2">
                        <svg class="h-4 w-4 text-slate-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"/>
                        </svg>
                        Import par lot de notes anonymes (CSV)
                    </span>
                    <span x-text="ouvert ? 'Fermer ▲' : 'Ouvrir ▼'" class="text-slate-400 text-[11px]"></span>
                </button>
                <form x-show="ouvert" x-cloak method="post" action="{{ route('admin.evaluations.notes.import-anonyme', $epreuve) }}" enctype="multipart/form-data" class="mt-3 pt-3 border-t border-slate-100 flex flex-col sm:flex-row items-start sm:items-center gap-3">
                    @csrf
                    <div class="flex-1">
                        <label for="fichier-import" class="sr-only">Fichier CSV</label>
                        <input id="fichier-import" type="file" name="fichier" accept=".csv,text/csv" required class="text-xs text-slate-600 file:mr-2 file:py-1 file:px-2 file:rounded-lg file:border-0 file:text-xs file:font-semibold file:bg-primary-50 file:text-primary-700 hover:file:bg-primary-100" />
                        <span class="block text-[10px] text-slate-400 mt-1">Colonnes requises : <code class="font-mono">compostage,note</code> (aucun nom ni CIN autorisé).</span>
                    </div>
                    <x-btn type="submit" size="sm">Importer les notes</x-btn>
                </form>
            </div>
        @endcan

        {{-- Import multi-format avec prévisualisation (CSV, XLSX, ODS, JSON, XML) --}}
        @can('notes_eval.import')
            <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                <form method="post" action="{{ route('admin.evaluations.notes.import-multi', $epreuve) }}" enctype="multipart/form-data" class="flex flex-col sm:flex-row items-start sm:items-center gap-3">
                    @csrf
                    <div class="flex-1">
                        <label for="fichier-import-multi" class="sr-only">Fichier d’import</label>
                        <input id="fichier-import-multi" type="file" name="fichier" accept=".csv,.xlsx,.ods,.json,.xml" required class="text-xs text-slate-600 file:mr-2 file:py-1 file:px-2 file:rounded-lg file:border-0 file:text-xs file:font-semibold file:bg-primary-50 file:text-primary-700 hover:file:bg-primary-100" />
                        <span class="block text-[10px] text-slate-400 mt-1">CSV, XLSX, ODS, JSON ou XML — colonnes <code class="font-mono">compostage,note</code> uniquement. Prévisualisation sans écriture avant confirmation.</span>
                    </div>
                    <x-btn type="submit" size="sm" variant="secondary">Prévisualiser l’import</x-btn>
                </form>
            </div>
        @endcan

        {{-- Formulaire principal de saisie anonyme --}}
        <form method="post" action="{{ route('admin.evaluations.notes.store-anonyme', $epreuve) }}" class="rounded-2xl border border-slate-200 bg-white overflow-hidden shadow-sm">
            @csrf
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-slate-50 text-left text-[11px] uppercase tracking-wider text-slate-500 border-b border-slate-200">
                        <tr>
                            <th scope="col" class="px-4 py-3 font-bold">N° Compostage</th>
                            <th scope="col" class="px-4 py-3 font-bold">Statut candidat</th>
                            <th scope="col" class="px-4 py-3 font-bold text-right">Note / {{ $epreuve->note_max }}</th>
                            <th scope="col" class="px-4 py-3 font-bold text-center">État note</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @forelse($lignes as $index => $compostage)
                            @php
                                $note = $notes->get($compostage->etudiant_id);
                                $candidat = $statuts->get($compostage->etudiant_id);
                            @endphp
                            <tr class="hover:bg-slate-50/60 transition-colors">
                                <td class="px-4 py-3 font-mono font-bold text-slate-900 tracking-wide">
                                    {{ $compostage->numero }}
                                    <input type="hidden" name="lignes[{{ $index }}][numero]" value="{{ $compostage->numero }}" />
                                </td>
                                <td class="px-4 py-3">
                                    <x-status-badge :statut="$candidat?->statut ?? 'present'" />
                                </td>
                                <td class="px-4 py-3 text-right">
                                    <input name="lignes[{{ $index }}][valeur]" type="number" step="0.25" min="0" max="{{ $epreuve->note_max }}"
                                        value="{{ old('lignes.' . $index . '.valeur', $note?->valeur) }}"
                                        @if(($candidat && $candidat->estAbsent()) || ($note && $note->estVerrouillee())) disabled @endif
                                        class="w-28 rounded-xl border border-slate-300 px-3 py-1.5 text-right font-mono font-semibold text-xs tabular-nums focus:border-primary-500 focus:ring-1 focus:ring-primary-500 disabled:bg-slate-100 disabled:text-slate-400" />
                                </td>
                                <td class="px-4 py-3 text-center">
                                    @if($note)
                                        <x-status-badge :statut="$note->statut" />
                                    @else
                                        <span class="text-xs text-slate-400 italic">Non saisie</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="px-4 py-8 text-center text-sm text-slate-500">
                                    Aucun candidat convoqué pour cette épreuve.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if($lignes->isNotEmpty())
                <div class="flex items-center justify-between border-t border-slate-100 bg-slate-50/50 px-5 py-3">
                    <span class="text-xs text-slate-500">{{ $lignes->count() }} copie(s) anonymisée(s)</span>
                    <x-btn type="submit">Enregistrer les notes</x-btn>
                </div>
            @endif
        </form>
    </div>
@endsection
