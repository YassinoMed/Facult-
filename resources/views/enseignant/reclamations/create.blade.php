@extends('layouts.app')

@section('title', 'Signaler une anomalie / Réclamation — Espace Enseignant')

@section('content')
    <div class="mx-auto max-w-3xl px-4 sm:px-6 py-6">
        <x-page-header titre="Signaler un problème ou déposer une réclamation" description="Utilisez ce formulaire pour signaler formellement une erreur d'emploi du temps, de présence, de surveillance ou de document."
            :fil="['Espace Enseignant' => route('enseignant.dashboard'), 'Mes réclamations' => route('enseignant.reclamations.index'), 'Nouveau signalement' => '#']" />

        @if($errors->any())
            <div class="mt-4"><x-alert type="error" titre="Veuillez corriger les erreurs suivantes :">
                <ul class="list-disc list-inside mt-1">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
            </x-alert></div>
        @endif

        <form method="POST" action="{{ route('enseignant.reclamations.store') }}" enctype="multipart/form-data"
              class="mt-6 rounded-2xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 p-6 space-y-5 shadow-sm">
            @csrf
            @if(! empty($presenceId))
                <input type="hidden" name="presence_id" value="{{ $presenceId }}">
                <p class="text-xs font-bold text-indigo-700 dark:text-indigo-300">Demande liée à une séance contestée (régularisation suivie depuis la présence).</p>
            @endif

            <div>
                <label for="categorie" class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1">
                    Catégorie du problème <span class="text-rose-500">*</span>
                </label>
                <select id="categorie" name="categorie" required
                        class="w-full rounded-xl border border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-800 px-3.5 py-2.5 text-sm focus:bg-white focus:outline-none">
                    <option value="">Sélectionnez la catégorie...</option>
                    @foreach($categories as $code => $libelle)
                        <option value="{{ $code }}" {{ (old('categorie', $categoriePreselectionnee) === $code) ? 'selected' : '' }}>
                            {{ $libelle }}
                        </option>
                    @endforeach
                </select>
            </div>

            <x-input id="objet" name="objet" label="Objet du signalement" required 
                     placeholder="Ex. Erreur horaire sur le cours de Mécanique du mardi..." 
                     value="{{ old('objet', $objetPreselectionne) }}" />

            <div>
                <label for="priorite" class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1">
                    Niveau d'urgence
                </label>
                <select id="priorite" name="priorite"
                        class="w-full rounded-xl border border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-800 px-3.5 py-2.5 text-sm focus:bg-white focus:outline-none">
                    <option value="normale" {{ old('priorite') === 'normale' ? 'selected' : '' }}>Normale (traitement sous 48h ouvrées)</option>
                    <option value="urgente" {{ old('priorite') === 'urgente' ? 'selected' : '' }}>Urgente (épreuve imminente ou blocage majeur)</option>
                </select>
            </div>

            <div>
                <label for="description" class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1">
                    Exposé précis de l'anomalie constatée <span class="text-rose-500">*</span>
                </label>
                <textarea id="description" name="description" rows="5" required
                          placeholder="Décrivez avec précision la séance, la salle, le conflit horaire ou les raisons de votre contestation..."
                          class="w-full rounded-xl border border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-800 px-3.5 py-2.5 text-sm focus:bg-white focus:outline-none">{{ old('description') }}</textarea>
            </div>

            <div>
                <label for="piece_jointe" class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1">
                    Pièce justificative (optionnelle, max 5 Mo)
                </label>
                <input type="file" id="piece_jointe" name="piece_jointe" accept=".pdf,.jpg,.jpeg,.png"
                       class="w-full text-xs text-slate-500 file:mr-4 file:py-2 file:px-4 file:rounded-xl file:border-0 file:text-xs file:font-bold file:bg-rose-50 file:text-rose-800 hover:file:bg-rose-100 cursor-pointer">
                <p class="text-[11px] text-slate-400 mt-1">Capture d'écran, certificat médical, ordre de mission, etc.</p>
            </div>

            <div class="pt-4 border-t border-slate-100 dark:border-slate-800 flex flex-col sm:flex-row gap-3">
                <x-btn variant="danger" type="submit">Transmettre le signalement</x-btn>
                <x-btn variant="secondary" href="{{ route('enseignant.reclamations.index') }}">Annuler</x-btn>
            </div>
        </form>
    </div>
@endsection
