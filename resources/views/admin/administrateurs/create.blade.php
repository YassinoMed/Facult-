@extends('layouts.app')

@section('title', 'Ajouter un Administrateur')

@section('content')
<div class="mx-auto max-w-3xl px-4 py-8 sm:px-6 lg:px-8">

    <div class="mb-8">
        <a href="{{ route('admin.administrateurs.index') }}"
           class="inline-flex items-center gap-1.5 text-xs font-bold text-slate-500 hover:text-primary-600 transition-smooth">
            ← Retour aux administrateurs
        </a>
        <h1 class="mt-3 text-2xl sm:text-3xl font-black text-slate-900 tracking-tight">Ajouter un Administrateur</h1>
        <p class="mt-1 text-sm text-slate-500">
            Le rôle détermine le périmètre : <strong>admin_faculte</strong> est strictement limité à sa faculté,
            les autres rôles disposent d'un accès global selon la matrice RBAC.
        </p>
    </div>

    <form method="POST" action="{{ route('admin.administrateurs.store') }}"
          x-data="{ role: '{{ old('role') }}' }"
          class="rounded-3xl border border-slate-200/90 bg-white p-6 sm:p-8 shadow-sm space-y-5">
        @csrf

        @if($errors->any())
            <div class="rounded-2xl border border-rose-200 bg-rose-50/90 p-4">
                <h3 class="text-sm font-bold text-rose-900">Veuillez corriger les champs suivants :</h3>
                <ul class="mt-2 list-disc list-inside space-y-1 text-xs text-rose-700">
                    @foreach($errors->all() as $erreur)
                        <li>{{ $erreur }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        {{-- Nom --}}
        <div>
            <label for="name" class="mb-1.5 block text-xs font-bold text-slate-700 uppercase tracking-wider">Nom complet <span class="text-rose-500">*</span></label>
            <input type="text" id="name" name="name" value="{{ old('name') }}" required minlength="2" maxlength="120"
                   class="w-full rounded-xl border border-slate-200 bg-white px-3.5 py-2.5 text-sm text-slate-900 focus:border-primary-600 focus:ring-4 focus:ring-primary-500/15 focus:outline-none">
        </div>

        {{-- Email --}}
        <div>
            <label for="email" class="mb-1.5 block text-xs font-bold text-slate-700 uppercase tracking-wider">Email professionnel <span class="text-rose-500">*</span></label>
            <input type="email" id="email" name="email" value="{{ old('email') }}" required maxlength="254"
                   class="w-full rounded-xl border border-slate-200 bg-white px-3.5 py-2.5 text-sm text-slate-900 focus:border-primary-600 focus:ring-4 focus:ring-primary-500/15 focus:outline-none">
        </div>

        {{-- Rôle --}}
        <div>
            <label for="role" class="mb-1.5 block text-xs font-bold text-slate-700 uppercase tracking-wider">Rôle RBAC <span class="text-rose-500">*</span></label>
            <select id="role" name="role" required x-model="role"
                    class="w-full rounded-xl border border-slate-200 bg-white px-3.5 py-2.5 text-sm text-slate-900 focus:border-primary-600 focus:ring-4 focus:ring-primary-500/15 focus:outline-none">
                <option value="">— Sélectionnez un rôle —</option>
                @foreach([
                    'super_admin' => 'Super Administrateur (toutes facultés)',
                    'admin_scolarite' => 'Administrateur Scolarité (global)',
                    'agent_scolarite' => 'Agent Scolarité (global)',
                    'consultation' => 'Consultation (global, lecture seule)',
                    'admin_faculte' => 'Administrateur de Faculté (périmètre restreint)',
                ] as $valeur => $libelle)
                    <option value="{{ $valeur }}">{{ $libelle }}</option>
                @endforeach
            </select>
        </div>

        {{-- Faculté (uniquement visible pour admin_faculte) --}}
        <div x-show="role === 'admin_faculte'" x-cloak>
            <label for="faculte_id" class="mb-1.5 block text-xs font-bold text-slate-700 uppercase tracking-wider">
                Faculté de rattachement <span class="text-rose-500">*</span>
            </label>
            <select id="faculte_id" name="faculte_id"
                    class="w-full rounded-xl border border-slate-200 bg-white px-3.5 py-2.5 text-sm text-slate-900 focus:border-primary-600 focus:ring-4 focus:ring-primary-500/15 focus:outline-none">
                <option value="">— Sélectionnez la faculté —</option>
                @foreach($facultes as $faculte)
                    <option value="{{ $faculte->id }}" {{ old('faculte_id') == $faculte->id ? 'selected' : '' }}>
                        {{ $faculte->nom }} ({{ $faculte->code }})
                    </option>
                @endforeach
            </select>
            <p class="mt-1.5 text-xs text-slate-400">
                Cet administrateur ne verra QUE les étudiants, dossiers, notes et documents de cette faculté.
            </p>
        </div>

        {{-- Mot de passe --}}
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
                <label for="password" class="mb-1.5 block text-xs font-bold text-slate-700 uppercase tracking-wider">Mot de passe <span class="text-rose-500">*</span></label>
                <input type="password" id="password" name="password" required minlength="10" autocomplete="new-password"
                       class="w-full rounded-xl border border-slate-200 bg-white px-3.5 py-2.5 text-sm text-slate-900 focus:border-primary-600 focus:ring-4 focus:ring-primary-500/15 focus:outline-none">
            </div>
            <div>
                <label for="password_confirmation" class="mb-1.5 block text-xs font-bold text-slate-700 uppercase tracking-wider">Confirmation <span class="text-rose-500">*</span></label>
                <input type="password" id="password_confirmation" name="password_confirmation" required minlength="10" autocomplete="new-password"
                       class="w-full rounded-xl border border-slate-200 bg-white px-3.5 py-2.5 text-sm text-slate-900 focus:border-primary-600 focus:ring-4 focus:ring-primary-500/15 focus:outline-none">
            </div>
        </div>
        <p class="text-xs text-slate-400">10 caractères minimum, au moins une lettre et un chiffre. Stocké exclusivement sous forme de hash bcrypt.</p>

        <button type="submit"
                class="w-full rounded-2xl bg-gradient-to-r from-primary-600 via-primary-700 to-accent-600 px-6 py-3.5 text-sm font-extrabold text-white shadow-xl shadow-primary-600/30 hover:-translate-y-0.5 transition-smooth">
            Créer le compte administrateur
        </button>
    </form>
</div>
@endsection
