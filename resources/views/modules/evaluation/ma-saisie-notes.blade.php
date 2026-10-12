@extends('layouts.app')

@section('title', 'Saisie anonyme — Espace Enseignant')

@section('content')
    <div class="mx-auto max-w-5xl px-4 sm:px-6 py-8">
        <x-page-header :titre="'Saisie — ' . $epreuve->code" :description="($epreuve->matiere?->nom ?? '—') . ' · Barème 0–' . $epreuve->note_max"
            :fil="['Notes' => route('enseignant.notes.index'), 'Saisie' => '#']">
            <x-slot:actions>
                <x-btn variant="secondary" href="{{ route('enseignant.notes.exporter', $epreuve) }}">Export anonyme (CSV)</x-btn>
            </x-slot:actions>
        </x-page-header>

        @if(session('success'))
            <div class="mt-4"><x-alert type="success" :message="session('success')" /></div>
        @endif
        @if($errors->any())
            <div class="mt-4"><x-alert type="error" :message="$errors->first()" /></div>
        @endif

        @if($epreuve->estVerrouillee())
            <div class="mt-4"><x-alert type="error" message="Épreuve verrouillée : saisie et import interdits. Contactez la scolarité pour toute correction." /></div>
        @endif

        <section aria-label="Saisie des notes par compostage" class="mt-4 rounded-2xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 shadow-sm overflow-hidden">
            <div class="px-5 py-3 border-b border-slate-100 dark:border-slate-800 flex items-center justify-between gap-2">
                <h3 class="text-sm font-black text-slate-900 dark:text-slate-100">Copies ({{ $lignes->count() }}) — ordre anonymisé</h3>
                <x-status-badge :statut="$epreuve->statut" />
            </div>
            @if($lignes->isNotEmpty())
                <form method="POST" action="{{ route('enseignant.notes.store', $epreuve) }}">
                    @csrf
                    <div class="overflow-x-auto">
                        <table class="w-full min-w-[480px] text-left text-xs sm:text-sm">
                            <thead>
                                <tr class="border-b border-slate-100 dark:border-slate-800 text-[11px] uppercase tracking-wider text-slate-400">
                                    <th scope="col" class="px-5 py-3 font-bold">N° de copie</th>
                                    <th scope="col" class="px-5 py-3 font-bold">Note déjà saisie</th>
                                    <th scope="col" class="px-5 py-3 font-bold">Note (0–{{ $epreuve->note_max }})</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                                @foreach($lignes as $i => $compostage)
                                    @php $existante = $notes->get($compostage->etudiant_id); @endphp
                                    <tr class="hover:bg-slate-50/60 dark:hover:bg-slate-800/30">
                                        <td class="px-5 py-2.5 font-mono font-bold text-slate-800 dark:text-slate-100">{{ $compostage->numero }}</td>
                                        <td class="px-5 py-2.5 tabular-nums text-slate-600 dark:text-slate-300">{{ $existante?->valeur ?? '—' }}</td>
                                        <td class="px-5 py-2.5">
                                            <input type="hidden" name="lignes[{{ $i }}][numero]" value="{{ $compostage->numero }}">
                                            <input type="number" name="lignes[{{ $i }}][valeur]" value="{{ old('lignes.' . $i . '.valeur', $existante?->valeur) }}"
                                                min="0" max="{{ $epreuve->note_max }}" step="0.25" inputmode="decimal" aria-label="Note de la copie {{ $compostage->numero }}"
                                                @disabled($epreuve->estVerrouillee())
                                                class="w-28 rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 px-2.5 py-1.5 text-sm tabular-nums">
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    <div class="px-5 py-4 border-t border-slate-100 dark:border-slate-800">
                        <x-btn type="submit" :disabled="$epreuve->estVerrouillee()">Enregistrer les notes</x-btn>
                    </div>
                </form>
            @else
                <div class="p-5"><x-empty-state message="Aucun candidat convoqué : les numéros apparaîtront après génération par la scolarité." /></div>
            @endif
        </section>

        <section aria-label="Import CSV anonyme" class="mt-4 rounded-2xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 shadow-sm p-6">
            <h3 class="text-sm font-black text-slate-900 dark:text-slate-100">Import CSV anonyme</h3>
            <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">Colonnes <code class="font-mono">numero,note</code> uniquement — toute colonne identifiante (nom, CIN, e-mail, matricule, identifiant) fait refuser le fichier.</p>
            <form method="POST" action="{{ route('enseignant.notes.importer', $epreuve) }}" enctype="multipart/form-data" class="mt-3 flex flex-wrap items-end gap-3">
                @csrf
                <div>
                    <label for="fichier" class="block text-xs font-bold uppercase tracking-wider text-slate-600 dark:text-slate-300 mb-1">Fichier CSV (2 Mo max)</label>
                    <input id="fichier" type="file" name="fichier" accept=".csv,text/csv" required @disabled($epreuve->estVerrouillee())
                        class="text-sm text-slate-700 dark:text-slate-300">
                </div>
                <x-btn type="submit" variant="secondary" :disabled="$epreuve->estVerrouillee()">Importer</x-btn>
            </form>
        </section>
    </div>
@endsection
