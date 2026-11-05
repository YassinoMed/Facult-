@extends('layouts.app')

@section('title', 'Périodes d’inscription — Administration')

@section('content')
<div class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8 space-y-8">
    {{-- En-tête de page moderne avec CTA principal --}}
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between border-b border-slate-200 dark:border-slate-800 pb-6">
        <div>
            <div class="flex items-center gap-3">
                <div class="flex h-11 w-11 items-center justify-center rounded-xl bg-primary-50 dark:bg-primary-950 text-primary-600 dark:text-primary-400 ring-1 ring-primary-500/20 shadow-sm">
                    <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                    </svg>
                </div>
                <div>
                    <h1 class="text-2xl font-bold tracking-tight text-slate-900 dark:text-white sm:text-3xl">Périodes d’inscription</h1>
                    <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                        Gestion des fenêtres temporelles d'ouverture planifiée et dérogations exceptionnelles.
                    </p>
                </div>
            </div>
        </div>
        <div class="flex items-center gap-3">
            <a href="{{ route('admin.periodes.create') }}" class="inline-flex items-center justify-center gap-2 rounded-xl bg-primary-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-primary-500 transition-all focus:outline-none focus:ring-2 focus:ring-primary-500 focus:ring-offset-2">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                </svg>
                <span>Nouvelle période</span>
            </a>
        </div>
    </div>

    {{-- Alertes de session --}}
    @if($errors->any())
        <div class="rounded-xl border border-rose-200 bg-rose-50/80 p-4 dark:border-rose-900/50 dark:bg-rose-950/40">
            <div class="flex items-start gap-3">
                <svg class="h-5 w-5 text-rose-600 dark:text-rose-400 shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
                <div>
                    <h3 class="text-sm font-semibold text-rose-800 dark:text-rose-300">Action refusée</h3>
                    <p class="mt-1 text-sm text-rose-700 dark:text-rose-400">{{ $errors->first() }}</p>
                </div>
            </div>
        </div>
    @endif

    @if(session('success'))
        <div class="rounded-xl border border-emerald-200 bg-emerald-50/80 p-4 dark:border-emerald-900/50 dark:bg-emerald-950/40">
            <div class="flex items-start gap-3">
                <svg class="h-5 w-5 text-emerald-600 dark:text-emerald-400 shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                </svg>
                <div class="text-sm font-medium text-emerald-800 dark:text-emerald-300">{{ session('success') }}</div>
            </div>
        </div>
    @endif

    {{-- Métriques en direct (Cartes d'état shadcn-inspired) --}}
    <div class="grid grid-cols-2 gap-4 sm:grid-cols-2 lg:grid-cols-4" role="status" aria-label="Compteurs de périodes">
        <div class="rounded-2xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 p-5 shadow-sm transition hover:shadow">
            <div class="flex items-center justify-between">
                <span class="text-xs font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">Ouvertes en cours</span>
                <span class="inline-flex h-2.5 w-2.5 rounded-full bg-emerald-500 animate-pulse"></span>
            </div>
            <div class="mt-3 flex items-baseline gap-2">
                <span class="text-3xl font-extrabold text-slate-900 dark:text-white">{{ $resume['ouvertes'] }}</span>
                <span class="text-xs font-medium text-emerald-600 dark:text-emerald-400">actives</span>
            </div>
            <p class="mt-1 text-xs text-slate-500">Inscriptions acceptées en temps réel</p>
        </div>

        <div class="rounded-2xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 p-5 shadow-sm transition hover:shadow">
            <div class="flex items-center justify-between">
                <span class="text-xs font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">Fermeture &lt; 48 h</span>
                <span class="inline-flex h-2.5 w-2.5 rounded-full bg-amber-500"></span>
            </div>
            <div class="mt-3 flex items-baseline gap-2">
                <span class="text-3xl font-extrabold text-amber-600 dark:text-amber-400">{{ $resume['bientot'] }}</span>
                <span class="text-xs font-medium text-amber-700 dark:text-amber-500">imminentes</span>
            </div>
            <p class="mt-1 text-xs text-slate-500">Nécessite prolongation ou rappel</p>
        </div>

        <div class="rounded-2xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 p-5 shadow-sm transition hover:shadow">
            <div class="flex items-center justify-between">
                <span class="text-xs font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">Planifiées</span>
                <span class="inline-flex h-2.5 w-2.5 rounded-full bg-sky-500"></span>
            </div>
            <div class="mt-3 flex items-baseline gap-2">
                <span class="text-3xl font-extrabold text-slate-900 dark:text-white">{{ $resume['planifiees'] }}</span>
                <span class="text-xs font-medium text-sky-600 dark:text-sky-400">futures</span>
            </div>
            <p class="mt-1 text-xs text-slate-500">Ouvertures programmées</p>
        </div>

        <div class="rounded-2xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 p-5 shadow-sm transition hover:shadow">
            <div class="flex items-center justify-between">
                <span class="text-xs font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">Exceptions</span>
                <span class="inline-flex h-2.5 w-2.5 rounded-full bg-indigo-500"></span>
            </div>
            <div class="mt-3 flex items-baseline gap-2">
                <span class="text-3xl font-extrabold text-indigo-600 dark:text-indigo-400">{{ $resume['exceptions'] }}</span>
                <span class="text-xs font-medium text-indigo-600 dark:text-indigo-400">dérogations</span>
            </div>
            <p class="mt-1 text-xs text-slate-500">Motivées et auditées</p>
        </div>
    </div>

    {{-- Carte Principale : Périodes configurées & Barre d'outils --}}
    <div class="rounded-2xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 shadow-sm overflow-hidden">
        {{-- En-tête de la carte --}}
        <div class="border-b border-slate-200 dark:border-slate-800 p-5 sm:px-6">
            <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                <div>
                    <h2 class="text-lg font-bold text-slate-900 dark:text-white">Périodes configurées</h2>
                    <p class="text-xs text-slate-500 mt-0.5">Toutes les fenêtres d'inscriptions avec statut calculé au fuseau de Tunis (UTC+1)</p>
                </div>
                <div class="text-xs text-slate-400">
                    Total : <span class="font-bold text-slate-700 dark:text-slate-300">{{ $periodes->total() }}</span> période(s)
                </div>
            </div>

            {{-- Formulaire de filtres multi-critères --}}
            <form method="get" action="{{ route('admin.periodes.index') }}" class="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-2 md:grid-cols-5 items-end">
                <div>
                    <label for="filtre-search" class="mb-1 block text-xs font-semibold uppercase text-slate-500">Recherche</label>
                    <input type="text" name="search" id="filtre-search" value="{{ $filtres['search'] ?? '' }}" placeholder="Libellé, niveau..." class="w-full rounded-xl border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 px-3 py-2 text-sm text-slate-900 dark:text-white focus:ring-2 focus:ring-primary-500" />
                </div>
                <div>
                    <label for="filtre-annee" class="mb-1 block text-xs font-semibold uppercase text-slate-500">Année universitaire</label>
                    <select name="annee_universitaire_id" id="filtre-annee" class="w-full rounded-xl border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 px-3 py-2 text-sm text-slate-900 dark:text-white focus:ring-2 focus:ring-primary-500">
                        <option value="">Toutes les années</option>
                        @foreach($annees as $annee)
                            <option value="{{ $annee->id }}" @selected((int) ($filtres['annee_universitaire_id'] ?? 0) === $annee->id)>{{ $annee->libelle }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="filtre-type" class="mb-1 block text-xs font-semibold uppercase text-slate-500">Type de période</label>
                    <select name="type_periode" id="filtre-type" class="w-full rounded-xl border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 px-3 py-2 text-sm text-slate-900 dark:text-white focus:ring-2 focus:ring-primary-500">
                        <option value="">Tous types</option>
                        <option value="normale" @selected(($filtres['type_periode'] ?? '') === 'normale')>Normale</option>
                        <option value="exception" @selected(($filtres['type_periode'] ?? '') === 'exception')>Exception</option>
                    </select>
                </div>
                <div>
                    <label for="filtre-statut" class="mb-1 block text-xs font-semibold uppercase text-slate-500">Statut système</label>
                    <select name="statut" id="filtre-statut" class="w-full rounded-xl border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 px-3 py-2 text-sm text-slate-900 dark:text-white focus:ring-2 focus:ring-primary-500">
                        <option value="">Tous statuts</option>
                        <option value="active" @selected(($filtres['statut'] ?? '') === 'active')>Active</option>
                        <option value="revoquee" @selected(($filtres['statut'] ?? '') === 'revoquee')>Révoquée</option>
                        <option value="utilisee" @selected(($filtres['statut'] ?? '') === 'utilisee')>Utilisée (consommée)</option>
                    </select>
                </div>
                <div class="flex items-center gap-2">
                    <button type="submit" class="flex-1 inline-flex items-center justify-center gap-2 rounded-xl bg-slate-900 dark:bg-slate-700 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800 transition">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z" />
                        </svg>
                        Filtrer
                    </button>
                    @if(array_filter($filtres))
                        <a href="{{ route('admin.periodes.index') }}" class="rounded-xl border border-slate-300 dark:border-slate-700 px-3 py-2 text-xs font-medium text-slate-600 dark:text-slate-400 hover:bg-slate-100 dark:hover:bg-slate-800 transition">
                            Effacer
                        </a>
                    @endif
                </div>
            </form>
        </div>

        {{-- Table de données --}}
        @if($periodes->isEmpty())
            <div class="p-12 text-center">
                <div class="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-slate-100 dark:bg-slate-800 text-slate-400">
                    <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                    </svg>
                </div>
                <h3 class="mt-3 text-sm font-semibold text-slate-900 dark:text-white">Aucune période trouvée</h3>
                <p class="mt-1 text-xs text-slate-500">Aucune période ne correspond aux critères sélectionnés. Les inscriptions restent autorisées en mode transition.</p>
                <div class="mt-4">
                    <a href="{{ route('admin.periodes.create') }}" class="inline-flex items-center gap-1.5 text-xs font-semibold text-primary-600 hover:text-primary-500">
                        <span>Créer une première période &rarr;</span>
                    </a>
                </div>
            </div>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm text-slate-600 dark:text-slate-300">
                    <thead class="bg-slate-50/75 dark:bg-slate-800/50 text-[11px] font-bold uppercase tracking-wider text-slate-500 dark:text-slate-400 border-b border-slate-200 dark:border-slate-800">
                        <tr>
                            <th scope="col" class="px-5 py-3.5">Période & Type</th>
                            <th scope="col" class="px-5 py-3.5">Année</th>
                            <th scope="col" class="px-5 py-3.5">Portée & Ciblage</th>
                            <th scope="col" class="px-5 py-3.5">Calendrier (Heure Tunis)</th>
                            <th scope="col" class="px-5 py-3.5">État en direct</th>
                            <th scope="col" class="px-5 py-3.5 text-right">Actions CRUD</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                        @foreach($periodes as $periode)
                            @php
                                $etatCalcule = $periode->statutCalcule();
                            @endphp
                            <tr class="hover:bg-slate-50/80 dark:hover:bg-slate-800/40 transition">
                                {{-- Période & Type --}}
                                <td class="px-5 py-4">
                                    <div class="flex items-center gap-2">
                                        @if($periode->type_periode === 'exception')
                                            <span class="inline-flex items-center gap-1 rounded-md bg-amber-50 px-2 py-0.5 text-[11px] font-bold text-amber-700 ring-1 ring-inset ring-amber-600/20 dark:bg-amber-950/50 dark:text-amber-400">
                                                <svg class="h-3 w-3" fill="currentColor" viewBox="0 0 20 20">
                                                    <path fill-rule="evenodd" d="M10.868 2.884c-.321-.772-1.415-.772-1.736 0l-1.83 4.401-4.753.381c-.833.067-1.171 1.107-.536 1.651l3.62 3.102-1.106 4.637c-.194.813.691 1.456 1.405 1.02L10 15.591l4.069 2.485c.713.436 1.598-.207 1.404-1.02l-1.106-4.637 3.62-3.102c.635-.544.297-1.584-.536-1.65l-4.752-.382-1.831-4.401z" clip-rule="evenodd" />
                                                </svg>
                                                Exception
                                            </span>
                                        @else
                                            <span class="inline-flex items-center rounded-md bg-indigo-50 px-2 py-0.5 text-[11px] font-bold text-indigo-700 ring-1 ring-inset ring-indigo-700/20 dark:bg-indigo-950/50 dark:text-indigo-400">
                                                Normale
                                            </span>
                                        @endif
                                        <span class="font-bold text-slate-900 dark:text-white">{{ $periode->nom ?: 'Période #'.$periode->id }}</span>
                                    </div>
                                    @if($periode->motif)
                                        <p class="mt-1 text-xs text-slate-400 truncate max-w-xs" title="{{ $periode->motif }}">{{ $periode->motif }}</p>
                                    @endif
                                </td>

                                {{-- Année --}}
                                <td class="px-5 py-4 whitespace-nowrap">
                                    <span class="font-medium text-slate-700 dark:text-slate-300">{{ $periode->anneeUniversitaire?->libelle ?? '—' }}</span>
                                </td>

                                {{-- Portée --}}
                                <td class="px-5 py-4">
                                    <div class="space-y-0.5 text-xs">
                                        <div class="font-medium text-slate-800 dark:text-slate-200">
                                            {{ $periode->faculte?->nom ?? '🌐 Toutes les facultés' }}
                                        </div>
                                        @if($periode->programme)
                                            <div class="text-slate-500 flex items-center gap-1">
                                                <span>📚 {{ $periode->programme->code }}</span>
                                            </div>
                                        @endif
                                        <div class="text-[11px] text-slate-400 flex items-center gap-2">
                                            @if($periode->niveau)
                                                <span class="bg-slate-100 dark:bg-slate-800 px-1.5 py-0.5 rounded">{{ $periode->niveau }}</span>
                                            @endif
                                            @if($periode->type_inscription)
                                                <span>{{ $periode->type_inscription === 'premiere' ? '1ère insc.' : 'Réinsc.' }}</span>
                                            @endif
                                            @if($periode->etudiant)
                                                <span class="font-mono text-primary-600 dark:text-primary-400">CIN/ID: {{ $periode->etudiant->cin ?? $periode->etudiant->id }}</span>
                                            @endif
                                        </div>
                                    </div>
                                </td>

                                {{-- Calendrier --}}
                                <td class="px-5 py-4 whitespace-nowrap text-xs">
                                    <div class="font-medium text-slate-900 dark:text-white">
                                        Du {{ $periode->opened_at->timezone(config('inscriptions.fuseau', 'Africa/Tunis'))->format('d/m/Y H:i') }}
                                    </div>
                                    <div class="text-slate-500">
                                        Au {{ $periode->closed_at->timezone(config('inscriptions.fuseau', 'Africa/Tunis'))->format('d/m/Y H:i') }}
                                    </div>
                                </td>

                                {{-- État en direct --}}
                                <td class="px-5 py-4 whitespace-nowrap">
                                    @if($etatCalcule === 'OPEN')
                                        <span class="inline-flex items-center gap-1.5 rounded-full bg-emerald-50 px-2.5 py-1 text-xs font-semibold text-emerald-700 ring-1 ring-inset ring-emerald-600/20 dark:bg-emerald-950/50 dark:text-emerald-400">
                                            <span class="h-1.5 w-1.5 rounded-full bg-emerald-500"></span>
                                            Ouverte
                                        </span>
                                    @elseif($etatCalcule === 'CLOSING_SOON')
                                        <span class="inline-flex items-center gap-1.5 rounded-full bg-amber-50 px-2.5 py-1 text-xs font-semibold text-amber-700 ring-1 ring-inset ring-amber-600/20 dark:bg-amber-950/50 dark:text-amber-400">
                                            <span class="h-1.5 w-1.5 rounded-full bg-amber-500 animate-pulse"></span>
                                            Ferme &lt; 48 h
                                        </span>
                                    @elseif($etatCalcule === 'SCHEDULED')
                                        <span class="inline-flex items-center gap-1.5 rounded-full bg-sky-50 px-2.5 py-1 text-xs font-semibold text-sky-700 ring-1 ring-inset ring-sky-600/20 dark:bg-sky-950/50 dark:text-sky-400">
                                            <span class="h-1.5 w-1.5 rounded-full bg-sky-500"></span>
                                            Planifiée
                                        </span>
                                    @elseif($etatCalcule === 'CANCELLED')
                                        <span class="inline-flex items-center gap-1.5 rounded-full bg-rose-50 px-2.5 py-1 text-xs font-semibold text-rose-700 ring-1 ring-inset ring-rose-600/20 dark:bg-rose-950/50 dark:text-rose-400">
                                            <span class="h-1.5 w-1.5 rounded-full bg-rose-500"></span>
                                            Révoquée
                                        </span>
                                    @else
                                        <span class="inline-flex items-center gap-1.5 rounded-full bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-600 ring-1 ring-inset ring-slate-500/20 dark:bg-slate-800 dark:text-slate-400">
                                            <span class="h-1.5 w-1.5 rounded-full bg-slate-400"></span>
                                            Fermée
                                        </span>
                                    @endif
                                </td>

                                {{-- Actions CRUD complètes --}}
                                <td class="px-5 py-4 whitespace-nowrap text-right">
                                    <div class="inline-flex items-center gap-1.5">
                                        {{-- Voir (Show) --}}
                                        <a href="{{ route('admin.periodes.show', $periode) }}" title="Consulter les détails" class="rounded-lg p-1.5 text-slate-500 hover:bg-slate-100 dark:hover:bg-slate-800 hover:text-slate-900 transition">
                                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                                            </svg>
                                        </a>

                                        {{-- Modifier (Edit) --}}
                                        <a href="{{ route('admin.periodes.edit', $periode) }}" title="Modifier la période" class="rounded-lg p-1.5 text-slate-500 hover:bg-slate-100 dark:hover:bg-slate-800 hover:text-primary-600 transition">
                                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                                            </svg>
                                        </a>

                                        {{-- Prolonger (Modal trigger) --}}
                                        @if($periode->statut === 'active')
                                            <button type="button" onclick="ouvrirModalProlongation({{ $periode->id }}, '{{ $periode->closed_at->format('Y-m-d\TH:i') }}')" title="Prolonger la fermeture" class="rounded-lg p-1.5 text-amber-600 hover:bg-amber-50 dark:hover:bg-amber-950/40 transition">
                                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                                                </svg>
                                            </button>

                                            {{-- Révoquer --}}
                                            <form method="post" action="{{ route('admin.periodes.revoquer', $periode) }}" onsubmit="return confirm('Êtes-vous sûr de vouloir révoquer cette période ? Elle ne permettra plus aucune inscription.')" class="inline">
                                                @csrf
                                                <input type="hidden" name="motif" value="Révocation administrative depuis la console de gestion." />
                                                <button type="submit" title="Révoquer la période" class="rounded-lg p-1.5 text-rose-600 hover:bg-rose-50 dark:hover:bg-rose-950/40 transition">
                                                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636" />
                                                    </svg>
                                                </button>
                                            </form>
                                        @endif

                                        {{-- Supprimer (si non utilisée) --}}
                                        @if($periode->statut !== 'utilisee')
                                            <form method="post" action="{{ route('admin.periodes.destroy', $periode) }}" onsubmit="return confirm('Confirmer la suppression définitive de la période #{{ $periode->id }} ?')" class="inline">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" title="Supprimer la période" class="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 dark:hover:bg-slate-800 hover:text-rose-600 transition">
                                                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                                    </svg>
                                                </button>
                                            </form>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            {{-- Pagination moderne --}}
            <div class="border-t border-slate-200 dark:border-slate-800 p-4">
                {{ $periodes->links() }}
            </div>
        @endif
    </div>
</div>

{{-- Modal dynamique de prolongation --}}
<div id="modal-prolongation" class="fixed inset-0 z-50 hidden bg-slate-900/60 backdrop-blur-sm flex items-center justify-center p-4">
    <div class="w-full max-w-md rounded-2xl bg-white dark:bg-slate-900 p-6 shadow-xl border border-slate-200 dark:border-slate-800">
        <div class="flex items-center justify-between border-b border-slate-100 dark:border-slate-800 pb-3">
            <h3 class="text-base font-bold text-slate-900 dark:text-white flex items-center gap-2">
                <svg class="h-5 w-5 text-amber-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
                Prolonger la période
            </h3>
            <button type="button" onclick="fermerModalProlongation()" class="text-slate-400 hover:text-slate-600">
                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                </svg>
            </button>
        </div>
        <form id="form-prolongation" method="post" action="" class="mt-4 space-y-4">
            @csrf
            <div>
                <label for="modal-closed-at" class="block text-xs font-bold uppercase text-slate-500 mb-1">Nouvelle date et heure de fermeture *</label>
                <input type="datetime-local" name="closed_at" id="modal-closed-at" required class="w-full rounded-xl border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 px-3 py-2 text-sm text-slate-900 dark:text-white" />
                <p class="mt-1 text-[11px] text-slate-400">Heure de Tunis (UTC+1)</p>
            </div>
            <div>
                <label for="modal-motif" class="block text-xs font-bold uppercase text-slate-500 mb-1">Motif officiel de prolongation *</label>
                <textarea name="motif" id="modal-motif" required rows="3" minlength="10" placeholder="Justification officielle (ex. Décision rectorale suite à incident réseau…)" class="w-full rounded-xl border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 px-3 py-2 text-sm text-slate-900 dark:text-white"></textarea>
            </div>
            <div class="flex items-center justify-end gap-2 pt-2">
                <button type="button" onclick="fermerModalProlongation()" class="rounded-xl border border-slate-300 dark:border-slate-700 px-4 py-2 text-sm font-medium text-slate-700 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800 transition">
                    Annuler
                </button>
                <button type="submit" class="rounded-xl bg-amber-600 px-4 py-2 text-sm font-semibold text-white hover:bg-amber-500 transition">
                    Confirmer la prolongation
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function ouvrirModalProlongation(periodeId, dateFermetureActuelle) {
    const form = document.getElementById('form-prolongation');
    form.action = '/admin/periodes/' + periodeId + '/prolonger';
    document.getElementById('modal-closed-at').value = dateFermetureActuelle;
    document.getElementById('modal-prolongation').classList.remove('hidden');
}

function fermerModalProlongation() {
    document.getElementById('modal-prolongation').classList.add('hidden');
}
</script>
@endsection
