@extends('layouts.app')

@section('title', 'Mise à jour de votre dossier d\'inscription')
@section('meta_description', 'Vérifiez et complétez les informations modifiables de votre dossier d\'inscription existant.')

@section('content')
@php
    $etapeEmail = session('dossier_existant.email_etape');
    if ($etapeEmail === null && ($errors->has('nouvel_email') || $errors->has('nouvel_email_confirmation'))) {
        $etapeEmail = 'saisie';
    } elseif ($etapeEmail === null && $errors->has('code_nouvelle')) {
        $etapeEmail = 'code-nouvelle';
    } elseif ($etapeEmail === null && $errors->has('code')) {
        $etapeEmail = 'code';
    }
    $focusEmail = $etapeEmail === 'code-nouvelle' ? 'code-nouvelle' : ($etapeEmail === 'code' ? 'code-email' : 'nouvel_email');
@endphp
<div class="mx-auto max-w-3xl px-4 sm:px-6 py-8 sm:py-12 space-y-8"
     x-data="{ emailPopup: { ouvert: {{ $etapeEmail ? 'true' : 'false' }}, etape: '{{ $etapeEmail ?? 'saisie' }}', chargement: false } }"
     x-init="if (emailPopup.ouvert) { $nextTick(() => document.getElementById('{{ $focusEmail }}')?.focus()); }">

    {{-- En-tête --}}
    <div class="text-center space-y-2">
        <span class="inline-flex items-center gap-1.5 rounded-full bg-emerald-100 border border-emerald-300 px-3 py-0.5 text-xs font-bold text-emerald-900">
            Identité vérifiée par code à usage unique
        </span>
        <h1 class="text-2xl sm:text-4xl font-black tracking-tight text-slate-900">
            Votre dossier d'inscription
        </h1>
        <p class="text-xs sm:text-sm text-slate-500 max-w-xl mx-auto">
            Vérifiez les informations de votre dossier et corrigez uniquement les champs modifiables.
            Aucun nouveau dossier n'est créé : votre demande est rattachée au dossier existant et
            reste soumise au traitement administratif.
        </p>
    </div>

    @if (session('statut'))
        <div class="rounded-2xl border border-sky-200 bg-sky-50 p-4 text-sm text-sky-900" role="status">
            {{ session('statut') }}
        </div>
    @endif

    @if (session('dossier_existant.email_succes'))
        <div class="rounded-2xl border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-900" role="status">
            Votre adresse email a été mise à jour : <strong class="font-mono">{{ session('dossier_existant.email_succes') }}</strong>.
            @if (session('dossier_existant.email_notifications_en_attente'))
                <span class="mt-1 block text-amber-900">L'envoi des emails de notification est en attente de nouvelle tentative : votre changement est bien enregistré.</span>
            @else
                <span class="mt-1 block">Des emails de confirmation vous ont été adressés (nouvelle adresse) et, pour votre sécurité, à votre ancienne adresse.</span>
            @endif
        </div>
    @endif

    {{-- Identité et affectation : lecture seule (procédure administrative) --}}
    <div class="rounded-3xl border border-slate-200/90 bg-white p-6 sm:p-8 shadow-sm">
        <h2 class="text-base font-black text-slate-900">Informations d'identité (non modifiables en ligne)</h2>
        <p class="mt-1 text-xs text-slate-500">
            Pour corriger une information d'identité ou d'affectation académique, contactez le service de la scolarité.
        </p>
        <dl class="mt-4 grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-3 text-sm">
            <div class="flex justify-between gap-4 border-b border-slate-100 pb-2">
                <dt class="text-slate-500 font-semibold">Numéro de dossier</dt>
                <dd class="font-mono font-bold text-slate-900">{{ $dossier['num_inscription'] }}</dd>
            </div>
            <div class="flex justify-between gap-4 border-b border-slate-100 pb-2">
                <dt class="text-slate-500 font-semibold">CIN</dt>
                <dd class="font-mono font-bold text-slate-900">{{ $dossier['cin_masquee'] }}</dd>
            </div>
            <div class="flex justify-between gap-4 border-b border-slate-100 pb-2">
                <dt class="text-slate-500 font-semibold">Nom</dt>
                <dd class="font-bold text-slate-900">{{ $dossier['nom'] }}</dd>
            </div>
            <div class="flex justify-between gap-4 border-b border-slate-100 pb-2">
                <dt class="text-slate-500 font-semibold">Prénom</dt>
                <dd class="font-bold text-slate-900">{{ $dossier['prenom'] }}</dd>
            </div>
            <div class="flex justify-between gap-4 border-b border-slate-100 pb-2">
                <dt class="text-slate-500 font-semibold">Date de naissance</dt>
                <dd class="font-bold text-slate-900">{{ $dossier['date_naissance'] ?? '—' }}</dd>
            </div>
            <div class="flex justify-between gap-4 border-b border-slate-100 pb-2">
                <dt class="text-slate-500 font-semibold">Email (masqué, non modifiable ici)</dt>
                <dd class="font-mono font-bold text-slate-900 break-all text-right">{{ $dossier['email_masque'] }}</dd>
            </div>
            <div class="flex justify-between gap-4 border-b border-slate-100 pb-2">
                <dt class="text-slate-500 font-semibold">Faculté</dt>
                <dd class="font-bold text-slate-900 text-right">{{ $dossier['faculte_nom'] ?? '—' }}</dd>
            </div>
            <div class="flex justify-between gap-4 border-b border-slate-100 pb-2">
                <dt class="text-slate-500 font-semibold">Niveau / Année</dt>
                <dd class="font-bold text-slate-900">{{ $dossier['niveau'] ?? '—' }} · {{ $dossier['annee_academique'] ?? '—' }}</dd>
            </div>
            <div class="flex justify-between gap-4 border-b border-slate-100 pb-2">
                <dt class="text-slate-500 font-semibold">Statut du dossier</dt>
                <dd class="font-bold text-slate-900">{{ $dossier['statut_label'] }}</dd>
            </div>
        </dl>

        @if ($messageStatut)
            <div class="mt-4 rounded-2xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900" role="alert">
                {{ $messageStatut }}
            </div>
        @endif
    </div>

    {{-- Adresse email : modification via popup vérifiée (double preuve) --}}
    @if ($emailModifiable)
        <div class="rounded-3xl border border-slate-200/90 bg-white p-6 sm:p-8 shadow-sm">
            <h2 class="text-base font-black text-slate-900">Adresse email</h2>
            <p class="mt-1 text-xs text-slate-500">
                Cette adresse reçoit vos codes de vérification et les communications de la scolarité ;
                elle sert aussi d'identifiant de connexion à votre espace étudiant.
                Sa modification exige deux codes : un envoyé à l'adresse actuelle
                (celle enregistrée sur le dossier), puis un envoyé à la nouvelle adresse,
                qui ne devient effective qu'après cette double confirmation.
            </p>
            <div class="mt-4 flex flex-col sm:flex-row sm:items-center gap-3">
                <span class="font-mono font-bold text-slate-900 break-all">{{ $dossier['email_masque'] }}</span>
                <button type="button"
                        @click="emailPopup.ouvert = true; emailPopup.etape = 'saisie'; emailPopup.chargement = false; $nextTick(() => document.getElementById('nouvel_email')?.focus())"
                        class="inline-flex items-center justify-center gap-2 rounded-xl bg-primary-800 px-5 py-2.5 text-sm font-bold text-white hover:bg-primary-700 focus:outline-none focus:ring-4 focus:ring-primary-500/25 transition min-h-[44px] sm:w-auto w-full">
                    ✉️ Modifier mon adresse email
                </button>
            </div>
        </div>
    @endif

    {{-- Coordonnées modifiables --}}
    @if ($dossierModifiable)
        <div class="rounded-3xl border border-slate-200/90 bg-white p-6 sm:p-8 shadow-sm">
            <h2 class="text-base font-black text-slate-900">Coordonnées modifiables</h2>
            <form action="{{ route('inscription.existant-store') }}" method="POST" class="mt-4 space-y-4">
                @csrf
                <div>
                    <label for="telephone" class="mb-1.5 block text-xs font-bold uppercase tracking-wider text-slate-700">
                        Téléphone
                    </label>
                    <input type="text"
                           id="telephone"
                           name="telephone"
                           value="{{ old('telephone', $dossier['telephone']) }}"
                           autocomplete="tel"
                           class="w-full rounded-xl border border-slate-300 bg-white px-3.5 py-2.5 text-sm text-slate-900 focus:border-primary-700 focus:ring-4 focus:ring-primary-500/20 focus:outline-none @error('telephone') border-rose-400 bg-rose-50/50 @enderror">
                    @error('telephone')
                        <p class="mt-1 text-xs font-semibold text-rose-700">⚠ {{ $message }}</p>
                    @enderror
                </div>
                <div>
                    <label for="adresse" class="mb-1.5 block text-xs font-bold uppercase tracking-wider text-slate-700">
                        Adresse <span class="text-rose-600 font-bold">*</span>
                    </label>
                    <textarea id="adresse"
                              name="adresse"
                              rows="2"
                              required
                              class="w-full rounded-xl border border-slate-300 bg-white px-3.5 py-2.5 text-sm text-slate-900 focus:border-primary-700 focus:ring-4 focus:ring-primary-500/20 focus:outline-none @error('adresse') border-rose-400 bg-rose-50/50 @enderror">{{ old('adresse', $dossier['adresse']) }}</textarea>
                    @error('adresse')
                        <p class="mt-1 text-xs font-semibold text-rose-700">⚠ {{ $message }}</p>
                    @enderror
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label for="ville" class="mb-1.5 block text-xs font-bold uppercase tracking-wider text-slate-700">
                            Ville <span class="text-rose-600 font-bold">*</span>
                        </label>
                        <input type="text"
                               id="ville"
                               name="ville"
                               value="{{ old('ville', $dossier['ville']) }}"
                               required
                               class="w-full rounded-xl border border-slate-300 bg-white px-3.5 py-2.5 text-sm text-slate-900 focus:border-primary-700 focus:ring-4 focus:ring-primary-500/20 focus:outline-none @error('ville') border-rose-400 bg-rose-50/50 @enderror">
                        @error('ville')
                            <p class="mt-1 text-xs font-semibold text-rose-700">⚠ {{ $message }}</p>
                        @enderror
                    </div>
                    <div>
                        <label for="code_postal" class="mb-1.5 block text-xs font-bold uppercase tracking-wider text-slate-700">
                            Code postal <span class="text-rose-600 font-bold">*</span>
                        </label>
                        <input type="text"
                               id="code_postal"
                               name="code_postal"
                               value="{{ old('code_postal', $dossier['code_postal']) }}"
                               required
                               class="w-full rounded-xl border border-slate-300 bg-white px-3.5 py-2.5 text-sm text-slate-900 focus:border-primary-700 focus:ring-4 focus:ring-primary-500/20 focus:outline-none @error('code_postal') border-rose-400 bg-rose-50/50 @enderror">
                        @error('code_postal')
                            <p class="mt-1 text-xs font-semibold text-rose-700">⚠ {{ $message }}</p>
                        @enderror
                    </div>
                </div>

                <label class="flex items-start gap-3 text-xs sm:text-sm text-slate-600">
                    <input type="checkbox"
                           name="confirmation"
                           value="1"
                           required
                           class="mt-0.5 h-4 w-4 rounded border-slate-300 text-primary-700 focus:ring-primary-500/30">
                    <span>Je certifie sur l'honneur l'exactitude des renseignements fournis.</span>
                </label>
                @error('confirmation')
                    <p class="text-xs font-semibold text-rose-700">⚠ {{ $message }}</p>
                @enderror

                <button type="submit"
                        class="inline-flex items-center justify-center gap-2 rounded-xl bg-primary-800 px-6 py-3 text-sm font-bold text-white hover:bg-primary-700 focus:outline-none focus:ring-4 focus:ring-primary-500/25 transition min-h-[48px] w-full sm:w-auto">
                    Soumettre ma mise à jour
                </button>
            </form>
        </div>
    @endif

    {{-- État des pièces justificatives (lecture seule) --}}
    <div class="rounded-3xl border border-slate-200/90 bg-white p-6 sm:p-8 shadow-sm">
        <h2 class="text-base font-black text-slate-900">Pièces justificatives requises</h2>
        @if (empty($pieces['pieces'] ?? []))
            <p class="mt-2 text-sm text-slate-500">Aucune pièce spécifique n'est exigée pour ce type de dossier.</p>
        @else
            <p class="mt-1 text-xs font-bold {{ ($pieces['complet'] ?? false) ? 'text-emerald-700' : 'text-amber-700' }}">
                {{ $pieces['progression'] ?? '' }}
            </p>
            <ul class="mt-3 space-y-2 text-sm">
                @foreach ($pieces['pieces'] as $piece)
                    <li class="flex items-start justify-between gap-3 border-b border-slate-100 pb-2">
                        <span class="text-slate-700">
                            {{ $piece['libelle'] }}
                            @if ($piece['obligatoire'])
                                <span class="text-rose-600 font-bold">*</span>
                            @endif
                            @if (! empty($piece['motif_rejet']))
                                <span class="block text-xs text-rose-700">Motif : {{ $piece['motif_rejet'] }}</span>
                            @endif
                        </span>
                        <span class="shrink-0 text-xs font-bold @if(($piece['statut'] ?? 'non_fourni') === 'valide') text-emerald-700 @elseif(in_array(($piece['statut'] ?? ''), ['expire', 'en_attente'])) text-amber-700 @elseif(($piece['statut'] ?? '') === 'rejete') text-rose-700 @else text-slate-500 @endif">
                            {{ $piece['statut_libelle'] ?? 'Non fourni' }}
                        </span>
                    </li>
                @endforeach
            </ul>
        @endif
        <p class="mt-3 text-xs text-slate-500">
            Les pièces déjà déposées sont conservées. Le dépôt ou le remplacement de pièces
            s'effectue via votre Espace Étudiant ou auprès de la scolarité.
        </p>
    </div>

    {{-- Popup de modification de l'adresse email (post-vérification uniquement) --}}
    @if ($emailModifiable)
        <div x-show="emailPopup.ouvert" x-cloak class="fixed inset-0 z-[80] flex items-center justify-center p-4" role="dialog" aria-modal="true" aria-labelledby="titre-email" @keydown.escape.window="if (!emailPopup.chargement) emailPopup.ouvert = false">
            <div class="absolute inset-0 bg-slate-900/50"></div>
            <div class="relative w-full max-w-xl max-h-full rounded-2xl border border-slate-200 bg-white p-6 shadow-xl overflow-y-auto">
                <h3 id="titre-email" class="text-base font-black text-slate-900">Modifier mon adresse email</h3>
                <p class="mt-1 text-xs text-slate-500">
                    Par sécurité, un code de confirmation sera envoyé à votre adresse actuelle
                    (masquée) : <code class="rounded bg-slate-100 px-1.5 py-0.5 font-mono font-bold text-slate-900">{{ $dossier['email_masque'] }}</code>.
                    Seul le titulaire contrôlant cette adresse peut valider le changement.
                </p>

                {{-- Étape 1 : saisie de la nouvelle adresse --}}
                <div x-show="emailPopup.etape === 'saisie'">
                    <form action="{{ route('inscription.existant.email') }}" method="POST" class="mt-4 space-y-4" @submit="emailPopup.chargement = true">
                        @csrf
                        <div>
                            <label for="nouvel_email" class="mb-1.5 block text-xs font-bold uppercase tracking-wider text-slate-700">
                                Nouvelle adresse email <span class="text-rose-600 font-bold">*</span>
                            </label>
                            <input type="email"
                                   id="nouvel_email"
                                   name="nouvel_email"
                                   value="{{ old('nouvel_email') }}"
                                   required
                                   autocomplete="email"
                                   class="w-full rounded-xl border border-slate-300 bg-white px-3.5 py-2.5 text-sm text-slate-900 focus:border-primary-700 focus:ring-4 focus:ring-primary-500/20 focus:outline-none @error('nouvel_email') border-rose-400 bg-rose-50/50 @enderror">
                            @error('nouvel_email')
                                <p class="mt-1 text-xs font-semibold text-rose-700" role="alert">⚠ {{ $message }}</p>
                            @enderror
                        </div>
                        <div>
                            <label for="nouvel_email_confirmation" class="mb-1.5 block text-xs font-bold uppercase tracking-wider text-slate-700">
                                Confirmation de la nouvelle adresse <span class="text-rose-600 font-bold">*</span>
                            </label>
                            <input type="email"
                                   id="nouvel_email_confirmation"
                                   name="nouvel_email_confirmation"
                                   value="{{ old('nouvel_email_confirmation') }}"
                                   required
                                   autocomplete="email"
                                   class="w-full rounded-xl border border-slate-300 bg-white px-3.5 py-2.5 text-sm text-slate-900 focus:border-primary-700 focus:ring-4 focus:ring-primary-500/20 focus:outline-none @error('nouvel_email_confirmation') border-rose-400 bg-rose-50/50 @enderror">
                            @error('nouvel_email_confirmation')
                                <p class="mt-1 text-xs font-semibold text-rose-700" role="alert">⚠ {{ $message }}</p>
                            @enderror
                        </div>
                        <p class="text-xs text-slate-500">Un code de confirmation à usage unique sera envoyé à votre adresse actuelle (enregistrée sur le dossier), valable 30 minutes.</p>
                        <div class="flex flex-col sm:flex-row gap-3">
                            <button type="submit"
                                    :disabled="emailPopup.chargement"
                                    class="inline-flex items-center justify-center gap-2 rounded-xl bg-primary-800 px-6 py-3 text-sm font-bold text-white hover:bg-primary-700 focus:outline-none focus:ring-4 focus:ring-primary-500/25 transition min-h-[48px] disabled:opacity-60">
                                <span x-show="!emailPopup.chargement">Enregistrer</span>
                                <span x-show="emailPopup.chargement">Envoi en cours…</span>
                            </button>
                            <button type="button"
                                    @click="if (!emailPopup.chargement) emailPopup.ouvert = false"
                                    class="inline-flex items-center justify-center rounded-xl border border-slate-300 bg-white px-5 py-2.5 text-sm font-bold text-slate-700 hover:bg-slate-50 focus:outline-none focus:ring-4 focus:ring-primary-500/25 transition min-h-[44px]">
                                Annuler
                            </button>
                        </div>
                    </form>
                </div>

                {{-- Étape 2 : code reçu à l'adresse actuelle (déjà vérifiée) --}}
                <div x-show="emailPopup.etape === 'code'">
                    <p class="mt-4 text-sm text-slate-600">
                        Saisissez le code à 6 chiffres envoyé à votre adresse actuelle
                        <code class="rounded bg-slate-100 px-1.5 py-0.5 font-mono font-bold text-slate-900">{{ $dossier['email_masque'] }}</code>.
                        Votre adresse reste inchangée à ce stade : un second code sera ensuite
                        envoyé à votre nouvelle adresse pour valider définitivement le changement.
                    </p>
                    <form action="{{ route('inscription.existant.email.confirmer') }}" method="POST" class="mt-4 space-y-4" @submit="emailPopup.chargement = true">
                        @csrf
                        <div class="max-w-xs">
                            <label for="code-email" class="mb-1.5 block text-xs font-bold uppercase tracking-wider text-slate-700">
                                Code reçu à l'adresse actuelle <span class="text-rose-600 font-bold">*</span>
                            </label>
                            <input type="text"
                                   id="code-email"
                                   name="code"
                                   value="{{ old('code') }}"
                                   inputmode="numeric"
                                   pattern="[0-9]{6}"
                                   maxlength="6"
                                   required
                                   autocomplete="one-time-code"
                                   class="w-full rounded-xl border border-slate-300 bg-white px-3.5 py-2.5 font-mono text-lg tracking-[.4em] text-slate-900 focus:border-primary-700 focus:ring-4 focus:ring-primary-500/20 focus:outline-none @error('code') border-rose-400 bg-rose-50/50 @enderror">
                            @error('code')
                                <p class="mt-1 text-xs font-semibold text-rose-700" role="alert">⚠ {{ $message }}</p>
                            @enderror
                        </div>
                        <div class="flex flex-col sm:flex-row gap-3">
                            <button type="submit"
                                    :disabled="emailPopup.chargement"
                                    class="inline-flex items-center justify-center gap-2 rounded-xl bg-primary-800 px-6 py-3 text-sm font-bold text-white hover:bg-primary-700 focus:outline-none focus:ring-4 focus:ring-primary-500/25 transition min-h-[48px] disabled:opacity-60">
                                <span x-show="!emailPopup.chargement">Vérifier ce code</span>
                                <span x-show="emailPopup.chargement">Vérification en cours…</span>
                            </button>
                            <button type="button"
                                    @click="if (!emailPopup.chargement) { emailPopup.etape = 'saisie'; $nextTick(() => document.getElementById('nouvel_email')?.focus()) }"
                                    class="inline-flex items-center justify-center rounded-xl border border-slate-300 bg-white px-5 py-2.5 text-sm font-bold text-slate-700 hover:bg-slate-50 focus:outline-none focus:ring-4 focus:ring-primary-500/25 transition min-h-[44px]">
                                Corriger la nouvelle adresse
                            </button>
                            <button type="button"
                                    @click="if (!emailPopup.chargement) emailPopup.ouvert = false"
                                    class="inline-flex items-center justify-center rounded-xl border border-slate-300 bg-white px-5 py-2.5 text-sm font-bold text-slate-700 hover:bg-slate-50 focus:outline-none focus:ring-4 focus:ring-primary-500/25 transition min-h-[44px]">
                                Annuler
                            </button>
                        </div>
                    </form>
                    <p class="mt-3 text-xs text-slate-500">
                        Aucun code reçu ? <button type="button" @click="emailPopup.etape = 'saisie'; emailPopup.chargement = false; $nextTick(() => document.getElementById('nouvel_email')?.focus())" class="font-bold text-primary-800 underline underline-offset-2">Demander un nouveau code</button>.
                        Si vous n'avez plus accès à votre adresse actuelle, contactez le service de la scolarité
                        ({{ config('inscriptions.contact_scolarite.email') }}) : un parcours de récupération
                        administratif vous sera proposé — la protection du changement d'adresse n'est jamais désactivée.
                    </p>
                </div>

                {{-- Étape 3 : code reçu à la NOUVELLE adresse (candidate) --}}
                <div x-show="emailPopup.etape === 'code-nouvelle'">
                    <p class="mt-4 text-sm text-slate-600">
                        Un code de confirmation a été envoyé à votre nouvelle adresse
                        @if (session('dossier_existant.email_nouveau_masque'))
                            <code class="rounded bg-slate-100 px-1.5 py-0.5 font-mono font-bold text-slate-900">{{ session('dossier_existant.email_nouveau_masque') }}</code>
                        @endif.
                        Saisissez-le ci-dessous : votre adresse actuelle reste en vigueur
                        jusqu'à cette confirmation, qui appliquera définitivement le changement.
                    </p>
                    <form action="{{ route('inscription.existant.email.confirmer-nouvelle') }}" method="POST" class="mt-4 space-y-4" @submit="emailPopup.chargement = true">
                        @csrf
                        <div class="max-w-xs">
                            <label for="code-nouvelle" class="mb-1.5 block text-xs font-bold uppercase tracking-wider text-slate-700">
                                Code reçu à la nouvelle adresse <span class="text-rose-600 font-bold">*</span>
                            </label>
                            <input type="text"
                                   id="code-nouvelle"
                                   name="code_nouvelle"
                                   value="{{ old('code_nouvelle') }}"
                                   inputmode="numeric"
                                   pattern="[0-9]{6}"
                                   maxlength="6"
                                   required
                                   autocomplete="one-time-code"
                                   class="w-full rounded-xl border border-slate-300 bg-white px-3.5 py-2.5 font-mono text-lg tracking-[.4em] text-slate-900 focus:border-primary-700 focus:ring-4 focus:ring-primary-500/20 focus:outline-none @error('code_nouvelle') border-rose-400 bg-rose-50/50 @enderror">
                            @error('code_nouvelle')
                                <p class="mt-1 text-xs font-semibold text-rose-700" role="alert">⚠ {{ $message }}</p>
                            @enderror
                        </div>
                        <div class="flex flex-col sm:flex-row gap-3">
                            <button type="submit"
                                    :disabled="emailPopup.chargement"
                                    class="inline-flex items-center justify-center gap-2 rounded-xl bg-primary-800 px-6 py-3 text-sm font-bold text-white hover:bg-primary-700 focus:outline-none focus:ring-4 focus:ring-primary-500/25 transition min-h-[48px] disabled:opacity-60">
                                <span x-show="!emailPopup.chargement">Confirmer le changement</span>
                                <span x-show="emailPopup.chargement">Vérification en cours…</span>
                            </button>
                            <button type="button"
                                    @click="if (!emailPopup.chargement) { emailPopup.etape = 'saisie'; $nextTick(() => document.getElementById('nouvel_email')?.focus()) }"
                                    class="inline-flex items-center justify-center rounded-xl border border-slate-300 bg-white px-5 py-2.5 text-sm font-bold text-slate-700 hover:bg-slate-50 focus:outline-none focus:ring-4 focus:ring-primary-500/25 transition min-h-[44px]">
                                Corriger la nouvelle adresse
                            </button>
                            <button type="button"
                                    @click="if (!emailPopup.chargement) emailPopup.ouvert = false"
                                    class="inline-flex items-center justify-center rounded-xl border border-slate-300 bg-white px-5 py-2.5 text-sm font-bold text-slate-700 hover:bg-slate-50 focus:outline-none focus:ring-4 focus:ring-primary-500/25 transition min-h-[44px]">
                                Annuler
                            </button>
                        </div>
                    </form>
                    <p class="mt-3 text-xs text-slate-500">
                        Aucun code reçu ? Vérifiez la saisie de la nouvelle adresse puis <button type="button" @click="emailPopup.etape = 'saisie'; emailPopup.chargement = false; $nextTick(() => document.getElementById('nouvel_email')?.focus())" class="font-bold text-primary-800 underline underline-offset-2">demandez un nouveau code</button>.
                    </p>
                </div>
            </div>
        </div>
    @endif
</div>
@endsection
