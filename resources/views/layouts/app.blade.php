@php
    // Portée du mouvement : cinématique sur les parcours publics éditoriaux,
    // GSAP + Lenis dans l'espace étudiant (pointeur fin uniquement), GSAP
    // borné dans le back-office (sans Lenis ni parallaxe), sobre ailleurs.
    // - le profil étudiant garde son module `profil.js`, plus riche ;
    // - les PDF (parcours, récépissé, dossier) ne chargent aucun mouvement ;
    // - les écrans de connexion et de mot de passe admin restent sobres :
    //   aucune animation ne doit s'interposer dans un parcours de sécurité.
    $estEspaceEtudiant = session('etudiant_auth_id') !== null
        && request()->routeIs('etudiant.*')
        && ! request()->routeIs('etudiant.profil.show', 'etudiant.parcours.pdf', 'etudiant.recepisse', 'etudiant.dossier.pdf.*');

    $estAdmin = request()->is('admin', 'admin/*')
        && ! request()->is(
            'admin/connexion',
            'admin/connexion/*',
            'admin/mot-de-passe',
            'admin/mot-de-passe/*',
        );

    $motionScope = 'app';

    if ($estEspaceEtudiant) {
        $motionScope = 'etudiant';
    } elseif ($estAdmin) {
        $motionScope = 'admin';
    } elseif (request()->routeIs('accueil', 'inscription.*', 'documents.verifier', 'documents.verify')) {
        $motionScope = 'cinematic';
    }

    // Portail étudiant : la V2 est le thème par défaut de toutes les pages
    // étudiantes (hors écrans d'authentification plein écran). `?theme=v1`
    // reste un repli partageable, y compris sans JavaScript. La préférence
    // locale est appliquée avant peinture par le script de tête ci-dessous.
    $estPortailEtudiant = request()->routeIs('etudiant.*')
        && ! request()->routeIs('etudiant.connexion.*', 'etudiant.mot-de-passe.*');

    $etudiantThemeInitial = $estPortailEtudiant && request()->query('theme') === 'v1'
        ? 'v1'
        : 'v2';

    // Destination dynamique du logo selon l'état d'authentification et les permissions effectives (§LOT 1)
    $destinationLogo = \App\Support\NavigationDestination::resoudre(request());
@endphp
<!DOCTYPE html>
<html lang="fr" class="scroll-smooth" @if($estPortailEtudiant) data-etudiant-theme="{{ $etudiantThemeInitial }}" @endif>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
    <meta name="description" content="@yield('meta_description', 'UniRegistre — Plateforme d\'inscription universitaire en ligne par faculté.')">
    <meta name="theme-color" content="#1e3a5f">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="apple-mobile-web-app-title" content="UniRegistre">
    <link rel="manifest" href="/manifest.webmanifest">
    <link rel="apple-touch-icon" href="/icons/apple-touch-icon.png">
    <link rel="icon" type="image/png" sizes="192x192" href="/icons/icon-192.png">
    <title>@yield('title', 'Inscription Universitaire') — UniRegistre</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <script>
        // Thème initial (clé `theme` : light|dark|system) — avant peinture, anti-flash.
        (function () {
            try {
                var choix = localStorage.getItem('theme') || 'system';
                var sombre = choix === 'dark' || (choix === 'system' && window.matchMedia('(prefers-color-scheme: dark)').matches);
                if (sombre) document.documentElement.classList.add('dark');
            } catch (e) { /* stockage indisponible : thème clair */ }
        })();
    </script>
    @if($estPortailEtudiant)
    <script>
        // Thème du portail étudiant : V2 par défaut, V1 disponible via
        // `?theme=v1` ou le sélecteur. La préférence locale est appliquée
        // avant peinture pour éviter tout flash de thème.
        (function () {
            var root = document.documentElement;
            var theme = root.getAttribute('data-etudiant-theme') || 'v2';

            try {
                var requested = new URLSearchParams(window.location.search).get('theme');
                var stored = window.localStorage.getItem('etudiant-theme');

                if (stored !== 'v1' && stored !== 'v2') {
                    stored = window.localStorage.getItem('profil-theme');
                }

                if (requested === 'v1' || requested === 'v2') {
                    theme = requested;
                    window.localStorage.setItem('etudiant-theme', theme);
                    window.localStorage.removeItem('profil-theme');
                } else if (stored === 'v1' || stored === 'v2') {
                    theme = stored;
                    window.localStorage.setItem('etudiant-theme', theme);
                    window.localStorage.removeItem('profil-theme');
                }
            } catch (e) { /* stockage indisponible : thème rendu par le serveur */ }

            root.setAttribute('data-etudiant-theme', theme);
        })();
    </script>
    @endif
    <script>
        // Mouvement : préparation avant peinture. Si le module ou JavaScript
        // ne charge pas, le minuteur rend le contenu visible.
        (function () {
            try {
                if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
                document.documentElement.classList.add('motion-prep');
                window.setTimeout(function () {
                    if (!document.documentElement.classList.contains('motion-ready')) {
                        document.documentElement.classList.remove('motion-prep');
                    }
                }, 1800);
            } catch (e) { /* mouvement indisponible : contenu visible */ }
        })();
    </script>
    @livewireStyles
    @livewireScriptConfig
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @if ($motionScope === 'etudiant')
        {{-- Le back-office (`admin`) est chargé à la demande par motion-loader.js. --}}
        @vite('resources/js/etudiant.js')
    @endif
