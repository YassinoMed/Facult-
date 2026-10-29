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

                @can('notes_eval.validate')
                    @if(! $epreuve->estVerrouillee())
                        <form method="post" action="{{ route('admin.evaluations.epreuves.notes.valider-tout', $epreuve) }}" onsubmit="return confirm('Confirmer la validation de toutes les notes saisies pour cette épreuve ?');" class="inline">
                            @csrf
                            <button type="submit" class="inline-flex items-center gap-1.5 rounded-xl border border-emerald-300 bg-emerald-50 px-3 py-1.5 text-xs font-bold text-emerald-800 shadow-sm hover:bg-emerald-100 transition-colors">
                                <svg class="h-3.5 w-3.5 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                                </svg>
                                <span>Valider toutes les notes</span>
                            </button>
                        </form>
                    @endif
                @endcan

                @can('notes.compostage.resolve')
                    <a href="{{ route('admin.evaluations.epreuves.correspondance', $epreuve) }}"
                       class="inline-flex items-center gap-1.5 rounded-xl border border-primary-200 bg-primary-50 px-3 py-1.5 text-xs font-bold text-primary-700 shadow-xs hover:bg-primary-100 transition-colors"
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
            <x-alert type="error">
                <p class="font-bold">{{ $errors->count() }} erreur(s) de saisie — corrigez les lignes signalées puis réenregistrez :</p>
                <ul class="mt-1 list-disc space-y-0.5 pl-5">
                    @foreach($errors->all() as $erreur)
                        <li>{{ $erreur }}</li>
                    @endforeach
                </ul>
            </x-alert>
        @endif

        @if(! ($baremeValide ?? true))
            <x-alert type="error">
                <p class="font-bold">Barème de l’épreuve invalide (note maximale absente ou nulle).</p>
                <p class="mt-1">La saisie et l’import des notes sont bloqués côté serveur tant que la note maximale de l’épreuve n’est pas strictement positive.</p>
            </x-alert>
        @endif

        {{-- Indicateurs de synthèse en temps réel --}}
        @php
            $nbTotal = $lignes->count();
            $nbSaisies = $notes->filter(fn ($n) => $n->valeur !== null)->count();
            $nbAbsents = $statuts->filter(fn ($c) => $c->estAbsent())->count();
            $nbEnAttente = max(0, $nbTotal - $nbSaisies - $nbAbsents);
            $sommeNotes = $notes->whereNotNull('valeur')->sum('valeur');
            $moyenneProvisoire = $nbSaisies > 0 ? round($sommeNotes / $nbSaisies, 2) : null;
        @endphp
        <div class="grid grid-cols-2 sm:grid-cols-5 gap-3">
            <div class="rounded-xl border border-slate-200 bg-white p-3 shadow-xs">
                <span class="block text-[11px] font-bold uppercase tracking-wider text-slate-400">Total copies</span>
                <span class="text-xl font-black text-slate-800">{{ $nbTotal }}</span>
            </div>
            <div class="rounded-xl border border-emerald-200 bg-emerald-50/50 p-3 shadow-xs">
                <span class="block text-[11px] font-bold uppercase tracking-wider text-emerald-600">Saisies</span>
                <span class="text-xl font-black text-emerald-700">{{ $nbSaisies }}</span>
            </div>
            <div class="rounded-xl border border-amber-200 bg-amber-50/50 p-3 shadow-xs">
                <span class="block text-[11px] font-bold uppercase tracking-wider text-amber-600">En attente</span>
                <span class="text-xl font-black text-amber-700">{{ $nbEnAttente }}</span>
            </div>
            <div class="rounded-xl border border-rose-200 bg-rose-50/50 p-3 shadow-xs">
                <span class="block text-[11px] font-bold uppercase tracking-wider text-rose-600">Absents</span>
                <span class="text-xl font-black text-rose-700">{{ $nbAbsents }}</span>
            </div>
            <div class="rounded-xl border border-primary-200 bg-primary-50/50 p-3 shadow-xs col-span-2 sm:col-span-1">
                <span class="block text-[11px] font-bold uppercase tracking-wider text-primary-600">Moyenne prov.</span>
                <span class="text-xl font-black text-primary-700">{{ $moyenneProvisoire !== null ? number_format($moyenneProvisoire, 2) : '—' }}</span>
            </div>
        </div>

        <p class="text-[11px] text-slate-500 italic">
            * Les statistiques et moyennes affichées sont strictement provisoires et ne constituent en aucun cas une délibération officielle.
        </p>

        {{-- Import rapide CSV anonyme --}}
        @can('notes_eval.enter')
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
        @can('notes_eval.enter')
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

        {{-- Formulaire principal de saisie anonyme avec navigation clavier, collage Excel et filtres --}}
        <form method="post" action="{{ route('admin.evaluations.notes.store-anonyme', $epreuve) }}"
              x-data="{
                  etatSauvegarde: '{{ $errors->any() ? 'erreur' : (session('succes') ? 'sauvegarde' : 'initial') }}',
                  noteMax: {{ (float) $epreuve->note_max }},
                  recherche: '',
                  filtreStatut: 'tous',
                  brouillonDisponible: false,
                  brouillon: null,
                  dejaEnregistre: {{ session('succes') ? 'true' : 'false' }},
                  initialiser() {
                      if (this.dejaEnregistre) {
                          this.effacerBrouillon();
                      } else {
                          this.chargerBrouillon();
                      }
                      window.addEventListener('beforeunload', (e) => {
                          if (this.etatSauvegarde === 'modifie') {
                              this.sauvegarderBrouillon();
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
                  cleBrouillon() {
                      return 'notes-eval:{{ auth()->id() }}:{{ $epreuve->id }}';
                  },
                  chargerBrouillon() {
                      try {
                          const brut = localStorage.getItem(this.cleBrouillon());
                          if (!brut) return;
                          const donnees = JSON.parse(brut);
                          if (donnees && typeof donnees === 'object' && Object.keys(donnees).length > 0) {
                              this.brouillon = donnees;
                              this.brouillonDisponible = true;
                          }
                      } catch (e) {
                          this.brouillon = null;
                          this.brouillonDisponible = false;
                      }
                  },
                  restaurerBrouillon() {
                      if (!this.brouillon) return;
                      this.$el.querySelectorAll('input.note-input[data-numero]').forEach((input) => {
                          if (input.disabled) return;
                          const numero = input.dataset.numero;
                          if (Object.prototype.hasOwnProperty.call(this.brouillon, numero)) {
                              input.value = this.brouillon[numero];
                              this.validerBareme(input);
                          }
                      });
                      this.brouillonDisponible = false;
                      this.etatSauvegarde = 'modifie';
                  },
                  ignorerBrouillon() {
                      this.effacerBrouillon();
                      this.brouillon = null;
                      this.brouillonDisponible = false;
                  },
                  effacerBrouillon() {
                      try {
                          localStorage.removeItem(this.cleBrouillon());
                      } catch (e) {
                          // Stockage local indisponible : la saisie reste fonctionnelle.
                      }
                  },
                  sauvegarderBrouillon() {
                      const donnees = {};
                      this.$el.querySelectorAll('input.note-input[data-numero]').forEach((input) => {
                          if (input.disabled) return;
                          const valeur = (input.value || '').trim();
                          if (valeur !== '') {
                              donnees[input.dataset.numero] = valeur;
                          }
                      });
                      try {
                          if (Object.keys(donnees).length > 0) {
                              localStorage.setItem(this.cleBrouillon(), JSON.stringify(donnees));
                          } else {
                              localStorage.removeItem(this.cleBrouillon());
                          }
                      } catch (e) {
                          // Quota ou mode privé : ne jamais bloquer la saisie.
                      }
                  },
                  planifierSauvegarde() {
                      clearTimeout(this._sauvegardeTimer);
                      this._sauvegardeTimer = setTimeout(() => this.sauvegarderBrouillon(), 250);
                  },
                  marquerModifie(input) {
                      this.etatSauvegarde = 'modifie';
                      this.validerBareme(input);
                      this.planifierSauvegarde();
                  },
                  validerBareme(input) {
                      const brut = (input.value || '').trim().replace(',', '.');
                      const val = parseFloat(brut);
                      const tropDeDecimales = /^[+-]?\d+\.\d{3,}$/.test(brut);
                      if (!isNaN(val) && (val < 0 || val > this.noteMax || tropDeDecimales)) {
                          input.classList.add('border-rose-500', 'bg-rose-50', 'text-rose-700');
                          input.setAttribute('aria-invalid', 'true');
                      } else {
                          input.classList.remove('border-rose-500', 'bg-rose-50', 'text-rose-700');
                          input.removeAttribute('aria-invalid');
                      }
                  },
                  bornerNote(input) {
                      if (input.disabled) return;
                      const brut = (input.value || '').trim().replace(',', '.');
                      if (brut === '') {
                          input.value = '';
                          this.validerBareme(input);
                          return;
                      }
                      const val = parseFloat(brut);
                      if (!isFinite(val)) {
                          input.value = '';
                          this.validerBareme(input);
                          this.afficherNotificationPressePapier('Valeur non numérique ignorée — aucune note enregistrée.');
                          return;
                      }
                      const borne = Math.min(this.noteMax, Math.max(0, val));
                      const arrondi = Math.round(borne * 100) / 100;
                      if (arrondi !== val) {
                          this.afficherNotificationPressePapier('Note ramenée à ' + arrondi + ' (barème 0–' + this.noteMax + ', 2 décimales maximum).');
                      }
                      input.value = arrondi.toFixed(2);
                      this.validerBareme(input);
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
                          let nbBornees = 0;
                          lignesCollees.forEach((ligneTexte, decalage) => {
                              const idxCible = idxDepart + decalage;
                              if (idxCible < inputs.length) {
                                  const cellules = ligneTexte.split('\t');
                                  const valStr = cellules[0].replace(',', '.');
                                  const val = parseFloat(valStr);
                                  if (!isNaN(val) && isFinite(val)) {
                                      const borne = Math.round(Math.min(this.noteMax, Math.max(0, val)) * 100) / 100;
                                      if (borne !== val) nbBornees++;
                                      inputs[idxCible].value = borne.toFixed(2);
                                      this.marquerModifie(inputs[idxCible]);
                                      nbModifiees++;
                                  }
                              }
                          });
                          if (nbModifiees > 0) {
                              this.afficherNotificationPressePapier(nbModifiees + ' note(s) insérée(s) depuis le presse-papier.'
                                  + (nbBornees > 0 ? ' ' + nbBornees + ' valeur(s) hors barème ramenée(s) dans 0–' + this.noteMax + '.' : ''));
                          }
                      }
                  },
                  estLigneVisible(numero, aNote, estAbsent) {
                      if (this.recherche && !numero.toLowerCase().includes(this.recherche.toLowerCase())) {
                          return false;
                      }
                      if (this.filtreStatut === 'avec_note' && !aNote) return false;
                      if (this.filtreStatut === 'sans_note' && (aNote || estAbsent)) return false;
                      if (this.filtreStatut === 'absent' && !estAbsent) return false;
                      return true;
                  },
                  soumettre(e) {
                      if (this._envoiEnCours) {
                          e.preventDefault();
                          return;
                      }
                      this._envoiEnCours = true;
                      this.etatSauvegarde = 'en_cours';
                  }
              }"
              x-init="initialiser()"
              @submit="soumettre($event)"
              class="rounded-2xl border border-slate-200 bg-white overflow-hidden shadow-sm">
            @csrf

            {{-- Notification de collage Excel --}}
            <div x-show="notificationPressePapier" x-cloak
                 x-transition:enter="transition ease-out duration-200"
                 x-transition:enter-start="opacity-0 -translate-y-1"
                 x-transition:enter-end="opacity-100 translate-y-0"
                 x-transition:leave="transition ease-in duration-150"
                 x-transition:leave-start="opacity-100 translate-y-0"
                 x-transition:leave-end="opacity-0 -translate-y-1"
                 class="px-5 py-2.5 bg-emerald-50 border-b border-emerald-200 text-emerald-800 text-xs font-semibold flex items-center justify-between">
                <div class="flex items-center gap-2">
                    <svg class="w-4 h-4 text-emerald-600 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                    <span x-text="notificationPressePapier"></span>
                </div>
                <button type="button" @click="notificationPressePapier = ''" class="text-emerald-700 hover:text-emerald-900 font-bold text-sm leading-none">×</button>
            </div>

            {{-- Reprise locale : aucune donnée nominative, uniquement des numéros anonymes et des valeurs --}}
            <div x-show="brouillonDisponible" x-cloak class="px-5 py-3 bg-amber-50 border-b border-amber-200">
                <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                    <p class="text-xs text-amber-900">
                        <strong>Brouillon local trouvé.</strong>
                        Il contient uniquement des numéros de compostage et des valeurs de notes, jamais de nom, CIN ou e-mail.
                    </p>
                    <div class="flex items-center gap-2">
                        <button type="button" @click="restaurerBrouillon()"
                                class="rounded-lg border border-amber-300 bg-white px-3 py-1.5 text-xs font-bold text-amber-800 hover:bg-amber-100 transition-colors">
                            Restaurer
                        </button>
                        <button type="button" @click="ignorerBrouillon()"
                                class="rounded-lg px-3 py-1.5 text-xs font-semibold text-amber-700 hover:bg-amber-100 transition-colors">
                            Ignorer
                        </button>
                    </div>
                </div>
            </div>

            {{-- Barre de filtres et recherche rapide en direct --}}
            <div class="px-5 py-3 bg-slate-50 border-b border-slate-200 flex flex-col sm:flex-row items-center justify-between gap-3 text-xs">
                <div class="flex items-center gap-2 w-full sm:w-auto">
                    <span class="font-bold text-slate-500 uppercase tracking-wider">Filtrer :</span>
                    <input type="text" x-model="recherche" placeholder="Rechercher CMP..."
                           class="rounded-lg border-slate-300 text-xs px-2.5 py-1 focus:border-primary-500 focus:ring-primary-500 w-36">
                    <select x-model="filtreStatut" class="rounded-lg border-slate-300 text-xs px-2 py-1 focus:border-primary-500 focus:ring-primary-500">
                        <option value="tous">Toutes les copies</option>
                        <option value="avec_note">Avec note</option>
                        <option value="sans_note">Sans note</option>
                        <option value="absent">Absents</option>
                    </select>
                </div>
                <div class="text-slate-500 text-[11px] flex items-center gap-2 flex-wrap">
                    <span class="flex items-center gap-1">
                        <kbd class="px-1.5 py-0.5 rounded border border-slate-300 bg-white font-mono text-[10px] font-semibold text-slate-700">↑</kbd>
                        <kbd class="px-1.5 py-0.5 rounded border border-slate-300 bg-white font-mono text-[10px] font-semibold text-slate-700">↓</kbd>
                        <span>naviguer</span>
                    </span>
                    <span class="text-slate-300">·</span>
                    <span class="flex items-center gap-1">
                        <kbd class="px-1.5 py-0.5 rounded border border-slate-300 bg-white font-mono text-[10px] font-semibold text-slate-700">Ctrl+V</kbd>
                        <span>collage Excel</span>
                    </span>
                </div>
            </div>

            <div class="overflow-x-auto max-h-[640px]">
                <table class="w-full text-sm">
                    <thead class="sticky top-0 z-10 bg-slate-50/95 backdrop-blur-xs text-left text-[11px] uppercase tracking-wider text-slate-600 border-b border-slate-200 shadow-2xs">
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
                                $aNote = $note && $note->valeur !== null;
                                $estAbsent = $candidat && $candidat->estAbsent();
                            @endphp
                            <tr x-show="estLigneVisible('{{ $compostage->numero }}', {{ $aNote ? 'true' : 'false' }}, {{ $estAbsent ? 'true' : 'false' }})"
                                class="hover:bg-slate-50/60 transition-colors">
                                <td class="px-4 py-3 font-mono font-bold text-slate-900 tracking-wide">
                                    {{ $compostage->numero }}
                                    <input type="hidden" name="lignes[{{ $index }}][numero]" value="{{ $compostage->numero }}" />
                                </td>
                                <td class="px-4 py-3">
                                    <x-status-badge :statut="$candidat?->statut ?? 'present'" />
                                </td>
                                <td class="px-4 py-3 text-right">
                                    <input name="lignes[{{ $index }}][valeur]" type="number" step="0.25" min="0" max="{{ $epreuve->note_max }}"
                                        inputmode="decimal" autocomplete="off"
                                        data-numero="{{ $compostage->numero }}"
                                        value="{{ old('lignes.' . $index . '.valeur', $note?->valeur) }}"
                                        @if($estAbsent || ($note && $note->estVerrouillee()) || ! ($baremeValide ?? true)) disabled @endif
                                        @keydown="naviguer($event)"
                                        @paste="collerExcel($event)"
                                        @input="marquerModifie($el)"
                                        @blur="bornerNote($el)"
                                        class="note-input w-28 rounded-xl border border-slate-300 px-3 py-1.5 text-right font-mono font-semibold text-xs tabular-nums focus:border-primary-500 focus:ring-1 focus:ring-primary-500 disabled:bg-slate-100 disabled:text-slate-400" />
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
                <div class="flex flex-col sm:flex-row items-center justify-between border-t border-slate-100 bg-slate-50/50 px-5 py-3 gap-3">
                    <div class="flex items-center gap-3">
                        <span class="text-xs text-slate-500">{{ $lignes->count() }} copie(s) anonymisée(s)</span>
                        <template x-if="etatSauvegarde === 'initial'">
                            <span class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-[11px] font-semibold bg-slate-100 text-slate-600 border border-slate-200">
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
                    <x-btn type="submit" ::loading="etatSauvegarde === 'en_cours'" ::disabled="etatSauvegarde === 'en_cours'">Enregistrer les notes</x-btn>
                </div>
            @endif
        </form>
    </div>
@endsection
