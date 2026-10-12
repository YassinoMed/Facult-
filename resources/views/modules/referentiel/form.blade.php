@extends('layouts.app')

@section('title', ($modele ? 'Modifier' : 'Créer') . ' — ' . $def['label'])

@section('content')
    <div class="mx-auto max-w-2xl px-4 sm:px-6 py-8">
        <x-page-header :titre="($modele ? 'Modifier : ' : 'Nouveau / nouvelle : ') . $def['label']"
            :description="$modele ? $modele->code . ' — ' . $modele->nom : 'Saisie validée côté serveur.'"
            :fil="['Référentiel' => route('admin.referentiel.tableau-bord'), $def['labels'] => route('admin.referentiel.entites.index', $entite), 'Fiche' => '#']" />

        @if($errors->any())
            <div class="mt-4"><x-alert type="error" titre="Vérifiez la saisie.">
                <ul class="list-disc list-inside mt-1">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
            </x-alert></div>
        @endif

        <form method="POST"
              action="{{ $modele ? route('admin.referentiel.entites.update', [$entite, $modele]) : route('admin.referentiel.entites.store', $entite) }}"
              class="mt-4 ds-card p-6 space-y-4">
            @csrf
            @if($modele) @method('PUT') @endif
            @include('modules.referentiel._champs')
            <div class="flex flex-col sm:flex-row gap-2">
                <x-btn type="submit">{{ $modele ? 'Enregistrer' : 'Créer' }}</x-btn>
                <x-btn variant="secondary" href="{{ route('admin.referentiel.entites.index', $entite) }}">Annuler</x-btn>
            </div>
        </form>
    </div>
@endsection
