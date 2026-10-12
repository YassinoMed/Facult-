@extends('layouts.app')

@section('title', 'Nouveau support — Espace Enseignant')

@section('content')
    <div class="mx-auto max-w-3xl px-4 sm:px-6 py-8">
        <x-page-header titre="Nouveau support de cours" description="PDF, bureautique, texte, image ou ZIP — 20 Mo maximum, stockage privé."
            :fil="['Supports' => route('enseignant.ressources.index'), 'Nouveau' => '#']" />

        @if($errors->any())
            <div class="mt-4"><x-alert type="error" :message="$errors->first()" /></div>
        @endif

        <form method="POST" action="{{ route('enseignant.ressources.store') }}" enctype="multipart/form-data" class="mt-4 rounded-2xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 p-6 space-y-4 shadow-sm">
            @csrf
            <x-input id="titre" name="titre" label="Titre" required :value="old('titre')" />
            <x-textarea id="description" name="description" label="Description (optionnel)"> {{ old('description') }}</x-textarea>
            <x-select id="matiere_id" name="matiere_id" label="Matière (vos ECUE affectés)" required vide="Sélectionner…"
                :options="$matieres->pluck('nom', 'id')->map(fn ($nom, $id) => $matieres->firstWhere('id', $id)->code . ' — ' . $nom)" />
            <div>
                <label for="groupe_id" class="mb-1 block text-xs font-bold text-slate-700 uppercase tracking-wider">Groupe ciblé</label>
                <select id="groupe_id" name="groupe_id" class="w-full rounded-xl border px-3.5 py-2.5 text-sm text-slate-900 bg-white border-slate-300 focus:border-primary-700">
                    <option value="">Tous les groupes</option>
                    @foreach($groupes as $groupe)
                        <option value="{{ $groupe->id }}" @selected((string) old('groupe_id') === (string) $groupe->id)>{{ $groupe->code }} — {{ $groupe->nom }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="fichier" class="mb-1 block text-xs font-bold text-slate-700 uppercase tracking-wider">Fichier * <span class="text-rose-600" aria-hidden="true">*</span></label>
                <input id="fichier" type="file" name="fichier" required accept=".pdf,.doc,.docx,.ppt,.pptx,.xls,.xlsx,.txt,.md,.csv,.zip,.png,.jpg,.jpeg"
                    class="text-sm text-slate-700 dark:text-slate-300">
                <p class="mt-1 text-xs text-slate-500">pdf, doc(x), ppt(x), xls(x), txt, md, csv, zip, png, jpg — contenu vérifié serveur.</p>
            </div>
            <div class="flex gap-2">
                <x-btn type="submit">Enregistrer en brouillon</x-btn>
                <x-btn variant="secondary" href="{{ route('enseignant.ressources.index') }}">Annuler</x-btn>
            </div>
        </form>
    </div>
@endsection
