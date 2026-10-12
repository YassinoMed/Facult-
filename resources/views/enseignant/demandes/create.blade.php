@extends('layouts.app')

@section('title', 'Déposer une demande — Espace Enseignant')

@section('content')
    <div class="mx-auto max-w-3xl px-4 sm:px-6 py-6">
        <x-page-header titre="Nouvelle demande administrative" description="Remplissez les détails de votre demande et joignez les pièces justificatives nécessaires."
            :fil="['Espace Enseignant' => route('enseignant.dashboard'), 'Mes demandes' => route('enseignant.demandes.index'), 'Nouvelle' => '#']" />

        @if($errors->any())
            <div class="mt-4"><x-alert type="error" titre="Veuillez corriger les erreurs de saisie :">
                <ul class="list-disc list-inside mt-1">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
            </x-alert></div>
        @endif

        <form method="POST" action="{{ route('enseignant.demandes.store') }}" enctype="multipart/form-data" 
              class="mt-6 rounded-2xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 p-6 space-y-5 shadow-sm">
            @csrf

            <div>
                <label for="type_demande" class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1">
                    Nature de la demande <span class="text-rose-500">*</span>
                </label>
                <select id="type_demande" name="type_demande" required
                        class="w-full rounded-xl border border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-800 px-3.5 py-2.5 text-sm focus:bg-white focus:outline-none">
                    <option value="">Sélectionnez le type de demande...</option>
                    @foreach($types as $code => $libelle)
                        <option value="{{ $code }}" {{ old('type_demande') === $code ? 'selected' : '' }}>{{ $libelle }}</option>
                    @endforeach
                </select>
            </div>

            <x-input id="objet" name="objet" label="Objet du dossier" required 
                     placeholder="Ex. Demande de congé pour mission pédagogique..." 
                     value="{{ old('objet') }}" />

            <div>
                <label for="description" class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1">
                    Exposé détaillé <span class="text-rose-500">*</span>
                </label>
                <textarea id="description" name="description" rows="5" required
                          placeholder="Précisez les dates, le motif, le cadre et toute information utile pour l'instruction du dossier..."
                          class="w-full rounded-xl border border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-800 px-3.5 py-2.5 text-sm focus:bg-white focus:outline-none">{{ old('description') }}</textarea>
            </div>

            <div>
                <label for="piece_jointe" class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1">
                    Pièce jointe justificative (optionnelle, max 5 Mo)
                </label>
                <input type="file" id="piece_jointe" name="piece_jointe" accept=".pdf,.jpg,.jpeg,.png"
                       class="w-full text-xs text-slate-500 file:mr-4 file:py-2 file:px-4 file:rounded-xl file:border-0 file:text-xs file:font-bold file:bg-primary-50 file:text-primary-800 hover:file:bg-primary-100 cursor-pointer">
                <p class="text-[11px] text-slate-400 mt-1">Formats acceptés : PDF, JPG, PNG. Stockage sécurisé et privé.</p>
            </div>

            <div class="pt-2 flex items-center gap-2">
                <input type="checkbox" id="est_brouillon" name="est_brouillon" value="1" {{ old('est_brouillon') ? 'checked' : '' }}
                       class="h-4 w-4 rounded border-slate-300 text-primary-800 focus:ring-primary-700">
                <label for="est_brouillon" class="text-xs font-medium text-slate-700 dark:text-slate-300">
                    Enregistrer sous forme de brouillon (vous pourrez la compléter et la soumettre plus tard)
                </label>
            </div>

            <div class="pt-4 border-t border-slate-100 dark:border-slate-800 flex flex-col sm:flex-row gap-3">
                <x-btn type="submit">Transmettre la demande</x-btn>
                <x-btn variant="secondary" href="{{ route('enseignant.demandes.index') }}">Annuler</x-btn>
            </div>
        </form>
    </div>
@endsection
