@extends('layouts.app')

@section('title', 'Prévisualisation import — Scolarité')

@php
$joursFr = ['monday' => 'Lundi', 'tuesday' => 'Mardi', 'wednesday' => 'Mercredi', 'thursday' => 'Jeudi', 'friday' => 'Vendredi', 'saturday' => 'Samedi', 'sunday' => 'Dimanche'];
@endphp

@section('content')
    <div class="mx-auto max-w-6xl px-4 sm:px-6 py-8 sm:py-12" x-data="{ remplacement: false }">
        <x-page-header titre="Prévisualisation ({{ strtoupper($format) }})" description="Vérifiez l'analyse avant toute écriture — rien n'est enregistré à ce stade."
            :fil="['Emplois du temps' => route('admin.emplois-du-temps.index'), 'Import' => route('admin.emplois-du-temps.import.form'), 'Prévisualisation' => '#']" />

        <div class="mt-4">
            <x-stepper :etapes="[['Fichier', true], ['Validation', true], ['Prévisualisation', false], ['Confirmation', false], ['Terminé', false]]" :courant="3" />
        </div>

        <div class="mt-4 grid grid-cols-1 sm:grid-cols-3 gap-3">
            <div class="rounded-2xl border border-emerald-300 bg-emerald-50 p-4">
                <p class="text-2xl font-black tabular-nums text-emerald-900">{{ count($resultat->valides) }}</p>
                <p class="text-[11px] font-bold uppercase text-emerald-700">✓ Lignes valides</p>
            </div>
            <div class="rounded-2xl border border-amber-300 bg-amber-50 p-4">
                <p class="text-2xl font-black tabular-nums text-amber-900">{{ count($resultat->avertissements) }}</p>
                <p class="text-[11px] font-bold uppercase text-amber-700">⚠ Avertissements</p>
            </div>
            <div class="rounded-2xl border border-rose-300 bg-rose-50 p-4">
                <p class="text-2xl font-black tabular-nums text-rose-900">{{ count($resultat->erreurs) }}</p>
                <p class="text-[11px] font-bold uppercase text-rose-700">❌ Erreurs</p>
            </div>
        </div>

        <div class="mt-3 rounded-2xl border border-slate-200 bg-white p-4 text-xs text-slate-600 shadow-sm">
            <p class="font-bold text-slate-800">Entités reconnues :
                <span class="font-black text-slate-900">{{ $resultat->resolues['matieres'] }}</span> matières ·
                <span class="font-black text-slate-900">{{ $resultat->resolues['enseignants'] }}</span> enseignants ·
                <span class="font-black text-slate-900">{{ $resultat->resolues['salles'] }}</span> salles.
            </p>
        </div>

        @if(count($resultat->avertissements) > 0)
            <div class="mt-4">
                <x-alert type="warning">
                    <ul class="list-disc list-inside space-y-1">
                        @foreach($resultat->avertissements as $avertissement)
                            <li>{{ $avertissement }}</li>
                        @endforeach
                    </ul>
                </x-alert>
            </div>
        @endif

        @if(count($resultat->erreurs) > 0)
            <div class="mt-4">
                <x-alert type="error" titre="Import bloqué — corrigez le fichier et recommencez.">
                    <ul class="space-y-1.5 max-h-64 overflow-y-auto mt-1">
                        @foreach($resultat->erreurs as $erreur)
                            <li><strong>Ligne {{ $erreur['ligne'] }}</strong> [{{ $erreur['champ'] }}] — {{ $erreur['message'] }}</li>
                        @endforeach
                    </ul>
                </x-alert>
            </div>
        @endif

        @if(count($resultat->apercu) > 0)
            <section aria-label="Aperçu des créneaux" class="mt-4 overflow-x-auto rounded-2xl border border-slate-200 bg-white shadow-sm">
                <table class="w-full min-w-[640px] text-left text-xs sm:text-sm">
                    <thead>
                        <tr class="border-b border-slate-100 text-[11px] uppercase tracking-wider text-slate-400">
                            <th scope="col" class="px-4 py-3 font-bold">Jour</th>
                            <th scope="col" class="px-4 py-3 font-bold">Horaire</th>
                            <th scope="col" class="px-4 py-3 font-bold">Matière</th>
                            <th scope="col" class="px-4 py-3 font-bold">Salle</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach($resultat->apercu as $ligne)
                            <tr>
                                <td class="px-4 py-2.5 font-bold text-slate-800">{{ $joursFr[$ligne['day']] ?? $ligne['day'] }}</td>
                                <td class="px-4 py-2.5 tabular-nums text-slate-700">{{ $ligne['start_time'] }}–{{ $ligne['end_time'] }}</td>
                                <td class="px-4 py-2.5 text-slate-700">{{ $ligne['course'] }}</td>
                                <td class="px-4 py-2.5 font-mono text-slate-700">{{ $ligne['room'] ?? '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </section>
        @endif

        <div class="mt-6 flex flex-col sm:flex-row gap-3" x-data="{ remplacement: false }">
            <form method="POST" action="{{ route('admin.emplois-du-temps.import.annuler') }}" class="contents">
                @csrf
                <input type="hidden" name="token" value="{{ $token }}">
                <x-btn variant="secondary" type="submit">Annuler</x-btn>
            </form>

            @if(!$resultat->bloque() && count($resultat->valides) > 0)
                <form method="POST" action="{{ route('admin.emplois-du-temps.import.confirmer') }}" class="contents">
                    @csrf
                    <input type="hidden" name="token" value="{{ $token }}">
                    <input type="hidden" name="mode" value="creer">
                    <x-btn type="submit">Confirmer la création</x-btn>
                </form>
                <x-btn variant="secondary" @click="remplacement = true">Remplacer…</x-btn>

                <x-modal titre="Remplacer l'emploi du temps ?" nom="remplacement">
                    <p>⚠ Un emploi du temps existe peut-être déjà pour ce groupe, cette année et ce semestre. Le remplacement <strong>supprimera ses créneaux actuels</strong> avant d'importer les nouveaux.</p>
                    <p class="mt-1">Cette action est journalisée. Fermez cette fenêtre pour annuler.</p>
                    <x-slot:actions>
                        <form method="POST" action="{{ route('admin.emplois-du-temps.import.confirmer') }}" class="flex flex-col sm:flex-row items-stretch sm:items-center gap-2">
                            @csrf
                            <input type="hidden" name="token" value="{{ $token }}">
                            <input type="hidden" name="mode" value="remplacer">
                            <label class="flex items-center gap-2 rounded-xl border-2 border-amber-300 bg-amber-50 px-3 py-2 text-xs font-bold text-amber-900 cursor-pointer">
                                <input type="checkbox" name="confirmation" value="1" required class="h-5 w-5">
                                Je confirme
                            </label>
                            <x-btn variant="danger" type="submit">Remplacer l'emploi du temps</x-btn>
                        </form>
                    </x-slot:actions>
                </x-modal>
            @else
                <p class="text-xs text-slate-500 self-center">Import bloqué : corrigez le fichier et recommencez.</p>
            @endif
        </div>
    </div>
@endsection
