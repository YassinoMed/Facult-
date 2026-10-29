@extends('layouts.app')

@section('title', 'Modifier mes coordonnées — Espace Étudiant')
@section('meta_description', 'Mettez à jour vos coordonnées personnelles : téléphone, adresse complète et code postal. L\'email et la date de naissance sont vérifiés par la scolarité.')

@section('content')
<div class="mx-auto max-w-3xl px-4 sm:px-6 lg:px-8 py-10 sm:py-14">

    {{-- ── En-tête de page ── --}}
    <div class="mb-8">
        <a href="{{ route('etudiant.profil.show') }}"
           class="inline-flex items-center gap-1.5 text-xs font-bold text-slate-500 hover:text-primary-600 transition-smooth">
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2.2" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5 3 12m0 0 7.5-7.5M3 12h18" />
            </svg>
            Retour à mon profil
        </a>
        <h1 class="mt-3 text-2xl sm:text-3xl font-black tracking-tight text-slate-900">Modifier mes coordonnées</h1>
        <p class="mt-2 text-sm text-slate-500">
            Ces informations permettent au service de la scolarité de vous joindre pendant le traitement de votre dossier
            <span class="font-semibold text-slate-700">{{ $etudiant->num_inscription ?? session('num_inscription') }}</span>.
        </p>
    </div>

    {{-- ── Formulaire ── --}}
    <div class="rounded-3xl border border-slate-200/90 bg-white p-6 sm:p-10 shadow-xl shadow-primary-950/5">
        <form method="POST" action="{{ route('etudiant.profil.update') }}" class="space-y-5">
            @csrf
            @method('PATCH')

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                {{-- Email — lecture seule : changement via parcours vérifié (double code) --}}
                <div class="sm:col-span-2">
                    <label for="email" class="mb-1.5 block text-xs font-bold text-slate-700 uppercase tracking-wider">
                        Adresse email
                    </label>
                    <div class="relative">
                        <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3.5 text-slate-400">
                            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M21.75 6.75v10.5a2.25 2.25 0 0 1-2.25 2.25h-15a2.25 2.25 0 0 1-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25m19.5 0v.243a2.25 2.25 0 0 1-1.07 1.916l-7.5 4.615a2.25 2.25 0 0 1-2.36 0L3.32 8.91a2.25 2.25 0 0 1-1.07-1.916V6.75" />
                            </svg>
                        </div>
                        <input id="email" name="email" type="email" readonly aria-readonly="true"
                               autocomplete="email"
                               value="{{ $etudiant->email }}"
                               class="w-full rounded-xl border border-slate-200 bg-slate-50 px-3.5 py-2.5 pl-11 text-sm text-slate-600 cursor-not-allowed focus:outline-none @error('email') border-rose-300 bg-rose-50/50 @enderror">
                    </div>
                    @error('email')
                        <p class="mt-1 text-xs text-rose-600">{{ $message }}</p>
                    @enderror
                    <p class="mt-1.5 text-xs text-slate-400">
                        L'adresse email vous sert d'identifiant de connexion et de canal de récupération de votre dossier.
                        Pour la modifier, utilisez le parcours vérifié
                        <a href="{{ route('inscription.existant.accueil') }}" class="font-semibold text-primary-700 underline">« Modifier mon adresse email »</a>
                        (code de confirmation envoyé à l'ancienne puis à la nouvelle adresse).
                    </p>
                </div>

                {{-- Téléphone --}}
                <div>
                    <label for="telephone" class="mb-1.5 block text-xs font-bold text-slate-700 uppercase tracking-wider">
                        Téléphone
                    </label>
                    <div class="relative">
                        <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3.5 text-slate-400">
                            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 6.75c0 8.284 6.716 15 15 15h2.25a2.25 2.25 0 0 0 2.25-2.25v-1.372c0-.516-.351-.966-.852-1.091l-4.423-1.106c-.44-.11-.902.055-1.173.417l-.97 1.293c-.282.376-.769.542-1.21.38a12.035 12.035 0 0 1-7.143-7.143c-.162-.441.004-.928.38-1.21l1.293-.97c.363-.271.527-.734.417-1.173L6.963 3.102a1.125 1.125 0 0 0-1.091-.852H4.5A2.25 2.25 0 0 0 2.25 4.5v2.25Z" />
                            </svg>
                        </div>
                        <input id="telephone" name="telephone" type="tel"
                               value="{{ old('telephone', $etudiant->telephone) }}"
                               placeholder="+212 6 12 34 56 78"
                               class="w-full rounded-xl border border-slate-200 bg-white px-3.5 py-2.5 pl-11 text-sm text-slate-900 transition-smooth focus:border-primary-600 focus:ring-4 focus:ring-primary-500/15 focus:outline-none @error('telephone') border-rose-300 bg-rose-50/50 @enderror">
                    </div>
                    @error('telephone')
                        <p class="mt-1 text-xs text-rose-600">{{ $message }}</p>
                    @enderror
                </div>

                {{-- Adresse --}}
                <div class="sm:col-span-2">
                    <label for="adresse" class="mb-1.5 block text-xs font-bold text-slate-700 uppercase tracking-wider">
                        Adresse complète <span class="text-rose-500">*</span>
                    </label>
                    <div class="relative">
                        <div class="pointer-events-none absolute left-0 top-2.5 flex items-center pl-3.5 text-slate-400">
                            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="m2.25 12 8.954-8.955c.44-.439 1.152-.439 1.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75" />
                            </svg>
                        </div>
                        <textarea id="adresse" name="adresse" rows="2" required
                                  placeholder="N°, rue, quartier, résidence…"
                                  class="w-full rounded-xl border border-slate-200 bg-white px-3.5 py-2.5 pl-11 text-sm text-slate-900 transition-smooth focus:border-primary-600 focus:ring-4 focus:ring-primary-500/15 focus:outline-none resize-none @error('adresse') border-rose-300 bg-rose-50/50 @enderror">{{ old('adresse', $etudiant->adresse) }}</textarea>
                    </div>
                    @error('adresse')
                        <p class="mt-1 text-xs text-rose-600">{{ $message }}</p>
                    @enderror
                </div>

                {{-- Ville --}}
                <div>
                    <label for="ville" class="mb-1.5 block text-xs font-bold text-slate-700 uppercase tracking-wider">
                        Ville <span class="text-rose-500">*</span>
                    </label>
                    <div class="relative">
                        <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3.5 text-slate-400">
                            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 21h16.5M4.5 3h15M5.25 3v18m13.5-18v18M9 6.75h1.5m-1.5 3h1.5m-1.5 3h1.5m3-6H15m-1.5 3H15m-1.5 3H15M9 21v-3.375c0-.621.504-1.125 1.125-1.125h3.75c.621 0 1.125.504 1.125 1.125V21" />
                            </svg>
                        </div>
                        <input id="ville" name="ville" type="text" required
                               value="{{ old('ville', $etudiant->ville) }}"
                               placeholder="Casablanca"
                               class="w-full rounded-xl border border-slate-200 bg-white px-3.5 py-2.5 pl-11 text-sm text-slate-900 transition-smooth focus:border-primary-600 focus:ring-4 focus:ring-primary-500/15 focus:outline-none @error('ville') border-rose-300 bg-rose-50/50 @enderror">
                    </div>
                    @error('ville')
                        <p class="mt-1 text-xs text-rose-600">{{ $message }}</p>
                    @enderror
                </div>

                {{-- Code postal --}}
                <div>
                    <label for="code_postal" class="mb-1.5 block text-xs font-bold text-slate-700 uppercase tracking-wider">
                        Code postal <span class="text-rose-500">*</span>
                    </label>
                    <div class="relative">
                        <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3.5 text-slate-400">
                            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M15 10.5a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
                                <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1 1 15 0Z" />
                            </svg>
                        </div>
                        <input id="code_postal" name="code_postal" type="text" required
                               value="{{ old('code_postal', $etudiant->code_postal) }}"
                               placeholder="20000"
                               class="w-full rounded-xl border border-slate-200 bg-white px-3.5 py-2.5 pl-11 text-sm text-slate-900 transition-smooth focus:border-primary-600 focus:ring-4 focus:ring-primary-500/15 focus:outline-none @error('code_postal') border-rose-300 bg-rose-50/50 @enderror">
                    </div>
                    @error('code_postal')
                        <p class="mt-1 text-xs text-rose-600">{{ $message }}</p>
                    @enderror
                </div>
            </div>

            {{-- Champs non modifiables : rappel --}}
            <div class="flex items-start gap-2.5 rounded-xl border border-slate-100 bg-slate-50 px-3.5 py-3 text-xs text-slate-500">
                <svg class="mt-0.5 h-4 w-4 flex-shrink-0 text-slate-400" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 1 0-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 0 0 2.25-2.25v-6.75a2.25 2.25 0 0 0-2.25-2.25H6.75a2.25 2.25 0 0 0-2.25 2.25v6.75a2.25 2.25 0 0 0 2.25 2.25Z" />
                </svg>
                <p>
                    Le CIN, le numéro d'inscription, votre affectation universitaire et le statut du dossier ne sont pas modifiables en ligne.
                    Contactez la scolarité pour toute demande de correction.
                </p>
            </div>

            {{-- Actions --}}
            <div class="flex flex-col sm:flex-row items-stretch sm:items-center justify-end gap-3 pt-4 border-t border-slate-100">
                <a href="{{ route('etudiant.profil.show') }}"
                   class="inline-flex items-center justify-center gap-2 rounded-xl border border-slate-200 bg-white px-5 py-3 text-sm font-bold text-slate-600 hover:bg-slate-50 transition-smooth">
                    Annuler
                </a>
                <button type="submit"
                        class="inline-flex items-center justify-center gap-2 rounded-xl bg-gradient-to-r from-primary-600 to-accent-600 px-6 py-3 text-sm font-extrabold text-white shadow-lg shadow-primary-500/25 hover:shadow-xl hover:shadow-primary-500/35 hover:-translate-y-0.5 transition-smooth">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2.2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                    </svg>
                    Enregistrer les modifications
                </button>
            </div>
        </form>
    </div>
</div>
@endsection
