@extends('layouts.app')

@section('title', 'Créer un étudiant — Scolarité')
@section('meta_description', 'Administration universitaire — création d’un dossier étudiant complet : identité, parcours, niveau, groupe et statut de paiement.')

@section('content')
@php
    $champ = 'mt-1 block w-full rounded-xl border border-slate-300 bg-white px-3.5 py-2.5 text-sm text-slate-900 shadow-sm transition focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500/20 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100';
    $libelle = 'block text-xs font-bold uppercase tracking-wider text-slate-500 dark:text-slate-400';
    $erreur = 'mt-1 text-xs font-semibold text-rose-600 dark:text-rose-400';
    $carte = 'rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900 sm:p-6';

    $faculteParDefaut = old('faculte_id', $facultes->count() === 1 ? $facultes->first()->id : null);
    $faculteParDefaut = is_numeric($faculteParDefaut) ? (int) $faculteParDefaut : null;

    $anneeParDefaut = old('annee_universitaire_id', $anneeActive?->id ?? $annees->first()?->id);
    $anneeParDefaut = is_numeric($anneeParDefaut) ? (int) $anneeParDefaut : null;

    $typesInscription = [
        'premiere' => 'Première inscription',
        'reinscription' => 'Réinscription',
        'transfert' => 'Transfert',
        'mutation' => 'Mutation',
        'reprise' => 'Reprise d’études',
        'equivalence' => 'Équivalence',
        'autre' => 'Autre',
    ];

    $mentionsBac = ['Passable', 'Assez Bien', 'Bien', 'Très Bien', 'Excellent'];
@endphp

