@extends('layouts.app')

@section('auth_layout', true)
@section('title', 'Mot de passe oublié — ' . $espace)
@section('meta_description', 'Recevez un lien sécurisé pour réinitialiser votre mot de passe.')

@section('content')
    <div class="min-h-screen bg-slate-50 dark:bg-slate-950 px-4 py-12 text-slate-800 dark:text-slate-100">
        <div class="mx-auto w-full max-w-md">
            <div class="rounded-3xl border border-slate-200 bg-white p-6 shadow-xl dark:border-slate-800 dark:bg-slate-900 sm:p-9">
                <div class="mb-6 flex items-start justify-between gap-4">
                    <div>
                        <p class="text-xs font-bold uppercase tracking-wider text-indigo-600 dark:text-indigo-400">{{ $espace }}</p>
                        <h1 class="mt-1 text-2xl font-black tracking-tight">Mot de passe oublié</h1>
                        <p class="mt-1 text-sm text-slate-600 dark:text-slate-400">
                            Saisissez votre adresse e-mail. Le lien est valable 60 minutes et utilisable une seule fois.
                        </p>
                    </div>
                    <a href="{{ route($routeConnexion) }}" class="text-xs font-bold text-indigo-700 underline underline-offset-2 dark:text-indigo-300">
                        Connexion
                    </a>
                </div>

                @if(session('success'))
                    <div class="mb-5 rounded-2xl border border-emerald-300 bg-emerald-50 p-4 text-sm font-semibold text-emerald-800 dark:border-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-200" role="status">
                        {{ session('success') }}
                    </div>
                @endif

                <form method="POST" action="{{ route($prefixe.'.mot-de-passe.envoyer') }}" x-data="{ submitting: false }" @submit="submitting = true" class="space-y-5">
                    @csrf

                    <div>
                        <label for="email" class="mb-1.5 block text-xs font-extrabold uppercase tracking-wider text-slate-700 dark:text-slate-300">
                            Adresse e-mail <span class="text-rose-600">*</span>
                        </label>
                        <input id="email" name="email" type="email" required maxlength="255" autocomplete="email" autofocus
                               value="{{ old('email') }}"
                               aria-describedby="email-aide @error('email') email-erreur @enderror"
                               @error('email') aria-invalid="true" @enderror
                               class="w-full rounded-2xl border-2 border-slate-300 bg-slate-50 px-4 py-3 text-sm font-medium focus:border-indigo-600 focus:bg-white focus:outline-none focus:ring-4 focus:ring-indigo-500/15 dark:border-slate-700 dark:bg-slate-800 dark:text-white @error('email') border-rose-500 bg-rose-50/60 dark:bg-rose-950/30 @enderror">
                        <p id="email-aide" class="mt-1.5 text-xs text-slate-500 dark:text-slate-400">
                            Utilisez l’adresse enregistrée dans votre dossier.
                        </p>
                        @error('email')
                            <p id="email-erreur" class="mt-1.5 text-xs font-semibold text-rose-600 dark:text-rose-400" role="alert">{{ $message }}</p>
                        @enderror
                    </div>

                    <button type="submit" :disabled="submitting"
                            class="w-full rounded-2xl bg-indigo-700 px-5 py-3.5 text-sm font-black text-white shadow-lg shadow-indigo-700/25 transition hover:bg-indigo-800 focus:outline-none focus:ring-4 focus:ring-indigo-500/30 disabled:opacity-60">
                        <span x-show="!submitting">Envoyer le lien de réinitialisation</span>
                        <span x-show="submitting" x-cloak>Envoi en cours…</span>
                    </button>
                </form>

                <p class="mt-5 border-t border-slate-200 pt-4 text-center text-xs text-slate-500 dark:border-slate-800 dark:text-slate-400">
                    <a href="{{ route($routeConnexion) }}" class="font-bold text-indigo-700 underline underline-offset-2 dark:text-indigo-300">← Retour à la connexion</a>
                </p>
            </div>
        </div>
    </div>
@endsection
