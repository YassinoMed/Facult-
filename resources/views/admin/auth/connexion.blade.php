@extends('layouts.app')

@section('auth_layout', true)
@section('title', 'Connexion — Espace Scolarité')
@section('meta_description', 'Accès sécurisé réservé au personnel administratif, enseignants et gestionnaires de la scolarité universitaire.')

@section('content')
<div class="relative min-h-screen flex flex-col bg-slate-50 dark:bg-slate-950 text-slate-800 dark:text-slate-100 selection:bg-indigo-500 selection:text-white overflow-x-hidden font-sans"
     x-data="{ 
         theme: localStorage.getItem('theme') || 'system',
         basculerTheme() {
             var suivant = this.theme === 'dark' ? 'light' : 'dark';
             this.theme = suivant;
             localStorage.setItem('theme', suivant);
             appliquerTheme(suivant);
         }
     }">

    {{-- ── Arrière-plan ambiant coloré avec orbes lumineux vivants ── --}}
    <div class="fixed inset-0 pointer-events-none z-0 overflow-hidden">
        <div class="absolute -top-32 -left-32 w-[550px] h-[550px] rounded-full bg-blue-600/15 dark:bg-blue-600/20 blur-[120px] animate-float"></div>
        <div class="absolute top-1/4 -right-32 w-[500px] h-[500px] rounded-full bg-purple-600/15 dark:bg-purple-600/20 blur-[120px] animate-pulse-subtle"></div>
        <div class="absolute -bottom-32 left-1/3 w-[600px] h-[600px] rounded-full bg-teal-500/15 dark:bg-teal-500/15 blur-[130px]"></div>
        <div class="absolute inset-0 bg-grid-pattern opacity-40 dark:opacity-20"></div>
    </div>

    {{-- ── Barre supérieure unifiée & colorée ── --}}
    <header class="relative z-20 border-b border-slate-200/80 dark:border-slate-800/80 bg-white/85 dark:bg-slate-950/80 backdrop-blur-xl px-4 sm:px-8 py-3.5 shadow-xs">
        <div class="mx-auto max-w-7xl flex items-center justify-between gap-4">
            
            {{-- Logo UniRegistre avec gradient coloré --}}
            <a href="{{ route('inscription.create') }}" class="group flex items-center gap-3 focus:outline-none focus:ring-2 focus:ring-blue-500 rounded-xl px-1.5 py-1">
                <span class="flex h-10 w-10 flex-shrink-0 items-center justify-center rounded-xl bg-gradient-to-br from-blue-600 via-indigo-600 to-purple-600 shadow-md shadow-blue-500/25 group-hover:scale-105 transition-transform duration-200">
                    <svg class="h-5 w-5 text-white" fill="none" viewBox="0 0 24 24" stroke-width="2.2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 1 0-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 0 0 2.25-2.25v-6.75a2.25 2.25 0 0 0-2.25-2.25H6.75a2.25 2.25 0 0 0-2.25 2.25v6.75a2.25 2.25 0 0 0 2.25 2.25Z" />
                    </svg>
                </span>
                <span class="flex flex-col">
                    <span class="text-base font-black tracking-tight text-slate-900 dark:text-white group-hover:text-blue-600 dark:group-hover:text-blue-400 transition-colors">UniRegistre</span>
                    <span class="text-[10px] font-bold uppercase tracking-wider text-blue-600 dark:text-blue-400">Back-Office Scolarité</span>
                </span>
            </a>

            {{-- Segmented control coloré : Espace Étudiant ↔ Espace Scolarité (actif) --}}
            <nav class="hidden md:flex items-center p-1 rounded-2xl bg-slate-100 dark:bg-slate-900 border border-slate-200 dark:border-slate-800" aria-label="Portails de connexion">
                <a href="{{ route('etudiant.connexion.show') }}"
                   class="flex items-center gap-2 px-4 py-1.5 rounded-xl text-xs font-semibold text-slate-600 dark:text-slate-300 hover:text-blue-600 dark:hover:text-white hover:bg-white dark:hover:bg-slate-800 transition-all">
                    <svg class="h-4 w-4 text-slate-400 group-hover:text-blue-500" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0ZM4.501 20.118a7.5 7.5 0 0 1 14.998 0A17.933 17.933 0 0 1 12 21.75c-2.676 0-5.216-.584-7.499-1.632Z" />
                    </svg>
                    Espace Étudiant
                </a>
                <span class="flex items-center gap-2 px-4 py-1.5 rounded-xl text-xs font-bold text-white bg-gradient-to-r from-blue-600 via-indigo-600 to-purple-600 shadow-sm shadow-blue-500/25" aria-current="page">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 1 0-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 0 0 2.25-2.25v-6.75a2.25 2.25 0 0 0-2.25-2.25H6.75a2.25 2.25 0 0 0-2.25 2.25v6.75a2.25 2.25 0 0 0 2.25 2.25Z" />
                    </svg>
                    Espace Scolarité
                </span>
            </nav>

            {{-- Actions : retour portail & basculeur de thème --}}
            <div class="flex items-center gap-2 sm:gap-3">
                <a href="{{ route('inscription.create') }}"
                   class="hidden sm:inline-flex items-center gap-2 px-3.5 py-1.5 rounded-xl text-xs font-bold text-blue-700 dark:text-blue-300 bg-blue-50 dark:bg-blue-950/60 hover:bg-blue-100 dark:hover:bg-blue-900/60 border border-blue-200 dark:border-blue-800/80 transition-all focus:outline-none focus:ring-2 focus:ring-blue-500">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5 3 12m0 0 7.5-7.5M3 12h18" />
                    </svg>
                    Portail Inscription
                </a>

                {{-- Sélecteur de thème --}}
                <button type="button" @click="basculerTheme()"
                        aria-label="Basculer le thème d'affichage"
                        class="rounded-xl p-2 text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800 border border-slate-200 dark:border-slate-800 transition-all focus:outline-none focus:ring-2 focus:ring-blue-500 min-h-[40px] min-w-[40px] flex items-center justify-center">
                    <svg x-show="theme === 'light'" class="h-4 w-4 text-amber-500" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 3v2.25m6.364.386-1.591 1.591M21 12h-2.25m-.386 6.364-1.591-1.591M12 18.75V21m-4.773-4.227-1.591 1.591M5.25 12H3m4.227-4.773L5.636 5.636M15.75 12a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0Z" />
                    </svg>
                    <svg x-show="theme !== 'light'" x-cloak class="h-4 w-4 text-blue-400" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M21.752 15.002A9.72 9.72 0 0 1 18 15.75c-5.385 0-9.75-4.365-9.75-9.75 0-1.33.266-2.597.748-3.752A9.753 9.753 0 0 0 12 21.75a9.753 9.753 0 0 0 9.752-6.748Z" />
                    </svg>
                </button>
            </div>
        </div>
    </header>

    {{-- ── Corps principal : Architecture Split-Screen avec couleurs vives & contraste élevé ── --}}
    <div class="relative z-10 flex-1 grid lg:grid-cols-12 max-w-7xl mx-auto w-full px-4 sm:px-6 lg:px-8 py-8 lg:py-12 gap-8 lg:gap-12 items-center">
        
        {{-- ── Colonne Gauche : Vitrine institutionnelle riche en couleurs ── --}}
        <div class="lg:col-span-6 xl:col-span-6 rounded-3xl bg-gradient-to-br from-slate-950 via-slate-900 to-indigo-950 text-white p-8 sm:p-10 shadow-2xl shadow-indigo-950/30 border border-indigo-700/30 relative overflow-hidden animate-fade-up">
            
            {{-- Orbes d'ambiance internes --}}
            <div class="absolute -top-20 -right-20 w-64 h-64 rounded-full bg-blue-500/25 blur-3xl pointer-events-none"></div>
            <div class="absolute -bottom-20 -left-20 w-64 h-64 rounded-full bg-purple-500/20 blur-3xl pointer-events-none"></div>

            <div class="relative z-10 space-y-7">
                {{-- Badge Accès Restreint coloré --}}
                <div class="inline-flex items-center gap-2.5 px-3.5 py-1.5 rounded-full bg-blue-500/20 border border-blue-400/30 backdrop-blur-md">
                    <span class="flex h-2.5 w-2.5 rounded-full bg-emerald-400 animate-pulse"></span>
                    <span class="text-xs font-bold text-blue-200 uppercase tracking-wider">Accès Restreint au Personnel Habilité</span>
                </div>

                {{-- Titre & Accroche à fort impact visuel --}}
                <div class="space-y-3">
                    <h2 class="text-3xl sm:text-4xl xl:text-5xl font-black tracking-tight leading-tight">
                        Pilotage académique et <span class="text-transparent bg-clip-text bg-gradient-to-r from-sky-300 via-blue-200 to-purple-300">registre certifié</span>.
                    </h2>
                    <p class="text-sm sm:text-base text-slate-200 leading-relaxed max-w-xl">
                        Back-office institutionnel : gestion des inscriptions pédagogiques, délibérations de jurys, emplois du temps et dossiers académiques 360°.
                    </p>
                </div>

                {{-- 3 Cartes de fonctionnalités colorées (Bleu Ciel, Jaune Ambré, Vert Émeraude) --}}
                <div class="grid sm:grid-cols-3 gap-3.5 pt-2">
                    {{-- 1. Bleu Ciel / Cyan — Registre 360° --}}
                    <div class="p-4 rounded-2xl bg-white/10 hover:bg-white/15 border border-sky-400/30 backdrop-blur-md transition-smooth space-y-2">
                        <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-sky-500/20 text-sky-300 border border-sky-400/40">
                            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="2.2" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 0 0 2.625.372 9.337 9.337 0 0 0 4.121-.952 4.125 4.125 0 0 0-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 0 1 8.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0 1 11.964-3.07M12 6.375a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0Zm8.25 2.25a2.625 2.625 0 1 1-5.25 0 2.625 2.625 0 0 1 5.25 0Z" />
                            </svg>
                        </div>
                        <p class="text-xs font-bold text-white">Registre 360°</p>
                        <p class="text-[11px] text-slate-200 leading-snug">Dossiers étudiants, GED sécurisée et historique LMD.</p>
                    </div>

                    {{-- 2. Jaune Ambré — Délibérations --}}
                    <div class="p-4 rounded-2xl bg-white/10 hover:bg-white/15 border border-amber-400/30 backdrop-blur-md transition-smooth space-y-2">
                        <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-amber-500/20 text-amber-300 border border-amber-400/40">
                            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="2.2" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                            </svg>
                        </div>
                        <p class="text-xs font-bold text-white">Délibérations</p>
                        <p class="text-[11px] text-slate-200 leading-snug">Sessions d'examens, PV de jurys et verrouillage officiel.</p>
                    </div>

                    {{-- 3. Vert Émeraude — Traçabilité & Audit --}}
                    <div class="p-4 rounded-2xl bg-white/10 hover:bg-white/15 border border-emerald-400/30 backdrop-blur-md transition-smooth space-y-2">
                        <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-emerald-500/20 text-emerald-300 border border-emerald-400/40">
                            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="2.2" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75m-3-7.036A11.959 11.959 0 0 1 3.598 6 11.99 11.99 0 0 0 3 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285Z" />
                            </svg>
                        </div>
                        <p class="text-xs font-bold text-white">Traçabilité & Audit</p>
                        <p class="text-[11px] text-slate-200 leading-snug">Journalisation complète des actions (§24-25) et conformité.</p>
                    </div>
                </div>

                {{-- Ligne de sécurité colorée --}}
                <div class="pt-4 border-t border-white/15 flex items-center gap-2.5 text-xs text-blue-200">
                    <span class="flex h-2 w-2 rounded-full bg-emerald-400"></span>
                    <span>Cloisonnement par faculté • Protection stricte par rôles (RBAC)</span>
                </div>
            </div>
        </div>

        {{-- ── Colonne Droite : Formulaire ultra-lisible avec couleurs et contrastes vifs ── --}}
        <div class="lg:col-span-6 xl:col-span-6 flex justify-center animate-fade-up-d1">
            <div class="w-full max-w-lg rounded-3xl bg-white dark:bg-slate-900 border-2 border-slate-200/90 dark:border-slate-800 shadow-2xl shadow-indigo-950/10 dark:shadow-black/50 p-6 sm:p-10 relative overflow-hidden"
                 x-data="{ showPassword: false, submitting: false }">

                {{-- Ligne d'accent lumineuse en haut de la carte --}}
                <div class="absolute top-0 inset-x-0 h-1.5 bg-gradient-to-r from-blue-600 via-indigo-600 to-purple-600"></div>

                {{-- En-tête de la carte avec icône colorée --}}
                <div class="flex items-start gap-4 pb-6 border-b border-slate-200 dark:border-slate-800">
                    <div class="flex h-14 w-14 flex-shrink-0 items-center justify-center rounded-2xl bg-gradient-to-br from-blue-600 via-indigo-600 to-purple-600 shadow-lg shadow-blue-500/30 text-white">
                        <svg class="h-7 w-7" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 1 0-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 0 0 2.25-2.25v-6.75a2.25 2.25 0 0 0-2.25-2.25H6.75a2.25 2.25 0 0 0-2.25 2.25v6.75a2.25 2.25 0 0 0 2.25 2.25Z" />
                        </svg>
                    </div>
                    <div>
                        <span class="inline-block rounded-full bg-blue-50 dark:bg-blue-950/60 px-3 py-0.5 text-[11px] font-bold uppercase tracking-wider text-blue-700 dark:text-blue-300 border border-blue-200 dark:border-blue-800 mb-1">
                            Accès Scolarité & Administration
                        </span>
                        <h1 class="text-2xl sm:text-3xl font-black tracking-tight text-slate-900 dark:text-white">Espace Scolarité</h1>
                        <p class="text-xs sm:text-sm text-slate-600 dark:text-slate-400 mt-0.5">Authentification réservée au personnel habilité.</p>
                    </div>
                </div>

                {{-- Formulaire --}}
                <form method="POST" action="{{ route('admin.connexion.login') }}" @submit="submitting = true" class="mt-6 space-y-5">
                    @csrf

                    {{-- Identifiant (Email professionnel) --}}
                    <div class="space-y-1.5">
                        <label for="email" class="block text-xs font-extrabold text-slate-800 dark:text-slate-200 uppercase tracking-wider">
                            Email professionnel <span class="text-rose-500">*</span>
                        </label>
                        <div class="relative">
                            <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3.5 text-blue-600 dark:text-blue-400">
                                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M21.75 6.75v10.5a2.25 2.25 0 0 1-2.25 2.25h-15a2.25 2.25 0 0 1-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25m19.5 0v.243a2.25 2.25 0 0 1-1.07 1.916l-7.5 4.615a2.25 2.25 0 0 1-2.36 0L3.32 8.91a2.25 2.25 0 0 1-1.07-1.916V6.75" />
                                </svg>
                            </div>
                            <input id="email" name="email" type="email" required autofocus autocomplete="username"
                                   value="{{ old('email') }}"
                                   placeholder="prenom.nom@universite.ac.ma"
                                   class="w-full rounded-2xl border-2 border-slate-300 dark:border-slate-700 bg-slate-50/90 dark:bg-slate-800/90 px-4 py-3 pl-11 text-sm font-medium text-slate-900 dark:text-white placeholder-slate-400 transition-smooth focus:border-blue-600 focus:bg-white dark:focus:bg-slate-800 focus:ring-4 focus:ring-blue-500/15 focus:outline-none @error('email') border-rose-500 bg-rose-50/60 dark:bg-rose-950/30 text-rose-900 dark:text-rose-200 @enderror">
                        </div>
                        @error('email')
                            <p class="text-xs font-semibold text-rose-600 dark:text-rose-400 flex items-center gap-1.5 mt-1">
                                <svg class="h-4 w-4 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008Z" />
                                </svg>
                                <span>{{ $message }}</span>
                            </p>
                        @enderror
                    </div>

                    {{-- Mot de passe --}}
                    <div class="space-y-1.5">
                        <div class="flex items-center justify-between">
                            <label for="password" class="block text-xs font-extrabold text-slate-800 dark:text-slate-200 uppercase tracking-wider">
                                Mot de passe <span class="text-rose-500">*</span>
                            </label>
                            <a href="{{ route('admin.mot-de-passe.oublie') }}" class="text-[11px] font-bold text-blue-600 dark:text-blue-400 hover:text-blue-700 dark:hover:text-blue-300 hover:underline transition-colors">
                                Mot de passe oublié ?
                            </a>
                        </div>
                        <div class="relative">
                            <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3.5 text-blue-600 dark:text-blue-400">
                                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 1 0-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 0 0 2.25-2.25v-6.75a2.25 2.25 0 0 0-2.25-2.25H6.75a2.25 2.25 0 0 0-2.25 2.25v6.75a2.25 2.25 0 0 0 2.25 2.25Z" />
                                </svg>
                            </div>
                            <input id="password" name="password" required autocomplete="current-password"
                                   :type="showPassword ? 'text' : 'password'"
                                   placeholder="••••••••"
                                   class="w-full rounded-2xl border-2 border-slate-300 dark:border-slate-700 bg-slate-50/90 dark:bg-slate-800/90 px-4 py-3 pl-11 pr-11 text-sm font-medium text-slate-900 dark:text-white placeholder-slate-400 transition-smooth focus:border-blue-600 focus:bg-white dark:focus:bg-slate-800 focus:ring-4 focus:ring-blue-500/15 focus:outline-none @error('password') border-rose-500 bg-rose-50/60 dark:bg-rose-950/30 text-rose-900 dark:text-rose-200 @enderror">
                            
                            {{-- Bouton afficher/masquer le mot de passe --}}
                            <button type="button" @click="showPassword = !showPassword"
                                    :aria-label="showPassword ? 'Masquer le mot de passe' : 'Afficher le mot de passe'"
                                    class="absolute inset-y-0 right-0 flex items-center pr-3.5 text-slate-400 hover:text-blue-600 dark:hover:text-white transition-colors focus:outline-none">
                                <svg x-show="!showPassword" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.964-7.178Z" />
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
                                </svg>
                                <svg x-show="showPassword" x-cloak class="h-5 w-5 text-blue-600 dark:text-blue-400" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M3.98 8.223A10.477 10.477 0 0 0 1.934 12c1.292 4.338 5.31 7.5 10.066 7.5.993 0 1.953-.138 2.863-.395M6.228 6.228A10.451 10.451 0 0 1 12 4.5c4.756 0 8.773 3.162 10.065 7.498a10.523 10.523 0 0 1-4.293 5.774M6.228 6.228 3 3m3.228 3.228 3.65 3.65m7.894 7.894L21 21m-3.228-3.228-3.65-3.65" />
                                </svg>
                            </button>
                        </div>
                        @error('password')
                            <p class="text-xs font-semibold text-rose-600 dark:text-rose-400 flex items-center gap-1.5 mt-1">
                                <svg class="h-4 w-4 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008Z" />
                                </svg>
                                <span>{{ $message }}</span>
                            </p>
                        @enderror
                    </div>

                    {{-- Option Se souvenir de moi --}}
                    <div class="flex items-center justify-between">
                        <label class="flex items-center gap-2 cursor-pointer group">
                            <input type="checkbox" name="remember" value="1"
                                   class="h-4 w-4 rounded-md border-2 border-slate-300 dark:border-slate-700 text-blue-600 focus:ring-blue-500">
                            <span class="text-xs font-medium text-slate-700 dark:text-slate-300 group-hover:text-blue-600 dark:group-hover:text-blue-400 transition-colors">Rester connecté</span>
                        </label>
                    </div>

                    {{-- Bouton d'action principal CTA : gradient dynamique vibrant avec reflets --}}
                    <button type="submit" :disabled="submitting"
                            class="btn-shine relative w-full rounded-2xl bg-gradient-to-r from-blue-600 via-indigo-600 to-purple-600 hover:from-blue-500 hover:via-indigo-500 hover:to-purple-500 px-6 py-4 text-base font-black text-white shadow-xl shadow-blue-600/30 hover:shadow-2xl hover:shadow-blue-600/40 hover:-translate-y-0.5 transition-smooth focus:outline-none focus:ring-4 focus:ring-blue-500/30 disabled:opacity-60 disabled:cursor-not-allowed">
                        <span x-show="!submitting" class="flex items-center justify-center gap-2">
                            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 5.25a3 3 0 0 1 3 3m3 0a6 6 0 0 1-7.029 5.912c-.563-.097-1.159.263-1.343.818l-.5 1.402m-3.428-7.132A6 6 0 0 1 3.75 8.25a6 6 0 0 1 5.879-6 6 6 0 0 1 5.871 6c0 .318-.024.63-.07.937-.107.684-.81 1.09-1.497.882l-1.183-.357c-.5-.152-.985.211-1.185.683a2.25 2.25 0 0 1-1.544 1.344c-.243.061-.49.089-.738.089-.89 0-1.724-.27-2.437-.77a6.042 6.042 0 0 1-1.394-1.302 2.23 2.23 0 0 1 .666-3.383c.192-.12.382-.232.57-.347" />
                            </svg>
                            Accéder à la console scolarité
                        </span>
                        <span x-show="submitting" x-cloak class="flex items-center justify-center gap-2">
                            <svg class="animate-spin h-5 w-5 text-white" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                            </svg>
                            Vérification des identifiants...
                        </span>
                    </button>
                </form>

                {{-- Bloc d'assistance coloré & clair --}}
                <div class="mt-6 pt-5 border-t border-slate-200 dark:border-slate-800 space-y-3.5">
                    <div class="p-3.5 rounded-2xl bg-blue-50/80 dark:bg-blue-950/40 border border-blue-200/80 dark:border-blue-800/60 flex items-start gap-3">
                        <div class="p-1 rounded-lg bg-blue-600 text-white mt-0.5">
                            <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="m11.25 11.25.041-.02a.75.75 0 0 1 1.063.852l-.708 2.836a.75.75 0 0 0 1.063.853l.041-.021M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9-3.75h.008v.008H12V8.25Z" />
                            </svg>
                        </div>
                        <p class="text-xs text-slate-700 dark:text-blue-200 leading-relaxed">
                            Accès strictement réservé au personnel habilité. Toute tentative d'intrusion est journalisée (§24-25).
                        </p>
                    </div>

                    {{-- Lien vers l'Espace Étudiant --}}
                    <div class="flex flex-col sm:flex-row items-center justify-between gap-2 text-xs pt-1">
                        <span class="text-slate-600 dark:text-slate-400 font-medium">Vous êtes étudiant ?</span>
                        <a href="{{ route('etudiant.connexion.show') }}" class="font-black text-blue-600 dark:text-blue-400 hover:text-blue-700 dark:hover:text-blue-300 hover:underline">
                            Accéder à l'Espace Étudiant →
                        </a>
                    </div>
                </div>

                {{-- Note sécurité en bas de carte --}}
                <div class="mt-6 pt-4 border-t border-slate-100 dark:border-slate-800 flex items-center justify-center gap-2 text-[11px] text-slate-500 dark:text-slate-400">
                    <svg class="h-4 w-4 text-emerald-500" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                    </svg>
                    <span>Authentification sécurisée TLS 1.3 • Chiffrement SHA-256</span>
                </div>
            </div>
        </div>
    </div>

    {{-- ── Pied de page épuré ── --}}
    <footer class="relative z-10 border-t border-slate-200/80 dark:border-slate-800/80 bg-white/70 dark:bg-slate-950/80 backdrop-blur-md py-4 px-4 sm:px-8 text-center text-xs text-slate-600 dark:text-slate-400">
        <div class="mx-auto max-w-7xl flex flex-col sm:flex-row items-center justify-between gap-2">
            <p>© {{ date('Y') }} Université — Système Officiel UniRegistre. Tous droits réservés.</p>
            <div class="flex items-center gap-4">
                <a href="{{ route('etudiant.connexion.show') }}" class="text-blue-600 dark:text-blue-400 font-semibold hover:underline">Portail Étudiant</a>
                <span>•</span>
                <span class="text-slate-500">Conforme LMD</span>
            </div>
        </div>
    </footer>
</div>
@endsection
