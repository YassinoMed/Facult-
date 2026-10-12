@extends('layouts.app')

@section('title', isset($salle) ? 'Modifier salle — Scolarité' : 'Nouvelle salle — Scolarité')

@section('content')
    <div class="mx-auto max-w-2xl px-4 sm:px-6 py-8">
        <x-page-header :titre="isset($salle) ? 'Modifier la salle ' . $salle->code : 'Nouvelle salle'" :fil="['Salles' => route('admin.salles.index'), 'Édition' => '#']" />

        @if($errors->any())
            <div class="mt-4"><x-alert type="error" titre="Veuillez corriger les champs suivants.">
                <ul class="list-disc list-inside mt-1">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
            </x-alert></div>
        @endif

        <form method="POST" action="{{ isset($salle) ? route('admin.salles.update', $salle) : route('admin.salles.store') }}"
              class="mt-4 ds-card p-6 space-y-4">
            @csrf
            @if(isset($salle)) @method('PUT') @endif
            <x-input id="code" name="code" label="Code" required value="{{ old('code', $salle->code ?? '') }}" />
            <x-input id="nom" name="nom" label="Nom" value="{{ old('nom', $salle->nom ?? '') }}" />
            <x-input id="capacite" name="capacite" type="number" label="Capacité" value="{{ old('capacite', $salle->capacite ?? '') }}" />
            <x-input id="min_surveillants" name="min_surveillants" type="number" label="Minimum surveillants (vide = règle générale)" value="{{ old('min_surveillants', $salle->min_surveillants ?? '') }}" />
            <x-input id="ratio_surveillants" name="ratio_surveillants" type="number" label="Candidats par surveillant (vide = ratio général)" value="{{ old('ratio_surveillants', $salle->ratio_surveillants ?? '') }}" />
            <x-input id="campus" name="campus" label="Campus (optionnel)" value="{{ old('campus', $salle->campus ?? '') }}" />
            <x-input id="batiment" name="batiment" label="Bâtiment (optionnel)" value="{{ old('batiment', $salle->batiment ?? '') }}" />
            <x-input id="etage" name="etage" label="Étage (optionnel)" value="{{ old('etage', $salle->etage ?? '') }}" />
            @if(isset($salle))
                <label class="flex items-center gap-2 text-sm font-bold text-slate-700 cursor-pointer">
                    <input type="checkbox" name="actif" value="1" @checked(old('actif', $salle->actif)) class="h-5 w-5 rounded">
                    Salle active
                </label>
            @endif
            <div class="flex flex-col sm:flex-row gap-2">
                <x-btn type="submit">{{ isset($salle) ? 'Enregistrer les modifications' : 'Créer la salle' }}</x-btn>
                <x-btn variant="secondary" href="{{ route('admin.salles.index') }}">Annuler</x-btn>
            </div>
        </form>

        @if(isset($salle))
            <form method="POST" action="{{ route('admin.salles.destroy', $salle) }}" class="mt-4"
                  x-data="{ confirmer: false }" @submit.prevent="confirmer = true">
                @csrf @method('DELETE')
                <x-btn variant="danger" type="submit">Supprimer cette salle</x-btn>
                <x-modal titre="Supprimer cette salle ?" nom="confirmer">
                    <p>La salle « {{ $salle->code }} » sera supprimée. Refusé automatiquement si des créneaux l'utilisent.</p>
                    <x-slot:actions>
                        <x-btn variant="secondary" @click="confirmer = false">Annuler</x-btn>
                        <x-btn variant="danger" @click="$root.requestSubmit()">Supprimer</x-btn>
                    </x-slot:actions>
                </x-modal>
            </form>
            @if($errors->has('salle'))
                <div class="mt-3"><x-alert type="error">{{ $errors->first('salle') }}</x-alert></div>
            @endif
        @endif
    </div>
@endsection
