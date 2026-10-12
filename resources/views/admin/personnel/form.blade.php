@extends('layouts.app')

@section('title', isset($personnel) ? 'Modifier profil — Personnel' : 'Nouveau profil — Personnel')

@section('content')
    <div class="mx-auto max-w-2xl px-4 sm:px-6 py-8">
        <x-page-header :titre="isset($personnel) ? 'Modifier le profil' : 'Nouveau profil personnel'" :fil="['Personnel' => route('admin.personnel.index'), 'Édition' => '#']" />

        <form method="POST" action="{{ isset($personnel) ? route('admin.personnel.update', $personnel) : route('admin.personnel.store') }}"
              class="mt-4 ds-card p-6 space-y-4">
            @csrf
            @if(isset($personnel)) @method('PUT') @endif
            @if(!isset($personnel))
                <div>
                    <label for="user_id" class="mb-1 block text-xs font-bold text-slate-700 uppercase tracking-wider">Compte <span class="text-rose-600">*</span></label>
                    <select id="user_id" name="user_id" required class="w-full rounded-xl border border-slate-300 px-3.5 py-2.5 text-sm min-h-[44px]">
                        <option value="">— Choisir —</option>
                        @foreach($comptes as $compte)
                            <option value="{{ $compte->id }}">{{ $compte->name }} ({{ $compte->email }})</option>
                        @endforeach
                    </select>
                </div>
            @endif
            <x-input id="matricule" name="matricule" label="Matricule" required value="{{ old('matricule', $personnel->matricule ?? '') }}" />
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <x-select id="fonction" name="fonction_id" label="Fonction" vide="— Choisir —"
                          :valeur="old('fonction_id', $personnel->fonction_id ?? null)"
                          :options="$fonctions->mapWithKeys(fn ($f) => [$f->id => $f->nom])->all()">
                </x-select>
                <x-select id="departement" name="departement_id" label="Département" vide="— Choisir —"
                          :valeur="old('departement_id', $personnel->departement_id ?? null)"
                          :options="$departements->mapWithKeys(fn ($d) => [$d->id => $d->code . ' — ' . $d->nom])->all()">
                </x-select>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <x-input id="bureau" name="bureau" label="Bureau" value="{{ old('bureau', $personnel->bureau ?? '') }}" />
                <x-input id="date_entree" name="date_entree" type="date" label="Date d'entrée" value="{{ old('date_entree', isset($personnel) && $personnel->date_entree ? $personnel->date_entree->format('Y-m-d') : '') }}" />
            </div>
            <x-select id="statut" name="statut" label="Statut" required
                      :valeur="old('statut', $personnel->statut ?? 'actif')"
                      :options="['actif' => 'Actif', 'inactif' => 'Inactif', 'suspendu' => 'Suspendu']">
            </x-select>
            <div class="flex flex-col sm:flex-row gap-2">
                <x-btn type="submit">{{ isset($personnel) ? 'Enregistrer' : 'Créer' }}</x-btn>
                <x-btn variant="secondary" href="{{ route('admin.personnel.index') }}">Annuler</x-btn>
            </div>
        </form>
    </div>
@endsection
