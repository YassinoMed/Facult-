@extends('layouts.app')

@section('title', 'Préférences de notification')

@section('content')
    <div class="mx-auto max-w-2xl px-4 sm:px-6 py-8">
        <x-page-header titre="Préférences de notification"
                       description="Choisissez les catégories d'alertes que vous souhaitez recevoir. À défaut de choix, tout est activé."
                       :fil="['Accueil' => $retour, 'Notifications' => route('etudiant.notifications.index'), 'Préférences' => '#']">
            <x-slot:actions>
                <a href="{{ $retour }}" class="text-xs font-bold text-primary-800 underline underline-offset-2">← Retour au centre</a>
            </x-slot:actions>
        </x-page-header>

        <form method="POST" action="{{ route('etudiant.notifications.preferences.update') }}" class="mt-6 space-y-4" aria-label="Préférences de notification">
            @csrf
            <section class="ds-card divide-y divide-slate-100" aria-label="Catégories de notification">
                @foreach($categories as $categorie)
                    <div class="px-5 py-4 flex items-start justify-between gap-4">
                        <div class="min-w-0">
                            <label for="pref-{{ $categorie['cle'] }}" class="text-sm font-black text-slate-900">
                                {{ $categorie['libelle'] }}
                            </label>
                            @if($categorie['obligatoire'])
                                <p class="mt-0.5 text-[11px] text-slate-500">Catégorie institutionnelle : toujours activée, non désactivable.</p>
                            @endif
                        </div>
                        <input type="checkbox" id="pref-{{ $categorie['cle'] }}" name="preferences[{{ $categorie['cle'] }}]" value="{{ $categorie['cle'] }}"
                               class="mt-1 h-5 w-5 flex-shrink-0 rounded border-slate-300 text-primary-700 focus:ring-primary-600"
                               @checked($categorie['actif']) @disabled($categorie['obligatoire'])>
                    </div>
                @endforeach
            </section>

            <div class="flex justify-end">
                <x-btn type="submit">Enregistrer les préférences</x-btn>
            </div>
        </form>
    </div>
@endsection
