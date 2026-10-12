@extends('layouts.app')

@section('title', isset($enseignement) ? 'Modifier enseignement' : 'Nouvel enseignement')

@section('content')
    <div class="mx-auto max-w-2xl px-4 sm:px-6 py-8">
        <x-page-header :titre="isset($enseignement) ? 'Modifier : ' . $enseignement->matiere->code : 'Nouvel enseignement'" description="ECUE × type × année — volumes prévus."
            :fil="['Enseignements' => route('admin.enseignements.index'), 'Édition' => '#']" />

        @if($errors->any())
            <div class="mt-4"><x-alert type="error" titre="Vérifiez la saisie.">
                <ul class="list-disc list-inside mt-1">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
            </x-alert></div>
        @endif

        <form method="POST" action="{{ isset($enseignement) ? route('admin.enseignements.update', $enseignement) : route('admin.enseignements.store') }}"
              class="mt-4 ds-card p-6 space-y-4">
            @csrf
            @if(isset($enseignement)) @method('PUT') @endif
            @if(!isset($enseignement))
                <div>
                    <label for="matiere_id" class="mb-1 block text-xs font-bold text-slate-700 uppercase tracking-wider">ECUE <span class="text-rose-600">*</span></label>
                    <select id="matiere_id" name="matiere_id" required class="w-full rounded-xl border border-slate-300 px-3.5 py-2.5 text-sm min-h-[44px]">
                        <option value="">— Choisir —</option>
                        @foreach($matieres as $matiere)
                            <option value="{{ $matiere->id }}" @selected(old('matiere_id') == $matiere->id)>{{ $matiere->code }} — {{ $matiere->nom }} (UE {{ $matiere->ue->code }})</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="type_id" class="mb-1 block text-xs font-bold text-slate-700 uppercase tracking-wider">Type <span class="text-rose-600">*</span></label>
                    <select id="type_id" name="type_id" required class="w-full rounded-xl border border-slate-300 px-3.5 py-2.5 text-sm min-h-[44px]">
                        <option value="">— Choisir —</option>
                        @foreach($types as $type)
                            <option value="{{ $type->id }}" @selected(old('type_id') == $type->id)>{{ $type->code }} — {{ $type->nom }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="annee_universitaire_id" class="mb-1 block text-xs font-bold text-slate-700 uppercase tracking-wider">Année universitaire <span class="text-rose-600">*</span></label>
                    <select id="annee_universitaire_id" name="annee_universitaire_id" required class="w-full rounded-xl border border-slate-300 px-3.5 py-2.5 text-sm min-h-[44px]">
                        <option value="">— Choisir —</option>
                        @foreach($annees as $annee)
                            <option value="{{ $annee->id }}" @selected(old('annee_universitaire_id') == $annee->id)>{{ $annee->libelle }}</option>
                        @endforeach
                    </select>
                </div>
            @else
                <p class="text-xs text-slate-500">ECUE : <strong>{{ $enseignement->matiere->code }}</strong> · Type : <strong>{{ $enseignement->type->code }}</strong> · {{ $enseignement->anneeUniversitaire->libelle }}</p>
            @endif
            <div class="grid grid-cols-2 sm:grid-cols-5 gap-3">
                @foreach([['volume_cm', 'CM'], ['volume_td', 'TD'], ['volume_tp', 'TP'], ['volume_projet', 'Projet'], ['volume_autre', 'Autre']] as [$champ, $libelle])
                    <x-input :id="$champ" :name="$champ" :label="$libelle" type="number" min="0" max="999" :value="old($champ, $enseignement->{$champ} ?? 0)" />
                @endforeach
            </div>
            <p class="text-xs text-slate-500">Total prévu : <strong class="tabular-nums">{{ (isset($enseignement) ? $enseignement->volumeTotal() : 0) }}h</strong> <span class="text-slate-400">(CM+TD+TP+Projet+Autre, calculé serveur)</span></p>
            <x-textarea id="description" name="description" label="Description / notes pédagogiques" :rows="2">{{ old('description', $enseignement->description ?? '') }}</x-textarea>
            @if(isset($enseignement) && $enseignement->estVerrouille())
                <x-input id="motif" name="motif" label="Motif de la modification (enseignement verrouillé)" required value="{{ old('motif') }}" />
            @endif
            <div class="flex flex-col sm:flex-row gap-2">
                <x-btn type="submit">{{ isset($enseignement) ? 'Enregistrer' : 'Créer' }}</x-btn>
                <x-btn variant="secondary" href="{{ route('admin.enseignements.index') }}">Annuler</x-btn>
            </div>
        </form>
    </div>
@endsection
