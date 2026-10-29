@extends('layouts.app')

@section('title', 'Knowledge Hub — Administration des Sources IA')

@section('content')
<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6">
    <!-- En-tête -->
    <div class="mb-6 flex flex-col md:flex-row md:items-center md:justify-between gap-4">
        <div>
            <div class="flex items-center gap-2">
                <a href="{{ route('admin.assistant-ia.index') }}" class="p-2 rounded-xl bg-gray-100 hover:bg-gray-200 text-gray-700 transition">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
                    </svg>
                </a>
                <span class="inline-flex items-center justify-center p-2 rounded-xl bg-indigo-600 text-white shadow-md shadow-indigo-200">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"/>
                    </svg>
                </span>
                <h1 class="text-2xl font-bold text-gray-900">Knowledge Hub UniRegistre</h1>
                <span class="text-xs px-2.5 py-0.5 rounded-full font-semibold bg-indigo-100 text-indigo-800 border border-indigo-200">
                    Administration des Sources
                </span>
            </div>
            <p class="text-sm text-gray-600 mt-1">
                Registre central des connaissances souveraines : code projet, documentation métier LMD et web technique contrôlé.
            </p>
        </div>

        <div class="flex flex-wrap items-center gap-3">
            <form method="POST" action="{{ route('admin.assistant-ia.sources.initialiser') }}">
                @csrf
                <button type="submit" class="inline-flex items-center gap-2 px-3.5 py-2 text-sm font-medium text-indigo-700 bg-indigo-50 border border-indigo-200 rounded-xl shadow-sm hover:bg-indigo-100 transition" title="Découvrir et enregistrer toutes les sources Markdown du projet">
                    <svg class="w-4 h-4 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"/>
                    </svg>
                    Découvrir toutes les sources
                </button>
            </form>

            <form method="POST" action="{{ route('admin.assistant-ia.sources.synchroniser_tout') }}">
                @csrf
                <button type="submit" class="inline-flex items-center gap-2 px-3.5 py-2 text-sm font-semibold text-white bg-indigo-600 rounded-xl shadow-sm hover:bg-indigo-700 transition" title="Indexer l'intégralité des sources Markdown dans le RAG">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                    </svg>
                    Tout synchroniser (RAG)
                </button>
            </form>

            <a href="{{ route('admin.assistant-ia.index') }}" class="inline-flex items-center gap-2 px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-xl shadow-sm hover:bg-gray-50 transition">
                <svg class="w-4 h-4 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/>
                </svg>
                Retour à l'Assistant
            </a>
        </div>
    </div>

    <!-- Messages Flash -->
    @if(session('succes'))
        <div class="mb-4 p-4 rounded-xl bg-emerald-50 border border-emerald-200 text-emerald-800 text-sm flex items-center gap-3">
            <svg class="w-5 h-5 text-emerald-600 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
            </svg>
            <div>{{ session('succes') }}</div>
        </div>
    @endif

    @if(session('erreur'))
        <div class="mb-4 p-4 rounded-xl bg-rose-50 border border-rose-200 text-rose-800 text-sm flex items-center gap-3">
            <svg class="w-5 h-5 text-rose-600 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
            </svg>
            <div>{{ session('erreur') }}</div>
        </div>
    @endif

    <!-- Cartes KPI & Diagnostic -->
    <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
        <div class="bg-white border border-gray-200 rounded-2xl p-4 shadow-sm">
            <div class="text-xs font-semibold uppercase tracking-wider text-gray-500 mb-1">Sources Actives</div>
            <div class="text-2xl font-bold text-gray-900">
                {{ $sources->where('statut', 'actif')->count() }} <span class="text-xs font-normal text-gray-500">/ {{ $sources->count() }} total</span>
            </div>
            <div class="text-xs text-emerald-600 mt-1 flex items-center gap-1">
                <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span>
                Registre central validé
            </div>
        </div>

        <div class="bg-white border border-gray-200 rounded-2xl p-4 shadow-sm">
            <div class="text-xs font-semibold uppercase tracking-wider text-gray-500 mb-1">Fragments Indexés</div>
            <div class="text-2xl font-bold text-indigo-600">{{ number_format($totalMorceaux) }}</div>
            <div class="text-xs text-gray-500 mt-1">
                Code + Documentation + Rapports
            </div>
        </div>

        <div class="bg-white border border-gray-200 rounded-2xl p-4 shadow-sm">
            <div class="text-xs font-semibold uppercase tracking-wider text-gray-500 mb-1">Documents Obsolètes</div>
            <div class="text-2xl font-bold {{ $docsObsoletes > 0 ? 'text-amber-600' : 'text-gray-900' }}">
                {{ $docsObsoletes }}
            </div>
            <div class="text-xs text-gray-500 mt-1">
                Exclus du RAG par précaution
            </div>
        </div>

        <div class="bg-white border border-gray-200 rounded-2xl p-4 shadow-sm">
            <div class="text-xs font-semibold uppercase tracking-wider text-gray-500 mb-1">Cible Locale (Ollama)</div>
            <div class="flex items-center gap-2">
                @if(($diagnosticLocal['serveur_ollama_disponible'] ?? false))
                    <span class="w-2.5 h-2.5 rounded-full bg-emerald-500"></span>
                    <span class="font-bold text-gray-900 text-sm">En ligne</span>
                @else
                    <span class="w-2.5 h-2.5 rounded-full bg-amber-500"></span>
                    <span class="font-bold text-amber-700 text-sm">Dégradé / Hors-ligne</span>
                @endif
            </div>
            <div class="text-xs text-gray-500 mt-1">
                {{ $diagnosticLocal['profil_materiel']['modele_recommande_generatif'] ?? 'qwen2.5-coder:7b' }}
            </div>
        </div>
    </div>

    <!-- Diagnostic Matériel Apple Silicon M2 -->
    <div class="bg-gradient-to-r from-slate-900 to-indigo-950 text-white rounded-2xl p-5 mb-6 shadow-md">
        <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
            <div>
                <div class="flex items-center gap-2 text-indigo-300 text-xs font-semibold uppercase tracking-wider">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 3v2m6-2v2M9 19v2m6-2v2M5 9H3m2 6H3m18-6h-2m2 6h-2M7 19h10a2 2 0 002-2V7a2 2 0 00-2-2H7a2 2 0 00-2 2v10a2 2 0 002 2zM9 9h6v6H9V9z"/>
                    </svg>
                    Profil Hôte Local — Souveraineté & Données Internes
                </div>
                <h2 class="text-lg font-bold text-white mt-1">
                    {{ $diagnosticLocal['profil_materiel']['architecture'] ?? 'Mac' }} — {{ $diagnosticLocal['profil_materiel']['memoire_totale_go'] ?? 8 }} Go RAM unifiée
                </h2>
                <p class="text-xs text-slate-300 mt-1">
                    Disque disponible : <strong class="text-white">{{ $diagnosticLocal['profil_materiel']['disque_libre_go'] ?? 'N/A' }} Go</strong> | 
                    Modèle souverain local : <span class="bg-emerald-900/80 px-2 py-0.5 rounded text-emerald-200 font-mono">{{ $diagnosticLocal['profil_materiel']['modele_recommande_generatif'] ?? 'nvidia/NVIDIA-Nemotron-3.5-Bolt-E4B-4GB-DFlash' }}</span> | 
                    Embeddings RAG (≤ 50M) : <span class="bg-indigo-800/80 px-2 py-0.5 rounded text-indigo-200 font-mono">{{ $diagnosticLocal['profil_materiel']['modele_recommande_embeddings'] ?? 'all-minilm' }}</span> | 
                    <span class="inline-flex items-center gap-1 text-emerald-400 font-semibold text-[11px]">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                        NVIDIA Nemotron 3.5 Bolt (E4B 4GB DFlash)
                    </span>
                </p>
            </div>
            <div class="text-xs text-slate-400 bg-slate-800/70 border border-slate-700 p-3 rounded-xl max-w-sm">
                <strong>Garantie Souveraine :</strong> Aucun appel vers une API distante en mode local. Aucune donnée d'étudiant, note ou secret n'est envoyée hors de l'instance locale.
            </div>
        </div>
    </div>

    <!-- Table des Sources -->
    <div class="bg-white border border-gray-200 rounded-2xl shadow-sm overflow-hidden mb-6">
        <div class="px-6 py-4 border-b border-gray-100 flex items-center justify-between">
            <div>
                <h3 class="text-base font-bold text-gray-900">Sources de connaissances autorisées</h3>
                <p class="text-xs text-gray-500">Périmètres indexés pour l'exploration par l'assistant</p>
            </div>
            <span class="text-xs text-gray-500 font-mono">{{ $sources->count() }} enregistrements</span>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm text-gray-700">
                <thead class="bg-gray-50 text-xs font-semibold uppercase tracking-wider text-gray-500 border-b border-gray-200">
                    <tr>
                        <th class="px-6 py-3">Source & Périmètre</th>
                        <th class="px-6 py-3">Type</th>
                        <th class="px-6 py-3">Statut</th>
                        <th class="px-6 py-3">Volume Indexé</th>
                        <th class="px-6 py-3">Dernière Synchro</th>
                        <th class="px-6 py-3 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse($sources as $source)
                        <tr class="hover:bg-gray-50/70 transition">
                            <td class="px-6 py-4">
                                <div class="font-bold text-gray-900">{{ $source->nom }}</div>
                                <div class="text-xs text-gray-500 mt-0.5">{{ $source->description }}</div>
                                <div class="text-xs text-gray-400 font-mono mt-1 flex items-center gap-1">
                                    <svg class="w-3.5 h-3.5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"/>
                                    </svg>
                                    {{ Str::limit($source->origine, 60) }}
                                </div>
                                @if($source->derniere_erreur)
                                    <div class="mt-2 text-xs text-rose-600 bg-rose-50 border border-rose-200 rounded p-1.5">
                                        <strong>Erreur :</strong> {{ $source->derniere_erreur }}
                                    </div>
                                @endif
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                @php
                                    $typeBadges = [
                                        'project_code' => ['bg' => 'bg-blue-50 text-blue-700 border-blue-200', 'label' => 'Code Source'],
                                        'internal_document' => ['bg' => 'bg-indigo-50 text-indigo-700 border-indigo-200', 'label' => 'Doc Interne'],
                                        'business_procedure' => ['bg' => 'bg-purple-50 text-purple-700 border-purple-200', 'label' => 'Procédure LMD'],
                                        'loop_report' => ['bg' => 'bg-emerald-50 text-emerald-700 border-emerald-200', 'label' => 'Rapport LOOP'],
                                        'approved_web_source' => ['bg' => 'bg-cyan-50 text-cyan-700 border-cyan-200', 'label' => 'Web Autorisé'],
                                    ];
                                    $badge = $typeBadges[$source->type] ?? ['bg' => 'bg-gray-100 text-gray-700 border-gray-200', 'label' => $source->type];
                                @endphp
                                <span class="text-xs px-2.5 py-1 rounded-full font-semibold border {{ $badge['bg'] }}">
                                    {{ $badge['label'] }}
                                </span>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                @if($source->estActive())
                                    <span class="inline-flex items-center gap-1.5 text-xs font-semibold px-2.5 py-1 rounded-full bg-emerald-50 text-emerald-700 border border-emerald-200">
                                        <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span>
                                        Actif
                                    </span>
                                @elseif($source->statut === 'erreur')
                                    <span class="inline-flex items-center gap-1.5 text-xs font-semibold px-2.5 py-1 rounded-full bg-rose-50 text-rose-700 border border-rose-200">
                                        <span class="w-1.5 h-1.5 rounded-full bg-rose-500"></span>
                                        Erreur
                                    </span>
                                @else
                                    <span class="inline-flex items-center gap-1.5 text-xs font-semibold px-2.5 py-1 rounded-full bg-gray-100 text-gray-700 border border-gray-200">
                                        <span class="w-1.5 h-1.5 rounded-full bg-gray-400"></span>
                                        Suspendu
                                    </span>
                                @endif
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-xs text-gray-600">
                                <div class="font-bold text-gray-800">{{ $source->nombre_documents }} docs</div>
                                <div class="text-gray-500">{{ $source->nombre_morceaux }} fragments</div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-xs text-gray-500">
                                @if($source->derniere_synchronisation_a)
                                    <div>{{ $source->derniere_synchronisation_a->format('d/m/Y H:i') }}</div>
                                    <div class="text-gray-400">{{ $source->derniere_synchronisation_a->diffForHumans() }}</div>
                                @else
                                    <span class="text-gray-400">Jamais synchronisé</span>
                                @endif
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-right text-xs">
                                <div class="flex items-center justify-end gap-2">
                                    <form method="POST" action="{{ route('admin.assistant-ia.sources.synchroniser', $source->id) }}">
                                        @csrf
                                        <button type="submit" class="inline-flex items-center gap-1 px-3 py-1.5 bg-indigo-50 hover:bg-indigo-100 text-indigo-700 rounded-lg font-medium transition border border-indigo-200" title="Relancer la synchronisation">
                                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                                            </svg>
                                            Synchro
                                        </button>
                                    </form>

                                    <form method="POST" action="{{ route('admin.assistant-ia.sources.basculer', $source->id) }}">
                                        @csrf
                                        @if($source->estActive())
                                            <button type="submit" class="inline-flex items-center gap-1 px-3 py-1.5 bg-gray-50 hover:bg-gray-100 text-gray-700 rounded-lg font-medium transition border border-gray-200" title="Suspendre temporairement cette source">
                                                Suspendre
                                            </button>
                                        @else
                                            <button type="submit" class="inline-flex items-center gap-1 px-3 py-1.5 bg-emerald-50 hover:bg-emerald-100 text-emerald-700 rounded-lg font-medium transition border border-emerald-200" title="Réactiver cette source">
                                                Réactiver
                                            </button>
                                        @endif
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-6 py-8 text-center text-gray-500 text-sm">
                                Aucune source de connaissance enregistrée dans le Knowledge Hub.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
