@extends('layouts.app')

@section('title', 'Statistiques et Mesure de l’expérience')

@section('content')
    <div class="mx-auto max-w-6xl px-4 sm:px-6 py-8 space-y-8">
        <x-page-header titre="Statistiques & Mesure de l'expérience" description="Indicateurs transversaux, mesure de l'expérience d'inscription et cycle d'amélioration continue."
            :fil="['Statistiques' => '#']">
            <x-slot:actions>
                <x-btn variant="secondary" href="{{ route('admin.statistiques.export', request()->query()) }}">Exporter CSV</x-btn>
            </x-slot:actions>
        </x-page-header>

        <form method="get" action="{{ route('admin.statistiques.index') }}" class="flex flex-wrap gap-2 items-center bg-white dark:bg-slate-800 p-4 rounded-2xl border border-slate-200 dark:border-slate-700 shadow-sm">
            <select name="annee_universitaire_id" class="rounded-xl border border-slate-200 dark:border-slate-700 dark:bg-slate-900 px-3 py-2 text-sm">
                @foreach($annees as $annee)
                    <option value="{{ $annee->id }}" @selected((int) $filtres['annee_universitaire_id'] === $annee->id)>{{ $annee->libelle }}</option>
                @endforeach
            </select>
            @if($facultes->isNotEmpty())
                <select name="faculte_id" class="rounded-xl border border-slate-200 dark:border-slate-700 dark:bg-slate-900 px-3 py-2 text-sm">
                    <option value="">Toutes facultés</option>
                    @foreach($facultes as $faculte)
                        <option value="{{ $faculte->id }}" @selected((int) $filtres['faculte_id'] === $faculte->id)>{{ $faculte->nom }}</option>
                    @endforeach
                </select>
            @endif
            <x-btn size="sm" type="submit">Filtrer</x-btn>
        </form>

        {{-- Cartes de synthèse transversale --}}
        <div>
            <h2 class="text-base font-semibold text-slate-800 dark:text-slate-100 mb-3">Indicateurs d'activité globaux</h2>
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4">
                @foreach($indicateurs as $libelle => $valeur)
                    <x-stat-card :libelle="$libelle" :valeur="$valeur" />
                @endforeach
            </div>
        </div>

        {{-- Section LOOP 22 : Métriques de l'expérience d'inscription --}}
        @if(isset($syntheseExperience))
            <div class="bg-white dark:bg-slate-800 rounded-2xl border border-slate-200 dark:border-slate-700 p-6 shadow-sm space-y-6">
                <div class="flex items-center justify-between border-b border-slate-100 dark:border-slate-700 pb-4">
                    <div>
                        <h2 class="text-lg font-bold text-slate-900 dark:text-white">Qualité & Expérience d'inscription (LOOP 22)</h2>
                        <p class="text-xs text-slate-500 mt-0.5">Métriques objectives avec formules, sources unifiées et minimisation stricte des données (zéro PII).</p>
                    </div>
                    <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-medium bg-emerald-50 dark:bg-emerald-950 text-emerald-700 dark:text-emerald-300 border border-emerald-200 dark:border-emerald-800">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        Données consolidées
                    </span>
                </div>

                {{-- Tableau des 5 métriques avec formules et sources --}}
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-xs border-collapse">
                        <thead>
                            <tr class="border-b border-slate-200 dark:border-slate-700 text-slate-500 font-semibold uppercase tracking-wider">
                                <th class="py-3 px-3">Métrique</th>
                                <th class="py-3 px-3 text-center">Valeur constatée</th>
                                <th class="py-3 px-3">Formule de calcul</th>
                                <th class="py-3 px-3">Source de données</th>
                                <th class="py-3 px-3">Finalité / Interprétation</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 dark:divide-slate-700/60 text-slate-700 dark:text-slate-300">
                            @foreach($syntheseExperience['indicateurs'] as $cle => $item)
                                <tr class="hover:bg-slate-50/50 dark:hover:bg-slate-750/50 transition">
                                    <td class="py-3.5 px-3 font-medium text-slate-900 dark:text-white">{{ $item['libelle'] }}</td>
                                    <td class="py-3.5 px-3 text-center">
                                        <span class="inline-block px-2.5 py-1 rounded-lg text-sm font-bold bg-indigo-50 dark:bg-indigo-950/60 text-indigo-700 dark:text-indigo-300 border border-indigo-200/60 dark:border-indigo-800/60">
                                            {{ $item['valeur'] !== null ? $item['valeur'] . ' ' . $item['unite'] : '—' }}
                                        </span>
                                    </td>
                                    <td class="py-3.5 px-3 font-mono text-[11px] text-slate-600 dark:text-slate-400 max-w-xs">{{ $item['formule'] }}</td>
                                    <td class="py-3.5 px-3 font-mono text-[11px] text-slate-500 dark:text-slate-400">{{ $item['source'] }}</td>
                                    <td class="py-3.5 px-3 text-slate-600 dark:text-slate-300 max-w-sm leading-relaxed">{{ $item['interpretation'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                {{-- Garde-fou méthodologique & Cycle d'amélioration continue --}}
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 pt-2">
                    <div class="p-4 rounded-xl bg-amber-50/70 dark:bg-amber-950/30 border border-amber-200 dark:border-amber-800/60">
                        <div class="flex items-center gap-2 text-amber-800 dark:text-amber-300 font-semibold text-xs mb-1.5">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                            Garde-fou méthodologique (Non-causalité)
                        </div>
                        <p class="text-xs text-amber-900/90 dark:text-amber-200/90 leading-relaxed">
                            {{ $syntheseExperience['cycle_amelioration']['garde_fou_causalite'] }}
                        </p>
                    </div>

                    <div class="p-4 rounded-xl bg-blue-50/70 dark:bg-blue-950/30 border border-blue-200 dark:border-blue-800/60">
                        <div class="flex items-center gap-2 text-blue-800 dark:text-blue-300 font-semibold text-xs mb-1.5">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                            Cycle d'amélioration continue fondé sur les faits
                        </div>
                        <ul class="text-xs text-blue-900/90 dark:text-blue-200/90 space-y-1 list-disc list-inside leading-relaxed">
                            @foreach($syntheseExperience['cycle_amelioration']['recommandations'] as $reco)
                                <li>{{ $reco }}</li>
                            @endforeach
                        </ul>
                    </div>
                </div>
            </div>
        @endif
    </div>
@endsection
