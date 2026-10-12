@extends('layouts.app')

@section('title', 'Importer ' . $definition->libelle() . ' — Scolarité')

@section('content')
    <div class="mx-auto max-w-3xl px-4 sm:px-6 py-8">
        <x-page-header :titre="'Importer : ' . $definition->libelle()" description="Analyse, validation, prévisualisation puis confirmation explicite."
            :fil="['Données' => route('admin.donnees.hub'), 'Import' => '#']" />

        <div class="mt-4">
            <x-stepper :etapes="[['Fichier', false], ['Validation', false], ['Prévisualisation', false], ['Confirmation', false], ['Terminé', false]]" :courant="1" />
        </div>

        @if($errors->any())
            <div class="mt-4"><x-alert type="error" titre="Fichier refusé.">
                <ul class="list-disc list-inside mt-1">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
            </x-alert></div>
        @endif

        <form method="POST" action="{{ route('admin.donnees.import.preview', $entite) }}" enctype="multipart/form-data"
              x-data="{ fichier: null, envoi: false }" @submit="envoi = true"
              class="mt-4 ds-card p-6 space-y-4">
            @csrf
            <div>
                <span id="label-fichier" class="mb-1.5 block text-xs font-bold text-slate-700 uppercase tracking-wider">Fichier (CSV, XLSX, JSON, XML — 10 Mo max) <span class="text-rose-600">*</span></span>
                <label for="fichier" class="ds-dropzone flex cursor-pointer flex-col items-center justify-center gap-2 px-6 py-8 text-center"
                       @dragover.prevent @drop.prevent="if ($event.dataTransfer.files.length) { $refs.entree.files = $event.dataTransfer.files; fichier = $event.dataTransfer.files[0]; }">
                    <span class="text-sm font-bold text-slate-700">Déposez votre fichier ici</span>
                    <span class="text-xs text-slate-500">ou <span class="font-bold text-primary-800 underline underline-offset-2">parcourez vos fichiers</span></span>
                    <span class="text-[11px] text-slate-400">CSV · XLSX · JSON · XML</span>
                    <input x-ref="entree" type="file" id="fichier" name="fichier" required accept=".csv,.xlsx,.json,.xml"
                           aria-labelledby="label-fichier" class="sr-only" @change="fichier = $event.target.files[0]">
                </label>
                <p x-show="fichier" class="mt-2 rounded-xl border border-emerald-300 bg-emerald-50 px-3.5 py-2.5 text-xs font-bold text-emerald-900">
                    ✓ <span x-text="fichier?.name"></span>
                    <span x-show="fichier?.size" class="font-normal" x-text="' — ' + (fichier.size > 1048576 ? (fichier.size / 1048576).toFixed(1) + ' Mo' : Math.max(1, Math.round(fichier.size / 1024)) + ' Ko') + ' · ' + (fichier.size > 10485760 ? 'trop volumineux (max 10 Mo)' : 'prêt pour analyse')"></span>
                </p>
            </div>
            <div>
                <p class="mb-1 block text-xs font-bold text-slate-700 uppercase tracking-wider">Colonnes attendues</p>
                <p class="font-mono text-[11px] text-slate-600">{{ implode(', ', $definition->colonnes()) }}</p>
                <a href="{{ route('admin.donnees.modele', [$entite, 'csv']) }}" class="mt-1 inline-block text-[11px] font-bold text-primary-800 underline underline-offset-2">Télécharger le modèle CSV</a>
            </div>
            <x-btn type="submit" ::disabled="envoi">
                <span x-show="!envoi">Analyser le fichier</span>
                <span x-show="envoi">Analyse du fichier…</span>
            </x-btn>
        </form>
    </div>
@endsection
