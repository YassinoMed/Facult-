@extends('layouts.app')

@section('title', 'Nouvelle maquette — Référentiel LMD')

@section('content')
    <div class="mx-auto max-w-2xl px-4 sm:px-6 py-8">
        <x-page-header titre="Nouvelle maquette" description="Brouillon versionné (v1 ou suivante) pour un programme et une année."
            :fil="['Référentiel' => route('admin.referentiel.tableau-bord'), 'Maquettes' => route('admin.referentiel.maquettes.index'), 'Nouvelle' => '#']" />

        <form method="POST" action="{{ route('admin.referentiel.maquettes.store') }}" class="mt-4 ds-card p-6 space-y-4">
            @csrf
            <div>
                <label for="programme_id" class="mb-1 block text-xs font-bold text-slate-700 uppercase tracking-wider">Programme <span class="text-rose-600">*</span></label>
                <select id="programme_id" name="programme_id" required class="w-full rounded-xl border border-slate-300 px-3.5 py-2.5 text-sm min-h-[44px]">
                    <option value="">— Choisir —</option>
                    @foreach($programmes as $programme)
                        <option value="{{ $programme['id'] }}" @selected(old('programme_id', request('programme_id')) == $programme['id'])>{{ $programme['label'] }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="annee_universitaire_id" class="mb-1 block text-xs font-bold text-slate-700 uppercase tracking-wider">Année universitaire <span class="text-rose-600">*</span></label>
                <select id="annee_universitaire_id" name="annee_universitaire_id" required class="w-full rounded-xl border border-slate-300 px-3.5 py-2.5 text-sm min-h-[44px]">
                    <option value="">— Choisir —</option>
                    @foreach($annees as $annee)
                        <option value="{{ $annee['id'] }}" @selected(old('annee_universitaire_id', request('annee_universitaire_id')) == $annee['id'])>{{ $annee['label'] }}</option>
                    @endforeach
                </select>
            </div>
            <x-input id="date_effet" name="date_effet" label="Date d'effet" type="date" :value="old('date_effet')" />
            <x-textarea id="commentaire" name="commentaire" label="Commentaire" :rows="3">{{ old('commentaire') }}</x-textarea>
            <div class="flex flex-col sm:flex-row gap-2">
                <x-btn type="submit">Créer le brouillon</x-btn>
                <x-btn variant="secondary" href="{{ route('admin.referentiel.maquettes.index') }}">Annuler</x-btn>
            </div>
        </form>
    </div>
@endsection
