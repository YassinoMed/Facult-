@extends('layouts.app')

@section('auth_layout', true)
@section('title', 'Nouveau mot de passe — Espace Scolarité')
@section('meta_description', 'Définissez votre nouveau mot de passe pour votre compte Espace Scolarité.')

@section('content')
<div class="relative min-h-screen flex flex-col justify-between bg-slate-50 dark:bg-slate-950 text-slate-800 dark:text-slate-100 selection:bg-blue-600 selection:text-white overflow-x-hidden"
     x-data="{ 
         theme: localStorage.getItem('theme') || 'system',
         basculerTheme() {
             var suivant = this.theme === 'dark' ? 'light' : 'dark';
             this.theme = suivant;
             localStorage.setItem('theme', suivant);
             appliquerTheme(suivant);
         }
     }">

    {{-- ── Arrière-plan ambiant coloré ── --}}
    <div class="fixed inset-0 pointer-events-none z-0 overflow-hidden">
        <div class="absolute -top-32 right-1/3 w-[600px] h-[600px] rounded-full bg-blue-600/15 dark:bg-blue-600/20 blur-[130px] animate-float"></div>
        <div class="absolute -bottom-32 left-1/4 w-[550px] h-[550px] rounded-full bg-indigo-500/15 blur-[140px]"></div>
        <div class="absolute inset-0 bg-grid-pattern opacity-40 dark:opacity-20"></div>
    </div>

    {{-- ── Barre supérieure ── --}}
    <header class="relative z-20 border-b border-slate-200/80 dark:border-slate-800/80 bg-white/85 dark:bg-slate-950/80 backdrop-blur-xl px-4 sm:px-8 py-3.5 shadow-xs">
        <div class="mx-auto max-w-7xl flex items-center justify-between gap-4">
            <a href="{{ route('admin.connexion.show') }}" class="group flex items-center gap-3 focus:outline-none focus:ring-2 focus:ring-blue-600 rounded-xl px-1.5 py-1">
                <span class="flex h-10 w-10 flex-shrink-0 items-center justify-center rounded-xl bg-gradient-to-br from-blue-700 to-indigo-900 border border-blue-500/30 shadow-md shadow-blue-900/30 group-hover:scale-105 transition-transform duration-200">
                    <svg class="h-5 w-5 text-blue-200" fill="none" viewBox="0 0 24 24" stroke-width="2.2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 1 0-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 0 0 2.25-2.25v-6.75a2.25 2.25 0 0 0-2.25-2.25H6.75a2.25 2.25 0 0 0-2.25 2.25v6.75a2.25 2.25 0 0 0 2.25 2.25Z" />
                    </svg>
                </span>
                <span class="flex flex-col">
                    <span class="text-base font-black tracking-tight text-slate-900 dark:text-white group-hover:text-blue-600 dark:group-hover:text-blue-400 transition-colors">UniRegistre</span>
                    <span class="text-[10px] font-bold uppercase tracking-wider text-blue-600 dark:text-blue-400">Back-Office Scolarité</span>
                </span>
            </a>

            <div class="flex items-center gap-3">
                <a href="{{ route('admin.connexion.show') }}"
                   class="inline-flex items-center gap-2 px-3.5 py-1.5 rounded-xl text-xs font-bold text-blue-700 dark:text-blue-300 bg-blue-50 dark:bg-blue-950/60 hover:bg-blue-100 dark:hover:bg-blue-900/60 border border-blue-200 dark:border-blue-800/80 transition-all focus:outline-none focus:ring-2 focus:ring-blue-600">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5 3 12m0 0 7.5-7.5M3 12h18" />
                    </svg>
                    Retour à la connexion
                </a>
            </div>
        </div>
    </header>

    {{-- ── Contenu centré ── --}}
    <div class="relative z-10 flex-1 flex items-center justify-center px-4 sm:px-6 py-12">
        <div class="w-full max-w-md rounded-3xl bg-white dark:bg-slate-900 border-2 border-slate-200/90 dark:border-slate-800 shadow-2xl shadow-blue-950/10 dark:shadow-black/50 p-6 sm:p-10 relative overflow-hidden animate-fade-up"
             x-data="{ showPassword: false, submitting: false }">

            <div class="absolute top-0 inset-x-0 h-1.5 bg-gradient-to-r from-blue-600 via-indigo-600 to-emerald-500"></div>

            <div class="flex items-start gap-4 pb-6 border-b border-slate-200 dark:border-slate-800">
                <div class="flex h-12 w-12 flex-shrink-0 items-center justify-center rounded-2xl bg-gradient-to-br from-blue-700 to-indigo-900 border border-blue-500/30 shadow-lg shadow-blue-900/30 text-white">
                    <svg class="h-6 w-6 text-blue-200" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 1 0-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 0 0 2.25-2.25v-6.75a2.25 2.25 0 0 0-2.25-2.25H6.75a2.25 2.25 0 0 0-2.25 2.25v6.75a2.25 2.25 0 0 0 2.25 2.25Z" />
                    </svg>
                </div>
                <div>
                    <h1 class="text-xl sm:text-2xl font-black tracking-tight text-slate-900 dark:text-white">Nouveau mot de passe</h1>
                    <p class="text-xs text-slate-600 dark:text-slate-400 mt-0.5">Choisissez un mot de passe robuste pour votre compte.</p>
                </div>
            </div>

            @if($errors->any())
                <div class="mt-5 p-4 rounded-2xl bg-rose-50 dark:bg-rose-950/40 border-2 border-rose-300 dark:border-rose-700/60 text-rose-800 dark:text-rose-200 text-xs font-bold flex items-start gap-2.5">
                    <svg class="h-4 w-4 text-rose-600 dark:text-rose-400 flex-shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008Z" />
                    </svg>
                    <span>{{ $errors->first() }}</span>
                </div>
            @endif

            <form method="POST" action="{{ route('admin.mot-de-passe.enregistrer') }}" @submit="submitting = true" class="mt-6 space-y-4">
                @csrf
                <input type="hidden" name="token" value="{{ $token }}" />

                {{-- Email --}}
                <div class="space-y-1.5">
                    <label for="email" class="block text-xs font-extrabold text-slate-800 dark:text-slate-200 uppercase tracking-wider">
                        Email professionnel <span class="text-rose-500">*</span>
                    </label>
                    <input id="email" type="email" name="email" required maxlength="255" autocomplete="email"
                           value="{{ old('email', $email) }}"
                           class="w-full rounded-2xl border-2 border-slate-300 dark:border-slate-700 bg-slate-50/90 dark:bg-slate-800/90 px-4 py-3 text-sm font-medium text-slate-900 dark:text-white placeholder-slate-400 transition-smooth focus:border-blue-600 focus:bg-white dark:focus:bg-slate-800 focus:ring-4 focus:ring-blue-500/15 focus:outline-none">
                </div>

                {{-- Nouveau mot de passe --}}
                <div class="space-y-1.5">
                    <label for="password" class="block text-xs font-extrabold text-slate-800 dark:text-slate-200 uppercase tracking-wider">
                        Nouveau mot de passe <span class="text-rose-500">*</span>
                    </label>
                    <div class="relative">
                        <input id="password" name="password" required autocomplete="new-password"
                               :type="showPassword ? 'text' : 'password'"
                               placeholder="••••••••"
                               class="w-full rounded-2xl border-2 border-slate-300 dark:border-slate-700 bg-slate-50/90 dark:bg-slate-800/90 px-4 py-3 pr-11 text-sm font-medium text-slate-900 dark:text-white placeholder-slate-400 transition-smooth focus:border-blue-600 focus:bg-white dark:focus:bg-slate-800 focus:ring-4 focus:ring-blue-500/15 focus:outline-none">
                        <button type="button" @click="showPassword = !showPassword"
                                class="absolute inset-y-0 right-0 flex items-center pr-3.5 text-slate-400 hover:text-blue-600 dark:hover:text-white transition-colors">
                            <svg x-show="!showPassword" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.964-7.178Z" />
                                <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
                            </svg>
                            <svg x-show="showPassword" x-cloak class="h-5 w-5 text-blue-600 dark:text-blue-400" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M3.98 8.223A10.477 10.477 0 0 0 1.934 12c1.292 4.338 5.31 7.5 10.066 7.5.993 0 1.953-.138 2.863-.395M6.228 6.228A10.451 10.451 0 0 1 12 4.5c4.756 0 8.773 3.162 10.065 7.498a10.523 10.523 0 0 1-4.293 5.774M6.228 6.228 3 3m3.228 3.228 3.65 3.65m7.894 7.894L21 21m-3.228-3.228-3.65-3.65" />
                            </svg>
                        </button>
                    </div>
                </div>

                {{-- Confirmation --}}
                <div class="space-y-1.5">
                    <label for="password_confirmation" class="block text-xs font-extrabold text-slate-800 dark:text-slate-200 uppercase tracking-wider">
                        Confirmer le mot de passe <span class="text-rose-500">*</span>
                    </label>
                    <input id="password_confirmation" type="password" name="password_confirmation" required autocomplete="new-password"
                           placeholder="••••••••"
                           class="w-full rounded-2xl border-2 border-slate-300 dark:border-slate-700 bg-slate-50/90 dark:bg-slate-800/90 px-4 py-3 text-sm font-medium text-slate-900 dark:text-white placeholder-slate-400 transition-smooth focus:border-blue-600 focus:bg-white dark:focus:bg-slate-800 focus:ring-4 focus:ring-blue-500/15 focus:outline-none">
                </div>

                <button type="submit" :disabled="submitting"
                        class="btn-shine relative w-full rounded-2xl bg-gradient-to-r from-blue-700 via-blue-600 to-indigo-700 px-6 py-4 text-base font-black text-white shadow-xl shadow-blue-700/30 transition-smooth hover:shadow-2xl hover:shadow-blue-600/40 hover:-translate-y-0.5 focus:outline-none focus:ring-4 focus:ring-blue-500/30 disabled:opacity-60 disabled:cursor-not-allowed">
                    <span x-show="!submitting" class="flex items-center justify-center gap-2">
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" />
                        </svg>
                        Enregistrer le nouveau mot de passe
                    </span>
                    <span x-show="submitting" x-cloak class="flex items-center justify-center gap-2">
                        <svg class="animate-spin h-5 w-5 text-white" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                        </svg>
                        Mise à jour en cours...
                    </span>
                </button>
            </form>
        </div>
    </div>

    {{-- ── Pied de page ── --}}
    <footer class="relative z-10 border-t border-slate-200/80 dark:border-slate-800/80 bg-white/70 dark:bg-slate-950/80 backdrop-blur-md py-4 px-4 sm:px-8 text-center text-xs text-slate-600 dark:text-slate-400">
        <p>© {{ date('Y') }} Université — Système Officiel UniRegistre.</p>
    </footer>
</div>
@endsection
