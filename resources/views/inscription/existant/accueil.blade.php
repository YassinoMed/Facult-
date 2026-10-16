@extends('layouts.app')

@section('sans_sidebar', true)
@section('title', 'Continuer ou mettre à jour votre dossier d\'inscription')
@section('meta_description', 'Parcours sécurisé pour retrouver votre dossier existant et soumettre une mise à jour, sans création de compte ni nouveau dossier.')

@section('content')
    @php
        $autonomeCodeOuvert = session('dossier_existant.autonome_etape') === 'code' || $errors->has('code_autonome');
        $autonomeFormOuvert = $errors->hasAny(['ancien_email', 'password', 'nouvel_email', 'nouvel_email_confirmation']);
        $classiqueOuvert = $codeEnvoye || $errors->hasAny(['cin', 'date_naissance', 'code']);
        $periodeOuverte = (bool) ($etatPeriode['interactif'] ?? false);

        // Classes partagées — TOUT le texte est noir (text-black), espacements généreux
        $input = 'w-full rounded-2xl border border-slate-300 bg-white px-4 py-3.5 text-base text-black shadow-sm transition duration-200 placeholder:text-slate-400 focus:border-primary-700 focus:ring-4 focus:ring-primary-500/20 focus:outline-none';
        $label = 'mb-2 block text-sm font-semibold text-black';
        $btnMain = 'btn-p';
        $btnSec = 'btn-s';
        $erreur = 'mt-2 text-sm font-semibold text-black';
        $info = 'rounded-2xl border border-sky-200 bg-sky-50 px-5 py-4 text-sm leading-relaxed text-black';
        $succes = 'rounded-2xl border border-emerald-200 bg-emerald-50 px-5 py-4 text-sm leading-relaxed text-black';
        $alerte = 'rounded-2xl border border-amber-200 bg-amber-50 px-5 py-4 text-sm leading-relaxed text-black';
        $overlay = 'absolute inset-0 bg-slate-900/60 backdrop-blur-sm';
        $panneau = 'relative w-full max-h-[92vh] overflow-y-auto rounded-3xl bg-white text-black shadow-2xl ring-1 ring-slate-900/10';
        $bloc = 'rounded-3xl border border-slate-200 bg-white p-6 sm:p-8';
    @endphp

    <style>
        [x-cloak] {
            display: none !important;
        }

        html {
            scroll-behavior: smooth;
        }

        /* ===== Mise en page de la page (CSS autonome, indépendant du build Tailwind) ===== */
        .page-ins {
            display: flex;
            flex-direction: column;
            gap: 2.5rem;
            width: 100%;
            max-width: 60rem;
            margin: 0 auto;
            padding: 3rem 1.25rem 4rem;
        }

        @media (min-width: 640px) {
            .page-ins {
                gap: 3rem;
                padding: 4.5rem 2rem 5rem;
            }
        }

        .hero-ins {
            width: 100%;
            padding: 2rem 1.5rem;
            text-align: center;
            background: linear-gradient(180deg, #eef2ff 0%, #ffffff 100%);
            border: 1px solid #e0e7ff;
            border-radius: 1.5rem;
            box-shadow: 0 8px 24px -18px rgba(30, 41, 59, .25);
        }

        @media (min-width: 640px) {
            .hero-ins {
                padding: 2.5rem 3rem;
            }
        }

        .hero-badge {
            display: inline-flex;
            align-items: center;
            gap: .5rem;
            padding: .3rem .9rem;
            font-size: .75rem;
            font-weight: 600;
            background: #fff;
            border: 1px solid #c7d2fe;
            border-radius: 999px;
        }

        .hero-titre {
            margin: 1rem 0 0;
            font-size: 1.5rem;
            font-weight: 800;
            line-height: 1.3;
            letter-spacing: -.01em;
        }

        @media (min-width: 640px) {
            .hero-titre {
                font-size: 1.75rem;
            }
        }

        .hero-texte {
            max-width: 32rem;
            margin: .6rem auto 0;
            font-size: .95rem;
            line-height: 1.7;
        }

        .statut-ins {
            display: flex;
            align-items: flex-start;
            gap: 1rem;
            width: 100%;
            padding: 1.35rem 1.75rem;
            font-size: .9rem;
            border: 1px solid;
            border-radius: 1.5rem;
        }

        .statut-ins.ok {
            background: #ecfdf5;
            border-color: #6ee7b7;
        }

        .statut-ins.ko {
            background: #fff1f2;
            border-color: #fda4af;
        }

        .statut-icone {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            width: 2.25rem;
            height: 2.25rem;
            background: #fff;
            border: 2px solid #000;
            border-radius: 50%;
            font-weight: 900;
        }

        .section-cartes {
            display: flex;
            flex-direction: column;
            gap: 2rem;
        }

        .cartes-ins {
            display: grid;
            grid-template-columns: 1fr;
            gap: 2rem;
        }

        @media (min-width: 768px) {
            .cartes-ins {
                grid-template-columns: 1.7fr 1fr;
            }
        }

        .carte-main,
        .carte-mail {
            position: relative;
            display: flex;
            flex-direction: column;
            padding: 2rem;
            border-radius: 1.75rem;
            transition: transform .2s ease, box-shadow .2s ease;
        }

        .carte-main {
            overflow: hidden;
            background: #eef2ff;
            border: 1px solid #c7d2fe;
        }

        .carte-mail {
            background: #fff;
            border: 1px solid #e2e8f0;
        }

        .carte-main:hover,
        .carte-mail:hover {
            transform: translateY(-3px);
            box-shadow: 0 18px 36px -20px rgba(30, 41, 59, .35);
        }

        @media (min-width: 768px) {
            .carte-main {
                padding: 2.75rem;
            }

            .carte-mail {
                padding: 2.25rem;
            }
        }

        .classique {
            width: 100%;
            overflow: hidden;
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 1.5rem;
            box-shadow: 0 1px 2px rgba(15, 23, 42, .05);
        }

        .classique-sum {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
            padding: 1.4rem 1.75rem;
            font-size: .95rem;
            font-weight: 800;
            list-style: none;
            cursor: pointer;
            transition: background-color .2s ease;
        }

        .classique-sum::-webkit-details-marker {
            display: none;
        }

        .classique-sum:hover {
            background: #f8fafc;
        }

        .classique-corps {
            display: flex;
            flex-direction: column;
            gap: 2.5rem;
            padding: 2.25rem 1.75rem 2.5rem;
            border-top: 1px solid #f1f5f9;
        }

        @media (min-width: 640px) {
            .classique-corps {
                padding: 2.5rem 2.5rem 3rem;
            }
        }

        .pied-ins {
            width: 100%;
            padding: 1.75rem 2rem;
            font-size: .9rem;
            line-height: 2;
            text-align: center;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 1.5rem;
        }

        /* ===== Boutons : style "pastille" contour noir + ombre décalée (CSS autonome) ===== */
        .btn-p,
        .btn-s {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: .5rem;
            min-height: 52px;
            padding: .85rem 2rem;
            font-size: .9rem;
            font-weight: 800;
            line-height: 1.2;
            text-align: center;
            color: #000;
            border: 2px solid #000;
            border-radius: 999px;
            cursor: pointer;
            transition: transform .15s ease, box-shadow .15s ease, background-color .15s ease;
        }

        .btn-p {
            background: #fef08a;
            box-shadow: 4px 4px 0 #000;
        }

        .btn-s {
            background: #fff;
            box-shadow: 0 0 0 #000;
        }

        .btn-p:hover {
            transform: translate(-2px, -2px);
            box-shadow: 6px 6px 0 #000;
        }

        .btn-p:active {
            transform: translate(3px, 3px);
            box-shadow: 1px 1px 0 #000;
        }

        .btn-s:hover {
            background: #f1f5f9;
            transform: translate(-1px, -1px);
            box-shadow: 3px 3px 0 #000;
        }

        .btn-p:focus-visible,
        .btn-s:focus-visible,
        .choix-carte:focus-visible {
            outline: 3px solid #6366f1;
            outline-offset: 3px;
        }

        .btn-p:disabled,
        .btn-s:disabled,
        .btn-p[aria-disabled="true"],
        .btn-s[aria-disabled="true"],
        span.btn-s[aria-disabled="true"] {
            opacity: .55;
            cursor: not-allowed;
            transform: none;
            box-shadow: none;
            pointer-events: none;
        }

        .choix-carte:disabled,
        .choix-carte[aria-disabled="true"] {
            opacity: .55;
            cursor: not-allowed;
            transform: none;
            box-shadow: none;
            background: #f1f5f9;
        }

        /* ===== Améliorations v2 : cohérence, alignements, espaces ===== */
        .page-ins {
            padding-top: 1.75rem;
        }

        @media (min-width: 640px) {
            .page-ins {
                padding-top: 2.5rem;
            }
        }

        .hero-ins,
        .carte-main,
        .carte-mail,
        .classique {
            border: 2px solid #000;
        }

        .hero-ins,
        .carte-main,
        .carte-mail {
            border-radius: 1.5rem;
        }

        .hero-etat {
            display: inline-flex;
            align-items: center;
            gap: .6rem;
            margin: 1.25rem 0 0;
            padding: .45rem 1.1rem;
            font-size: .85rem;
            font-weight: 700;
            border: 1.5px solid;
            border-radius: 999px;
        }

        .hero-etat.ok {
            background: #ecfdf5;
            border-color: #34d399;
        }

        .hero-etat.ko {
            background: #fff1f2;
            border-color: #fb7185;
        }

        .hero-point {
            width: .6rem;
            height: .6rem;
            border-radius: 50%;
        }

        .hero-etat.ok .hero-point {
            background: #10b981;
        }

        .hero-etat.ko .hero-point {
            background: #e11d48;
        }

        .hero-alerte {
            max-width: 32rem;
            margin: .9rem auto 0;
            font-size: .875rem;
            line-height: 1.7;
        }

        .carte-main {
            background: #eef2ff;
        }

        .carte-mail {
            background: #fff;
        }

        .carte-titre {
            font-size: 1.5rem;
            font-weight: 900;
            line-height: 1.3;
        }

        .carte-titre-sm {
            margin-top: 1.25rem;
            font-size: 1.15rem;
        }

        .carte-texte {
            margin-top: .75rem;
            font-size: .95rem;
            line-height: 1.8;
        }

        .carte-icone {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 3rem;
            height: 3rem;
            background: #fef3c7;
            border: 2px solid #000;
            border-radius: 50%;
        }

        .etapes {
            display: flex;
            flex-wrap: wrap;
            gap: .6rem;
            margin: 1.5rem 0 2rem;
            padding: 0;
            list-style: none;
        }

        .etapes li {
            display: inline-flex;
            align-items: center;
            gap: .5rem;
            padding: .35rem .9rem .35rem .4rem;
            font-size: .85rem;
            font-weight: 700;
            background: #fff;
            border: 1.5px solid #000;
            border-radius: 999px;
        }

        .etapes li span {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 1.5rem;
            height: 1.5rem;
            font-size: .75rem;
            font-weight: 800;
            background: #fef08a;
            border: 1.5px solid #000;
            border-radius: 50%;
        }

        /* Boutons des cartes : alignés en bas, même taille */
        .carte-main .btn-p,
        .carte-mail .btn-s {
            width: 100%;
            margin-top: auto;
        }

        .carte-mail .carte-texte {
            margin-bottom: 1.75rem;
        }

        .carte-note {
            margin-top: .75rem;
            font-size: .8rem;
            text-align: center;
        }

        .lien-aide {
            margin-top: 1rem;
            padding: .25rem;
            font-size: .8rem;
            font-weight: 600;
            text-align: center;
            text-decoration: underline;
            text-underline-offset: 4px;
            background: none;
            border: 0;
            cursor: pointer;
            transition: opacity .2s ease;
        }

        .lien-aide:hover {
            opacity: .65;
        }

        .lien-aide:focus-visible {
            outline: 3px solid #6366f1;
            outline-offset: 3px;
            border-radius: .5rem;
        }

        /* Pied de page : deux boutons */
        .pied-ins {
            padding: 1.75rem 1.5rem 2rem;
            line-height: 1.5;
        }

        .pied-texte {
            margin: 0 0 1.25rem;
            font-size: .85rem;
            font-weight: 700;
        }

        .pied-actions {
            display: flex;
            flex-wrap: wrap;
            justify-content: center;
            gap: 1rem;
        }

        a.btn-p,
        a.btn-s {
            text-decoration: none;
        }

        @media (max-width: 639px) {

            .btn-p,
            .btn-s {
                width: 100%;
            }

            .pied-actions {
                flex-direction: column;
            }
        }

        /* ===== Popup « récupérer l'accès » + lien d'aide + dates ===== */
        .lien-aide {
            display: block;
            width: 100%;
            margin-top: 1.25rem;
            padding: .75rem 1rem;
            font-size: .85rem;
            font-weight: 700;
            line-height: 1.5;
            text-align: center;
            text-decoration: none;
            white-space: normal;
            background: #fffbeb;
            border: 1.5px dashed #000;
            border-radius: 1rem;
            cursor: pointer;
            transition: background-color .2s ease, transform .15s ease;
        }

        .lien-aide:hover {
            background: #fef3c7;
            opacity: 1;
            transform: translateY(-1px);
        }

        .popup-aide.popup-aide {
            width: 100%;
            max-width: 38rem;
            padding: 2rem 1.5rem 2rem;
            border-radius: 1.5rem 1.5rem 0 0;
        }

        @media (min-width: 640px) {
            .popup-aide.popup-aide {
                padding: 2.5rem 2.75rem 2.75rem;
                border-radius: 1.5rem;
            }
        }

        .popup-aide>p:first-of-type {
            margin-top: .75rem;
            font-size: .9rem;
            line-height: 1.8;
        }

        .aide-liste {
            display: flex;
            flex-direction: column;
            gap: 1.25rem;
            margin: 2rem 0 0;
            padding: 0;
            list-style: none;
        }

        .aide-item {
            display: block;
            padding: 1.4rem 1.5rem;
            background: #fff;
            border: 2px solid #000;
            border-radius: 1.25rem;
            box-shadow: 3px 3px 0 #000;
        }

        .aide-item p {
            font-size: .875rem;
            line-height: 1.75;
        }

        .aide-item p:first-child {
            font-size: .95rem;
            font-weight: 800;
        }

        .aide-item p+p {
            margin-top: .4rem;
        }

        .aide-lien {
            display: inline-block;
            margin-top: .9rem;
            font-size: .875rem;
            font-weight: 800;
            text-decoration: underline;
            text-underline-offset: 4px;
        }

        .popup-aide>p.mt-8 {
            margin-top: 1.75rem;
        }

        /* ===== Fenêtre principale : espacements ===== */
        .popup-entete {
            position: sticky;
            top: 0;
            z-index: 10;
            padding: 1.75rem 1.5rem 1.5rem;
            background: rgba(255, 255, 255, .96);
            border-bottom: 1px solid #f1f5f9;
            backdrop-filter: blur(6px);
        }

        .popup-corps {
            padding: 2rem 1.5rem 2.5rem;
        }

        @media (min-width: 640px) {
            .popup-entete {
                padding: 2.25rem 2.75rem 1.75rem;
            }

            .popup-corps {
                padding: 2.5rem 2.75rem 3rem;
            }
        }

        .popup-titre {
            font-size: 1.25rem;
            font-weight: 900;
            line-height: 1.4;
        }

        @media (min-width: 640px) {
            .popup-titre {
                font-size: 1.5rem;
            }
        }

        /* ===== Cartes de choix : compactes, avec pastille flèche ===== */
        .choix-intro {
            margin: 0 0 2rem;
            font-size: .95rem;
            line-height: 1.7;
        }

        .choix-grille {
            display: grid;
            grid-template-columns: 1fr;
            gap: 1.75rem;
            padding: 0 .5rem .5rem 0;
        }

        .choix-carte {
            display: grid;
            grid-template-columns: 3.25rem 1fr 2.75rem;
            column-gap: 1.5rem;
            align-items: center;
            width: 100%;
            padding: 1.6rem 1.75rem;
            text-align: left;
            background: #fff;
            border: 2px solid #000;
            border-radius: 1.25rem;
            box-shadow: 3px 3px 0 #000;
            cursor: pointer;
            transition: transform .15s ease, box-shadow .15s ease, background-color .15s ease;
        }

        .choix-carte:hover {
            transform: translate(-2px, -2px);
            box-shadow: 5px 5px 0 #000;
            background: #fefce8;
        }

        .choix-carte:active {
            transform: translate(2px, 2px);
            box-shadow: 1px 1px 0 #000;
        }

        .choix-icone {
            grid-row: 1 / span 2;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 3.25rem;
            height: 3.25rem;
            border: 2px solid #000;
            border-radius: 50%;
        }

        .choix-titre {
            grid-column: 2;
            grid-row: 1;
            align-self: end;
            display: block;
            font-size: 1.05rem;
            font-weight: 900;
            line-height: 1.35;
        }

        .choix-desc {
            grid-column: 2;
            grid-row: 2;
            align-self: start;
            display: block;
            margin-top: .2rem;
            font-size: .875rem;
            line-height: 1.6;
        }

        .choix-fleche {
            grid-column: 3;
            grid-row: 1 / span 2;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 2.75rem;
            height: 2.75rem;
            font-size: 1.1rem;
            font-weight: 900;
            background: #fef08a;
            border: 2px solid #000;
            border-radius: 50%;
            transition: transform .15s ease;
        }

        .choix-carte:hover .choix-fleche {
            transform: translateX(4px);
        }

        @media (max-width: 480px) {
            .choix-carte {
                grid-template-columns: 3rem 1fr;
                padding: 1.1rem;
                column-gap: 1rem;
            }

            .choix-fleche {
                display: none;
            }
        }

        /* Bouton fermer rond, cohérent avec les boutons */
        .btn-fermer {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            width: 2.75rem;
            height: 2.75rem;
            background: #fff;
            border: 2px solid #000;
            border-radius: 50%;
            cursor: pointer;
            transition: transform .15s ease, background-color .15s ease;
        }

        .btn-fermer:hover {
            background: #fef08a;
            transform: rotate(90deg);
        }

        .btn-fermer:focus-visible {
            outline: 3px solid #6366f1;
            outline-offset: 3px;
        }


        /* Tout le texte de la page en noir */
        #page-inscription,
        #page-inscription * {
            color: #000;
        }

        #page-inscription ::placeholder {
            color: #94a3b8;
        }

        /* Apparition douce */
        @keyframes apparition {
            from {
                opacity: 0;
                transform: translateY(14px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .apparait {
            animation: apparition .6s ease-out backwards;
        }

        .apparait-2 {
            animation-delay: .1s;
        }

        .apparait-3 {
            animation-delay: .2s;
        }

        @media (prefers-reduced-motion: reduce) {

            html {
                scroll-behavior: auto;
            }

            .apparait,
            .popup-anim,
            .popup-anim * {
                transition: none !important;
                animation: none !important;
            }
        }
    </style>

    <div id="page-inscription" class="page-ins" x-data="{
                                            aideAcces: false,
                                            formAutonome: {{ $autonomeFormOuvert ? 'true' : 'false' }},
                                            ouvrirAutonome() { this.formAutonome = true; this.$nextTick(() => document.getElementById('autonome_cin')?.focus()); }
                                         }">

        {{-- ===================== HERO ===================== --}}
        <header class="apparait hero-ins" role="status">
            <span class="hero-badge">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"
                    aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round"
                        d="M16.5 10.5V6.75a4.5 4.5 0 1 0-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 0 0 2.25-2.25v-6.75a2.25 2.25 0 0 0-2.25-2.25H6.75a2.25 2.25 0 0 0-2.25 2.25v6.75a2.25 2.25 0 0 0 2.25 2.25Z" />
                </svg>
                Parcours sécurisé
            </span>
            <h1 class="hero-titre">Votre dossier d'inscription</h1>
            <p class="hero-texte">Retrouvez votre dossier avec votre CIN et un code reçu par e-mail.</p>
            <p class="hero-etat {{ $periodeOuverte ? 'ok' : 'ko' }}">
                <span class="sr-only">État des inscriptions : </span>
                <span class="hero-point" aria-hidden="true"></span>
                <span>{{ $etatPeriode['titre'] ?? 'Inscriptions' }}@if (!empty($etatPeriode['fin'])) · jusqu'au
                {{ $etatPeriode['fin'] }}@endif</span>
            </p>
            @if (!empty($etatPeriode['debut']) && !empty($etatPeriode['fin']))
                <p class="mt-1 text-xs opacity-80">Période : {{ $etatPeriode['debut'] }} → {{ $etatPeriode['fin'] }}</p>
            @endif
            @if (!$periodeOuverte && !empty($etatPeriode['message']))
                <p class="hero-alerte">{{ $etatPeriode['message'] }}</p>
            @endif
        </header>

        @if (session('statut'))
            <div class="{{ $info }}" role="status">{{ session('statut') }}</div>
        @endif

        {{-- ===================== PARCOURS 100 % PAGE (popup) ===================== --}}
        <div x-data="parcoursAccueil()"
            @ouvrir-existant.window="ouvrir(); aller('existant'); $nextTick(() => document.getElementById('m-ex-cin')?.focus())"
            @ouvrir-suivi.window="ouvrir(); aller('suivi'); $nextTick(() => document.getElementById('m-sv-reference')?.focus())"
            class="contents">

            {{-- Cartes d'entrée --}}
            <section aria-labelledby="titre-actions" class="apparait apparait-3 section-cartes">
                <h2 id="titre-actions" class="sr-only">Que souhaitez-vous faire ?</h2>
                <div class="cartes-ins">

                    {{-- Carte principale --}}
                    <article class="group carte-main">
                        <h3 class="carte-titre">Mon inscription</h3>
                        <p class="carte-texte">Reprenez votre dossier ou déposez une nouvelle inscription.</p>
                        <ol class="etapes" aria-label="Étapes du parcours">
                            <li><span aria-hidden="true">1</span> CIN</li>
                            <li><span aria-hidden="true">2</span> Code e-mail</li>
                            <li><span aria-hidden="true">3</span> Dossier</li>
                        </ol>
                        <button type="button" id="btn-commencer-accueil" @click="ouvrir()" class="{{ $btnMain }}"
                            @disabled(!$periodeOuverte) @if(!$periodeOuverte) aria-disabled="true"
                                title="Inscriptions fermées — aucune inscription ou modification n’est possible pour le moment."
                            @endif>
                            @if($periodeOuverte)
                                Commencer
                                <svg class="h-4 w-4 transition group-hover:translate-x-1" fill="none" viewBox="0 0 24 24"
                                    stroke-width="2.2" stroke="currentColor" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round"
                                        d="M13.5 4.5 21 12m0 0-7.5 7.5M21 12H3" />
                                </svg>
                            @else
                                🔒 Inscriptions fermées
                            @endif
                        </button>
                        @if(!$periodeOuverte)
                            <p class="carte-note" role="status">Période fermée : les inscriptions et modifications sont
                                suspendues jusqu’à la prochaine ouverture.</p>
                        @endif
                    </article>

                    {{-- Carte secondaire : e-mail --}}
                    <article class="carte-mail">
                        <span class="carte-icone" aria-hidden="true">
                            <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round"
                                    d="M21.75 6.75v10.5a2.25 2.25 0 0 1-2.25 2.25h-15a2.25 2.25 0 0 1-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25m19.5 0v.243a2.25 2.25 0 0 1-1.07 1.916l-7.5 4.615a2.25 2.25 0 0 1-2.36 0L3.32 8.91a2.25 2.25 0 0 1-1.07-1.916V6.75" />
                            </svg>
                        </span>
                        <h3 class="carte-titre carte-titre-sm">Adresse e-mail</h3>
                        <p class="carte-texte">Changer mon adresse e-mail sans passer par la scolarité : votre mot de passe
                            et un code de confirmation suffisent.</p>
                        <button type="button" @click="ouvrirAutonome()" class="{{ $btnSec }}" @disabled(!$periodeOuverte) {{ !$periodeOuverte ? 'aria-disabled="true" title="Inscriptions fermées."' : '' }}>@if($periodeOuverte)
                            Modifier mon adresse
                        @else
                                🔒 Modifier mon adresse
                            @endif</button>
                        @if ($autonomeCodeOuvert)
                            <p class="carte-note">Un code est en attente de saisie.</p>
                        @endif
                        @if(!$periodeOuverte)
                            <p class="carte-note" role="status">Période fermée : le changement d’adresse est suspendu jusqu’à la
                                prochaine ouverture.</p>
                        @endif
                        <button type="button"
                            @click="aideAcces = true; $nextTick(() => document.getElementById('titre-aide-acces')?.focus())"
                            class="lien-aide">Je n'ai plus accès à mon ancienne adresse</button>
                    </article>
                </div>
            </section>

            <noscript>
                <div class="rounded-2xl border border-slate-200 bg-white p-6 text-sm leading-loose">
                    JavaScript est désactivé : utilisez les parcours complets
                    <a class="font-bold underline" href="/inscription/existant">Dossier existant</a> ou
                    <a class="font-bold underline" href="/inscription/nouvelle">Nouvelle inscription</a>.
                </div>
            </noscript>

            {{-- ===== POPUP PRINCIPALE : choix → existant → code → dossier → email | nouvelle ===== --}}
            <div x-show="ouvert" x-cloak id="popup-parcours-accueil"
                class="popup-anim fixed inset-0 z-[80] flex items-end justify-center p-0 sm:items-center sm:p-6"
                role="dialog" aria-modal="true" aria-labelledby="titre-parcours-accueil"
                x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0"
                x-transition:enter-end="opacity-100" x-transition:leave="transition ease-in duration-150"
                x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0" @keydown.escape.window="fermer()"
                @keydown.tab="if (ouvert) { const f = [...$el.querySelectorAll('a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled])')].filter(e => e.offsetParent !== null); if (f.length) { const p = f[0], d = f[f.length - 1]; if ($event.shiftKey && document.activeElement === p) { d.focus(); $event.preventDefault(); } else if (!$event.shiftKey && document.activeElement === d) { p.focus(); $event.preventDefault(); } } }">
                <div class="{{ $overlay }}" @click="fermer()" aria-hidden="true"></div>

                <div class="{{ $panneau }} max-w-3xl rounded-b-none sm:rounded-b-3xl" data-panneau-defilant
                    x-transition:enter="transition ease-out duration-300"
                    x-transition:enter-start="translate-y-6 scale-95 opacity-0"
                    x-transition:enter-end="translate-y-0 scale-100 opacity-100">

                    {{-- En-tête collant + rail de progression --}}
                    <div class="popup-entete">
                        <div class="flex items-center justify-between gap-4">
                            <h3 id="titre-parcours-accueil" tabindex="-1" class="popup-titre focus:outline-none">Comment souhaitez-vous vous inscrire ?</h3>
                            <button type="button" id="btn-fermer-parcours-accueil" @click="fermer()"
                                aria-label="Fermer le parcours" class="btn-fermer"><svg class="h-5 w-5" fill="none"
                                    viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
                                </svg></button>
                        </div>
                        <ol class="mt-6 flex items-center gap-3"
                            x-show="['existant','existant-code','dossier'].includes(vue)" x-cloak aria-label="Progression">
                            <template x-for="(nom, i) in ['Identification', 'Code', 'Dossier']" :key="i">
                                <li class="flex flex-1 items-center gap-3 text-xs font-semibold transition"
                                    :class="['existant','existant-code','dossier'].indexOf(vue) >= i ? 'opacity-100' : 'opacity-40'">
                                    <span
                                        class="inline-flex h-7 w-7 items-center justify-center rounded-full border text-[11px] font-bold transition"
                                        :class="['existant','existant-code','dossier'].indexOf(vue) >= i ? 'border-primary-700 bg-primary-200' : 'border-slate-300 bg-slate-100'"
                                        x-text="i + 1"></span>
                                    <span x-text="nom"></span>
                                    <span class="h-px flex-1 bg-slate-200" x-show="i < 2"></span>
                                </li>
                            </template>
                        </ol>
                    </div>

                    <div class="popup-corps">

                        {{-- ÉTAT : choix --}}
                        <p x-show="vue === 'choix'" class="choix-intro">Choisissez la situation qui vous correspond.</p>
                        <div x-show="vue === 'choix'" id="etat-choix-parcours" class="choix-grille">
                            @if(!$periodeOuverte)
                                <p class="choix-intro" role="status">🔒 Période fermée : le choix d’un parcours d’inscription
                                    est suspendu jusqu’à la prochaine ouverture.</p>
                            @endif
                            <button type="button" id="btn-choix-deja-inscrit"
                                @click="aller('existant'); $nextTick(() => document.getElementById('m-ex-cin')?.focus())"
                                class="choix-carte group focus:outline-none focus:ring-4 focus:ring-primary-500/25"
                                @disabled(!$periodeOuverte) @if(!$periodeOuverte) aria-disabled="true"
                                title="Inscriptions fermées." @endif>
                                <span class="choix-icone" style="background:#e0e7ff" aria-hidden="true">
                                    <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke-width="1.8"
                                        stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round"
                                            d="M2.25 12.75V12A2.25 2.25 0 0 1 4.5 9.75h15A2.25 2.25 0 0 1 21.75 12v.75m-8.69-6.44-2.12-2.12a1.5 1.5 0 0 0-1.061-.44H4.5A2.25 2.25 0 0 0 2.25 6v12a2.25 2.25 0 0 0 2.25 2.25h15A2.25 2.25 0 0 0 21.75 18V9a2.25 2.25 0 0 0-2.25-2.25h-5.379a1.5 1.5 0 0 1-1.06-.44Z" />
                                    </svg>
                                </span>
                                <span class="choix-titre">Dossier existant</span>
                                <span class="choix-desc">Déjà inscrit : retrouvez votre dossier avec un
                                    code de vérification.</span>
                                <span class="choix-fleche" aria-hidden="true">→</span>
                            </button>
                            <button type="button" id="btn-choix-nouvel-etudiant"
                                @click="aller('nouvelle'); $nextTick(() => document.getElementById('m-id-cin')?.focus())"
                                class="choix-carte group focus:outline-none focus:ring-4 focus:ring-primary-500/25"
                                @disabled(!$periodeOuverte) @if(!$periodeOuverte) aria-disabled="true"
                                title="Inscriptions fermées." @endif>
                                <span class="choix-icone" style="background:#d1fae5" aria-hidden="true">
                                    <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke-width="1.8"
                                        stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round"
                                            d="M18 7.5v3m0 0v3m0-3h3m-3 0h-3m-2.25-4.125a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0ZM3 19.235v-.11a6.375 6.375 0 0 1 12.75 0v.109A12.318 12.318 0 0 1 9.374 21c-2.331 0-4.512-.645-6.374-1.766Z" />
                                    </svg>
                                </span>
                                <span class="choix-titre">Nouvel étudiant</span>
                                <span class="choix-desc">Je m'inscris pour la première fois avec le
                                    formulaire officiel.</span>
                                <span class="choix-fleche" aria-hidden="true">→</span>
                            </button>
                        </div>

                        {{-- ÉTAT : dossier existant — recherche --}}
                        <div x-show="vue === 'existant'" x-cloak id="etat-dossier-existant">
                            <h4 class="text-lg font-black">Retrouver votre dossier</h4>
                            <p class="mt-2 text-sm leading-loose">Nous enverrons un code à l'adresse enregistrée sur votre
                                dossier.</p>
                            <div x-show="message" x-cloak class="{{ $info }} mt-5" role="status" x-text="message"></div>
                            <form action="/inscription/existant/recherche" method="POST" class="mt-8 space-y-8"
                                @submit.prevent="rechercherDossier($el)">
                                @csrf
                                <div class="grid grid-cols-1 gap-6 sm:grid-cols-2">
                                    <div>
                                        <label for="m-ex-cin" class="{{ $label }}">Numéro de CIN <span>*</span></label>
                                        <input type="text" id="m-ex-cin" name="cin" required minlength="8" maxlength="8"
                                            inputmode="numeric" pattern="[0-9]{8}" autocomplete="off" placeholder="12345678"
                                            class="{{ $input }} font-mono">
                                    </div>
                                    <div>
                                        <label for="m-ex-dob" class="{{ $label }}">Date de naissance
                                            <span>*</span></label>
                                        <input type="date" id="m-ex-dob" name="date_naissance" required min="1900-01-01"
                                            max="{{ date('Y-m-d') }}" oninput="limiterAnnee(this)" class="{{ $input }}">
                                    </div>
                                </div>
                                <div class="flex flex-col-reverse gap-4 sm:flex-row sm:justify-end">
                                    <button type="button" @click="precedant()" class="{{ $btnSec }}">Retour</button>
                                    <button type="submit" :disabled="envoi || {{ $periodeOuverte ? 'false' : 'true' }}"
                                        @disabled(!$periodeOuverte) @if(!$periodeOuverte) title="Inscriptions fermées."
                                        @endif class="{{ $btnMain }}">
                                        <span x-show="!envoi">Recevoir mon code</span><span x-show="envoi" x-cloak>Envoi en
                                            cours…</span>
                                    </button>
                                </div>
                            </form>
                        </div>

                        {{-- ÉTAT : vérification du code --}}
                        <div x-show="vue === 'existant-code'" x-cloak id="etat-verification-code">
                            <h4 class="text-lg font-black">Vérifier votre identité</h4>
                            <p class="mt-2 text-sm leading-loose">Saisissez le code à 6 chiffres reçu par e-mail. Il est
                                valable 10 minutes et ne sert qu'une fois.</p>
                            <div x-show="message" x-cloak class="{{ $info }} mt-5" role="status" x-text="message"></div>
                            <form action="/inscription/existant/verification" method="POST" class="mt-8 space-y-8"
                                @submit.prevent="verifierCode($el)">
                                @csrf
                                <div class="max-w-xs">
                                    <label for="m-ex-code" class="{{ $label }}">Code de vérification
                                        <span>*</span></label>
                                    <input type="text" id="m-ex-code" name="code" required inputmode="numeric"
                                        pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code" placeholder="••••••"
                                        class="{{ $input }} text-center font-mono text-xl tracking-[.5em]">
                                    <p x-show="erreurs.code" x-cloak class="{{ $erreur }}" role="alert">⚠ <span
                                            x-text="erreurs.code && erreurs.code[0]"></span></p>
                                </div>
                                <div class="flex flex-col-reverse gap-4 sm:flex-row sm:justify-end">
                                    <button type="button" @click="precedant()" class="{{ $btnSec }}">Retour</button>
                                    <button type="submit" :disabled="envoi || {{ $periodeOuverte ? 'false' : 'true' }}"
                                        @disabled(!$periodeOuverte) @if(!$periodeOuverte) title="Inscriptions fermées."
                                        @endif class="{{ $btnMain }}">
                                        <span x-show="!envoi">Vérifier et accéder</span><span x-show="envoi"
                                            x-cloak>Vérification…</span>
                                    </button>
                                </div>
                            </form>
                        </div>

                        {{-- ÉTAT : dossier vérifié --}}
                        <div x-show="vue === 'dossier'" x-cloak id="etat-dossier-verifie" class="space-y-8">
                            <h4 id="titre-dossier" tabindex="-1" class="text-lg font-black focus:outline-none">Votre
                                dossier</h4>
                            <div x-show="dossierFlags.message" x-cloak class="{{ $alerte }}" role="status"
                                x-text="dossierFlags.message"></div>

                            <dl class="grid grid-cols-1 gap-x-10 gap-y-1 rounded-3xl bg-slate-50 p-6 text-sm sm:grid-cols-2 sm:p-8"
                                x-show="dossier">
                                <div class="flex justify-between gap-4 border-b border-slate-200/70 py-3.5">
                                    <dt class="opacity-60">Numéro</dt>
                                    <dd class="font-mono font-bold" x-text="dossier && dossier.num_inscription"></dd>
                                </div>
                                <div class="flex justify-between gap-4 border-b border-slate-200/70 py-3.5">
                                    <dt class="opacity-60">CIN</dt>
                                    <dd class="font-mono font-bold" x-text="dossier && dossier.cin_masquee"></dd>
                                </div>
                                <div class="flex justify-between gap-4 border-b border-slate-200/70 py-3.5">
                                    <dt class="opacity-60">Nom</dt>
                                    <dd class="font-bold" x-text="dossier && dossier.nom"></dd>
                                </div>
                                <div class="flex justify-between gap-4 border-b border-slate-200/70 py-3.5">
                                    <dt class="opacity-60">Prénom</dt>
                                    <dd class="font-bold" x-text="dossier && dossier.prenom"></dd>
                                </div>
                                <div class="flex justify-between gap-4 border-b border-slate-200/70 py-3.5">
                                    <dt class="opacity-60">Naissance</dt>
                                    <dd class="font-bold" x-text="dossier && dossier.date_naissance"></dd>
                                </div>
                                <div class="flex justify-between gap-4 border-b border-slate-200/70 py-3.5">
                                    <dt class="opacity-60">E-mail</dt>
                                    <dd class="break-all text-right font-mono font-bold"
                                        x-text="dossier && dossier.email_masque"></dd>
                                </div>
                                <div class="flex items-center justify-between gap-4 py-3.5 sm:col-span-2">
                                    <dt class="opacity-60">Statut</dt>
                                    <dd class="rounded-full border border-primary-200 bg-primary-100 px-4 py-1 text-xs font-bold"
                                        x-text="dossier && dossier.statut_label"></dd>
                                </div>
                            </dl>

                            <div x-show="coordsSucces && coordsSucces.num_inscription" x-cloak class="{{ $succes }}"
                                role="status">
                                ✓ Demande enregistrée (<span x-text="coordsSucces?.nb_champs_modifies"></span> champ(s)) —
                                dossier <span class="font-mono font-bold" x-text="coordsSucces?.num_inscription"></span>.
                            </div>

                            {{-- Coordonnées --}}
                            <section x-show="dossierFlags.modifiable" class="{{ $bloc }}">
                                <h5 class="text-base font-black">Mettre à jour mes coordonnées</h5>
                                <div x-show="message && vue === 'dossier'" x-cloak class="{{ $info }} mt-5" role="status"
                                    x-text="message"></div>
                                <form action="/inscription/existant" method="POST" class="mt-6 space-y-6"
                                    @submit.prevent="enregistrerCoords($el)">
                                    @csrf
                                    <div class="grid grid-cols-1 gap-6 sm:grid-cols-2">
                                        <div><label for="m-co-tel" class="{{ $label }}">Téléphone</label><input type="text"
                                                id="m-co-tel" name="telephone" autocomplete="tel" class="{{ $input }}">
                                        </div>
                                        <div><label for="m-co-ville" class="{{ $label }}">Ville *</label><input type="text"
                                                id="m-co-ville" name="ville" required class="{{ $input }}"></div>
                                        <div><label for="m-co-cp" class="{{ $label }}">Code postal *</label><input
                                                type="text" id="m-co-cp" name="code_postal" required class="{{ $input }}">
                                        </div>
                                        <div class="sm:col-span-2"><label for="m-co-adr" class="{{ $label }}">Adresse
                                                *</label><textarea id="m-co-adr" name="adresse" rows="3" required
                                                class="{{ $input }}"></textarea></div>
                                    </div>
                                    <label class="flex items-start gap-3 text-sm leading-relaxed"><input type="checkbox"
                                            name="confirmation" value="1" required
                                            class="mt-0.5 h-5 w-5 rounded border-slate-300"> Je certifie sur l'honneur
                                        l'exactitude des renseignements fournis.</label>
                                    <button type="submit" :disabled="envoi || {{ $periodeOuverte ? 'false' : 'true' }}"
                                        @disabled(!$periodeOuverte) @if(!$periodeOuverte) title="Inscriptions fermées."
                                        @endif class="{{ $btnMain }} w-full sm:w-auto">
                                        <span x-show="!envoi">Soumettre ma mise à jour</span><span x-show="envoi"
                                            x-cloak>Envoi…</span>
                                    </button>
                                </form>
                            </section>

                            {{-- Adresse e-mail (3 étapes) --}}
                            <section x-show="dossierFlags.email" class="{{ $bloc }}">
                                <h5 class="text-base font-black">Changer mon adresse e-mail</h5>
                                <p class="mt-3 text-sm leading-loose">Pour mettre à jour votre adresse email, indiquez la
                                    nouvelle adresse : un code sera envoyé à votre adresse actuelle, puis un second à la
                                    nouvelle.</p>
                                <div x-show="emailMessage" x-cloak class="{{ $info }} mt-5" role="status"
                                    x-text="emailMessage"></div>
                                <div x-show="emailSucces" x-cloak class="{{ $succes }} mt-5" role="status">✓ Adresse mise
                                    à jour : <strong class="font-mono" x-text="emailSucces"></strong></div>

                                <div x-show="emailEtape === 'saisie'">
                                    <form action="/inscription/existant/email" method="POST" class="mt-6 space-y-6"
                                        @submit.prevent="demanderEmail($el)">
                                        @csrf
                                        <div class="grid grid-cols-1 gap-6 sm:grid-cols-2">
                                            <div><label for="m-email-nouveau" class="{{ $label }}">Nouvelle adresse
                                                    *</label><input type="email" id="m-email-nouveau" name="nouvel_email"
                                                    required autocomplete="email" class="{{ $input }}"></div>
                                            <div><label for="m-email-confirm" class="{{ $label }}">Confirmation
                                                    *</label><input type="email" id="m-email-confirm"
                                                    name="nouvel_email_confirmation" required autocomplete="email"
                                                    class="{{ $input }}"></div>
                                        </div>
                                        <p x-show="emailErreurs.nouvel_email" x-cloak class="{{ $erreur }}" role="alert">⚠
                                            <span x-text="emailErreurs.nouvel_email && emailErreurs.nouvel_email[0]"></span>
                                        </p>
                                        <button type="submit" :disabled="envoi"
                                            class="{{ $btnMain }} w-full sm:w-auto"><span
                                                x-show="!envoi">Enregistrer</span><span x-show="envoi"
                                                x-cloak>Envoi…</span></button>
                                    </form>
                                </div>
                                <div x-show="emailEtape === 'code'">
                                    <form action="/inscription/existant/email/confirmer" method="POST"
                                        class="mt-6 space-y-6" @submit.prevent="confirmerEmail($el, 'm-email-code')">
                                        @csrf
                                        <div class="max-w-xs"><label for="m-email-code" class="{{ $label }}">Code reçu
                                                (adresse actuelle) *</label><input type="text" id="m-email-code" name="code"
                                                required inputmode="numeric" pattern="[0-9]{6}" maxlength="6"
                                                autocomplete="one-time-code"
                                                class="{{ $input }} text-center font-mono text-xl tracking-[.5em]"></div>
                                        <p x-show="emailErreurs.code" x-cloak class="{{ $erreur }}" role="alert">⚠ <span
                                                x-text="emailErreurs.code && emailErreurs.code[0]"></span></p>
                                        <button type="submit" :disabled="envoi"
                                            class="{{ $btnMain }} w-full sm:w-auto"><span x-show="!envoi">Vérifier ce
                                                code</span><span x-show="envoi" x-cloak>Vérification…</span></button>
                                    </form>
                                </div>
                                <div x-show="emailEtape === 'code-nouvelle'">
                                    <form action="/inscription/existant/email/confirmer-nouvelle" method="POST"
                                        class="mt-6 space-y-6" @submit.prevent="confirmerEmail($el, 'm-email-code2')">
                                        @csrf
                                        <div class="max-w-xs"><label for="m-email-code2" class="{{ $label }}">Code reçu
                                                (nouvelle adresse) *</label><input type="text" id="m-email-code2"
                                                name="code_nouvelle" required inputmode="numeric" pattern="[0-9]{6}"
                                                maxlength="6" autocomplete="one-time-code"
                                                class="{{ $input }} text-center font-mono text-xl tracking-[.5em]"></div>
                                        <p x-show="emailErreurs.code_nouvelle" x-cloak class="{{ $erreur }}" role="alert">⚠
                                            <span
                                                x-text="emailErreurs.code_nouvelle && emailErreurs.code_nouvelle[0]"></span>
                                        </p>
                                        <button type="submit" :disabled="envoi"
                                            class="{{ $btnMain }} w-full sm:w-auto"><span x-show="!envoi">Confirmer le
                                                changement</span><span x-show="envoi" x-cloak>Vérification…</span></button>
                                    </form>
                                </div>
                                <div x-show="emailEtape === 'succes'">
                                    <button type="button" @click="emailEtape = 'saisie'; emailSucces = '';"
                                        class="{{ $btnSec }} mt-6">Nouvelle modification</button>
                                </div>
                            </section>

                            <button type="button" @click="precedant()" class="{{ $btnSec }}">Retour</button>
                        </div>

                        {{-- ÉTAT : nouvelle inscription --}}
                        <div x-show="vue === 'nouvelle'" x-cloak id="etat-nouvelle-inscription" class="space-y-8">
                            <div>
                                <h4 class="text-lg font-black">Nouvelle inscription</h4>
                                <p class="mt-2 text-sm leading-loose">Vérifions d'abord que vous n'avez pas déjà un dossier,
                                    puis le formulaire officiel s'affichera ici.</p>
                            </div>
                            <div x-show="identMessage" x-cloak class="{{ $info }}" role="status" x-text="identMessage">
                            </div>
                            <form action="/inscription/identifier" method="POST" class="space-y-6"
                                @submit.prevent="identifierNouveau($el)">
                                @csrf
                                <div class="grid grid-cols-1 gap-6 sm:grid-cols-2">
                                    <div><label for="m-id-cin" class="{{ $label }}">CIN *</label><input type="text"
                                            id="m-id-cin" name="cin" required autocomplete="off" placeholder="12345678"
                                            class="{{ $input }} font-mono"></div>
                                    <div><label for="m-id-dob" class="{{ $label }}">Date de naissance *</label><input
                                            type="date" id="m-id-dob" name="date_naissance" required min="1900-01-01"
                                            max="{{ date('Y-m-d') }}" oninput="limiterAnnee(this)" class="{{ $input }}">
                                    </div>
                                </div>
                                <p x-show="identErreurs.cin || identErreurs.date_naissance" x-cloak class="{{ $erreur }}"
                                    role="alert">⚠ Vérifiez la saisie (CIN de 8 chiffres, date valide).</p>
                                <div class="flex flex-col-reverse gap-4 sm:flex-row sm:justify-end">
                                    <button type="button" @click="precedant()" class="{{ $btnSec }}">Retour</button>
                                    <button type="submit" :disabled="envoi || {{ $periodeOuverte ? 'false' : 'true' }}"
                                        @disabled(!$periodeOuverte) @if(!$periodeOuverte) title="Inscriptions fermées."
                                        @endif class="{{ $btnMain }}"><span x-show="!envoi">Vérifier mon
                                            identité</span><span x-show="envoi" x-cloak>Vérification…</span></button>
                                </div>
                            </form>
                            <div id="panneau-nouvelle" x-show="nouvellePrete" x-cloak>
                                @include('inscription._wizard', ['embarque' => true, 'prefixe' => 'emb-'])
                            </div>
                        </div>

                        {{-- ÉTAT : suivi de dossier (consultation publique, sans navigation) --}}
                        <div x-show="vue === 'suivi'" x-cloak id="etat-suivi" class="space-y-8">
                            <div>
                                <h4 class="text-lg font-black">Suivre mon dossier</h4>
                                <p class="mt-2 text-sm leading-loose">Renseignez votre numéro d'inscription officiel ainsi que votre CIN ou date de naissance. La consultation reste disponible même lorsque les inscriptions sont fermées.</p>
                            </div>
                            <div x-show="suiviMessage" x-cloak class="{{ $info }}" role="status" x-text="suiviMessage"></div>
                            <form action="{{ route('inscription.suivi.rechercher') }}" method="POST" class="space-y-6"
                                @submit.prevent="rechercherSuivi($el)">
                                @csrf
                                <div class="grid grid-cols-1 gap-6 sm:grid-cols-2">
                                    <div><label for="m-sv-reference" class="{{ $label }}">Numéro d'inscription *</label><input type="text"
                                            id="m-sv-reference" name="reference" required autocomplete="off" placeholder="INS-2026-00001"
                                            class="{{ $input }} font-mono"></div>
                                    <div><label for="m-sv-secondaire" class="{{ $label }}">CIN ou date de naissance *</label><input
                                            type="text" id="m-sv-secondaire" name="secondaire" required autocomplete="off" placeholder="CIN ou JJ/MM/AAAA"
                                            class="{{ $input }}"></div>
                                </div>
                                <div class="flex flex-col-reverse gap-4 sm:flex-row sm:justify-end">
                                    <button type="button" @click="precedant()" class="{{ $btnSec }}">Retour</button>
                                    <button type="submit" :disabled="envoi" class="{{ $btnMain }}"><span
                                            x-show="!envoi">Rechercher mon dossier</span><span x-show="envoi"
                                            x-cloak>Recherche…</span></button>
                                </div>
                            </form>
                            <div x-show="suivi" x-cloak class="space-y-6">
                                <div class="{{ $bloc }}">
                                    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                                        <div>
                                            <span class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-bold border"
                                                :class="suivi && suivi.statut_brut === 'valide' ? 'border-emerald-300 bg-emerald-50 text-emerald-900' : (suivi && suivi.statut_brut === 'rejete' ? 'border-rose-300 bg-rose-50 text-rose-900' : 'border-amber-300 bg-amber-50 text-amber-900')"
                                                x-text="suivi && suivi.statut_libelle"></span>
                                            <h5 class="mt-2 text-base font-black" x-text="suivi && suivi.etudiant && suivi.etudiant.nom_complet"></h5>
                                            <p class="text-xs font-mono mt-0.5">Réf. <span x-text="suivi && suivi.reference"></span> · <span x-text="suivi && suivi.faculte_nom"></span></p>
                                        </div>
                                        <p class="text-xs">Dernière mise à jour : <strong x-text="suivi && suivi.derniere_mise_a_jour"></strong></p>
                                    </div>
                                    <div class="mt-4 rounded-2xl border border-sky-200 bg-sky-50/60 p-4">
                                        <h6 class="text-sm font-bold" x-text="suivi && suivi.prochaine_action && suivi.prochaine_action.titre"></h6>
                                        <p class="mt-1 text-xs leading-relaxed" x-text="suivi && suivi.prochaine_action && suivi.prochaine_action.description"></p>
                                    </div>
                                </div>
                                <div class="{{ $bloc }}">
                                    <h6 class="text-sm font-black mb-4">Progression d'instruction</h6>
                                    <ol class="space-y-4 border-l-2 border-slate-200 ml-3 pl-5 text-xs">
                                        <template x-for="(etape, i) in (suivi && suivi.chronologie ? suivi.chronologie : [])" :key="i">
                                            <li class="relative">
                                                <span class="absolute -left-[27px] top-0.5 flex h-4 w-4 items-center justify-center rounded-full text-[9px] font-bold text-white"
                                                    :class="(etape.statut === 'complete' || etape.statut === 'valide') ? 'bg-emerald-600' : (etape.statut === 'en_cours' ? 'bg-sky-600' : (etape.statut === 'action_requise' ? 'bg-amber-600' : 'bg-slate-300'))"
                                                    x-text="(etape.statut === 'complete' || etape.statut === 'valide') ? '✓' : (etape.statut === 'action_requise' ? '!' : '·')"></span>
                                                <div class="flex items-center justify-between gap-2">
                                                    <strong class="text-slate-900" x-text="etape.titre"></strong>
                                                    <span class="text-[10px] text-slate-400" x-text="etape.date"></span>
                                                </div>
                                                <p class="text-slate-500 mt-0.5" x-text="etape.description"></p>
                                            </li>
                                        </template>
                                    </ol>
                                </div>
                                <div class="flex flex-col-reverse gap-4 sm:flex-row sm:justify-end">
                                    <button type="button" @click="nouvelleRechercheSuivi()" class="{{ $btnSec }}">Nouvelle recherche</button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>{{-- /parcoursAccueil --}}

        {{-- ===================== CHANGEMENT D'E-MAIL AUTONOME (popups) ===================== --}}
        <div x-data="autonomeEmail({ codeOuvert: {{ $autonomeCodeOuvert ? 'true' : 'false' }} })"
            x-init="if (codeOuvert) { $nextTick(() => document.getElementById('code_autonome')?.focus()); }"
            class="contents">

            {{-- Retours visibles sur la page --}}
            <div x-show="message" x-cloak class="{{ $info }}" role="status" x-text="message"></div>
            <div x-show="succes" x-cloak class="{{ $succes }}" role="status">
                ✓ <span x-text="succes"></span> <strong class="font-mono" x-text="succesAdresse"></strong>
            </div>
            @if ($autonomeCodeOuvert)
                <div class="{{ $info }}" role="status">
                    Un code de confirmation est en attente pour votre nouvelle adresse.
                    <button type="button"
                        @click="codeOuvert = true; $nextTick(() => document.getElementById('code_autonome')?.focus())"
                        class="font-bold underline underline-offset-4" @disabled(!$periodeOuverte)>Saisir le code reçu</button>
                </div>
            @endif
            @if (session('dossier_existant.autonome_succes'))
                <div class="{{ $succes }}" role="status">
                    ✓ Votre adresse e-mail a été mise à jour : <strong
                        class="font-mono">{{ session('dossier_existant.autonome_succes') }}</strong>.
                    @if (session('dossier_existant.email_notifications_en_attente'))
                        <span class="mt-2 block">L'envoi des e-mails de notification sera retenté : votre changement
                            est bien enregistré.</span>
                    @endif
                </div>
            @endif

            {{-- POPUP 1 : formulaire de changement autonome --}}
            <div x-show="formAutonome" x-cloak
                class="popup-anim fixed inset-0 z-[80] flex items-end justify-center sm:items-center sm:p-6" role="dialog"
                aria-modal="true" aria-labelledby="titre-form-autonome"
                x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0"
                x-transition:enter-end="opacity-100" x-transition:leave="transition ease-in duration-150"
                x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0"
                @keydown.escape.window="if (!envoi && !confirme && !codeOuvert) formAutonome = false">
                <div class="{{ $overlay }}" @click="if (!envoi && !confirme && !codeOuvert) formAutonome = false"
                    aria-hidden="true"></div>
                <div class="{{ $panneau }} max-w-2xl rounded-b-none sm:rounded-b-3xl">
                    <div class="flex items-start justify-between gap-4 border-b border-slate-100 px-6 py-7 sm:px-10">
                        <div>
                            <h3 id="titre-form-autonome" tabindex="-1" class="text-xl font-black">Changer mon
                                adresse e-mail</h3>
                            <p class="mt-2 text-sm leading-loose">Prouvez votre identité : un code sera envoyé à votre
                                <strong>nouvelle</strong> adresse. L'actuelle reste active jusqu'à sa saisie.
                            </p>
                        </div>
                        <button type="button" @click="formAutonome = false" aria-label="Fermer"
                            class="-mr-2 -mt-1 inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-xl hover:bg-slate-100 focus:outline-none focus:ring-4 focus:ring-primary-500/25"><svg
                                class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"
                                aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
                            </svg></button>
                    </div>

                    <form action="{{ route('inscription.existant.autonome') }}" method="POST"
                        class="space-y-8 px-6 py-8 sm:px-10" x-ref="autonomeForm">
                        @csrf
                        <div class="grid grid-cols-1 gap-6 sm:grid-cols-2">
                            <div>
                                <label for="autonome_cin" class="{{ $label }}">Numéro de CIN <span>*</span></label>
                                <input type="text" id="autonome_cin" name="cin" value="{{ old('cin') }}"
                                    placeholder="12345678" required minlength="8" maxlength="8" inputmode="numeric"
                                    pattern="[0-9]{8}" autocomplete="off"
                                    oninput="this.value = this.value.replace(/[^0-9]/g, '').slice(0, 8)"
                                    class="{{ $input }} font-mono @error('cin') border-rose-400 bg-rose-50/50 @enderror">
                                @error('cin')
                                <p class="{{ $erreur }}" role="alert">⚠ {{ $message }}</p>@enderror
                                <p x-show="erreurs.cin" x-cloak class="{{ $erreur }}" role="alert">⚠ <span
                                        x-text="erreurs.cin && erreurs.cin[0]"></span></p>
                            </div>
                            <div>
                                <label for="autonome_naissance" class="{{ $label }}">Date de naissance
                                    <span>*</span></label>
                                <input type="text" id="autonome_naissance" name="date_naissance"
                                    value="{{ old('date_naissance') }}" placeholder="JJ/MM/AAAA" required maxlength="10"
                                    inputmode="numeric" pattern="^(0[1-9]|[12][0-9]|3[01])/(0[1-9]|1[0-2])/(19|20)[0-9]{2}$"
                                    autocomplete="off" oninput="formaterDateNaissance(this)"
                                    class="{{ $input }} @error('date_naissance') border-rose-400 bg-rose-50/50 @enderror">
                                @error('date_naissance')
                                <p class="{{ $erreur }}" role="alert">⚠ {{ $message }}</p>@enderror
                                <p x-show="erreurs.date_naissance" x-cloak class="{{ $erreur }}" role="alert">⚠ <span
                                        x-text="erreurs.date_naissance && erreurs.date_naissance[0]"></span></p>
                            </div>
                            <div>
                                <label for="ancien_email" class="{{ $label }}">Ancienne adresse e-mail
                                    <span>*</span></label>
                                <input type="email" id="ancien_email" name="ancien_email" value="{{ old('ancien_email') }}"
                                    required autocomplete="email"
                                    class="{{ $input }} @error('ancien_email') border-rose-400 bg-rose-50/50 @enderror">
                                @error('ancien_email')
                                <p class="{{ $erreur }}" role="alert">⚠ {{ $message }}</p>@enderror
                                <p x-show="erreurs.ancien_email" x-cloak class="{{ $erreur }}" role="alert">⚠ <span
                                        x-text="erreurs.ancien_email && erreurs.ancien_email[0]"></span></p>
                            </div>
                            <div>
                                <label for="autonome_password" class="{{ $label }}">Mot de passe du compte
                                    <span>*</span></label>
                                <input type="password" id="autonome_password" name="password" required
                                    autocomplete="current-password"
                                    class="{{ $input }} @error('password') border-rose-400 bg-rose-50/50 @enderror">
                                @error('password')
                                <p class="{{ $erreur }}" role="alert">⚠ {{ $message }}</p>@enderror
                                <p x-show="erreurs.password" x-cloak class="{{ $erreur }}" role="alert">⚠ <span
                                        x-text="erreurs.password && erreurs.password[0]"></span></p>
                            </div>
                            <div>
                                <label for="autonome_nouvel_email" class="{{ $label }}">Nouvelle adresse e-mail
                                    <span>*</span></label>
                                <input type="email" id="autonome_nouvel_email" name="nouvel_email"
                                    value="{{ old('nouvel_email') }}" required autocomplete="email"
                                    class="{{ $input }} @error('nouvel_email') border-rose-400 bg-rose-50/50 @enderror">
                                @error('nouvel_email')
                                <p class="{{ $erreur }}" role="alert">⚠ {{ $message }}</p>@enderror
                                <p x-show="erreurs.nouvel_email" x-cloak class="{{ $erreur }}" role="alert">⚠ <span
                                        x-text="erreurs.nouvel_email && erreurs.nouvel_email[0]"></span></p>
                            </div>
                            <div>
                                <label for="autonome_nouvel_email_confirmation" class="{{ $label }}">Confirmer la nouvelle
                                    adresse <span>*</span></label>
                                <input type="email" id="autonome_nouvel_email_confirmation" name="nouvel_email_confirmation"
                                    value="{{ old('nouvel_email_confirmation') }}" required autocomplete="email"
                                    class="{{ $input }} @error('nouvel_email_confirmation') border-rose-400 bg-rose-50/50 @enderror">
                                @error('nouvel_email_confirmation')
                                <p class="{{ $erreur }}" role="alert">⚠ {{ $message }}</p>@enderror
                                <p x-show="erreurs.nouvel_email_confirmation" x-cloak class="{{ $erreur }}" role="alert">⚠
                                    <span
                                        x-text="erreurs.nouvel_email_confirmation && erreurs.nouvel_email_confirmation[0]"></span>
                                </p>
                            </div>
                        </div>
                        <div class="flex flex-col-reverse gap-4 pt-2 sm:flex-row sm:justify-end">
                            <button type="button" @click="formAutonome = false" class="{{ $btnSec }}">Annuler</button>
                            <button type="button" id="autonome_envoyer"
                                @click="confirme = true; $nextTick(() => document.getElementById('titre-confirme-autonome')?.focus())"
                                class="{{ $btnMain }}">Envoyer le code à ma nouvelle adresse</button>
                        </div>
                    </form>
                </div>
            </div>

            {{-- POPUP 2 : confirmation pré-envoi.
            L'adresse enregistrée n'y est JAMAIS affichée (pas d'oracle d'existence). --}}
            <div x-show="confirme" x-cloak class="popup-anim fixed inset-0 z-[90] flex items-center justify-center p-5"
                role="dialog" aria-modal="true" aria-labelledby="titre-confirme-autonome"
                x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0 scale-95"
                x-transition:enter-end="opacity-100 scale-100" x-transition:leave="transition ease-in duration-150"
                x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0"
                @keydown.escape.window="confirme = false; $nextTick(() => document.getElementById('autonome_envoyer')?.focus())">
                <div class="{{ $overlay }}" aria-hidden="true"></div>
                <div class="{{ $panneau }} max-w-xl p-8 sm:p-10">
                    <span class="inline-flex h-14 w-14 items-center justify-center rounded-2xl bg-amber-100"
                        aria-hidden="true">
                        <svg class="h-7 w-7" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round"
                                d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" />
                        </svg>
                    </span>
                    <h3 id="titre-confirme-autonome" tabindex="-1" class="mt-6 text-xl font-black focus:outline-none">
                        Confirmer votre demande de changement d'adresse e-mail</h3>
                    <p class="mt-4 text-sm leading-loose">
                        Pour sécuriser votre compte, nous vous recommandons de confirmer depuis votre adresse actuelle : le
                        parcours vérifié lui envoie un code avant toute modification.
                        Sans accès à cette adresse, vous pouvez continuer ici : un code sera envoyé à votre
                        <strong>nouvelle</strong> adresse.
                        Le changement est irréversible une fois confirmé.
                    </p>
                    <div class="mt-8 flex flex-col gap-4">
                        <button type="button" @click="envoyerDemande()" :disabled="envoi" class="{{ $btnMain }} w-full">
                            <span x-show="!envoi">Continuer sans l'ancienne adresse</span><span x-show="envoi" x-cloak>Envoi
                                en cours…</span>
                        </button>
                        <button type="button" @click="confirme = false; formAutonome = false; $dispatch('ouvrir-existant')"
                            class="{{ $btnSec }} w-full">Vérifier via mon ancienne adresse</button>
                        <button type="button"
                            @click="confirme = false; $nextTick(() => document.getElementById('autonome_envoyer')?.focus())"
                            class="{{ $btnSec }} w-full">Annuler</button>
                    </div>
                </div>
            </div>

            {{-- POPUP 3 : code reçu sur la nouvelle adresse --}}
            <div x-show="codeOuvert" x-cloak class="popup-anim fixed inset-0 z-[90] flex items-center justify-center p-5"
                role="dialog" aria-modal="true" aria-labelledby="titre-code-autonome"
                x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0 scale-95"
                x-transition:enter-end="opacity-100 scale-100" x-transition:leave="transition ease-in duration-150"
                x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0"
                @keydown.escape.window="if (!envoi) { codeOuvert = false; }">
                <div class="{{ $overlay }}" @click="if (!envoi) { codeOuvert = false; }" aria-hidden="true"></div>
                <div class="{{ $panneau }} max-w-md p-8 sm:p-10">
                    <span class="inline-flex h-14 w-14 items-center justify-center rounded-2xl bg-primary-100"
                        aria-hidden="true">
                        <svg class="h-7 w-7" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round"
                                d="M9 12.75 11.25 15 15 9.75m-3-7.036A11.959 11.959 0 0 1 3.598 6 11.99 11.99 0 0 0 3 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285Z" />
                        </svg>
                    </span>
                    <h3 id="titre-code-autonome" tabindex="-1" class="mt-6 text-xl font-black focus:outline-none">
                        Confirmer la nouvelle adresse</h3>
                    <p class="mt-4 text-sm leading-loose">
                        Saisissez le code à 6 chiffres envoyé à votre nouvelle adresse (valable 30 minutes, usage unique).
                        Votre adresse actuelle reste active jusqu'à cette confirmation.
                    </p>
                    <form action="{{ route('inscription.existant.autonome.confirmer') }}" method="POST"
                        class="mt-8 space-y-8" @submit.prevent="confirmerCode()" x-ref="autonomeCodeForm">
                        @csrf
                        <div>
                            <label for="code_autonome" class="{{ $label }}">Code reçu <span>*</span></label>
                            <input type="text" id="code_autonome" name="code_autonome" value="{{ old('code_autonome') }}"
                                inputmode="numeric" pattern="[0-9]{6}" maxlength="6" required autocomplete="one-time-code"
                                placeholder="••••••"
                                class="{{ $input }} text-center font-mono text-2xl tracking-[.5em] @error('code_autonome') border-rose-400 bg-rose-50/50 @enderror">
                            @error('code_autonome')
                            <p class="{{ $erreur }}" role="alert">⚠ {{ $message }}</p>@enderror
                            <p x-show="erreurs.code_autonome" x-cloak class="{{ $erreur }}" role="alert">⚠ <span
                                    x-text="erreurs.code_autonome && erreurs.code_autonome[0]"></span></p>
                        </div>
                        <div class="flex flex-col-reverse gap-4 sm:flex-row sm:justify-end">
                            <button type="button" @click="if (!envoi) { codeOuvert = false; }"
                                class="{{ $btnSec }}">Annuler</button>
                            <button type="submit" :disabled="envoi" class="{{ $btnMain }}"><span x-show="!envoi">Confirmer
                                    le changement</span><span x-show="envoi" x-cloak>Vérification…</span></button>
                        </div>
                    </form>
                    <p class="mt-6 text-xs leading-loose">Aucun code reçu ? Vérifiez la nouvelle adresse puis refaites la
                        demande pour en recevoir un nouveau.</p>
                </div>
            </div>
        </div>{{-- /autonomeEmail --}}

        {{-- ===================== POPUP : récupérer l'accès ===================== --}}
        <div x-show="aideAcces" x-cloak
            class="popup-anim fixed inset-0 z-[80] flex items-end justify-center sm:items-center sm:p-6" role="dialog"
            aria-modal="true" aria-labelledby="titre-aide-acces" x-transition:enter="transition ease-out duration-200"
            x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
            x-transition:leave="transition ease-in duration-150" x-transition:leave-start="opacity-100"
            x-transition:leave-end="opacity-0" @keydown.escape.window="aideAcces = false">
            <div class="{{ $overlay }}" @click="aideAcces = false" aria-hidden="true"></div>
            <div class="{{ $panneau }} popup-aide">
                <h3 id="titre-aide-acces" tabindex="-1" class="popup-titre focus:outline-none">
                    Récupérer l'accès à votre dossier</h3>
                <p class="mt-3 text-sm leading-loose">
                    Par sécurité, aucune modification d'adresse email n'est effectuée automatiquement à distance :
                    votre identité doit d'abord être vérifiée par la scolarité.
                </p>
                <ol class="aide-liste">
                    <li class="aide-item">
                        <p class="text-sm font-bold">Contactez la scolarité</p>
                        <p class="mt-2 text-sm leading-loose">Indiquez votre numéro de dossier, votre CIN et une adresse
                            joignable. Un agent habilité vérifie votre identité avant toute modification
                            ({{ config('inscriptions.contact_scolarite.horaires') }}).</p>
                        <p class="mt-3 font-mono text-sm font-bold">
                            {{ config('inscriptions.contact_scolarite.email') }}
                        </p>
                    </li>
                </ol>
                <p class="{{ $alerte }} mt-8 !text-xs">Ne communiquez jamais votre mot
                    de passe ni vos codes : aucun agent ne vous les demandera.</p>
                <div class="mt-8 flex justify-end">
                    <button type="button" @click="aideAcces = false" class="{{ $btnSec }}">Fermer</button>
                </div>
            </div>
        </div>

        {{-- ===================== REPLI SANS JAVASCRIPT : formulaires classiques ===================== --}}
        <details class="group classique" @if ($classiqueOuvert) open @endif>
            <summary class="classique-sum">
                <span>Formulaire classique (sans fenêtre)</span>
                <svg class="h-4 w-4 transition group-open:rotate-180" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" />
                </svg>
            </summary>
            <div class="classique-corps">
                <div>
                    <h2 class="text-lg font-black">1. Retrouver votre dossier</h2>
                    <form action="{{ route('inscription.existant.recherche') }}" method="POST" class="mt-8 space-y-8">
                        @csrf
                        <div class="grid grid-cols-1 gap-6 sm:grid-cols-2">
                            <div>
                                <label for="cin" class="{{ $label }}">Numéro de CIN <span>*</span></label>
                                <input type="text" id="cin" name="cin" value="{{ old('cin') }}" placeholder="12345678" required minlength="8" maxlength="8" inputmode="numeric" pattern="[0-9]{8}" autocomplete="off" oninput="this.value = this.value.replace(/[^0-9]/g, '').slice(0, 8)" class="{{ $input }} font-mono @error('cin') border-rose-400 bg-rose-50/50 @enderror">
                                <p class="mt-2 text-xs">Exactement 8 chiffres.</p>
                                @error('cin')
                                <p class="{{ $erreur }}">⚠ {{ $message }}</p>@enderror
                            </div>
                            <div>
                                <label for="date_naissance" class="{{ $label }}">Date de naissance <span>*</span></label>
                                <input type="text" id="date_naissance" name="date_naissance" value="{{ old('date_naissance') }}" placeholder="JJ/MM/AAAA" required maxlength="10" inputmode="numeric" pattern="^(0[1-9]|[12][0-9]|3[01])/(0[1-9]|1[0-2])/(19|20)[0-9]{2}$" autocomplete="off" oninput="formaterDateNaissance(this)" class="{{ $input }} @error('date_naissance') border-rose-400 bg-rose-50/50 @enderror">
                                <p class="mt-2 text-xs">Format JJ/MM/AAAA (ex : 17/05/2003).</p>
                                @error('date_naissance')
                                <p class="{{ $erreur }}">⚠ {{ $message }}</p>@enderror
                            </div>
                        </div>
                        <button type="submit" class="{{ $btnMain }} w-full sm:w-auto" @disabled(!$periodeOuverte) {{ !$periodeOuverte ? 'title="Inscriptions fermées."' : '' }}>@if($periodeOuverte)
                                Recevoir mon code de vérification
                            @else
                                🔒 Inscriptions fermées
                            @endif</button>
                    </form>
                </div>
                @if ($codeEnvoye)
                    <div class="border-t border-slate-100 pt-10">
                        <h2 class="text-lg font-black">2. Vérifier votre identité</h2>
                        <p class="mt-2 text-sm leading-loose">Saisissez le code à 6 chiffres reçu par e-mail (10 minutes, usage unique).</p>
                        <form action="{{ route('inscription.existant.verification') }}" method="POST" class="mt-8 space-y-8">
                            @csrf
                            <div class="max-w-xs">
                                <label for="code" class="{{ $label }}">Code de vérification <span>*</span></label>
                                <input type="text" id="code" name="code" value="{{ old('code') }}" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" required autocomplete="one-time-code" class="{{ $input }} text-center font-mono text-xl tracking-[.5em] @error('code') border-rose-400 bg-rose-50/50 @enderror">
                                @error('code')
                                <p class="{{ $erreur }}">⚠ {{ $message }}</p>@enderror
                            </div>
                            <button type="submit" class="{{ $btnMain }} w-full sm:w-auto" @disabled(!$periodeOuverte) {{ !$periodeOuverte ? 'title="Inscriptions fermées."' : '' }}>@if($periodeOuverte)
                                    Vérifier et accéder à mon dossier
                                @else
                                    🔒 Inscriptions fermées
                                @endif</button>
                        </form>
                        <p class="mt-5 text-xs leading-loose">Aucun e-mail reçu ? Relancez la recherche ci-dessus pour obtenir un nouveau code, ou contactez la scolarité.</p>
                    </div>
                @endif
            </div>
        </details>


        {{-- ===================== ORIENTATION ALTERNATIVE ===================== --}}
        <footer class="pied-ins">
            <p class="pied-texte">Autres démarches</p>
            <div class="pied-actions">
                @if($periodeOuverte)
                    <a href="{{ route('inscription.create') }}" class="btn-s">Nouvelle inscription</a>
                @else
                    <span class="btn-s" aria-disabled="true"
                        title="Inscriptions fermées — aucune nouvelle inscription n’est possible pour le moment." role="link">🔒
                        Nouvelle inscription</span>
                @endif
                <a href="{{ route('inscription.suivi') }}" @click.prevent="$dispatch('ouvrir-suivi')" class="btn-s">Suivre mon dossier</a>
            </div>
            @if(!$periodeOuverte)
                <p class="mt-3 text-xs" role="status">Période fermée : la création d’un nouveau dossier est suspendue. Le suivi
                    d’un dossier existant reste accessible.</p>
            @endif
        </footer>

        {{-- Auto-formatage JJ/MM/AAAA ; la validation serveur reste souveraine. --}}
        <script>
            // Année limitée à 4 chiffres pour les champs <input type="date">
            function limiterAnnee(input) {
                if (!input.value) return;
                const p = input.value.split('-');
                if (p[0].length > 4) {
                    p[0] = p[0].slice(0, 4);
                    input.value = p.join('-');
                }
            }

            function formaterDateNaissance(input) {
                let v = input.value.replace(/\D/g, '');
                if (v.length > 2) v = v.substring(0, 2) + '/' + v.substring(2);
                if (v.length > 5) v = v.substring(0, 5) + '/' + v.substring(5);
                input.value = v.substring(0, 10);
            }
        </script>
    </div>
@endsection