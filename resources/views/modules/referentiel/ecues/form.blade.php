@extends('layouts.app')

@section('title', 'Modifier ECUE — ' . $ecue->code)

@section('content')
    <div class="mx-auto max-w-2xl px-4 sm:px-6 py-8">
        <x-page-header :titre="'ECUE : ' . $ecue->code" description="Volumes, évaluation, description — le catalogue (code, crédits) reste géré par le cœur."
            :fil="['Référentiel' => route('admin.referentiel.tableau-bord'), 'ECUE' => route('admin.referentiel.ecues.index'), $ecue->code => '#']" />

        <form method="POST" action="{{ route('admin.referentiel.ecues.update', $ecue) }}" class="mt-4 ds-card p-6 space-y-4">
            @csrf @method('PATCH')
            <x-textarea id="description" name="description" label="Description" :rows="3">{{ old('description', $ecue->description) }}</x-textarea>
            <div class="grid grid-cols-3 gap-3">
                <x-input id="volume_cm" name="volume_cm" label="Volume CM (h)" type="number" :value="old('volume_cm', $ecue->volume_cm)" />
                <x-input id="volume_td" name="volume_td" label="Volume TD (h)" type="number" :value="old('volume_td', $ecue->volume_td)" />
                <x-input id="volume_tp" name="volume_tp" label="Volume TP (h)" type="number" :value="old('volume_tp', $ecue->volume_tp)" />
            </div>
            <x-input id="type_evaluation" name="type_evaluation" label="Type d'évaluation" :value="old('type_evaluation', $ecue->type_evaluation)" />
            <div class="flex flex-col sm:flex-row gap-2">
                <x-btn type="submit">Enregistrer</x-btn>
                <x-btn variant="secondary" href="{{ route('admin.referentiel.ecues.show', $ecue) }}">Annuler</x-btn>
            </div>
        </form>
    </div>
@endsection
