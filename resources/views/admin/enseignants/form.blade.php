@extends('layouts.app')

@section('title', isset($enseignant) ? 'Modifier enseignant — Scolarité' : 'Nouvel enseignant — Scolarité')

@section('content')
    <div class="mx-auto max-w-2xl px-4 sm:px-6 py-8">
        <x-page-header :titre="isset($enseignant) ? 'Modifier enseignant' : 'Nouvel enseignant'" :fil="['Enseignants' => route('admin.enseignants.index'), 'Édition' => '#']" />

        @if($errors->any())
            <div class="mt-4"><x-alert type="error" titre="Veuillez corriger les champs suivants.">
                <ul class="list-disc list-inside mt-1">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
            </x-alert></div>
        @endif

        <form method="POST" action="{{ isset($enseignant) ? route('admin.enseignants.update', $enseignant) : route('admin.enseignants.store') }}"
              class="mt-4 ds-card p-6 space-y-4">
            @csrf
            @if(isset($enseignant)) @method('PUT') @endif
            <x-input id="nom" name="nom" label="Nom" required value="{{ old('nom', $enseignant->nom ?? '') }}" />
            <x-input id="prenom" name="prenom" label="Prénom" required value="{{ old('prenom', $enseignant->prenom ?? '') }}" />
            <x-input id="email" name="email" type="email" label="Email" value="{{ old('email', $enseignant->email ?? '') }}" />
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <x-input id="matricule" name="matricule" label="Matricule" value="{{ old('matricule', $enseignant->matricule ?? '') }}" />
                <x-input id="bureau" name="bureau" label="Bureau" value="{{ old('bureau', $enseignant->bureau ?? '') }}" />
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <x-select id="grade" name="grade_id" label="Grade" vide="— Choisir —"
                          :valeur="old('grade_id', $enseignant->grade_id ?? null)"
                          :options="$grades->mapWithKeys(fn ($g) => [$g->id => $g->nom])->all()">
                </x-select>
                <x-select id="departement" name="departement_id" label="Département" vide="— Choisir —"
                          :valeur="old('departement_id', $enseignant->departement_id ?? null)"
                          :options="$departements->mapWithKeys(fn ($d) => [$d->id => $d->code . ' — ' . $d->nom])->all()">
                </x-select>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <x-select id="specialite" name="specialite_id" label="Spécialité professionnelle" vide="— Choisir —"
                          :valeur="old('specialite_id', $enseignant->specialite_id ?? null)"
                          :options="$specialites->mapWithKeys(fn ($s) => [$s->id => $s->code . ' — ' . $s->nom])->all()">
                </x-select>
                <x-input id="date_embauche" name="date_embauche" type="date" label="Date d'embauche" value="{{ old('date_embauche', isset($enseignant) && $enseignant->date_embauche ? $enseignant->date_embauche->format('Y-m-d') : '') }}" />
            </div>
            @if(isset($enseignant))
                <x-select id="statut" name="statut" label="Statut administratif"
                          :valeur="old('statut', $enseignant->statut ?? 'actif')"
                          :options="['actif' => 'Actif — compte ouvert', 'inactif' => 'Inactif — compte fermé', 'suspendu' => 'Suspendu — compte fermé', 'retraite' => 'Retraité — compte fermé']">
                </x-select>
                <p class="text-xs text-slate-500">Seul le statut « Actif » ouvre le compte (connexion portail). Les autres statuts ferment le compte sans supprimer l'historique.</p>
            @else
                <x-select id="statut" name="statut" label="Statut administratif" valeur="actif"
                          :options="['actif' => 'Actif — compte ouvert', 'inactif' => 'Inactif — compte fermé', 'suspendu' => 'Suspendu — compte fermé', 'retraite' => 'Retraité — compte fermé']">
                </x-select>
            @endif
            <div class="rounded-2xl border border-indigo-200 dark:border-indigo-800 bg-indigo-50/50 dark:bg-indigo-950/30 p-4 space-y-4">
                <p class="text-xs text-slate-600 dark:text-slate-300">
                    <strong>Accès Espace Enseignant :</strong>
                    @if(isset($enseignant) && ! empty($enseignant->password))
                        activé (laisser vide pour conserver le mot de passe actuel).
                    @else
                        non activé (définir un mot de passe initial pour activer la connexion).
                    @endif
                </p>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <x-input id="password" name="password" type="password" label="Mot de passe initial" autocomplete="new-password" value="" />
                    <x-input id="password_confirmation" name="password_confirmation" type="password" label="Confirmation" autocomplete="new-password" value="" />
                </div>
            </div>
            <div class="flex flex-col sm:flex-row gap-2">
                <x-btn type="submit">{{ isset($enseignant) ? 'Enregistrer les modifications' : 'Créer l’enseignant' }}</x-btn>
                <x-btn variant="secondary" href="{{ route('admin.enseignants.index') }}">Annuler</x-btn>
            </div>
        </form>

        @if(isset($enseignant))
            <form method="POST" action="{{ route('admin.enseignants.destroy', $enseignant) }}" class="mt-4"
                  x-data="{ confirmer: false }" @submit.prevent="confirmer = true">
                @csrf @method('DELETE')
                <x-btn variant="danger" type="submit">Supprimer cet enseignant</x-btn>
                <x-modal titre="Supprimer cet enseignant ?" nom="confirmer">
                    <p>« {{ $enseignant->nomComplet() }} » sera supprimé. Refusé automatiquement s'il est affecté à des créneaux.</p>
                    <x-slot:actions>
                        <x-btn variant="secondary" @click="confirmer = false">Annuler</x-btn>
                        <x-btn variant="danger" @click="$root.requestSubmit()">Supprimer</x-btn>
                    </x-slot:actions>
                </x-modal>
            </form>
            @if($errors->has('enseignant'))
                <div class="mt-3"><x-alert type="error">{{ $errors->first('enseignant') }}</x-alert></div>
            @endif
        @endif
    </div>
@endsection
