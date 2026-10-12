@extends('layouts.app')

@section('title', 'Modifier la maquette — Scolarité')

@section('content')
    <div class="mx-auto max-w-3xl px-4 sm:px-6 py-8">
        <x-page-header :titre="'Modifier : ' . $maquette->nom" description="Métadonnées uniquement (brouillon) — le contenu se versionne."
            :fil="['Maquettes' => route('admin.maquettes.index'), 'Modifier' => '#']" />

        <form method="POST" action="{{ route('admin.maquettes.update', $maquette) }}" class="mt-4 ds-card p-5 space-y-4">
            @csrf
            @method('PATCH')
            <x-input id="nom-maquette" label="Nom" name="nom" :value="old('nom', $maquette->nom)" required />
            <div class="grid sm:grid-cols-3 gap-4">
                <x-select id="locale-maquette" label="Langue" name="locale" :options="['fr' => 'Français', 'ar' => 'Arabe', 'bilingue' => 'Bilingue']" :valeur="old('locale', $maquette->locale)" />
                <x-select id="format-maquette" label="Format" name="format" :options="['A4' => 'A4']" :valeur="old('format', $maquette->format)" />
                <x-select id="orientation-maquette" label="Orientation" name="orientation" :options="['portrait' => 'Portrait', 'paysage' => 'Paysage']" :valeur="old('orientation', $maquette->orientation)" />
            </div>
            <x-select id="faculte-maquette" label="Faculté (vide = toutes)" name="faculte_id" :options="$facultes->pluck('code', 'id')->all()" :valeur="old('faculte_id', $maquette->faculte_id)" vide="Toutes facultés" />
            <div class="grid sm:grid-cols-2 gap-4">
                <x-input id="valide-du" label="Valide du" name="valide_du" type="date" :value="old('valide_du', $maquette->valide_du?->format('Y-m-d'))" />
                <x-input id="valide-au" label="Valide au" name="valide_au" type="date" :value="old('valide_au', $maquette->valide_au?->format('Y-m-d'))" />
            </div>
            <div class="flex gap-2">
                <x-btn type="submit">Enregistrer</x-btn>
                <x-btn href="{{ route('admin.maquettes.show', $maquette) }}" variant="secondary">Annuler</x-btn>
            </div>
        </form>
    </div>
@endsection
