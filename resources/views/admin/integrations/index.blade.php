@extends('layouts.app')

@section('title', 'Intégrations — Scolarité')
@section('meta_description', 'Imports, exports et journal des échanges avec les systèmes externes.')

@section('content')
<div class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">

    {{-- En-tête --}}
    <div class="mb-8 flex flex-col md:flex-row md:items-center md:justify-between gap-4">
        <div class="flex items-center gap-2.5">
            <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-primary-100 text-primary-700">
                <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M7.5 21 3 16.5m0 0L7.5 12M3 16.5h9M16.5 3 21 7.5M21 7.5 16.5 12M21 7.5h-9" />
                </svg>
            </div>
            <div>
                <h1 class="text-2xl sm:text-3xl font-black text-slate-900 tracking-tight">Intégrations & Échanges</h1>
                <p class="text-xs sm:text-sm text-slate-500">Imports (prévisualisation → confirmation), exports à profils restreints et journal des échanges.</p>
            </div>
        </div>
        <a href="{{ route('admin.archives.index') }}"
           class="inline-flex items-center gap-1.5 rounded-xl border border-slate-200 bg-white px-3.5 py-2 text-xs font-bold text-slate-600 hover:bg-slate-50 shadow-xs transition-smooth">
            <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2.2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5 3 12m0 0 7.5-7.5M3 12h18" /></svg>
            Archives
        </a>
    </div>

    {{-- Onglets Alpine --}}
    <div x-data="{ onglet: 'import' }" class="space-y-6">

        {{-- Barre d'onglets --}}
        <div class="flex flex-wrap gap-1.5 rounded-2xl border border-slate-200/80 bg-slate-100/80 p-1.5">
            @can('configuration.manage')
                <button @click="onglet = 'import'" :class="onglet === 'import' ? 'bg-white text-primary-700 shadow-xs font-bold' : 'text-slate-600 hover:text-slate-900'"
                        class="px-4 py-2 rounded-xl text-xs font-semibold transition-smooth">📥 Import</button>
                <button @click="onglet = 'modeles'" :class="onglet === 'modeles' ? 'bg-white text-primary-700 shadow-xs font-bold' : 'text-slate-600 hover:text-slate-900'"
                        class="px-4 py-2 rounded-xl text-xs font-semibold transition-smooth">📑 Modèles</button>
                <button @click="onglet = 'export'" :class="onglet === 'export' ? 'bg-white text-primary-700 shadow-xs font-bold' : 'text-slate-600 hover:text-slate-900'"
                        class="px-4 py-2 rounded-xl text-xs font-semibold transition-smooth">📤 Export</button>
            @endcan
            <button @click="onglet = 'rapports'" :class="onglet === 'rapports' ? 'bg-white text-primary-700 shadow-xs font-bold' : 'text-slate-600 hover:text-slate-900'"
                    class="px-4 py-2 rounded-xl text-xs font-semibold transition-smooth">📋 Rapports</button>
            <button @click="onglet = 'journal'" :class="onglet === 'journal' ? 'bg-white text-primary-700 shadow-xs font-bold' : 'text-slate-600 hover:text-slate-900'"
                    class="px-4 py-2 rounded-xl text-xs font-semibold transition-smooth">🔍 Journal des échanges</button>
        </div>

        {{-- ── Onglet Modèles (§101 : cartes par type + download) ── --}}
        <div x-show="onglet === 'modeles'" x-cloak class="space-y-4">
            <div class="rounded-2xl border border-slate-200 bg-white p-5 text-xs text-slate-500 leading-relaxed">
                <strong class="text-slate-800">Workflow d'import (§116) :</strong>
                1 · Téléchargez le modèle officiel → 2 · Remplissez la feuille IMPORT (supprimez la ligne d'exemple fictive) →
                3 · Importez le fichier depuis l'onglet Import → 4 · Vérifiez la prévisualisation → 5 · Confirmez → 6 · Rapport final.
                Les feuilles INSTRUCTIONS et RÉFÉRENCES documentent chaque colonne et ses valeurs autorisées —
                elles ne sont JAMAIS importées (seule la feuille IMPORT est lue).
            </div>

            @php($modelesAutorises = collect(\App\Services\Io\Templates\ImportTemplateDefinition::definitions())
                ->filter(fn ($d) => auth()->user()->hasPermission($d['permissionWeb'])))
            @foreach($modelesAutorises as $type => $definition)
                <div class="rounded-2xl border border-slate-200/80 bg-white p-5 shadow-xs flex flex-col sm:flex-row sm:items-center gap-4">
                    <div class="flex h-11 w-11 flex-shrink-0 items-center justify-center rounded-xl bg-primary-50 border border-primary-100 text-xl">{{ $definition['icone'] }}</div>
                    <div class="flex-1 min-w-0">
                        <p class="text-sm font-bold text-slate-900">{{ $definition['label'] }}
                            <span class="ml-1.5 rounded-full bg-slate-100 px-2 py-0.5 text-[10px] font-bold text-slate-500">v{{ \App\Services\Io\Templates\ImportTemplateDefinition::VERSIONS[$type] }}</span>
                        </p>
                        <p class="text-xs text-slate-400">{{ $definition['description'] }}</p>
                        <p class="mt-1 text-[11px] text-slate-500 font-mono truncate">Colonnes : {{ implode(' · ', array_keys($definition['colonnes'])) }}</p>
                    </div>
                    <div class="flex flex-wrap gap-2 flex-shrink-0">
                        @foreach($definition['formats'] as $formatModele)
                            <a href="{{ route('admin.imports.templates.download', ['type' => $type, 'format' => $formatModele]) }}"
                               class="rounded-xl bg-slate-900 px-3 py-2 text-[11px] font-bold text-white hover:bg-slate-800 transition-smooth">⬇ {{ strtoupper($formatModele) }}</a>
                        @endforeach
                        <a href="{{ route('admin.imports.templates.download', ['type' => $type, 'format' => $definition['formats'][0], 'vide' => 1]) }}"
                           class="rounded-xl border border-slate-300 px-3 py-2 text-[11px] font-bold text-slate-600 hover:bg-slate-50 transition-smooth">Vierge</a>
                    </div>
                </div>
            @endforeach
        </div>

        {{-- ── Onglet Import ── --}}
        @can('configuration.manage')
            <div x-show="onglet === 'import'" x-cloak class="rounded-3xl border border-slate-200/80 bg-white p-6 shadow-xs">
                <h3 class="mb-1 text-sm font-bold text-slate-900">Importer depuis un système externe</h3>
                <p class="mb-5 text-xs text-slate-500">Workflow sécurisé : analyse → prévisualisation → confirmation. Aucune donnée n'est écrite sans votre accord explicite.</p>
                <form method="POST" action="{{ route('admin.integrations.import') }}" enctype="multipart/form-data"
                      class="grid grid-cols-1 sm:grid-cols-3 gap-4 items-end">
                    @csrf
                    <div>
                        <label class="mb-1.5 block text-xs font-bold text-slate-700 uppercase tracking-wider">Type de données *</label>
                        <select name="type" required class="w-full rounded-xl border border-slate-200 bg-white px-3.5 py-2.5 text-sm focus:border-primary-600 focus:ring-4 focus:ring-primary-500/15 focus:outline-none">
                            @foreach(\App\Services\Io\Templates\ImportTemplateDefinition::types() as $typeImport)
                                @if(auth()->user()->hasPermission(\App\Services\Io\Templates\ImportTemplateDefinition::definition($typeImport)['permissionWeb']))
                                    <option value="{{ $typeImport }}">{{ \App\Services\Io\Templates\ImportTemplateDefinition::definition($typeImport)['icone'] }} {{ \App\Services\Io\Templates\ImportTemplateDefinition::definition($typeImport)['label'] }}</option>
                                @endif
                            @endforeach
                        </select>
                        <p class="mt-1.5 text-[11px] text-slate-400">💡 Téléchargez d'abord le modèle officiel dans l'onglet « Modèles » — structure garantie compatible.</p>
                    </div>
                    <div>
                        <label class="mb-1.5 block text-xs font-bold text-slate-700 uppercase tracking-wider">Fichier (CSV, JSON, XML, XLSX — max 10 Mo) *</label>
                        <input type="file" name="file" required accept=".csv,.json,.xml,.xlsx,.txt"
                               class="w-full rounded-xl border border-slate-200 bg-white px-3.5 py-2.5 text-sm text-slate-600 file:mr-2 file:rounded-lg file:border-0 file:bg-slate-900 file:px-2.5 file:py-1.5 file:text-[11px] file:font-bold file:text-white focus:border-primary-600 focus:ring-4 focus:ring-primary-500/15 focus:outline-none">
                        @error('file')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror
                    </div>
                    <button type="submit" class="rounded-xl bg-gradient-to-r from-primary-600 to-accent-600 px-5 py-3 text-sm font-extrabold text-white shadow-lg shadow-primary-500/25 hover:-translate-y-0.5 transition-smooth">
                        Analyser le fichier
                    </button>
                </form>
            </div>

            {{-- ── Onglet Export ── --}}
            <div x-show="onglet === 'export'" x-cloak class="rounded-3xl border border-slate-200/80 bg-white p-6 shadow-xs">
                <h3 class="mb-1 text-sm font-bold text-slate-900">Exporter vers un système externe</h3>
                <p class="mb-5 text-xs text-slate-500">Profils restreints — le profil « statistique » n'expose aucune donnée individuelle.</p>
                <form method="POST" action="{{ route('admin.integrations.export') }}"
                      class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4 items-end">
                    @csrf
                    <div>
                        <label class="mb-1.5 block text-xs font-bold text-slate-700 uppercase tracking-wider">Type *</label>
                        <select name="type" required class="w-full rounded-xl border border-slate-200 bg-white px-3.5 py-2.5 pl-11 text-sm focus:border-primary-600 focus:ring-4 focus:ring-primary-500/15 focus:outline-none">
                            <option value="students">Étudiants / Parcours</option>
                            <option value="grades">Notes détaillées</option>
                        </select>
                    </div>
                    <div>
                        <label class="mb-1.5 block text-xs font-bold text-slate-700 uppercase tracking-wider">Profil *</label>
                        <select name="profil" class="w-full rounded-xl border border-slate-200 bg-white px-3.5 py-2.5 pl-11 text-sm focus:border-primary-600 focus:ring-4 focus:ring-primary-500/15 focus:outline-none">
                            <option value="administratif">Administratif (identité + contact)</option>
                            <option value="academique">Académique (parcours + résultats)</option>
                            <option value="statistique">Statistique (agrégats anonymes)</option>
                        </select>
                    </div>
                    <div>
                        <label class="mb-1.5 block text-xs font-bold text-slate-700 uppercase tracking-wider">Format *</label>
                        <select name="format" required class="w-full rounded-xl border border-slate-200 bg-white px-3.5 py-2.5 pl-11 text-sm focus:border-primary-600 focus:ring-4 focus:ring-primary-500/15 focus:outline-none">
                            <option value="csv">CSV</option><option value="xlsx">XLSX</option>
                            <option value="json">JSON</option><option value="xml">XML</option>
                        </select>
                    </div>
                    <div>
                        <label class="mb-1.5 block text-xs font-bold text-slate-700 uppercase tracking-wider">Filtre faculté (code)</label>
                        <input type="text" name="faculte" placeholder="ex : FST" class="w-full rounded-xl border border-slate-200 bg-white px-3.5 py-2.5 text-sm focus:border-primary-600 focus:ring-4 focus:ring-primary-500/15 focus:outline-none">
                    </div>
                    <div>
                        <label class="mb-1.5 block text-xs font-bold text-slate-700 uppercase tracking-wider">Filtre année</label>
                        <input type="text" name="annee" placeholder="2025-2026" class="w-full rounded-xl border border-slate-200 bg-white px-3.5 py-2.5 text-sm focus:border-primary-600 focus:ring-4 focus:ring-primary-500/15 focus:outline-none">
                    </div>
                    <button type="submit" class="rounded-xl bg-slate-900 px-5 py-3 text-sm font-extrabold text-white shadow-lg hover:-translate-y-0.5 transition-smooth">
                        Générer l'export
                    </button>
                </form>
            </div>
        @endcan

        {{-- ── Onglet Rapports ── --}}
        <div x-show="onglet === 'rapports'" x-cloak class="rounded-3xl border border-slate-200/80 bg-white shadow-xs overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="border-b border-slate-200 bg-slate-50/70 text-[11px] uppercase tracking-wider text-slate-400">
                        <tr>
                            <th class="px-5 py-3.5">Référence</th>
                            <th class="px-5 py-3.5">Sens</th>
                            <th class="px-5 py-3.5">Type / Format</th>
                            <th class="px-5 py-3.5">Lignes</th>
                            <th class="px-5 py-3.5">Statut</th>
                            <th class="px-5 py-3.5">Initié par</th>
                            <th class="px-5 py-3.5 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @forelse($rapports as $rapport)
                            <tr class="hover:bg-primary-50/30 transition-smooth">
                                <td class="px-5 py-4 font-mono text-xs font-bold text-slate-900">{{ $rapport->reference }}</td>
                                <td class="px-5 py-4">
                                    <span class="rounded-full px-2.5 py-1 text-[10px] font-bold {{ $rapport->direction === 'import' ? 'bg-indigo-100 text-indigo-700' : 'bg-emerald-100 text-emerald-700' }}">
                                        {{ strtoupper($rapport->direction) }}
                                    </span>
                                </td>
                                <td class="px-5 py-4 text-xs font-semibold text-slate-600">{{ $rapport->type }} · {{ strtoupper($rapport->format) }}</td>
                                <td class="px-5 py-4 text-xs text-slate-500">{{ $rapport->valides }} / {{ $rapport->total }}</td>
                                <td class="px-5 py-4 text-xs font-bold
                                    {{ $rapport->status === 'completed' ? 'text-emerald-600' : ($rapport->status === 'failed' ? 'text-rose-600' : ($rapport->status === 'pending' ? 'text-amber-600' : 'text-slate-500')) }}">
                                    {{ $rapport->statut_libelle }}
                                </td>
                                <td class="px-5 py-4 text-xs text-slate-500">{{ $rapport->user?->name ?? $rapport->integration }}</td>
                                <td class="px-5 py-4 text-right">
                                    {{-- Œil de vérification après importation : chaque type legacy pointe
                                        vers l'entité visualisable du centre Données (les résultats mettent
                                        à jour les semestres des inscriptions académiques).
                                        NOTE : uniquement des @php(...) en ligne ici — un bloc fermé
                                        casserait la compilation (capté par le @php en ligne plus haut). --}}
                                    @php($entitesVerifiables = ['students' => 'etudiants', 'grades' => 'notes', 'academic_registrations' => 'inscriptions-academiques', 'subjects' => 'matieres', 'results' => 'inscriptions-academiques', 'documents' => 'documents-academiques'])
                                    @php($entiteVerif = $entitesVerifiables[$rapport->type] ?? null)
                                    @php($oeilDonnees = $entiteVerif !== null && in_array($entiteVerif, \App\Services\DataExchange\DefinitionRegistry::exportables(), true))
                                    @if($rapport->direction === 'import' && $rapport->status === 'pending')
                                        @can('configuration.manage')
                                            <a href="{{ route('admin.integrations.preview', $rapport) }}" class="rounded-xl bg-slate-900 px-3 py-1.5 text-[11px] font-bold text-white hover:bg-slate-800 transition-smooth">Confirmer ?</a>
                                        @endcan
                                    @elseif($rapport->direction === 'import' && $rapport->error_rows > 0)
                                        @can('configuration.manage')
                                            <a href="{{ route('admin.imports.templates.erreurs', $rapport) }}" class="rounded-xl border border-rose-300 px-3 py-1.5 text-[11px] font-bold text-rose-600 hover:bg-rose-50 transition-smooth">⬇ Erreurs</a>
                                        @endcan
                                    @elseif($rapport->direction === 'export' && $rapport->status === 'completed')
                                        <a href="{{ route('admin.integrations.download', $rapport) }}" class="rounded-xl bg-slate-900 px-3 py-1.5 text-[11px] font-bold text-white hover:bg-slate-800 transition-smooth">Télécharger</a>
                                    @endif
                                    @if($rapport->direction === 'import' && $rapport->status === 'completed' && $oeilDonnees)
                                        @can('data.import')
                                            <a href="{{ route('admin.donnees.voir', $entiteVerif) }}"
                                               class="inline-flex items-center gap-1 rounded-xl border border-sky-300 bg-sky-50 px-3 py-1.5 text-[11px] font-bold text-sky-700 hover:bg-sky-100 transition-smooth"
                                               title="Vérifier les données importées en base">
                                                <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z" />
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                                </svg>
                                                Vérifier
                                            </a>
                                        @elsecan('documents.view')
                                            @if($rapport->type === 'documents')
                                                <a href="{{ route('admin.ged.index') }}"
                                                   class="inline-flex items-center gap-1 rounded-xl border border-sky-300 bg-sky-50 px-3 py-1.5 text-[11px] font-bold text-sky-700 hover:bg-sky-100 transition-smooth"
                                                   title="Vérifier les documents déclarés">
                                                    <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                                        <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z" />
                                                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                                    </svg>
                                                    Vérifier
                                                </a>
                                            @endif
                                        @elsecan('archives.view')
                                            <a href="{{ $rapport->type === 'subjects' ? route('admin.archives.configuration.index') : route('admin.archives.index') }}"
                                               class="inline-flex items-center gap-1 rounded-xl border border-sky-300 bg-sky-50 px-3 py-1.5 text-[11px] font-bold text-sky-700 hover:bg-sky-100 transition-smooth"
                                               title="Vérifier les données importées">
                                                <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z" />
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                                </svg>
                                                Vérifier
                                            </a>
                                        @endcan
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="px-5 py-14 text-center text-sm text-slate-400">Aucun échange enregistré.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="border-t border-slate-100 px-5 py-3">{{ $rapports->links() }}</div>
        </div>

        {{-- ── Onglet Journal ── --}}
        <div x-show="onglet === 'journal'" x-cloak class="rounded-3xl border border-slate-200/80 bg-white shadow-xs overflow-hidden">
            <div class="border-b border-slate-100 px-5 py-3.5 text-xs text-slate-500">
                Journal des intégrations — un enregistrement par échange (API, import, export, webhook). Aucun payload : uniquement empreintes SHA-256.
            </div>
            <div class="divide-y divide-slate-50">
                @forelse($journal as $entree)
                    <div class="flex flex-wrap items-center gap-x-4 gap-y-1 px-5 py-3 text-xs">
                        <span class="rounded-md px-2 py-0.5 font-black
                            {{ $entree->status === 'completed' ? 'bg-emerald-100 text-emerald-700' : 'bg-rose-100 text-rose-700' }}">
                            {{ strtoupper($entree->status) }}
                        </span>
                        <span class="font-bold text-slate-800">{{ $entree->integration }} · {{ $entree->direction }}</span>
                        <span class="font-mono text-slate-500">{{ $entree->operation }}</span>
                        @if($entree->http_status)
                            <span class="rounded-md bg-slate-100 px-1.5 py-0.5 font-bold text-slate-600">HTTP {{ $entree->http_status }}</span>
                        @endif
                        <span class="ml-auto text-slate-400">{{ $entree->created_at->format('d/m/Y H:i:s') }}</span>
                    </div>
                @empty
                    <div class="px-5 py-10 text-center text-sm text-slate-400">Aucun échange journalisé pour le moment.</div>
                @endforelse
            </div>
        </div>
    </div>
</div>
@endsection
