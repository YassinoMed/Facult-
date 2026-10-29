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

        @php
            $nbTotal = $lignes->count();
            $nbSaisies = $notes->filter(fn ($n) => $n->valeur !== null)->count();
            $nbEnAttente = max(0, $nbTotal - $nbSaisies);
            $sommeNotes = $notes->whereNotNull('valeur')->sum('valeur');
            $moyenneProvisoire = $nbSaisies > 0 ? round($sommeNotes / $nbSaisies, 2) : null;
        @endphp
        <div class="mt-4 grid grid-cols-2 sm:grid-cols-4 gap-3">
            <div class="rounded-xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 p-3 shadow-xs">
                <span class="block text-[11px] font-bold uppercase tracking-wider text-slate-400">Total copies</span>
                <span class="text-xl font-black text-slate-800 dark:text-slate-100">{{ $nbTotal }}</span>
            </div>
            <div class="rounded-xl border border-emerald-200 dark:border-emerald-800 bg-emerald-50/50 dark:bg-emerald-950/20 p-3 shadow-xs">
                <span class="block text-[11px] font-bold uppercase tracking-wider text-emerald-600 dark:text-emerald-400">Saisies</span>
                <span class="text-xl font-black text-emerald-700 dark:text-emerald-300">{{ $nbSaisies }}</span>
            </div>
            <div class="rounded-xl border border-amber-200 dark:border-amber-800 bg-amber-50/50 dark:bg-amber-950/20 p-3 shadow-xs">
                <span class="block text-[11px] font-bold uppercase tracking-wider text-amber-600 dark:text-amber-400">En attente</span>
                <span class="text-xl font-black text-amber-700 dark:text-amber-300">{{ $nbEnAttente }}</span>
            </div>
            <div class="rounded-xl border border-primary-200 dark:border-primary-800 bg-primary-50/50 dark:bg-primary-950/20 p-3 shadow-xs">
                <span class="block text-[11px] font-bold uppercase tracking-wider text-primary-600 dark:text-primary-400">Moyenne prov.</span>
                <span class="text-xl font-black text-primary-700 dark:text-primary-300">{{ $moyenneProvisoire !== null ? number_format($moyenneProvisoire, 2) : '—' }}</span>
            </div>
        </div>

        <p class="text-[11px] text-slate-500 dark:text-slate-400 italic mt-2">
            * Les statistiques et moyennes affichées sont strictement provisoires et ne constituent en aucun cas une délibération officielle.
        </p>

        <section aria-label="Saisie des notes par compostage" class="mt-4 rounded-2xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 shadow-sm overflow-hidden">
            <div class="px-5 py-3 border-b border-slate-100 dark:border-slate-800 flex items-center justify-between gap-2">
                <h3 class="text-sm font-black text-slate-900 dark:text-slate-100">Copies ({{ $lignes->count() }}) — ordre anonymisé</h3>
                <x-status-badge :statut="$epreuve->statut" />
            </div>
            @if($lignes->isNotEmpty())
                <form method="POST" action="{{ route('enseignant.notes.store', $epreuve) }}"
                      x-data="{
                          etatSauvegarde: '{{ $errors->any() ? 'erreur' : (session('success') ? 'sauvegarde' : 'initial') }}',
                          noteMax: {{ (float) $epreuve->note_max }},
                          recherche: '',
                          initialiser() {
                              window.addEventListener('beforeunload', (e) => {
                                  if (this.etatSauvegarde === 'modifie') {
                                      e.preventDefault();
                                      e.returnValue = '';
                                  }
                              });
                              window.addEventListener('offline', () => {
                                  this.etatSauvegarde = 'erreur';
                              });
                              window.addEventListener('online', () => {
                                  if (this.etatSauvegarde === 'erreur') {
                                      this.etatSauvegarde = 'modifie';
                                  }
                              });
                          },
                          marquerModifie(input) {
                              this.etatSauvegarde = 'modifie';
                              this.validerBareme(input);
                          },
                          validerBareme(input) {
                              const val = parseFloat(input.value);
                              if (!isNaN(val) && (val < 0 || val > this.noteMax)) {
                                  input.classList.add('border-rose-500', 'bg-rose-50', 'text-rose-700', 'dark:bg-rose-950/30');
                                  input.setAttribute('aria-invalid', 'true');
                              } else {
                                  input.classList.remove('border-rose-500', 'bg-rose-50', 'text-rose-700', 'dark:bg-rose-950/30');
                                  input.removeAttribute('aria-invalid');
                              }
                          },
                          naviguer(e) {
                              if (e.key === 'ArrowDown' || e.key === 'Enter') {
                                  e.preventDefault();
                                  const inputs = Array.from($el.querySelectorAll('input.note-input:not([disabled])'));
                                  const idx = inputs.indexOf(e.target);
                                  if (idx >= 0 && idx < inputs.length - 1) inputs[idx + 1].focus();
                              } else if (e.key === 'ArrowUp') {
                                  e.preventDefault();
                                  const inputs = Array.from($el.querySelectorAll('input.note-input:not([disabled])'));
                                  const idx = inputs.indexOf(e.target);
                                  if (idx > 0) inputs[idx - 1].focus();
                              }
                          },
                          notificationPressePapier: '',
                          timerPressePapier: null,
                          afficherNotificationPressePapier(msg) {
                              this.notificationPressePapier = msg;
                              clearTimeout(this.timerPressePapier);
                              this.timerPressePapier = setTimeout(() => {
                                  this.notificationPressePapier = '';
                              }, 4000);
                          },
                          collerExcel(e) {
                              const texte = (e.clipboardData || window.clipboardData)?.getData('text');
                              if (!texte) return;
                              const lignesCollees = texte.split(/\r?\n/).map(l => l.trim()).filter(l => l !== '');
                              if (lignesCollees.length > 1) {
                                  e.preventDefault();
                                  const inputs = Array.from($el.querySelectorAll('input.note-input:not([disabled])'));
                                  const idxDepart = inputs.indexOf(e.target);
                                  if (idxDepart < 0) return;
                                  let nbModifiees = 0;
                                  lignesCollees.forEach((ligneTexte, decalage) => {
                                      const idxCible = idxDepart + decalage;
                                      if (idxCible < inputs.length) {
                                          const cellules = ligneTexte.split('\t');
                                          const valStr = cellules[0].replace(',', '.');
                                          const val = parseFloat(valStr);
                                          if (!isNaN(val)) {
                                              inputs[idxCible].value = val;
                                              this.marquerModifie(inputs[idxCible]);
                                              nbModifiees++;
                                          }
                                      }
                                  });
                                  if (nbModifiees > 0) {
                                      this.afficherNotificationPressePapier(nbModifiees + ' note(s) insérée(s) depuis le presse-papier.');
                                  }
                              }
                          },
                          soumettre() {
                              this.etatSauvegarde = 'en_cours';
                          }
                      }"
                      x-init="initialiser()"
                      @submit="soumettre()">
                    @csrf

                    {{-- Notification de collage --}}
                    <div x-show="notificationPressePapier" x-cloak
                         x-transition:enter="transition ease-out duration-200"
                         x-transition:enter-start="opacity-0 -translate-y-1"
                         x-transition:enter-end="opacity-100 translate-y-0"
                         x-transition:leave="transition ease-in duration-150"
                         x-transition:leave-start="opacity-100 translate-y-0"
                         x-transition:leave-end="opacity-0 -translate-y-1"
                         class="px-5 py-2 bg-emerald-50 dark:bg-emerald-950/40 border-b border-emerald-200 dark:border-emerald-800 text-emerald-800 dark:text-emerald-200 text-xs font-semibold flex items-center justify-between">
                        <div class="flex items-center gap-2">
                            <svg class="w-4 h-4 text-emerald-600 dark:text-emerald-400 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                            </svg>
                            <span x-text="notificationPressePapier"></span>
                        </div>
                        <button type="button" @click="notificationPressePapier = ''" class="text-emerald-700 dark:text-emerald-300 hover:text-emerald-900 font-bold text-sm leading-none">×</button>
                    </div>

                    <div class="px-5 py-2.5 bg-slate-50 dark:bg-slate-800/50 border-b border-slate-100 dark:border-slate-800 flex items-center justify-between gap-3 text-xs">
                        <div class="flex items-center gap-2">
                            <span class="font-bold text-slate-500 uppercase tracking-wider">Recherche :</span>
                            <input type="text" x-model="recherche" placeholder="N° copie..."
                                   class="rounded-lg border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 text-xs px-2.5 py-1 focus:border-primary-500 focus:ring-primary-500 w-32">
                        </div>
                        <div class="text-slate-500 dark:text-slate-400 text-[11px] flex items-center gap-2 flex-wrap">
                            <span class="flex items-center gap-1">
                                <kbd class="px-1.5 py-0.5 rounded border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 font-mono text-[10px] font-semibold text-slate-700 dark:text-slate-300">↑</kbd>
                                <kbd class="px-1.5 py-0.5 rounded border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 font-mono text-[10px] font-semibold text-slate-700 dark:text-slate-300">↓</kbd>
                                <span>naviguer</span>
                            </span>
                            <span class="text-slate-300 dark:text-slate-600">·</span>
                            <span class="flex items-center gap-1">
                                <kbd class="px-1.5 py-0.5 rounded border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 font-mono text-[10px] font-semibold text-slate-700 dark:text-slate-300">Ctrl+V</kbd>
                                <span>collage Excel</span>
                            </span>
                        </div>
                    </div>
                    <div class="overflow-x-auto max-h-[640px]">
                        <table class="w-full min-w-[480px] text-left text-xs sm:text-sm">
                            <thead class="sticky top-0 z-10 bg-slate-50/95 dark:bg-slate-900/95 backdrop-blur-xs shadow-2xs">
                                <tr class="border-b border-slate-100 dark:border-slate-800 text-[11px] uppercase tracking-wider text-slate-500 dark:text-slate-400">
                                    <th scope="col" class="px-5 py-3 font-bold">N° de copie</th>
                                    <th scope="col" class="px-5 py-3 font-bold">Note déjà saisie</th>
                                    <th scope="col" class="px-5 py-3 font-bold">Note (0–{{ $epreuve->note_max }})</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                                @foreach($lignes as $i => $compostage)
                                    @php $existante = $notes->get($compostage->etudiant_id); @endphp
                                    <tr x-show="!recherche || '{{ strtolower($compostage->numero) }}'.includes(recherche.toLowerCase())"
                                        class="hover:bg-slate-50/60 dark:hover:bg-slate-800/30">
                                        <td class="px-5 py-2.5 font-mono font-bold text-slate-800 dark:text-slate-100">{{ $compostage->numero }}</td>
                                        <td class="px-5 py-2.5 tabular-nums text-slate-600 dark:text-slate-300">{{ $existante?->valeur ?? '—' }}</td>
                                        <td class="px-5 py-2.5">
                                            <input type="hidden" name="lignes[{{ $i }}][numero]" value="{{ $compostage->numero }}">
                                            <input type="number" name="lignes[{{ $i }}][valeur]" value="{{ old('lignes.' . $i . '.valeur', $existante?->valeur) }}"
                                                min="0" max="{{ $epreuve->note_max }}" step="0.25" inputmode="decimal" aria-label="Note de la copie {{ $compostage->numero }}"
                                                @disabled($epreuve->estVerrouillee())
                                                @keydown="naviguer($event)"
                                                @paste="collerExcel($event)"
                                                @input="marquerModifie($el)"
                                                class="note-input w-28 rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 px-2.5 py-1.5 text-sm tabular-nums focus:border-primary-500 focus:ring-1 focus:ring-primary-500">
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    <div class="px-5 py-4 border-t border-slate-100 dark:border-slate-800 flex flex-col sm:flex-row items-center justify-between gap-3">
                        <div class="flex items-center gap-3">
                            <template x-if="etatSauvegarde === 'initial'">
                                <span class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-[11px] font-semibold bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-400 border border-slate-200 dark:border-slate-700">
                                    <span class="w-1.5 h-1.5 rounded-full bg-slate-400"></span>
                                    Prêt pour saisie
                                </span>
                            </template>
                            <template x-if="etatSauvegarde === 'sauvegarde'">
                                <span class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-[11px] font-semibold bg-emerald-50 text-emerald-700 border border-emerald-200">
                                    <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span>
                                    Enregistré
                                </span>
                            </template>
                            <template x-if="etatSauvegarde === 'modifie'">
                                <span class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-[11px] font-semibold bg-amber-50 text-amber-700 border border-amber-200">
                                    <span class="w-1.5 h-1.5 rounded-full bg-amber-500 animate-pulse"></span>
                                    Non enregistré
                                </span>
                            </template>
                            <template x-if="etatSauvegarde === 'en_cours'">
                                <span class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-[11px] font-semibold bg-blue-50 text-blue-700 border border-blue-200">
                                    <svg class="animate-spin h-3 w-3 text-blue-600" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"></path></svg>
                                    Enregistrement…
                                </span>
                            </template>
                            <template x-if="etatSauvegarde === 'erreur'">
                                <span class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-[11px] font-semibold bg-rose-50 text-rose-700 border border-rose-200">
                                    <span class="w-1.5 h-1.5 rounded-full bg-rose-500"></span>
                                    Erreur d’enregistrement / Hors ligne
                                </span>
                            </template>
                        </div>
                        <x-btn type="submit" :disabled="$epreuve->estVerrouillee()" ::loading="etatSauvegarde === 'en_cours'">Enregistrer les notes</x-btn>
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
