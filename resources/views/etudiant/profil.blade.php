@extends('layouts.app')

@section('title', 'Fiche Étudiant — Espace Scolarité')
@section('meta_description', 'Dossier académique officiel de l\'étudiant : état civil, affectation universitaire, coordonnées et sécurité du compte.')

@section('content')
@php
    $statut = $etudiant->statut;
    $statutLabel = $statut === 'valide' ? 'Validé' : ($statut === 'en_attente' ? 'En attente' : 'Rejeté');
    $statutTone = $statut === 'valide' ? 'success' : ($statut === 'en_attente' ? 'warning' : 'danger');
    $decisionPrise = in_array($statut, ['valide', 'rejete'], true);
    $initiales = mb_strtoupper(mb_substr($etudiant->prenom, 0, 1)) . mb_strtoupper(mb_substr($etudiant->nom, 0, 1));
    $themeInitial = request()->query('theme') === 'v1' ? 'v1' : 'v2';
@endphp

<script>
    (function () {
        var root = document.documentElement;
        if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
        root.classList.add('profil-motion');
        window.setTimeout(function () {
            if (!root.classList.contains('profil-motion-ready')) root.classList.remove('profil-motion');
        }, 2200);
    })();
</script>

<div class="profil-page" data-profil
     x-data="{
        showPassword: false,
        mdp: '',
        get mdpLongueurOk() { return this.mdp.length >= 10; },
        get mdpLettreOk() { return /[A-Za-z]/.test(this.mdp); },
        get mdpChiffreOk() { return /[0-9]/.test(this.mdp); }
     }">

    {{-- Fil d'information --}}
    <div class="profil-topline">
        <div class="profil-topline-context">
            <p>Espace étudiant <span aria-hidden="true">/</span> Dossier académique</p>
            <p>Réf. <span class="profil-mono">{{ $etudiant->num_inscription }}</span></p>
        </div>

        <x-etudiant-theme-switch :theme="$themeInitial" />
    </div>

    {{-- ═══════════ COUVERTURE DU DOSSIER ═══════════ --}}
    <header class="profil-cover">
        <svg class="profil-cover-grid" aria-hidden="true">
            <defs>
                <pattern id="profil-grid" width="44" height="44" patternUnits="userSpaceOnUse">
                    <path d="M44 0H0V44" fill="none" stroke="rgba(224,231,255,.18)" stroke-width="1" />
                </pattern>
                <radialGradient id="profil-grid-fade" cx="50%" cy="0%" r="95%">
                    <stop offset="0%" stop-color="#fff" stop-opacity=".85" />
                    <stop offset="100%" stop-color="#fff" stop-opacity="0" />
                </radialGradient>
                <mask id="profil-grid-mask">
                    <rect width="100%" height="100%" fill="url(#profil-grid-fade)" />
                </mask>
            </defs>
            <rect width="100%" height="100%" fill="url(#profil-grid)" mask="url(#profil-grid-mask)" />
        </svg>

        <p class="profil-cover-year" aria-hidden="true" data-anim="watermark">{{ $etudiant->annee_academique }}</p>

        <div class="profil-cover-inner">
            <div class="profil-identity" data-anim="cover-main">
                <div class="profil-avatar" aria-hidden="true">{{ $initiales }}</div>

                <div class="min-w-0">
                    <p class="profil-cover-kicker">Dossier académique · {{ $etudiant->annee_academique }}</p>
                    <h1 class="profil-cover-name">
                        <span class="profil-name-line"><span data-anim="name">{{ $etudiant->prenom }} {{ $etudiant->nom }}</span></span>
                    </h1>
                    <div class="profil-cover-meta">
                        <span>CIN <span class="profil-mono">{{ $etudiant->cin }}</span></span>
                        <span class="profil-cover-sep" aria-hidden="true">·</span>
                        <span>{{ $etudiant->sexe === 'M' ? 'Masculin' : 'Féminin' }}</span>
                        @if($etudiant->date_naissance)
                            <span class="profil-cover-sep" aria-hidden="true">·</span>
                            <span>Né(e) le {{ $etudiant->date_naissance->translatedFormat('d/m/Y') }}</span>
                        @endif
                    </div>
                </div>
            </div>

            <div class="profil-cover-side" data-anim="cover-side">
                <span class="profil-cover-beta">Aperçu V2 · Beta</span>

                <span class="profil-status-pill profil-status-pill--{{ $statutTone }}">
                    <span class="profil-status-pill-dot" aria-hidden="true"></span>
                    {{ $statutLabel }}
                </span>

                <div class="profil-cover-ref">
                    <p class="profil-cover-ref-label">Numéro d'inscription</p>
                    <p class="profil-mono profil-cover-ref-value">{{ $etudiant->num_inscription }}</p>
                </div>

                <a href="{{ route('etudiant.recepisse') }}" target="_blank" rel="noopener"
                   class="profil-cover-cta" data-magnetic>
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5M16.5 12 12 16.5m0 0L7.5 12m4.5 4.5V3" />
                    </svg>
                    Récépissé de dépôt
                </a>
            </div>
        </div>

        <div class="profil-cover-facts" data-anim="cover-facts">
            <div>
                <p class="profil-fact-label">Faculté</p>
                <p class="profil-fact-value">{{ $etudiant->faculte->nom }}</p>
            </div>
            <div>
                <p class="profil-fact-label">Niveau d'études</p>
                <p class="profil-fact-value">{{ $etudiant->niveau }}</p>
            </div>
            <div>
                <p class="profil-fact-label">Baccalauréat</p>
                <p class="profil-fact-value">Session {{ $etudiant->annee_bac }}{{ $etudiant->mention_bac ? ' — ' . $etudiant->mention_bac : '' }}</p>
            </div>
            <div>
                <p class="profil-fact-label">Dossier déposé le</p>
                <p class="profil-fact-value">{{ $etudiant->created_at->translatedFormat('d/m/Y') }}</p>
            </div>
        </div>
    </header>

    {{-- ═══════════ AVIS DE LA SCOLARITÉ ═══════════ --}}
    @if($statut === 'en_attente')
        <div class="profil-notice profil-notice--warning" role="status" data-anim="notice">
            <svg class="profil-notice-icon" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
            </svg>
            <div>
                <p class="profil-notice-title">Dossier en cours d'examen</p>
                <p class="profil-notice-text">La scolarité instruit votre dossier. Vous serez notifié dans votre espace dès l'évolution du statut.</p>
            </div>
        </div>
    @elseif($statut === 'valide')
        <div class="profil-notice profil-notice--success" role="status" data-anim="notice">
            <svg class="profil-notice-icon" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="m9 12.75 2.25 2.25L15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
            </svg>
            <div>
                <p class="profil-notice-title">Admission validée</p>
                <p class="profil-notice-text">Présentez votre récépissé au service de la scolarité pour le retrait de la carte d'étudiant.</p>
            </div>
        </div>
    @else
        <div class="profil-notice profil-notice--danger" role="status" data-anim="notice">
            <svg class="profil-notice-icon" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m0 3.75h.008v.008H12v-.008ZM21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
            </svg>
            <div>
                <p class="profil-notice-title">Dossier rejeté</p>
                <p class="profil-notice-text">Contactez scolarite@universite.ac.ma en précisant votre numéro d'inscription pour connaître le motif et les recours.</p>
            </div>
        </div>
    @endif

    {{-- Sommaire --}}
    <nav class="profil-index" aria-label="Sections du dossier">
        <span class="profil-index-label">Sommaire</span>
        <a href="#etat-civil">État civil</a>
        <a href="#parcours">Parcours</a>
        <a href="#coordonnees">Coordonnées</a>
        <a href="#securite">Sécurité</a>
    </nav>

    <div class="profil-layout">
        <div class="profil-main">

            {{-- 01 — État civil --}}
            <section class="profil-panel" id="etat-civil" aria-labelledby="etat-civil-title" data-anim="panel">
                <header class="profil-panel-head">
                    <span class="profil-panel-index" aria-hidden="true">01</span>
                    <div>
                        <h2 class="profil-panel-title" id="etat-civil-title">État civil</h2>
                        <p class="profil-panel-sub">Identité officielle enregistrée lors du dépôt de votre dossier.</p>
                    </div>
                    <span class="profil-panel-tag">
                        <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 1 0-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 0 0 2.25-2.25v-6.75a2.25 2.25 0 0 0-2.25-2.25H6.75a2.25 2.25 0 0 0-2.25 2.25v6.75a2.25 2.25 0 0 0 2.25 2.25Z" />
                        </svg>
                        Lecture seule
                    </span>
                </header>

                <dl class="profil-data">
                    <div class="profil-data-item">
                        <dt>Numéro de CIN</dt>
                        <dd class="profil-mono">{{ $etudiant->cin }}</dd>
                    </div>
                    <div class="profil-data-item">
                        <dt>Nom de famille</dt>
                        <dd>{{ $etudiant->nom }}</dd>
                    </div>
                    <div class="profil-data-item">
                        <dt>Prénom</dt>
                        <dd>{{ $etudiant->prenom }}</dd>
                    </div>
                    <div class="profil-data-item">
                        <dt>Sexe</dt>
                        <dd>{{ $etudiant->sexe === 'M' ? 'Masculin' : 'Féminin' }}</dd>
                    </div>
                    <div class="profil-data-item">
                        <dt>Date de naissance</dt>
                        <dd>{{ $etudiant->date_naissance?->translatedFormat('d/m/Y') ?? '—' }}</dd>
                    </div>
                </dl>
            </section>

            {{-- 02 — Parcours --}}
            <section class="profil-panel" id="parcours" aria-labelledby="parcours-title" data-anim="panel">
                <header class="profil-panel-head">
                    <span class="profil-panel-index" aria-hidden="true">02</span>
                    <div>
                        <h2 class="profil-panel-title" id="parcours-title">Parcours universitaire</h2>
                        <p class="profil-panel-sub">Affectation académique décidée par la scolarité.</p>
                    </div>
                    <span class="profil-panel-tag">
                        <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 1 0-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 0 0 2.25-2.25v-6.75a2.25 2.25 0 0 0-2.25-2.25H6.75a2.25 2.25 0 0 0-2.25 2.25v6.75a2.25 2.25 0 0 0 2.25 2.25Z" />
                        </svg>
                        Lecture seule
                    </span>
                </header>

                <dl class="profil-data">
                    <div class="profil-data-item">
                        <dt>Faculté</dt>
                        <dd>{{ $etudiant->faculte->nom }}</dd>
                    </div>
                    <div class="profil-data-item">
                        <dt>Niveau d'études</dt>
                        <dd>{{ $etudiant->niveau }}</dd>
                    </div>
                    <div class="profil-data-item">
                        <dt>Année académique</dt>
                        <dd>{{ $etudiant->annee_academique }}</dd>
                    </div>
                    <div class="profil-data-item">
                        <dt>Baccalauréat</dt>
                        <dd>Session {{ $etudiant->annee_bac }}</dd>
                    </div>
                    <div class="profil-data-item">
                        <dt>Mention obtenue</dt>
                        <dd>{{ $etudiant->mention_bac ?? '—' }}</dd>
                    </div>
                </dl>
            </section>

            {{-- 03 — Coordonnées --}}
            <section class="profil-panel" id="coordonnees" aria-labelledby="coordonnees-title" data-anim="panel">
                <header class="profil-panel-head">
                    <span class="profil-panel-index" aria-hidden="true">03</span>
                    <div>
                        <h2 class="profil-panel-title" id="coordonnees-title">Coordonnées personnelles</h2>
                        <p class="profil-panel-sub">Ces informations permettent à la scolarité de vous joindre pendant l'instruction.</p>
                    </div>
                    <a href="{{ route('etudiant.profil.edit') }}" class="profil-panel-action">
                        <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931Z" />
                        </svg>
                        Modifier
                    </a>
                </header>

                <dl class="profil-data">
                    <div class="profil-data-item">
                        <dt>Adresse email</dt>
                        <dd class="profil-mono">{{ $etudiant->email }}</dd>
                    </div>
                    <div class="profil-data-item">
                        <dt>Téléphone</dt>
                        <dd>{{ $etudiant->telephone ?? '—' }}</dd>
                    </div>
                    <div class="profil-data-item">
                        <dt>Adresse complète</dt>
                        <dd>{{ $etudiant->adresse }}</dd>
                    </div>
                    <div class="profil-data-item">
                        <dt>Ville</dt>
                        <dd>{{ $etudiant->ville }}</dd>
                    </div>
                    <div class="profil-data-item">
                        <dt>Code postal</dt>
                        <dd class="profil-mono">{{ $etudiant->code_postal }}</dd>
                    </div>
                </dl>

                <p class="profil-panel-note">
                    L'adresse email est l'identifiant de votre compte : sa modification passe par une procédure vérifiée depuis
                    <a href="{{ route('etudiant.profil.edit') }}">la page de modification</a>.
                </p>
            </section>

            {{-- 04 — Sécurité --}}
            <section class="profil-panel" id="securite" aria-labelledby="securite-title" data-anim="panel">
                <header class="profil-panel-head">
                    <span class="profil-panel-index" aria-hidden="true">04</span>
                    <div>
                        <h2 class="profil-panel-title" id="securite-title">Sécurité du compte</h2>
                        <p class="profil-panel-sub">Modifiez votre mot de passe régulièrement et gardez-le confidentiel.</p>
                    </div>
                    <span class="profil-panel-tag">
                        <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931Z" />
                        </svg>
                        Modifiable
                    </span>
                </header>

                <form method="POST" action="{{ route('etudiant.profil.password') }}" class="profil-form">
                    @csrf
                    @method('PATCH')

                    <div class="profil-field">
                        <label for="current_password">Mot de passe actuel <span class="profil-required" aria-hidden="true">*</span></label>
                        <div class="profil-input-wrap">
                            <svg class="profil-field-icon" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 1 0-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 0 0 2.25-2.25v-6.75a2.25 2.25 0 0 0-2.25-2.25H6.75a2.25 2.25 0 0 0-2.25 2.25v6.75a2.25 2.25 0 0 0 2.25 2.25Z" />
                            </svg>
                            <input id="current_password" name="current_password" type="password" required autocomplete="current-password"
                                   placeholder="Votre mot de passe actuel"
                                   class="profil-input @error('current_password') profil-input--error @enderror">
                        </div>
                        @error('current_password')
                            <p class="profil-error">{{ $message }}</p>
                        @enderror
                    </div>

                    <div class="profil-form-grid">
                        <div class="profil-field">
                            <label for="new_password">Nouveau mot de passe <span class="profil-required" aria-hidden="true">*</span></label>
                            <div class="profil-input-wrap">
                                <svg class="profil-field-icon" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 1 0-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 0 0 2.25-2.25v-6.75a2.25 2.25 0 0 0-2.25-2.25H6.75a2.25 2.25 0 0 0-2.25 2.25v6.75a2.25 2.25 0 0 0 2.25 2.25Z" />
                                </svg>
                                <input id="new_password" name="password" x-model="mdp" :type="showPassword ? 'text' : 'password'" type="password" required autocomplete="new-password"
                                       placeholder="10 caractères minimum"
                                       class="profil-input profil-input--password @error('password') profil-input--error @enderror">
                                <button type="button" @click="showPassword = !showPassword"
                                        :aria-pressed="showPassword ? 'true' : 'false'"
                                        :aria-label="showPassword ? 'Masquer le mot de passe' : 'Afficher le mot de passe'"
                                        class="profil-eye">
                                    <svg x-show="!showPassword" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.964-7.178Z" />
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
                                    </svg>
                                    <svg x-show="showPassword" x-cloak class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M3.98 8.223A10.477 10.477 0 0 0 1.934 12c1.292 4.338 5.31 7.5 10.066 7.5.993 0 1.953-.138 2.863-.395M6.228 6.228A10.451 10.451 0 0 1 12 4.5c4.756 0 8.773 3.162 10.065 7.498a10.523 10.523 0 0 1-4.293 5.774M6.228 6.228 3 3m3.228 3.228 3.65 3.65m7.894 7.894L21 21m-3.228-3.228-3.65-3.65" />
                                    </svg>
                                </button>
                            </div>
                            @error('password')
                                <p class="profil-error">{{ $message }}</p>
                            @enderror
                        </div>

                        <div class="profil-field">
                            <label for="password_confirmation">Confirmer le mot de passe <span class="profil-required" aria-hidden="true">*</span></label>
                            <div class="profil-input-wrap">
                                <svg class="profil-field-icon" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 1 0-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 0 0 2.25-2.25v-6.75a2.25 2.25 0 0 0-2.25-2.25H6.75a2.25 2.25 0 0 0-2.25 2.25v6.75a2.25 2.25 0 0 0 2.25 2.25Z" />
                                </svg>
                                <input id="password_confirmation" name="password_confirmation" :type="showPassword ? 'text' : 'password'" type="password" required autocomplete="new-password"
                                       placeholder="Répétez le mot de passe"
                                       class="profil-input @error('password') profil-input--error @enderror">
                            </div>
                        </div>
                    </div>

                    <ul class="profil-rules" aria-label="Exigences du mot de passe">
                        <li class="profil-rule" :class="mdpLongueurOk ? 'is-ok' : ''">
                            <span class="profil-rule-mark" aria-hidden="true"></span>
                            10 caractères minimum
                        </li>
                        <li class="profil-rule" :class="mdpLettreOk ? 'is-ok' : ''">
                            <span class="profil-rule-mark" aria-hidden="true"></span>
                            Au moins une lettre
                        </li>
                        <li class="profil-rule" :class="mdpChiffreOk ? 'is-ok' : ''">
                            <span class="profil-rule-mark" aria-hidden="true"></span>
                            Au moins un chiffre
                        </li>
                    </ul>

                    <div>
                        <button type="submit" class="profil-btn profil-btn--primary" data-magnetic>
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75m-3-7.036A11.959 11.959 0 0 1 3.598 6 11.99 11.99 0 0 0 3 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285Z" />
                            </svg>
                            Mettre à jour le mot de passe
                        </button>
                    </div>
                </form>
            </section>
        </div>

        {{-- Rail de situation --}}
        <aside class="profil-rail" aria-label="Situation du dossier">
            <div class="profil-status-card profil-status-card--{{ $statutTone }}" data-anim="rail">
                <div class="profil-status-head">
                    <p class="profil-status-title">Situation du dossier</p>
                    <span class="profil-status-badge">
                        <span class="profil-status-badge-dot" aria-hidden="true"></span>
                        {{ $statutLabel }}
                    </span>
                </div>

                <ol class="profil-timeline">
                    <li class="profil-step is-done">
                        <span class="profil-step-dot" aria-hidden="true"></span>
                        <div>
                            <p class="profil-step-title">Dépôt du dossier</p>
                            <p class="profil-step-sub">{{ $etudiant->created_at->translatedFormat('d/m/Y') }}</p>
                        </div>
                    </li>
                    <li class="profil-step {{ $statut === 'en_attente' ? 'is-current' : 'is-done' }}">
                        <span class="profil-step-dot" aria-hidden="true"></span>
                        <div>
                            <p class="profil-step-title">Instruction</p>
                            <p class="profil-step-sub">{{ $statut === 'en_attente' ? 'En cours — service de la scolarité' : 'Terminée' }}</p>
                        </div>
                    </li>
                    <li class="profil-step {{ $decisionPrise ? 'is-done' : '' }} {{ $statut === 'rejete' ? 'is-danger' : '' }}">
                        <span class="profil-step-dot" aria-hidden="true"></span>
                        <div>
                            <p class="profil-step-title">Décision</p>
                            <p class="profil-step-sub">{{ $decisionPrise ? $statutLabel : 'À venir' }}</p>
                        </div>
                    </li>
                </ol>

                <p class="profil-status-note">
                    @if($statut === 'valide')
                        Votre admission est confirmée. Présentez votre récépissé au service de la scolarité.
                    @elseif($statut === 'en_attente')
                        Statut actualisé par la scolarité. Aucune action n'est requise de votre part.
                    @else
                        Consultez le motif de la décision et vos voies de recours auprès de la scolarité.
                    @endif
                </p>

                <a href="mailto:scolarite@universite.ac.ma" class="profil-status-link">
                    Contacter la scolarité
                    <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 6H5.25A2.25 2.25 0 0 0 3 8.25v10.5A2.25 2.25 0 0 0 5.25 21h10.5A2.25 2.25 0 0 0 18 18.75V10.5m-10.5 6L21 3m0 0h-5.25M21 3v5.25" />
                    </svg>
                </a>
            </div>
        </aside>
    </div>

    {{-- ═══════════ PIED DE DOSSIER ═══════════ --}}
    <footer class="profil-foot" data-anim="foot">
        <p class="profil-foot-note">
            Document généré le {{ now()->translatedFormat('d/m/Y à H:i') }} — Réf. <span class="profil-mono profil-foot-ref">{{ $etudiant->num_inscription }}</span><br>
            Les données d'état civil et d'affectation sont gérées exclusivement par le service de la scolarité.
        </p>

        <div class="profil-foot-actions">
            <a href="{{ route('etudiant.archives.show') }}" class="profil-btn profil-btn--ghost">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 21v-8.25M15.75 21v-8.25M8.25 21v-8.25M3 9l9-6 9 6m-1.5 12V10.332A48.36 48.36 0 0 0 12 9.75c-2.551 0-5.056.2-7.5.582V21M3 21h18M12 6.75h.008v.008H12V6.75Z" />
                </svg>
                Mon dossier académique
            </a>

            <a href="{{ route('etudiant.profil.edit') }}" class="profil-btn profil-btn--primary" data-magnetic>
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931Z" />
                </svg>
                Modifier mes coordonnées
            </a>

            <form method="POST" action="{{ route('etudiant.connexion.logout') }}">
                @csrf
                <button type="submit" class="profil-btn profil-btn--quiet">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 9V5.25A2.25 2.25 0 0 0 13.5 3h-6a2.25 2.25 0 0 0-2.25 2.25v13.5A2.25 2.25 0 0 0 7.5 21h6a2.25 2.25 0 0 0 2.25-2.25V15m3 0 3-3m0 0-3-3m3 3H9" />
                    </svg>
                    Déconnexion
                </button>
            </form>
        </div>
    </footer>
</div>
@endsection

@push('scripts')
    @vite('resources/js/profil.js')
@endpush
