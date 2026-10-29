@extends('layouts.app')

@section('title', isset($reference) ? 'Modifier référence MENS — Scolarité' : 'Nouvelle référence MENS — Scolarité')

@section('content')
    <div class="mx-auto max-w-3xl px-4 sm:px-6 py-8"
         x-data="assistanceMens(@js([
             'urlReferentiels' => route('admin.mens.referentiels'),
             'urlAssistance' => route('admin.mens.assistance'),
             'ignorer' => isset($reference) ? $reference->id : null,
         ]))"
         x-init="chargerReferentiels()">
        <x-page-header
            :titre="isset($reference) ? 'Modifier la référence ' . $reference->cin_normalise . ' (' . $reference->annee . ')' : 'Ajouter une référence MENS'"
            description="Mêmes règles que l'import officiel : CIN 8 chiffres, unicité CIN + année. Saisie assistée : l'identité se pré-complète depuis le dossier local. Chaque écriture est journalisée (CIN masquée)."
            :fil="['Données' => route('admin.donnees.hub'), 'MENS' => route('admin.mens.index'), 'Édition' => '#']" />

        @if($errors->any())
            <div class="mt-4"><x-alert type="error" titre="Veuillez corriger les champs suivants.">
                <ul class="list-disc list-inside mt-1">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
            </x-alert></div>
        @endif

        <form method="POST" action="{{ isset($reference) ? route('admin.mens.update', $reference) : route('admin.mens.store') }}"
              class="mt-4 ds-card p-6 space-y-5" @submit="envoi = true">
            @csrf
            @if(isset($reference)) @method('PUT') @endif

            <div>
                <h2 class="text-sm font-bold text-slate-900">Identité (données nationales)</h2>
                <p class="mt-1 text-xs text-slate-500">
                    Saisissez la CIN : si un dossier local correspond, les champs vides se complètent automatiquement
                    et un éventuel doublon CIN + année est signalé avant l'enregistrement (le serveur reste seul juge).
                </p>
                <div class="mt-3 grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <x-input id="cin" name="cin" label="CIN (8 chiffres)" required inputmode="numeric" maxlength="8"
                                 pattern="[0-9]{8}" placeholder="12345678" autocomplete="off"
                                 :autofocus="! isset($reference)"
                                 class="font-mono tracking-wide"
                                 oninput="this.value = this.value.replace(/[^0-9]/g, '').slice(0, 8)"
                                 x-on:input.debounce.400ms="sonderCin()"
                                 value="{{ old('cin', $reference->cin_normalise ?? '') }}" />
                        <div aria-live="polite" class="mt-1.5 space-y-0.5 text-[11px] leading-relaxed">
                            <p x-show="rechercheCin" x-cloak class="text-slate-500">Recherche locale…</p>
                            <p x-show="!rechercheCin && prefill" x-cloak class="font-semibold text-emerald-700">
                                ✓ <span x-text="apercu"></span> — champs vides pré-complétés (modifiables).
                            </p>
                            <p x-show="!rechercheCin && ambigu" x-cloak class="font-semibold text-amber-700">
                                ⚠ Plusieurs dossiers locaux partagent cette CIN : pré-remplissage bloqué, vérification manuelle requise.
                            </p>
                            <p x-show="!rechercheCin && doublon" x-cloak class="font-semibold text-amber-700">
                                ⚠ Une référence MENS existe déjà pour cette CIN et cette année — l'enregistrement la refusera.
                            </p>
                        </div>
                    </div>
                    <x-input id="date_naissance" name="date_naissance" type="date" label="Date de naissance"
                             value="{{ old('date_naissance', $reference?->date_naissance?->format('Y-m-d') ?? '') }}" />
                    <x-input id="nom" name="nom" label="Nom (latin)" required maxlength="100"
                             value="{{ old('nom', $reference->nom ?? '') }}" />
                    <x-input id="prenom" name="prenom" label="Prénom (latin)" required maxlength="100"
                             value="{{ old('prenom', $reference->prenom ?? '') }}" />
                    <x-input id="nom_ar" name="nom_ar" label="Nom (arabe)" maxlength="100" dir="rtl"
                             value="{{ old('nom_ar', $reference->nom_ar ?? '') }}" />
                    <x-input id="prenom_ar" name="prenom_ar" label="Prénom (arabe)" maxlength="100" dir="rtl"
                             value="{{ old('prenom_ar', $reference->prenom_ar ?? '') }}" />
                    <x-select id="sexe" name="sexe" label="Sexe" vide="— Non précisé —"
                              :valeur="old('sexe', $reference->sexe ?? null)"
                              :options="['M' => 'M (masculin)', 'F' => 'F (féminin)']" />
                    <x-input id="nationalite" name="nationalite" label="Nationalité" maxlength="50"
                             list="liste-nationalites"
                             value="{{ old('nationalite', $reference->nationalite ?? '') }}" />
                </div>
            </div>

            <div>
                <h2 class="text-sm font-bold text-slate-900">Rattachement &amp; scolarité</h2>
                <div class="mt-3 grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <x-input id="annee" name="annee" label="Année (clé avec la CIN)" required maxlength="20"
                             placeholder="2025-2026" list="liste-annees"
                             x-on:input.debounce.500ms="sonderCin()"
                             value="{{ old('annee', $reference->annee ?? '') }}" />
                    <x-input id="programme_code" name="programme_code" label="Code programme" maxlength="30"
                             placeholder="Ex. LMI" list="liste-programmes"
                             value="{{ old('programme_code', $reference->programme_code ?? '') }}" />
                    <x-select id="niveau" name="niveau" label="Niveau" vide="— Non précisé —"
                              :valeur="old('niveau', $reference->niveau ?? null)"
                              :options="['L1' => 'L1', 'L2' => 'L2', 'L3' => 'L3', 'M1' => 'M1', 'M2' => 'M2', 'D1' => 'D1', 'D2' => 'D2', 'D3' => 'D3']" />
                    <x-input id="matricule_national" name="matricule_national" label="Matricule national" maxlength="30"
                             value="{{ old('matricule_national', $reference->matricule_national ?? '') }}" />
                    <x-input id="etablissement" name="etablissement" label="Établissement" maxlength="150"
                             list="liste-etablissements"
                             value="{{ old('etablissement', $reference->etablissement ?? '') }}" />
                </div>
            </div>

            {{-- Référentiels d'auto-complétion : chargés en une seule fois,
                 bornés et cloisonnés par périmètre côté serveur — la
                 complétion est ensuite purement locale (aucune requête
                 par frappe). --}}
            <datalist id="liste-annees">
                <template x-for="valeurAnnee in annees" :key="valeurAnnee">
                    <option :value="valeurAnnee"></option>
                </template>
            </datalist>
            <datalist id="liste-programmes">
                <template x-for="programme in programmes" :key="programme.code">
                    <option :value="programme.code" :label="programme.libelle"></option>
                </template>
            </datalist>
            <datalist id="liste-etablissements">
                <template x-for="etablissement in etablissements" :key="etablissement">
                    <option :value="etablissement"></option>
                </template>
            </datalist>
            <datalist id="liste-nationalites">
                <template x-for="nationalite in nationalites" :key="nationalite">
                    <option :value="nationalite"></option>
                </template>
            </datalist>

            @if(isset($reference))
                <p class="rounded-xl bg-amber-50 border border-amber-200 p-3 text-xs text-amber-900">
                    La CIN et l'année forment la clé d'identification (unicité vérifiée, import idempotent).
                    Toute modification est journalisée ; la provenance d'import d'origine est conservée.
                </p>
            @endif

            <div class="flex flex-col sm:flex-row gap-2">
                <x-btn type="submit" x-bind:disabled="envoi"
                       class="disabled:opacity-60 disabled:cursor-not-allowed disabled:pointer-events-none">
                    <span x-show="!envoi">{{ isset($reference) ? 'Enregistrer les modifications' : 'Ajouter la référence' }}</span>
                    <span x-show="envoi" x-cloak class="inline-flex items-center gap-2">
                        <svg class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                        </svg>
                        Enregistrement…
                    </span>
                </x-btn>
                <x-btn variant="secondary" :href="isset($reference) ? route('admin.mens.show', $reference) : route('admin.mens.index')">Annuler</x-btn>
            </div>
        </form>
    </div>