<div class="mx-auto max-w-6xl px-4 py-8 sm:px-6 lg:px-8">
    <x-page-header
        titre="Créer un étudiant"
        description="Dossier complet : identité, faculté, parcours, niveau, groupe et statut de paiement."
        :fil="['Étudiants' => route('admin.etudiants.index'), 'Créer un étudiant' => '#']">
        <x-slot:actions>
            <x-btn variant="secondary" :href="route('admin.etudiants.index')">Retour au registre</x-btn>
        </x-slot:actions>
    </x-page-header>

    <div class="mt-4 space-y-3">
        @if($errors->any())
            <div role="alert" class="rounded-xl border border-rose-300 bg-rose-50 px-4 py-3 text-xs text-rose-900 sm:text-sm">
                <p class="flex items-start gap-2">
                    <span aria-hidden="true" class="font-black">✕</span>
                    <span>
                        <strong>Veuillez corriger les champs suivants.</strong>
                        <ul class="mt-1 list-inside list-disc space-y-1">
                            @foreach($errors->all() as $message)
                                <li>{{ $message }}</li>
                            @endforeach
                        </ul>
                    </span>
                </p>
            </div>
        @endif

        <x-alert type="warning" titre="Validation immédiate de l’inscription">
            Vous créez un dossier validé et une inscription validée sans passer par le contrôle documentaire public. Cette décision est journalisée et exige un motif explicite.
        </x-alert>
    </div>

    <form method="POST"
          action="{{ route('admin.etudiants.store') }}"
          class="mt-6 space-y-6"
          x-data="{}"
          x-on:submit="document.getElementById('btn-creer-etudiant').disabled = true">
        @csrf

        {{-- ── Identité ── --}}
        <section class="{{ $carte }}" aria-labelledby="section-identite">
            <div class="mb-5 flex items-start gap-3">
                <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-primary-100 text-primary-700 dark:bg-primary-900/40 dark:text-primary-300">
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0ZM4.501 20.118a7.5 7.5 0 0 1 14.998 0A17.933 17.933 0 0 1 12 21.75c-2.676 0-5.216-.584-7.499-1.632Z" />
                    </svg>
                </div>
                <div>
                    <h2 id="section-identite" class="text-lg font-black tracking-tight text-slate-900 dark:text-slate-100">Identité de l’étudiant</h2>
                    <p class="mt-0.5 text-sm text-slate-500 dark:text-slate-400">Les champs marqués d’un astérisque sont obligatoires.</p>
                </div>
            </div>

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div>
                    <label for="nom" class="{{ $libelle }}">Nom <span class="text-rose-500">*</span></label>
                    <input id="nom" name="nom" type="text" value="{{ old('nom') }}" required maxlength="100" autocomplete="family-name"
                           class="{{ $champ }} @error('nom') border-rose-400 @enderror">
                    @error('nom')<p class="{{ $erreur }}">{{ $message }}</p>@enderror
                </div>

                <div>
                    <label for="prenom" class="{{ $libelle }}">Prénom <span class="text-rose-500">*</span></label>
                    <input id="prenom" name="prenom" type="text" value="{{ old('prenom') }}" required maxlength="100" autocomplete="given-name"
                           class="{{ $champ }} @error('prenom') border-rose-400 @enderror">
                    @error('prenom')<p class="{{ $erreur }}">{{ $message }}</p>@enderror
                </div>

                <div>
                    <label for="cin" class="{{ $libelle }}">CIN / Pièce d’identité <span class="text-rose-500">*</span></label>
                    <input id="cin" name="cin" type="text" value="{{ old('cin') }}" required maxlength="30"
                           pattern="[A-Za-z0-9\-]{5,30}" title="5 à 30 caractères : lettres, chiffres ou tirets."
                           autocomplete="off" class="{{ $champ }} @error('cin') border-rose-400 @enderror">
                    @error('cin')<p class="{{ $erreur }}">{{ $message }}</p>@enderror
                </div>

                <div>
                    <label for="identifiant_national" class="{{ $libelle }}">Identifiant national (MENS)</label>
                    <input id="identifiant_national" name="identifiant_national" type="text" value="{{ old('identifiant_national') }}" maxlength="30"
                           class="{{ $champ }} @error('identifiant_national') border-rose-400 @enderror">
                    @error('identifiant_national')<p class="{{ $erreur }}">{{ $message }}</p>@enderror
                </div>

                <div>
                    <label for="email" class="{{ $libelle }}">Adresse email <span class="text-rose-500">*</span></label>
                    <input id="email" name="email" type="email" value="{{ old('email') }}" required maxlength="254" autocomplete="email"
                           class="{{ $champ }} @error('email') border-rose-400 @enderror">
                    @error('email')<p class="{{ $erreur }}">{{ $message }}</p>@enderror
                </div>

                <div>
                    <label for="telephone" class="{{ $libelle }}">Téléphone</label>
                    <input id="telephone" name="telephone" type="tel" value="{{ old('telephone') }}" maxlength="30"
                           pattern="\+?[0-9\s\-()]{7,30}" title="7 à 30 caractères : chiffres, espaces, tirets ou parenthèses."
                           autocomplete="tel" class="{{ $champ }} @error('telephone') border-rose-400 @enderror">
                    @error('telephone')<p class="{{ $erreur }}">{{ $message }}</p>@enderror
                </div>

                <div>
                    <label for="date_naissance" class="{{ $libelle }}">Date de naissance <span class="text-rose-500">*</span></label>
                    <input id="date_naissance" name="date_naissance" type="date" value="{{ old('date_naissance') }}" required
                           max="{{ now()->subDay()->toDateString() }}" min="1920-01-01" autocomplete="bday"
                           class="{{ $champ }} @error('date_naissance') border-rose-400 @enderror">
                    @error('date_naissance')<p class="{{ $erreur }}">{{ $message }}</p>@enderror
                </div>

                <div>
                    <label for="sexe" class="{{ $libelle }}">Sexe <span class="text-rose-500">*</span></label>
                    <select id="sexe" name="sexe" required class="{{ $champ }} @error('sexe') border-rose-400 @enderror">
                        <option value="">— Sélectionner —</option>
                        <option value="M" @selected(old('sexe') === 'M')>Masculin</option>
                        <option value="F" @selected(old('sexe') === 'F')>Féminin</option>
                    </select>
                    @error('sexe')<p class="{{ $erreur }}">{{ $message }}</p>@enderror
                </div>

                <div class="sm:col-span-2">
                    <label for="adresse" class="{{ $libelle }}">Adresse <span class="text-rose-500">*</span></label>
                    <input id="adresse" name="adresse" type="text" value="{{ old('adresse') }}" required minlength="5" maxlength="500"
                           autocomplete="street-address" class="{{ $champ }} @error('adresse') border-rose-400 @enderror">
                    @error('adresse')<p class="{{ $erreur }}">{{ $message }}</p>@enderror
                </div>

                <div>
                    <label for="ville" class="{{ $libelle }}">Ville <span class="text-rose-500">*</span></label>
                    <input id="ville" name="ville" type="text" value="{{ old('ville') }}" required minlength="2" maxlength="100"
                           autocomplete="address-level2" class="{{ $champ }} @error('ville') border-rose-400 @enderror">
                    @error('ville')<p class="{{ $erreur }}">{{ $message }}</p>@enderror
                </div>

                <div>
                    <label for="code_postal" class="{{ $libelle }}">Code postal <span class="text-rose-500">*</span></label>
                    <input id="code_postal" name="code_postal" type="text" value="{{ old('code_postal') }}" required maxlength="20"
                           autocomplete="postal-code" class="{{ $champ }} @error('code_postal') border-rose-400 @enderror">
                    @error('code_postal')<p class="{{ $erreur }}">{{ $message }}</p>@enderror
                </div>

                <div>
                    <label for="annee_bac" class="{{ $libelle }}">Année du baccalauréat <span class="text-rose-500">*</span></label>
                    <input id="annee_bac" name="annee_bac" type="text" value="{{ old('annee_bac') }}" required inputmode="numeric"
                           maxlength="4" pattern="\d{4}" title="Année sur 4 chiffres, ex. 2024."
                           class="{{ $champ }} @error('annee_bac') border-rose-400 @enderror">
                    @error('annee_bac')<p class="{{ $erreur }}">{{ $message }}</p>@enderror
                </div>

                <div>
                    <label for="mention_bac" class="{{ $libelle }}">Mention au baccalauréat</label>
                    <select id="mention_bac" name="mention_bac" class="{{ $champ }} @error('mention_bac') border-rose-400 @enderror">
                        <option value="">— Non renseignée —</option>
                        @foreach($mentionsBac as $mention)
                            <option value="{{ $mention }}" @selected(old('mention_bac') === $mention)>{{ $mention }}</option>
                        @endforeach
                    </select>
                    @error('mention_bac')<p class="{{ $erreur }}">{{ $message }}</p>@enderror
                </div>
            </div>
        </section>

        {{-- ── Scolarité ── --}}
        <section class="{{ $carte }}" aria-labelledby="section-scolarite">
            <div class="mb-5 flex items-start gap-3">
                <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-indigo-100 text-indigo-700 dark:bg-indigo-900/40 dark:text-indigo-300">
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M4.26 10.147a60.438 60.438 0 0 0-.491 6.347A48.62 48.62 0 0 1 12 20.904a48.62 48.62 0 0 1 8.232-4.41 60.46 60.46 0 0 0-.491-6.347m-15.482 0a50.636 50.636 0 0 0-2.658-.813A59.906 59.906 0 0 1 12 3.493a59.903 59.903 0 0 1 10.399 5.84c-.896.248-1.783.52-2.658.814m-15.482 0A50.717 50.717 0 0 1 12 13.489a50.702 50.702 0 0 1 7.74-3.342M6.75 15a.75.75 0 1 0 0-1.5.75.75 0 0 0 0 1.5Zm0 0v-3.675A55.378 55.378 0 0 1 12 8.443m-7.007 11.55A5.981 5.981 0 0 0 6.75 15.75v-1.5" />
                    </svg>
                </div>
                <div>
                    <h2 id="section-scolarite" class="text-lg font-black tracking-tight text-slate-900 dark:text-slate-100">Rattachement académique</h2>
                    <p class="mt-0.5 text-sm text-slate-500 dark:text-slate-400">Faculté, parcours (filière), niveau et groupe. La filière et le libellé du groupe sont dérivés automatiquement.</p>
                </div>
            </div>

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div>
                    <label for="faculte_id" class="{{ $libelle }}">Faculté <span class="text-rose-500">*</span></label>
                    <select id="faculte_id" name="faculte_id" required class="{{ $champ }} @error('faculte_id') border-rose-400 @enderror">
                        <option value="">— Sélectionner —</option>
                        @foreach($facultes as $faculte)
                            <option value="{{ $faculte->id }}" @selected((string) $faculteParDefaut === (string) $faculte->id)>
                                {{ $faculte->nom }} ({{ $faculte->code }})
                            </option>
                        @endforeach
                    </select>
                    @error('faculte_id')<p class="{{ $erreur }}">{{ $message }}</p>@enderror
                </div>

                <div>
                    <label for="annee_universitaire_id" class="{{ $libelle }}">Année universitaire <span class="text-rose-500">*</span></label>
                    <select id="annee_universitaire_id" name="annee_universitaire_id" required
                            class="{{ $champ }} @error('annee_universitaire_id') border-rose-400 @enderror">
                        <option value="">— Sélectionner —</option>
                        @foreach($annees as $annee)
                            <option value="{{ $annee->id }}" @selected((string) $anneeParDefaut === (string) $annee->id)>
                                {{ $annee->libelle }}@if($annee->active) — active @endif
                            </option>
                        @endforeach
                    </select>
                    @error('annee_universitaire_id')<p class="{{ $erreur }}">{{ $message }}</p>@enderror
                </div>

                <div class="sm:col-span-2">
                    <label for="programme_id" class="{{ $libelle }}">Parcours / filière <span class="text-rose-500">*</span></label>
                    <select id="programme_id" name="programme_id" required class="{{ $champ }} @error('programme_id') border-rose-400 @enderror">
                        <option value="">— Sélectionner —</option>
                        @foreach($programmes as $programme)
                            <option value="{{ $programme->id }}"
                                    data-faculte="{{ $programme->faculte_id }}"
                                    @selected((string) old('programme_id') === (string) $programme->id)>
                                {{ $programme->code }} — {{ $programme->nom }}
                            </option>
                        @endforeach
                    </select>
                    @error('programme_id')<p class="{{ $erreur }}">{{ $message }}</p>@enderror
                </div>

                <div>
                    <label for="niveau_id" class="{{ $libelle }}">Niveau <span class="text-rose-500">*</span></label>
                    <select id="niveau_id" name="niveau_id" required class="{{ $champ }} @error('niveau_id') border-rose-400 @enderror">
                        <option value="">— Sélectionner —</option>
                        @foreach($niveaux as $niveau)
                            <option value="{{ $niveau->id }}"
                                    data-programme="{{ $niveau->programme_id }}"
                                    data-code="{{ $niveau->code }}"
                                    @selected((string) old('niveau_id') === (string) $niveau->id)>
                                {{ $niveau->code }} — {{ $niveau->nom }}
                            </option>
                        @endforeach
                    </select>
                    @error('niveau_id')<p class="{{ $erreur }}">{{ $message }}</p>@enderror
                </div>

                <div>
                    <label for="groupe_id" class="{{ $libelle }}">Groupe / classe</label>
                    <select id="groupe_id" name="groupe_id" class="{{ $champ }} @error('groupe_id') border-rose-400 @enderror">
                        <option value="">— Aucun groupe (affectation ultérieure) —</option>
                        @foreach($groupes as $groupe)
                            @php
                                $capacite = $groupe->capacite_illimitee
                                    ? 'illimitée'
                                    : ($groupe->capacite !== null ? $groupe->capacite : 'non définie');
                            @endphp
                            <option value="{{ $groupe->id }}"
                                    data-faculte="{{ $groupe->faculte_id }}"
                                    data-programme="{{ $groupe->programme_id }}"
                                    data-annee="{{ $groupe->annee_universitaire_id }}"
                                    data-niveau-id="{{ $groupe->niveau_id }}"
                                    data-niveau-code="{{ $groupe->niveau }}"
                                    @selected((string) old('groupe_id') === (string) $groupe->id)>
                                {{ $groupe->code }} — {{ $groupe->nom }} ({{ (int) ($groupe->effectif_actuel ?? 0) }}/{{ $capacite }})
                            </option>
                        @endforeach
                    </select>
                    @error('groupe_id')<p class="{{ $erreur }}">{{ $message }}</p>@enderror
                    <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                        Laisser vide pour laisser le moteur d’affectation proposer un groupe compatible après validation.
                    </p>
                </div>

                <div>
                    <label for="type_inscription" class="{{ $libelle }}">Type d’inscription <span class="text-rose-500">*</span></label>
                    <select id="type_inscription" name="type_inscription" required
                            class="{{ $champ }} @error('type_inscription') border-rose-400 @enderror">
                        @foreach($typesInscription as $valeur => $texte)
                            <option value="{{ $valeur }}" @selected(old('type_inscription', 'premiere') === $valeur)>{{ $texte }}</option>
                        @endforeach
                    </select>
                    @error('type_inscription')<p class="{{ $erreur }}">{{ $message }}</p>@enderror
                </div>

                <div>
                    <label for="regime" class="{{ $libelle }}">Régime d’études <span class="text-rose-500">*</span></label>
                    <select id="regime" name="regime" required class="{{ $champ }} @error('regime') border-rose-400 @enderror">
                        <option value="initiale" @selected(old('regime', 'initiale') === 'initiale')>Initiale (présentiel)</option>
                        <option value="continue" @selected(old('regime') === 'continue')>Formation continue</option>
                        <option value="alternance" @selected(old('regime') === 'alternance')>Alternance</option>
                    </select>
                    @error('regime')<p class="{{ $erreur }}">{{ $message }}</p>@enderror
                </div>

                <div class="sm:col-span-2">
                    <label for="motif_validation" class="{{ $libelle }}">Motif de validation immédiate <span class="text-rose-500">*</span></label>
                    <textarea id="motif_validation" name="motif_validation" rows="3" required minlength="10" maxlength="255"
                              class="{{ $champ }} @error('motif_validation') border-rose-400 @enderror"
                              placeholder="Ex. Dossier vérifié au guichet, pièces originales présentées le …">{{ old('motif_validation') }}</textarea>
                    @error('motif_validation')<p class="{{ $erreur }}">{{ $message }}</p>@enderror
                    <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                        Ce motif est enregistré dans l’historique académique de l’étudiant.
                    </p>
                </div>
            </div>
        </section>

        {{-- ── Paiement ── --}}
        <section class="{{ $carte }}" aria-labelledby="section-paiement">
            <div class="mb-5 flex items-start gap-3">
                <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300">
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 8.25h19.5M2.25 9h19.5m-16.5 5.25h6m-6 2.25h3m-3.75 3h15a2.25 2.25 0 0 0 2.25-2.25V6.75A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25v10.5A2.25 2.25 0 0 0 4.5 19.5Z" />
                    </svg>
                </div>
                <div>
                    <h2 id="section-paiement" class="text-lg font-black tracking-tight text-slate-900 dark:text-slate-100">Paiement</h2>
                    <p class="mt-0.5 text-sm text-slate-500 dark:text-slate-400">
                        Le paiement est effectué sur le site externe. Indiquez simplement s’il a été effectué.
                    </p>
                </div>
            </div>

            <div class="max-w-md">
                <label for="paye" class="{{ $libelle }}">Paiement effectué ? <span class="text-rose-500">*</span></label>
                <select id="paye" name="paye" required class="{{ $champ }} @error('paye') border-rose-400 @enderror">
                    <option value="0" @selected((string) old('paye', '0') === '0')>Non</option>
                    <option value="1" @selected((string) old('paye') === '1')>Oui</option>
                </select>
                @error('paye')<p class="{{ $erreur }}">{{ $message }}</p>@enderror
                <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                    Aucune facture ni transaction de paiement n’est enregistrée dans l’application.
                </p>
            </div>
        </section>

        <div class="flex flex-col-reverse gap-3 sm:flex-row sm:items-center sm:justify-between">
            <p class="text-xs text-slate-500 dark:text-slate-400">
                Un mot de passe provisoire sera généré et affiché une seule fois après la création.
            </p>
            <div class="flex flex-wrap gap-2">
                <x-btn variant="secondary" :href="route('admin.etudiants.index')">Annuler</x-btn>
                <x-btn type="submit" id="btn-creer-etudiant">
                    Créer l’étudiant et valider
                </x-btn>
            </div>
        </div>
    </form>
