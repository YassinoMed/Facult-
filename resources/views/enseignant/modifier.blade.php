@extends('layouts.app')

@section('title', 'Modifier mes coordonnées — Espace Enseignant')

@section('content')
    <div class="mx-auto max-w-2xl px-4 sm:px-6 py-6">
        <x-page-header titre="Modifier mes coordonnées" description="Seuls l'email professionnel, le téléphone et le bureau sont modifiables."
            :fil="['Mon profil' => route('enseignant.profil.show'), 'Modifier' => '#']" />

        @if($errors->any())
            <div class="mt-4"><x-alert type="error" titre="Veuillez corriger les champs suivants.">
                <ul class="list-disc list-inside mt-1">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
            </x-alert></div>
        @endif

        <form method="POST" action="{{ route('enseignant.profil.update') }}" class="mt-6 rounded-2xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 p-6 space-y-4 shadow-sm">
            @csrf @method('PATCH')
            <x-input id="email" name="email" type="email" label="Email professionnel" required value="{{ old('email', $enseignant->email) }}" />
            <p class="-mt-2 text-xs text-slate-500 dark:text-slate-400">
                L'email sert d'identifiant de connexion : le changer exige votre mot de passe actuel (champ ci-dessous).
            </p>
            <x-input id="telephone" name="telephone" label="Téléphone (mobile ou fixe professionnel)" value="{{ old('telephone', $enseignant->telephone) }}" />
            <x-input id="bureau" name="bureau" label="Bureau (ex. Bâtiment B, Bureau 204)" value="{{ old('bureau', $enseignant->bureau) }}" />
            <x-input id="current_password" name="current_password" type="password" label="Mot de passe actuel (obligatoire pour changer l'email)" autocomplete="current-password" value="" />
            <p class="-mt-2 text-xs text-slate-500 dark:text-slate-400">
                Laissez ce champ vide si vous ne modifiez pas l'adresse email.
            </p>
            <div class="pt-2 flex flex-col sm:flex-row gap-2">
                <x-btn type="submit">Enregistrer les modifications</x-btn>
                <x-btn variant="secondary" href="{{ route('enseignant.profil.show') }}">Annuler</x-btn>
            </div>
        </form>

        <form method="POST" action="{{ route('enseignant.profil.password') }}" class="mt-6 rounded-2xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 p-6 space-y-4 shadow-sm">
            @csrf @method('PATCH')
            <h3 class="text-sm font-black text-slate-900 dark:text-slate-100">Changer mon mot de passe</h3>
            <x-input id="current_password" name="current_password" type="password" label="Mot de passe actuel" required autocomplete="current-password" value="" />
            <x-input id="password" name="password" type="password" label="Nouveau mot de passe (10 caractères min, lettres et chiffres)" required autocomplete="new-password" value="" />
            <x-input id="password_confirmation" name="password_confirmation" type="password" label="Confirmation du nouveau mot de passe" required autocomplete="new-password" value="" />
            <div class="pt-2 flex flex-col sm:flex-row gap-2">
                <x-btn type="submit">Mettre à jour le mot de passe</x-btn>
            </div>
        </form>
    </div>
@endsection
