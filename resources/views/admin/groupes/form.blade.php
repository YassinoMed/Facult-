@extends('layouts.app')

@section('title', isset($groupe) ? 'Modifier groupe — Scolarité' : 'Nouveau groupe — Scolarité')

@section('content')
    <div class="mx-auto max-w-2xl px-4 sm:px-6 py-8">
        <x-page-header :titre="isset($groupe) ? 'Modifier le groupe ' . $groupe->code : 'Nouveau groupe'" :fil="['Groupes' => route('admin.groupes.index'), 'Édition' => '#']" />

        @if($errors->any())
            <div class="mt-4"><x-alert type="error" titre="Veuillez corriger les champs suivants.">
                <ul class="list-disc list-inside mt-1">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
            </x-alert></div>
        @endif

        <form method="POST" action="{{ isset($groupe) ? route('admin.groupes.update', $groupe) : route('admin.groupes.store') }}"
              class="mt-4 ds-card p-6 space-y-4">
            @csrf
            @if(isset($groupe)) @method('PUT') @endif
            <x-input id="code" name="code" label="Code (unique)" required value="{{ old('code', $groupe->code ?? '') }}" />
            <x-input id="nom" name="nom" label="Nom" required value="{{ old('nom', $groupe->nom ?? '') }}" />
            <x-select id="faculte" name="faculte_id" label="Faculté" required vide="— Choisir —"
                      :valeur="old('faculte_id', $groupe->faculte_id ?? null)"
                      :options="$facultes->mapWithKeys(fn ($f) => [$f->id => $f->nom . ' (' . $f->code . ')'])->all()">
            </x-select>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <x-select id="niveau" name="niveau" label="Niveau" required vide="— Choisir —"
                          :valeur="old('niveau', $groupe->niveau ?? null)"
                          :options="['L1' => 'L1', 'L2' => 'L2', 'L3' => 'L3', 'M1' => 'M1', 'M2' => 'M2', 'D1' => 'D1', 'D2' => 'D2', 'D3' => 'D3']">
                </x-select>
                <x-input id="filiere" name="filiere" label="Filière" value="{{ old('filiere', $groupe->filiere ?? '') }}" />
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <x-select id="programme" name="programme_id" label="Programme (LMD)" vide="— Choisir —"
                          :valeur="old('programme_id', $groupe->programme_id ?? null)"
                          :options="$programmes->mapWithKeys(fn ($p) => [$p->id => $p->code . ' — ' . $p->nom])->all()">
                </x-select>
                <x-select id="niveau_ref" name="niveau_id" label="Niveau (LMD)" vide="— Choisir —"
                          :valeur="old('niveau_id', $groupe->niveau_id ?? null)"
                          :options="$niveaux->mapWithKeys(fn ($n) => [$n->id => $n->programme->code . ' · ' . $n->code])->all()">
                </x-select>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <x-select id="annee" name="annee_universitaire_id" label="Année universitaire" vide="— Choisir —"
                          :valeur="old('annee_universitaire_id', $groupe->annee_universitaire_id ?? null)"
                          :options="$annees->mapWithKeys(fn ($a) => [$a->id => $a->libelle])->all()">
                </x-select>
                <x-input id="capacite" name="capacite" type="number" label="Capacité (vide = illimitée)" value="{{ old('capacite', $groupe->capacite ?? '') }}" />
            </div>
            <div class="flex flex-col sm:flex-row gap-2">
                <x-btn type="submit">{{ isset($groupe) ? 'Enregistrer les modifications' : 'Créer le groupe' }}</x-btn>
                <x-btn variant="secondary" href="{{ route('admin.groupes.index') }}">Annuler</x-btn>
            </div>
        </form>

        @if(isset($groupe))
            <form method="POST" action="{{ route('admin.groupes.destroy', $groupe) }}" class="mt-4"
                  x-data="{ confirmer: false }" @submit.prevent="confirmer = true">
                @csrf @method('DELETE')
                <x-btn variant="danger" type="submit">Supprimer ce groupe</x-btn>
                <x-modal titre="Supprimer ce groupe ?" nom="confirmer">
                    <p>Le groupe « {{ $groupe->code }} » sera supprimé. Refusé automatiquement s'il a des étudiants ou des plannings.</p>
                    <x-slot:actions>
                        <x-btn variant="secondary" @click="confirmer = false">Annuler</x-btn>
                        <x-btn variant="danger" @click="$root.requestSubmit()">Supprimer</x-btn>
                    </x-slot:actions>
                </x-modal>
            </form>
            @if($errors->has('groupe'))
                <div class="mt-3"><x-alert type="error">{{ $errors->first('groupe') }}</x-alert></div>
            @endif
        @endif
    </div>
@endsection
