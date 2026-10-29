@extends('layouts.app')

@section('auth_layout', true)
@section('title', 'Nouveau mot de passe — ' . $espace)
@section('meta_description', 'Définissez un nouveau mot de passe pour votre compte.')

@section('content')
    <div class="min-h-screen bg-slate-50 dark:bg-slate-950 px-4 py-12 text-slate-800 dark:text-slate-100">
        <div class="mx-auto w-full max-w-md">
            <div class="rounded-3xl border border-slate-200 bg-white p-6 shadow-xl dark:border-slate-800 dark:bg-slate-900 sm:p-9">
                <div class="mb-6">
                    <p class="text-xs font-bold uppercase tracking-wider text-indigo-600 dark:text-indigo-400">{{ $espace }}</p>
                    <h1 class="mt-1 text-2xl font-black tracking-tight">Nouveau mot de passe</h1>
                    <p class="mt-1 text-sm text-slate-600 dark:text-slate-400">
                        Ce lien est à usage unique. Choisissez un mot de passe robuste.
                    </p>
                </div>

                @if($errors->any())
                    <div class="mb-5 rounded-2xl border border-rose-300 bg-rose-50 p-4 text-sm font-semibold text-rose-800 dark:border-rose-800 dark:bg-rose-950/40 dark:text-rose-200" role="alert">
                        {{ $errors->first() }}
                    </div>
                @endif

                <form method="POST" action="{{ route($prefixe.'.mot-de-passe.enregistrer') }}" x-data="{ submitting: false }" @submit="submitting = true" class="space-y-5">
                    @csrf
                    <input type="hidden" name="token" value="{{ $token }}">

                    <div>
                        <label for="email" class="mb-1.5 block text-xs font-extrabold uppercase tracking-wider text-slate-700 dark:text-slate-300">
                            Adresse e-mail <span class="text-rose-600">*</span>
                        </label>
                        <input id="email" name="email" type="email" required maxlength="255" autocomplete="email"
                               value="{{ old('email', $email) }}"
                               @error('email') aria-invalid="true" aria-describedby="email-erreur" @enderror
                               class="w-full rounded-2xl border-2 border-slate-300 bg-slate-50 px-4 py-3 text-sm font-medium focus:border-indigo-600 focus:bg-white focus:outline-none focus:ring-4 focus:ring-indigo-500/15 dark:border-slate-700 dark:bg-slate-800 dark:text-white @error('email') border-rose-500 bg-rose-50/60 dark:bg-rose-950/30 @enderror">
                        @error('email')
                            <p id="email-erreur" class="mt-1.5 text-xs font-semibold text-rose-600 dark:text-rose-400" role="alert">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <label for="password" class="mb-1.5 block text-xs font-extrabold uppercase tracking-wider text-slate-700 dark:text-slate-300">
                            Nouveau mot de passe <span class="text-rose-600">*</span>
                        </label>
                        <input id="password" name="password" type="password" required autocomplete="new-password"
                               aria-describedby="password-aide @error('password') password-erreur @enderror"
                               @error('password') aria-invalid="true" @enderror
                               class="w-full rounded-2xl border-2 border-slate-300 bg-slate-50 px-4 py-3 text-sm font-medium focus:border-indigo-600 focus:bg-white focus:outline-none focus:ring-4 focus:ring-indigo-500/15 dark:border-slate-700 dark:bg-slate-800 dark:text-white @error('password') border-rose-500 bg-rose-50/60 dark:bg-rose-950/30 @enderror">
                        <p id="password-aide" class="mt-1.5 text-xs text-slate-500 dark:text-slate-400">
                            Minimum 10 caractères, avec lettres et chiffres.
                        </p>
                        @error('password')
                            <p id="password-erreur" class="mt-1.5 text-xs font-semibold text-rose-600 dark:text-rose-400" role="alert">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <label for="password_confirmation" class="mb-1.5 block text-xs font-extrabold uppercase tracking-wider text-slate-700 dark:text-slate-300">
                            Confirmer le mot de passe <span class="text-rose-600">*</span>
                        </label>
                        <input id="password_confirmation" name="password_confirmation" type="password" required autocomplete="new-password"
                               class="w-full rounded-2xl border-2 border-slate-300 bg-slate-50 px-4 py-3 text-sm font-medium focus:border-indigo-600 focus:bg-white focus:outline-none focus:ring-4 focus:ring-indigo-500/15 dark:border-slate-700 dark:bg-slate-800 dark:text-white">
                    </div>

                    <button type="submit" :disabled="submitting"
                            class="w-full rounded-2xl bg-indigo-700 px-5 py-3.5 text-sm font-black text-white shadow-lg shadow-indigo-700/25 transition hover:bg-indigo-800 focus:outline-none focus:ring-4 focus:ring-indigo-500/30 disabled:opacity-60">
                        <span x-show="!submitting">Enregistrer le nouveau mot de passe</span>
                        <span x-show="submitting" x-cloak>Enregistrement…</span>
                    </button>
                </form>
            </div>
        </div>
    </div>
@endsection
