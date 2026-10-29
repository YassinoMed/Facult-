@extends('layouts.app')

@section('title', 'Consentement alumni')

@section('content')
@php
    $nom = old('nom_affichage', $profil?->nom_affichage ?? trim(($diplome->etudiant->prenom ?? '').' '.($diplome->etudiant->nom ?? '')));
    $visibilite = old('visibilite', $profil?->visibilite ?? \App\Modules\Alumni\Models\ProfilAlumni::VISIBILITE_AGREGATS);
    $consentementExistant = $profil?->estConsenti() ?? false;
@endphp

<div class="mx-auto max-w-3xl px-4 py-10" data-reveal="fade-up">
    <header class="mb-8">
        <p class="text-sm font-semibold uppercase tracking-wide text-primary-700">UniRegistre · Alumni</p>
        <h1 class="mt-2 text-2xl font-black text-slate-900">Votre diplôme et votre consentement</h1>
        <p class="mt-2 text-sm text-slate-600">
            Ce lien personnel et temporaire vous permet de choisir ce que l’université peut
            publier dans l’annuaire des diplômés. Aucune note, aucun crédit et aucune décision
            académique ne sont affichés ou transmis.
        </p>
    </header>

    <section aria-labelledby="diplome-titre" class="mb-8 rounded-2xl border border-slate-200 bg-white p-5">
        <h2 id="diplome-titre" class="text-base font-bold text-slate-900">Diplôme concerné</h2>
        <dl class="mt-3 grid gap-3 text-sm sm:grid-cols-2">
            <div>
                <dt class="text-slate-500">Numéro officiel</dt>
                <dd class="font-semibold text-slate-900">{{ $diplome->numero_diplome }}</dd>
            </div>
            <div>
                <dt class="text-slate-500">Diplômé</dt>
                <dd class="font-semibold text-slate-900">{{ $diplome->etudiant->prenom ?? '' }} {{ $diplome->etudiant->nom ?? '' }}</dd>
            </div>
            <div>
                <dt class="text-slate-500">Type de diplôme</dt>
                <dd class="font-semibold text-slate-900">{{ $diplome->typeDiplome->nom ?? 'Non précisé' }}</dd>
            </div>
            <div>
                <dt class="text-slate-500">Programme</dt>
                <dd class="font-semibold text-slate-900">{{ $diplome->programme->nom ?? 'Non précisé' }}</dd>
            </div>
            <div>
                <dt class="text-slate-500">Faculté</dt>
                <dd class="font-semibold text-slate-900">{{ $diplome->faculte->nom ?? 'Non précisée' }}</dd>
            </div>
            <div>
                <dt class="text-slate-500">Date de délivrance</dt>
                <dd class="font-semibold text-slate-900">{{ $diplome->date_delivrance?->format('d/m/Y') }}</dd>
            </div>
        </dl>
    </section>

    @if(session('statut') === 'consentement_enregistre')
        <div role="status" class="mb-6 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-900">
            Votre consentement a été enregistré. Vous pouvez le modifier ou le retirer à tout moment avec ce lien.
        </div>
    @elseif(session('statut') === 'consentement_retire')
        <div role="status" class="mb-6 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
            Votre consentement a été retiré. Votre profil n’apparaît plus dans l’annuaire.
        </div>
    @endif

    @if($errors->any())
        <div role="alert" class="mb-6 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-900">
            <p class="font-semibold">Le consentement n’a pas été enregistré.</p>
            <ul class="mt-2 list-inside list-disc">
                @foreach($errors->all() as $erreur)
                    <li>{{ $erreur }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="POST" action="{{ $actionConsentement }}" class="rounded-2xl border border-slate-200 bg-white p-5">
        @csrf
        <input type="hidden" name="cle_idempotence" value="{{ old('cle_idempotence', \Illuminate\Support\Str::uuid()) }}">

        <fieldset class="grid gap-4 sm:grid-cols-2">
            <legend class="mb-2 text-base font-bold text-slate-900">Vos coordonnées et votre activité</legend>

            <div class="sm:col-span-2">
                <label for="nom_affichage" class="block text-sm font-semibold text-slate-700">Nom affiché dans l’annuaire *</label>
                <input id="nom_affichage" name="nom_affichage" type="text" maxlength="150" required
                       autocomplete="name" value="{{ $nom }}"
                       class="mt-1 w-full rounded-xl border border-slate-300 px-3 py-2 text-sm focus:border-primary-600 focus:outline-none focus:ring-2 focus:ring-primary-200">
            </div>

            <div>
                <label for="email_contact" class="block text-sm font-semibold text-slate-700">E-mail de contact</label>
                <input id="email_contact" name="email_contact" type="email" maxlength="190"
                       autocomplete="email" value="{{ old('email_contact', $profil?->email_contact) }}"
                       aria-describedby="email-aide"
                       class="mt-1 w-full rounded-xl border border-slate-300 px-3 py-2 text-sm focus:border-primary-600 focus:outline-none focus:ring-2 focus:ring-primary-200">
                <p id="email-aide" class="mt-1 text-xs text-slate-500">Visible uniquement si vous choisissez « Annuaire avec contact ».</p>
            </div>

            <div>
                <label for="telephone_contact" class="block text-sm font-semibold text-slate-700">Téléphone de contact</label>
                <input id="telephone_contact" name="telephone_contact" type="tel" maxlength="30"
                       autocomplete="tel" value="{{ old('telephone_contact', $profil?->telephone_contact) }}"
                       class="mt-1 w-full rounded-xl border border-slate-300 px-3 py-2 text-sm focus:border-primary-600 focus:outline-none focus:ring-2 focus:ring-primary-200">
            </div>

            <div>
                <label for="poste_actuel" class="block text-sm font-semibold text-slate-700">Poste actuel</label>
                <input id="poste_actuel" name="poste_actuel" type="text" maxlength="150"
                       autocomplete="organization-title" value="{{ old('poste_actuel', $profil?->poste_actuel) }}"
                       class="mt-1 w-full rounded-xl border border-slate-300 px-3 py-2 text-sm focus:border-primary-600 focus:outline-none focus:ring-2 focus:ring-primary-200">
            </div>

            <div>
                <label for="employeur" class="block text-sm font-semibold text-slate-700">Employeur</label>
                <input id="employeur" name="employeur" type="text" maxlength="150"
                       autocomplete="organization" value="{{ old('employeur', $profil?->employeur) }}"
                       class="mt-1 w-full rounded-xl border border-slate-300 px-3 py-2 text-sm focus:border-primary-600 focus:outline-none focus:ring-2 focus:ring-primary-200">
            </div>

            <div>
                <label for="secteur" class="block text-sm font-semibold text-slate-700">Secteur d’activité</label>
                <input id="secteur" name="secteur" type="text" maxlength="80"
                       value="{{ old('secteur', $profil?->secteur) }}"
                       class="mt-1 w-full rounded-xl border border-slate-300 px-3 py-2 text-sm focus:border-primary-600 focus:outline-none focus:ring-2 focus:ring-primary-200">
            </div>

            <div>
                <label for="ville" class="block text-sm font-semibold text-slate-700">Ville</label>
                <input id="ville" name="ville" type="text" maxlength="100"
                       autocomplete="address-level2" value="{{ old('ville', $profil?->ville) }}"
                       class="mt-1 w-full rounded-xl border border-slate-300 px-3 py-2 text-sm focus:border-primary-600 focus:outline-none focus:ring-2 focus:ring-primary-200">
            </div>

            <div>
                <label for="pays" class="block text-sm font-semibold text-slate-700">Pays</label>
                <input id="pays" name="pays" type="text" maxlength="80"
                       autocomplete="country-name" value="{{ old('pays', $profil?->pays) }}"
                       class="mt-1 w-full rounded-xl border border-slate-300 px-3 py-2 text-sm focus:border-primary-600 focus:outline-none focus:ring-2 focus:ring-primary-200">
            </div>

            <div class="sm:col-span-2">
                <label for="linkedin" class="block text-sm font-semibold text-slate-700">Profil professionnel (https)</label>
                <input id="linkedin" name="linkedin" type="url" maxlength="255"
                       autocomplete="url" value="{{ old('linkedin', $profil?->linkedin) }}"
                       class="mt-1 w-full rounded-xl border border-slate-300 px-3 py-2 text-sm focus:border-primary-600 focus:outline-none focus:ring-2 focus:ring-primary-200">
            </div>
        </fieldset>

        <div class="mt-6">
            <label for="visibilite" class="block text-sm font-semibold text-slate-700">Visibilité souhaitée *</label>
            <select id="visibilite" name="visibilite" required
                    class="mt-1 w-full rounded-xl border border-slate-300 px-3 py-2 text-sm focus:border-primary-600 focus:outline-none focus:ring-2 focus:ring-primary-200">
                @foreach($visibilites as $valeur => $libelle)
                    <option value="{{ $valeur }}" @selected($visibilite === $valeur)>{{ $libelle }}</option>
                @endforeach
            </select>
        </div>

        <div class="mt-6 rounded-xl border border-slate-200 bg-slate-50 p-4">
            <label for="consentement" class="flex items-start gap-3 text-sm text-slate-700">
                <input id="consentement" name="consentement" type="checkbox" value="1" required
                       @checked($consentementExistant)
                       class="mt-0.5 h-4 w-4 rounded border-slate-300 text-primary-700 focus:ring-primary-200">
                <span>
                    J’accepte que l’université conserve les informations ci-dessus et les publie
                    selon la visibilité choisie. Je peux retirer ce consentement à tout moment.
                    Les coordonnées privées ne sont jamais accessibles à un employeur.
                </span>
            </label>
        </div>

        <button type="submit"
                class="mt-6 inline-flex items-center justify-center rounded-xl bg-primary-800 px-5 py-2.5 text-sm font-bold text-white hover:bg-primary-900 focus:outline-none focus:ring-2 focus:ring-primary-300">
            {{ $consentementExistant ? 'Mettre à jour mon consentement' : 'Donner mon consentement' }}
        </button>
    </form>

    @if($consentementExistant)
        <form method="POST" action="{{ $actionRetrait }}" class="mt-6 rounded-2xl border border-amber-200 bg-amber-50 p-5">
            @csrf
            <input type="hidden" name="cle_idempotence" value="{{ \Illuminate\Support\Str::uuid() }}">
            <h2 class="text-base font-bold text-amber-900">Retirer mon consentement</h2>
            <p class="mt-1 text-sm text-amber-800">
                Votre profil sera retiré de l’annuaire immédiatement. Le registre officiel de
                diplomation reste inchangé : il ne dépend pas de ce consentement.
            </p>
            <button type="submit"
                    class="mt-4 inline-flex items-center justify-center rounded-xl border border-amber-300 bg-white px-4 py-2 text-sm font-bold text-amber-900 hover:bg-amber-100 focus:outline-none focus:ring-2 focus:ring-amber-200">
                Retirer mon consentement
            </button>
        </form>
    @endif

    <p class="mt-6 text-xs text-slate-500">
        Ce lien est personnel et temporaire. Ne le transférez pas. Aucune note, aucun crédit,
        aucune décision académique et aucune donnée médicale ne sont affichés sur cette page.
    </p>
</div>
@endsection