</head>
<body data-motion="{{ $motionScope }}" @if($estPortailEtudiant) data-etudiant-page-theme="{{ $etudiantThemeInitial }}" @endif class="min-h-dvh {{ ($densiteTableaux ?? 'confortable') === 'compact' ? 'densite-compact' : '' }} @hasSection('auth_layout') bg-slate-50 dark:bg-slate-950 text-slate-800 dark:text-slate-100 @else bg-slate-100 text-slate-800 @endif font-sans antialiased selection:bg-primary-500 selection:text-white flex flex-col">

    <a href="#contenu" class="sr-only focus:not-sr-only focus:absolute focus:top-2 focus:left-2 focus:z-[80] focus:rounded-lg focus:bg-primary-800 focus:px-4 focus:py-2 focus:text-sm focus:font-bold focus:text-white">Aller au contenu</a>

    @hasSection('auth_layout')
        <main id="contenu" class="min-h-dvh flex flex-col flex-1" tabindex="-1">
            @yield('content')
        </main>
    @else
    <div x-data="{ barreLaterale: false, menuUtilisateur: false, reduite: localStorage.getItem('sidebar-collapsed') === '1' }"
         :class="reduite && 'is-reduite'"
         class="flex min-h-dvh flex-1 {{ $estPortailEtudiant ? 'etudiant-shell' : '' }}">
        {{-- ── Sidebar desktop (collapsible, préférence persistée) ── --}}
        {{-- Masquée sur les pages déclarant `sans_sidebar` (ex. accueil public). --}}
        @sectionMissing('sans_sidebar')
        <aside :class="reduite ? 'lg:w-20' : 'lg:w-64'" class="lateral hidden lg:flex w-64 flex-shrink-0 flex-col bg-white no-print transition-[width]" aria-label="Navigation principale">
            <div class="flex h-16 items-center gap-3 px-5">
                <a href="{{ $destinationLogo }}" class="flex min-w-0 flex-1 items-center gap-3">
                    <span class="flex h-10 w-10 flex-shrink-0 items-center justify-center rounded-xl bg-primary-800" aria-hidden="true">
                        <svg class="h-5 w-5 text-white" fill="none" viewBox="0 0 24 24" stroke-width="2.2" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M4.26 10.147a60.438 60.438 0 0 0-.491 6.347A48.62 48.62 0 0 1 12 20.904a48.62 48.62 0 0 1 8.232-4.41 60.46 60.46 0 0 0-.491-6.347m-15.482 0a50.636 50.636 0 0 0-2.658-.813A59.906 59.906 0 0 1 12 3.493a59.903 59.903 0 0 1 10.399 5.84c-.896.248-1.783.52-2.658.814m-15.482 0A50.717 50.717 0 0 1 12 13.489a50.702 50.702 0 0 1 7.74-3.342" />
                        </svg>
                    </span>
                    <span class="logo-texte min-w-0">
                        <span class="block truncate text-base font-black tracking-tight text-slate-900">UniRegistre</span>
                        <span class="block text-[13px] font-semibold text-slate-500">Portail universitaire</span>
                    </span>
                </a>
                <button type="button" @click="reduite = !reduite; localStorage.setItem('sidebar-collapsed', reduite ? '1' : '0')"
                        aria-label="Réduire ou étendre la barre latérale" :aria-expanded="(!reduite).toString()"
                        class="rounded-xl p-2 text-slate-400 hover:bg-slate-100 hover:text-slate-700 min-h-[44px] min-w-[44px]">
                    <svg class="h-5 w-5 transition-transform" :class="reduite && 'rotate-180'" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5 8.25 12l7.5-7.5" />
                    </svg>
                </button>
            </div>
            <nav class="flex-1 space-y-1 overflow-y-auto px-3 py-4" aria-label="Sections" data-lenis-prevent>
                @include('layouts._navigation')
            </nav>
            <div class="px-5 py-4 space-y-3">
                @if($estPortailEtudiant && ! request()->routeIs('etudiant.profil.show'))
                    <x-etudiant-theme-switch :theme="$etudiantThemeInitial" compact />
                @endif
                <p class="flex items-center gap-1.5 text-[13px] font-semibold text-slate-500">
                    <span class="h-1.5 w-1.5 rounded-full bg-emerald-500" aria-hidden="true"></span>
                    Session {{ date('Y') }}–{{ date('Y') + 1 }}
                </p>
            </div>
        </aside>
        @endif

        {{-- ── Drawer mobile ── --}}
        @sectionMissing('sans_sidebar')
        <div x-show="barreLaterale" x-cloak class="fixed inset-0 z-[60] lg:hidden"
             @keydown.escape.window="barreLaterale = false" role="dialog" aria-modal="true" aria-label="Menu de navigation">
            <div class="absolute inset-0 bg-slate-900/50" @click="barreLaterale = false" aria-hidden="true"></div>
            <aside x-trap.noscroll="barreLaterale"
                   x-transition:enter="transition ease-out duration-200" x-transition:enter-start="-translate-x-full" x-transition:enter-end="translate-x-0"
                   x-transition:leave="transition ease-out duration-150" x-transition:leave-start="translate-x-0" x-transition:leave-end="-translate-x-full"
                   aria-label="Menu mobile"
                   class="lateral-mobile absolute inset-y-0 left-0 flex w-72 flex-col bg-white shadow-xl"><!-- taste-skill-ignore : ombre de surperposition (tiroir mobile) -->
                <div class="flex h-16 items-center justify-between px-5">
                    <a href="{{ $destinationLogo }}" class="text-base font-black text-slate-900">UniRegistre</a>
                    <button type="button" @click="barreLaterale = false" aria-label="Fermer le menu"
                            class="rounded-xl p-2 text-slate-500 hover:bg-slate-100 min-h-[44px] min-w-[44px]"><svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" /></svg></button>
                </div>
                <nav class="flex-1 space-y-1 overflow-y-auto overscroll-contain px-3 py-4" aria-label="Sections mobile" data-lenis-prevent>
                    @include('layouts._navigation')
                </nav>
                @if($estPortailEtudiant && ! request()->routeIs('etudiant.profil.show'))
                    <div class="border-t border-slate-200/80 px-4 py-4">
                        <x-etudiant-theme-switch :theme="$etudiantThemeInitial" compact />
                    </div>
                @endif
            </aside>
        </div>
        @endif

        {{-- ── Colonne principale ── --}}
        <div class="flex min-w-0 flex-1 flex-col">
            <header class="etudiant-header sticky top-0 z-40 bg-white/90 backdrop-blur no-print">
                <div class="flex h-16 items-center gap-3 px-4 sm:px-6">
                    @sectionMissing('sans_sidebar')
                    <button type="button" @click="barreLaterale = true" aria-label="Ouvrir le menu"
                            class="lg:hidden rounded-xl p-2 text-slate-600 hover:bg-slate-100 min-h-[44px] min-w-[44px]">
                        <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5" />
                        </svg>
                    </button>
                    @endif

                    <a href="{{ $destinationLogo }}" class="{{ \Illuminate\Support\Facades\View::hasSection('sans_sidebar') ? '' : 'lg:hidden ' }}flex items-center gap-2">
                        <span class="flex h-9 w-9 items-center justify-center rounded-xl bg-primary-800 text-sm font-black text-white" aria-hidden="true">U</span>
                        <span class="text-base font-black text-slate-900">UniRegistre</span>
                    </a>

                    @auth
                        <form method="GET" action="{{ route('admin.recherche') }}" role="search" class="ml-2 hidden md:block w-full max-w-md">
                            <label for="recherche-globale" class="sr-only">Recherche globale (2 caractères minimum, raccourci /)</label>
                            <div class="relative">
                                <input type="search" id="recherche-globale" name="q" value="{{ request('q') }}"
                                       placeholder="Recherche globale… (étudiants, cours, salles, demandes)"
                                       @keydown.window.slash.prevent="if ($event.target.tagName !== 'INPUT' && $event.target.tagName !== 'TEXTAREA') $el.focus()"
                                       class="w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-2 pr-10 text-sm focus:border-primary-700 focus:bg-white focus:outline-none">
                                <span class="admin-kbd pointer-events-none absolute right-3 top-1/2 -translate-y-1/2" aria-hidden="true">/</span>
                            </div>
                        </form>
                    @else
                        @if(session('enseignant_auth_id'))
                            <form method="GET" action="{{ route('enseignant.recherche') }}" role="search" class="ml-2 hidden md:block w-full max-w-md">
                                <label for="recherche-portail" class="sr-only">Rechercher dans mon espace (2 caractères minimum)</label>
                                <input type="search" id="recherche-portail" name="q" value="{{ request('q') }}"
                                       placeholder="Rechercher (étudiants, séances, épreuves, supports)…"
                                       class="w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-2 text-sm focus:border-primary-700 focus:bg-white focus:outline-none">
                            </form>
                        @elseif(session('etudiant_auth_id'))
                            <form method="GET" action="{{ route('etudiant.recherche') }}" role="search" class="ml-2 hidden md:block w-full max-w-md">
                                <label for="recherche-portail" class="sr-only">Rechercher dans mon espace (2 caractères minimum)</label>
                                <input type="search" id="recherche-portail" name="q" value="{{ request('q') }}"
                                       placeholder="Rechercher (supports, demandes, séances, annonces)…"
                                       class="w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-2 text-sm focus:border-primary-700 focus:bg-white focus:outline-none">
                            </form>
                        @endif
                    @endauth

                    <div class="ml-auto flex items-center gap-2" x-data="{ cloche: false }">
                        {{-- Thème clair / sombre / système (clé `theme`) --}}
                        <div class="relative" x-data="{ theme: localStorage.getItem('theme') || 'system', menuTheme: false }"
                             x-init="$watch('theme', function (v) { localStorage.setItem('theme', v); appliquerTheme(v); })">
                            <button type="button" @click="menuTheme = !menuTheme" aria-label="Choisir le thème d'affichage" aria-haspopup="true"
                                    class="rounded-xl p-2.5 text-slate-500 hover:bg-slate-100 min-h-[44px] min-w-[44px]">
                                <svg x-show="theme === 'light'" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 3v2.25m6.364.386-1.591 1.591M21 12h-2.25m-.386 6.364-1.591-1.591M12 18.75V21m-4.773-4.227-1.591 1.591M5.25 12H3m4.227-4.773L5.636 5.636M15.75 12a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0Z" />
                                </svg>
                                <svg x-show="theme === 'dark'" x-cloak class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M21.752 15.002A9.72 9.72 0 0 1 18 15.75c-5.385 0-9.75-4.365-9.75-9.75 0-1.33.266-2.597.748-3.752A9.753 9.753 0 0 0 12 21.75a9.753 9.753 0 0 0 9.752-6.748Z" />
                                </svg>
                                <svg x-show="theme !== 'light' && theme !== 'dark'" x-cloak class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 17.25v1.007a3 3 0 0 1-.879 2.122L7.5 21h9l-.621-.621A3 3 0 0 1 15 18.257V17.25m6-12V15a2.25 2.25 0 0 1-2.25 2.25H5.25A2.25 2.25 0 0 1 3 15V5.25m18 0A2.25 2.25 0 0 0 18.75 3H5.25A2.25 2.25 0 0 0 3 5.25m18 0V12a2.25 2.25 0 0 1-2.25 2.25H5.25A2.25 2.25 0 0 1 3 12V5.25" />
                                </svg>
                            </button>
                            <div x-show="menuTheme" x-cloak @click.outside="menuTheme = false"
                                 class="absolute right-0 mt-2 w-44 rounded-2xl bg-white p-1.5 shadow-lg dark:bg-slate-900" role="menu" aria-label="Thème"><!-- taste-skill-ignore : ombre de menu -->
                                <template x-for="option in [['light', 'Clair'], ['dark', 'Sombre'], ['system', 'Système']]" :key="option[0]">
                                    <button type="button" role="menuitemradio" :aria-checked="theme === option[0]"
                                            @click="theme = option[0]; menuTheme = false"
                                            class="flex w-full items-center justify-between rounded-xl px-3 py-2.5 text-sm font-semibold text-slate-700 hover:bg-slate-100 dark:text-slate-200 dark:hover:bg-slate-800">
                                        <span x-text="option[1]"></span>
                                        <svg x-show="theme === option[0]" class="h-4 w-4 text-primary-800" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" /></svg>
                                    </button>
                                </template>
                            </div>
                        </div>
                        <script>
                            function appliquerTheme(choix) {
                                var sombre = choix === 'dark' || (choix === 'system' && window.matchMedia('(prefers-color-scheme: dark)').matches);
                                document.documentElement.classList.toggle('dark', sombre);
                            }
                        </script>
                        @if(session('etudiant_auth_id') || session('enseignant_auth_id') || auth()->check())
                            <div class="relative">
                                <button type="button" @click="cloche = !cloche" aria-label="Notifications" aria-haspopup="true"
                                        class="relative rounded-xl p-2.5 text-slate-500 hover:bg-slate-100 min-h-[44px] min-w-[44px]">
                                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M14.857 17.082a23.848 23.848 0 0 0 5.454-1.31A8.967 8.967 0 0 1 18 9.75v-.7V9A6 6 0 0 0 6 9v.75a8.967 8.967 0 0 1-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 0 1-5.714 0m5.714 0a3 3 0 1 1-5.714 0" />
                                    </svg>
                                    @if(($notificationsNonLues ?? 0) > 0)
                                        <span class="absolute top-1 right-1 flex h-4 min-w-4 items-center justify-center rounded-full bg-rose-600 px-1 text-[11px] font-black text-white" aria-label="{{ $notificationsNonLues }} non lues">{{ $notificationsNonLues > 9 ? '9+' : $notificationsNonLues }}</span>
                                    @endif
                                </button>
                                <div x-show="cloche" x-cloak @click.outside="cloche = false"
                                     class="absolute right-0 mt-2 w-80 max-w-[90vw] rounded-2xl bg-white shadow-xl" role="menu" aria-label="Notifications récentes"><!-- taste-skill-ignore : ombre de menu -->
                                    <div class="flex items-center justify-between px-4 pb-1 pt-3">
                                        <p class="text-sm font-black text-slate-900">Notifications</p>
                                        @if($notificationsLien)
                                            <a href="{{ $notificationsLien }}" class="text-[13px] font-bold text-primary-800 underline underline-offset-2">Tout voir</a>
                                        @endif
                                    </div>
                                    @if(($notificationsRecentes ?? collect())->isNotEmpty())
                                        <ul class="max-h-80 space-y-1 overflow-y-auto overscroll-contain px-2 pb-2" data-lenis-prevent>
                                            @foreach($notificationsRecentes as $notification)
                                                <li class="rounded-xl px-3 py-3 {{ $notification->read_at === null ? 'bg-primary-50/50' : '' }}">
                                                    <p class="text-sm font-bold text-slate-900">{{ $notification->titre }}</p>
                                                    <p class="mt-0.5 line-clamp-2 text-[13px] text-slate-500">{{ $notification->message }}</p>
                                                    <p class="mt-0.5 text-[13px] text-slate-400 tabular-nums">{{ $notification->created_at->diffForHumans() }}</p>
                                                </li>
                                            @endforeach
                                        </ul>
                                    @else
                                        <p class="px-4 pb-5 pt-3 text-[13px] text-slate-500">Aucune notification.</p>
                                    @endif
                                </div>
                            </div>
                        @endif
                        @if(session('etudiant_auth_id'))
                            <div class="relative">
                                <button type="button" @click="menuUtilisateur = !menuUtilisateur" aria-label="Menu utilisateur" aria-haspopup="true"
                                        class="flex items-center gap-2 rounded-xl border border-slate-200 px-2.5 py-1.5 hover:bg-slate-50 min-h-[44px]">
                                    <span class="flex h-8 w-8 items-center justify-center rounded-lg bg-primary-800 text-xs font-black text-white" aria-hidden="true">
                                        {{ mb_strtoupper(mb_substr(session('etudiant_auth_prenom', 'É'), 0, 1)) }}
                                    </span>
                                    <span class="hidden sm:block max-w-[120px] truncate text-xs font-bold text-slate-700">{{ session('etudiant_auth_prenom') }}</span>
                                </button>
                                <div x-show="menuUtilisateur" x-cloak @click.outside="menuUtilisateur = false"
                                     class="absolute right-0 mt-2 w-52 rounded-2xl bg-white p-2 shadow-lg" role="menu"><!-- taste-skill-ignore : ombre de menu -->
                                    <a href="{{ route('etudiant.profil.show') }}" role="menuitem" class="block rounded-xl px-3 py-2.5 text-sm font-semibold text-slate-700 hover:bg-slate-100">Mon profil</a>
                                    <a href="{{ route('etudiant.archives.show') }}" role="menuitem" class="block rounded-xl px-3 py-2.5 text-sm font-semibold text-slate-700 hover:bg-slate-100">Mon dossier</a>
                                    <form method="POST" action="{{ route('etudiant.connexion.logout') }}">
                                        @csrf
                                        <button type="submit" role="menuitem" class="block w-full rounded-xl px-3 py-2.5 text-left text-sm font-semibold text-rose-600 hover:bg-rose-50">Déconnexion</button>
                                    </form>
                                </div>
                            </div>
                        @elseif(session('enseignant_auth_id'))
                            <div class="relative">
                                <button type="button" @click="menuUtilisateur = !menuUtilisateur" aria-label="Menu utilisateur" aria-haspopup="true"
                                        class="flex items-center gap-2 rounded-xl border border-slate-200 px-2.5 py-1.5 hover:bg-slate-50 min-h-[44px]">
                                    <span class="flex h-8 w-8 items-center justify-center rounded-lg bg-primary-800 text-xs font-black text-white" aria-hidden="true">
                                        {{ mb_strtoupper(mb_substr(session('enseignant_auth_prenom', 'E'), 0, 1)) }}
                                    </span>
                                    <span class="hidden sm:block max-w-[120px] truncate text-xs font-bold text-slate-700">{{ session('enseignant_auth_prenom') }}</span>
                                </button>
                                <div x-show="menuUtilisateur" x-cloak @click.outside="menuUtilisateur = false"
                                     class="absolute right-0 mt-2 w-52 rounded-2xl bg-white p-2 shadow-lg" role="menu"><!-- taste-skill-ignore : ombre de menu -->
                                    <a href="{{ route('enseignant.profil.show') }}" role="menuitem" class="block rounded-xl px-3 py-2.5 text-sm font-semibold text-slate-700 hover:bg-slate-100">Mon profil</a>
                                    <a href="{{ route('enseignant.dashboard') }}" role="menuitem" class="block rounded-xl px-3 py-2.5 text-sm font-semibold text-slate-700 hover:bg-slate-100">Tableau de bord</a>
                                    <form method="POST" action="{{ route('enseignant.connexion.logout') }}">
                                        @csrf
                                        <button type="submit" role="menuitem" class="block w-full rounded-xl px-3 py-2.5 text-left text-sm font-semibold text-rose-600 hover:bg-rose-50">Déconnexion</button>
                                    </form>
                                </div>
                            </div>
                        @elseif(auth()->check())
                            <div class="relative">
                                <button type="button" @click="menuUtilisateur = !menuUtilisateur" aria-label="Menu utilisateur" aria-haspopup="true"
                                        class="flex items-center gap-2 rounded-xl border border-slate-200 px-2.5 py-1.5 hover:bg-slate-50 min-h-[44px]">
                                    <span class="flex h-8 w-8 items-center justify-center rounded-lg bg-slate-900 text-xs font-black text-white" aria-hidden="true">
                                        {{ mb_strtoupper(mb_substr(auth()->user()->name, 0, 1)) }}
                                    </span>
                                    <span class="hidden sm:block max-w-[140px] truncate text-xs font-bold text-slate-700">{{ auth()->user()->name }}</span>
                                </button>
                                <div x-show="menuUtilisateur" x-cloak @click.outside="menuUtilisateur = false"
                                     class="absolute right-0 mt-2 w-52 rounded-2xl bg-white p-2 shadow-lg" role="menu"><!-- taste-skill-ignore : ombre de menu -->
                                    <p class="px-3 py-2 text-[13px] font-semibold text-slate-500">{{ auth()->user()->role_libelle }}</p>
                                    <a href="{{ route('admin.dashboard') }}" role="menuitem" class="block rounded-xl px-3 py-2.5 text-sm font-semibold text-slate-700 hover:bg-slate-100">Tableau de bord</a>
                                    <a href="{{ route('admin.mfa.configurer') }}" role="menuitem" class="block rounded-xl px-3 py-2.5 text-sm font-semibold text-slate-700 hover:bg-slate-100">Double facteur</a>
                                    <a href="{{ route('admin.verrouillage.code') }}" role="menuitem" class="block rounded-xl px-3 py-2.5 text-sm font-semibold text-slate-700 hover:bg-slate-100">Code de verrouillage</a>
                                    <form method="POST" action="{{ route('admin.connexion.logout') }}">
                                        @csrf
                                        <button type="submit" role="menuitem" class="block w-full rounded-xl px-3 py-2.5 text-left text-sm font-semibold text-rose-600 hover:bg-rose-50">Déconnexion</button>
                                    </form>
                                </div>
                            </div>
                        @else
                            <a href="{{ route('etudiant.connexion.show') }}"
                               class="hidden sm:inline-flex items-center rounded-xl border border-slate-300 px-4 py-2 text-xs font-bold text-slate-700 hover:bg-slate-50 min-h-[44px]">Connexion</a>
                            <a href="{{ route('admin.connexion.show') }}"
                               class="inline-flex items-center rounded-xl bg-slate-900 px-4 py-2 text-xs font-bold text-white hover:bg-slate-800 min-h-[44px]">Espace Scolarité</a>
                        @endif
                    </div>
                </div>
            </header>

            {{-- ── Contenu ── --}}
            <main id="contenu" class="flex-1 px-4 sm:px-6 lg:px-8 py-6 sm:py-8" tabindex="-1">
                <div class="mx-auto max-w-[88rem]">
                    @hasSection('content')
                        @yield('content')
                    @elseif(isset($slot) && ($slot instanceof \Illuminate\Contracts\Support\Htmlable || is_string($slot)))
                        {{ $slot }}
                    @endif
                </div>
            </main>

            {{-- ── Pied de page sobre ── --}}
            <footer class="etudiant-footer bg-white no-print">
                <div class="mx-auto flex max-w-[88rem] flex-col sm:flex-row items-center justify-between gap-2 px-4 sm:px-6 lg:px-8 py-5 text-[13px] text-slate-500">
                    <p>© {{ date('Y') }} Université — Plateforme officielle UniRegistre. Tous droits réservés.</p>
                    <p class="flex items-center gap-1.5">
                        <span class="h-1.5 w-1.5 rounded-full bg-emerald-500" aria-hidden="true"></span>
                        Service sécurisé (TLS) — scolarite@universite.ac.ma
                    </p>
                </div>
            </footer>
        </div>
    </div>
    @endif

    {{-- ── Palette de commandes (utilisateurs connectés) ── --}}
    <x-palette />

    {{-- ── Assistant IA Popup Multiportail (UniRegistre AI) ── --}}
    {{-- Réservé aux acteurs connectés : le contrôleur résout l'acteur et
         répond 401 aux visiteurs anonymes — monter le popup pour eux ne
         produisait qu'une requête inutile et une erreur console. --}}
    @if(config('assistant-ia.actif', true) && auth()->check())
        <x-uniregistre-ai-popup />
    @endif

    {{-- ── Notifications toast (flash session) ── --}}
    <div class="fixed bottom-4 right-4 z-[70] w-full max-w-sm space-y-2 px-4 sm:px-0 no-print" aria-live="polite">
        @if(session('success'))
            <div x-data="{ visible: true }" x-show="visible" x-init="setTimeout(() => visible = false, 6000)"
                 class="flex items-start gap-3 rounded-2xl bg-white p-4 shadow-lg" role="status"><!-- taste-skill-ignore : ombre de toast (surperposition réelle) -->
                <svg class="h-5 w-5 shrink-0 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" /></svg>
                <p class="flex-1 text-[13px] font-semibold text-emerald-900">{{ session('success') }}</p>
                <button type="button" @click="visible = false" aria-label="Fermer la notification" class="text-slate-400 hover:text-slate-600 min-h-[32px] min-w-[32px]"><svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" /></svg></button>
            </div>
        @endif
        @if(session('error'))
            <div x-data="{ visible: true }" x-show="visible" x-init="setTimeout(() => visible = false, 8000)"
                 class="flex items-start gap-3 rounded-2xl bg-white p-4 shadow-lg" role="alert"><!-- taste-skill-ignore : ombre de toast (surperposition réelle) -->
                <svg class="h-5 w-5 shrink-0 text-rose-600" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" /></svg>
                <p class="flex-1 text-[13px] font-semibold text-rose-900">{{ session('error') }}</p>
                <button type="button" @click="visible = false" aria-label="Fermer la notification" class="text-slate-400 hover:text-slate-600 min-h-[32px] min-w-[32px]"><svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" /></svg></button>
            </div>
        @endif
        @if(session('info'))
            <div x-data="{ visible: true }" x-show="visible" x-init="setTimeout(() => visible = false, 6000)"
                 class="flex items-start gap-3 rounded-2xl bg-white p-4 shadow-lg" role="status"><!-- taste-skill-ignore : ombre de toast (surperposition réelle) -->
                <svg class="h-5 w-5 shrink-0 text-sky-600" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m11.25 11.25.041-.02a.75.75 0 0 1 1.063.852l-.708 2.836a.75.75 0 0 0 1.063.853l.041-.021M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9-3.75h.008v.008H12V8.25Z" /></svg>
                <p class="flex-1 text-[13px] font-semibold text-sky-900">{{ session('info') }}</p>
                <button type="button" @click="visible = false" aria-label="Fermer la notification" class="text-slate-400 hover:text-slate-600 min-h-[32px] min-w-[32px]"><svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" /></svg></button>
            </div>
        @endif
        @if(session('warning'))
            <div x-data="{ visible: true }" x-show="visible" x-init="setTimeout(() => visible = false, 7000)"
                 class="flex items-start gap-3 rounded-2xl bg-white p-4 shadow-lg" role="status"><!-- taste-skill-ignore : ombre de toast (surperposition réelle) -->
                <svg class="h-5 w-5 shrink-0 text-amber-600" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008Z" /></svg>
                <p class="flex-1 text-[13px] font-semibold text-amber-900">{{ session('warning') }}</p>
                <button type="button" @click="visible = false" aria-label="Fermer la notification" class="text-slate-400 hover:text-slate-600 min-h-[32px] min-w-[32px]"><svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" /></svg></button>
            </div>
        @endif
    </div>

    <style>
        [x-cloak] { display: none !important; }
    </style>

    <script>
        // PWA : enregistrement du service worker (production uniquement —
        // jamais en local/dev pour ne pas servir un shell obsolète).
        if ('serviceWorker' in navigator && window.location.protocol === 'https:') {
            window.addEventListener('load', () => {
                navigator.serviceWorker.register('/sw.js').catch(() => {});
            });
        }
    </script>

    @stack('scripts')

    {{-- Surveillance d'inactivité (LOOP N) : overlay seul si éligible
         (compte avec PIN) et écran autre que le verrouillage. --}}
    @if(session('secondaire_eligible') && empty($sansSurveillance))
        <x-verrouillage-surveillance
            :delai="app(App\Services\VerrouillageService::class)->delaiSecondes()"
            :preavis="app(App\Services\VerrouillageService::class)->preavisSecondes()"
            :ancreServeur="time()" />
    @endif
</body>
</html>
