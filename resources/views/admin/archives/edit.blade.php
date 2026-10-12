@extends('layouts.app')

@section('title', 'Gérer le Dossier — ' . $dossier->matricule)
@section('meta_description', 'Modification du statut et des observations du dossier académique.')

@section('content')
<div class="mx-auto max-w-2xl px-4 sm:px-6 lg:px-8 py-10 sm:py-14">

    <div class="mb-8">
        <a href="{{ route('admin.archives.show', $dossier) }}"
           class="inline-flex items-center gap-1.5 text-xs font-bold text-slate-500 hover:text-primary-600 transition-smooth">
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2.2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5 3 12m0 0 7.5-7.5M3 12h18" /></svg>
            Retour au dossier
        </a>
        <h1 class="mt-3 text-2xl sm:text-3xl font-black tracking-tight text-slate-900">Gestion du dossier</h1>
        <p class="mt-2 text-sm text-slate-500">
            <span class="font-mono font-bold text-slate-700">{{ $dossier->matricule }}</span>
            — {{ $dossier->etudiant->nom_complet }} ({{ $dossier->etudiant->faculte->code }}).
            Toute modification est journalisée dans l'historique du dossier.
        </p>
    </div>

    <div class="rounded-3xl border border-slate-200/90 bg-white p-6 sm:p-10 shadow-xl shadow-slate-200/50">
        <form method="POST" action="{{ route('admin.archives.update', $dossier) }}" class="space-y-5">
            @csrf
            @method('PATCH')

            <div>
                <label class="mb-1.5 block text-xs font-bold text-slate-700 uppercase tracking-wider">Statut du dossier <span class="text-rose-500">*</span></label>
                <div class="grid grid-cols-2 gap-3">
                    <label class="flex cursor-pointer items-center gap-2.5 rounded-xl border px-4 py-3 text-sm transition-smooth
                        {{ old('statut', $dossier->statut) === 'actif' ? 'border-emerald-400 bg-emerald-50 text-emerald-900 font-bold' : 'border-slate-200 text-slate-600 hover:border-slate-300' }}">
                        <input type="radio" name="statut" value="actif" class="sr-only" {{ old('statut', $dossier->statut) === 'actif' ? 'checked' : '' }}>
                        <span class="h-2.5 w-2.5 rounded-full {{ old('statut', $dossier->statut) === 'actif' ? 'bg-emerald-500' : 'bg-slate-300' }}"></span>
                        Actif — dossier vivant
                    </label>
                    <label class="flex cursor-pointer items-center gap-2.5 rounded-xl border px-4 py-3 text-sm transition-smooth
                        {{ old('statut', $dossier->statut) === 'clos' ? 'border-slate-500 bg-slate-100 text-slate-900 font-bold' : 'border-slate-200 text-slate-600 hover:border-slate-300' }}">
                        <input type="radio" name="statut" value="clos" class="sr-only" {{ old('statut', $dossier->statut) === 'clos' ? 'checked' : '' }}>
                        <span class="h-2.5 w-2.5 rounded-full {{ old('statut', $dossier->statut) === 'clos' ? 'bg-slate-500' : 'bg-slate-300' }}"></span>
                        Clos — parcours terminé
                    </label>
                </div>
                @error('statut')
                    <p class="mt-1 text-xs text-rose-600">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label for="observation" class="mb-1.5 block text-xs font-bold text-slate-700 uppercase tracking-wider">
                    Observation du dossier
                </label>
                <textarea id="observation" name="observation" rows="3"
                          placeholder="Remarques officielles de la scolarité…"
                          class="w-full rounded-xl border border-slate-200 bg-white px-3.5 py-2.5 text-sm text-slate-900 focus:border-primary-600 focus:ring-4 focus:ring-primary-500/15 focus:outline-none resize-none @error('observation') border-rose-300 bg-rose-50/50 @enderror">{{ old('observation', $dossier->observation) }}</textarea>
                @error('observation')
                    <p class="mt-1 text-xs text-rose-600">{{ $message }}</p>
                @enderror
            </div>

            <div class="flex items-center justify-end gap-3 pt-4 border-t border-slate-100">
                <a href="{{ route('admin.archives.show', $dossier) }}"
                   class="rounded-xl border border-slate-200 px-5 py-3 text-sm font-bold text-slate-600 hover:bg-slate-50 transition-smooth">Annuler</a>
                <button type="submit"
                        class="rounded-xl bg-slate-900 px-5 py-3 text-sm font-bold text-white hover:bg-slate-800 transition-smooth">
                    Enregistrer les modifications
                </button>
            </div>
        </form>
    </div>

    @can('delete', $dossier)
        <div class="mt-6 rounded-3xl border border-rose-200 bg-rose-50/50 p-6 sm:p-8" x-data="{ confirmer: false }">
            <h2 class="text-sm font-black uppercase tracking-wider text-rose-700">Zone dangereuse — suppression irréversible</h2>
            <p class="mt-1 text-xs text-rose-600">Le dossier, son parcours, ses notes et ses fichiers seront définitivement supprimés. Un motif est exigé et journalisé.</p>
            <form method="POST" action="{{ route('admin.archives.destroy', $dossier) }}" class="mt-4 space-y-3"
                  onsubmit="return confirmer && confirm('Supprimer définitivement le dossier {{ $dossier->matricule }} ?')">
                @csrf
                @method('DELETE')
                <div>
                    <label for="motif-suppression" class="mb-1.5 block text-xs font-bold text-rose-700 uppercase tracking-wider">Motif de suppression *</label>
                    <textarea id="motif-suppression" name="motif" rows="2" required minlength="10" maxlength="1000"
                              placeholder="Ex : doublon avec le dossier MAT-…, créé par erreur le…"
                              class="w-full rounded-xl border border-rose-200 bg-white px-3.5 py-2.5 text-sm text-slate-900 focus:border-rose-500 focus:ring-4 focus:ring-rose-500/15 focus:outline-none resize-none">{{ old('motif') }}</textarea>
                    @error('motif')
                        <p class="mt-1 text-xs text-rose-600">{{ $message }}</p>
                    @enderror
                </div>
                <label class="flex items-center gap-2 text-xs font-bold text-rose-700">
                    <input type="checkbox" x-model="confirmer" class="rounded border-rose-300">
                    Je confirme la suppression définitive
                </label>
                <button type="submit" :disabled="! confirmer"
                        class="rounded-xl bg-rose-600 px-5 py-3 text-sm font-bold text-white hover:bg-rose-700 transition-smooth disabled:cursor-not-allowed disabled:opacity-50">
                    Supprimer définitivement
                </button>
            </form>
        </div>
    @endcan
</div>
@endsection
