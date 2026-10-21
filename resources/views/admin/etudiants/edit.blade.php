@extends('layouts.app')

@section('title', 'Modifier l’étudiant — Scolarité')
@section('meta_description', 'Administration universitaire — correction de la fiche d’un dossier étudiant : identité, contact, rattachement LMD et inscription courante.')

@section('content')
@php
    $champ = 'mt-1 block w-full rounded-xl border border-slate-300 bg-white px-3.5 py-2.5 text-sm text-slate-900 shadow-sm transition focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500/20 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100';
    $libelle = 'block text-xs font-bold uppercase tracking-wider text-slate-500 dark:text-slate-400';
    $erreur = 'mt-1 text-xs font-semibold text-rose-600 dark:text-rose-400';
    $carte = 'rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900 sm:p-6';

    $faculteParDefaut = old('faculte_id', $etudiant->faculte_id);
    $faculteParDefaut = is_numeric($faculteParDefaut) ? (int) $faculteParDefaut : null;

    $programmeParDefaut = old('programme_id', $etudiant->programme_id);
    $programmeParDefaut = is_numeric($programmeParDefaut) ? (int) $programmeParDefaut : null;

    $niveauParDefaut = old('niveau_id', $etudiant->niveau_id);
    $niveauParDefaut = is_numeric($niveauParDefaut) ? (int) $niveauParDefaut : null;

    $anneeParDefaut = old('annee_universitaire_id', $inscription?->annee_universitaire_id);
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
        titre="Modifier l’étudiant"
        description="Correction de la fiche : identité, contact, rattachement LMD et inscription courante."
        :fil="['Étudiants' => route('admin.etudiants.index'), $etudiant->nom_complet => route('admin.etudiants.show', $etudiant), 'Modifier' => '#']">
        <x-slot:actions>
            <x-btn variant="secondary" :href="route('admin.etudiants.show', $etudiant)">Retour à la fiche</x-btn>
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

        <x-alert type="warning" titre="Statut, groupe et mot de passe inchangés">
            Le statut, le groupe et le mot de passe ne se modifient pas ici. Ces éléments relèvent de leurs propres workflows (changement de statut, affectation de groupe, réinitialisation du mot de passe).
        </x-alert>

        @if($inscription === null)
            <x-alert type="info" titre="Aucune inscription courante">
                Cet étudiant n’a aucune inscription annuelle. La fiche reste modifiable ; l’inscription se crée via le workflow de progression d’inscription.
            </x-alert>
        @endif
    </div>

    <form method="POST"
          action="{{ route('admin.etudiants.update', $etudiant) }}"
          class="mt-6 space-y-6"
          x-data="{}"
          x-on:submit="document.getElementById('btn-enregistrer-modifications').disabled = true">
        @csrf
        @method('PATCH')

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
                    <input id="nom" name="nom" type="text" value="{{ old('nom', $etudiant->nom) }}" required maxlength="100" autocomplete="family-name"
                           class="{{ $champ }} @error('nom') border-rose-400 @enderror">
                    @error('nom')<p class="{{ $erreur }}">{{ $message }}</p>@enderror
                </div>

                <div>
                    <label for="prenom" class="{{ $libelle }}">Prénom <span class="text-rose-500">*</span></label>
                    <input id="prenom" name="prenom" type="text" value="{{ old('prenom', $etudiant->prenom) }}" required maxlength="100" autocomplete="given-name"
                           class="{{ $champ }} @error('prenom') border-rose-400 @enderror">
                    @error('prenom')<p class="{{ $erreur }}">{{ $message }}</p>@enderror
                </div>

                <div>
                    <label for="cin" class="{{ $libelle }}">CIN / Pièce d’identité <span class="text-rose-500">*</span></label>
                    <input id="cin" name="cin" type="text" value="{{ old('cin', $etudiant->cin) }}" required maxlength="30"
                           pattern="[A-Za-z0-9\-]{5,30}" title="5 à 30 caractères : lettres, chiffres ou tirets."
                           autocomplete="off" class="{{ $champ }} @error('cin') border-rose-400 @enderror">
                    @error('cin')<p class="{{ $erreur }}">{{ $message }}</p>@enderror
                </div>

                <div>
                    <label for="identifiant_national" class="{{ $libelle }}">Identifiant national (MENS)</label>
                    <input id="identifiant_national" name="identifiant_national" type="text" value="{{ old('identifiant_national', $etudiant->identifiant_national) }}" maxlength="30"
                           class="{{ $champ }} @error('identifiant_national') border-rose-400 @enderror">
                    @error('identifiant_national')<p class="{{ $erreur }}">{{ $message }}</p>@enderror
                </div>

                <div>
                    <label for="email" class="{{ $libelle }}">Adresse email <span class="text-rose-500">*</span></label>
                    <input id="email" name="email" type="email" value="{{ old('email', $etudiant->email) }}" required maxlength="254" autocomplete="email"
                           class="{{ $champ }} @error('email') border-rose-400 @enderror">
                    @error('email')<p class="{{ $erreur }}">{{ $message }}</p>@enderror
                </div>

                <div>
                    <label for="telephone" class="{{ $libelle }}">Téléphone</label>
                    <input id="telephone" name="telephone" type="tel" value="{{ old('telephone', $etudiant->telephone) }}" maxlength="30"
                           pattern="\+?[0-9\s\-()]{7,30}" title="7 à 30 caractères : chiffres, espaces, tirets ou parenthèses."
                           autocomplete="tel" class="{{ $champ }} @error('telephone') border-rose-400 @enderror">
                    @error('telephone')<p class="{{ $erreur }}">{{ $message }}</p>@enderror
                </div>

                <div>
                    <label for="date_naissance" class="{{ $libelle }}">Date de naissance <span class="text-rose-500">*</span></label>
                    <input id="date_naissance" name="date_naissance" type="date" value="{{ old('date_naissance', $etudiant->date_naissance?->format('Y-m-d')) }}" required
                           max="{{ now()->subDay()->toDateString() }}" min="1920-01-01" autocomplete="bday"
                           class="{{ $champ }} @error('date_naissance') border-rose-400 @enderror">
                    @error('date_naissance')<p class="{{ $erreur }}">{{ $message }}</p>@enderror
                </div>

                <div>
                    <label for="sexe" class="{{ $libelle }}">Sexe <span class="text-rose-500">*</span></label>
                    <select id="sexe" name="sexe" required class="{{ $champ }} @error('sexe') border-rose-400 @enderror">
                        <option value="">— Sélectionner —</option>
                        <option value="M" @selected(old('sexe', $etudiant->sexe) === 'M')>Masculin</option>
                        <option value="F" @selected(old('sexe', $etudiant->sexe) === 'F')>Féminin</option>
                    </select>
                    @error('sexe')<p class="{{ $erreur }}">{{ $message }}</p>@enderror
                </div>

                <div class="sm:col-span-2">
                    <label for="adresse" class="{{ $libelle }}">Adresse <span class="text-rose-500">*</span></label>
                    <input id="adresse" name="adresse" type="text" value="{{ old('adresse', $etudiant->adresse) }}" required minlength="5" maxlength="500"
                           autocomplete="street-address" class="{{ $champ }} @error('adresse') border-rose-400 @enderror">
                    @error('adresse')<p class="{{ $erreur }}">{{ $message }}</p>@enderror
                </div>

                <div>
                    <label for="ville" class="{{ $libelle }}">Ville <span class="text-rose-500">*</span></label>
                    <input id="ville" name="ville" type="text" value="{{ old('ville', $etudiant->ville) }}" required minlength="2" maxlength="100"
                           autocomplete="address-level2" class="{{ $champ }} @error('ville') border-rose-400 @enderror">
                    @error('ville')<p class="{{ $erreur }}">{{ $message }}</p>@enderror
                </div>

                <div>
                    <label for="code_postal" class="{{ $libelle }}">Code postal <span class="text-rose-500">*</span></label>
                    <input id="code_postal" name="code_postal" type="text" value="{{ old('code_postal', $etudiant->code_postal) }}" required maxlength="20"
                           autocomplete="postal-code" class="{{ $champ }} @error('code_postal') border-rose-400 @enderror">
                    @error('code_postal')<p class="{{ $erreur }}">{{ $message }}</p>@enderror
                </div>

                <div>
                    <label for="annee_bac" class="{{ $libelle }}">Année du baccalauréat <span class="text-rose-500">*</span></label>
                    <input id="annee_bac" name="annee_bac" type="text" value="{{ old('annee_bac', $etudiant->annee_bac) }}" required inputmode="numeric"
                           maxlength="4" pattern="\d{4}" title="Année sur 4 chiffres, ex. 2024."
                           class="{{ $champ }} @error('annee_bac') border-rose-400 @enderror">
                    @error('annee_bac')<p class="{{ $erreur }}">{{ $message }}</p>@enderror
                </div>

                <div>
                    <label for="mention_bac" class="{{ $libelle }}">Mention au baccalauréat</label>
                    <select id="mention_bac" name="mention_bac" class="{{ $champ }} @error('mention_bac') border-rose-400 @enderror">
                        <option value="">— Non renseignée —</option>
                        @foreach($mentionsBac as $mention)
                            <option value="{{ $mention }}" @selected(old('mention_bac', $etudiant->mention_bac) === $mention)>{{ $mention }}</option>
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
                    <p class="mt-0.5 text-sm text-slate-500 dark:text-slate-400">Faculté, parcours (filière) et niveau. Le code du niveau est dérivé du référentiel, jamais saisi.</p>
                </div>
            </div>

            {{-- ── Éléments en lecture seule (workflows dédiés) ── --}}
            <div class="mb-5 grid grid-cols-1 gap-3 rounded-xl border border-slate-200 bg-slate-50 p-4 sm:grid-cols-3 dark:border-slate-700 dark:bg-slate-800/50">
                <div>
                    <p class="{{ $libelle }}">Statut du dossier (lecture seule)</p>
                    <p class="mt-1 text-sm font-bold text-slate-900 dark:text-slate-100">{{ $etudiant->statut_label }}</p>
                    <a href="{{ route('admin.etudiants.show', $etudiant) }}" class="mt-1 inline-block text-xs font-bold text-primary-700 underline underline-offset-2 dark:text-primary-300">
                        Changer le statut sur la fiche
                    </a>
                </div>
                <div>
                    <p class="{{ $libelle }}">Groupe courant (lecture seule)</p>
                    <p class="mt-1 text-sm font-bold text-slate-900 dark:text-slate-100">
                        {{ $groupeCourant?->code ?? 'Aucun groupe' }}
                        @if($groupeCourant)<span class="font-normal text-slate-500">— {{ $groupeCourant->nom }}</span>@endif
                    </p>
                    <a href="{{ route('admin.etudiants.show', $etudiant) }}" class="mt-1 inline-block text-xs font-bold text-primary-700 underline underline-offset-2 dark:text-primary-300">
                        Gérer l’affectation sur la fiche
                    </a>
                </div>
                <div>
                    <p class="{{ $libelle }}">Année d’inscription courante (lecture seule)</p>
                    <p class="mt-1 text-sm font-bold text-slate-900 dark:text-slate-100">
                        {{ $inscription?->anneeUniversitaire?->libelle ?? 'Aucune inscription' }}
                    </p>
                    <span class="mt-1 inline-block text-xs text-slate-500">L’année ne change pas ici.</span>
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
                    <label for="annee_universitaire_id" class="{{ $libelle }}">Année universitaire (lecture seule)</label>
                    <select id="annee_universitaire_id" name="annee_universitaire_id" disabled data-lecture-seule="1"
                            class="{{ $champ }} cursor-not-allowed opacity-70">
                        @foreach($annees as $annee)
                            <option value="{{ $annee->id }}" @selected((string) $anneeParDefaut === (string) $annee->id)>
                                {{ $annee->libelle }}@if($annee->active) — active @endif
                            </option>
                        @endforeach
                    </select>
                </div>

                <div class="sm:col-span-2">
                    <label for="programme_id" class="{{ $libelle }}">Parcours / filière <span class="text-rose-500">*</span></label>
                    <select id="programme_id" name="programme_id" required class="{{ $champ }} @error('programme_id') border-rose-400 @enderror">
                        <option value="">— Sélectionner —</option>
                        @foreach($programmes as $programme)
                            <option value="{{ $programme->id }}"
                                    data-faculte="{{ $programme->faculte_id }}"
                                    @selected((string) $programmeParDefaut === (string) $programme->id)>
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
                                    @selected((string) $niveauParDefaut === (string) $niveau->id)>
                                {{ $niveau->code }} — {{ $niveau->nom }}
                            </option>
                        @endforeach
                    </select>
                    @error('niveau_id')<p class="{{ $erreur }}">{{ $message }}</p>@enderror
                </div>

                <div>
                    <label for="groupe_id" class="{{ $libelle }}">Groupe / classe (lecture seule)</label>
                    {{-- Select « lecture seule » : il n'est JAMAIS reconstruit par la
                         cascade JS (le groupe courant reste donc affiché et
                         sélectionné) et ne peut jamais être réactivé. --}}
                    <select id="groupe_id" name="groupe_id" disabled data-lecture-seule="1"
                            class="{{ $champ }} cursor-not-allowed opacity-70">
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
                                    @selected((string) old('groupe_id', $etudiant->groupe_id) === (string) $groupe->id)>
                                {{ $groupe->code }} — {{ $groupe->nom }} ({{ (int) ($groupe->effectif_actuel ?? 0) }}/{{ $capacite }})
                            </option>
                        @endforeach
                    </select>
                    <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                        Le groupe se change uniquement via le workflow d’affectation (historisé, simulé, audité).
                    </p>
                </div>

                <div class="sm:col-span-2">
                    <label for="motif_modification" class="{{ $libelle }}">Motif de la modification <span class="text-rose-500">*</span></label>
                    <textarea id="motif_modification" name="motif_modification" rows="3" required minlength="10" maxlength="255"
                              class="{{ $champ }} @error('motif_modification') border-rose-400 @enderror"
                              placeholder="Ex. Faute de frappe sur le CIN relevée au guichet, correction validée le …">{{ old('motif_modification') }}</textarea>
                    @error('motif_modification')<p class="{{ $erreur }}">{{ $message }}</p>@enderror
                    <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                        Ce motif est enregistré dans l’historique académique de l’étudiant.
                    </p>
                </div>
            </div>
        </section>

        {{-- ── Inscription courante ── --}}
        @if($inscription !== null)
            <section class="{{ $carte }}" aria-labelledby="section-inscription">
                <div class="mb-5 flex items-start gap-3">
                    <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300">
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 8.25h19.5M2.25 9h19.5m-16.5 5.25h6m-6 2.25h3m-3.75 3h15a2.25 2.25 0 0 0 2.25-2.25V6.75A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25v10.5A2.25 2.25 0 0 0 4.5 19.5Z" />
                        </svg>
                    </div>
                    <div>
                        <h2 id="section-inscription" class="text-lg font-black tracking-tight text-slate-900 dark:text-slate-100">Inscription courante</h2>
                        <p class="mt-0.5 text-sm text-slate-500 dark:text-slate-400">
                            Inscription {{ $inscription->anneeUniversitaire?->libelle }} : type, régime d’études et statut de paiement.
                        </p>
                    </div>
                </div>

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div>
                        <label for="type_inscription" class="{{ $libelle }}">Type d’inscription <span class="text-rose-500">*</span></label>
                        <select id="type_inscription" name="type_inscription" required
                                class="{{ $champ }} @error('type_inscription') border-rose-400 @enderror">
                            @foreach($typesInscription as $valeur => $texte)
                                <option value="{{ $valeur }}" @selected(old('type_inscription', $inscription->type) === $valeur)>{{ $texte }}</option>
                            @endforeach
                        </select>
                        @error('type_inscription')<p class="{{ $erreur }}">{{ $message }}</p>@enderror
                    </div>

                    <div>
                        <label for="regime" class="{{ $libelle }}">Régime d’études <span class="text-rose-500">*</span></label>
                        <select id="regime" name="regime" required class="{{ $champ }} @error('regime') border-rose-400 @enderror">
                            <option value="initiale" @selected(old('regime', $inscription->regime) === 'initiale')>Initiale (présentiel)</option>
                            <option value="continue" @selected(old('regime', $inscription->regime) === 'continue')>Formation continue</option>
                            <option value="alternance" @selected(old('regime', $inscription->regime) === 'alternance')>Alternance</option>
                        </select>
                        @error('regime')<p class="{{ $erreur }}">{{ $message }}</p>@enderror
                    </div>

                    <div>
                        <label for="paye" class="{{ $libelle }}">Paiement effectué ? <span class="text-rose-500">*</span></label>
                        <select id="paye" name="paye" required class="{{ $champ }} @error('paye') border-rose-400 @enderror">
                            <option value="0" @selected((string) old('paye', $inscription->paye ? '1' : '0') === '0')>Non</option>
                            <option value="1" @selected((string) old('paye', $inscription->paye ? '1' : '0') === '1')>Oui</option>
                        </select>
                        @error('paye')<p class="{{ $erreur }}">{{ $message }}</p>@enderror
                        <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                            Aucune facture ni transaction de paiement n’est enregistrée dans l’application.
                        </p>
                    </div>
                </div>
            </section>
        @endif

        <div class="flex flex-col-reverse gap-3 sm:flex-row sm:items-center sm:justify-between">
            <p class="text-xs text-slate-500 dark:text-slate-400">
                Les modifications sont journalisées dans l’historique du dossier.
            </p>
            <div class="flex flex-wrap gap-2">
                <x-btn variant="secondary" :href="route('admin.etudiants.show', $etudiant)">Annuler</x-btn>
                <x-btn type="submit" id="btn-enregistrer-modifications">
                    Enregistrer les modifications
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
        const groupe = document.getElementById('groupe_id');

        if (!faculte || !programme || !niveau || !groupe) {
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

        const valeurInitiale = {
            programme: programme.value,
            niveau: niveau.value,
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

            // Un select marqué « lecture seule » (groupe, année) ne peut
            // JAMAIS être réactivé par la cascade.
            select.disabled = select.dataset.lectureSeule === '1' ? true : items.length === 0;
        }

        function rafraichirProgrammes(valeurProgramme = null, valeurNiveau = null) {
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
            rafraichirNiveaux(valeurNiveau);
        }

        function rafraichirNiveaux(valeurNiveau = null) {
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
        }

        faculte.addEventListener('change', () => {
            rafraichirProgrammes();
        });

        programme.addEventListener('change', () => {
            rafraichirNiveaux();
        });

        rafraichirProgrammes(
            valeurInitiale.programme || null,
            valeurInitiale.niveau || null
        );

        // Le groupe n'est PAS modifiable sur cet écran (workflow
        // d'affectation dédié) : le select reste désactivé en permanence
        // et n'est jamais reconstruit par la cascade — le groupe courant
        // reste donc affiché et sélectionné.
        groupe.disabled = true;
    })();
</script>
@endpush
