@extends('layouts.app')

@section('auth_layout', true)
@section('title', 'Connexion — Espace Enseignant')

@section('content')
<div class="min-h-screen flex items-center justify-center bg-slate-50 dark:bg-slate-950 px-4 py-12">
    <div class="w-full max-w-md rounded-3xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 shadow-xl p-6 sm:p-8" x-data="{ showPassword: false, submitting: false }">
        <div class="flex items-center gap-3 pb-5 border-b border-slate-200 dark:border-slate-800">
            <div class="flex h-12 w-12 items-center justify-center rounded-2xl bg-gradient-to-br from-indigo-600 to-purple-600 text-white">
                <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M4.26 10.147a60.438 60.438 0 0 0-.491 6.347A48.62 48.62 0 0 1 12 20.904a48.62 48.62 0 0 1 8.232-4.41 60.46 60.46 0 0 0-.491-6.347m-15.482 0a50.636 50.636 0 0 0-2.658-.813A59.906 59.906 0 0 1 12 3.493a59.903 59.903 0 0 1 10.399 5.84c-.896.248-1.783.52-2.658.814m-15.482 0A50.717 50.717 0 0 1 12 13.489a50.702 50.702 0 0 1 7.74-3.342" /></svg>
            </div>
            <div>
                <span class="inline-block rounded-full bg-indigo-50 dark:bg-indigo-950/60 px-3 py-0.5 text-[11px] font-bold uppercase tracking-wider text-indigo-700 dark:text-indigo-300 border border-indigo-200 dark:border-indigo-800 mb-1">Accès Enseignant</span>
                <h1 class="text-2xl font-black tracking-tight text-slate-900 dark:text-white">Espace Enseignant</h1>
                <p class="text-xs text-slate-600 dark:text-slate-400 mt-0.5">Emploi du temps, surveillances, présences et documents.</p>
            </div>
        </div>

        @if($errors->any())
            <div class="mt-4"><x-alert type="error" :message="$errors->first()" /></div>
        @endif

        <form method="POST" action="{{ route('enseignant.connexion.login') }}" @submit="submitting = true" class="mt-5 space-y-4">
            @csrf
            <div>
                <label for="identifiant" class="block text-xs font-extrabold text-slate-800 dark:text-slate-200 uppercase tracking-wider">Email professionnel ou matricule <span class="text-rose-500">*</span></label>
                <input id="identifiant" name="identifiant" type="text" required autofocus autocomplete="username"
                       value="{{ old('identifiant') }}" placeholder="ex : prenom.nom@univ.ma"
                       class="mt-1.5 w-full rounded-2xl border-2 border-slate-300 dark:border-slate-700 bg-slate-50 dark:bg-slate-800 px-4 py-3 text-sm font-medium text-slate-900 dark:text-white placeholder-slate-400 focus:border-indigo-600 focus:outline-none">
            </div>
            <div>
                <label for="password" class="block text-xs font-extrabold text-slate-800 dark:text-slate-200 uppercase tracking-wider">Mot de passe <span class="text-rose-500">*</span></label>
                <div class="relative mt-1.5">
                    <input id="password" name="password" required autocomplete="current-password"
                           :type="showPassword ? 'text' : 'password'" placeholder="••••••••"
                           class="w-full rounded-2xl border-2 border-slate-300 dark:border-slate-700 bg-slate-50 dark:bg-slate-800 px-4 py-3 pr-11 text-sm font-medium text-slate-900 dark:text-white placeholder-slate-400 focus:border-indigo-600 focus:outline-none">
                    <button type="button" @click="showPassword = !showPassword"
                            :aria-label="showPassword ? 'Masquer le mot de passe' : 'Afficher le mot de passe'"
                            class="absolute inset-y-0 right-0 flex items-center pr-3.5 text-slate-400 hover:text-indigo-600 focus:outline-none">
                        <svg x-show="!showPassword" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.964-7.178Z" /><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" /></svg>
                        <svg x-show="showPassword" x-cloak class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3.98 8.223A10.477 10.477 0 0 0 1.934 12c1.292 4.338 5.31 7.5 10.066 7.5.993 0 1.953-.138 2.863-.395M6.228 6.228A10.451 10.451 0 0 1 12 4.5c4.756 0 8.773 3.162 10.065 7.498a10.523 10.523 0 0 1-4.293 5.774M6.228 6.228 3 3m3.228 3.228 3.65 3.65m7.894 7.894L21 21m-3.228-3.228-3.65-3.65" /></svg>
                    </button>
                </div>
            </div>
            <button type="submit" :disabled="submitting"
                    class="w-full rounded-2xl bg-gradient-to-r from-indigo-600 to-purple-600 px-6 py-3.5 text-base font-black text-white shadow-lg hover:-translate-y-0.5 transition-transform focus:outline-none focus:ring-4 focus:ring-indigo-500/30 disabled:opacity-60">
                <span x-show="!submitting">Accéder à mon espace</span>
                <span x-show="submitting" x-cloak>Vérification…</span>
            </button>
        </form>

        <p class="mt-5 text-xs text-slate-600 dark:text-slate-400 leading-relaxed">
            Compte activé par votre scolarité.
            <a href="{{ route('enseignant.mot-de-passe.oublie') }}" class="font-bold text-indigo-700 dark:text-indigo-300 underline underline-offset-2">Mot de passe oublié ?</a>
            Sinon, contactez la scolarité de votre faculté.
        </p>
    </div>
</div>
@endsection
