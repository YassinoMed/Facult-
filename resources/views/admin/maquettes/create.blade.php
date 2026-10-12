@extends('layouts.app')

@section('title', 'Nouvelle maquette — Scolarité')

@section('content')
    <div class="mx-auto max-w-3xl px-4 sm:px-6 py-8">
        <x-page-header titre="Nouvelle maquette" description="Saisie directe ou fichier (.html, .txt, .md, .csv, .docx, .odt, .rtf — 200 Ko max, texte extrait, aucun code exécutable)."
            :fil="['Maquettes' => route('admin.maquettes.index'), 'Nouvelle' => '#']" />

        <form method="POST" action="{{ route('admin.maquettes.store') }}" enctype="multipart/form-data" class="mt-4 ds-card p-5 space-y-4">
            @csrf
            <x-select id="type-maquette" label="Type de document" name="type" :options="collect($types)->mapWithKeys(fn ($def, $code) => [$code => $def['libelle']])->all()" vide="— Choisir —" required />
            <x-input id="nom-maquette" label="Nom" name="nom" placeholder="Ex. Attestation de scolarité — modèle FST" required />
            <div class="grid sm:grid-cols-3 gap-4">
                <x-select id="locale-maquette" label="Langue" name="locale" :options="['fr' => 'Français', 'ar' => 'Arabe', 'bilingue' => 'Bilingue']" :valeur="'fr'" />
                <x-select id="format-maquette" label="Format" name="format" :options="['A4' => 'A4']" :valeur="'A4'" />
                <x-select id="orientation-maquette" label="Orientation" name="orientation" :options="['portrait' => 'Portrait', 'paysage' => 'Paysage']" :valeur="'portrait'" />
            </div>
            <x-select id="faculte-maquette" label="Faculté (vide = toutes)" name="faculte_id" :options="$facultes->pluck('code', 'id')->all()" vide="Toutes facultés" />
            <div class="grid sm:grid-cols-2 gap-4">
                <x-input id="valide-du" label="Valide du" name="valide_du" type="date" />
                <x-input id="valide-au" label="Valide au" name="valide_au" type="date" />
            </div>
            <x-textarea id="contenu-maquette" label="Contenu initial (variables entre accolades)" name="contenu" rows="10" placeholder="Exemple : &lt;h1&gt;Titre du document&lt;/h1&gt;" />
            <div>
                <label for="fichier-maquette" class="mb-1 block text-xs font-bold text-slate-700 uppercase tracking-wider">…ou fichier modèle</label>
                <input id="fichier-maquette" type="file" name="fichier" accept=".html,.txt,.md,.csv,.docx,.odt,.rtf"
                    class="w-full rounded-xl border border-slate-200 bg-white px-3.5 py-2.5 text-sm text-slate-600 file:mr-2 file:rounded-lg file:border-0 file:bg-slate-900 file:px-2.5 file:py-1.5 file:text-[11px] file:font-bold file:text-white">
                <p class="mt-1 text-[11px] text-slate-400">Prioritaire sur la zone de texte. Word/RTF : texte extrait, mise en forme non conservée. PDF et Word binaire (.doc) refusés (illisibles sans perte).</p>
                @error('fichier')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror
            </div>
            <x-textarea id="schema-maquette" label="Schéma des variables (JSON, optionnel)" name="schema" rows="3" placeholder='{"etudiant.nom": "Nom de l’étudiant"}' />
            @error('contenu')<p class="text-xs text-rose-600">{{ $message }}</p>@enderror
            <div class="flex gap-2">
                <x-btn type="submit">Créer (v1, brouillon)</x-btn>
                <x-btn href="{{ route('admin.maquettes.index') }}" variant="secondary">Annuler</x-btn>
            </div>
        </form>
    </div>
@endsection