</div>
@endsection

@push('scripts')
<script>
    (function () {
        const faculte = document.getElementById('faculte_id');
        const programme = document.getElementById('programme_id');
        const niveau = document.getElementById('niveau_id');
        const annee = document.getElementById('annee_universitaire_id');
        const groupe = document.getElementById('groupe_id');

        if (!faculte || !programme || !niveau || !annee || !groupe) {
            return;
        }

        const lireOptions = (select) => Array.from(select.options)
            .filter((option) => option.value !== '')
            .map((option) => ({
                value: option.value,
                label: option.textContent,
                dataset: Object.assign({}, option.dataset),
            }));

        const programmes = lireOptions(programme);
        const niveaux = lireOptions(niveau);
        const groupes = lireOptions(groupe);

        const valeurInitiale = {
            programme: programme.value,
            niveau: niveau.value,
            groupe: groupe.value,
        };

        function remplir(select, items, valeur, texteVide) {
            select.innerHTML = '';

            const vide = document.createElement('option');
            vide.value = '';
            vide.textContent = texteVide;
            select.appendChild(vide);

            items.forEach((item) => {
                const option = document.createElement('option');
                option.value = item.value;
                option.textContent = item.label;
                Object.entries(item.dataset).forEach(([cle, val]) => {
                    option.dataset[cle] = val;
                });
                select.appendChild(option);
            });

            if (valeur !== null && items.some((item) => item.value === valeur)) {
                select.value = valeur;
            } else {
                select.value = '';
            }

            select.disabled = items.length === 0;
        }

        function codeNiveau() {
            return niveau.selectedOptions[0]?.dataset.code ?? '';
        }

        function rafraichirProgrammes(valeurProgramme = null, valeurNiveau = null, valeurGroupe = null) {
            const faculteId = faculte.value;
            const items = faculteId
                ? programmes.filter((item) => item.dataset.faculte === faculteId)
                : [];

            remplir(
                programme,
                items,
                valeurProgramme,
                faculteId ? '— Sélectionner un parcours —' : '— Sélectionnez d’abord une faculté —'
            );
            rafraichirNiveaux(valeurNiveau, valeurGroupe);
        }

        function rafraichirNiveaux(valeurNiveau = null, valeurGroupe = null) {
            const programmeId = programme.value;
            const items = programmeId
                ? niveaux.filter((item) => item.dataset.programme === programmeId)
                : [];

            remplir(
                niveau,
                items,
                valeurNiveau,
                programmeId ? '— Sélectionner un niveau —' : '— Sélectionnez d’abord un parcours —'
            );
            rafraichirGroupes(valeurGroupe);
        }

        function rafraichirGroupes(valeurGroupe = null) {
            const faculteId = faculte.value;
            const programmeId = programme.value;
            const anneeId = annee.value;
            const niveauId = niveau.value;
            const code = codeNiveau();

            if (!anneeId) {
                remplir(groupe, [], valeurGroupe, '— Sélectionnez d’abord une année —');

                return;
            }

            if (!niveauId && !code) {
                remplir(groupe, [], valeurGroupe, '— Sélectionnez d’abord un niveau —');

                return;
            }

            const items = groupes.filter((item) => {
                const okAnnee = !item.dataset.annee || item.dataset.annee === anneeId;
                const okFaculte = !faculteId || !item.dataset.faculte || item.dataset.faculte === faculteId;
                const okProgramme = !programmeId || !item.dataset.programme || item.dataset.programme === programmeId;
                const okNiveau = item.dataset.niveauId
                    ? item.dataset.niveauId === niveauId
                    : (!item.dataset.niveauCode || item.dataset.niveauCode === code);

                return okAnnee && okFaculte && okProgramme && okNiveau;
            });

            remplir(groupe, items, valeurGroupe, '— Aucun groupe (affectation ultérieure) —');
        }

        faculte.addEventListener('change', () => {
            rafraichirProgrammes();
        });

        programme.addEventListener('change', () => {
            rafraichirNiveaux();
        });

        niveau.addEventListener('change', () => {
            rafraichirGroupes();
        });

        annee.addEventListener('change', () => {
            rafraichirGroupes();
        });

        rafraichirProgrammes(
            valeurInitiale.programme || null,
            valeurInitiale.niveau || null,
            valeurInitiale.groupe || null
        );
    })();
</script>
@endpush
