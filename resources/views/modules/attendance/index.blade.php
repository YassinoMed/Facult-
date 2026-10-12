@extends('layouts.app')

@section('title', 'Présences & Emploi du temps — Scolarité')
@section('meta_description', 'Suivi intelligent des présences des enseignants basé sur l\'emploi du temps et relevés de présence.')

@section('content')
<div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 py-8 sm:py-10"
     x-data="{
        onglet: '{{ request('onglet', 'enseignants') }}',
        modalPointage: false,
        modalDiagnostic: false,
        modalValider: false,
        modalConfirmer: false,
        modalCorriger: false,
        modalHistorique: false,
        seanceActive: null,
        presenceSelectionnee: null,
        historiqueLignes: [],
        chargerHistorique(presenceId) {
            this.historiqueLignes = [];
            fetch('{{ url('admin/presences/enseignants') }}/' + presenceId + '/historique')
                .then(res => res.json())
                .then(data => {
                    this.historiqueLignes = data.historique || [];
                    this.modalHistorique = true;
                });
        }
     }">

    {{-- En-tête de page --}}
    <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
        <div>
            <p class="text-[11px] font-bold uppercase tracking-wider text-slate-400">Module présences</p>
            <h1 class="mt-1 text-2xl sm:text-3xl font-black tracking-tight text-slate-900">
                Suivi des présences & Relevés de présence
            </h1>
            <p class="mt-1 text-sm text-slate-500">
                Rapprochement déterministe entre l'emploi du temps officiel et les pointages réels des enseignants.
            </p>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            @can('attendance.create')
                <form method="POST" action="{{ route('admin.presences.enseignants.reconcilier') }}" class="inline">
                    @csrf
                    <input type="hidden" name="date" value="{{ $dateSelectionnee }}">
                    <x-btn type="submit" variant="secondary" title="Recalculer et rapprocher de manière idempotente les séances de la journée">
                        <svg class="h-4 w-4 mr-1.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                        </svg>
                        Rapprocher les séances du jour
                    </x-btn>
                </form>

                <x-btn type="button" variant="primary" @click="modalPointage = true">
                    <svg class="h-4 w-4 mr-1.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                    </svg>
                    Enregistrer un pointage
                </x-btn>
            @endcan

            @can('attendance.export')
                <x-btn variant="ghost" href="{{ route('admin.presences.feuille.nouveau', request()->only(['groupe_id'])) }}">
                    Générateur multi-modèles
                </x-btn>
                <x-btn variant="ghost" href="{{ route('admin.presences.feuille', request()->only(['groupe_id'])) }}">
                    Export rapide
                </x-btn>
            @endcan

            @can('attendance.templates.manage')
                <x-btn variant="ghost" href="{{ route('admin.presences.modeles.index') }}">
                    Modèles de fiches
                </x-btn>
            @endcan
        </div>
    </div>

    {{-- Sélecteur d'onglets --}}
    <div class="mt-6 border-b border-slate-200">
        <nav class="-mb-px flex space-x-6" aria-label="Onglets">
            <button type="button"
                    @click="onglet = 'enseignants'"
                    :class="onglet === 'enseignants' ? 'border-primary-600 text-primary-700 font-bold' : 'border-transparent text-slate-500 hover:text-slate-700 hover:border-slate-300 font-medium'"
                    class="py-3 px-1 border-b-2 text-sm flex items-center gap-2">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                </svg>
                Séances Enseignants (Emploi du temps)
                <span class="ml-1 rounded-full bg-slate-100 px-2 py-0.5 text-xs font-bold text-slate-600">
                    {{ $kpisEnseignants['seances_prevues'] }}
                </span>
            </button>

            <button type="button"
                    @click="onglet = 'etudiants'"
                    :class="onglet === 'etudiants' ? 'border-primary-600 text-primary-700 font-bold' : 'border-transparent text-slate-500 hover:text-slate-700 hover:border-slate-300 font-medium'"
                    class="py-3 px-1 border-b-2 text-sm flex items-center gap-2">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z" />
                </svg>
                Relevés Étudiants
                <span class="ml-1 rounded-full bg-slate-100 px-2 py-0.5 text-xs font-bold text-slate-600">
                    {{ $presences->total() }}
                </span>
            </button>

            <button type="button"
                    @click="onglet = 'appels'"
                    :class="onglet === 'appels' ? 'border-primary-600 text-primary-700 font-bold' : 'border-transparent text-slate-500 hover:text-slate-700 hover:border-slate-300 font-medium'"
                    class="py-3 px-1 border-b-2 text-sm flex items-center gap-2">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2" />
                </svg>
                Appels de séances (Étudiants)
                <span class="ml-1 rounded-full bg-slate-100 px-2 py-0.5 text-xs font-bold text-slate-600">
                    {{ $appelsSeances->total() }}
                </span>
            </button>
        </nav>
    </div>

    {{-- ========================================================================= --}}
    {{-- ONGLET 1 : SÉANCES ENSEIGNANTS (RAPPROCHEMENT EMPLOI DU TEMPS)            --}}
    {{-- ========================================================================= --}}
    <div x-show="onglet === 'enseignants'" x-cloak class="mt-6 space-y-6">

        {{-- Tableau de bord des indicateurs synthétiques (partition exhaustive :
             la somme des compteurs = séances prévues, aucune séance ignorée) --}}
        <div class="grid grid-cols-2 sm:grid-cols-4 lg:grid-cols-7 gap-3">
            <x-stat-card libelle="Séances prévues" :valeur="$kpisEnseignants['seances_prevues']" couleur="slate" />
            <x-stat-card libelle="Présences confirmées" :valeur="$kpisEnseignants['presences_confirmees']" couleur="emerald" />
            <x-stat-card libelle="Retards potentiels" :valeur="$kpisEnseignants['retards_potentiels']" couleur="amber" />
            <x-stat-card libelle="Pointages manquants" :valeur="$kpisEnseignants['pointages_manquants']" couleur="amber" />
            <x-stat-card libelle="Absences à vérifier" :valeur="$kpisEnseignants['absences_a_verifier']" couleur="rose" />
            <x-stat-card libelle="Absences validées" :valeur="$kpisEnseignants['absences_validees']" couleur="rose" />
            <x-stat-card libelle="Séances annulées" :valeur="$kpisEnseignants['seances_annulees']" couleur="slate" />
            <x-stat-card libelle="Dispenses / missions" :valeur="$kpisEnseignants['dispenses_missions']" couleur="indigo" />
            <x-stat-card libelle="Retards justifiés" :valeur="$kpisEnseignants['retards_justifies']" couleur="indigo" />
            <x-stat-card libelle="Absences rejetées" :valeur="$kpisEnseignants['absences_rejetees']" couleur="slate" />
            <x-stat-card libelle="À venir" :valeur="$kpisEnseignants['seances_a_venir']" couleur="slate" />
            <x-stat-card libelle="En cours" :valeur="$kpisEnseignants['seances_en_cours']" couleur="slate" />
        </div>
        <p class="text-[11px] text-slate-500">
            Tolérance de retard appliquée : {{ $toleranceRetard }} min (paramètre administrable « presences.tolerance_retard_minutes »).
            Un pointage manquant n'est jamais automatiquement validé comme absence : il devient « à vérifier » par la scolarité.
        </p>

        {{-- Barre de filtres pour les séances enseignants --}}
        <form method="GET" action="{{ route('admin.presences.index') }}" class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
            <input type="hidden" name="onglet" value="enseignants">
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1" for="date_seance">Date de la séance</label>
                    <input type="date" id="date_seance" name="date" value="{{ $dateSelectionnee }}"
                           class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm min-h-[44px]">
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1" for="filtre_enseignant">Enseignant</label>
                    <select id="filtre_enseignant" name="teacher_id" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm min-h-[44px]">
                        <option value="">Tous les enseignants</option>
                        @foreach($enseignants as $ens)
                            <option value="{{ $ens->id }}" @selected((string) request('teacher_id') === (string) $ens->id)>
                                {{ $ens->nomComplet() }}
                            </option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1" for="filtre_groupe">Groupe d'étudiants</label>
                    <select id="filtre_groupe" name="groupe_id" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm min-h-[44px]">
                        <option value="">Tous les groupes</option>
                        @foreach($groupes as $grp)
                            <option value="{{ $grp->id }}" @selected((string) request('groupe_id') === (string) $grp->id)>
                                {{ $grp->code }} ({{ $grp->nom ?? 'Standard' }})
                            </option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1" for="filtre_statut">Statut de présence</label>
                    <select id="filtre_statut" name="statut_enseignant" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm min-h-[44px]">
                        <option value="">Tous les statuts</option>
                        <option value="presence_confirmee" @selected(request('statut_enseignant') === 'presence_confirmee')>Présence confirmée</option>
                        <option value="retard_potentiel" @selected(request('statut_enseignant') === 'retard_potentiel')>Retard potentiel</option>
                        <option value="pointage_manquant" @selected(request('statut_enseignant') === 'pointage_manquant')>Pointage manquant</option>
                        <option value="absence_a_verifier" @selected(request('statut_enseignant') === 'absence_a_verifier')>Absence à vérifier</option>
                        <option value="absence_validee" @selected(request('statut_enseignant') === 'absence_validee')>Absence validée</option>
                        <option value="seance_annulee" @selected(request('statut_enseignant') === 'seance_annulee')>Séance annulée</option>
                        <option value="dispense_mission" @selected(request('statut_enseignant') === 'dispense_mission')>Dispense / Mission</option>
                    </select>
                </div>
            </div>

            <div class="mt-3 flex flex-wrap items-center justify-between gap-3 border-t border-slate-100 pt-3">
                <label class="inline-flex items-center gap-2 text-xs font-bold text-slate-700 cursor-pointer">
                    <input type="checkbox" name="a_verifier" value="1" @checked(request('a_verifier')) class="rounded border-slate-300 text-rose-600 focus:ring-rose-500">
                    <span>Afficher uniquement les anomalies & vérifications requises</span>
                </label>

                <div class="flex items-center gap-2">
                    <x-btn type="submit" variant="secondary">Appliquer les filtres</x-btn>
                    <a href="{{ route('admin.presences.index') }}" class="rounded-xl px-3 py-2 text-xs font-medium text-slate-500 hover:text-slate-800">
                        Réinitialiser
                    </a>
                </div>
            </div>
        </form>

        {{-- Tableau des séances de la journée --}}
        @if($seancesEnseignants->isNotEmpty())
            <div class="overflow-x-auto rounded-2xl border border-slate-200 bg-white shadow-sm">
                <table class="w-full min-w-[960px] text-left text-xs sm:text-sm">
                    <thead>
                        <tr class="border-b border-slate-100 bg-slate-50/50 text-[11px] uppercase tracking-wider text-slate-400">
                            <th scope="col" class="px-5 py-3 font-bold">Horaire prévu</th>
                            <th scope="col" class="px-5 py-3 font-bold">Séance & Matière</th>
                            <th scope="col" class="px-5 py-3 font-bold">Enseignant</th>
                            <th scope="col" class="px-5 py-3 font-bold">Groupe & Salle</th>
                            <th scope="col" class="px-5 py-3 font-bold">Pointage réel</th>
                            <th scope="col" class="px-5 py-3 font-bold">Statut calculé & Diagnostic</th>
                            <th scope="col" class="px-5 py-3 font-bold">Validation</th>
                            <th scope="col" class="px-5 py-3 font-bold text-right"><span class="sr-only">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach($seancesEnseignants as $s)
                            <tr class="hover:bg-slate-50/70 transition-colors {{ $s['a_verifier'] ? 'bg-amber-50/20' : '' }}">
                                {{-- Horaire prévu --}}
                                <td class="px-5 py-3.5 whitespace-nowrap">
                                    <div class="font-mono font-bold text-slate-900">
                                        {{ substr($s['heure_debut'], 0, 5) }} — {{ substr($s['heure_fin'], 0, 5) }}
                                    </div>
                                    <div class="text-[11px] text-slate-400">
                                        {{ $s['duree_minutes'] }} min
                                    </div>
                                </td>

                                {{-- Séance & Matière --}}
                                <td class="px-5 py-3.5">
                                    <div class="flex items-center gap-1.5">
                                        <span class="inline-flex rounded px-1.5 py-0.5 text-[10px] font-black uppercase tracking-wider bg-slate-100 text-slate-700">
                                            {{ $s['type_seance'] }}
                                        </span>
                                        <span class="font-bold text-slate-900">{{ $s['matiere']?->code }}</span>
                                    </div>
                                    <div class="text-[11px] text-slate-500 truncate max-w-[220px]" title="{{ $s['matiere']?->nom }}">
                                        {{ $s['matiere']?->nom }}
                                    </div>
                                </td>

                                {{-- Enseignant --}}
                                <td class="px-5 py-3.5">
                                    @if($s['enseignant'])
                                        <div class="font-bold text-slate-900">{{ $s['enseignant']->nomComplet() }}</div>
                                        <div class="text-[11px] text-slate-500 font-mono">
                                            {{ $s['enseignant']->matricule ?? $s['enseignant']->departement?->code ?? 'Enseignant' }}
                                        </div>
                                    @else
                                        <span class="inline-flex rounded px-1.5 py-0.5 text-[11px] font-bold bg-rose-50 text-rose-700">
                                            Non assigné
                                        </span>
                                    @endif
                                </td>

                                {{-- Groupe & Salle --}}
                                <td class="px-5 py-3.5 whitespace-nowrap">
                                    <div class="font-bold text-slate-900">{{ $s['groupe']?->code ?? '—' }}</div>
                                    <div class="text-[11px] text-slate-500">
                                        {{ $s['salle']?->nom ?? $s['salle']?->code ?? 'Salle non définie' }}
                                    </div>
                                </td>

                                {{-- Pointage réel --}}
                                <td class="px-5 py-3.5 whitespace-nowrap">
                                    @if($s['heure_arrivee'])
                                        <div class="font-mono font-bold text-slate-900">
                                            Arrivée : {{ substr($s['heure_arrivee'], 0, 5) }}
                                        </div>
                                        @if($s['retard_minutes'] > 0)
                                            <div class="text-[11px] font-bold text-amber-600">
                                                +{{ $s['retard_minutes'] }} min retard
                                            </div>
                                        @else
                                            <div class="text-[11px] text-emerald-600 font-medium">À l'heure</div>
                                        @endif
                                    @else
                                        <span class="text-[11px] text-slate-400 italic">Aucun pointage</span>
                                    @endif
                                </td>

                                {{-- Statut & Diagnostic --}}
                                <td class="px-5 py-3.5">
                                    <x-status-badge statut="{{ $s['statut_effectif'] }}" />
                                    <p class="mt-1 text-[11px] text-slate-500 line-clamp-2" title="{{ $s['diagnostic'] }}">
                                        {{ $s['diagnostic'] }}
                                    </p>
                                </td>

                                {{-- Validation humaine --}}
                                <td class="px-5 py-3.5 text-xs">
                                    @if($s['statut_valide'])
                                        <span class="inline-flex items-center gap-1 font-bold text-emerald-700">
                                            <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                                            </svg>
                                            Validé
                                        </span>
                                        <div class="text-[11px] text-slate-400">
                                            {{ $s['valide_par']?->name ?? 'Admin' }}
                                        </div>
                                    @else
                                        <span class="text-[11px] text-slate-400">Calcul automatique</span>
                                    @endif
                                </td>

                                {{-- Actions --}}
                                <td class="px-5 py-3.5 text-right whitespace-nowrap">
                                    <div class="flex items-center justify-end gap-1.5">
                                        {{-- Bouton Explication détaillée --}}
                                        <button type="button"
                                                @click="seanceActive = {{ json_encode($s) }}; modalDiagnostic = true"
                                                class="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 hover:text-slate-700"
                                                title="Voir le diagnostic et les pointages bruts">
                                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                            </svg>
                                        </button>

                                        @if($s['presence_id'])
                                            @can('attendance.create')
                                                {{-- Confirmer présence --}}
                                                <button type="button"
                                                        @click="presenceSelectionnee = {{ $s['presence_id'] }}; modalConfirmer = true"
                                                        class="rounded-lg px-2 py-1 text-xs font-bold text-emerald-700 hover:bg-emerald-50"
                                                        title="Confirmer la présence">
                                                    Confirmer
                                                </button>

                                                {{-- Valider absence --}}
                                                <button type="button"
                                                        @click="presenceSelectionnee = {{ $s['presence_id'] }}; modalValider = true"
                                                        class="rounded-lg px-2 py-1 text-xs font-bold text-rose-700 hover:bg-rose-50"
                                                        title="Valider officiellement l'absence">
                                                    Absence
                                                </button>

                                                {{-- Corriger --}}
                                                <button type="button"
                                                        @click="presenceSelectionnee = {{ $s['presence_id'] }}; modalCorriger = true"
                                                        class="rounded-lg px-2 py-1 text-xs font-medium text-slate-600 hover:bg-slate-100"
                                                        title="Corriger les horaires ou le statut">
                                                    Corriger
                                                </button>
                                            @endcan

                                            @can('attendance.view')
                                                {{-- Historique d'audit --}}
                                                <button type="button"
                                                        @click="chargerHistorique({{ $s['presence_id'] }})"
                                                        class="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 hover:text-slate-700"
                                                        title="Consulter l'historique d'audit">
                                                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                                                    </svg>
                                                </button>
                                            @endcan
                                        @else
                                            <span class="text-[11px] text-slate-400 italic">Non persisté</span>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @else
            <x-empty-state message="Aucune séance d'emploi du temps trouvée pour la date et les critères sélectionnés." />
        @endif
    </div>

    {{-- ========================================================================= --}}
    {{-- ONGLET 2 : RELEVÉS ÉTUDIANTS (COMPATIBILITÉ ASCENDANTE TOTALE)            --}}
    {{-- ========================================================================= --}}
    <div x-show="onglet === 'etudiants'" x-cloak class="mt-6 space-y-6">
        <form method="GET" action="{{ route('admin.presences.index') }}" class="flex flex-col sm:flex-row gap-2">
            <input type="hidden" name="onglet" value="etudiants">
            <input type="date" name="date" value="{{ request('date') }}" aria-label="Date" class="rounded-xl border border-slate-300 px-3 py-2.5 text-sm min-h-[44px]">
            <select name="statut" aria-label="Statut" class="rounded-xl border border-slate-300 px-3 py-2.5 text-sm min-h-[44px]">
                <option value="">Tous statuts</option>
                @foreach(['present' => 'Présents', 'absent' => 'Absents', 'retard' => 'Retards', 'excuse' => 'Excusés'] as $valeur => $libelle)
                    <option value="{{ $valeur }}" @selected(request('statut') === $valeur)>{{ $libelle }}</option>
                @endforeach
            </select>
            <select name="groupe_id" aria-label="Groupe" class="rounded-xl border border-slate-300 px-3 py-2.5 text-sm min-h-[44px]">
                <option value="">Tous groupes</option>
                @foreach($groupes as $groupe)
                    <option value="{{ $groupe->id }}" @selected((string) request('groupe_id') === (string) $groupe->id)>{{ $groupe->code }}</option>
                @endforeach
            </select>
            <x-btn type="submit" variant="secondary">Filtrer</x-btn>
        </form>

        @if($presences->isNotEmpty())
            <div class="overflow-x-auto rounded-2xl border border-slate-200 bg-white shadow-sm">
                <table class="w-full min-w-[640px] text-left text-xs sm:text-sm">
                    <thead>
                        <tr class="border-b border-slate-100 text-[11px] uppercase tracking-wider text-slate-400">
                            <th scope="col" class="px-5 py-3 font-bold">Date</th>
                            <th scope="col" class="px-5 py-3 font-bold">Étudiant</th>
                            <th scope="col" class="px-5 py-3 font-bold">Séance</th>
                            <th scope="col" class="px-5 py-3 font-bold">Statut</th>
                            <th scope="col" class="px-5 py-3 font-bold text-right"><span class="sr-only">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach($presences as $presence)
                            <tr class="hover:bg-slate-50/60">
                                <td class="px-5 py-3 tabular-nums text-slate-700">{{ $presence->date->format('d/m/Y') }}</td>
                                <td class="px-5 py-3">
                                    <span class="block font-bold text-slate-900">{{ $presence->etudiant->nom_complet }}</span>
                                    <span class="block font-mono text-[11px] text-slate-500">{{ $presence->etudiant->cin_masquee }} · {{ $presence->etudiant->faculte?->code }}</span>
                                </td>
                                <td class="px-5 py-3 text-slate-600">{{ $presence->seance ?? '—' }}</td>
                                <td class="px-5 py-3">
                                    <x-status-badge statut="{{ $presence->statut }}" />
                                </td>
                                <td class="px-5 py-3 text-right">
                                    @can('attendance.delete')
                                        <form method="POST" action="{{ route('admin.presences.destroy', $presence) }}" class="inline">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="font-bold text-rose-700 underline underline-offset-2 hover:text-rose-500">Supprimer</button>
                                        </form>
                                    @endcan
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="mt-4">{{ $presences->links() }}</div>
        @else
            <x-empty-state message="Aucun relevé de présence pour ce périmètre." />
        @endif
    </div>

    {{-- ========================================================================= --}}
    {{-- ONGLET 3 : APPELS DE SÉANCES (ÉTUDIANTS)                                  --}}
    {{-- ========================================================================= --}}
    <div x-show="onglet === 'appels'" x-cloak class="mt-6 space-y-4">
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 p-4 rounded-2xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 shadow-sm">
            <div>
                <h2 class="text-sm font-bold text-slate-900 dark:text-slate-100">
                    Appels de présences enregistrés par les enseignants
                </h2>
                <p class="text-xs text-slate-500">
                    Suivi des feuilles d'appel, sessions incomplètes et statistiques de présence par séance.
                </p>
            </div>
            <form method="GET" action="{{ route('admin.presences.index') }}" class="flex items-center gap-2">
                <input type="hidden" name="onglet" value="appels">
                <select name="statut_appel" class="rounded-xl border border-slate-300 dark:border-slate-700 px-3 py-1.5 text-xs bg-white dark:bg-slate-900 min-h-[38px]">
                    <option value="">Tous les états</option>
                    <option value="incomplet" @selected(request('statut_appel') === 'incomplet')>⚠️ Appels incomplets</option>
                    <option value="en_cours" @selected(request('statut_appel') === 'en_cours')>En cours</option>
                    <option value="cloture" @selected(request('statut_appel') === 'cloture')>Clôturés</option>
                </select>
                <x-btn type="submit" variant="secondary" class="text-xs py-1.5">Filtrer</x-btn>
            </form>
        </div>

        @if($appelsSeances->isNotEmpty())
            <div class="overflow-x-auto rounded-2xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 shadow-sm">
                <table class="w-full min-w-[700px] text-left text-xs sm:text-sm">
                    <thead>
                        <tr class="border-b border-slate-100 dark:border-slate-800 text-[11px] uppercase tracking-wider text-slate-400">
                            <th scope="col" class="px-5 py-3 font-bold">Date & Horaire</th>
                            <th scope="col" class="px-5 py-3 font-bold">Séance / Matière</th>
                            <th scope="col" class="px-5 py-3 font-bold">Groupe & Salle</th>
                            <th scope="col" class="px-5 py-3 font-bold">Enseignant</th>
                            <th scope="col" class="px-5 py-3 font-bold">Statut</th>
                            <th scope="col" class="px-5 py-3 font-bold">Compteurs</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                        @foreach($appelsSeances as $seanceAppel)
                            <tr class="hover:bg-slate-50/60 dark:hover:bg-slate-800/30">
                                <td class="px-5 py-3 tabular-nums text-slate-700 dark:text-slate-300">
                                    <span class="font-bold">{{ $seanceAppel->date->format('d/m/Y') }}</span>
                                    <span class="block text-xs text-slate-400">
                                        {{ substr((string) $seanceAppel->slot?->start_time, 0, 5) }} → {{ substr((string) $seanceAppel->slot?->end_time, 0, 5) }}
                                    </span>
                                </td>
                                <td class="px-5 py-3 font-bold text-slate-900 dark:text-slate-100">
                                    {{ $seanceAppel->slot?->matiere?->nom ?? '—' }}
                                    <span class="block text-[11px] font-normal text-slate-500 uppercase">{{ $seanceAppel->slot?->type }}</span>
                                </td>
                                <td class="px-5 py-3 text-slate-600 dark:text-slate-400">
                                    <span class="font-bold text-slate-800 dark:text-slate-200">{{ $seanceAppel->slot?->schedule?->groupe?->nom ?? '—' }}</span>
                                    <span class="block text-xs text-slate-400">Salle {{ $seanceAppel->slot?->room?->code ?? '—' }}</span>
                                </td>
                                <td class="px-5 py-3 font-medium text-slate-700 dark:text-slate-300">
                                    {{ $seanceAppel->enseignant?->nomComplet() ?? '—' }}
                                </td>
                                <td class="px-5 py-3">
                                    <x-status-badge :statut="$seanceAppel->statut" />
                                    @if($seanceAppel->non_renseignes > 0)
                                        <span class="inline-block mt-1 px-2 py-0.5 rounded text-[10px] font-bold bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-300">
                                            {{ $seanceAppel->non_renseignes }} non renseigné(s)
                                        </span>
                                    @endif
                                </td>
                                <td class="px-5 py-3 tabular-nums text-xs">
                                    <div class="flex items-center gap-2">
                                        <span class="text-emerald-700 dark:text-emerald-400 font-bold" title="Présents">{{ $seanceAppel->presents }} P</span>
                                        <span class="text-rose-700 dark:text-rose-400 font-bold" title="Absents">{{ $seanceAppel->absents }} A</span>
                                        <span class="text-amber-700 dark:text-amber-400 font-bold" title="Retards">{{ $seanceAppel->retards }} R</span>
                                        <span class="text-slate-500 font-medium" title="Non renseignés">{{ $seanceAppel->non_renseignes }} NR</span>
                                    </div>
                                    @if($seanceAppel->cloture_le)
                                        <span class="block text-[10px] text-slate-400 mt-0.5">Clôturé le {{ $seanceAppel->cloture_le->format('d/m/Y H:i') }}</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="mt-4">{{ $appelsSeances->links() }}</div>
        @else
            <x-empty-state message="Aucun appel de séance enregistré pour ce périmètre." />
        @endif
    </div>

    {{-- ========================================================================= --}}
    {{-- MODALES D'INTERACTION MÉTIER (ACCESSIBLES CLAVIER & ESCAPE)               --}}
    {{-- ========================================================================= --}}

    {{-- Modale 1 : Nouveau pointage brut enseignant --}}
    <x-modal titre="Enregistrer un pointage enseignant" nom="modalPointage">
        <form method="POST" action="{{ route('admin.presences.enseignants.pointage') }}" class="space-y-4">
            @csrf
            <div>
                <label class="block text-xs font-bold text-slate-700 mb-1" for="pt_teacher_id">Enseignant</label>
                <select id="pt_teacher_id" name="teacher_id" required class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm min-h-[44px]">
                    @foreach($enseignants as $ens)
                        <option value="{{ $ens->id }}">{{ $ens->nomComplet() }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label class="block text-xs font-bold text-slate-700 mb-1" for="pt_horodatage">Date et heure du pointage</label>
                <input type="datetime-local" id="pt_horodatage" name="horodatage" value="{{ now()->format('Y-m-d\TH:i') }}" required
                       class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm min-h-[44px]">
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1" for="pt_type">Type</label>
                    <select id="pt_type" name="type" required class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm min-h-[44px]">
                        <option value="entree">Entrée (Arrivée)</option>
                        <option value="sortie">Sortie (Départ)</option>
                        <option value="presence">Pointage ponctuel</option>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1" for="pt_source">Source</label>
                    <select id="pt_source" name="source" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm min-h-[44px]">
                        <option value="manuel">Saisie manuelle</option>
                        <option value="badgeuse">Badgeuse</option>
                        <option value="terminal">Borne / Terminal</option>
                        <option value="qr">Scan QR Code</option>
                        <option value="portail">Portail web</option>
                    </select>
                </div>
            </div>

            <div>
                <label class="block text-xs font-bold text-slate-700 mb-1" for="pt_notes">Notes / Observations</label>
                <textarea id="pt_notes" name="notes" rows="2" placeholder="Remarque optionnelle..."
                          class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm"></textarea>
            </div>

            <div class="flex justify-end gap-2 pt-2 border-t border-slate-100">
                <x-btn type="button" variant="ghost" @click="modalPointage = false">Annuler</x-btn>
                <x-btn type="submit" variant="primary">Enregistrer le pointage</x-btn>
            </div>
        </form>
    </x-modal>

    {{-- Modale 2 : Diagnostic et détail des pointages bruts --}}
    <x-modal titre="Diagnostic & Pointages bruts" nom="modalDiagnostic">
        <template x-if="seanceActive">
            <div class="space-y-4">
                <div class="rounded-xl bg-slate-50 p-3 border border-slate-200">
                    <p class="text-xs font-bold text-slate-500 uppercase">Séance</p>
                    <p class="font-bold text-slate-900" x-text="seanceActive.matiere?.nom + ' (' + seanceActive.type_seance?.toUpperCase() + ')'"></p>
                    <p class="text-xs text-slate-600 font-mono" x-text="seanceActive.heure_debut + ' - ' + seanceActive.heure_fin + ' · ' + (seanceActive.groupe?.code || '') + ' · ' + (seanceActive.salle?.nom || seanceActive.salle?.code || '')"></p>
                </div>

                <div>
                    <p class="text-xs font-bold text-slate-500 uppercase mb-1">Règle de diagnostic</p>
                    <div class="rounded-xl border border-slate-200 p-3 text-xs bg-white text-slate-700 font-mono" x-text="seanceActive.diagnostic"></div>
                </div>

                <div>
                    <p class="text-xs font-bold text-slate-500 uppercase mb-2">Pointages bruts capturés</p>
                    <template x-if="seanceActive.pointages && seanceActive.pointages.length > 0">
                        <div class="space-y-1.5 max-h-40 overflow-y-auto">
                            <template x-for="pt in seanceActive.pointages" :key="pt.id">
                                <div class="flex items-center justify-between p-2 rounded-lg bg-slate-100 text-xs">
                                    <span class="font-mono font-bold text-slate-800" x-text="pt.horodatage.substring(11, 19)"></span>
                                    <span class="font-bold uppercase text-[10px] px-1.5 py-0.5 rounded bg-white text-slate-700" x-text="pt.type"></span>
                                    <span class="text-slate-500" x-text="pt.source"></span>
                                </div>
                            </template>
                        </div>
                    </template>
                    <template x-if="!seanceActive.pointages || seanceActive.pointages.length === 0">
                        <p class="text-xs text-slate-400 italic">Aucun pointage brut enregistré pour cette plage horaire.</p>
                    </template>
                </div>

                <div class="flex justify-end pt-2 border-t border-slate-100">
                    <x-btn type="button" variant="secondary" @click="modalDiagnostic = false">Fermer</x-btn>
                </div>
            </div>
        </template>
    </x-modal>

    {{-- Modale 3 : Valider une absence officielle --}}
    <x-modal titre="Validation officielle d'absence" nom="modalValider">
        <form :action="'{{ url('admin/presences/enseignants') }}/' + presenceSelectionnee + '/valider'" method="POST" class="space-y-4">
            @csrf
            <input type="hidden" name="action" value="absence_validee">
            <p class="text-xs text-slate-600">
                La validation d'une absence confirme administrativement le manquement. Un motif explicite est obligatoire.
            </p>
            <div>
                <label class="block text-xs font-bold text-slate-700 mb-1" for="val_motif">Motif d'absence officiel *</label>
                <textarea id="val_motif" name="motif" required rows="3" placeholder="Ex: Absence constatée par les étudiants et confirmée par le chef de département..."
                          class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm"></textarea>
            </div>
            <div class="flex justify-end gap-2 pt-2 border-t border-slate-100">
                <x-btn type="button" variant="ghost" @click="modalValider = false">Annuler</x-btn>
                <x-btn type="submit" variant="danger">Confirmer l'absence validée</x-btn>
            </div>
        </form>
    </x-modal>

    {{-- Modale 4 : Confirmer manuellement la présence --}}
    <x-modal titre="Confirmer la présence" nom="modalConfirmer">
        <form :action="'{{ url('admin/presences/enseignants') }}/' + presenceSelectionnee + '/confirmer'" method="POST" class="space-y-4">
            @csrf
            <p class="text-xs text-slate-600">
                Cette action confirme la présence de l'enseignant même en cas de défaillance matérielle de pointage.
            </p>
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1" for="conf_arr">Heure d'arrivée</label>
                    <input type="time" id="conf_arr" name="heure_arrivee" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm min-h-[44px]">
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1" for="conf_dep">Heure de départ</label>
                    <input type="time" id="conf_dep" name="heure_depart" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm min-h-[44px]">
                </div>
            </div>
            <div>
                <label class="block text-xs font-bold text-slate-700 mb-1" for="conf_motif">Justification / Renseignement</label>
                <input type="text" id="conf_motif" name="motif" placeholder="Ex: Émargement sur feuille papier signé"
                       class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm min-h-[44px]">
            </div>
            <div class="flex justify-end gap-2 pt-2 border-t border-slate-100">
                <x-btn type="button" variant="ghost" @click="modalConfirmer = false">Annuler</x-btn>
                <x-btn type="submit" variant="primary">Confirmer la présence</x-btn>
            </div>
        </form>
    </x-modal>

    {{-- Modale 5 : Correction avec motif obligatoire --}}
    <x-modal titre="Correction de présence" nom="modalCorriger">
        <form :action="'{{ url('admin/presences/enseignants') }}/' + presenceSelectionnee + '/corriger'" method="POST" class="space-y-4">
            @csrf
            <div>
                <label class="block text-xs font-bold text-slate-700 mb-1" for="cor_statut">Nouveau statut validé *</label>
                <select id="cor_statut" name="statut_valide" required class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm min-h-[44px]">
                    <option value="presence_confirmee">Présence confirmée</option>
                    <option value="retard_justifie">Retard justifié</option>
                    <option value="dispense_mission">Dispense / Ordre de mission</option>
                    <option value="absence_validee">Absence validée</option>
                    <option value="absence_rejetee">Absence rejetée</option>
                </select>
            </div>
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1" for="cor_arr">Heure d'arrivée</label>
                    <input type="time" id="cor_arr" name="heure_arrivee" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm min-h-[44px]">
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1" for="cor_dep">Heure de départ</label>
                    <input type="time" id="cor_dep" name="heure_depart" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm min-h-[44px]">
                </div>
            </div>
            <div>
                <label class="block text-xs font-bold text-slate-700 mb-1" for="cor_motif">Motif obligatoire de la correction *</label>
                <textarea id="cor_motif" name="motif" required rows="2" placeholder="Justification de la correction..."
                          class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm"></textarea>
            </div>
            <div class="flex justify-end gap-2 pt-2 border-t border-slate-100">
                <x-btn type="button" variant="ghost" @click="modalCorriger = false">Annuler</x-btn>
                <x-btn type="submit" variant="primary">Enregistrer la correction</x-btn>
            </div>
        </form>
    </x-modal>

    {{-- Modale 6 : Historique d'audit --}}
    <x-modal titre="Historique d'audit de la séance" nom="modalHistorique">
        <div class="space-y-3">
            <template x-if="historiqueLignes.length > 0">
                <div class="space-y-2 max-h-60 overflow-y-auto">
                    <template x-for="(ligne, idx) in historiqueLignes" :key="idx">
                        <div class="p-2.5 rounded-xl border border-slate-200 bg-slate-50 text-xs">
                            <div class="flex items-center justify-between font-bold text-slate-800">
                                <span x-text="ligne.action"></span>
                                <span class="font-mono text-[10px] text-slate-400" x-text="ligne.date"></span>
                            </div>
                            <p class="mt-1 text-slate-600" x-text="ligne.motif || 'Aucun motif'"></p>
                        </div>
                    </template>
                </div>
            </template>
            <template x-if="historiqueLignes.length === 0">
                <p class="text-xs text-slate-400 italic">Aucune modification enregistrée dans l'historique.</p>
            </template>
            <div class="flex justify-end pt-2 border-t border-slate-100">
                <x-btn type="button" variant="secondary" @click="modalHistorique = false">Fermer</x-btn>
            </div>
        </div>
    </x-modal>

</div>
@endsection
