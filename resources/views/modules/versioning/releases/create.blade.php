@extends('layouts.app')

@section('title', 'Préparer une nouvelle release')

@section('content')
<div class="mx-auto max-w-4xl px-4 sm:px-6 lg:px-8 py-8 sm:py-10">

    <div class="flex items-center gap-2 text-xs font-semibold text-slate-500 mb-2">
        <a href="{{ route('admin.versioning.index') }}" class="hover:underline">Versions</a>
        <span>/</span>
        <a href="{{ route('admin.versioning.releases.index') }}" class="hover:underline">Releases</a>
        <span>/</span>
        <span class="text-slate-800">Nouvelle</span>
    </div>

    <div>
        <h1 class="text-2xl sm:text-3xl font-black tracking-tight text-slate-900">
            Préparer une nouvelle release
        </h1>
        <p class="mt-1 text-sm text-slate-500">
            Création d'un numéro de version sémantique et génération du lot applicatif cohérent.
        </p>
    </div>

    @if(session('erreur'))
        <div class="mt-6 rounded-xl bg-rose-50 border border-rose-200 p-4 text-sm font-medium text-rose-800">
            {{ session('erreur') }}
        </div>
    @endif

    <form method="POST" action="{{ route('admin.versioning.releases.store') }}" class="mt-8 space-y-6">
        @csrf

        {{-- Suggestions de version --}}
        <div class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
            <h2 class="text-base font-bold text-slate-900 mb-1">Numéro de version sémantique (SemVer)</h2>
            <p class="text-xs text-slate-500 mb-4">Version actuelle installée : <span class="font-mono font-bold text-slate-800">{{ $appActuelle }}</span></p>

            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 mb-4">
                <button type="button" onclick="document.getElementById('version').value = '{{ $suggestions['patch'] }}'; document.getElementById('app_version').value = '{{ $suggestions['patch'] }}';"
                        class="rounded-xl border border-slate-200 p-3 text-left hover:border-primary-600 hover:bg-primary-50/30 transition">
                    <span class="text-[11px] font-bold uppercase text-slate-400">Correctif (Patch)</span>
                    <p class="font-mono text-lg font-bold text-slate-800">{{ $suggestions['patch'] }}</p>
                    <span class="text-[11px] text-slate-500">Bugs & ajustements</span>
                </button>

                <button type="button" onclick="document.getElementById('version').value = '{{ $suggestions['minor'] }}'; document.getElementById('app_version').value = '{{ $suggestions['minor'] }}';"
                        class="rounded-xl border border-slate-200 p-3 text-left hover:border-primary-600 hover:bg-primary-50/30 transition">
                    <span class="text-[11px] font-bold uppercase text-slate-400">Mineure</span>
                    <p class="font-mono text-lg font-bold text-slate-800">{{ $suggestions['minor'] }}</p>
                    <span class="text-[11px] text-slate-500">Nouvelles fonctions</span>
                </button>

                <button type="button" onclick="document.getElementById('version').value = '{{ $suggestions['major'] }}'; document.getElementById('app_version').value = '{{ $suggestions['major'] }}';"
                        class="rounded-xl border border-slate-200 p-3 text-left hover:border-primary-600 hover:bg-primary-50/30 transition">
                    <span class="text-[11px] font-bold uppercase text-slate-400">Majeure</span>
                    <p class="font-mono text-lg font-bold text-slate-800">{{ $suggestions['major'] }}</p>
                    <span class="text-[11px] text-slate-500">Refonte structurelle</span>
                </button>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label for="version" class="mb-1 block text-xs font-bold text-slate-700">Version de la release *</label>
                    <input type="text" id="version" name="version" value="{{ old('version', $suggestions['patch']) }}" required
                           class="w-full rounded-xl border border-slate-300 px-3.5 py-2.5 font-mono text-sm focus:border-primary-700 focus:outline-none">
                    @error('version') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="environnement" class="mb-1 block text-xs font-bold text-slate-700">Environnement cible *</label>
                    <select id="environnement" name="environnement" class="w-full rounded-xl border border-slate-300 px-3.5 py-2.5 text-sm focus:border-primary-700 focus:outline-none">
                        <option value="local" @selected(old('environnement', config('versioning.environnement')) === 'local')>Local</option>
                        <option value="recette" @selected(old('environnement') === 'recette')>Recette</option>
                        <option value="production" @selected(old('environnement') === 'production')>Production</option>
                    </select>
                </div>
            </div>
        </div>

        {{-- Synchronisation des composants --}}
        <div class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
            <h2 class="text-base font-bold text-slate-900 mb-4">Composants de la release</h2>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label for="app_version" class="mb-1 block text-xs font-bold text-slate-700">Version APP *</label>
                    <input type="text" id="app_version" name="app_version" value="{{ old('app_version', $suggestions['patch']) }}" required
                           class="w-full rounded-xl border border-slate-300 px-3.5 py-2.5 font-mono text-sm focus:border-primary-700 focus:outline-none">
                </div>
                <div>
                    <label for="db_version" class="mb-1 block text-xs font-bold text-slate-700">DB Release (Logique SemVer) *</label>
                    <input type="text" id="db_version" name="db_version" value="{{ old('db_version', $dbRelease ?? $dbActuelle) }}" required
                           class="w-full rounded-xl border border-slate-300 px-3.5 py-2.5 font-mono text-sm focus:border-primary-700 focus:outline-none">
                    <p class="mt-1 text-[11px] text-slate-500">Schéma technique : {{ $dbSchemaTexte ?? 'Migrations appliquées' }}</p>
                </div>
                <div>
                    <label for="dev_version" class="mb-1 block text-xs font-bold text-slate-700">Version DEV (Optionnel)</label>
                    <input type="text" id="dev_version" name="dev_version" value="{{ old('dev_version', $suggestions['patch'].'-dev.1') }}"
                           class="w-full rounded-xl border border-slate-300 px-3.5 py-2.5 font-mono text-sm focus:border-primary-700 focus:outline-none">
                </div>
                <div>
                    <label for="prod_version" class="mb-1 block text-xs font-bold text-slate-700">Version PROD cible (Optionnel)</label>
                    <input type="text" id="prod_version" name="prod_version" value="{{ old('prod_version', $suggestions['patch']) }}"
                           class="w-full rounded-xl border border-slate-300 px-3.5 py-2.5 font-mono text-sm focus:border-primary-700 focus:outline-none">
                </div>
            </div>
        </div>

        {{-- Notes et changelog --}}
        <div class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
            <h2 class="text-base font-bold text-slate-900 mb-1">Notes de release & Changelog</h2>
            <p class="text-xs text-slate-500 mb-3">Détail des nouveautés, correctifs et consignes d'exploitation.</p>

            <textarea id="notes" name="notes" rows="4" aria-label="Notes de release et changelog" placeholder="Ex : Correction des calculs de moyennes LMD, optimisation des requêtes de présences, ajout des exports PDF..."
                      class="w-full rounded-xl border border-slate-300 p-3.5 text-sm focus:border-primary-700 focus:outline-none">{{ old('notes') }}</textarea>
        </div>

        <div class="flex items-center justify-end gap-3">
            <a href="{{ route('admin.versioning.releases.index') }}" class="rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 hover:bg-slate-50">
                Annuler
            </a>
            <button type="submit" class="rounded-xl bg-primary-800 px-5 py-2.5 text-sm font-bold text-white shadow-sm hover:bg-primary-700">
                Créer la release (Brouillon)
            </button>
        </div>
    </form>

</div>
@endsection
