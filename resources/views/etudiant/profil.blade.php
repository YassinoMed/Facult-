@extends('layouts.app')

@section('title', 'Fiche Étudiant — Espace Scolarité')
@section('meta_description', 'Dossier académique officiel de l\'étudiant : état civil, affectation universitaire, coordonnées et sécurité du compte.')

@section('content')
<div class="profil-v2" x-data="{ showPassword: false }">

    {{-- ═══════════ HERO BANNER — immersif avec gradient + motif ═══════════ --}}
    <div class="relative overflow-hidden rounded-2xl bg-gradient-to-br from-primary-900 via-primary-800 to-primary-950 px-6 py-8 sm:px-10 sm:py-12 lg:px-16 xl:px-20">

        {{-- Orbes lumineux d'ambiance --}}
        <div class="absolute -top-20 -right-20 h-72 w-72 rounded-full bg-accent-500/20 blur-[100px] animate-pulse-subtle" aria-hidden="true"></div>
        <div class="absolute -bottom-24 -left-16 h-80 w-80 rounded-full bg-primary-400/15 blur-[120px] animate-float" aria-hidden="true"></div>
        <div class="absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 h-96 w-96 aurora-glow rounded-full opacity-30" aria-hidden="true"></div>

        {{-- Grille de fond en filigrane --}}
        <div class="absolute inset-0 bg-grid-fade opacity-30" aria-hidden="true"></div>

        {{-- Contenu du héro --}}
        <div class="relative z-10 flex flex-col gap-8 lg:flex-row lg:items-center lg:justify-between">

            {{-- Identité principale --}}
            <div class="flex items-center gap-5 sm:gap-6 animate-fade-up">
                {{-- Avatar avec anneau gradient animé --}}
                <div class="relative flex-shrink-0">
                    <div class="profil-v2-avatar-ring">
                        <div class="flex h-20 w-20 sm:h-24 sm:w-24 items-center justify-center rounded-2xl bg-white/10 backdrop-blur-xl border border-white/20 text-2xl sm:text-3xl font-black tracking-wider text-white shadow-xl">
                            {{ mb_strtoupper(mb_substr($etudiant->prenom, 0, 1)) }}{{ mb_strtoupper(mb_substr($etudiant->nom, 0, 1)) }}
                        </div>
                    </div>
                    <div class="absolute -bottom-1.5 -right-1.5 flex h-8 w-8 items-center justify-center rounded-xl bg-gradient-to-br from-accent-500 to-accent-600 text-white shadow-lg shadow-accent-500/30 ring-3 ring-primary-900">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2.2" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M4.26 10.147a60.438 60.438 0 0 0-.491 6.347A48.62 48.62 0 0 1 12 20.904a48.62 48.62 0 0 1 8.232-4.41 60.46 60.46 0 0 0-.491-6.347m-15.482 0a50.636 50.636 0 0 0-2.658-.813A59.906 59.906 0 0 1 12 3.493a59.903 59.903 0 0 1 10.399 5.84c-.896.248-1.783.52-2.658.814m-15.482 0A50.717 50.717 0 0 1 12 13.489a50.702 50.702 0 0 1 7.74-3.342" />
                        </svg>
                    </div>
                </div>

                {{-- Nom, CIN, infos rapides --}}
                <div class="min-w-0">
                    <p class="text-[10px] font-bold uppercase tracking-[0.25em] text-primary-300/80">Dossier Académique</p>
                    <h1 class="mt-1.5 text-2xl sm:text-3xl lg:text-4xl font-black leading-tight text-white drop-shadow-sm">
                        {{ $etudiant->prenom }} {{ $etudiant->nom }}
                    </h1>
                    <div class="mt-3 flex flex-wrap items-center gap-x-3 gap-y-1.5">
                        <span class="inline-flex items-center gap-1.5 rounded-lg bg-white/10 backdrop-blur-sm px-2.5 py-1 text-xs font-bold text-white/90 ring-1 ring-white/10">
                            <svg class="h-3 w-3 text-primary-300" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15 9h3.75M15 12h3.75M15 15h3.75M4.5 19.5h15a2.25 2.25 0 0 0 2.25-2.25V6.75A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25v10.5A2.25 2.25 0 0 0 4.5 19.5Zm6-10.125a1.875 1.875 0 1 1-3.75 0 1.875 1.875 0 0 1 3.75 0Zm1.294 6.336a6.721 6.721 0 0 1-3.17.789 6.721 6.721 0 0 1-3.168-.789 3.376 3.376 0 0 1 6.338 0Z" /></svg>
                            {{ $etudiant->cin }}
                        </span>
                        <span class="inline-flex items-center gap-1.5 rounded-lg bg-white/10 backdrop-blur-sm px-2.5 py-1 text-xs font-semibold text-white/80 ring-1 ring-white/10">
                            {{ $etudiant->sexe === 'M' ? '♂ Masculin' : '♀ Féminin' }}
                        </span>
                        @if($etudiant->date_naissance)
                            <span class="inline-flex items-center gap-1.5 rounded-lg bg-white/10 backdrop-blur-sm px-2.5 py-1 text-xs font-semibold text-white/80 ring-1 ring-white/10">
                                <svg class="h-3 w-3 text-primary-300" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 0 1 2.25-2.25h13.5A2.25 2.25 0 0 1 21 7.5v11.25m-18 0A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75m-18 0v-7.5A2.25 2.25 0 0 1 5.25 9h13.5A2.25 2.25 0 0 1 21 11.25v7.5" /></svg>
                                {{ $etudiant->date_naissance->translatedFormat('d/m/Y') }}
                            </span>
                        @endif
                    </div>
                </div>
            </div>

            {{-- Référence + Tampon statut --}}
            <div class="flex flex-col items-start gap-4 lg:items-end animate-fade-up-d1">
                <div class="text-left lg:text-right">
                    <p class="text-[10px] font-bold uppercase tracking-[0.22em] text-primary-300/70">N° Inscription</p>
                    <p class="mt-0.5 font-mono text-lg font-black tracking-wider text-white">{{ $etudiant->num_inscription }}</p>
                    <p class="mt-1 text-[10px] text-primary-300/60">Déposé le {{ $etudiant->created_at->translatedFormat('d/m/Y à H:i') }}</p>
                    <a href="{{ route('etudiant.recepisse') }}" target="_blank" rel="noopener"
                       class="mt-2 inline-flex items-center gap-1.5 rounded-lg bg-white/10 px-3 py-1.5 text-[11px] font-bold text-white ring-1 ring-white/20 hover:bg-white/20 transition">
                        <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z" /></svg>
                        Récépissé de dépôt (PDF)
                    </a>
                </div>

                {{-- Tampon statut avec glow --}}
                @if($etudiant->statut === 'valide')
                    <div class="profil-v2-stamp profil-v2-stamp--success">
                        <span class="profil-v2-stamp-dot bg-emerald-400"></span>
                        Validé
                    </div>
                @elseif($etudiant->statut === 'en_attente')
                    <div class="profil-v2-stamp profil-v2-stamp--warning">
                        <span class="profil-v2-stamp-dot bg-amber-400 animate-pulse-subtle"></span>
                        En attente
                    </div>
                @else
                    <div class="profil-v2-stamp profil-v2-stamp--danger">
                        <span class="profil-v2-stamp-dot bg-rose-400"></span>
                        Rejeté
                    </div>
                @endif
            </div>
        </div>

        {{-- Bandeau d'affectation glassy superposé --}}
        <div class="relative z-10 mt-8 grid grid-cols-1 gap-px rounded-xl overflow-hidden ring-1 ring-white/10 sm:grid-cols-3 animate-fade-up-d2">
            <div class="bg-white/[0.07] backdrop-blur-md px-5 py-3.5">
                <p class="text-[9px] font-bold uppercase tracking-[0.2em] text-primary-300/60">Faculté</p>
                <p class="mt-0.5 truncate text-sm font-bold text-white">{{ $etudiant->faculte->nom }}</p>
            </div>
            <div class="bg-white/[0.07] backdrop-blur-md px-5 py-3.5">
                <p class="text-[9px] font-bold uppercase tracking-[0.2em] text-primary-300/60">Niveau d'études</p>
                <p class="mt-0.5 text-sm font-bold text-white">{{ $etudiant->niveau }}</p>
            </div>
            <div class="bg-white/[0.07] backdrop-blur-md px-5 py-3.5">
                <p class="text-[9px] font-bold uppercase tracking-[0.2em] text-primary-300/60">Baccalauréat</p>
                <p class="mt-0.5 text-sm font-bold text-white">Session {{ $etudiant->annee_bac }}{{ $etudiant->mention_bac ? ' — ' . $etudiant->mention_bac : '' }}</p>
            </div>
        </div>
    </div>

    {{-- ═══════════ AVIS OFFICIEL ═══════════ --}}
    @if($etudiant->statut === 'en_attente')
        <div class="profil-v2-alert profil-v2-alert--warning animate-fade-up-d2">
            <div class="flex items-center gap-2.5">
                <span class="flex h-8 w-8 flex-shrink-0 items-center justify-center rounded-xl bg-amber-100 text-amber-600">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" /></svg>
                </span>
                <div>
                    <p class="text-xs font-black uppercase tracking-wider text-amber-800">Avis de la scolarité</p>
                    <p class="mt-0.5 text-sm text-amber-700">Dossier en cours d'examen. Vous serez notifié dans votre espace dès l'évolution du statut.</p>
                </div>
            </div>
        </div>
    @elseif($etudiant->statut === 'valide')
        <div class="profil-v2-alert profil-v2-alert--success animate-fade-up-d2">
            <div class="flex items-center gap-2.5">
                <span class="flex h-8 w-8 flex-shrink-0 items-center justify-center rounded-xl bg-emerald-100 text-emerald-600">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" /></svg>
                </span>
                <div>
                    <p class="text-xs font-black uppercase tracking-wider text-emerald-800">Admission validée</p>
                    <p class="mt-0.5 text-sm text-emerald-700">Présentez votre récépissé au service de la scolarité pour le retrait de la carte d'étudiant.</p>
                </div>
            </div>
        </div>
    @else
        <div class="profil-v2-alert profil-v2-alert--danger animate-fade-up-d2">
            <div class="flex items-center gap-2.5">
                <span class="flex h-8 w-8 flex-shrink-0 items-center justify-center rounded-xl bg-rose-100 text-rose-600">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" /></svg>
                </span>
                <div>
                    <p class="text-xs font-black uppercase tracking-wider text-rose-800">Dossier rejeté</p>
                    <p class="mt-0.5 text-sm text-rose-700">Contactez scolarite@universite.ac.ma en précisant votre numéro d'inscription pour connaître le motif et les recours.</p>
                </div>
            </div>
        </div>
    @endif

    {{-- ═══════════ BENTO GRID — sections données ═══════════ --}}
    <div class="mt-6 grid grid-cols-1 gap-5 lg:grid-cols-2">

        {{-- Section I : État civil --}}
        <section class="profil-v2-card group animate-fade-up-d2">
            <header class="profil-v2-card-header">
                <div class="flex items-center gap-3">
                    <span class="profil-v2-card-icon bg-primary-50 text-primary-600 group-hover:bg-primary-100">
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0ZM4.501 20.118a7.5 7.5 0 0 1 14.998 0A17.933 17.933 0 0 1 12 21.75c-2.676 0-5.216-.584-7.499-1.632Z" /></svg>
                    </span>
                    <div>
                        <h3 class="text-sm font-black text-slate-900">État civil</h3>
                        <p class="text-[10px] text-slate-400">Géré par la scolarité</p>
                    </div>
                </div>
                <span class="profil-v2-badge-locked">
                    <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 1 0-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 0 0 2.25-2.25v-6.75a2.25 2.25 0 0 0-2.25-2.25H6.75a2.25 2.25 0 0 0-2.25 2.25v6.75a2.25 2.25 0 0 0 2.25 2.25Z" /></svg>
                </span>
            </header>
            <dl class="profil-v2-dl">
                <div class="profil-v2-dl-row">
                    <dt>Numéro de CIN</dt>
                    <dd class="font-mono">{{ $etudiant->cin }}</dd>
                </div>
                <div class="profil-v2-dl-row">
                    <dt>Nom de famille</dt>
                    <dd>{{ $etudiant->nom }}</dd>
                </div>
                <div class="profil-v2-dl-row">
                    <dt>Prénom</dt>
                    <dd>{{ $etudiant->prenom }}</dd>
                </div>
                <div class="profil-v2-dl-row">
                    <dt>Sexe</dt>
                    <dd>{{ $etudiant->sexe === 'M' ? 'Masculin' : 'Féminin' }}</dd>
                </div>
                <div class="profil-v2-dl-row">
                    <dt>Date de naissance</dt>
                    <dd>{{ $etudiant->date_naissance?->translatedFormat('d/m/Y') ?? '—' }}</dd>
                </div>
            </dl>
        </section>

        {{-- Section II : Parcours universitaire --}}
        <section class="profil-v2-card group animate-fade-up-d3">
            <header class="profil-v2-card-header">
                <div class="flex items-center gap-3">
                    <span class="profil-v2-card-icon bg-accent-500/10 text-accent-600 group-hover:bg-accent-500/20">
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M4.26 10.147a60.438 60.438 0 0 0-.491 6.347A48.62 48.62 0 0 1 12 20.904a48.62 48.62 0 0 1 8.232-4.41 60.46 60.46 0 0 0-.491-6.347m-15.482 0a50.636 50.636 0 0 0-2.658-.813A59.906 59.906 0 0 1 12 3.493a59.903 59.903 0 0 1 10.399 5.84c-.896.248-1.783.52-2.658.814m-15.482 0A50.717 50.717 0 0 1 12 13.489a50.702 50.702 0 0 1 7.74-3.342" /></svg>
                    </span>
                    <div>
                        <h3 class="text-sm font-black text-slate-900">Parcours universitaire</h3>
                        <p class="text-[10px] text-slate-400">Affectation académique</p>
                    </div>
                </div>
                <span class="profil-v2-badge-locked">
                    <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 1 0-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 0 0 2.25-2.25v-6.75a2.25 2.25 0 0 0-2.25-2.25H6.75a2.25 2.25 0 0 0-2.25 2.25v6.75a2.25 2.25 0 0 0 2.25 2.25Z" /></svg>
                </span>
            </header>
            <dl class="profil-v2-dl">
                <div class="profil-v2-dl-row">
                    <dt>Faculté</dt>
                    <dd class="text-right">{{ $etudiant->faculte->nom }}</dd>
                </div>
                <div class="profil-v2-dl-row">
                    <dt>Niveau d'études</dt>
                    <dd>{{ $etudiant->niveau }}</dd>
                </div>
                <div class="profil-v2-dl-row">
                    <dt>Année académique</dt>
                    <dd>{{ $etudiant->annee_academique }}</dd>
                </div>
                <div class="profil-v2-dl-row">
                    <dt>Baccalauréat</dt>
                    <dd class="text-right">Session {{ $etudiant->annee_bac }}</dd>
                </div>
                <div class="profil-v2-dl-row">
                    <dt>Mention obtenue</dt>
                    <dd>{{ $etudiant->mention_bac ?? '—' }}</dd>
                </div>
            </dl>
        </section>

        {{-- Section III : Coordonnées personnelles --}}
        <section class="profil-v2-card group animate-fade-up-d2">
            <header class="profil-v2-card-header">
                <div class="flex items-center gap-3">
                    <span class="profil-v2-card-icon bg-sky-50 text-sky-600 group-hover:bg-sky-100">
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M21.75 6.75v10.5a2.25 2.25 0 0 1-2.25 2.25h-15a2.25 2.25 0 0 1-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25m19.5 0v.243a2.25 2.25 0 0 1-1.07 1.916l-7.5 4.615a2.25 2.25 0 0 1-2.36 0L3.32 8.91a2.25 2.25 0 0 1-1.07-1.916V6.75" /></svg>
                    </span>
                    <div>
                        <h3 class="text-sm font-black text-slate-900">Coordonnées personnelles</h3>
                        <p class="text-[10px] text-slate-400">Modifiable par l'étudiant</p>
                    </div>
                </div>
                <a href="{{ route('etudiant.profil.edit') }}"
                   class="profil-v2-badge-edit btn-shine">
                    <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke-width="2.2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931Z" /></svg>
                    Modifier
                </a>
            </header>
            <dl class="profil-v2-dl">
                <div class="profil-v2-dl-row">
                    <dt>Adresse email</dt>
                    <dd class="text-right break-all">{{ $etudiant->email }}</dd>
                </div>
                <div class="profil-v2-dl-row">
                    <dt>Téléphone</dt>
                    <dd>{{ $etudiant->telephone ?? '—' }}</dd>
                </div>
                <div class="profil-v2-dl-row">
                    <dt>Adresse complète</dt>
                    <dd class="text-right">{{ $etudiant->adresse }}</dd>
                </div>
                <div class="profil-v2-dl-row">
                    <dt>Ville</dt>
                    <dd>{{ $etudiant->ville }}</dd>
                </div>
                <div class="profil-v2-dl-row">
                    <dt>Code postal</dt>
                    <dd class="font-mono">{{ $etudiant->code_postal }}</dd>
                </div>
            </dl>
            <p class="mt-4 flex items-start gap-1.5 rounded-lg bg-slate-50 px-3 py-2 text-[11px] leading-relaxed text-slate-400">
                <svg class="mt-0.5 h-3 w-3 flex-shrink-0 text-primary-400" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m11.25 11.25.041-.02a.75.75 0 0 1 1.063.852l-.708 2.836a.75.75 0 0 0 1.063.853l.041-.021M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9-3.75h.008v.008H12V8.25Z" /></svg>
                Ces données restent modifiables à tout moment afin que la scolarité puisse vous joindre pendant l'instruction de votre dossier.
            </p>
        </section>

        {{-- Section IV : Sécurité du compte --}}
        <section class="profil-v2-card group animate-fade-up-d3">
            <header class="profil-v2-card-header">
                <div class="flex items-center gap-3">
                    <span class="profil-v2-card-icon bg-rose-50 text-rose-500 group-hover:bg-rose-100">
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75m-3-7.036A11.959 11.959 0 0 1 3.598 6 11.99 11.99 0 0 0 3 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285Z" /></svg>
                    </span>
                    <div>
                        <h3 class="text-sm font-black text-slate-900">Sécurité du compte</h3>
                        <p class="text-[10px] text-slate-400">Mot de passe & accès</p>
                    </div>
                </div>
            </header>

            <form method="POST" action="{{ route('etudiant.profil.password') }}" class="space-y-4">
                @csrf
                @method('PATCH')

                <div>
                    <label for="current_password" class="profil-v2-label">
                        Mot de passe actuel <span class="text-rose-500">*</span>
                    </label>
                    <div class="relative">
                        <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3.5 text-slate-400">
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 1 0-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 0 0 2.25-2.25v-6.75a2.25 2.25 0 0 0-2.25-2.25H6.75a2.25 2.25 0 0 0-2.25 2.25v6.75a2.25 2.25 0 0 0 2.25 2.25Z" /></svg>
                        </div>
                        <input id="current_password" name="current_password" type="password" required autocomplete="current-password"
                               placeholder="••••••••"
                               class="profil-v2-input pl-10 @error('current_password') profil-v2-input--error @enderror">
                    </div>
                    @error('current_password')
                        <p class="mt-1 text-xs text-rose-600">{{ $message }}</p>
                    @enderror
                </div>

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div>
                        <label for="new_password" class="profil-v2-label">
                            Nouveau mot de passe <span class="text-rose-500">*</span>
                        </label>
                        <div class="relative">
                            <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3.5 text-slate-400">
                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 1 0-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 0 0 2.25-2.25v-6.75a2.25 2.25 0 0 0-2.25-2.25H6.75a2.25 2.25 0 0 0-2.25 2.25v6.75a2.25 2.25 0 0 0 2.25 2.25Z" /></svg>
                            </div>
                            <input id="new_password" name="password" :type="showPassword ? 'text' : 'password'" type="password" required autocomplete="new-password"
                                   placeholder="••••••••"
                                   class="profil-v2-input pl-10 pr-10 @error('password') profil-v2-input--error @enderror">
                            <button type="button" @click="showPassword = !showPassword" aria-label="Afficher / masquer"
                                    class="absolute inset-y-0 right-0 flex items-center pr-3.5 text-slate-400 hover:text-primary-600 transition-smooth">
                                <svg x-show="!showPassword" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.964-7.178Z" />
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
                                </svg>
                                <svg x-show="showPassword" x-cloak class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M3.98 8.223A10.477 10.477 0 0 0 1.934 12c1.292 4.338 5.31 7.5 10.066 7.5.993 0 1.953-.138 2.863-.395M6.228 6.228A10.451 10.451 0 0 1 12 4.5c4.756 0 8.773 3.162 10.065 7.498a10.523 10.523 0 0 1-4.293 5.774M6.228 6.228 3 3m3.228 3.228 3.65 3.65m7.894 7.894L21 21m-3.228-3.228-3.65-3.65" />
                                </svg>
                            </button>
                        </div>
                        @error('password')
                            <p class="mt-1 text-xs text-rose-600">{{ $message }}</p>
                        @else
                            <p class="mt-1 text-[11px] text-slate-400">10 caractères min., une lettre et un chiffre.</p>
                        @enderror
                    </div>

                    <div>
                        <label for="password_confirmation" class="profil-v2-label">
                            Confirmer <span class="text-rose-500">*</span>
                        </label>
                        <div class="relative">
                            <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3.5 text-slate-400">
                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 1 0-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 0 0 2.25-2.25v-6.75a2.25 2.25 0 0 0-2.25-2.25H6.75a2.25 2.25 0 0 0-2.25 2.25v6.75a2.25 2.25 0 0 0 2.25 2.25Z" /></svg>
                            </div>
                            <input id="password_confirmation" name="password_confirmation" :type="showPassword ? 'text' : 'password'" type="password" required autocomplete="new-password"
                                   placeholder="••••••••"
                                   class="profil-v2-input pl-10 @error('password') profil-v2-input--error @enderror">
                        </div>
                    </div>
                </div>

                <button type="submit" class="profil-v2-btn-primary btn-shine">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75m-3-7.036A11.959 11.959 0 0 1 3.598 6 11.99 11.99 0 0 0 3 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285Z" /></svg>
                    Changer le mot de passe
                </button>
            </form>
        </section>
    </div>

    {{-- ═══════════ BARRE D'ACTIONS PREMIUM ═══════════ --}}
    <div class="mt-6 animate-fade-up-d3">
        <div class="flex flex-col items-stretch gap-3 rounded-2xl border border-slate-200 bg-gradient-to-r from-slate-50 via-white to-slate-50 p-5 shadow-sm sm:flex-row sm:items-center sm:justify-between">
            <p class="text-[11px] leading-relaxed text-slate-400">
                Document généré le {{ now()->translatedFormat('d/m/Y à H:i') }} — Réf. <strong class="font-mono text-slate-500">{{ $etudiant->num_inscription }}</strong><br>
                Les données d'état civil et d'affectation sont gérées exclusivement par le Service de la Scolarité.
            </p>

            <div class="flex flex-col gap-2.5 sm:flex-row sm:items-center">
                <a href="{{ route('etudiant.archives.show') }}"
                   class="profil-v2-btn-outline group">
                    <svg class="h-4 w-4 text-slate-400 group-hover:text-primary-600 transition-smooth" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 21v-8.25M15.75 21v-8.25M8.25 21v-8.25M3 9l9-6 9 6m-1.5 12V10.332A48.36 48.36 0 0 0 12 9.75c-2.551 0-5.056.2-7.5.582V21M3 21h18M12 6.75h.008v.008H12V6.75Z" /></svg>
                    Mon dossier académique
                </a>

                <a href="{{ route('etudiant.profil.edit') }}"
                   class="profil-v2-btn-primary btn-shine">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2.2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931Z" /></svg>
                    Modifier mes coordonnées
                </a>

                <form method="POST" action="{{ route('etudiant.connexion.logout') }}">
                    @csrf
                    <button type="submit"
                            class="profil-v2-btn-danger">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2.2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 9V5.25A2.25 2.25 0 0 0 13.5 3h-6a2.25 2.25 0 0 0-2.25 2.25v13.5A2.25 2.25 0 0 0 7.5 21h6a2.25 2.25 0 0 0 2.25-2.25V15m3 0 3-3m0 0-3-3m3 3H9" /></svg>
                        Déconnexion
                    </button>
                </form>
            </div>
        </div>
    </div>

</div>
@endsection
