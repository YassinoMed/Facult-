@extends('layouts.app')

@section('title', 'Nouveau Dossier Académique — Scolarité')
@section('meta_description', 'Création d\'un dossier académique pour un étudiant inscrit.')

@section('content')
<div class="mx-auto max-w-3xl px-4 sm:px-6 lg:px-8 py-10 sm:py-14">

    <div class="mb-8">
        <a href="{{ route('admin.archives.index') }}"
           class="inline-flex items-center gap-1.5 text-xs font-bold text-slate-500 hover:text-primary-600 transition-smooth">
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2.2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5 3 12m0 0 7.5-7.5M3 12h18" /></svg>
            Retour au registre
        </a>
        <h1 class="mt-3 text-2xl sm:text-3xl font-black tracking-tight text-slate-900">Nouveau dossier académique</h1>
        <p class="mt-2 text-sm text-slate-500">Sélectionnez un étudiant inscrit qui ne possède pas encore de dossier. Le matricule est généré automatiquement si vous n'en définissez pas.</p>
    </div>

    <div class="rounded-3xl border border-slate-200/90 bg-white p-6 sm:p-10 shadow-xl shadow-slate-200/50">
        @if($etudiantsSansDossier->isEmpty())
            <div class="text-center py-10 space-y-3">
                <svg class="mx-auto h-12 w-12 text-slate-300" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 21v-8.25M15.75 21v-8.25M8.25 21v-8.25M3 9l9-6 9 6m-1.5 12V10.332A48.36 48.36 0 0 0 12 9.75c-2.551 0-5.056.2-7.5.582V21M3 21h18" />
                </svg>
                <p class="text-sm font-semibold text-slate-700">Tous les étudiants inscrits possèdent déjà un dossier académique.</p>
                <a href="{{ route('admin.archives.index') }}" class="inline-block text-xs font-bold text-primary-600 hover:underline">Voir le registre des archives →</a>
            </div>
        @else
            <form method="POST" action="{{ route('admin.archives.store') }}" class="space-y-5">
                @csrf

                <div>
                    <label for="etudiant_id" class="mb-1.5 block text-xs font-bold text-slate-700 uppercase tracking-wider">
                        Étudiant concerné <span class="text-rose-500">*</span>
                    </label>
                    <select id="etudiant_id" name="etudiant_id" required
                            class="w-full rounded-xl border border-slate-200 bg-white px-3.5 py-3 text-sm font-semibold text-slate-900 focus:border-primary-600 focus:ring-4 focus:ring-primary-500/15 focus:outline-none @error('etudiant_id') border-rose-300 bg-rose-50/50 @enderror">
                        <option value="">— Sélectionnez un étudiant —</option>
                        @foreach($etudiantsSansDossier as $etudiant)
                            <option value="{{ $etudiant->id }}" {{ old('etudiant_id') == $etudiant->id ? 'selected' : '' }}>
                                {{ $etudiant->nom_complet }} — {{ $etudiant->cin }} ({{ $etudiant->faculte->code }} · {{ $etudiant->niveau }})
                            </option>
                        @endforeach
                    </select>
                    @error('etudiant_id')
                        <p class="mt-1 text-xs text-rose-600">{{ $message }}</p>
                    @enderror
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                    <div>
                        <label for="matricule" class="mb-1.5 block text-xs font-bold text-slate-700 uppercase tracking-wider">
                            Matricule (optionnel)
                        </label>
                        <input id="matricule" name="matricule" type="text"
                               value="{{ old('matricule') }}" placeholder="Auto : MAT-00001"
                               class="w-full rounded-xl border border-slate-200 bg-white px-3.5 py-2.5 font-mono text-sm uppercase text-slate-900 focus:border-primary-600 focus:ring-4 focus:ring-primary-500/15 focus:outline-none @error('matricule') border-rose-300 bg-rose-50/50 @enderror">
                        @error('matricule')
                            <p class="mt-1 text-xs text-rose-600">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <label for="date_archivage" class="mb-1.5 block text-xs font-bold text-slate-700 uppercase tracking-wider">
                            Date d'archivage (optionnel)
                        </label>
                        <input id="date_archivage" name="date_archivage" type="date"
                               value="{{ old('date_archivage', now()->toDateString()) }}"
                               class="w-full rounded-xl border border-slate-200 bg-white px-3.5 py-2.5 text-sm text-slate-900 focus:border-primary-600 focus:ring-4 focus:ring-primary-500/15 focus:outline-none @error('date_archivage') border-rose-300 bg-rose-50/50 @enderror">
                        @error('date_archivage')
                            <p class="mt-1 text-xs text-rose-600">{{ $message }}</p>
                        @enderror
                    </div>
                </div>

                <div>
                    <label for="observation" class="mb-1.5 block text-xs font-bold text-slate-700 uppercase tracking-wider">
                        Observation (optionnel)
                    </label>
                    <textarea id="observation" name="observation" rows="2"
                              placeholder="Contexte du dossier, remarques de la scolarité…"
                              class="w-full rounded-xl border border-slate-200 bg-white px-3.5 py-2.5 text-sm text-slate-900 focus:border-primary-600 focus:ring-4 focus:ring-primary-500/15 focus:outline-none resize-none @error('observation') border-rose-300 bg-rose-50/50 @enderror">{{ old('observation') }}</textarea>
                    @error('observation')
                        <p class="mt-1 text-xs text-rose-600">{{ $message }}</p>
                    @enderror
                </div>

                <div class="flex items-center justify-end gap-3 pt-4 border-t border-slate-100">
                    <a href="{{ route('admin.archives.index') }}"
                       class="rounded-xl border border-slate-200 px-5 py-3 text-sm font-bold text-slate-600 hover:bg-slate-50 transition-smooth">Annuler</a>
                    <button type="submit"
                            class="inline-flex items-center gap-2 rounded-xl bg-gradient-to-r from-primary-600 to-accent-600 px-6 py-3 text-sm font-extrabold text-white shadow-lg shadow-primary-500/25 hover:-translate-y-0.5 transition-smooth">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2.2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" /></svg>
                        Créer le dossier académique
                    </button>
                </div>
            </form>
        @endif
    </div>
</div>
@endsection
