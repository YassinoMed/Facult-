<!DOCTYPE html>
<html lang="fr" class="scroll-smooth">
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
    @livewireStyles
    @livewireScriptConfig
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen {{ ($densiteTableaux ?? 'confortable') === 'compact' ? 'densite-compact' : '' }} @hasSection('auth_layout') bg-slate-50 dark:bg-slate-950 text-slate-800 dark:text-slate-100 @else bg-slate-100 text-slate-800 @endif font-sans antialiased selection:bg-primary-500 selection:text-white flex flex-col">

    <a href="#contenu" class="sr-only focus:not-sr-only focus:absolute focus:top-2 focus:left-2 focus:z-[80] focus:rounded-lg focus:bg-primary-800 focus:px-4 focus:py-2 focus:text-sm focus:font-bold focus:text-white">Aller au contenu</a>

    @hasSection('auth_layout')
        <main id="contenu" class="min-h-screen flex flex-col flex-1" tabindex="-1">
            @yield('content')
        </main>
    @else
    <div x-data="{ barreLaterale: false, menuUtilisateur: false, reduite: localStorage.getItem('sidebar-collapsed') === '1' }"
         :class="reduite && 'is-reduite'" class="flex min-h-screen flex-1">
        {{-- ── Sidebar desktop (collapsible, préférence persistée) ── --}}
        <aside :class="reduite ? 'lg:w-20' : 'lg:w-64'" class="lateral hidden lg:flex w-64 flex-shrink-0 flex-col border-r border-slate-200 bg-white no-print transition-all" aria-label="Navigation principale">
            <div class="flex h-16 items-center gap-3 border-b border-slate-100 px-5">
                <a href="{{ route('inscription.create') }}" class="flex min-w-0 flex-1 items-center gap-3">
                    <span class="flex h-10 w-10 flex-shrink-0 items-center justify-center rounded-xl bg-primary-800 shadow-sm" aria-hidden="true">
                        <svg class="h-5 w-5 text-white" fill="none" viewBox="0 0 24 24" stroke-width="2.2" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M4.26 10.147a60.438 60.438 0 0 0-.491 6.347A48.62 48.62 0 0 1 12 20.904a48.62 48.62 0 0 1 8.232-4.41 60.46 60.46 0 0 0-.491-6.347m-15.482 0a50.636 50.636 0 0 0-2.658-.813A59.906 59.906 0 0 1 12 3.493a59.903 59.903 0 0 1 10.399 5.84c-.896.248-1.783.52-2.658.814m-15.482 0A50.717 50.717 0 0 1 12 13.489a50.702 50.702 0 0 1 7.74-3.342" />
                        </svg>
                    </span>
                    <span class="logo-texte min-w-0">
                        <span class="block truncate text-base font-black tracking-tight text-slate-900">UniRegistre</span>
                        <span class="block text-[10px] font-semibold uppercase tracking-wider text-slate-400">Portail universitaire</span>
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
            <nav class="flex-1 space-y-1 overflow-y-auto px-3 py-4" aria-label="Sections">
                @include('layouts._navigation')
            </nav>
            <div class="border-t border-slate-100 px-5 py-3">
                <p class="flex items-center gap-1.5 text-[11px] font-semibold text-slate-500">
                    <span class="h-1.5 w-1.5 rounded-full bg-emerald-500" aria-hidden="true"></span>
                    Session {{ date('Y') }}–{{ date('Y') + 1 }}
                </p>
            </div>
        </aside>

        {{-- ── Drawer mobile ── --}}
        <div x-show="barreLaterale" x-cloak class="fixed inset-0 z-[60] lg:hidden"
             @keydown.escape.window="barreLaterale = false" role="dialog" aria-modal="true" aria-label="Menu de navigation">
            <div class="absolute inset-0 bg-slate-900/50" @click="barreLaterale = false" aria-hidden="true"></div>
            <aside class="absolute inset-y-0 left-0 flex w-72 flex-col bg-white shadow-xl"
                   x-transition:enter="transition ease-out duration-200" x-transition:enter-start="-translate-x-full" x-transition:enter-end="translate-x-0"
                   x-transition:leave="transition ease-in duration-150" x-transition:leave-start="translate-x-0" x-transition:leave-end="-translate-x-full"
                   aria-label="Menu mobile">
                <div class="flex h-16 items-center justify-between border-b border-slate-100 px-5">
                    <span class="text-base font-black text-slate-900">UniRegistre</span>
                    <button type="button" @click="barreLaterale = false" aria-label="Fermer le menu"
                            class="rounded-xl p-2 text-slate-500 hover:bg-slate-100 min-h-[44px] min-w-[44px]">✕</button>
                </div>
                <nav class="flex-1 space-y-1 overflow-y-auto px-3 py-4" aria-label="Sections mobile">
                    @include('layouts._navigation')
                </nav>
            </aside>
        </div>

        {{-- ── Colonne principale ── --}}
        <div class="flex min-w-0 flex-1 flex-col">
            <header class="sticky top-0 z-40 border-b border-slate-200 bg-white/90 backdrop-blur no-print">
                <div class="flex h-16 items-center gap-3 px-4 sm:px-6">
                    <button type="button" @click="barreLaterale = true" aria-label="Ouvrir le menu"
                            class="lg:hidden rounded-xl p-2 text-slate-600 hover:bg-slate-100 min-h-[44px] min-w-[44px]">
                        <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5" />
                        </svg>
                    </button>

                    <a href="{{ route('inscription.create') }}" class="lg:hidden flex items-center gap-2">
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
                                 class="absolute right-0 mt-2 w-44 rounded-2xl border border-slate-200 bg-white p-1.5 shadow-lg dark:border-slate-700 dark:bg-slate-900" role="menu" aria-label="Thème">
                                <template x-for="option in [['light', 'Clair'], ['dark', 'Sombre'], ['system', 'Système']]" :key="option[0]">
                                    <button type="button" role="menuitemradio" :aria-checked="theme === option[0]"
                                            @click="theme = option[0]; menuTheme = false"
                                            class="flex w-full items-center justify-between rounded-xl px-3 py-2.5 text-sm font-semibold text-slate-700 hover:bg-slate-100 dark:text-slate-200 dark:hover:bg-slate-800">
                                        <span x-text="option[1]"></span>
                                        <span x-show="theme === option[0]" aria-hidden="true">✓</span>
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
                                        <span class="absolute top-1 right-1 flex h-4 min-w-4 items-center justify-center rounded-full bg-rose-600 px-1 text-[9px] font-black text-white" aria-label="{{ $notificationsNonLues }} non lues">{{ $notificationsNonLues > 9 ? '9+' : $notificationsNonLues }}</span>
                                    @endif
                                </button>
                                <div x-show="cloche" x-cloak @click.outside="cloche = false"
                                     class="absolute right-0 mt-2 w-80 max-w-[90vw] rounded-2xl border border-slate-200 bg-white shadow-xl" role="menu" aria-label="Notifications récentes">
                                    <div class="flex items-center justify-between border-b border-slate-100 px-4 py-3">
                                        <p class="text-sm font-black text-slate-900">Notifications</p>
                                        @if($notificationsLien)
                                            <a href="{{ $notificationsLien }}" class="text-[11px] font-bold text-primary-800 underline underline-offset-2">Tout voir</a>
                                        @endif
                                    </div>
                                    @if(($notificationsRecentes ?? collect())->isNotEmpty())
                                        <ul class="max-h-80 overflow-y-auto divide-y divide-slate-100">
                                            @foreach($notificationsRecentes as $notification)
                                                <li class="px-4 py-3 {{ $notification->read_at === null ? 'bg-primary-50/50' : '' }}">
                                                    <p class="text-xs font-bold text-slate-900">{{ $notification->titre }}</p>
                                                    <p class="mt-0.5 line-clamp-2 text-[11px] text-slate-500">{{ $notification->message }}</p>
                                                    <p class="mt-0.5 text-[10px] text-slate-400 tabular-nums">{{ $notification->created_at->diffForHumans() }}</p>
                                                </li>
                                            @endforeach
                                        </ul>
                                    @else
                                        <p class="px-4 py-6 text-center text-xs text-slate-500">Aucune notification.</p>
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
                                     class="absolute right-0 mt-2 w-52 rounded-2xl border border-slate-200 bg-white p-2 shadow-lg" role="menu">
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
                                     class="absolute right-0 mt-2 w-52 rounded-2xl border border-slate-200 bg-white p-2 shadow-lg" role="menu">
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
                                     class="absolute right-0 mt-2 w-52 rounded-2xl border border-slate-200 bg-white p-2 shadow-lg" role="menu">
                                    <p class="px-3 py-2 text-[11px] font-bold uppercase tracking-wider text-slate-400">{{ auth()->user()->role_libelle }}</p>
                                    <a href="{{ route('admin.dashboard') }}" role="menuitem" class="block rounded-xl px-3 py-2.5 text-sm font-semibold text-slate-700 hover:bg-slate-100">Tableau de bord</a>
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
                    @yield('content')
                </div>
            </main>

            {{-- ── Pied de page sobre ── --}}
            <footer class="border-t border-slate-200 bg-white no-print">
                <div class="mx-auto flex max-w-[88rem] flex-col sm:flex-row items-center justify-between gap-2 px-4 sm:px-6 lg:px-8 py-4 text-[11px] text-slate-500">
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

    {{-- ── Notifications toast (flash session) ── --}}
    <div class="fixed bottom-4 right-4 z-[70] w-full max-w-sm space-y-2 px-4 sm:px-0 no-print" aria-live="polite">
        @if(session('success'))
            <div x-data="{ visible: true }" x-show="visible" x-init="setTimeout(() => visible = false, 6000)"
                 class="flex items-start gap-3 rounded-2xl border border-emerald-300 bg-white p-4 shadow-lg" role="status">
                <span class="font-black text-emerald-600" aria-hidden="true">✓</span>
                <p class="flex-1 text-xs font-semibold text-emerald-900">{{ session('success') }}</p>
                <button type="button" @click="visible = false" aria-label="Fermer la notification" class="text-slate-400 hover:text-slate-600 min-h-[32px] min-w-[32px]">✕</button>
            </div>
        @endif
        @if(session('error'))
            <div x-data="{ visible: true }" x-show="visible" x-init="setTimeout(() => visible = false, 8000)"
                 class="flex items-start gap-3 rounded-2xl border border-rose-300 bg-white p-4 shadow-lg" role="alert">
                <span class="font-black text-rose-600" aria-hidden="true">✕</span>
                <p class="flex-1 text-xs font-semibold text-rose-900">{{ session('error') }}</p>
                <button type="button" @click="visible = false" aria-label="Fermer la notification" class="text-slate-400 hover:text-slate-600 min-h-[32px] min-w-[32px]">✕</button>
            </div>
        @endif
        @if(session('info'))
            <div x-data="{ visible: true }" x-show="visible" x-init="setTimeout(() => visible = false, 6000)"
                 class="flex items-start gap-3 rounded-2xl border border-sky-300 bg-white p-4 shadow-lg" role="status">
                <span class="font-black text-sky-600" aria-hidden="true">ℹ</span>
                <p class="flex-1 text-xs font-semibold text-sky-900">{{ session('info') }}</p>
                <button type="button" @click="visible = false" aria-label="Fermer la notification" class="text-slate-400 hover:text-slate-600 min-h-[32px] min-w-[32px]">✕</button>
            </div>
        @endif
        @if(session('warning'))
            <div x-data="{ visible: true }" x-show="visible" x-init="setTimeout(() => visible = false, 7000)"
                 class="flex items-start gap-3 rounded-2xl border border-amber-300 bg-white p-4 shadow-lg" role="status">
                <span class="font-black text-amber-600" aria-hidden="true">⚠</span>
                <p class="flex-1 text-xs font-semibold text-amber-900">{{ session('warning') }}</p>
                <button type="button" @click="visible = false" aria-label="Fermer la notification" class="text-slate-400 hover:text-slate-600 min-h-[32px] min-w-[32px]">✕</button>
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
</body>
</html>
