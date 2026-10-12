@extends('layouts.app')

@section('title', 'Affectation automatique')

@section('content')
    <div class="mx-auto max-w-4xl px-4 sm:px-6 py-8" x-data="{
        programmeFiltre: '{{ old('programme_id', '') }}',
        niveauFiltre: '{{ old('niveau', '') }}',
        cocherTous() {
            document.querySelectorAll('input[name=\'groupe_ids[]\']').forEach(cb => {
                if (cb.closest('label').style.display !== 'none') {
                    cb.checked = true;
                }
            });
        },
        decocherTous() {
            document.querySelectorAll('input[name=\'groupe_ids[]\']').forEach(cb => cb.checked = false);
        }
    }">
        <x-page-header titre="Affectation automatique des étudiants" description="Paramètres multicritères → simulation sans écriture → validation par groupe." />

        @if ($errors->any())
            <div class="mt-4 rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-700 dark:border-red-900/50 dark:bg-red-950/30 dark:text-red-400">
                <p class="font-bold">Erreurs détectées :</p>
                <ul class="mt-1 list-disc pl-5 space-y-1">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form method="POST" action="{{ route('admin.affectations.automatique.simuler') }}" class="mt-6 ds-card p-6 space-y-6">
            @csrf

            <!-- Section 1 : Périmètre & Année -->
            <div>
                <h3 class="text-sm font-bold uppercase tracking-wider text-slate-800 dark:text-slate-200 flex items-center gap-2">
                    <span class="flex h-6 w-6 items-center justify-center rounded-full bg-blue-100 text-xs font-bold text-blue-700 dark:bg-blue-900 dark:text-blue-300">1</span>
                    Cadre académique & Inscription
                </h3>
                <div class="mt-3 grid gap-4 sm:grid-cols-2">
                    <div>
                        <label for="annee" class="mb-1 block text-xs font-bold uppercase text-slate-700 dark:text-slate-300">Année universitaire *</label>
                        <select id="annee" name="annee_universitaire_id" required class="w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-900">
                            @foreach($annees as $annee)
                                <option value="{{ $annee->id }}" @selected((int) old('annee_universitaire_id', $anneeDefaut ?? 0) === $annee->id)>{{ $annee->libelle }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div>
                        <label for="regime" class="mb-1 block text-xs font-bold uppercase text-slate-700 dark:text-slate-300">Régime d'études</label>
                        <select id="regime" name="regime" class="w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-900">
                            <option value="">Tous les régimes</option>
                            @foreach($regimes as $codeRegime => $libelleRegime)
                                <option value="{{ $codeRegime }}" @selected(old('regime') === $codeRegime)>{{ $libelleRegime }}</option>
                            @endforeach
                        </select>
                        <p class="mt-1 text-[11px] text-slate-500">Permet de séparer les étudiants en alternance ou formation continue.</p>
                    </div>
                </div>
            </div>

            <hr class="border-slate-200 dark:border-slate-800">

            <!-- Section 2 : Filtres LMD (Filières, Spécialités, Niveaux) -->
            <div>
                <h3 class="text-sm font-bold uppercase tracking-wider text-slate-800 dark:text-slate-200 flex items-center gap-2">
                    <span class="flex h-6 w-6 items-center justify-center rounded-full bg-blue-100 text-xs font-bold text-blue-700 dark:bg-blue-900 dark:text-blue-300">2</span>
                    Filtres Référentiel LMD (Population cible)
                </h3>
                <div class="mt-3 grid gap-4 sm:grid-cols-3">
                    <div>
                        <label for="programme" class="mb-1 block text-xs font-bold uppercase text-slate-700 dark:text-slate-300">Programme / Parcours</label>
                        <select id="programme" name="programme_id" x-model="programmeFiltre" class="w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-900">
                            <option value="">Tous les programmes</option>
                            @foreach($programmes as $prog)
                                <option value="{{ $prog->id }}" @selected((int) old('programme_id') === $prog->id)>{{ $prog->code }} — {{ $prog->nom }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div>
                        <label for="specialite" class="mb-1 block text-xs font-bold uppercase text-slate-700 dark:text-slate-300">Spécialité</label>
                        <select id="specialite" name="specialite_id" class="w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-900">
                            <option value="">Toutes les spécialités</option>
                            @foreach($specialites as $spec)
                                <option value="{{ $spec->id }}" @selected((int) old('specialite_id') === $spec->id)>{{ $spec->nom }} ({{ $spec->code }})</option>
                            @endforeach
                        </select>
                    </div>

                    <div>
                        <label for="niveau" class="mb-1 block text-xs font-bold uppercase text-slate-700 dark:text-slate-300">Niveau académique</label>
                        <select id="niveau" name="niveau" x-model="niveauFiltre" class="w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-900">
                            <option value="">Tous les niveaux</option>
                            @foreach($niveaux as $niv)
                                <option value="{{ $niv->code }}" @selected(old('niveau') === $niv->code)>{{ $niv->code }} — {{ $niv->nom }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
            </div>

            <hr class="border-slate-200 dark:border-slate-800">

            <!-- Section 3 : Stratégie d'affectation -->
            <div>
                <h3 class="text-sm font-bold uppercase tracking-wider text-slate-800 dark:text-slate-200 flex items-center gap-2">
                    <span class="flex h-6 w-6 items-center justify-center rounded-full bg-blue-100 text-xs font-bold text-blue-700 dark:bg-blue-900 dark:text-blue-300">3</span>
                    Politique d'équilibrage & Critères de tri
                </h3>
                <div class="mt-3 grid gap-3 sm:grid-cols-2">
                    <label class="flex cursor-pointer items-start gap-3 rounded-xl border p-3.5 transition hover:bg-slate-50 dark:border-slate-700 dark:hover:bg-slate-800/50 has-[:checked]:border-blue-600 has-[:checked]:bg-blue-50/50 dark:has-[:checked]:border-blue-500 dark:has-[:checked]:bg-blue-950/20">
                        <input type="radio" name="strategie" value="equilibre" class="mt-1" @checked(old('strategie', 'equilibre') === 'equilibre')>
                        <div>
                            <span class="block text-sm font-bold text-slate-900 dark:text-white">Équilibrage quantitatif pur</span>
                            <span class="block text-xs text-slate-500 dark:text-slate-400">Contraintes dures d'abord, puis répartition uniforme de la taille des groupes (MOVE / SWAP).</span>
                        </div>
                    </label>

                    <label class="flex cursor-pointer items-start gap-3 rounded-xl border p-3.5 transition hover:bg-slate-50 dark:border-slate-700 dark:hover:bg-slate-800/50 has-[:checked]:border-blue-600 has-[:checked]:bg-blue-50/50 dark:has-[:checked]:border-blue-500 dark:has-[:checked]:bg-blue-950/20">
                        <input type="radio" name="strategie" value="mixite_genre" class="mt-1" @checked(old('strategie') === 'mixite_genre')>
                        <div>
                            <span class="block text-sm font-bold text-slate-900 dark:text-white">Mixité de genre (Parité H / F)</span>
                            <span class="block text-xs text-slate-500 dark:text-slate-400">Ventilation veillant à équilibrer le ratio femmes/hommes dans chaque groupe candidat.</span>
                        </div>
                    </label>

                    <label class="flex cursor-pointer items-start gap-3 rounded-xl border p-3.5 transition hover:bg-slate-50 dark:border-slate-700 dark:hover:bg-slate-800/50 has-[:checked]:border-blue-600 has-[:checked]:bg-blue-50/50 dark:has-[:checked]:border-blue-500 dark:has-[:checked]:bg-blue-950/20">
                        <input type="radio" name="strategie" value="redoublants_homogenes" class="mt-1" @checked(old('strategie') === 'redoublants_homogenes')>
                        <div>
                            <span class="block text-sm font-bold text-slate-900 dark:text-white">Équilibre pédagogique (Redoublants)</span>
                            <span class="block text-xs text-slate-500 dark:text-slate-400">Répartition homogène des réinscriptions/redoublants entre tous les groupes.</span>
                        </div>
                    </label>

                    <label class="flex cursor-pointer items-start gap-3 rounded-xl border p-3.5 transition hover:bg-slate-50 dark:border-slate-700 dark:hover:bg-slate-800/50 has-[:checked]:border-blue-600 has-[:checked]:bg-blue-50/50 dark:has-[:checked]:border-blue-500 dark:has-[:checked]:bg-blue-950/20">
                        <input type="radio" name="strategie" value="alphabetique" class="mt-1" @checked(old('strategie') === 'alphabetique')>
                        <div>
                            <span class="block text-sm font-bold text-slate-900 dark:text-white">Répartition alphabétique</span>
                            <span class="block text-xs text-slate-500 dark:text-slate-400">Distribution ordonnée par nom et prénom (facilite l'émargement et les examens).</span>
                        </div>
                    </label>
                </div>
            </div>

            <hr class="border-slate-200 dark:border-slate-800">

            <!-- Section 4 : Groupes cibles -->
            <div>
                <div class="flex items-center justify-between">
                    <h3 class="text-sm font-bold uppercase tracking-wider text-slate-800 dark:text-slate-200 flex items-center gap-2">
                        <span class="flex h-6 w-6 items-center justify-center rounded-full bg-blue-100 text-xs font-bold text-blue-700 dark:bg-blue-900 dark:text-blue-300">4</span>
                        Groupes candidats *
                    </h3>
                    <div class="flex gap-2">
                        <button type="button" @click="cocherTous()" class="text-xs text-blue-600 hover:underline dark:text-blue-400">Tout cocher</button>
                        <span class="text-slate-300 dark:text-slate-700">|</span>
                        <button type="button" @click="decocherTous()" class="text-xs text-slate-600 hover:underline dark:text-slate-400">Tout décocher</button>
                    </div>
                </div>

                <div class="mt-3 max-h-64 overflow-y-auto rounded-xl border border-slate-200 p-3 space-y-1.5 dark:border-slate-700 bg-slate-50/50 dark:bg-slate-900/50">
                    @forelse($groupes as $groupe)
                        <label class="flex items-center justify-between rounded-lg p-2 text-sm hover:bg-white dark:hover:bg-slate-800 transition"
                               x-show="(!programmeFiltre || '{{ $groupe->programme_id }}' == programmeFiltre) && (!niveauFiltre || '{{ $groupe->niveau }}' == niveauFiltre)">
                            <div class="flex items-center gap-3">
                                <input type="checkbox" name="groupe_ids[]" value="{{ $groupe->id }}" class="rounded text-blue-600 focus:ring-blue-500">
                                <div>
                                    <span class="font-mono font-bold text-slate-900 dark:text-white">{{ $groupe->code }}</span>
                                    <span class="text-slate-600 dark:text-slate-400">— {{ $groupe->nom }}</span>
                                    @if($groupe->programme)
                                        <span class="ml-1 inline-flex items-center rounded bg-slate-100 px-1.5 py-0.5 text-[10px] font-medium text-slate-700 dark:bg-slate-800 dark:text-slate-300">{{ $groupe->programme->code }}</span>
                                    @endif
                                    @if($groupe->niveau)
                                        <span class="ml-1 inline-flex items-center rounded bg-blue-50 px-1.5 py-0.5 text-[10px] font-bold text-blue-700 dark:bg-blue-900/30 dark:text-blue-300">{{ $groupe->niveau }}</span>
                                    @endif
                                </div>
                            </div>
                            <div class="text-right text-xs text-slate-500 font-mono">
                                {{ $groupe->effectifActuel() }} / {{ $groupe->capacite ?? '∞' }} places
                            </div>
                        </label>
                    @empty
                        <p class="p-3 text-center text-sm text-slate-500">Aucun groupe disponible pour votre faculté.</p>
                    @endforelse
                </div>
            </div>

            <hr class="border-slate-200 dark:border-slate-800">

            <!-- Section 5 : Candidats manuels ou auto -->
            <div>
                <h3 class="text-sm font-bold uppercase tracking-wider text-slate-800 dark:text-slate-200 flex items-center gap-2">
                    <span class="flex h-6 w-6 items-center justify-center rounded-full bg-blue-100 text-xs font-bold text-blue-700 dark:bg-blue-900 dark:text-blue-300">5</span>
                    Étudiants à répartir (Optionnel)
                </h3>
                <div class="mt-2">
                    <label for="ids" class="mb-1 block text-xs font-bold uppercase text-slate-700 dark:text-slate-300">IDs étudiants spécifiques (un par ligne, max 5000)</label>
                    <textarea id="ids" name="etudiant_ids_text" rows="4" class="w-full rounded-xl border border-slate-300 bg-white px-3 py-2 font-mono text-sm dark:border-slate-700 dark:bg-slate-900" placeholder="12&#10;45&#10;78">{{ old('etudiant_ids_text', $nonAffectesIds) }}</textarea>
                    <p class="mt-1 text-xs text-slate-500">
                        Pré-rempli par défaut avec les étudiants non encore affectés. 
                        <strong>Laissez vide</strong> pour sélectionner automatiquement tous les étudiants validés correspondant aux filtres LMD ci-dessus.
                    </p>
                </div>
            </div>

            <div class="pt-2 flex items-center justify-between">
                <a href="{{ route('admin.groupes.index') }}" class="text-sm text-slate-600 hover:underline dark:text-slate-400">← Retour à la gestion des groupes</a>
                <x-btn type="submit" class="px-6 py-2.5 text-sm font-bold">Lancer la simulation (sans écriture)</x-btn>
            </div>
        </form>
    </div>
@endsection
