@extends('layouts.app')

@section('title', 'File de traitement des inscriptions')

@section('content')
<div class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
    <x-page-header
        titre="File administrative des inscriptions"
        description="Traitement ordonné des dossiers, suivi des délais (FIFO), détection des dossiers en souffrance (> 48h) et actions attendues.">
        <x-slot:actions>
            <div class="flex items-center gap-2">
                <a href="{{ route('admin.verification.index') }}" class="inline-flex items-center gap-1.5 rounded-xl border border-slate-200 bg-white px-3.5 py-2 text-xs font-bold text-slate-700 hover:bg-slate-50 transition-smooth">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                    Dossiers bloqués
                </a>
                <a href="{{ route('admin.etudiants.index') }}" class="inline-flex items-center gap-1.5 rounded-xl border border-slate-200 bg-white px-3.5 py-2 text-xs font-bold text-slate-700 hover:bg-slate-50 transition-smooth">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 10h16M4 14h16M4 18h16"/></svg>
                    Registre général
                </a>
            </div>
        </x-slot:actions>
    </x-page-header>

    @if(session('status'))
        <div class="mt-4"><x-alert type="info">{{ session('status') }}</x-alert></div>
    @endif
    @if(session('success'))
        <div class="mt-4"><x-alert type="success">{{ session('success') }}</x-alert></div>
    @endif
    @if($errors->any())
        <div class="mt-4"><x-alert type="error" titre="Erreur">{{ $errors->first() }}</x-alert></div>
    @endif

    {{-- ── Indicateurs de file (Volumes, Délais, Souffrance) ── --}}
    <div class="mt-6 grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-7" role="region" aria-label="Indicateurs de la file de traitement">
        {{-- Total en attente --}}
        <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-xs">
            <p class="text-[11px] font-black uppercase tracking-wider text-slate-500">En attente</p>
            <p class="mt-1.5 text-2xl font-black tabular-nums text-slate-900">{{ $kpis['en_attente'] }}</p>
            <p class="mt-0.5 text-[11px] text-slate-500">sur {{ $kpis['total'] }} dossiers</p>
        </div>

        {{-- En souffrance > 48h --}}
        <div class="rounded-2xl border p-4 shadow-xs {{ $kpis['souffrance_48h'] > 0 ? 'border-rose-300 bg-rose-50/70 text-rose-900' : 'border-slate-200 bg-white text-slate-900' }}"
             title="Dossiers en attente depuis plus de 48 heures (dont plus de 7 jours détaillés ci-dessous)">
            <p class="text-[11px] font-black uppercase tracking-wider {{ $kpis['souffrance_48h'] > 0 ? 'text-rose-700' : 'text-slate-500' }}">En souffrance &gt; 48h</p>
            <div class="mt-1.5 flex items-baseline gap-2">
                <span class="text-2xl font-black tabular-nums {{ $kpis['souffrance_48h'] > 0 ? 'text-rose-600' : 'text-slate-900' }}">{{ $kpis['souffrance_48h'] }}</span>
                @if($kpis['souffrance_48h'] > 0)
                    <span class="inline-flex items-center rounded-full bg-rose-200 px-2 py-0.5 text-[10px] font-bold text-rose-800">Priorité</span>
                @endif
            </div>
            <p class="mt-0.5 text-[11px] {{ $kpis['souffrance_48h'] > 0 ? 'text-rose-600' : 'text-slate-500' }}">&gt; 7j : {{ $kpis['souffrance_7j'] }}</p>
        </div>

        {{-- Délai moyen d'attente --}}
        <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-xs"
             title="Ancienneté moyenne des dossiers en attente (0 si aucun dossier en attente)">
            <p class="text-[11px] font-black uppercase tracking-wider text-slate-500">Délai moyen</p>
            <p class="mt-1.5 text-2xl font-black tabular-nums text-slate-900">
                @if($kpis['delai_moyen_heures'] >= 24)
                    {{ $kpis['delai_moyen_jours'] }} <span class="text-sm font-semibold text-slate-400">jours</span>
                @else
                    {{ $kpis['delai_moyen_heures'] }} <span class="text-sm font-semibold text-slate-400">heures</span>
                @endif
            </p>
            <p class="mt-0.5 text-[11px] text-slate-500">ancienneté moyenne</p>
        </div>

        {{-- À instruire --}}
        <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-xs">
            <p class="text-[11px] font-black uppercase tracking-wider text-slate-500">À instruire</p>
            <p class="mt-1.5 text-2xl font-black tabular-nums text-primary-600">{{ $kpis['actions']['A_INSTRUIRE'] ?? 0 }}</p>
            <p class="mt-0.5 text-[11px] text-slate-500">prêts pour contrôle</p>
        </div>

        {{-- Complément ou anomalie --}}
        <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-xs">
            <p class="text-[11px] font-black uppercase tracking-wider text-slate-500">Blocages / Compléments</p>
            <p class="mt-1.5 text-2xl font-black tabular-nums text-amber-600">
                {{ ($kpis['actions']['COMPLEMENT_ATTENDU'] ?? 0) + ($kpis['actions']['ANOMALIE_DETECTEE'] ?? 0) }}
            </p>
            <p class="mt-0.5 text-[11px] text-slate-500">
                {{ $kpis['actions']['COMPLEMENT_ATTENDU'] ?? 0 }} att. / {{ $kpis['actions']['ANOMALIE_DETECTEE'] ?? 0 }} ano.
            </p>
        </div>

        {{-- Traités aujourd'hui --}}
        <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-xs">
            <p class="text-[11px] font-black uppercase tracking-wider text-slate-500">Traités aujourd’hui</p>
            <p class="mt-1.5 text-2xl font-black tabular-nums text-emerald-600">
                +{{ $kpis['valides_aujourdhui'] + $kpis['rejetes_aujourdhui'] }}
            </p>
            <p class="mt-0.5 text-[11px] text-slate-500">{{ $kpis['valides_aujourdhui'] }} val. / {{ $kpis['rejetes_aujourdhui'] }} rej.</p>
        </div>

        {{-- LOOP D : automatisation (§14) — agrégats sans PII --}}
        <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-xs"
             title="Taux de réussite de l'affectation automatique après validation (audits affectation.auto_assignee / auto_non_assignee) et volume de changements de groupe administratifs.">
            <p class="text-[11px] font-black uppercase tracking-wider text-slate-500">Affectation auto</p>
            <p class="mt-1.5 text-2xl font-black tabular-nums text-slate-900">
                {{ $kpis['affectations_auto']['taux_reussite'] !== null ? $kpis['affectations_auto']['taux_reussite'].' %' : '—' }}
            </p>
            <p class="mt-0.5 text-[11px] text-slate-500">
                {{ $kpis['affectations_auto']['reussies'] }} réussies / {{ $kpis['affectations_auto']['tentatives'] }} tentatives
            </p>
            <p class="mt-1 text-[11px] text-slate-400">{{ $kpis['changements_groupe']['effectues'] }} changement(s) de groupe</p>
        </div>
    </div>

    {{-- ── Filtres et tri de la file ── --}}
    <div class="mt-6 rounded-2xl border border-slate-200 bg-white p-5 shadow-xs">
        <form method="get" action="{{ route('admin.inscriptions.file-traitement') }}" class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-6 items-end">
            {{-- Recherche --}}
            <div class="lg:col-span-2">
                <label for="filtre-search" class="mb-1 block text-xs font-bold uppercase tracking-wider text-slate-500">Recherche</label>
                <input type="text" name="search" id="filtre-search" value="{{ $filtres['search'] ?? '' }}"
                       placeholder="Nom, prénom, CIN, matricule..."
                       class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm focus:border-primary-500 focus:outline-hidden">
            </div>

            {{-- Statut --}}
            <div>
                <label for="filtre-statut" class="mb-1 block text-xs font-bold uppercase tracking-wider text-slate-500">Statut dossier</label>
                <select name="statut" id="filtre-statut" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm focus:border-primary-500 focus:outline-hidden">
                    <option value="">Tous les statuts</option>
                    <option value="en_attente" @selected(($filtres['statut'] ?? '') === 'en_attente')>En attente</option>
                    <option value="valide" @selected(($filtres['statut'] ?? '') === 'valide')>Validé</option>
                    <option value="rejete" @selected(($filtres['statut'] ?? '') === 'rejete')>Rejeté</option>
                </select>
            </div>

            {{-- Action attendue --}}
            <div>
                <label for="filtre-action" class="mb-1 block text-xs font-bold uppercase tracking-wider text-slate-500">Action attendue</label>
                <select name="action_attendue" id="filtre-action" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm focus:border-primary-500 focus:outline-hidden">
                    <option value="">Toutes les actions</option>
                    @foreach($actionsAttendues as $cle => $libelle)
                        <option value="{{ $cle }}" @selected(($filtres['action_attendue'] ?? '') === $cle)>{{ $libelle }}</option>
                    @endforeach
                </select>
            </div>

            {{-- LOOP C : catégorie dérivée (familles de règles du dernier run) --}}
            <div>
                <label for="filtre-categorie" class="mb-1 block text-xs font-bold uppercase tracking-wider text-slate-500">Catégorie</label>
                <select name="categorie" id="filtre-categorie" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm focus:border-primary-500 focus:outline-hidden">
                    <option value="">Toutes les catégories</option>
                    @foreach($categoriesFile as $cle => $libelle)
                        <option value="{{ $cle }}" @selected(($filtres['categorie'] ?? '') === $cle)>{{ $libelle }}</option>
                    @endforeach
                </select>
            </div>

            {{-- Ancienneté minimale --}}
            <div>
                <label for="filtre-depose" class="mb-1 block text-xs font-bold uppercase tracking-wider text-slate-500">Déposé depuis</label>
                <select name="depose_depuis" id="filtre-depose" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm focus:border-primary-500 focus:outline-hidden">
                    <option value="">Toute ancienneté</option>
                    <option value="2" @selected(($filtres['depose_depuis'] ?? '') == 2)>&ge; 48 heures (&ge; 2 jours)</option>
                    <option value="5" @selected(($filtres['depose_depuis'] ?? '') == 5)>&ge; 5 jours</option>
                    <option value="7" @selected(($filtres['depose_depuis'] ?? '') == 7)>&ge; 7 jours (1 semaine)</option>
                    <option value="14" @selected(($filtres['depose_depuis'] ?? '') == 14)>&ge; 14 jours</option>
                </select>
            </div>

            {{-- Tri --}}
            <div>
                <label for="filtre-tri" class="mb-1 block text-xs font-bold uppercase tracking-wider text-slate-500">Tri par</label>
                <select name="tri" id="filtre-tri" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm focus:border-primary-500 focus:outline-hidden">
                    <option value="created_at" @selected(($filtres['tri'] ?? '') === 'created_at' || ($filtres['tri'] ?? '') === 'anciennete')>Ancienneté dépôt</option>
                    <option value="nom" @selected(($filtres['tri'] ?? '') === 'nom')>Nom étudiant</option>
                    <option value="num_inscription" @selected(($filtres['tri'] ?? '') === 'num_inscription')>Matricule</option>
                    <option value="statut" @selected(($filtres['tri'] ?? '') === 'statut')>Statut</option>
                </select>
            </div>

            {{-- Sens du tri et boutons --}}
            <div class="sm:col-span-2 lg:col-span-6 flex flex-wrap items-center justify-between gap-3 pt-2 border-t border-slate-100">
                <div class="flex items-center gap-3">
                    <label class="inline-flex items-center gap-1.5 text-xs font-bold text-slate-600">
                        <input type="radio" name="sens" value="asc" @checked(($filtres['sens'] ?? 'asc') === 'asc') class="text-primary-600">
                        FIFO (plus anciens d'abord)
                    </label>
                    <label class="inline-flex items-center gap-1.5 text-xs font-bold text-slate-600">
                        <input type="radio" name="sens" value="desc" @checked(($filtres['sens'] ?? '') === 'desc') class="text-primary-600">
                        LIFO (plus récents d'abord)
                    </label>
                </div>

                <div class="flex items-center gap-2">
                    @if($filtresActifs > 0)
                        <span class="rounded-full bg-primary-50 px-2.5 py-1 text-[11px] font-bold text-primary-700 border border-primary-200">{{ $filtresActifs }} filtre{{ $filtresActifs > 1 ? 's' : '' }} actif{{ $filtresActifs > 1 ? 's' : '' }}</span>
                    @endif
                    <a href="{{ route('admin.inscriptions.file-traitement') }}" class="rounded-xl border border-slate-200 px-3 py-2 text-xs font-bold text-slate-600 hover:bg-slate-50">
                        Réinitialiser
                    </a>
                    <x-btn type="submit" variant="primary">Appliquer les filtres</x-btn>
                </div>
            </div>
        </form>
    </div>

    {{-- ── Liste des dossiers de la file ── --}}
    <div class="mt-6 rounded-2xl border border-slate-200 bg-white shadow-xs overflow-hidden" x-data="{ noteModalOpen: false, etudiantId: null, etudiantNom: '', actionUrl: '', envoiNote: false }">
        <div class="border-b border-slate-200 px-6 py-4 flex items-center justify-between">
            <h2 class="text-sm font-black uppercase tracking-wider text-slate-700">
                Dossiers en file ({{ $etudiants->total() }})
            </h2>
            <span class="text-xs text-slate-500 font-medium">Page {{ $etudiants->currentPage() }} sur {{ max(1, $etudiants->lastPage()) }}</span>
        </div>

        @if($etudiants->isEmpty())
            <div class="p-8">
                <x-empty-state message="Aucun dossier ne correspond aux critères de filtrage de la file." />
            </div>
        @else
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="border-b border-slate-200 bg-slate-50/70 text-left text-xs uppercase tracking-wider text-slate-500">
                            <th class="px-4 py-3">Étudiant &amp; Matricule</th>
                            <th class="px-4 py-3">Faculté &amp; Parcours</th>
                            <th class="px-4 py-3">Ancienneté</th>
                            <th class="px-4 py-3">Statut</th>
                            <th class="px-4 py-3">Action attendue</th>
                            <th class="px-4 py-3 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach($etudiants as $etudiant)
                            <tr class="hover:bg-slate-50/60 transition-colors {{ $etudiant->est_en_souffrance ? 'bg-rose-50/30' : '' }}">
                                {{-- Identité --}}
                                <td class="px-4 py-3.5">
                                    <div class="font-bold text-slate-900">{{ $etudiant->nom_complet }}</div>
                                    <div class="mt-0.5 flex items-center gap-2 text-xs text-slate-500 font-mono">
                                        <span>{{ $etudiant->num_inscription }}</span>
                                        @if($etudiant->cin)
                                            <span>· CIN: {{ $etudiant->cin }}</span>
                                        @endif
                                    </div>
                                </td>

                                {{-- Faculté / Parcours --}}
                                <td class="px-4 py-3.5">
                                    <div class="text-xs font-semibold text-slate-800">{{ $etudiant->faculte?->nom ?? '—' }}</div>
                                    <div class="mt-0.5 text-xs text-slate-500">
                                        {{ $etudiant->programmeRef?->code ?? $etudiant->niveau ?? '—' }}
                                        @if($etudiant->groupe)
                                            <span class="rounded-md bg-slate-100 px-1.5 py-0.5 font-bold text-slate-700">Gr. {{ $etudiant->groupe->nom }}</span>
                                        @endif
                                    </div>
                                </td>

                                {{-- Ancienneté & Souffrance --}}
                                <td class="px-4 py-3.5">
                                    <div class="flex items-center gap-2">
                                        @if($etudiant->est_en_souffrance)
                                            <span class="inline-flex h-2.5 w-2.5 rounded-full bg-rose-500 animate-pulse" title="Dossier en souffrance (> 48h)"></span>
                                        @endif
                                        <span class="text-xs font-bold tabular-nums {{ $etudiant->est_en_souffrance ? 'text-rose-700' : 'text-slate-700' }}">
                                            @if($etudiant->anciennete_jours > 0)
                                                {{ $etudiant->anciennete_jours }}j {{ $etudiant->anciennete_heures % 24 }}h
                                            @else
                                                {{ $etudiant->anciennete_heures }}h
                                            @endif
                                        </span>
                                    </div>
                                    <div class="text-[10px] text-slate-400">
                                        Déposé le {{ $etudiant->created_at?->format('d/m/Y H:i') }}
                                    </div>
                                </td>

                                {{-- Statut --}}
                                <td class="px-4 py-3.5">
                                    @if($etudiant->statut === 'valide')
                                        <span class="inline-flex items-center rounded-lg bg-emerald-50 px-2 py-0.5 text-xs font-bold text-emerald-700 border border-emerald-200">
                                            Validé
                                        </span>
                                    @elseif($etudiant->statut === 'rejete')
                                        <span class="inline-flex items-center rounded-lg bg-rose-50 px-2 py-0.5 text-xs font-bold text-rose-700 border border-rose-200">
                                            Rejeté
                                        </span>
                                    @else
                                        <span class="inline-flex items-center rounded-lg bg-amber-50 px-2 py-0.5 text-xs font-bold text-amber-700 border border-amber-200">
                                            En attente
                                        </span>
                                    @endif
                                </td>

                                {{-- Action attendue + catégorie dérivée (LOOP C) --}}
                                <td class="px-4 py-3.5">
                                    @php
                                        $badgeClasses = match($etudiant->action_attendue) {
                                            'A_INSTRUIRE' => 'bg-primary-50 text-primary-700 border-primary-200',
                                            'COMPLEMENT_ATTENDU' => 'bg-amber-50 text-amber-800 border-amber-200',
                                            'ANOMALIE_DETECTEE' => 'bg-rose-50 text-rose-800 border-rose-200',
                                            'A_AFFECTER' => 'bg-violet-50 text-violet-700 border-violet-200',
                                            default => 'bg-slate-50 text-slate-700 border-slate-200',
                                        };
                                        $categorieClasses = match($etudiant->categorie_file) {
                                            'MENS_REVIEW' => 'text-indigo-700 bg-indigo-50 border-indigo-200',
                                            'IDENTITY_REVIEW' => 'text-cyan-700 bg-cyan-50 border-cyan-200',
                                            'DUPLICATE_REVIEW' => 'text-fuchsia-700 bg-fuchsia-50 border-fuchsia-200',
                                            'MISSING_DOCUMENTS' => 'text-amber-700 bg-amber-50 border-amber-200',
                                            'BLOCKED' => 'text-rose-700 bg-rose-50 border-rose-200',
                                            'READY_FOR_ASSIGNMENT' => 'text-violet-700 bg-violet-50 border-violet-200',
                                            'ASSIGNED', 'COMPLETED' => 'text-emerald-700 bg-emerald-50 border-emerald-200',
                                            default => 'text-slate-600 bg-slate-50 border-slate-200',
                                        };
                                    @endphp
                                    <span class="inline-flex items-center rounded-lg border px-2.5 py-1 text-xs font-bold {{ $badgeClasses }}">
                                        {{ $etudiant->action_attendue_libelle }}
                                    </span>
                                    @if(!empty($etudiant->categorie_file))
                                        <div class="mt-1.5">
                                            <span class="inline-flex items-center rounded-md border px-2 py-0.5 text-[10px] font-bold {{ $categorieClasses }}"
                                                  title="{{ $etudiant->categorie_motif }}">
                                                {{ $etudiant->categorie_libelle }}
                                            </span>
                                            @if($etudiant->prochaine_action !== '—')
                                                <p class="mt-1 max-w-[16rem] text-[10px] leading-snug text-slate-500" title="{{ $etudiant->categorie_motif }}">
                                                    {{ $etudiant->prochaine_action }}
                                                </p>
                                            @endif
                                        </div>
                                    @endif
                                </td>

                                {{-- Actions --}}
                                <td class="px-4 py-3.5 text-right">
                                    <div class="inline-flex items-center gap-1.5">
                                        {{-- Note d'instruction rapide --}}
                                        <button type="button"
                                                @click="noteModalOpen = true; envoiNote = false; etudiantId = {{ $etudiant->id }}; etudiantNom = '{{ addslashes($etudiant->nom_complet) }}'; actionUrl = '{{ route('admin.inscriptions.file-traitement.commenter', $etudiant) }}'"
                                                class="rounded-lg border border-slate-200 bg-white px-2.5 py-1.5 text-xs font-bold text-slate-700 hover:bg-slate-50 transition-colors"
                                                title="Ajouter une note d'instruction">
                                            Note
                                        </button>

                                        {{-- Examiner le dossier (contrôle intelligent) --}}
                                        <a href="{{ route('admin.verification.show', $etudiant) }}"
                                           class="rounded-lg bg-primary-600 px-3 py-1.5 text-xs font-bold text-white hover:bg-primary-700 transition-colors shadow-2xs">
                                            Examiner
                                        </a>

                                        {{-- Vue 360° --}}
                                        <a href="{{ route('admin.etudiants.show', $etudiant) }}"
                                           class="rounded-lg border border-slate-200 px-2 py-1.5 text-xs font-bold text-slate-500 hover:text-slate-900 transition-colors"
                                           title="Fiche étudiant complète">
                                            360°
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="border-t border-slate-200 px-6 py-4">
                {{ $etudiants->links() }}
            </div>
        @endif

        {{-- ── Modale de note d'instruction administrative ── --}}
        <div x-show="noteModalOpen" x-cloak
             class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/60 backdrop-blur-xs p-4"
             role="dialog" aria-modal="true" aria-labelledby="modal-note-titre"
             data-reouverture="{{ session('note_etudiant_id') ?? '' }}"
             x-init="if ($el.dataset.reouverture) { noteModalOpen = true; etudiantId = $el.dataset.reouverture; actionUrl = '{{ route('admin.inscriptions.file-traitement.commenter', '__ID__') }}'.replace('__ID__', $el.dataset.reouverture); }">
            <div @click.away="noteModalOpen = false"
                 class="w-full max-w-lg rounded-3xl bg-white p-6 shadow-2xl">
                <div class="flex items-center justify-between border-b border-slate-100 pb-3">
                    <h3 id="modal-note-titre" class="text-base font-bold text-slate-900">
                        Note d'instruction administrative
                    </h3>
                    <button type="button" @click="noteModalOpen = false" class="text-slate-400 hover:text-slate-600">&times;</button>
                </div>

                <p class="mt-2 text-xs text-slate-600">
                    Étudiant : <strong x-text="etudiantNom"></strong>. Cette note sera tracée dans l'audit officiel et annexée à l'inscription académique.
                </p>

                <form :action="actionUrl" method="POST" class="mt-4 space-y-4" @submit="envoiNote = true">
                    @csrf
                    <div>
                        <label for="modal-motif" class="mb-1 block text-xs font-bold uppercase tracking-wider text-slate-500">Motif normalisé</label>
                        <select name="motif_cle" id="modal-motif" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm focus:border-primary-500 focus:outline-hidden">
                            <option value="">— Aucun motif spécifique —</option>
                            @foreach($motifsNormalises as $cle => $libelle)
                                <option value="{{ $cle }}" @selected(old('motif_cle') === $cle)>{{ $libelle }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div>
                        <label for="modal-commentaire" class="mb-1 block text-xs font-bold uppercase tracking-wider text-slate-500">Consigne ou observation détaillée *</label>
                        <textarea name="commentaire" id="modal-commentaire" rows="3" required minlength="3" maxlength="1000"
                                  placeholder="Indiquez les constatations de la scolarité, les pièces manquantes ou les consignes de traitement..."
                                  class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm focus:border-primary-500 focus:outline-hidden">{{ old('commentaire') }}</textarea>
                    </div>

                    <div class="flex justify-end gap-2 pt-2">
                        <button type="button" @click="noteModalOpen = false" class="rounded-xl border border-slate-200 px-4 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-50">
                            Annuler
                        </button>
                        <x-btn type="submit" variant="primary" x-bind:disabled="envoiNote">
                            <span x-text="envoiNote ? 'Enregistrement…' : 'Enregistrer la note'"></span>
                        </x-btn>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
@endsection
