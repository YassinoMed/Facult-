@extends('layouts.app')

@section('title', 'Portail des inscriptions universitaires')
@section('meta_description', 'Portail officiel des inscriptions universitaires. Inscrivez-vous en quelques étapes, votre dossier sera ensuite traité par l\'administration.')

@section('content')
    <noscript>
        <style>
            .section-reveal {
                opacity: 1 !important;
                transform: none !important;
            }
        </style>
    </noscript>

    @php
        $clesErreurs = array_keys($errors->toArray());
        // Parcours en 6 étapes (1 Identification · 2 Identité · 3 Coordonnées ·
        // 4 Formation · 5 Documents · 6 Confirmation).
        $etapeErreur = 2;
        if (count(array_intersect($clesErreurs, ['email', 'telephone', 'ville', 'code_postal', 'adresse'])) > 0)
            $etapeErreur = 3;
        if (count(array_intersect($clesErreurs, ['annee_bac', 'mention_bac', 'faculte_id', 'niveau', 'annee_academique', 'periode'])) > 0)
            $etapeErreur = 4;
        if (count(array_intersect($clesErreurs, ['password', 'password_confirmation'])) > 0)
            $etapeErreur = 6;
    @endphp

    <style>
        [x-cloak] {
            display: none !important;
        }

        input:disabled,
        select:disabled,
        textarea:disabled,
        button:disabled {
            cursor: not-allowed;
        }

        input:disabled,
        select:disabled,
        textarea:disabled {
            background-color: #f1f5f9;
            color: #64748b;
        }

        /* Popup : le panneau « jaillit » à chaque ouverture. */
        .popup-panneau {
            animation: popup-entree .32s cubic-bezier(.2, .9, .3, 1.15) both;
        }

        @keyframes popup-entree {
            from {
                opacity: 0;
                transform: translateY(18px) scale(.94);
            }

            to {
                opacity: 1;
                transform: none;
            }
        }

        /* Pastille d'icône : halo qui pulse une seule fois. */
        .popup-halo {
            animation: popup-halo 1.1s ease-out .15s 1 both;
        }

        @keyframes popup-halo {
            0% {
                box-shadow: 0 0 0 0 var(--halo, rgba(16, 185, 129, .45));
            }

            100% {
                box-shadow: 0 0 0 22px rgba(16, 185, 129, 0);
            }
        }

        /* Coche qui se dessine. */
        .coche-trace {
            stroke-dasharray: 32;
            stroke-dashoffset: 32;
            animation: coche-trace .5s ease .3s forwards;
        }

        @keyframes coche-trace {
            to {
                stroke-dashoffset: 0;
            }
        }

        /* Pastille « live » de la période. */
        .point-live {
            animation: point-live 2s ease-in-out infinite;
        }

        @keyframes point-live {

            0%,
            100% {
                opacity: 1;
            }

            50% {
                opacity: .35;
            }
        }

        @media (prefers-reduced-motion: reduce) {

            .popup-panneau,
            .popup-halo,
            .coche-trace,
            .point-live {
                animation: none !important;
            }

            .coche-trace {
                stroke-dashoffset: 0;
            }
        }
    </style>

    <div class="relative mx-auto max-w-5xl px-4 sm:px-6 lg:px-8 py-6 sm:py-10"
        x-data="periodeInscription(@js($etatInscription), '{{ route('inscription.periode-etat', [], false) }}', '{{ route('inscription.identifier') }}')">

        {{-- ════════════ 1. HERO ════════════ --}}
        <section aria-labelledby="titre-portail" data-motion-hero
            class="relative overflow-hidden rounded-3xl bg-gradient-to-br from-primary-900 via-primary-800 to-primary-700 p-6 sm:p-12 text-white shadow-xl">
            <div class="pointer-events-none absolute -right-24 -top-24 h-72 w-72 rounded-full bg-white/10 blur-3xl"
                aria-hidden="true"></div>
            <div class="pointer-events-none absolute -bottom-28 -left-16 h-72 w-72 rounded-full bg-emerald-300/10 blur-3xl"
                aria-hidden="true"></div>

            <div class="relative">
                <div class="flex flex-wrap items-center gap-2" data-motion-hero-item>
                    <span
                        class="inline-flex items-center gap-2 rounded-full bg-white/15 px-3.5 py-1.5 text-xs font-semibold backdrop-blur ring-1 ring-white/20">
                        <span class="h-2 w-2 rounded-full point-live"
                            :class="periode.interactif ? 'bg-emerald-400' : 'bg-rose-400'" aria-hidden="true"></span>
                        <span
                            x-text="periode.statut === 'EXCEPTION_OPEN' ? 'Ouverture exceptionnelle' : (periode.interactif ? 'Inscriptions ouvertes' : 'Inscriptions fermées')">{{ $etatInscription['statut'] === 'EXCEPTION_OPEN' ? 'Ouverture exceptionnelle' : ($etatInscription['interactif'] ? 'Inscriptions ouvertes' : 'Inscriptions fermées') }}</span>
                    </span>
                    <span
                        class="inline-flex items-center rounded-full bg-white/10 px-3.5 py-1.5 text-xs font-medium text-white/90 ring-1 ring-white/15">
                        Année universitaire {{ date('Y') }}–{{ date('Y') + 1 }}
                    </span>
                </div>

                <h1 id="titre-portail" data-motion-hero-item class="mt-5 max-w-2xl text-3xl sm:text-5xl font-black tracking-tight leading-[1.1]">
                    Portail des inscriptions universitaires
                </h1>
                <p class="mt-4 max-w-xl text-sm sm:text-base text-white/80 leading-relaxed" data-motion-hero-item>
                    Inscrivez-vous en quelques étapes. Votre dossier sera ensuite traité par l'administration.
                </p>

                <div class="mt-7 flex flex-col sm:flex-row gap-3" data-motion-hero-item>
                    <a href="#inscription" data-magnetic="0.1"
                        class="inline-flex items-center justify-center gap-2 rounded-xl bg-white px-6 py-3.5 text-sm font-bold text-primary-900 shadow-lg shadow-black/10 transition hover:bg-primary-50 focus:outline-none focus:ring-4 focus:ring-white/40 min-h-[48px]">
                        Commencer mon inscription
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2.2" stroke="currentColor"
                            aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5 21 12m0 0-7.5 7.5M21 12H3" />
                        </svg>
                    </a>
                    <a href="{{ route('etudiant.connexion.show') }}"
                        class="inline-flex items-center justify-center gap-2 rounded-xl bg-white/10 px-6 py-3.5 text-sm font-bold text-white ring-1 ring-white/30 backdrop-blur transition hover:bg-white/20 focus:outline-none focus:ring-4 focus:ring-white/40 min-h-[48px]">
                        Suivre mon dossier
                    </a>
                </div>

                {{-- Parcours --}}
                <ol class="mt-10 grid grid-cols-2 sm:grid-cols-5 gap-3" aria-label="Étapes de l'inscription" data-motion-hero-item>
                    @foreach([
                            ['1', 'Préparer', 'Vos informations et pièces'],
                            ['2', 'Remplir', 'Le formulaire guidé'],
                            ['3', 'Ajouter', 'Les documents demandés'],
                            ['4', 'Vérifier', 'Le récapitulatif'],
                            ['5', 'Confirmer', 'L\'envoi du dossier'],
                        ] as [$numero, $titre, $detail])
                        <li class="rounded-2xl bg-white/10 px-4 py-3.5 ring-1 ring-white/15 backdrop-blur">
                            <span
                                class="flex h-7 w-7 items-center justify-center rounded-full bg-white text-xs font-black text-primary-900"
                                aria-hidden="true">{{ $numero }}</span>
                            <p class="mt-2.5 text-sm font-bold">{{ $titre }}</p>
                            <p class="text-xs text-white/70">{{ $detail }}</p>
                        </li>
                    @endforeach
                </ol>
            </div>
        </section>

        {{-- ════════════ 2. FORMULAIRE ════════════ --}}
        <section id="inscription" aria-labelledby="titre-formulaire"
            class="mt-8 scroll-mt-24 rounded-3xl border border-slate-200 bg-white p-5 sm:p-10 shadow-sm">

            <header class="flex flex-col gap-1">
                <h2 id="titre-formulaire" class="text-xl sm:text-2xl font-black tracking-tight text-slate-900">Formulaire
                    d'inscription</h2>
                <p class="text-xs sm:text-sm text-slate-500">
                    Renseignez les champs avec exactitude. Les champs marqués <span class="font-bold text-rose-600">*</span>
                    sont obligatoires.
                </p>
            </header>

            {{-- Bannière d'état de la période (le backend reste la vérité) --}}
            <div class="mt-6 flex items-start gap-3 rounded-2xl border px-4 py-3.5 text-xs sm:text-sm transition-colors"
                role="status" aria-label="État des inscriptions"
                :class="periode.interactif ? 'border-emerald-200 bg-emerald-50 text-emerald-900' : 'border-rose-200 bg-rose-50 text-rose-900'">
                <span class="mt-0.5 flex h-8 w-8 flex-shrink-0 items-center justify-center rounded-full text-base"
                    :class="periode.interactif ? 'bg-emerald-100' : 'bg-rose-100'" aria-hidden="true"
                    x-text="periode.interactif ? (periode.statut === 'EXCEPTION_OPEN' ? '⚡' : '✓') : '🔒'">{{ $etatInscription['interactif'] ? ($etatInscription['statut'] === 'EXCEPTION_OPEN' ? '⚡' : '✓') : '🔒' }}</span>
                <div>
                    <p class="font-bold" x-text="periode.titre">{{ $etatInscription['titre'] }}</p>
                    <p class="mt-0.5" x-text="periode.message">{{ $etatInscription['message'] }}</p>
                    <p class="mt-1 text-xs opacity-80" x-show="periode.debut && periode.fin">Période : <span
                            x-text="periode.debut"></span> → <span x-text="periode.fin"></span>.</p>
                </div>
            </div>

            {{-- Reprise de dossier : ouvre le popup de choix (aucune logique métier ici) --}}
            <div
                class="mt-5 flex flex-col gap-4 rounded-2xl border border-primary-200 bg-gradient-to-r from-primary-50 to-white p-5 sm:flex-row sm:items-center sm:justify-between">
                <div class="flex items-start gap-3">
                    <span
                        class="flex h-10 w-10 flex-shrink-0 items-center justify-center rounded-xl bg-primary-800 text-white"
                        aria-hidden="true">
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round"
                                d="M2.25 12.75V12A2.25 2.25 0 0 1 4.5 9.75h15A2.25 2.25 0 0 1 21.75 12v.75m-8.69-6.44-2.12-2.12a1.5 1.5 0 0 0-1.061-.44H4.5A2.25 2.25 0 0 0 2.25 6v12a2.25 2.25 0 0 0 2.25 2.25h15A2.25 2.25 0 0 0 21.75 18V9a2.25 2.25 0 0 0-2.25-2.25h-5.379a1.5 1.5 0 0 1-1.06-.44Z" />
                        </svg>
                    </span>
                    <div>
                        <h3 class="text-sm sm:text-base font-bold text-slate-900">Déjà inscrit ? Reprenez votre dossier</h3>
                        <p class="mt-0.5 text-xs sm:text-sm text-slate-500">Inutile de remplir à nouveau : passez par le
                            parcours sécurisé de mise à jour.</p>
                    </div>
                </div>
                <button type="button" id="btn-choix-inscription" @click="ouvrirModal('choix')"
                    class="inline-flex flex-shrink-0 items-center justify-center gap-2 rounded-xl bg-primary-800 px-5 py-3 text-sm font-bold text-white shadow-sm transition hover:bg-primary-700 focus:outline-none focus:ring-4 focus:ring-primary-500/25 min-h-[48px]">
                    Continuer mon inscription
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2.2" stroke="currentColor"
                        aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5 21 12m0 0-7.5 7.5M21 12H3" />
                    </svg>
                </button>
            </div>

            {{-- Étape 1 — Identification --}}
            <section id="identification" aria-labelledby="titre-identification"
                class="mt-5 rounded-2xl border bg-slate-50/70 p-5 sm:p-6 transition-colors"
                :class="identification.etat === 'inconnu' ? 'border-emerald-300' : 'border-slate-200'">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <h3 id="titre-identification" class="text-sm sm:text-base font-bold text-slate-900">Étape 1 —
                            Identification</h3>
                        <p class="mt-1 text-xs sm:text-sm text-slate-500">Pour commencer, renseignez votre CIN et votre date
                            de naissance.</p>
                    </div>
                    <span x-show="identification.etat === 'inconnu'" x-cloak
                        class="inline-flex items-center gap-1.5 rounded-full bg-emerald-100 px-3 py-1 text-xs font-bold text-emerald-800">
                        <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="3" stroke="currentColor"
                            aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" />
                        </svg>
                        Vérifiée
                    </span>
                </div>

                <div class="mt-5 grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label for="ident-cin" class="mb-1.5 block text-xs font-semibold text-slate-700">CIN <span
                                class="text-rose-600" aria-hidden="true">*</span></label>
                        <input type="text" id="ident-cin" x-model="identification.cin" autocomplete="off" required
                            minlength="8" maxlength="8" inputmode="numeric" pattern="[0-9]{8}"
                            oninput="this.value = this.value.replace(/[^0-9]/g, '').slice(0, 8)"
                            @keydown.enter.prevent="verifierIdentite()"
                            class="w-full rounded-xl border border-slate-300 bg-white px-4 py-3 text-sm font-mono font-bold tracking-widest text-slate-900 placeholder:font-sans placeholder:font-normal placeholder:tracking-normal placeholder:text-slate-400 focus:border-primary-700 focus:ring-4 focus:ring-primary-500/20 focus:outline-none"
                            placeholder="8 chiffres">
                        <p class="mt-1 text-[11px] text-slate-500">Le CIN doit contenir exactement 8 chiffres.</p>
                    </div>
                    <div>
                        <label for="ident-naissance" class="mb-1.5 block text-xs font-semibold text-slate-700">Date de
                            naissance <span class="text-rose-600" aria-hidden="true">*</span></label>
                        <input type="text" id="ident-naissance" x-model="identification.naissance" placeholder="JJ/MM/AAAA"
                            autocomplete="off" required maxlength="10" inputmode="numeric"
                            pattern="^(0[1-9]|[12][0-9]|3[01])/(0[1-9]|1[0-2])/(19|20)[0-9]{2}$"
                            oninput="formaterDateNaissance(this)" @keydown.enter.prevent="verifierIdentite()"
                            class="w-full rounded-xl border border-slate-300 bg-white px-4 py-3 text-sm text-slate-900 placeholder:text-slate-400 focus:border-primary-700 focus:ring-4 focus:ring-primary-500/20 focus:outline-none">
                        <p class="mt-1 text-[11px] text-slate-500">Format obligatoire : JJ/MM/AAAA (ex : 17/05/2003).</p>
                    </div>
                </div>

                <div class="mt-5 flex flex-col sm:flex-row items-stretch sm:items-center gap-3">
                    <button type="button" @click="verifierIdentite()" :disabled="identification.etat === 'chargement'"
                        class="inline-flex items-center justify-center gap-2 rounded-xl bg-primary-800 px-6 py-3 text-sm font-bold text-white shadow-sm transition hover:bg-primary-700 focus:outline-none focus:ring-4 focus:ring-primary-500/25 min-h-[48px] disabled:opacity-60">
                        <svg x-show="identification.etat === 'chargement'" x-cloak class="h-4 w-4 animate-spin" fill="none"
                            viewBox="0 0 24 24" aria-hidden="true">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4">
                            </circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 0 1 8-8v4a4 4 0 0 0-4 4H4z"></path>
                        </svg>
                        <span x-show="identification.etat !== 'chargement'">Vérifier mon identité</span>
                        <span x-show="identification.etat === 'chargement'" x-cloak>Vérification en cours…</span>
                    </button>
                    <button type="button" x-show="identification.etat === 'inconnu'" x-cloak
                        @click="modifierIdentification()"
                        class="text-xs font-bold text-slate-500 underline underline-offset-2 hover:text-slate-800">Modifier
                        l'identification</button>
                </div>

                <div aria-live="polite" class="mt-3 text-xs sm:text-sm">
                    <p x-show="identification.etat === 'inconnu' && !identification.mensIndisponible" x-cloak
                        class="font-semibold text-emerald-800">Identité confirmée par le référentiel national MENS.
                        Complétez votre inscription.</p>
                    <p x-show="identification.etat === 'inconnu' && identification.mensIndisponible" x-cloak
                        class="font-semibold text-amber-800">Référentiel national indisponible — poursuite en saisie
                        manuelle autorisée.</p>
                    <p x-show="identification.etat === 'erreur'" x-cloak
                        class="flex items-start gap-2 rounded-xl bg-rose-50 px-3.5 py-2.5 font-semibold text-rose-700 ring-1 ring-rose-200">
                        <svg class="mt-0.5 h-4 w-4 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="2"
                            stroke="currentColor" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round"
                                d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008Z" />
                        </svg>
                        <span x-text="identification.message"></span>
                    </p>
                </div>
            </section>

            {{-- Erreurs serveur --}}
            @if($errors->any())
                <div class="mt-6 rounded-2xl border border-rose-300 bg-rose-50 p-4 sm:p-5" role="alert" tabindex="-1"
                    id="erreurs-globales">
                    <div class="flex items-start gap-3">
                        <svg class="mt-0.5 h-5 w-5 flex-shrink-0 text-rose-600" fill="none" viewBox="0 0 24 24" stroke-width="2"
                            stroke="currentColor" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round"
                                d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008Z" />
                        </svg>
                        <div class="flex-1">
                            <h3 class="text-sm font-bold text-rose-900">Le formulaire contient des erreurs
                                ({{ $errors->count() }}) — veuillez les corriger :</h3>
                            <ul class="mt-2 list-inside list-disc space-y-1 text-xs text-rose-800">
                                @foreach($errors->all() as $error)
                                    <li>{{ $error }}</li>
                                @endforeach
                            </ul>
                        </div>
                    </div>
                </div>
            @endif

            {{-- Le formulaire complet n'apparaît qu'après identification sans dossier existant --}}
            <div id="formulaire-detaille" class="scroll-mt-24" x-show="['inconnu'].includes(identification.etat)" x-cloak>
                @include('inscription._wizard', ['embarque' => false])
            </div>
        </section>

        {{-- ════════════ 3. AIDE ════════════ --}}
        <section id="aide" aria-labelledby="titre-aide" data-reveal="fade-up"
            class="mt-8 scroll-mt-24 rounded-3xl border border-slate-200 bg-white p-6 sm:p-8 shadow-sm">
            <h2 id="titre-aide" class="text-lg font-black text-slate-900">Besoin d'aide ?</h2>
            <div class="mt-4 divide-y divide-slate-200 rounded-2xl border border-slate-200">
                @foreach([
                        ['Quels documents préparer ?', 'Votre CIN (ou passeport), votre année et mention du bac, un email actif et la faculté visée.'],
                        ['Où suivre mon dossier ?', 'Via « Suivi » puis « Connexion » avec votre email (ou CIN) et le mot de passe créé à la dernière étape.'],
                        ['Mon scan est peu lisible', 'Le formulaire affiche « Vérification manuelle requise » : corrigez simplement les champs à la main, rien n\'est inventé.'],
                    ] as [$question, $reponse])
                    <details class="group p-4 open:bg-slate-50/70">
                        <summary
                            class="flex cursor-pointer list-none items-center justify-between gap-3 text-sm font-bold text-slate-900 focus:outline-none focus-visible:ring-4 focus-visible:ring-primary-500/25 rounded-lg">
                            {{ $question }}
                            <svg class="h-4 w-4 flex-shrink-0 text-slate-400 transition-transform group-open:rotate-180"
                                fill="none" viewBox="0 0 24 24" stroke-width="2.2" stroke="currentColor" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" />
                            </svg>
                        </summary>
                        <p class="mt-2 text-xs sm:text-sm text-slate-600 leading-relaxed">{{ $reponse }}</p>
                    </details>
                @endforeach
                <div class="p-4 text-xs sm:text-sm text-slate-600">
                    Consultez la <a href="{{ route('inscription.faq') }}"
                        class="font-bold text-primary-700 underline decoration-primary-300 underline-offset-2 hover:text-primary-900">FAQ officielle</a>
                    ou contactez la scolarité : {{ config('inscriptions.contact_scolarite.email') }} —
                    {{ config('inscriptions.contact_scolarite.horaires') }}.
                </div>
            </div>
        </section>

        {{-- ════════════ POPUP UNIQUE (choix · dossier existant · confirmé · manuel) ════════════
        Pure présentation : aucune opération métier à l'ouverture/fermeture. --}}
        <div x-show="modal" x-cloak x-transition:enter="transition ease-out duration-200"
            x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
            x-transition:leave="transition ease-in duration-150" x-transition:leave-start="opacity-100"
            x-transition:leave-end="opacity-0" x-effect="document.body.style.overflow = modal ? 'hidden' : ''"
            class="fixed inset-0 z-[80] flex items-end sm:items-center justify-center p-4" role="dialog" aria-modal="true"
            :aria-labelledby="'titre-modal-' + modal" @keydown.escape.window="if (modal) fermerModal()"
            @keydown.tab="piegerFocus($event, $el)">

            <div class="absolute inset-0 bg-slate-900/60 backdrop-blur-sm" @click="fermerModal()" aria-hidden="true"></div>

            <div
                class="popup-panneau relative w-full max-w-lg max-h-full overflow-y-auto rounded-3xl bg-white p-6 sm:p-8 shadow-2xl ring-1 ring-black/5">
                <button type="button" @click="fermerModal()" aria-label="Fermer"
                    class="absolute right-4 top-4 inline-flex h-9 w-9 items-center justify-center rounded-full text-slate-400 transition hover:bg-slate-100 hover:text-slate-700 focus:outline-none focus:ring-4 focus:ring-primary-500/25">
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"
                        aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
                    </svg>
                </button>

                {{-- ── Choix du parcours ── --}}
                <div x-show="modal === 'choix'">
                    <span
                        class="popup-halo flex h-14 w-14 items-center justify-center rounded-2xl bg-primary-100 text-primary-800"
                        style="--halo: rgba(30, 64, 175, .3)" aria-hidden="true">
                        <svg class="h-7 w-7" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round"
                                d="M15 19.128a9.38 9.38 0 0 0 2.625.372 9.337 9.337 0 0 0 4.121-.952 4.125 4.125 0 0 0-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 0 1 8.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0 1 11.964-3.07M12 6.375a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0Zm8.25 2.25a2.625 2.625 0 1 1-5.25 0 2.625 2.625 0 0 1 5.25 0Z" />
                        </svg>
                    </span>
                    <h3 id="titre-modal-choix" tabindex="-1"
                        class="mt-4 text-xl font-black text-slate-900 focus:outline-none">Comment souhaitez-vous vous
                        inscrire ?</h3>
                    <p class="mt-1 text-sm text-slate-500">Choisissez le parcours qui correspond à votre situation.</p>

                    <div class="mt-5 grid gap-3">
                        <a href="{{ route('inscription.existant.accueil') }}"
                            @click="if (navigationEnCours) $event.preventDefault(); navigationEnCours = true"
                            class="group flex items-center gap-4 rounded-2xl border-2 border-primary-200 bg-primary-50/60 p-4 transition hover:border-primary-700 hover:bg-primary-50 focus:outline-none focus:ring-4 focus:ring-primary-500/25">
                            <span
                                class="flex h-11 w-11 flex-shrink-0 items-center justify-center rounded-xl bg-primary-800 text-white"
                                aria-hidden="true">
                                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.8"
                                    stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round"
                                        d="M9 12.75 11.25 15 15 9.75m-3-7.036A11.959 11.959 0 0 1 3.598 6 11.99 11.99 0 0 0 3 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285Z" />
                                </svg>
                            </span>
                            <span class="flex-1">
                                <span class="block text-sm font-black text-slate-900">J'ai déjà un dossier</span>
                                <span class="block text-xs text-slate-500">Reprendre mon inscription et compléter les
                                    informations.</span>
                            </span>
                            <svg class="h-5 w-5 text-primary-700 transition-transform group-hover:translate-x-1" fill="none"
                                viewBox="0 0 24 24" stroke-width="2.2" stroke="currentColor" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" />
                            </svg>
                        </a>

                        <button type="button" @click="nouvelleInscription()"
                            class="group flex items-center gap-4 rounded-2xl border-2 border-slate-200 p-4 text-left transition hover:border-slate-400 hover:bg-slate-50 focus:outline-none focus:ring-4 focus:ring-primary-500/25">
                            <span
                                class="flex h-11 w-11 flex-shrink-0 items-center justify-center rounded-xl bg-slate-100 text-slate-700"
                                aria-hidden="true">
                                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.8"
                                    stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round"
                                        d="M18 7.5v3m0 0v3m0-3h3m-3 0h-3m-2.25-4.125a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0ZM3 19.235v-.11a6.375 6.375 0 0 1 12.75 0v.109A12.318 12.318 0 0 1 9.374 21c-2.331 0-4.512-.645-6.374-1.766Z" />
                                </svg>
                            </span>
                            <span class="flex-1">
                                <span class="block text-sm font-black text-slate-900">Je suis un nouvel étudiant</span>
                                <span class="block text-xs text-slate-500">Commencer une nouvelle procédure
                                    d'inscription.</span>
                            </span>
                            <svg class="h-5 w-5 text-slate-500 transition-transform group-hover:translate-x-1" fill="none"
                                viewBox="0 0 24 24" stroke-width="2.2" stroke="currentColor" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" />
                            </svg>
                        </button>
                    </div>
                </div>

                {{-- ── Dossier existant ── --}}
                <div x-show="modal === 'existe'">
                    <span
                        class="popup-halo flex h-14 w-14 items-center justify-center rounded-2xl bg-amber-100 text-amber-700"
                        style="--halo: rgba(245, 158, 11, .35)" aria-hidden="true">
                        <svg class="h-7 w-7" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round"
                                d="M16.5 10.5V6.75a4.5 4.5 0 1 0-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 0 0 2.25-2.25v-6.75a2.25 2.25 0 0 0-2.25-2.25H6.75a2.25 2.25 0 0 0-2.25 2.25v6.75a2.25 2.25 0 0 0 2.25 2.25Z" />
                        </svg>
                    </span>
                    <h3 id="titre-modal-existe" tabindex="-1"
                        class="mt-4 text-xl font-black text-slate-900 focus:outline-none">Un dossier existe déjà</h3>
                    <p class="mt-2 text-sm leading-relaxed text-slate-600">
                        Pour le consulter ou le mettre à jour, utilisez le parcours sécurisé : un code à usage unique sera
                        envoyé à l'adresse e-mail enregistrée sur votre dossier.
                    </p>
                    <div class="mt-4 flex items-center gap-3 rounded-2xl bg-slate-50 px-4 py-3 ring-1 ring-slate-200">
                        <svg class="h-5 w-5 flex-shrink-0 text-slate-400" fill="none" viewBox="0 0 24 24" stroke-width="1.8"
                            stroke="currentColor" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round"
                                d="M21.75 6.75v10.5a2.25 2.25 0 0 1-2.25 2.25h-15a2.25 2.25 0 0 1-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25m19.5 0v.243a2.25 2.25 0 0 1-1.07 1.916l-7.5 4.615a2.25 2.25 0 0 1-2.36 0L3.32 8.91a2.25 2.25 0 0 1-1.07-1.916V6.75" />
                        </svg>
                        <code class="font-mono text-sm font-bold text-slate-900"
                            x-text="identification.masked_email"></code>
                    </div>
                    <p class="mt-1.5 text-xs text-slate-500">Par sécurité, seule une partie de l'adresse est affichée. Aucun
                        nouveau dossier ne sera créé.</p>

                    <div class="mt-6 flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
                        <button type="button" @click="fermerModal()"
                            class="inline-flex items-center justify-center rounded-xl border border-slate-300 bg-white px-5 py-2.5 text-sm font-bold text-slate-700 transition hover:bg-slate-50 focus:outline-none focus:ring-4 focus:ring-primary-500/25 min-h-[44px]">Fermer</button>
                        <a :href="identification.redirection || '{{ route('inscription.existant.accueil') }}'"
                            class="inline-flex items-center justify-center rounded-xl bg-primary-800 px-6 py-3 text-sm font-bold text-white shadow-sm transition hover:bg-primary-700 focus:outline-none focus:ring-4 focus:ring-primary-500/25 min-h-[48px]">
                            Ouvrir le parcours de mise à jour
                        </a>
                    </div>
                    <p class="mt-4 text-center text-xs text-slate-500">
                        Ou <a href="{{ route('etudiant.connexion.show') }}"
                            class="font-bold text-primary-800 underline underline-offset-2">connectez-vous</a> à votre
                        espace étudiant avec votre email ou CIN.
                    </p>
                </div>

                {{-- ── Identité confirmée ── --}}
                <div x-show="modal === 'confirme'" class="text-center">
                    <span
                        class="popup-halo mx-auto flex h-16 w-16 items-center justify-center rounded-full bg-emerald-100 text-emerald-600"
                        aria-hidden="true">
                        <svg class="h-8 w-8" fill="none" viewBox="0 0 24 24" stroke-width="3" stroke="currentColor">
                            <path class="coche-trace" stroke-linecap="round" stroke-linejoin="round"
                                d="m4.5 12.75 6 6 9-13.5" />
                        </svg>
                    </span>
                    <h3 id="titre-modal-confirme" tabindex="-1"
                        class="mt-4 text-xl font-black text-slate-900 focus:outline-none">Identité confirmée</h3>
                    <p class="mx-auto mt-2 max-w-sm text-sm leading-relaxed text-slate-600">
                        Votre CIN et votre date de naissance ont été vérifiés par le référentiel national. Vos informations
                        ont été pré-remplies : il ne reste qu'à compléter le formulaire.
                    </p>
                    <button type="button" @click="continuer()"
                        class="mt-6 inline-flex w-full items-center justify-center gap-2 rounded-xl bg-primary-800 px-6 py-3 text-sm font-bold text-white shadow-sm transition hover:bg-primary-700 focus:outline-none focus:ring-4 focus:ring-primary-500/25 min-h-[48px]">
                        Continuer mon inscription
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2.2" stroke="currentColor"
                            aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 13.5 12 21m0 0-7.5-7.5M12 21V3" />
                        </svg>
                    </button>
                </div>

                {{-- ── Référentiel indisponible (saisie manuelle) ── --}}
                <div x-show="modal === 'manuel'" class="text-center">
                    <span
                        class="popup-halo mx-auto flex h-16 w-16 items-center justify-center rounded-full bg-amber-100 text-amber-600"
                        style="--halo: rgba(245, 158, 11, .35)" aria-hidden="true">
                        <svg class="h-8 w-8" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round"
                                d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931Zm0 0L19.5 7.125M18 14v4.75A2.25 2.25 0 0 1 15.75 21H5.25A2.25 2.25 0 0 1 3 18.75V8.25A2.25 2.25 0 0 1 5.25 6H10" />
                        </svg>
                    </span>
                    <h3 id="titre-modal-manuel" tabindex="-1"
                        class="mt-4 text-xl font-black text-slate-900 focus:outline-none">Référentiel national indisponible
                    </h3>
                    <p class="mx-auto mt-2 max-w-sm text-sm leading-relaxed text-slate-600">
                        La vérification automatique n'est pas possible pour le moment. Vous pouvez poursuivre en saisie
                        manuelle : votre dossier sera contrôlé par l'administration.
                    </p>
                    <button type="button" @click="continuer()"
                        class="mt-6 inline-flex w-full items-center justify-center rounded-xl bg-primary-800 px-6 py-3 text-sm font-bold text-white shadow-sm transition hover:bg-primary-700 focus:outline-none focus:ring-4 focus:ring-primary-500/25 min-h-[48px]">
                        Poursuivre en saisie manuelle
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Auto-formatage JJ/MM/AAAA. Le pré-contrôle regex vit dans
        // verifierIdentite() ; la validation serveur (VerifierCinRequest +
        // SaisieIdentification) reste souveraine.
        function formaterDateNaissance(input) {
            let v = input.value.replace(/\D/g, '');
            if (v.length > 2) v = v.substring(0, 2) + '/' + v.substring(2);
            if (v.length > 5) v = v.substring(0, 5) + '/' + v.substring(5);
            input.value = v.substring(0, 10);
        }

        // Piège de focus clavier pour les popups (Tab / Shift+Tab).
        function piegerFocus(evenement, racine) {
            const focalisables = [...racine.querySelectorAll('a[href], button:not([disabled])')].filter((e) => e.offsetParent !== null);
            if (!focalisables.length) return;
            const premier = focalisables[0];
            const dernier = focalisables[focalisables.length - 1];
            if (evenement.shiftKey && document.activeElement === premier) {
                dernier.focus(); evenement.preventDefault();
            } else if (!evenement.shiftKey && document.activeElement === dernier) {
                premier.focus(); evenement.preventDefault();
            }
        }

        document.addEventListener('alpine:init', () => {
            Alpine.data('periodeInscription', (initial, url, urlIdentifier) => ({
                periode: initial,
                modal: null,            // null | 'choix' | 'existe' | 'confirme' | 'manuel'
                declencheur: null,
                navigationEnCours: false,
                identification: { etat: 'initial', cin: '', naissance: '', message: '', masked_email: '', redirection: '', source: '', certitude: '', mensIndisponible: false },

                // ── Période (le backend reste la vérité, jamais recalculée en JS) ──
                actualiserPeriode() {
                    const faculte = document.getElementById('faculte_id')?.value ?? '';
                    const annee = document.getElementById('annee_academique')?.value ?? '';
                    const cin = document.getElementById('cin')?.value ?? '';
                    const params = new URLSearchParams({ faculte_id: faculte, annee: annee, cin: cin });
                    // Même origine (jamais de CORS) + sans cache navigateur.
                    fetch(`${url}?${params.toString()}`, { headers: { Accept: 'application/json' }, cache: 'no-store' })
                        .then((reponse) => (reponse.ok ? reponse.json() : null))
                        .then((json) => {
                            if (!json) return;
                            this.periode = json;
                            window.dispatchEvent(new CustomEvent('periode-actualisee', { detail: { interactif: json.interactif } }));
                        })
                        .catch(() => { });
                },
                init() {
                    this.actualiserPeriode();
                    setInterval(() => this.actualiserPeriode(), 60000);
                    document.addEventListener('visibilitychange', () => {
                        if (!document.hidden) this.actualiserPeriode();
                    });
                },

                // ── Popups ──
                ouvrirModal(nom) {
                    this.declencheur = document.activeElement;
                    this.navigationEnCours = false;
                    this.modal = nom;
                    this.$nextTick(() => document.getElementById('titre-modal-' + nom)?.focus());
                },
                fermerModal() {
                    if (this.modal === 'existe') this.fermerBoiteExiste();
                    this.modal = null;
                    this.$nextTick(() => this.declencheur?.focus?.());
                },
                nouvelleInscription() {
                    this.modal = null;
                    this.$nextTick(() => {
                        document.getElementById('identification')?.scrollIntoView({ behavior: 'smooth', block: 'center' });
                        document.getElementById('ident-cin')?.focus({ preventScroll: true });
                    });
                },
                continuer() {
                    this.modal = null;
                    this.$nextTick(() => document.getElementById('formulaire-detaille')?.scrollIntoView({ behavior: 'smooth', block: 'start' }));
                },

                // ── Identification ──
                modifierIdentification() {
                    this.identification.etat = 'initial';
                    for (const id of ['cin', 'date_naissance']) {
                        document.getElementById(id)?.removeAttribute('readonly');
                    }
                },
                verifierIdentite() {
                    if (this.identification.etat === 'chargement') return;

                    // Contrôle strict AVANT tout appel serveur : aucune requête
                    // n'est émise pour une saisie non conforme.
                    if (!/^[0-9]{8}$/.test(this.identification.cin)) {
                        this.identification.etat = 'erreur';
                        this.identification.message = 'Le CIN doit contenir exactement 8 chiffres numériques.';
                        return;
                    }
                    if (!/^(0[1-9]|[12][0-9]|3[01])\/(0[1-9]|1[0-2])\/(19|20)\d{2}$/.test(this.identification.naissance)) {
                        this.identification.etat = 'erreur';
                        this.identification.message = 'La date de naissance doit être au format JJ/MM/AAAA (ex : 17/05/2003).';
                        return;
                    }

                    this.identification.etat = 'chargement';
                    this.identification.message = '';
                    this.identification.mensIndisponible = false;
                    this.identification.source = '';
                    this.identification.certitude = '';
                    const jeton = document.querySelector('meta[name="csrf-token"]')?.content ?? '';
                    fetch(urlIdentifier, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': jeton },
                        body: JSON.stringify({ cin: this.identification.cin, date_naissance: this.identification.naissance }),
                    })
                        .then((reponse) => reponse.json().then((json) => ({ statut: reponse.status, json })).catch(() => null))
                        .then((resultat) => {
                            if (!resultat) {
                                this.identification.etat = 'erreur';
                                this.identification.message = 'Vérification impossible pour le moment, réessayez.';
                                return;
                            }
                            if (resultat.statut === 422) {
                                this.identification.etat = 'erreur';
                                this.identification.message = 'CIN et date de naissance requis (formats contrôlés).';
                                return;
                            }
                            if (resultat.statut === 429) {
                                this.identification.etat = 'erreur';
                                this.identification.message = 'Trop de tentatives — patientez une minute avant de réessayer.';
                                return;
                            }
                            const statut = resultat.json.status;
                            if (statut === 'MENS_UNAVAILABLE') {
                                this.identification.mensIndisponible = true;
                                this.identification.source = 'MANUELLE';
                                this.identification.etat = 'inconnu';
                                this.appliquerCinNaissanceManuel();
                                window.dispatchEvent(new CustomEvent('identification-verifiee'));
                                this.ouvrirModal('manuel');
                                return;
                            }
                            if (statut === 'AMBIGUOUS') {
                                this.identification.etat = 'erreur';
                                this.identification.message = resultat.json.message || 'Une ambiguïté d’identification a été détectée sur votre dossier. Veuillez contacter le service de scolarité.';
                                return;
                            }
                            if (statut === 'EXISTING_STUDENT') {
                                // Aucune donnée du dossier avant vérification :
                                // adresse masquée + parcours sécurisé (code à usage unique).
                                this.identification.masked_email = resultat.json.masked_email ?? '';
                                this.identification.redirection = resultat.json.redirection ?? '{{ route('inscription.existant.accueil') }}';
                                this.identification.etat = 'existe';
                                this.ouvrirModal('existe');
                                return;
                            }
                            if (statut === 'MENS_FOUND') {
                                this.identification.source = resultat.json.source || 'MENS';
                                this.identification.certitude = resultat.json.certitude || 'certaine';
                                this.appliquerPrefillMens(resultat.json.prefill || {});
                                this.identification.etat = 'inconnu';
                                // Identification vérifiée → le wizard passe de 1 à 2.
                                window.dispatchEvent(new CustomEvent('identification-verifiee'));
                                this.ouvrirModal('confirme');
                                return;
                            }
                            // Fail-closed : couple absent du référentiel ou statut inattendu.
                            this.identification.etat = 'erreur';
                            this.identification.message = 'Les informations fournies ne permettent pas de confirmer votre identité. Vérifiez votre CIN et votre date de naissance.';
                        })
                        .catch(() => {
                            this.identification.etat = 'erreur';
                            this.identification.message = 'Vérification impossible pour le moment, réessayez.';
                        });
                },
                fermerBoiteExiste() {
                    this.identification.etat = 'initial';
                    this.identification.masked_email = '';
                    this.identification.redirection = '';
                },
                appliquerCinNaissanceManuel() {
                    const cinForm = document.getElementById('cin');
                    if (cinForm && this.identification.cin) cinForm.value = this.identification.cin.toUpperCase();
                    const naissanceForm = document.getElementById('date_naissance');
                    if (naissanceForm && this.identification.naissance) {
                        // L'identification saisit JJ/MM/AAAA ; le champ d'état civil
                        // attend Y-m-d : conversion d'affichage uniquement (le serveur
                        // revalide la valeur soumise).
                        const m = /^(\d{2})\/(\d{2})\/(\d{4})$/.exec(this.identification.naissance);
                        naissanceForm.value = m ? `${m[3]}-${m[2]}-${m[1]}` : this.identification.naissance;
                    }
                },
                appliquerPrefillMens(prefill) {
                    const renseigner = (id, valeur) => {
                        const champ = document.getElementById(id);
                        if (champ && valeur) champ.value = valeur;
                    };
                    renseigner('nom', prefill.nom);
                    renseigner('prenom', prefill.prenom);
                    renseigner('date_naissance', prefill.date_naissance);
                    // CIN + naissance vérifiés : verrouillés (modification = re-vérification).
                    for (const id of ['cin', 'date_naissance']) {
                        const champ = document.getElementById(id);
                        if (champ) champ.setAttribute('readonly', 'readonly');
                    }
                    const cinForm = document.getElementById('cin');
                    if (cinForm && this.identification.cin) cinForm.value = this.identification.cin.toUpperCase();
                    const naissanceForm = document.getElementById('date_naissance');
                    if (naissanceForm && this.identification.naissance) naissanceForm.value = this.identification.naissance;
                    if (prefill.sexe) {
                        window.dispatchEvent(new CustomEvent('sexe-identifie', { detail: { sexe: prefill.sexe } }));
                    }
                },
            }));
        });
    </script>
@endsection