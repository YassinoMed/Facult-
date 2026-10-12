@extends('layouts.app')

@section('title', 'Import emploi du temps — Scolarité')

@section('content')
    <div class="mx-auto max-w-3xl px-4 sm:px-6 py-8 sm:py-12">
        <x-page-header titre="Import emploi du temps" description="CSV, JSON ou XML → prévisualisation → confirmation explicite. Aucun import direct."
            :fil="['Emplois du temps' => route('admin.emplois-du-temps.index'), 'Import' => '#']" />

        <div class="mt-4">
            <x-stepper :etapes="[['Fichier', false], ['Validation', false], ['Prévisualisation', false], ['Confirmation', false], ['Terminé', false]]" :courant="1" />
        </div>

        @if($errors->any())
            <div class="mt-4">
                <x-alert type="error" titre="Impossible d'analyser le fichier.">
                    <ul class="list-disc list-inside mt-1">
                        @foreach($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </x-alert>
            </div>
        @endif

        <form method="POST" action="{{ route('admin.emplois-du-temps.import.preview') }}" enctype="multipart/form-data"
              x-data="{ fichier: null, envoi: false, glisser: false }"
              @submit="envoi = true"
              class="mt-6 ds-card p-6 space-y-4">
            @csrf

            <div>
                <span id="label-fichier" class="mb-1.5 block text-xs font-bold text-slate-700 uppercase tracking-wider">Fichier (CSV, JSON, XML, XLSX — 10 Mo max) <span class="text-rose-600">*</span></span>
                <label for="fichier" class="ds-dropzone flex cursor-pointer flex-col items-center justify-center gap-2 px-6 py-8 text-center"
                       :class="glisser ? 'ds-dragover' : ''"
                       @dragover.prevent="glisser = true" @dragleave.prevent="glisser = false"
                       @drop.prevent="glisser = false; if ($event.dataTransfer.files.length) { $refs.entree.files = $event.dataTransfer.files; fichier = $event.dataTransfer.files[0]; }">
                    <svg class="h-8 w-8 text-slate-400" fill="none" viewBox="0 0 24 24" stroke-width="1.6" stroke="currentColor" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 16.5V9.75m0 0 3 3m-3-3-3 3M6.75 19.5a4.5 4.5 0 0 1-1.41-8.775 6 6 0 0 1 11.32-2.27 4.5 4.5 0 0 1 1.34 8.797" />
                    </svg>
                    <span class="text-sm font-bold text-slate-700">Déposez votre fichier ici</span>
                    <span class="text-xs text-slate-500">ou <span class="font-bold text-primary-800 underline underline-offset-2">parcourez vos fichiers</span></span>
                    <span class="text-[11px] text-slate-400">CSV · JSON · XML · XLSX</span>
                    <input x-ref="entree" type="file" id="fichier" name="fichier" required accept=".csv,.json,.xml,.xlsx"
                           aria-labelledby="label-fichier" class="sr-only"
                           @change="fichier = $event.target.files[0]">
                </label>
                <p x-show="fichier" class="mt-2 rounded-xl border border-emerald-300 bg-emerald-50 px-3.5 py-2.5 text-xs font-bold text-emerald-900">
                    ✓ <span x-text="fichier?.name"></span>
                    <span class="font-normal" x-text="fichier ? '· ' + Math.round(fichier.size / 1024) + ' Ko' : ''"></span>
                </p>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <x-select id="annee" name="annee_universitaire_id" label="Année (contrôle)" vide="— Fichier seul —">
                    @foreach($annees as $annee)
                        <option value="{{ $annee->id }}">{{ $annee->libelle }}</option>
                    @endforeach
                </x-select>
                <x-select id="semestre" name="semestre" label="Semestre (contrôle)" vide="— Fichier seul —">
                    <option value="1">S1</option>
                    <option value="2">S2</option>
                </x-select>
                <x-select id="groupe" name="groupe_id" label="Groupe (contrôle)" vide="— Fichier seul —">
                    @foreach($groupes as $groupe)
                        <option value="{{ $groupe->id }}">{{ $groupe->code }}</option>
                    @endforeach
                </x-select>
            </div>
            <p class="text-[11px] text-slate-400">L'en-tête du fichier fait foi ; les sélecteurs, s'ils sont renseignés, doivent concorder (incohérence = erreur bloquante).</p>

            <x-btn type="submit" ::disabled="envoi">
                <span x-show="!envoi">Valider l'import</span>
                <span x-show="envoi">Analyse du fichier…</span>
            </x-btn>
        </form>
    </div>
@endsection