@endsection

@push('scripts')
    <script>
        document.addEventListener('alpine:init', () => {
            Alpine.data('assistanceMens', (config) => ({
                urlReferentiels: config.urlReferentiels,
                urlAssistance: config.urlAssistance,
                ignorer: config.ignorer,

                annees: [],
                programmes: [],
                etablissements: [],
                nationalites: [],

                rechercheCin: false,
                prefill: null,
                apercu: '',
                ambigu: false,
                doublon: false,
                envoi: false,
                sondeSequence: 0,

                // Référentiels bornés : une requête au chargement, puis la
                // complétion native du navigateur (datalist) prend le relais.
                chargerReferentiels() {
                    fetch(this.urlReferentiels, { headers: { Accept: 'application/json' }, cache: 'no-store' })
                        .then((reponse) => (reponse.ok ? reponse.json() : null))
                        .then((donnees) => {
                            if (!donnees) return;
                            this.annees = donnees.annees ?? [];
                            this.programmes = donnees.programmes ?? [];
                            this.etablissements = donnees.etablissements ?? [];
                            this.nationalites = donnees.nationalites ?? [];
                        })
                        .catch(() => {});
                },

                // Sonde CIN (400 ms après la dernière frappe) : l'état local
                // est un confort de saisie, la validation serveur reste la
                // seule autorité au moment d'écrire.
                sonderCin() {
                    const cin = (document.getElementById('cin')?.value ?? '').trim();

                    if (cin.length < 4) {
                        this.reinitialiserSonde();
                        this.rechercheCin = false;
                        return;
                    }

                    const params = new URLSearchParams({ cin, annee: document.getElementById('annee')?.value ?? '' });
                    if (this.ignorer !== null) params.set('ignorer', this.ignorer);

                    const jeton = ++this.sondeSequence;
                    this.rechercheCin = true;

                    fetch(`${this.urlAssistance}?${params.toString()}`, { headers: { Accept: 'application/json' }, cache: 'no-store' })
                        .then((reponse) => (reponse.ok ? reponse.json() : null))
                        .then((donnees) => {
                            if (jeton !== this.sondeSequence) return;

                            this.rechercheCin = false;
                            this.reinitialiserSonde();

                            if (!donnees) return;

                            this.prefill = donnees.prefill;
                            this.apercu = donnees.apercu ?? '';
                            this.ambigu = !!donnees.ambigu;
                            this.doublon = !!donnees.doublon;

                            if (this.prefill) this.appliquerPrefill();
                        })
                        .catch(() => {
                            if (jeton !== this.sondeSequence) return;

                            this.rechercheCin = false;
                            this.reinitialiserSonde();
                        });
                },

                reinitialiserSonde() {
                    this.prefill = null;
                    this.apercu = '';
                    this.ambigu = false;
                    this.doublon = false;
                },

                // Ne remplit QUE les champs vides : jamais d'écrasement
                // d'une saisie en cours, l'opérateur reste maître des valeurs.
                appliquerPrefill() {
                    const remplir = (id, valeur) => {
                        const champ = document.getElementById(id);
                        if (champ && champ.value === '' && valeur) champ.value = valeur;
                    };

                    remplir('nom', this.prefill.nom);
                    remplir('prenom', this.prefill.prenom);
                    remplir('date_naissance', this.prefill.date_naissance);

                    const sexe = document.getElementById('sexe');
                    if (sexe && sexe.value === '' && this.prefill.sexe) sexe.value = this.prefill.sexe;
                },
            }));
        });
    </script>
@endpush
