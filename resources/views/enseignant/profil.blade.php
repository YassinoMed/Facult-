@extends('layouts.app')

@section('title', 'Mon profil — Espace Enseignant')

@section('content')
    <div class="mx-auto max-w-4xl px-4 sm:px-6 py-6">
        <x-page-header titre="Mon profil" description="Vos informations professionnelles. Les données officielles sont gérées par la scolarité et l'administration."
            :fil="['Espace Enseignant' => route('enseignant.dashboard'), 'Mon profil' => '#']">
            <x-slot:actions>
                <x-btn variant="secondary" href="{{ route('enseignant.profil.edit') }}">Modifier mes coordonnées</x-btn>
            </x-slot:actions>
        </x-page-header>

        @if(session('success'))
            <div class="mt-4"><x-alert type="success" :message="session('success')" /></div>
        @endif

        <div class="mt-6 grid grid-cols-1 md:grid-cols-3 gap-6">
            {{-- Carte récapitulative gauche --}}
            <div class="rounded-2xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 p-6 shadow-sm text-center">
                <div class="mx-auto flex h-20 w-20 items-center justify-center rounded-2xl bg-primary-800 text-2xl font-black text-white shadow-md">
                    {{ mb_strtoupper(mb_substr($enseignant->prenom, 0, 1) . mb_substr($enseignant->nom, 0, 1)) }}
                </div>
                <h3 class="mt-4 text-base font-black text-slate-900 dark:text-slate-100">{{ $enseignant->nomComplet() }}</h3>
                <p class="text-xs font-mono text-slate-500 mt-1">Matricule : {{ $enseignant->matricule ?? 'Non assigné' }}</p>
                <div class="mt-3 inline-block">
                    <x-status-badge :statut="$enseignant->actif ? 'actif' : 'inactif'" />
                </div>

                <div class="mt-6 pt-6 border-t border-slate-100 dark:border-slate-800 text-left space-y-3">
                    <div>
                        <p class="text-[11px] font-bold uppercase tracking-wider text-slate-400">Département</p>
                        <p class="text-xs font-semibold text-slate-800 dark:text-slate-200">{{ $enseignant->departement?->nom ?? '—' }}</p>
                    </div>
                    <div>
                        <p class="text-[11px] font-bold uppercase tracking-wider text-slate-400">Faculté</p>
                        <p class="text-xs font-semibold text-slate-800 dark:text-slate-200">{{ $enseignant->departement?->faculte?->nom ?? '—' }}</p>
                    </div>
                    <div>
                        <p class="text-[11px] font-bold uppercase tracking-wider text-slate-400">Grade officiel</p>
                        <p class="text-xs font-semibold text-slate-800 dark:text-slate-200">{{ $enseignant->grade?->nom ?? '—' }}</p>
                    </div>
                </div>
            </div>

            {{-- Tableau détaillé droite --}}
            <div class="md:col-span-2 space-y-6">
                <section aria-label="Coordonnées et statut" class="rounded-2xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 shadow-sm overflow-hidden">
                    <div class="px-5 py-3.5 border-b border-slate-100 dark:border-slate-800 flex items-center justify-between">
                        <h4 class="text-xs font-black uppercase tracking-wider text-slate-500">Coordonnées modifiables</h4>
                        <a href="{{ route('enseignant.profil.edit') }}" class="text-xs font-bold text-primary-700 dark:text-primary-300 hover:underline">Modifier</a>
                    </div>
                    <dl class="divide-y divide-slate-100 dark:divide-slate-800 text-sm">
                        <div class="px-5 py-3 flex flex-col sm:flex-row justify-between gap-1 sm:gap-4">
                            <dt class="text-slate-500">Email professionnel</dt>
                            <dd class="font-semibold text-slate-800 dark:text-slate-100">{{ $enseignant->email ?? '—' }}</dd>
                        </div>
                        <div class="px-5 py-3 flex flex-col sm:flex-row justify-between gap-1 sm:gap-4">
                            <dt class="text-slate-500">Téléphone</dt>
                            <dd class="font-semibold text-slate-800 dark:text-slate-100">{{ $enseignant->telephone ?? 'Non renseigné' }}</dd>
                        </div>
                        <div class="px-5 py-3 flex flex-col sm:flex-row justify-between gap-1 sm:gap-4">
                            <dt class="text-slate-500">Bureau</dt>
                            <dd class="font-semibold text-slate-800 dark:text-slate-100">{{ $enseignant->bureau ?? 'Non attribué' }}</dd>
                        </div>
                    </dl>
                </section>

                <section aria-label="Informations officielles" class="rounded-2xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 shadow-sm overflow-hidden">
                    <div class="px-5 py-3.5 border-b border-slate-100 dark:border-slate-800 flex items-center justify-between">
                        <h4 class="text-xs font-black uppercase tracking-wider text-slate-500">Données officielles (lecture seule)</h4>
                        <span class="text-[11px] text-slate-400">Géré par l'administration</span>
                    </div>
                    <dl class="divide-y divide-slate-100 dark:divide-slate-800 text-sm">
                        <div class="px-5 py-3 flex flex-col sm:flex-row justify-between gap-1 sm:gap-4">
                            <dt class="text-slate-500">Spécialité</dt>
                            <dd class="font-semibold text-slate-800 dark:text-slate-100">{{ $enseignant->specialite?->nom ?? '—' }}</dd>
                        </div>
                        <div class="px-5 py-3 flex flex-col sm:flex-row justify-between gap-1 sm:gap-4">
                            <dt class="text-slate-500">Date d'embauche / prise de fonction</dt>
                            <dd class="font-semibold text-slate-800 dark:text-slate-100">{{ $enseignant->date_embauche ? $enseignant->date_embauche->format('d/m/Y') : '—' }}</dd>
                        </div>
                        <div class="px-5 py-3 flex flex-col sm:flex-row justify-between gap-1 sm:gap-4">
                            <dt class="text-slate-500">Statut d'activité</dt>
                            <dd class="font-semibold text-slate-800 dark:text-slate-100">{{ ucfirst($enseignant->statut ?? 'Actif') }}</dd>
                        </div>
                    </dl>
                </section>

                {{-- Bloc de signalement d'anomalie sur données officielles --}}
                <div class="rounded-2xl border border-amber-200 dark:border-amber-900/50 bg-amber-50 dark:bg-amber-950/20 p-4 flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4">
                    <div>
                        <p class="text-xs font-bold text-amber-900 dark:text-amber-200">Une information officielle est inexacte ?</p>
                        <p class="text-xs text-amber-700 dark:text-amber-400 mt-0.5">Le matricule, le grade, le département ou la faculté ne peuvent être modifiés que par la scolarité centrale.</p>
                    </div>
                    <a href="{{ route('enseignant.reclamations.create', ['categorie' => 'donnees_personnelles', 'objet' => 'Demande de rectification de données administratives']) }}" 
                       class="whitespace-nowrap px-3 py-1.5 rounded-xl text-xs font-bold bg-amber-200 dark:bg-amber-800 text-amber-900 dark:text-amber-100 hover:bg-amber-300 transition">
                        Demander une correction
                    </a>
                </div>
            </div>
        </div>
    </div>
@endsection
