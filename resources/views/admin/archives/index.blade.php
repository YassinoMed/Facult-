@extends('layouts.app')

@section('title', 'Archives Académiques — Scolarité')
@section('meta_description', 'Registre des dossiers académiques historisés des étudiants.')

@section('content')
<div class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">

    {{-- ── En-tête ── --}}
    <div class="mb-8 flex flex-col md:flex-row md:items-center md:justify-between gap-4">
        <div class="flex items-center gap-2.5">
            <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-primary-100 text-primary-700">
                <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 21v-8.25M15.75 21v-8.25M8.25 21v-8.25M3 9l9-6 9 6m-1.5 12V10.332A48.36 48.36 0 0 0 12 9.75c-2.551 0-5.056.2-7.5.582V21M3 21h18M12 6.75h.008v.008H12V6.75Z" />
                </svg>
            </div>
            <div>
                <h1 class="text-2xl sm:text-3xl font-black text-slate-900 tracking-tight">Archives Académiques</h1>
                <p class="text-xs sm:text-sm text-slate-500">Dossiers historiques : parcours, notes, moyennes, crédits et documents.</p>
            </div>
        </div>

        {{-- Actions de l'en-tête (export, configuration, création) --}}
        <div class="flex flex-wrap items-center gap-2">
            @can('archives.export')
                <a href="{{ route('admin.archives.export-registre', request()->query()) }}"
                   class="inline-flex items-center gap-2 rounded-xl border border-slate-200 bg-white px-3.5 py-2 text-xs font-bold text-slate-700 hover:bg-slate-50 shadow-xs transition-smooth"
                   title="Exporter le registre filtré au format CSV">
                    <svg class="h-4 w-4 text-slate-500" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5M16.5 12 12 16.5m0 0L7.5 12m4.5 4.5V3" />
                    </svg>
                    Exporter le registre (CSV)
                </a>
            @endcan

            <a href="{{ route('admin.archives.configuration.index') }}"
               class="inline-flex items-center gap-2 rounded-xl border border-slate-200 bg-white px-3.5 py-2 text-xs font-bold text-slate-700 hover:bg-slate-50 shadow-xs transition-smooth">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9.594 3.94c.09-.542.56-.94 1.11-.94h2.593c.55 0 1.02.398 1.11.94l.213 1.281c.063.374.313.686.645.87.074.04.147.083.22.127.325.196.72.257 1.075.124l1.217-.456a1.125 1.125 0 0 1 1.37.49l1.296 2.247a1.125 1.125 0 0 1-.216 1.411l-1.001 1.001a1.125 1.125 0 0 0-.29 1.176c.04.077.078.155.113.234" />
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 6.75a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
                    <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 10.5c.058.29.088.59.088.9 0 2.7-2.988 5.36-7.588 6.897-4.6-1.537-7.588-4.197-7.588-6.897 0-2.152 1.94-3.938 4.695-5.123" />
                </svg>
                Configuration
            </a>

            @can('archives.create')
                <a href="{{ route('admin.archives.create') }}"
                   class="inline-flex items-center gap-2 rounded-xl bg-gradient-to-r from-primary-600 to-accent-600 px-4 py-2 text-xs font-bold text-white shadow-md shadow-primary-500/20 hover:shadow-lg hover:-translate-y-0.5 transition-smooth">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2.2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                    </svg>
                    Nouveau dossier
                </a>
            @endcan
        </div>
    </div>

    {{-- ── Synthèse statistique (scopée et filtrée) ── --}}
    <div class="mb-6 grid grid-cols-1 sm:grid-cols-3 gap-4">
        <x-stat-card libelle="Total dossiers" :valeur="$stats['total']" couleur="slate" detail="Selon les filtres appliqués" />
        <x-stat-card libelle="Dossiers actifs" :valeur="$stats['actifs']" couleur="emerald" detail="Inscrits en cours d'études" />
        <x-stat-card libelle="Dossiers clos" :valeur="$stats['clos']" couleur="amber" detail="Diplômés ou archivés" />
    </div>

    {{-- ── Recherche & Filtres ── --}}
    <div class="mb-6 rounded-3xl border border-slate-200/80 bg-white p-5 shadow-xs">
        <form method="GET" action="{{ route('admin.archives.index') }}" class="space-y-4" role="search" aria-label="Recherche et filtres du registre">
            <div class="relative">
                <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-4 text-slate-400" aria-hidden="true">
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z" />
                    </svg>
                </div>
                <label for="recherche-registre" class="sr-only">Rechercher par CIN, nom, prénom, email, n° d'inscription ou matricule</label>
                <input id="recherche-registre" type="text" name="search" value="{{ request('search') }}"
                       placeholder="Rechercher par CIN, nom, prénom, email, n° d'inscription ou matricule…"
                       autocomplete="off"
                       class="w-full rounded-xl border border-slate-200 bg-white px-3.5 py-2.5 pl-12 text-sm text-slate-900 transition-smooth focus:border-primary-600 focus:ring-4 focus:ring-primary-500/15 focus:outline-none">
            </div>

            <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-3">
                <div>
                    <label for="filtre-faculte" class="sr-only">Filtrer par faculté</label>
                    <select id="filtre-faculte" name="faculte_id" class="w-full rounded-xl border border-slate-200 bg-white px-3.5 py-2.5 text-sm text-slate-900 focus:border-primary-600 focus:ring-4 focus:ring-primary-500/15 focus:outline-none">
                    <option value="">Toutes facultés</option>
                    @foreach($facultes as $faculte)
                        <option value="{{ $faculte->id }}" {{ request('faculte_id') == $faculte->id ? 'selected' : '' }}>{{ $faculte->code }}</option>
                    @endforeach
                    </select>
                </div>

                <div>
                    <label for="filtre-annee" class="sr-only">Filtrer par année universitaire</label>
                    <select id="filtre-annee" name="annee_universitaire_id" class="w-full rounded-xl border border-slate-200 bg-white px-3.5 py-2.5 text-sm text-slate-900 focus:border-primary-600 focus:ring-4 focus:ring-primary-500/15 focus:outline-none">
                    <option value="">Toutes années</option>
                    @foreach($annees as $annee)
                        <option value="{{ $annee->id }}" {{ request('annee_universitaire_id') == $annee->id ? 'selected' : '' }}>{{ $annee->libelle }}</option>
                    @endforeach
                    </select>
                </div>

                <div>
                    <label for="filtre-niveau" class="sr-only">Filtrer par niveau</label>
                    <select id="filtre-niveau" name="niveau" class="w-full rounded-xl border border-slate-200 bg-white px-3.5 py-2.5 text-sm text-slate-900 focus:border-primary-600 focus:ring-4 focus:ring-primary-500/15 focus:outline-none">
                    <option value="">Tous niveaux</option>
                    @foreach($niveaux as $niveau)
                        <option value="{{ $niveau }}" {{ request('niveau') == $niveau ? 'selected' : '' }}>{{ $niveau }}</option>
                    @endforeach
                    </select>
                </div>

                <div>
                    <label for="filtre-statut" class="sr-only">Filtrer par statut du dossier</label>
                    <select id="filtre-statut" name="statut" class="w-full rounded-xl border border-slate-200 bg-white px-3.5 py-2.5 text-sm text-slate-900 focus:border-primary-600 focus:ring-4 focus:ring-primary-500/15 focus:outline-none">
                    <option value="">Tous statuts</option>
                    <option value="actif" {{ request('statut') == 'actif' ? 'selected' : '' }}>Actif</option>
                    <option value="clos" {{ request('statut') == 'clos' ? 'selected' : '' }}>Clos</option>
                    </select>
                </div>

                <div>
                    <label for="filtre-programme" class="sr-only">Filtrer par parcours</label>
                    <select id="filtre-programme" name="programme_id" class="w-full rounded-xl border border-slate-200 bg-white px-3.5 py-2.5 text-sm text-slate-900 focus:border-primary-600 focus:ring-4 focus:ring-primary-500/15 focus:outline-none">
                    <option value="">Tous parcours</option>
                    @foreach($programmes as $programme)
                        <option value="{{ $programme->id }}" {{ request('programme_id') == $programme->id ? 'selected' : '' }}>{{ $programme->code }}</option>
                    @endforeach
                    </select>
                </div>
            </div>

            <div class="flex items-center justify-between gap-3">
                <p class="text-xs text-slate-600 font-medium" role="status" aria-live="polite">{{ $dossiers->total() }} dossier(s) — CIN masquée par mesure de confidentialité.</p>
                <div class="flex gap-2">
                    <a href="{{ route('admin.archives.index') }}" class="rounded-xl border border-slate-200 px-3.5 py-2 text-xs font-bold text-slate-600 hover:bg-slate-50 transition-smooth">Réinitialiser</a>
                    <button type="submit" class="rounded-xl bg-primary-600 px-4 py-2 text-xs font-bold text-white hover:bg-primary-700 transition-smooth">Filtrer</button>
                </div>
            </div>
        </form>
    </div>

    {{-- ── Tableau (desktop) ── --}}
    <div class="hidden lg:block rounded-3xl border border-slate-200/80 bg-white shadow-xs overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm min-w-[800px]">
                <thead class="border-b border-slate-200 bg-slate-50/70 text-[11px] uppercase tracking-wider text-slate-700 font-bold">
                    <tr>
                        <th scope="col" class="px-5 py-3.5">Matricule</th>
                        <th scope="col" class="px-5 py-3.5">Étudiant</th>
                        <th scope="col" class="px-5 py-3.5">CIN</th>
                        <th scope="col" class="px-5 py-3.5">Faculté</th>
                        <th scope="col" class="px-5 py-3.5">Dernier niveau</th>
                        <th scope="col" class="px-5 py-3.5">Années</th>
                        <th scope="col" class="px-5 py-3.5">Statut</th>
                        <th scope="col" class="px-5 py-3.5 text-right">Actions</th>
                    </tr>
                </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse($dossiers as $dossier)
                    <tr class="hover:bg-primary-50/30 transition-smooth">
                        <td class="px-5 py-4 font-mono text-xs font-bold text-slate-900">{{ $dossier->matricule }}</td>
                        <td class="px-5 py-4">
                            <div class="font-bold text-slate-900">{{ $dossier->etudiant->nom_complet }}</div>
                            <div class="text-xs text-slate-500 font-medium">{{ $dossier->etudiant->num_inscription }}</div>
                        </td>
                        <td class="px-5 py-4 font-mono text-xs text-slate-500">{{ $dossier->etudiant->cin_masquee }}</td>
                        <td class="px-5 py-4">
                            <span class="text-xs font-semibold text-slate-600">{{ $dossier->etudiant->faculte->code }}</span>
                        </td>
                        <td class="px-5 py-4 text-xs font-bold text-slate-700">{{ $dossier->etudiant->niveau }}</td>
                        <td class="px-5 py-4 text-xs font-semibold text-slate-600">{{ $dossier->inscriptions_count }}</td>
                        <td class="px-5 py-4">
                            @if($dossier->statut === 'actif')
                                <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[11px] font-bold bg-emerald-100 text-emerald-800 border border-emerald-200"><span class="h-1.5 w-1.5 rounded-full bg-emerald-500"></span>Actif</span>
                            @else
                                <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[11px] font-bold bg-slate-100 text-slate-600 border border-slate-200"><span class="h-1.5 w-1.5 rounded-full bg-slate-400"></span>Clos</span>
                            @endif
                        </td>
                        <td class="px-5 py-4 text-right">
                            <a href="{{ route('admin.archives.show', $dossier) }}"
                               class="inline-flex items-center gap-1.5 rounded-xl bg-slate-900 px-3.5 py-2 text-[11px] font-bold text-white hover:bg-slate-800 transition-smooth">
                                Consulter
                                <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5 21 12m0 0-7.5 7.5M21 12H3" /></svg>
                            </a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8" class="px-5 py-14 text-center text-sm text-slate-600 font-medium">
                            <p>Aucun dossier académique ne correspond à votre recherche.</p>
                            @if(request()->anyFilled(['search', 'faculte_id', 'annee_universitaire_id', 'niveau', 'statut', 'programme_id']))
                                <a href="{{ route('admin.archives.index') }}" class="mt-3 inline-flex items-center gap-1.5 text-xs font-semibold text-primary-600 hover:text-primary-800 transition-smooth">
                                    <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0 3.181 3.183a8.25 8.25 0 0 0 13.803-3.7M4.031 9.865a8.25 8.25 0 0 1 13.803-3.7l3.181 3.182m0-4.991v4.99" /></svg>
                                    Effacer les filtres
                                </a>
                            @endif
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
        </div>
    </div>

    {{-- ── Cartes (mobile) ── --}}
    <div class="lg:hidden space-y-3">
        @forelse($dossiers as $dossier)
            <a href="{{ route('admin.archives.show', $dossier) }}"
               class="block rounded-2xl border border-slate-200/80 bg-white p-4 shadow-xs active:scale-[0.99] transition-smooth">
                <div class="flex items-center justify-between gap-3">
                    <div class="min-w-0">
                        <p class="truncate text-sm font-bold text-slate-900">{{ $dossier->etudiant->nom_complet }}</p>
                        <p class="font-mono text-[11px] text-slate-500 font-medium">{{ $dossier->matricule }} · {{ $dossier->etudiant->cin_masquee }}</p>
                    </div>
                    @if($dossier->statut === 'actif')
                        <span class="flex-shrink-0 rounded-full bg-emerald-100 text-emerald-800 border border-emerald-200 px-2 py-0.5 text-[10px] font-bold">Actif</span>
                    @else
                        <span class="flex-shrink-0 rounded-full bg-slate-100 text-slate-600 border border-slate-200 px-2 py-0.5 text-[10px] font-bold">Clos</span>
                    @endif
                </div>
                <div class="mt-2.5 flex items-center gap-3 text-[11px] text-slate-500">
                    <span class="font-semibold">{{ $dossier->etudiant->faculte->code }}</span>
                    <span class="text-slate-300">·</span>
                    <span>{{ $dossier->etudiant->niveau }}</span>
                    <span class="text-slate-300">·</span>
                    <span>{{ $dossier->inscriptions_count }} année(s)</span>
                </div>
            </a>
        @empty
            <div class="rounded-2xl border border-slate-200/80 bg-white p-10 text-center text-sm text-slate-600 font-medium">
                <p>Aucun dossier académique trouvé.</p>
                @if(request()->anyFilled(['search', 'faculte_id', 'annee_universitaire_id', 'niveau', 'statut', 'programme_id']))
                    <a href="{{ route('admin.archives.index') }}" class="mt-3 inline-flex items-center gap-1.5 text-xs font-semibold text-primary-600 hover:text-primary-800 transition-smooth">
                        Effacer les filtres
                    </a>
                @endif
            </div>
        @endforelse
    </div>

    {{-- ── Pagination ── --}}
    <div class="mt-6">
        {{ $dossiers->links() }}
    </div>
</div>
@endsection
