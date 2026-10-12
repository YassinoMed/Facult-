@extends('layouts.app')

@section('auth_layout', true)
@section('title', 'Mot de passe oublié — Espace Scolarité')
@section('meta_description', 'Réinitialisez l\'accès à votre compte professionnel de l\'Espace Scolarité.')

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
             x-data="{ submitting: false }">

            <div class="absolute top-0 inset-x-0 h-1.5 bg-gradient-to-r from-blue-600 via-indigo-600 to-emerald-500"></div>

            <div class="flex items-start gap-4 pb-6 border-b border-slate-200 dark:border-slate-800">
                <div class="flex h-12 w-12 flex-shrink-0 items-center justify-center rounded-2xl bg-gradient-to-br from-blue-700 to-indigo-900 border border-blue-500/30 shadow-lg shadow-blue-900/30 text-white">
                    <svg class="h-6 w-6 text-blue-200" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 5.25a3 3 0 0 1 3 3m3 0a6 6 0 0 1-7.029 5.912c-.563-.097-1.159.263-1.343.818l-.5 1.402m-3.428-7.132A6 6 0 0 1 3.75 8.25a6 6 0 0 1 5.879-6 6 6 0 0 1 5.871 6c0 .318-.024.63-.07.937-.107.684-.81 1.09-1.497.882l-1.183-.357c-.5-.152-.985.211-1.185.683a2.25 2.25 0 0 1-1.544 1.344c-.243.061-.49.089-.738.089-.89 0-1.724-.27-2.437-.77a6.042 6.042 0 0 1-1.394-1.302 2.23 2.23 0 0 1 .666-3.383c.192-.12.382-.232.57-.347" />
                    </svg>
                </div>
                <div>
                    <h1 class="text-xl sm:text-2xl font-black tracking-tight text-slate-900 dark:text-white">Mot de passe oublié</h1>
                    <p class="text-xs text-slate-600 dark:text-slate-400 mt-0.5">Saisissez votre email professionnel. Le lien est valable 60 minutes.</p>
                </div>
            </div>

            @if(session('success'))
                <div class="mt-5 p-4 rounded-2xl bg-emerald-50 dark:bg-emerald-950/40 border-2 border-emerald-300 dark:border-emerald-700/60 text-emerald-800 dark:text-emerald-200 text-xs font-bold flex items-start gap-2.5">
                    <svg class="h-4 w-4 text-emerald-600 dark:text-emerald-400 flex-shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                    </svg>
                    <span>{{ session('success') }}</span>
                </div>
            @endif

            <form method="POST" action="{{ route('admin.mot-de-passe.envoyer') }}" @submit="submitting = true" class="mt-6 space-y-5">
                @csrf
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
                        <input id="email" type="email" name="email" required maxlength="255" autocomplete="email" autofocus
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

                <button type="submit" :disabled="submitting"
                        class="btn-shine relative w-full rounded-2xl bg-gradient-to-r from-blue-700 via-blue-600 to-indigo-700 px-6 py-4 text-base font-black text-white shadow-xl shadow-blue-700/30 transition-smooth hover:shadow-2xl hover:shadow-blue-600/40 hover:-translate-y-0.5 focus:outline-none focus:ring-4 focus:ring-blue-500/30 disabled:opacity-60 disabled:cursor-not-allowed">
                    <span x-show="!submitting" class="flex items-center justify-center gap-2">
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 12 3.269 3.125A59.769 59.769 0 0 1 21.485 12 59.768 59.768 0 0 1 3.27 20.875L5.999 12Zm0 0h7.5" />
                        </svg>
                        Envoyer le lien de réinitialisation
                    </span>
                    <span x-show="submitting" x-cloak class="flex items-center justify-center gap-2">
                        <svg class="animate-spin h-5 w-5 text-white" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                        </svg>
                        Envoi en cours...
                    </span>
                </button>
            </form>

            <div class="mt-6 pt-5 border-t border-slate-200 dark:border-slate-800 text-center">
                <a href="{{ route('admin.connexion.show') }}" class="text-xs font-bold text-blue-600 dark:text-blue-400 hover:text-blue-700 dark:hover:text-blue-300 hover:underline">
                    ← Retour au formulaire de connexion
                </a>
            </div>
        </div>
    </div>

    {{-- ── Pied de page ── --}}
    <footer class="relative z-10 border-t border-slate-200/80 dark:border-slate-800/80 bg-white/70 dark:bg-slate-950/80 backdrop-blur-md py-4 px-4 sm:px-8 text-center text-xs text-slate-600 dark:text-slate-400">
        <p>© {{ date('Y') }} Université — Système Officiel UniRegistre.</p>
    </footer>
</div>
@endsection
