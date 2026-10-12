@extends('layouts.app')

@section('title', 'Vérification des données : ' . $definition->libelle() . ' — Scolarité')

@section('content')
    <div class="mx-auto max-w-7xl px-4 sm:px-6 py-8">
        <x-page-header :titre="'Données : ' . $definition->libelle()" 
                       :description="'Contrôle et inspection visuelle des enregistrements actuellement présents en base de données.'"
                       :fil="['Données' => route('admin.donnees.hub'), $definition->libelle() => '#']" />

        <!-- Actions & Compteurs -->
        <div class="mt-4 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 bg-white p-4 rounded-2xl border border-slate-200 shadow-sm">
            <div class="flex items-center gap-3">
                <span class="inline-flex items-center justify-center w-10 h-10 rounded-xl bg-sky-100 text-sky-700">
                    <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z" />
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                    </svg>
                </span>
                <div>
                    <h2 class="text-sm font-black text-slate-900">Enregistrements en base</h2>
                    <p class="text-xs text-slate-500">
                        Total : <span class="font-bold text-sky-800 tabular-nums">{{ number_format($total, 0, ',', ' ') }}</span> élément(s)
                    </p>
                </div>
            </div>

            <div class="flex flex-wrap items-center gap-2 text-xs">
                @if($routeMetier)
                    <a href="{{ $routeMetier }}" class="inline-flex items-center gap-1.5 px-3 py-2 rounded-xl bg-slate-100 text-slate-800 font-bold hover:bg-slate-200 transition">
                        <span>Module dédié</span>
                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5L21 12m0 0l-7.5 7.5M21 12H3" />
                        </svg>
                    </a>
                @endif
                <a href="{{ route('admin.donnees.import.form', $entite) }}" class="inline-flex items-center gap-1.5 px-3 py-2 rounded-xl bg-primary-800 text-white font-bold hover:bg-primary-700 transition">
                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.5V16.5m-13.5-9L12 3m0 0l4.5 4.5M12 3v13.5" />
                    </svg>
                    <span>Importer</span>
                </a>
                <div class="flex items-center border border-slate-200 rounded-xl overflow-hidden bg-slate-50">
                    <span class="px-2 text-[11px] font-bold text-slate-400">Export :</span>
                    <a href="{{ route('admin.donnees.export', [$entite, 'csv']) }}" class="px-2.5 py-1.5 font-bold text-slate-700 hover:bg-white hover:text-primary-800 transition border-l border-slate-200">CSV</a>
                    <a href="{{ route('admin.donnees.export', [$entite, 'xlsx']) }}" class="px-2.5 py-1.5 font-bold text-slate-700 hover:bg-white hover:text-primary-800 transition border-l border-slate-200">XLSX</a>
                    <a href="{{ route('admin.donnees.export', [$entite, 'json']) }}" class="px-2.5 py-1.5 font-bold text-slate-700 hover:bg-white hover:text-primary-800 transition border-l border-slate-200">JSON</a>
                </div>
            </div>
        </div>

        <!-- Filtre de recherche -->
        <div class="mt-4">
            <form method="GET" action="{{ route('admin.donnees.voir', $entite) }}" class="flex items-center gap-2">
                <div class="relative flex-1 max-w-md">
                    <span class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none text-slate-400">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z" />
                        </svg>
                    </span>
                    <input type="text" name="search" value="{{ $search }}" placeholder="Rechercher (code, nom, libellé...)..." 
                           class="w-full pl-9 pr-3 py-2 text-xs rounded-xl border border-slate-200 bg-white focus:outline-none focus:ring-2 focus:ring-primary-600 focus:border-transparent">
                </div>
                <button type="submit" class="px-3 py-2 text-xs font-bold rounded-xl bg-slate-800 text-white hover:bg-slate-700 transition">
                    Filtrer
                </button>
                @if($search !== '')
                    <a href="{{ route('admin.donnees.voir', $entite) }}" class="px-3 py-2 text-xs font-bold rounded-xl border border-slate-300 text-slate-700 hover:bg-slate-50 transition">
                        Réinitialiser
                    </a>
                @endif
            </form>
        </div>

        <!-- Tableau des données -->
        <div class="mt-4 overflow-x-auto rounded-2xl border border-slate-200 bg-white shadow-sm">
            @if($total > 0)
                <table class="w-full min-w-[720px] text-left text-xs sm:text-sm">
                    <thead>
                        <tr class="border-b border-slate-200 bg-slate-50/70 text-[11px] uppercase tracking-wider text-slate-500">
                            <th scope="col" class="px-4 py-3 font-bold text-center w-12">#</th>
                            @foreach($colonnes as $colonne)
                                <th scope="col" class="px-4 py-3 font-bold">{{ str_replace('_', ' ', $colonne) }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach($lignes as $index => $ligne)
                            <tr class="hover:bg-sky-50/40 transition">
                                <td class="px-4 py-3 text-center text-slate-400 tabular-nums text-xs">
                                    {{ $modeles->firstItem() + $index }}
                                </td>
                                @foreach($colonnes as $colonne)
                                    @php
                                        $valeur = $ligne[$colonne] ?? '—';
                                    @endphp
                                    <td class="px-4 py-3 text-slate-700">
                                        @if(in_array(strtolower((string)$valeur), ['actif', 'active', 'valide', 'validee', 'completed', 'oui', 'true', '1'], true))
                                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold bg-emerald-100 text-emerald-800">
                                                {{ $valeur }}
                                            </span>
                                        @elseif(in_array(strtolower((string)$valeur), ['inactif', 'inactive', 'rejete', 'abandon', 'cancelled', 'non', 'false', '0'], true))
                                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold bg-rose-100 text-rose-800">
                                                {{ $valeur }}
                                            </span>
                                        @elseif(in_array(strtolower((string)$valeur), ['en_attente', 'en_cours', 'draft', 'planned', 'provisoire', 'a_generer'], true))
                                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold bg-amber-100 text-amber-800">
                                                {{ $valeur }}
                                            </span>
                                        @elseif(str_contains($colonne, 'code') || str_contains($colonne, 'matricule') || str_contains($colonne, 'cin') || str_contains($colonne, 'reference'))
                                            <span class="font-mono text-xs font-semibold text-slate-900 bg-slate-100 px-1.5 py-0.5 rounded">
                                                {{ $valeur }}
                                            </span>
                                        @elseif(str_contains($colonne, 'email'))
                                            <span class="font-mono text-xs text-slate-600">
                                                {{ $valeur }}
                                            </span>
                                        @else
                                            <span>{{ is_array($valeur) ? json_encode($valeur) : $valeur }}</span>
                                        @endif
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>

                <div class="px-5 py-3 border-t border-slate-100">
                    {{ $modeles->links() }}
                </div>
            @else
                <div class="p-8 text-center">
                    <x-empty-state message="Aucune donnée enregistrée pour cette entité." />
                    <div class="mt-4">
                        <a href="{{ route('admin.donnees.import.form', $entite) }}" class="inline-flex items-center gap-1.5 px-4 py-2 rounded-xl bg-primary-800 text-white font-bold text-xs hover:bg-primary-700 transition">
                            Importer un premier fichier
                        </a>
                    </div>
                </div>
            @endif
        </div>
    </div>
@endsection
