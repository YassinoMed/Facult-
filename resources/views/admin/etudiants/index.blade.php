@extends('layouts.app')

@section('title', 'Registre des Inscriptions — Scolarité')
@section('meta_description', 'Administration universitaire — Consultation, recherche et validation des inscriptions.')

@section('content')
<div class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">

    {{-- ── En-tête Desktop avec Titre & Actions Rapides ── --}}
    <div class="mb-8 flex flex-col md:flex-row md:items-center md:justify-between gap-4">
        <div>
            <div class="flex items-center gap-2.5">
                <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-primary-100 text-primary-700">
                    <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 0 0 2.625.372 9.337 9.337 0 0 0 4.121-.952 4.125 4.125 0 0 0-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 0 1 8.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0 1 11.964-3.07M12 6.375a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0Zm8.25 2.25a2.625 2.625 0 1 1-5.25 0 2.625 2.625 0 0 1 5.25 0Z" />
                    </svg>
                </div>
                <div>
                    <h1 class="text-2xl sm:text-3xl font-black text-slate-900 tracking-tight" data-motion-block>Registre des Inscriptions</h1>
                    <p class="text-xs sm:text-sm text-slate-500">Gestion centrale des dossiers académiques, filtres multicritères et validation. <strong class="tabular-nums">{{ $etudiants->total() }}</strong> résultat(s).</p>
                </div>
            </div>
        </div>

        <div class="flex flex-wrap items-center justify-end gap-2.5">
            <button type="button"
                    onclick="window.print()"
                    class="no-print inline-flex items-center gap-2 rounded-xl border border-slate-200 bg-white px-3.5 py-2 text-xs font-bold text-slate-700 hover:bg-slate-50 shadow-xs transition-smooth">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6.72 13.829c-.24-1.076-.673-2.13-1.28-3.14m0 0A9.953 9.953 0 0 1 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-1.474 0-2.887-.319-4.158-.89m0 0L3 21l1.89-3.72" />
                </svg>
                Imprimer le registre
            </button>

            @can('create', App\Models\Etudiant::class)
                <a href="{{ route('admin.etudiants.creer') }}"
                   class="inline-flex items-center gap-2 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-2 text-xs font-bold text-emerald-800 shadow-xs hover:bg-emerald-100 transition-smooth">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2.2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                    </svg>
                    Créer un étudiant
                </a>
            @endcan

            <a href="{{ route('inscription.create') }}"
               class="inline-flex items-center gap-2 rounded-xl bg-gradient-to-r from-primary-600 to-accent-600 px-4 py-2 text-xs font-bold text-white shadow-md shadow-primary-500/20 hover:shadow-lg hover:shadow-primary-500/30 hover:-translate-y-0.5 transition-smooth">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2.2" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                </svg>
                Nouvelle Inscription
            </a>
        </div>
    </div>

    {{-- ── KPI Metric Cards ── --}}
    <div class="mb-4 flex flex-wrap gap-2">
        <a href="{{ route('admin.etudiants.sans-groupe') }}" class="inline-flex items-center gap-2 rounded-xl border border-amber-200 bg-amber-50 px-3.5 py-2 text-xs font-bold text-amber-800 hover:bg-amber-100">
            Sans groupe : {{ $stats['sans_groupe'] ?? 0 }} → affecter
        </a>
        <a href="{{ route('admin.listes.index') }}" class="inline-flex items-center gap-2 rounded-xl border border-slate-200 bg-white px-3.5 py-2 text-xs font-bold text-slate-700 hover:bg-slate-50">
            Listes / Exports
        </a>
        <a href="{{ route('admin.affectations.auto') }}" class="inline-flex items-center gap-2 rounded-xl border border-slate-200 bg-white px-3.5 py-2 text-xs font-bold text-slate-700 hover:bg-slate-50">
            Affectation automatique
        </a>
    </div>
    <div class="mb-8 grid grid-cols-2 gap-3 sm:gap-5 lg:grid-cols-4">
        {{-- Total --}}
        <div class="card-hover rounded-3xl border border-slate-200/80 bg-white p-5 shadow-xs relative overflow-hidden">
            <div class="flex items-center justify-between">
                <div>
                    <span class="text-xs font-bold uppercase tracking-wider text-slate-400">Total Candidats</span>
                    <p class="text-3xl font-black text-slate-900 mt-1">{{ $stats['total'] }}</p>
                </div>
                <div class="flex h-12 w-12 items-center justify-center rounded-2xl bg-primary-50 text-primary-600 border border-primary-100">
                    <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M18 18.72a9.094 9.094 0 0 0 3.741-.479 3 3 0 0 0-4.682-2.72m.94 3.198.001.031c0 .225-.012.447-.037.666A11.944 11.944 0 0 1 12 21c-2.17 0-4.207-.576-5.963-1.584A6.062 6.062 0 0 1 6 18.719m12 0a5.971 5.971 0 0 0-.941-3.197m0 0A5.995 5.995 0 0 0 12 12.75a5.995 5.995 0 0 0-5.058 2.772m0 0a3 3 0 0 0-4.681 2.72 8.986 8.986 0 0 0 3.74.477m.94-3.197a5.971 5.971 0 0 0-.94 3.197M15 6.75a3 3 0 1 1-6 0 3 3 0 0 1 6 0Zm6 3a2.25 2.25 0 1 1-4.5 0 2.25 2.25 0 0 1 4.5 0Zm-13.5 0a2.25 2.25 0 1 1-4.5 0 2.25 2.25 0 0 1 4.5 0Z" />
                    </svg>
                </div>
            </div>
            <div class="mt-4 flex items-center gap-1.5 text-xs text-slate-500">
                <span class="inline-block h-1.5 w-1.5 rounded-full bg-primary-500"></span>
                Inscriptions officielles enregistrées
            </div>
        </div>

        {{-- En attente --}}
        <div class="card-hover rounded-3xl border border-amber-200/90 bg-white p-5 shadow-xs relative overflow-hidden">
            <div class="flex items-center justify-between">
                <div>
                    <span class="text-xs font-bold uppercase tracking-wider text-amber-600">En Attente</span>
                    <p class="text-3xl font-black text-amber-600 mt-1">{{ $stats['en_attente'] }}</p>
                </div>
                <div class="flex h-12 w-12 items-center justify-center rounded-2xl bg-amber-50 text-amber-600 border border-amber-100">
                    <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                    </svg>
                </div>
            </div>
            <div class="mt-4 flex items-center gap-1.5 text-xs text-amber-700 font-medium">
                <span class="inline-block h-1.5 w-1.5 rounded-full bg-amber-500 animate-ping"></span>
                À vérifier par la scolarité
            </div>
        </div>

        {{-- Validés --}}
        <div class="card-hover rounded-3xl border border-emerald-200/90 bg-white p-5 shadow-xs relative overflow-hidden">
            <div class="flex items-center justify-between">
                <div>
                    <span class="text-xs font-bold uppercase tracking-wider text-emerald-600">Dossiers Validés</span>
                    <p class="text-3xl font-black text-emerald-600 mt-1">{{ $stats['valide'] }}</p>
                </div>
                <div class="flex h-12 w-12 items-center justify-center rounded-2xl bg-emerald-50 text-emerald-600 border border-emerald-100">
                    <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                    </svg>
                </div>
            </div>
            <div class="mt-4 flex items-center gap-1.5 text-xs text-emerald-700 font-medium">
                <span class="inline-block h-1.5 w-1.5 rounded-full bg-emerald-500"></span>
                Admissions confirmées
            </div>
        </div>

        {{-- Rejetés --}}
        <div class="card-hover rounded-3xl border border-rose-200/90 bg-white p-5 shadow-xs relative overflow-hidden">
            <div class="flex items-center justify-between">
                <div>
                    <span class="text-xs font-bold uppercase tracking-wider text-rose-600">Dossiers Rejetés</span>
                    <p class="text-3xl font-black text-rose-600 mt-1">{{ $stats['rejete'] }}</p>
                </div>
                <div class="flex h-12 w-12 items-center justify-center rounded-2xl bg-rose-50 text-rose-600 border border-rose-100">
                    <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="m9.75 9.75 4.5 4.5m0-4.5-4.5 4.5M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                    </svg>
                </div>
            </div>
            <div class="mt-4 flex items-center gap-1.5 text-xs text-rose-700 font-medium">
                <span class="inline-block h-1.5 w-1.5 rounded-full bg-rose-500"></span>
                Pièces non conformes
            </div>
        </div>
    </div>

    {{-- ── Toolbar Filtres & Recherche ── --}}
    <div class="mb-6 rounded-3xl border border-slate-200/80 bg-white p-5 shadow-xs backdrop-blur-md">
        <form method="GET" action="{{ route('admin.etudiants.index') }}" class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-12 items-end">
            {{-- Search Input --}}
            <div class="lg:col-span-4">
                <label for="search" class="mb-1.5 block text-xs font-bold uppercase tracking-wider text-slate-500">
                    Recherche instantanée
                </label>
                <div class="relative">
                    <svg class="absolute left-3.5 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z" />
                    </svg>
                    <input type="text" name="search" id="search" value="{{ request('search') }}"
                           class="w-full rounded-xl border border-slate-200 bg-slate-50/70 py-2.5 pl-10 pr-4 text-xs sm:text-sm text-slate-900 placeholder-slate-400 transition-smooth focus:border-primary-600 focus:bg-white focus:ring-4 focus:ring-primary-500/15 focus:outline-none"
                           placeholder="Nom, CIN, N° inscription, email, ville...">
                </div>
            </div>

            {{-- Faculté --}}
            <div class="lg:col-span-3">
                <label for="faculte_id" class="mb-1.5 block text-xs font-bold uppercase tracking-wider text-slate-500">
                    Faculté
                </label>
                <select name="faculte_id" id="faculte_id"
                        class="w-full rounded-xl border border-slate-200 bg-slate-50/70 py-2.5 px-3 text-xs sm:text-sm text-slate-900 transition-smooth focus:border-primary-600 focus:bg-white focus:ring-4 focus:ring-primary-500/15 focus:outline-none">
                    <option value="">Toutes les facultés</option>
                    @foreach($facultes as $f)
                        <option value="{{ $f->id }}" {{ request('faculte_id') == $f->id ? 'selected' : '' }}>
                            {{ $f->code }} — {{ Str::limit($f->nom, 26) }}
                        </option>
                    @endforeach
                </select>
            </div>

            {{-- Statut --}}
            <div class="lg:col-span-2">
                <label for="statut" class="mb-1.5 block text-xs font-bold uppercase tracking-wider text-slate-500">
                    Statut
                </label>
                <select name="statut" id="statut"
                        class="w-full rounded-xl border border-slate-200 bg-slate-50/70 py-2.5 px-3 text-xs sm:text-sm text-slate-900 transition-smooth focus:border-primary-600 focus:bg-white focus:ring-4 focus:ring-primary-500/15 focus:outline-none">
                    <option value="">Tous les statuts</option>
                    <option value="en_attente" {{ request('statut') == 'en_attente' ? 'selected' : '' }}>En attente</option>
                    <option value="valide" {{ request('statut') == 'valide' ? 'selected' : '' }}>Validé</option>
                    <option value="rejete" {{ request('statut') == 'rejete' ? 'selected' : '' }}>Rejeté</option>
                </select>
            </div>

            {{-- Niveau --}}
            <div class="lg:col-span-1">
                <label for="niveau" class="mb-1.5 block text-xs font-bold uppercase tracking-wider text-slate-500">
                    Niveau
                </label>
                <select name="niveau" id="niveau"
                        class="w-full rounded-xl border border-slate-200 bg-slate-50/70 py-2.5 px-2.5 text-xs sm:text-sm text-slate-900 transition-smooth focus:border-primary-600 focus:bg-white focus:ring-4 focus:ring-primary-500/15 focus:outline-none">
                    <option value="">Tous</option>
                    @foreach($niveaux as $niv)
                        <option value="{{ $niv }}" {{ request('niveau') == $niv ? 'selected' : '' }}>{{ $niv }}</option>
                    @endforeach
                </select>
            </div>

            {{-- Programme --}}
            <div class="lg:col-span-3">
                <label for="programme_id" class="mb-1.5 block text-xs font-bold uppercase tracking-wider text-slate-500">
                    Programme
                </label>
                <select name="programme_id" id="programme_id"
                        class="w-full rounded-xl border border-slate-200 bg-slate-50/70 py-2.5 px-3 text-xs sm:text-sm text-slate-900 focus:border-primary-600 focus:bg-white focus:outline-none">
                    <option value="">Tous programmes</option>
                    @foreach($programmes as $programme)
                        <option value="{{ $programme->id }}" {{ (string) request('programme_id') === (string) $programme->id ? 'selected' : '' }}>{{ $programme->code }} — {{ $programme->nom }}</option>
                    @endforeach
                </select>
            </div>

            {{-- Groupe --}}
            <div class="lg:col-span-3">
                <label for="groupe_id" class="mb-1.5 block text-xs font-bold uppercase tracking-wider text-slate-500">
                    Groupe
                </label>
                <select name="groupe_id" id="groupe_id"
                        class="w-full rounded-xl border border-slate-200 bg-slate-50/70 py-2.5 px-3 text-xs sm:text-sm text-slate-900 focus:border-primary-600 focus:bg-white focus:outline-none">
                    <option value="">Tous groupes</option>
                    @foreach($groupes as $groupe)
                        <option value="{{ $groupe->id }}" {{ (string) request('groupe_id') === (string) $groupe->id ? 'selected' : '' }}>{{ $groupe->code }}</option>
                    @endforeach
                </select>
            </div>

            <div class="lg:col-span-3 flex items-end gap-4 pb-2.5">
                <label class="inline-flex items-center gap-1.5 text-xs font-bold text-slate-600"><input type="checkbox" name="sans_groupe" value="1" @checked(request('sans_groupe')) /> Sans groupe</label>
                <label class="inline-flex items-center gap-1.5 text-xs font-bold text-slate-600">
                    Déposé depuis
                    <input type="number" name="depose_depuis" min="1" max="3650" value="{{ request('depose_depuis') }}" placeholder="jours"
                           class="w-20 rounded-lg border border-slate-200 px-2 py-1.5 text-xs" aria-label="Ancienneté minimale en jours" />
                    jours
                </label>
            </div>

            {{-- Actions --}}
            <div class="lg:col-span-2 flex items-center gap-2">
                <button type="submit"
                        class="flex-1 rounded-xl bg-slate-900 py-2.5 px-4 text-xs sm:text-sm font-bold text-white shadow-xs hover:bg-slate-800 transition-smooth">
                    Filtrer
                </button>
                @if(request()->hasAny(['search', 'faculte_id', 'statut', 'niveau']))
                    <a href="{{ route('admin.etudiants.index') }}"
                       title="Réinitialiser les filtres"
                       class="rounded-xl border border-slate-200 bg-white p-2.5 text-slate-500 hover:bg-slate-50 transition-smooth">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
                        </svg>
                    </a>
                @endif
            </div>
        </form>
        <div class="mt-3 flex flex-wrap items-center gap-2">
            <x-export-actions />
            @if($vues->isNotEmpty())
                @foreach($vues as $vue)
                    <a href="{{ route('admin.etudiants.index', $vue->filtres ?? []) }}" class="rounded-full border border-slate-200 bg-slate-50 px-2.5 py-1 text-xs font-bold text-slate-600">{{ $vue->nom }}</a>
                @endforeach
            @endif
            <details class="text-xs">
                <summary class="cursor-pointer font-bold text-primary-800 underline underline-offset-2 min-h-[44px] inline-flex items-center">Enregistrer la vue</summary>
                <form method="post" action="{{ route('admin.vues.store') }}" class="mt-1 flex gap-1.5">
                    @csrf
                    <input type="hidden" name="tableau" value="etudiants" />
                    @foreach(request()->except(['_token', 'page']) as $cle => $valeur)
                        <input type="hidden" name="filtres[{{ $cle }}]" value="{{ $valeur }}" />
                    @endforeach
                    <input name="nom" maxlength="80" required placeholder="Ma vue M2 DSIR" class="rounded-xl border border-slate-200 px-2.5 py-1.5 text-xs" />
                    <x-btn size="sm" variant="secondary" type="submit">OK</x-btn>
                </form>
            </details>
        </div>
    </div>

    {{-- ── Grand Tableau Desktop Élégant ── --}}
    <div x-data="registreBulk"
         data-bulk-url="{{ route('admin.etudiants.bulkStatut') }}" data-csrf="{{ csrf_token() }}">
    <div class="hidden md:block overflow-hidden rounded-3xl border border-slate-200/90 bg-white shadow-xs admin-table-scroll">
        <div class="overflow-x-auto">
            <table class="w-full min-w-[880px] text-left text-sm text-slate-600">
                <thead class="border-b border-slate-200/80 bg-slate-50/80 text-[11px] font-bold uppercase tracking-wider text-slate-400">
                    <tr>
                        @can('archives.update')
                            <th class="py-4 pl-5 pr-1 w-10">
                                <input type="checkbox" id="bulk-tout" @change="basculerTout($event.target.checked)"
                                       aria-label="Tout sélectionner" class="h-5 w-5 rounded border-slate-300">
                            </th>
                        @endcan
                        <th class="py-4 px-5">Numéro Inscription</th>
                        <th class="py-4 px-5">Étudiant & Contact</th>
                        <th class="py-4 px-5">CIN</th>
                        <th class="py-4 px-5">Faculté & Niveau</th>
                        <th class="py-4 px-5">Origine & Bac</th>
                        <th class="py-4 px-5">Statut Actuel</th>
                        <th class="py-4 px-5 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse($etudiants as $etudiant)
                        <tr class="transition-smooth hover:bg-slate-50/90 group">
                            @can('archives.update')
                                <td class="py-4 pl-5 pr-1">
                                    <input type="checkbox" data-bulk-id value="{{ $etudiant->id }}"
                                           aria-label="Sélectionner {{ $etudiant->nom_complet }}"
                                           class="h-5 w-5 rounded border-slate-300">
                                </td>
                            @endcan
                            {{-- N° Inscription --}}
                            <td class="py-4 px-5">
                                <span class="font-mono text-xs font-extrabold text-primary-800 bg-primary-50 px-2.5 py-1 rounded-lg border border-primary-200/70 inline-block shadow-2xs">
                                    {{ $etudiant->num_inscription }}
                                </span>
                            </td>

                            {{-- Étudiant & Email --}}
                            <td class="py-4 px-5">
                                <div class="flex items-center gap-3">
                                    <div class="flex h-9 w-9 items-center justify-center rounded-xl bg-gradient-to-tr from-slate-100 to-slate-200 text-xs font-bold text-slate-700 border border-slate-200">
                                        {{ strtoupper(substr($etudiant->prenom, 0, 1)) }}{{ strtoupper(substr($etudiant->nom, 0, 1)) }}
                                    </div>
                                    <div>
                                        <a href="{{ route('admin.etudiants.show', $etudiant) }}"
                                           class="font-bold text-slate-900 hover:text-primary-600 transition-smooth">
                                            {{ $etudiant->nom_complet }}
                                        </a>
                                        <span class="block text-xs text-slate-400">{{ $etudiant->email }}</span>
                                    </div>
                                </div>
                            </td>

                            {{-- CIN --}}
                            <td class="py-4 px-5">
                                <span class="font-mono text-xs font-semibold text-slate-700">
                                    {{ $etudiant->cin }}
                                </span>
                            </td>

                            {{-- Faculté & Niveau --}}
                            <td class="py-4 px-5">
                                <div class="flex items-center gap-1.5">
                                    <span class="inline-flex items-center rounded-md bg-slate-100 px-2 py-0.5 text-xs font-bold text-slate-800">
                                        {{ $etudiant->faculte->code }}
                                    </span>
                                    <span class="text-xs font-semibold text-primary-700 bg-primary-50 px-1.5 py-0.5 rounded">
                                        {{ $etudiant->niveau }}
                                    </span>
                                </div>
                            </td>

                            {{-- Origine & Bac --}}
                            <td class="py-4 px-5">
                                <div class="text-xs">
                                    <span class="font-semibold text-slate-800">{{ $etudiant->ville }}</span>
                                    <span class="block text-[11px] text-slate-400">Bac {{ $etudiant->annee_bac }}</span>
                                </div>
                            </td>

                            {{-- Statut --}}
                            <td class="py-4 px-5">
                                {!! $etudiant->statut_badge !!}
                            </td>

                            {{-- Actions --}}
                            <td class="py-4 px-5 text-right">
                                <x-dropdown :etiquette="'Actions pour ' . $etudiant->nom_complet">
                                    <x-slot:liens>
                                        <a href="{{ route('admin.etudiants.show', $etudiant) }}" role="menuitem"
                                           class="block px-4 py-2.5 text-xs font-bold text-slate-700 hover:bg-slate-50 min-h-[44px]">Consulter le dossier</a>
                                        @can('update', $etudiant)
                                            <a href="{{ route('admin.etudiants.modifier', $etudiant) }}" role="menuitem"
                                               class="block px-4 py-2.5 text-xs font-bold text-slate-700 hover:bg-slate-50 min-h-[44px]">Modifier</a>
                                        @endcan
                                        <button type="button" role="menuitem"
                                                @click="suppressionUrl = '{{ route('admin.etudiants.destroy', $etudiant) }}'; suppressionNom = @js($etudiant->nom_complet)"
                                                class="block w-full px-4 py-2.5 text-left text-xs font-bold text-rose-700 hover:bg-slate-50 min-h-[44px]">Supprimer</button>
                                    </x-slot:liens>
                                </x-dropdown>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="py-16 text-center text-slate-400">
                                <svg class="mx-auto h-12 w-12 text-slate-300 mb-3" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0ZM4.501 20.118a7.5 7.5 0 0 1 14.998 0A17.933 17.933 0 0 1 12 21.75c-2.676 0-5.216-.584-7.499-1.632Z" />
                                </svg>
                                Aucun dossier étudiant ne correspond à vos filtres actuels.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- ── Vue Mobile : Cartes Tactiles ── --}}
    <div class="md:hidden space-y-3">
        @forelse($etudiants as $etudiant)
            <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-xs">
                <div class="flex items-start justify-between gap-2 mb-2">
                    <div>
                        <span class="font-mono text-xs font-bold text-primary-800 bg-primary-50 px-2 py-0.5 rounded border border-primary-200">
                            {{ $etudiant->num_inscription }}
                        </span>
                        <h2 class="text-base font-bold text-slate-900 mt-1.5">
                            {{ $etudiant->nom_complet }}
                        </h2>
                        <p class="text-xs text-slate-500 font-mono">CIN: {{ $etudiant->cin }}</p>
                    </div>
                    <div>
                        {!! $etudiant->statut_badge !!}
                    </div>
                </div>

                <div class="flex items-center gap-2 text-xs text-slate-600 bg-slate-50 p-2.5 rounded-xl my-3">
                    <span class="font-bold text-primary-900">{{ $etudiant->faculte->code }}</span>
                    <span>•</span>
                    <span>{{ $etudiant->niveau }}</span>
                    <span>•</span>
                    <span>{{ $etudiant->ville }}</span>
                </div>

                <div class="flex items-center gap-2 pt-1 border-t border-slate-100">
                    <a href="{{ route('admin.etudiants.show', $etudiant) }}"
                       class="flex-1 text-center py-2 px-3 rounded-xl bg-primary-50 text-xs font-bold text-primary-700 min-h-[44px] inline-flex items-center justify-center">
                        Consulter Fiche
                    </a>
                    @can('update', $etudiant)
                        <a href="{{ route('admin.etudiants.modifier', $etudiant) }}"
                           class="py-2 px-3 rounded-xl border border-primary-200 text-xs font-bold text-primary-700 min-h-[44px] inline-flex items-center justify-center">
                            Modifier
                        </a>
                    @endcan
                    <button type="button"
                            @click="suppressionUrl = '{{ route('admin.etudiants.destroy', $etudiant) }}'; suppressionNom = @js($etudiant->nom_complet)"
                            class="py-2 px-3 rounded-xl border border-rose-200 text-xs font-bold text-rose-600 min-h-[44px]">
                        Supprimer
                    </button>
                </div>
            </div>
        @empty
            <div class="rounded-2xl border border-slate-200 bg-white p-8 text-center text-slate-400">
                Aucun étudiant trouvé.
            </div>
        @endforelse
    </div>

    {{-- ── Barre flottante de sélection + confirmation ── --}}
    @can('archives.update')
        <div x-show="selection.length > 0" x-cloak
             class="fixed bottom-4 left-1/2 z-[65] w-[calc(100%-2rem)] max-w-xl -translate-x-1/2 rounded-2xl border border-slate-200 bg-white p-3 shadow-xl"
             role="status" aria-live="polite">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <p class="text-xs font-black text-slate-900"><span x-text="selection.length"></span> sélectionné(s)</p>
                <div class="flex gap-2">
                    <x-btn variant="secondary" type="button" @click="demander('valide')">Valider</x-btn>
                    <x-btn variant="danger" type="button" @click="demander('rejete')">Rejeter</x-btn>
                </div>
            </div>
        </div>
        <x-modal titre="Confirmer l'action en masse" nom="confirmeOuvert">
            <p>Cette action concerne <strong x-text="selection.length"></strong> étudiant(s) :
                <strong x-text="statutCible === 'valide' ? 'validation' : 'rejet'"></strong>.
                Chaque dossier est vérifié (permission + périmètre) côté serveur.</p>
            <x-slot:actions>
                <x-btn variant="secondary" type="button" @click="confirmeOuvert = false">Annuler</x-btn>
                <x-btn type="button" @click="soumettre()" ::disabled="envoi">
                    <span x-show="!envoi">Confirmer</span>
                    <span x-show="envoi">Envoi…</span>
                </x-btn>
            </x-slot:actions>
        </x-modal>
    @endcan
    {{-- ── Modale de suppression partagée (desktop + mobile) ── --}}
    <x-modal titre="Supprimer cet étudiant ?" nom="suppressionUrl">
        <p>Le dossier de <strong x-text="suppressionNom"></strong> sera définitivement supprimé. Cette action est journalisée.</p>
        <x-slot:actions>
            <form method="POST" :action="suppressionUrl" class="flex flex-col-reverse sm:flex-row sm:justify-end gap-2">
                @csrf @method('DELETE')
                <x-btn variant="secondary" type="button" @click.prevent="suppressionUrl = ''">Annuler</x-btn>
                <x-btn variant="danger" type="submit">Supprimer</x-btn>
            </form>
        </x-slot:actions>
    </x-modal>
    </div>

    {{-- ── Pagination ── --}}
    <div class="mt-6">
        {{ $etudiants->links() }}
    </div>
</div>
@endsection
