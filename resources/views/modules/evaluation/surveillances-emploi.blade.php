@extends('layouts.app')

@section('title', 'Emploi du temps des surveillances')

@section('content')
<div class="mx-auto max-w-7xl px-4 sm:px-6 py-8"
     x-data="{
         modaleExamen: {{ $detailExamen ? 'true' : 'false' }},
         examenCourant: null
     }">

    <!-- En-tête de page -->
    <x-page-header
        titre="📅 Emploi des surveillances"
        :description="($sessionDominante ? 'Session : ' . $sessionDominante->nom . ' — ' : '') . 'Visualisation et gestion en lecture seule des affectations des enseignants aux examens.'"
        :fil="['Évaluations' => route('admin.evaluations.index'), 'Surveillance' => route('admin.evaluations.surveillances.index'), 'Emploi des surveillances' => '#']">
        <x-slot:actions>
            <div class="flex flex-wrap items-center gap-2 no-print">
                <x-btn variant="secondary" href="{{ route('admin.evaluations.affectations.index', request()->only('session_id')) }}">
                    ⚡ Affectation enseignants
                </x-btn>
                <x-btn variant="secondary" href="{{ route('admin.evaluations.surveillances.cockpit', request()->only('session_id')) }}">
                    Cockpit gardes
                </x-btn>
                <button type="button"
                        onclick="window.print()"
                        class="inline-flex items-center justify-center gap-1.5 rounded-xl px-4 py-2.5 text-xs font-bold transition focus:outline-none min-h-[44px] border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 text-slate-700 dark:text-slate-200 hover:bg-slate-50 dark:hover:bg-slate-700 shadow-xs cursor-pointer">
                    <svg class="w-4 h-4 text-slate-600 dark:text-slate-300" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6.72 13.829c-.24.03-.48.062-.72.096m.72-.096a42.415 42.415 0 0 1 10.56 0m-10.56 0L6.34 18m10.94-4.171c.24.03.48.062.72.096m-.72-.096L17.66 18m0 0 .229 2.523a1.125 1.125 0 0 1-1.12 1.227H7.231c-.662 0-1.18-.568-1.12-1.227L6.34 18m11.318 0h1.091A2.25 2.25 0 0 0 21 15.75V9.456c0-1.081-.768-2.015-1.837-2.175a48.055 48.055 0 0 0-1.913-.247M6.34 18H5.25A2.25 2.25 0 0 1 3 15.75V9.456c0-1.081.768-2.015 1.837-2.175a48.041 48.041 0 0 1 1.913-.247m10.5 0a48.536 48.536 0 0 0-10.5 0m10.5 0V3.375c0-.621-.504-1.125-1.125-1.125h-8.25c-.621 0-1.125.504-1.125 1.125v3.659M18 10.5h.008v.008H18V10.5Zm-3 0h.008v.008H15V10.5Z" /></svg>
                    <span>🖨 Imprimer</span>
                </button>
                <a href="{{ route('admin.evaluations.surveillances.emploi.pdf', request()->query()) }}"
                   class="inline-flex items-center justify-center gap-1.5 rounded-xl px-4 py-2.5 text-xs font-bold transition focus:outline-none min-h-[44px] border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 text-slate-700 dark:text-slate-200 hover:bg-slate-50 dark:hover:bg-slate-700 shadow-xs cursor-pointer">
                    <svg class="w-4 h-4 text-rose-600" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m2.25 0H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z" /></svg>
                    <span>Exporter PDF</span>
                </a>
                <a href="{{ route('admin.evaluations.surveillances.emploi.csv', array_merge(request()->query(), ['mode' => $vue === 'enseignant' ? 'enseignant' : 'jour'])) }}"
                   class="inline-flex items-center justify-center gap-1.5 rounded-xl px-4 py-2.5 text-xs font-bold transition focus:outline-none min-h-[44px] border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 text-slate-700 dark:text-slate-200 hover:bg-slate-50 dark:hover:bg-slate-700 shadow-xs cursor-pointer">
                    <svg class="w-4 h-4 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5M16.5 12 12 16.5m0 0L7.5 12m4.5 4.5V3" /></svg>
                    <span>Exporter CSV</span>
                </a>
            </div>
        </x-slot:actions>
    </x-page-header>

    <!-- En-tête imprimable (uniquement visible à l'impression) -->
    <div class="print-only hidden p-4 mb-6 border-b border-slate-300">
        <h1 class="text-xl font-black text-slate-900 uppercase tracking-wide">EMPLOI DU TEMPS DES SURVEILLANCES</h1>
        <p class="text-xs text-slate-600 mt-1">
            <strong>Session :</strong> {{ $sessionDominante?->nom ?? 'Toutes sessions' }}
            · <strong>Édité le :</strong> {{ now()->locale('fr')->isoFormat('D MMMM YYYY [à] HH:mm') }}
            · <strong>Épreuves :</strong> {{ $couverture['total_epreuves'] }} (Couverture {{ $couverture['taux'] }}%)
        </p>
    </div>

    <!-- Synthèse & Indicateurs de Couverture / Équilibrage (§11-12) -->
    <div class="mt-6 grid grid-cols-1 lg:grid-cols-2 gap-4 no-print">
        <!-- Carte 1 : Couverture des surveillances -->
        <div class="ds-card p-5 bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 shadow-xs">
            <div class="flex items-center justify-between pb-3 border-b border-slate-100 dark:border-slate-700/60">
                <div class="flex items-center gap-2">
                    <span class="inline-flex items-center justify-center w-8 h-8 rounded-lg bg-indigo-50 dark:bg-indigo-950/60 text-indigo-600 dark:text-indigo-400 font-bold">
                        🛡
                    </span>
                    <div>
                        <h2 class="text-sm font-bold text-slate-900 dark:text-white">Couverture des surveillances</h2>
                        <p class="text-xs text-slate-500 dark:text-slate-400">Respect des quotas surveillants & réserves</p>
                    </div>
                </div>
                <div class="text-right">
                    <span class="text-2xl font-black tabular-nums {{ $couverture['taux'] >= 100 ? 'text-emerald-600 dark:text-emerald-400' : ($couverture['taux'] >= 75 ? 'text-indigo-600 dark:text-indigo-400' : 'text-amber-600 dark:text-amber-400') }}">
                        {{ $couverture['taux'] }}%
                    </span>
                    <span class="text-[10px] text-slate-400 block font-semibold">Taux global</span>
                </div>
            </div>

            <!-- Barre de progression -->
            <div class="mt-3">
                <div class="w-full bg-slate-100 dark:bg-slate-700 h-2.5 rounded-full overflow-hidden flex">
                    <div class="bg-emerald-500 h-full transition-all duration-300" style="width: {{ min(100, $couverture['taux']) }}%" title="Complet : {{ $couverture['completes'] }}"></div>
                    @if($couverture['partielles'] > 0 && $couverture['total_epreuves'] > 0)
                        <div class="bg-amber-400 h-full" style="width: {{ round($couverture['partielles'] / $couverture['total_epreuves'] * 100, 1) }}%" title="Partiel : {{ $couverture['partielles'] }}"></div>
                    @endif
                    @if($couverture['non_affectees'] > 0 && $couverture['total_epreuves'] > 0)
                        <div class="bg-slate-300 dark:bg-slate-600 h-full" style="width: {{ round($couverture['non_affectees'] / $couverture['total_epreuves'] * 100, 1) }}%" title="Non affecté : {{ $couverture['non_affectees'] }}"></div>
                    @endif
                </div>
            </div>

            <!-- Chiffres clés de couverture -->
            <div class="mt-4 grid grid-cols-2 sm:grid-cols-4 gap-2 text-center">
                <div class="p-2.5 bg-slate-50 dark:bg-slate-900/40 rounded-lg border border-slate-100 dark:border-slate-800">
                    <span class="text-xs text-slate-500 dark:text-slate-400 block font-medium">Examens couverts</span>
                    <span class="text-sm font-black text-emerald-600 dark:text-emerald-400 tabular-nums">
                        {{ $couverture['completes'] }} <span class="text-xs font-normal text-slate-400">/ {{ $couverture['total_epreuves'] }}</span>
                    </span>
                </div>
                <div class="p-2.5 bg-slate-50 dark:bg-slate-900/40 rounded-lg border border-slate-100 dark:border-slate-800">
                    <span class="text-xs text-slate-500 dark:text-slate-400 block font-medium">Surveillances</span>
                    <span class="text-sm font-black text-indigo-600 dark:text-indigo-400 tabular-nums">
                        {{ $couverture['total_gardes'] }}
                    </span>
                </div>
                <div class="p-2.5 bg-slate-50 dark:bg-slate-900/40 rounded-lg border border-slate-100 dark:border-slate-800">
                    <span class="text-xs text-slate-500 dark:text-slate-400 block font-medium">Réserves</span>
                    <span class="text-sm font-black text-purple-600 dark:text-purple-400 tabular-nums">
                        {{ $couverture['total_reserves'] }}
                    </span>
                </div>
                <div class="p-2.5 bg-slate-50 dark:bg-slate-900/40 rounded-lg border border-slate-100 dark:border-slate-800">
                    <span class="text-xs text-slate-500 dark:text-slate-400 block font-medium">Conflits</span>
                    <span class="text-sm font-black {{ $couverture['conflits'] > 0 ? 'text-rose-600 dark:text-rose-400' : 'text-slate-400' }} tabular-nums">
                        {{ $couverture['conflits'] }}
                    </span>
                </div>
            </div>
        </div>

        <!-- Carte 2 : Équilibrage des enseignants -->
        <div class="ds-card p-5 bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 shadow-xs">
            <div class="flex items-center justify-between pb-3 border-b border-slate-100 dark:border-slate-700/60">
                <div class="flex items-center gap-2">
                    <span class="inline-flex items-center justify-center w-8 h-8 rounded-lg bg-emerald-50 dark:bg-emerald-950/60 text-emerald-600 dark:text-emerald-400 font-bold">
                        ⚖
                    </span>
                    <div>
                        <h2 class="text-sm font-bold text-slate-900 dark:text-white">Équilibrage des enseignants</h2>
                        <p class="text-xs text-slate-500 dark:text-slate-400">Équité des charges et mobilisation</p>
                    </div>
                </div>
                <div class="text-right">
                    <span class="text-xs font-bold text-slate-600 dark:text-slate-300">
                        {{ $stats['enseignants_mobilises'] }} mobilisés
                    </span>
                    <span class="text-[10px] text-slate-400 block">Jours moyens : {{ $stats['jours_moyens'] }} j</span>
                </div>
            </div>

            <!-- Grille métriques équilibrage -->
            <div class="mt-4 grid grid-cols-2 sm:grid-cols-4 gap-2 text-center">
                <div class="p-2.5 bg-slate-50 dark:bg-slate-900/40 rounded-lg border border-slate-100 dark:border-slate-800">
                    <span class="text-xs text-slate-500 dark:text-slate-400 block font-medium">Charge min / max</span>
                    <span class="text-sm font-black text-slate-800 dark:text-slate-200 tabular-nums">
                        {{ $stats['charge_min'] }} / {{ $stats['charge_max'] }}
                    </span>
                </div>
                <div class="p-2.5 bg-slate-50 dark:bg-slate-900/40 rounded-lg border border-slate-100 dark:border-slate-800">
                    <span class="text-xs text-slate-500 dark:text-slate-400 block font-medium">Écart de charge</span>
                    <span class="text-sm font-black {{ $stats['ecart'] <= 2 ? 'text-emerald-600 dark:text-emerald-400' : 'text-amber-600 dark:text-amber-400' }} tabular-nums">
                        {{ $stats['ecart'] }}
                    </span>
                </div>
                <div class="p-2.5 bg-slate-50 dark:bg-slate-900/40 rounded-lg border border-slate-100 dark:border-slate-800">
                    <span class="text-xs text-slate-500 dark:text-slate-400 block font-medium">Charge moyenne</span>
                    <span class="text-sm font-black text-indigo-600 dark:text-indigo-400 tabular-nums">
                        {{ $stats['charge_moyenne'] }}
                    </span>
                </div>
                <div class="p-2.5 bg-slate-50 dark:bg-slate-900/40 rounded-lg border border-slate-100 dark:border-slate-800">
                    <span class="text-xs text-slate-500 dark:text-slate-400 block font-medium">> 3 jours mobilisés</span>
                    <span class="text-sm font-black {{ $stats['enseignants_disperses'] > 0 ? 'text-amber-600 dark:text-amber-400' : 'text-emerald-600 dark:text-emerald-400' }} tabular-nums">
                        {{ $stats['enseignants_disperses'] }}
                    </span>
                </div>
            </div>

            @if($stats['enseignants_disperses'] > 0)
                <p class="mt-2.5 text-[11px] text-amber-700 dark:text-amber-300 font-medium">
                    ⚠ {{ $stats['enseignants_disperses'] }} enseignant(s) mobilisé(s) sur plus de 3 jours (dispersion).
                </p>
            @endif
        </div>
    </div>

    <!-- Filtres multi-critères (§6) -->
    <div class="mt-6 ds-card p-5 bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 shadow-xs no-print">
        <form method="get" action="{{ route('admin.evaluations.surveillances.emploi') }}" class="space-y-4">
            <input type="hidden" name="vue" value="{{ $vue }}">

            <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-3 text-xs">
                <!-- Session -->
                <div>
                    <label class="block font-bold uppercase tracking-wider text-slate-600 dark:text-slate-300 mb-1">Session</label>
                    <select name="session_id" class="w-full rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-900 px-2.5 py-1.5 text-xs text-slate-800 dark:text-slate-200 focus:border-indigo-500">
                        <option value="">Toutes les sessions</option>
                        @foreach($sessions as $session)
                            <option value="{{ $session->id }}" @selected(($filtres['session_id'] ?? null) == $session->id)>
                                {{ $session->nom }}
                            </option>
                        @endforeach
                    </select>
                </div>

                <!-- Année universitaire -->
                <div>
                    <label class="block font-bold uppercase tracking-wider text-slate-600 dark:text-slate-300 mb-1">Année univ.</label>
                    <select name="annee_universitaire_id" class="w-full rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-900 px-2.5 py-1.5 text-xs text-slate-800 dark:text-slate-200 focus:border-indigo-500">
                        <option value="">Toutes les années</option>
                        @foreach($annees as $annee)
                            <option value="{{ $annee->id }}" @selected(($filtres['annee_universitaire_id'] ?? null) == $annee->id)>
                                {{ $annee->libelle }}
                            </option>
                        @endforeach
                    </select>
                </div>

                <!-- Semestre -->
                <div>
                    <label class="block font-bold uppercase tracking-wider text-slate-600 dark:text-slate-300 mb-1">Semestre</label>
                    <select name="semestre" class="w-full rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-900 px-2.5 py-1.5 text-xs text-slate-800 dark:text-slate-200 focus:border-indigo-500">
                        <option value="">Tous</option>
                        <option value="1" @selected(($filtres['semestre'] ?? null) == 1)>Semestre 1</option>
                        <option value="2" @selected(($filtres['semestre'] ?? null) == 2)>Semestre 2</option>
                    </select>
                </div>

                <!-- Niveau -->
                <div>
                    <label class="block font-bold uppercase tracking-wider text-slate-600 dark:text-slate-300 mb-1">Niveau</label>
                    <select name="niveau" class="w-full rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-900 px-2.5 py-1.5 text-xs text-slate-800 dark:text-slate-200 focus:border-indigo-500">
                        <option value="">Tous les niveaux</option>
                        @foreach(['L1', 'L2', 'L3', 'M1', 'M2', 'D1', 'D2'] as $niv)
                            <option value="{{ $niv }}" @selected(($filtres['niveau'] ?? null) === $niv)>{{ $niv }}</option>
                        @endforeach
                    </select>
                </div>

                <!-- Groupe -->
                <div>
                    <label class="block font-bold uppercase tracking-wider text-slate-600 dark:text-slate-300 mb-1">Groupe</label>
                    <select name="groupe_id" class="w-full rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-900 px-2.5 py-1.5 text-xs text-slate-800 dark:text-slate-200 focus:border-indigo-500">
                        <option value="">Tous les groupes</option>
                        @foreach($groupes as $g)
                            <option value="{{ $g->id }}" @selected(($filtres['groupe_id'] ?? null) == $g->id)>{{ $g->nom }} ({{ $g->code }})</option>
                        @endforeach
                    </select>
                </div>

                <!-- Matière / ECUE -->
                <div>
                    <label class="block font-bold uppercase tracking-wider text-slate-600 dark:text-slate-300 mb-1">Matière / ECUE</label>
                    <select name="matiere_id" class="w-full rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-900 px-2.5 py-1.5 text-xs text-slate-800 dark:text-slate-200 focus:border-indigo-500">
                        <option value="">Toutes les matières</option>
                        @foreach($matieres as $m)
                            <option value="{{ $m->id }}" @selected(($filtres['matiere_id'] ?? null) == $m->id)>{{ $m->code }} — {{ $m->nom }}</option>
                        @endforeach
                    </select>
                </div>

                <!-- Statut couverture / conflit -->
                <div>
                    <label class="block font-bold uppercase tracking-wider text-slate-600 dark:text-slate-300 mb-1">Statut</label>
                    <select name="statut" class="w-full rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-900 px-2.5 py-1.5 text-xs text-slate-800 dark:text-slate-200 focus:border-indigo-500">
                        <option value="">Tous les statuts</option>
                        <option value="complet" @selected(($filtres['statut'] ?? null) === 'complet')>✓ Complet</option>
                        <option value="partiel" @selected(($filtres['statut'] ?? null) === 'partiel')>⚠ Partiellement affecté</option>
                        <option value="non_affecte" @selected(($filtres['statut'] ?? null) === 'non_affecte')>✕ Non affecté</option>
                        <option value="conflit" @selected(($filtres['statut'] ?? null) === 'conflit')>⚠ Conflit</option>
                    </select>
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-4 gap-3 text-xs pt-1">
                <!-- Enseignant -->
                <div>
                    <label class="block font-bold uppercase tracking-wider text-slate-600 dark:text-slate-300 mb-1">Enseignant</label>
                    <select name="teacher_id" class="w-full rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-900 px-2.5 py-1.5 text-xs text-slate-800 dark:text-slate-200 focus:border-indigo-500">
                        <option value="">Tous les enseignants</option>
                        @foreach($enseignantsFiltre as $ens)
                            <option value="{{ $ens->id }}" @selected(($filtres['teacher_id'] ?? null) == $ens->id)>
                                {{ $ens->nomComplet() }}
                            </option>
                        @endforeach
                    </select>
                </div>

                <!-- Salle -->
                <div>
                    <label class="block font-bold uppercase tracking-wider text-slate-600 dark:text-slate-300 mb-1">Salle</label>
                    <select name="salle_id" class="w-full rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-900 px-2.5 py-1.5 text-xs text-slate-800 dark:text-slate-200 focus:border-indigo-500">
                        <option value="">Toutes les salles</option>
                        @foreach($salles as $salle)
                            <option value="{{ $salle->id }}" @selected(($filtres['salle_id'] ?? null) == $salle->id)>
                                {{ $salle->code }} (cap. {{ $salle->capacite ?? '—' }})
                            </option>
                        @endforeach
                    </select>
                </div>

                <!-- Date début -->
                <div>
                    <label class="block font-bold uppercase tracking-wider text-slate-600 dark:text-slate-300 mb-1">Date début</label>
                    <input type="date" name="date_debut" value="{{ $filtres['date_debut'] ?? '' }}" class="w-full rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-900 px-2.5 py-1.5 text-xs text-slate-800 dark:text-slate-200 focus:border-indigo-500">
                </div>

                <!-- Date fin -->
                <div>
                    <label class="block font-bold uppercase tracking-wider text-slate-600 dark:text-slate-300 mb-1">Date fin</label>
                    <input type="date" name="date_fin" value="{{ $filtres['date_fin'] ?? '' }}" class="w-full rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-900 px-2.5 py-1.5 text-xs text-slate-800 dark:text-slate-200 focus:border-indigo-500">
                </div>
            </div>

            <!-- Boutons filtrer & reset -->
            <div class="flex items-center justify-between pt-3 border-t border-slate-100 dark:border-slate-700/60">
                <span class="text-xs text-slate-500 dark:text-slate-400">
                    <strong>{{ $jours->flatten(1)->count() }}</strong> épreuve(s) affichée(s) sur <strong>{{ $jours->count() }}</strong> jour(s)
                </span>
                <div class="flex items-center gap-2">
                    <a href="{{ route('admin.evaluations.surveillances.emploi') }}"
                       class="rounded-lg px-3 py-1.5 text-xs font-semibold text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white transition">
                        Réinitialiser les filtres
                    </a>
                    <x-btn type="submit">
                        Filtrer
                    </x-btn>
                </div>
            </div>
        </form>
    </div>

    <!-- Légende des couleurs (§8) -->
    <div class="mt-4 flex flex-wrap items-center gap-3 text-xs text-slate-600 dark:text-slate-300 no-print">
        <span class="font-bold text-slate-400 uppercase text-[10px]">Légende :</span>
        <span class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded-md bg-emerald-50 text-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-300 border border-emerald-200 dark:border-emerald-800/60 font-semibold">
            <span class="w-2 h-2 rounded-full bg-emerald-500"></span> Affectation complète
        </span>
        <span class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded-md bg-amber-50 text-amber-800 dark:bg-amber-950/40 dark:text-amber-300 border border-amber-200 dark:border-amber-800/60 font-semibold">
            <span class="w-2 h-2 rounded-full bg-amber-500"></span> Partiellement affectée
        </span>
        <span class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded-md bg-rose-50 text-rose-800 dark:bg-rose-950/40 dark:text-rose-300 border border-rose-200 dark:border-rose-800/60 font-semibold">
            <span class="w-2 h-2 rounded-full bg-rose-500"></span> Conflit détecté
        </span>
        <span class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded-md bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300 border border-slate-200 dark:border-slate-700 font-semibold">
            <span class="w-2 h-2 rounded-full bg-slate-400"></span> Non affectée
        </span>
    </div>

    <!-- Sélecteur d'onglets (Modes d'affichage §5) -->
    <div class="mt-6 border-b border-slate-200 dark:border-slate-700 no-print">
        <nav class="flex gap-4">
            <a href="{{ route('admin.evaluations.surveillances.emploi', array_merge(request()->except('vue'), ['vue' => 'global'])) }}"
               class="pb-3 text-sm font-bold border-b-2 transition flex items-center gap-2 {{ $vue === 'global' ? 'border-indigo-600 text-indigo-600 dark:text-indigo-400 dark:border-indigo-400' : 'border-transparent text-slate-500 hover:text-slate-700 dark:text-slate-400 dark:hover:text-slate-200' }}">
                <span>📅 Vue globale (Jour × Créneau)</span>
            </a>
            <a href="{{ route('admin.evaluations.surveillances.emploi', array_merge(request()->except('vue'), ['vue' => 'enseignant'])) }}"
               class="pb-3 text-sm font-bold border-b-2 transition flex items-center gap-2 {{ $vue === 'enseignant' ? 'border-indigo-600 text-indigo-600 dark:text-indigo-400 dark:border-indigo-400' : 'border-transparent text-slate-500 hover:text-slate-700 dark:text-slate-400 dark:hover:text-slate-200' }}">
                <span>👨‍🏫 Vue par enseignant</span>
            </a>
            <a href="{{ route('admin.evaluations.surveillances.emploi', array_merge(request()->except('vue'), ['vue' => 'examen'])) }}"
               class="pb-3 text-sm font-bold border-b-2 transition flex items-center gap-2 {{ $vue === 'examen' ? 'border-indigo-600 text-indigo-600 dark:text-indigo-400 dark:border-indigo-400' : 'border-transparent text-slate-500 hover:text-slate-700 dark:text-slate-400 dark:hover:text-slate-200' }}">
                <span>📝 Vue par examen</span>
            </a>
        </nav>
    </div>

    <!-- ================================================================= -->
    <!-- MODE A : VUE GLOBALE (JOUR × CRÉNEAU)                             -->
    <!-- ================================================================= -->
    @if($vue === 'global')
        <div class="mt-6 space-y-6">
            @forelse($jours as $dateIso => $epreuvesDuJour)
                <div class="ds-card overflow-hidden bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 shadow-xs print-card">
                    <!-- En-tête de la journée -->
                    <div class="bg-slate-50 dark:bg-slate-900/60 px-5 py-3.5 border-b border-slate-200 dark:border-slate-700 flex items-center justify-between">
                        <div class="flex items-center gap-3">
                            <span class="inline-flex items-center justify-center w-9 h-9 rounded-xl bg-indigo-600 text-white font-black text-sm shadow-xs">
                                {{ \Carbon\Carbon::parse($dateIso)->format('d') }}
                            </span>
                            <div>
                                <h3 class="text-sm font-bold text-slate-900 dark:text-white capitalize">
                                    {{ \Carbon\Carbon::parse($dateIso)->locale('fr')->isoFormat('dddd D MMMM YYYY') }}
                                </h3>
                                <p class="text-[11px] text-slate-500 dark:text-slate-400">
                                    {{ count($epreuvesDuJour) }} épreuve(s) programmée(s)
                                </p>
                            </div>
                        </div>
                    </div>

                    <!-- Grille des créneaux / examens du jour -->
                    <div class="divide-y divide-slate-100 dark:divide-slate-700/60">
                        @foreach($epreuvesDuJour as $item)
                            @php
                                $epreuve = $item['epreuve'];
                                $statut = $item['statut'];
                                $classeBordure = match($statut) {
                                    'complet' => 'border-l-4 border-l-emerald-500 bg-emerald-50/20 dark:bg-emerald-950/10',
                                    'partiel' => 'border-l-4 border-l-amber-500 bg-amber-50/20 dark:bg-amber-950/10',
                                    'conflit' => 'border-l-4 border-l-rose-500 bg-rose-50/30 dark:bg-rose-950/20',
                                    default => 'border-l-4 border-l-slate-300 dark:border-l-slate-600 bg-slate-50/30 dark:bg-slate-900/20',
                                };
                            @endphp
                            <div class="p-5 {{ $classeBordure }} hover:bg-slate-50/60 dark:hover:bg-slate-700/30 transition-colors">
                                <div class="flex flex-col lg:flex-row lg:items-start justify-between gap-4">
                                    <!-- Horaires et métadonnées -->
                                    <div class="flex items-start gap-4">
                                        <!-- Bloc horaire -->
                                        <div class="text-center min-w-[85px] pt-0.5">
                                            <span class="text-xs font-bold text-indigo-700 dark:text-indigo-300 bg-indigo-50 dark:bg-indigo-950/60 px-2.5 py-1 rounded-md tabular-nums block shadow-2xs">
                                                {{ $item['heure_debut'] }}
                                            </span>
                                            <span class="text-[10px] text-slate-500 dark:text-slate-400 tabular-nums block mt-1">
                                                à {{ $item['heure_fin'] }}
                                            </span>
                                        </div>

                                        <!-- Détails examen -->
                                        <div>
                                            <div class="flex flex-wrap items-center gap-2">
                                                <span class="font-black text-slate-900 dark:text-white text-sm">
                                                    {{ $epreuve->code }}
                                                </span>
                                                <span class="text-slate-400">·</span>
                                                <span class="text-sm text-slate-800 dark:text-slate-200 font-bold">
                                                    {{ $epreuve->matiere?->nom ?? 'Épreuve' }}
                                                </span>

                                                <!-- Statut badge -->
                                                @if($statut === 'complet')
                                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-[11px] font-bold bg-emerald-100 text-emerald-800 dark:bg-emerald-900/60 dark:text-emerald-200">
                                                        ✓ Complet
                                                    </span>
                                                @elseif($statut === 'partiel')
                                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-[11px] font-bold bg-amber-100 text-amber-800 dark:bg-amber-900/60 dark:text-amber-200">
                                                        ⚠ Partiel
                                                    </span>
                                                @elseif($statut === 'conflit')
                                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-[11px] font-bold bg-rose-100 text-rose-800 dark:bg-rose-900/60 dark:text-rose-200">
                                                        ⚠ Conflit
                                                    </span>
                                                @else
                                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-[11px] font-bold bg-slate-200 text-slate-700 dark:bg-slate-700 dark:text-slate-300">
                                                        ✕ Non affecté
                                                    </span>
                                                @endif
                                            </div>

                                            <div class="mt-1.5 flex flex-wrap items-center gap-3 text-xs text-slate-600 dark:text-slate-400">
                                                <span class="inline-flex items-center gap-1 font-medium">
                                                    <span>Niveau :</span> <strong class="text-slate-800 dark:text-slate-200">{{ $epreuve->groupe?->niveau ?? ($epreuve->matiere?->niveau ?? '—') }}</strong>
                                                </span>
                                                <span class="text-slate-300 dark:text-slate-600">·</span>
                                                <span class="inline-flex items-center gap-1 font-medium">
                                                    <span>Groupe :</span> <strong class="text-slate-800 dark:text-slate-200">{{ $epreuve->groupe?->nom ?? ($epreuve->groupe?->code ?? 'Tous') }}</strong>
                                                </span>
                                                <span class="text-slate-300 dark:text-slate-600">·</span>
                                                <span class="inline-flex items-center gap-1 font-medium">
                                                    <span>Salle :</span>
                                                    <strong class="text-slate-800 dark:text-slate-200">{{ $epreuve->salle?->code ?? 'Non assignée' }}</strong>
                                                    @if($epreuve->salle?->capacite)
                                                        <span class="text-slate-400">({{ $epreuve->salle->capacite }} places)</span>
                                                    @endif
                                                </span>
                                                <span class="text-slate-300 dark:text-slate-600">·</span>
                                                <span class="inline-flex items-center gap-1 font-medium">
                                                    <span>Effectif :</span>
                                                    <strong class="text-slate-800 dark:text-slate-200">{{ $item['nb_etudiants'] }} étudiants</strong>
                                                </span>
                                            </div>

                                            <!-- Alertes Conflits éventuels (§9) -->
                                            @if($item['conflits'] !== [])
                                                <div class="mt-2.5 space-y-1">
                                                    @foreach($item['conflits'] as $c)
                                                        <div class="inline-flex items-center gap-1.5 text-xs text-rose-700 dark:text-rose-300 bg-rose-50 dark:bg-rose-950/60 px-2.5 py-1 rounded-md border border-rose-200 dark:border-rose-900/60 font-semibold">
                                                            <svg class="w-3.5 h-3.5 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" /></svg>
                                                            <span>⚠ {{ $c['message'] }}</span>
                                                        </div>
                                                    @endforeach
                                                </div>
                                            @endif
                                        </div>
                                    </div>

                                    <!-- Surveillants affectés (§7) & Actions -->
                                    <div class="flex flex-col sm:flex-row lg:flex-col xl:flex-row items-start sm:items-center lg:items-end xl:items-center gap-4">
                                        <!-- Liste des surveillants avec leurs rôles réels -->
                                        <div class="flex flex-wrap items-center gap-1.5">
                                            @forelse($item['actifs'] as $surv)
                                                @php
                                                    $estPrincipal = $surv->role === 'principal';
                                                    $estReserve = $surv->role === 'reserve';
                                                @endphp
                                                <div class="inline-flex items-center gap-1 px-2.5 py-1 rounded-lg text-xs font-semibold
                                                    {{ $estPrincipal ? 'bg-indigo-50 text-indigo-900 border border-indigo-200 dark:bg-indigo-950/50 dark:text-indigo-200 dark:border-indigo-800' :
                                                       ($estReserve ? 'bg-purple-50 text-purple-900 border border-purple-200 dark:bg-purple-950/50 dark:text-purple-200 dark:border-purple-800' :
                                                       'bg-slate-100 text-slate-800 border border-slate-200 dark:bg-slate-800 dark:text-slate-200 dark:border-slate-700') }}">
                                                    <span>{{ $estPrincipal ? '👨‍🏫' : ($estReserve ? '🛡' : '👤') }}</span>
                                                    <span class="font-bold">{{ $surv->enseignant?->nomComplet() ?? 'Enseignant' }}</span>
                                                    <span class="text-[10px] uppercase tracking-wider px-1 py-0.2 rounded
                                                        {{ $estPrincipal ? 'bg-indigo-200/70 text-indigo-900 dark:bg-indigo-900 dark:text-indigo-200' :
                                                           ($estReserve ? 'bg-purple-200/70 text-purple-900 dark:bg-purple-900 dark:text-purple-200' :
                                                           'bg-slate-200 text-slate-700 dark:bg-slate-700 dark:text-slate-300') }}">
                                                        {{ $surv->libelleRole() }}
                                                    </span>
                                                </div>
                                            @empty
                                                <span class="text-xs text-slate-400 italic">Aucun surveillant affecté</span>
                                            @endforelse
                                        </div>

                                        <!-- Boutons d'action dans le bloc (§17) -->
                                        <div class="flex items-center gap-2 no-print shrink-0">
                                            <a href="{{ route('admin.evaluations.surveillances.emploi', array_merge(request()->query(), ['vue' => 'examen', 'epreuve_id' => $epreuve->id])) }}"
                                               class="inline-flex items-center gap-1 rounded-lg px-2.5 py-1.5 text-xs font-bold bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 text-slate-700 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-700 shadow-2xs">
                                                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178Z" /><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" /></svg>
                                                <span>👁 Voir</span>
                                            </a>
                                            <a href="{{ route('admin.evaluations.affectations.index', ['session_id' => $epreuve->session_id]) }}"
                                               class="inline-flex items-center gap-1 rounded-lg px-2.5 py-1.5 text-xs font-bold bg-indigo-50 dark:bg-indigo-950/60 border border-indigo-200 dark:border-indigo-800 text-indigo-700 dark:text-indigo-300 hover:bg-indigo-100 dark:hover:bg-indigo-900/60 shadow-2xs">
                                                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931Zm0 0L19.5 7.125M18 14v4.75A2.25 2.25 0 0 1 15.75 21H5.25A2.25 2.25 0 0 1 3 18.75V8.25A2.25 2.25 0 0 1 5.25 6H10" /></svg>
                                                <span>✏ Affectation</span>
                                            </a>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            @empty
                <div class="ds-card p-12 bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 text-center">
                    <span class="text-4xl block mb-2">📅</span>
                    <h3 class="text-sm font-bold text-slate-800 dark:text-slate-200">Aucune épreuve trouvée</h3>
                    <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">Aucune épreuve ne correspond aux filtres appliqués.</p>
                </div>
            @endforelse
        </div>
    @endif

    <!-- ================================================================= -->
    <!-- MODE B : VUE ENSEIGNANT (§5.B & §10)                              -->
    <!-- ================================================================= -->
    @if($vue === 'enseignant')
        <div class="mt-6 space-y-6">
            <!-- Sélecteur rapide d'enseignant -->
            <div class="ds-card p-4 bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 shadow-xs no-print">
                <form method="get" action="{{ route('admin.evaluations.surveillances.emploi') }}" class="flex flex-wrap items-center gap-3">
                    <input type="hidden" name="vue" value="enseignant">
                    @foreach(request()->except(['vue', 'teacher_id']) as $k => $v)
                        <input type="hidden" name="{{ $k }}" value="{{ $v }}">
                    @endforeach

                    <label class="text-xs font-bold text-slate-700 dark:text-slate-300">Enseignant :</label>
                    <select name="teacher_id" onchange="this.form.submit()" class="rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-900 px-3 py-1.5 text-xs text-slate-800 dark:text-slate-200 focus:border-indigo-500">
                        <option value="">-- Sélectionner un enseignant pour afficher son emploi --</option>
                        @foreach($enseignantsFiltre as $ens)
                            <option value="{{ $ens->id }}" @selected(request('teacher_id') == $ens->id)>
                                {{ $ens->nomComplet() }}
                            </option>
                        @endforeach
                    </select>
                </form>
            </div>

            @if($detailEnseignant)
                <!-- Carte statistiques de l'enseignant sélectionné -->
                <div class="ds-card p-5 bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 shadow-xs">
                    <div class="flex flex-col sm:flex-row sm:items-center justify-between pb-4 border-b border-slate-100 dark:border-slate-700 gap-3">
                        <div class="flex items-center gap-3">
                            <span class="inline-flex items-center justify-center w-10 h-10 rounded-xl bg-indigo-600 text-white font-bold text-base shadow-xs">
                                👨‍🏫
                            </span>
                            <div>
                                <h3 class="text-base font-bold text-slate-900 dark:text-white">
                                    {{ $detailEnseignant['nom'] }}
                                </h3>
                                <p class="text-xs text-slate-500 dark:text-slate-400">
                                    Statut : <span class="font-semibold {{ $detailEnseignant['actif'] ? 'text-emerald-600' : 'text-rose-600' }}">{{ $detailEnseignant['actif'] ? 'Actif' : 'Inactif' }}</span>
                                </p>
                            </div>
                        </div>

                        <!-- Badge règle des 3 jours -->
                        <div>
                            @if($detailEnseignant['dispersion'])
                                <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-lg text-xs font-bold bg-amber-50 text-amber-900 border border-amber-300 dark:bg-amber-950/60 dark:text-amber-200 dark:border-amber-800">
                                    ⚠ Dispersion : {{ $detailEnseignant['jours'] }} jours (> 3 jours)
                                </span>
                            @else
                                <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-lg text-xs font-bold bg-emerald-50 text-emerald-900 border border-emerald-300 dark:bg-emerald-950/60 dark:text-emerald-200 dark:border-emerald-800">
                                    ✓ Jours mobilisés : {{ $detailEnseignant['jours'] }} / 3
                                </span>
                            @endif
                        </div>
                    </div>

                    <!-- Métriques de l'enseignant -->
                    <div class="mt-4 grid grid-cols-2 sm:grid-cols-4 gap-3 text-center">
                        <div class="p-3 bg-slate-50 dark:bg-slate-900/40 rounded-lg border border-slate-100 dark:border-slate-800">
                            <span class="text-xs text-slate-500 dark:text-slate-400 block font-medium">Nombre de surveillances</span>
                            <span class="text-base font-black text-indigo-600 dark:text-indigo-400 tabular-nums">{{ $detailEnseignant['gardes'] }}</span>
                        </div>
                        <div class="p-3 bg-slate-50 dark:bg-slate-900/40 rounded-lg border border-slate-100 dark:border-slate-800">
                            <span class="text-xs text-slate-500 dark:text-slate-400 block font-medium">Nombre de jours</span>
                            <span class="text-base font-black text-slate-800 dark:text-slate-200 tabular-nums">{{ $detailEnseignant['jours'] }}</span>
                        </div>
                        <div class="p-3 bg-slate-50 dark:bg-slate-900/40 rounded-lg border border-slate-100 dark:border-slate-800">
                            <span class="text-xs text-slate-500 dark:text-slate-400 block font-medium">Gardes réserve</span>
                            <span class="text-base font-black text-purple-600 dark:text-purple-400 tabular-nums">{{ $detailEnseignant['reserves'] }}</span>
                        </div>
                        <div class="p-3 bg-slate-50 dark:bg-slate-900/40 rounded-lg border border-slate-100 dark:border-slate-800">
                            <span class="text-xs text-slate-500 dark:text-slate-400 block font-medium">Charge pondérée</span>
                            <span class="text-base font-black text-emerald-600 dark:text-emerald-400 tabular-nums">{{ $detailEnseignant['charge'] }}</span>
                        </div>
                    </div>
                </div>

                <!-- Emploi du temps individuel -->
                <div class="ds-card overflow-hidden bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 shadow-xs">
                    <div class="p-4 border-b border-slate-100 dark:border-slate-700 flex items-center justify-between">
                        <h4 class="text-sm font-bold text-slate-900 dark:text-white">Créneaux de surveillance assignés</h4>
                        <span class="text-xs text-slate-500 dark:text-slate-400">{{ $jours->flatten(1)->count() }} examen(s)</span>
                    </div>

                    <div class="divide-y divide-slate-100 dark:divide-slate-700/60">
                        @forelse($jours as $dateIso => $epreuvesDuJour)
                            <div class="p-4 bg-slate-50/50 dark:bg-slate-900/30">
                                <h5 class="text-xs font-bold text-slate-800 dark:text-slate-200 capitalize mb-2">
                                    {{ \Carbon\Carbon::parse($dateIso)->locale('fr')->isoFormat('dddd D MMMM YYYY') }}
                                </h5>
                                <div class="space-y-2">
                                    @foreach($epreuvesDuJour as $item)
                                        @php
                                            $epreuve = $item['epreuve'];
                                            $monSurv = $item['actifs']->firstWhere('teacher_id', $detailEnseignant['teacher_id']);
                                        @endphp
                                        <div class="p-3 bg-white dark:bg-slate-800 rounded-lg border border-slate-200 dark:border-slate-700 flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                                            <div class="flex items-center gap-3">
                                                <span class="text-xs font-bold text-indigo-700 dark:text-indigo-300 bg-indigo-50 dark:bg-indigo-950/60 px-2 py-1 rounded tabular-nums">
                                                    {{ $item['heure_debut'] }} - {{ $item['heure_fin'] }}
                                                </span>
                                                <div>
                                                    <span class="font-bold text-slate-900 dark:text-white text-xs">
                                                        {{ $epreuve->code }} — {{ $epreuve->matiere?->nom }}
                                                    </span>
                                                    <p class="text-[11px] text-slate-500">
                                                        Salle : <strong class="text-slate-700 dark:text-slate-300">{{ $epreuve->salle?->code ?? '—' }}</strong>
                                                        · Niveau : {{ $epreuve->groupe?->niveau ?? ($epreuve->matiere?->niveau ?? '—') }}
                                                    </p>
                                                </div>
                                            </div>

                                            <div class="flex items-center gap-2">
                                                @if($monSurv)
                                                    <span class="inline-flex items-center px-2.5 py-1 rounded-md text-xs font-bold
                                                        {{ $monSurv->role === 'principal' ? 'bg-indigo-100 text-indigo-800 dark:bg-indigo-900/60 dark:text-indigo-200' :
                                                           ($monSurv->role === 'reserve' ? 'bg-purple-100 text-purple-800 dark:bg-purple-900/60 dark:text-purple-200' :
                                                           'bg-slate-100 text-slate-800 dark:bg-slate-700 dark:text-slate-200') }}">
                                                        Rôle : {{ $monSurv->libelleRole() }}
                                                    </span>
                                                @endif
                                                <a href="{{ route('admin.evaluations.surveillances.emploi', array_merge(request()->query(), ['vue' => 'examen', 'epreuve_id' => $epreuve->id])) }}"
                                                   class="text-xs font-bold text-indigo-600 hover:text-indigo-800 px-2 py-1 rounded">
                                                    👁 Détails
                                                </a>
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        @empty
                            <div class="p-8 text-center text-xs text-slate-400">
                                Aucune surveillance assignée à cet enseignant pour les filtres sélectionnés.
                            </div>
                        @endforelse
                    </div>
                </div>
            @else
                <!-- Tableau de tous les enseignants si aucun n'est ciblé -->
                <div class="ds-card overflow-hidden bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 shadow-xs">
                    <div class="p-4 border-b border-slate-100 dark:border-slate-700 flex items-center justify-between">
                        <div>
                            <h3 class="font-bold text-slate-900 dark:text-white text-sm">Tableau de synthèse des enseignants</h3>
                            <p class="text-xs text-slate-500">Cliquez sur un enseignant pour afficher son emploi du temps détaillé.</p>
                        </div>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="w-full text-xs text-left">
                            <thead class="bg-slate-50 dark:bg-slate-900/60 uppercase font-bold text-slate-500 border-b border-slate-200 dark:border-slate-700">
                                <tr>
                                    <th class="px-4 py-3">Enseignant</th>
                                    <th class="px-4 py-3 text-center">Statut</th>
                                    <th class="px-4 py-3 text-center">Surveillances</th>
                                    <th class="px-4 py-3 text-center">Réserves</th>
                                    <th class="px-4 py-3 text-center">Jours mobilisés</th>
                                    <th class="px-4 py-3 text-center">Charge pondérée</th>
                                    <th class="px-4 py-3 text-center">Règle 3 jours</th>
                                    <th class="px-4 py-3 text-right">Action</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100 dark:divide-slate-700/60">
                                @forelse($enseignants as $ens)
                                    <tr class="hover:bg-slate-50/50 dark:hover:bg-slate-700/30">
                                        <td class="px-4 py-3 font-bold text-slate-900 dark:text-white">
                                            {{ $ens['nom'] }}
                                        </td>
                                        <td class="px-4 py-3 text-center">
                                            <span class="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-bold {{ $ens['actif'] ? 'bg-emerald-50 text-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-300' : 'bg-rose-50 text-rose-800' }}">
                                                {{ $ens['actif'] ? 'Actif' : 'Inactif' }}
                                            </span>
                                        </td>
                                        <td class="px-4 py-3 text-center font-bold text-indigo-600 dark:text-indigo-400 tabular-nums">
                                            {{ $ens['gardes'] }}
                                        </td>
                                        <td class="px-4 py-3 text-center font-semibold text-purple-600 dark:text-purple-400 tabular-nums">
                                            {{ $ens['reserves'] }}
                                        </td>
                                        <td class="px-4 py-3 text-center tabular-nums">
                                            {{ $ens['jours'] }} j
                                        </td>
                                        <td class="px-4 py-3 text-center font-bold text-slate-800 dark:text-slate-200 tabular-nums">
                                            {{ $ens['charge'] }}
                                        </td>
                                        <td class="px-4 py-3 text-center">
                                            @if($ens['dispersion'])
                                                <span class="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-bold bg-amber-100 text-amber-800 dark:bg-amber-900/60 dark:text-amber-200">
                                                    ⚠ {{ $ens['jours'] }} j (>3j)
                                                </span>
                                            @else
                                                <span class="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-bold bg-emerald-100 text-emerald-800 dark:bg-emerald-900/60 dark:text-emerald-200">
                                                    ✓ {{ $ens['jours'] }}/3 j
                                                </span>
                                            @endif
                                        </td>
                                        <td class="px-4 py-3 text-right">
                                            <a href="{{ route('admin.evaluations.surveillances.emploi', array_merge(request()->query(), ['vue' => 'enseignant', 'teacher_id' => $ens['teacher_id']])) }}"
                                               class="inline-flex items-center gap-1 rounded-lg px-2.5 py-1 text-xs font-bold bg-indigo-50 text-indigo-700 dark:bg-indigo-950/60 dark:text-indigo-300 hover:bg-indigo-100">
                                                Voir emploi
                                            </a>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="8" class="px-4 py-8 text-center text-slate-400 italic">
                                            Aucun enseignant mobilisé sur le périmètre actuel.
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            @endif
        </div>
    @endif

    <!-- ================================================================= -->
    <!-- MODE C : VUE EXAMEN (§5.C)                                        -->
    <!-- ================================================================= -->
    @if($vue === 'examen')
        <div class="mt-6 space-y-6">
            @if($detailExamen)
                @php
                    $epreuve = $detailExamen['epreuve'];
                @endphp
                <!-- Fiche d'inspection détaillée de l'examen -->
                <div class="ds-card p-6 bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 shadow-xs">
                    <div class="flex flex-col sm:flex-row sm:items-center justify-between pb-4 border-b border-slate-100 dark:border-slate-700 gap-3">
                        <div>
                            <span class="text-xs font-bold text-indigo-600 dark:text-indigo-400 uppercase tracking-wider block">
                                Inspection d'épreuve
                            </span>
                            <h3 class="text-lg font-black text-slate-900 dark:text-white mt-0.5">
                                {{ $epreuve->matiere?->code }} — {{ $epreuve->matiere?->nom }}
                            </h3>
                            <p class="text-xs text-slate-500">
                                Session : {{ $epreuve->session?->nom ?? '—' }} · Code épreuve : {{ $epreuve->code }}
                            </p>
                        </div>
                        <div class="flex items-center gap-2">
                            <a href="{{ route('admin.evaluations.epreuves.show', $epreuve) }}"
                               class="inline-flex items-center gap-1.5 rounded-xl px-3.5 py-2 text-xs font-bold bg-white border border-slate-300 hover:bg-slate-50 text-slate-700 shadow-xs">
                                👁 Voir l'épreuve
                            </a>
                            <a href="{{ route('admin.evaluations.affectations.index', ['session_id' => $epreuve->session_id]) }}"
                               class="inline-flex items-center gap-1.5 rounded-xl px-3.5 py-2 text-xs font-bold bg-indigo-600 hover:bg-indigo-700 text-white shadow-xs">
                                ✏ Modifier les affectations
                            </a>
                        </div>
                    </div>

                    <!-- Métadonnées principales -->
                    <div class="mt-5 grid grid-cols-1 sm:grid-cols-2 md:grid-cols-4 gap-4 text-xs">
                        <div class="p-3 bg-slate-50 dark:bg-slate-900/40 rounded-lg">
                            <span class="text-slate-400 block font-medium">Date & Horaire</span>
                            <span class="text-sm font-bold text-slate-900 dark:text-white mt-1 block">
                                {{ \Carbon\Carbon::parse($detailExamen['date_iso'])->locale('fr')->isoFormat('dddd D MMMM YYYY') }}
                            </span>
                            <span class="text-xs font-semibold text-indigo-600 dark:text-indigo-400">
                                {{ $detailExamen['heure_debut'] }} – {{ $detailExamen['heure_fin'] }}
                            </span>
                        </div>

                        <div class="p-3 bg-slate-50 dark:bg-slate-900/40 rounded-lg">
                            <span class="text-slate-400 block font-medium">Niveau & Groupe</span>
                            <span class="text-sm font-bold text-slate-900 dark:text-white mt-1 block">
                                {{ $epreuve->groupe?->niveau ?? ($epreuve->matiere?->niveau ?? '—') }}
                            </span>
                            <span class="text-xs text-slate-600 dark:text-slate-300">
                                {{ $epreuve->groupe?->nom ?? ($epreuve->groupe?->code ?? 'Tous les groupes') }}
                            </span>
                        </div>

                        <div class="p-3 bg-slate-50 dark:bg-slate-900/40 rounded-lg">
                            <span class="text-slate-400 block font-medium">Salle & Capacité</span>
                            <span class="text-sm font-bold text-slate-900 dark:text-white mt-1 block">
                                {{ $epreuve->salle?->code ?? 'Non définie' }}
                            </span>
                            <span class="text-xs text-slate-600 dark:text-slate-300">
                                Capacité : {{ $epreuve->salle?->capacite ?? '—' }} places
                            </span>
                        </div>

                        <div class="p-3 bg-slate-50 dark:bg-slate-900/40 rounded-lg">
                            <span class="text-slate-400 block font-medium">Candidats / Effectif</span>
                            <span class="text-sm font-bold text-slate-900 dark:text-white mt-1 block">
                                {{ $detailExamen['nb_etudiants'] }} étudiants
                            </span>
                            <span class="text-xs font-semibold {{ $detailExamen['statut'] === 'complet' ? 'text-emerald-600' : 'text-amber-600' }}">
                                Statut : {{ ucfirst($detailExamen['statut']) }}
                            </span>
                        </div>
                    </div>

                    <!-- Besoins calculés vs Couverture -->
                    <div class="mt-5 p-4 bg-slate-50 dark:bg-slate-900/40 rounded-xl border border-slate-200 dark:border-slate-700">
                        <h4 class="text-xs font-bold text-slate-800 dark:text-slate-200 uppercase tracking-wider mb-2">Besoins réglementaires</h4>
                        <div class="flex flex-wrap items-center gap-4 text-xs">
                            <span>Surveillants requis : <strong>{{ $detailExamen['besoins']['besoin_surveillants_actifs'] ?? 2 }}</strong> (Affectés : <strong>{{ $detailExamen['actifs']->whereIn('role', ['principal', 'adjoint', 'surveillant'])->count() }}</strong>)</span>
                            <span>Réserves requises : <strong>{{ $detailExamen['besoins']['besoin_gardes'] ?? 1 }}</strong> (Affectées : <strong>{{ $detailExamen['gardes']->count() }}</strong>)</span>
                            <span>Principal assigné : <strong>{{ $detailExamen['principaux']->isNotEmpty() ? '✓ Oui' : '✕ Non' }}</strong></span>
                        </div>
                    </div>

                    <!-- Liste détaillée des surveillants -->
                    <div class="mt-5">
                        <h4 class="text-xs font-bold text-slate-800 dark:text-slate-200 uppercase tracking-wider mb-3">Surveillants affectés</h4>
                        <div class="overflow-x-auto">
                            <table class="w-full text-xs text-left">
                                <thead class="bg-slate-50 dark:bg-slate-900/60 uppercase font-bold text-slate-500 border-b border-slate-200 dark:border-slate-700">
                                    <tr>
                                        <th class="px-4 py-2.5">Surveillant</th>
                                        <th class="px-4 py-2.5">Rôle</th>
                                        <th class="px-4 py-2.5">Statut</th>
                                        <th class="px-4 py-2.5">Salle</th>
                                        <th class="px-4 py-2.5">Présence</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100 dark:divide-slate-700/60">
                                    @forelse($detailExamen['actifs'] as $surv)
                                        <tr>
                                            <td class="px-4 py-2.5 font-bold text-slate-900 dark:text-white">
                                                {{ $surv->enseignant?->nomComplet() ?? 'Enseignant' }}
                                            </td>
                                            <td class="px-4 py-2.5">
                                                <span class="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-bold
                                                    {{ $surv->role === 'principal' ? 'bg-indigo-100 text-indigo-800 dark:bg-indigo-900/60 dark:text-indigo-200' :
                                                       ($surv->role === 'reserve' ? 'bg-purple-100 text-purple-800 dark:bg-purple-900/60 dark:text-purple-200' :
                                                       'bg-slate-100 text-slate-800 dark:bg-slate-700 dark:text-slate-200') }}">
                                                    {{ $surv->libelleRole() }}
                                                </span>
                                            </td>
                                            <td class="px-4 py-2.5 text-slate-600 dark:text-slate-300">
                                                {{ ucfirst($surv->statut) }}
                                            </td>
                                            <td class="px-4 py-2.5 text-slate-600 dark:text-slate-300">
                                                {{ $surv->salle?->code ?? ($epreuve->salle?->code ?? '—') }}
                                            </td>
                                            <td class="px-4 py-2.5 text-slate-600 dark:text-slate-300">
                                                {{ $surv->presence ?? 'non_emarge' }}
                                            </td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="5" class="px-4 py-4 text-center text-slate-400 italic">
                                                Aucun surveillant affecté à cette épreuve.
                                            </td>
                                        </tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Conflits éventuels -->
                    @if($detailExamen['conflits'] !== [])
                        <div class="mt-5 p-4 bg-rose-50 dark:bg-rose-950/40 rounded-xl border border-rose-200 dark:border-rose-900/60">
                            <h4 class="text-xs font-bold text-rose-800 dark:text-rose-200 uppercase tracking-wider mb-2">Conflits détectés</h4>
                            <ul class="space-y-1.5 text-xs text-rose-700 dark:text-rose-300 font-medium">
                                @foreach($detailExamen['conflits'] as $c)
                                    <li class="flex items-center gap-1.5">
                                        <svg class="w-4 h-4 shrink-0 text-rose-600" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" /></svg>
                                        <span>{{ $c['message'] }}</span>
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endif
                </div>
            @else
                <!-- Liste des examens pour en sélectionner un -->
                <div class="ds-card overflow-hidden bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 shadow-xs">
                    <div class="p-4 border-b border-slate-100 dark:border-slate-700">
                        <h3 class="font-bold text-slate-900 dark:text-white text-sm">Sélectionner un examen à inspecter</h3>
                        <p class="text-xs text-slate-500">Cliquez sur une épreuve pour afficher sa fiche complète et la couverture des surveillances.</p>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="w-full text-xs text-left">
                            <thead class="bg-slate-50 dark:bg-slate-900/60 uppercase font-bold text-slate-500 border-b border-slate-200 dark:border-slate-700">
                                <tr>
                                    <th class="px-4 py-3">Date</th>
                                    <th class="px-4 py-3">Horaire</th>
                                    <th class="px-4 py-3">Matière / Épreuve</th>
                                    <th class="px-4 py-3">Niveau & Groupe</th>
                                    <th class="px-4 py-3">Salle</th>
                                    <th class="px-4 py-3 text-center">Surveillants</th>
                                    <th class="px-4 py-3 text-center">Statut</th>
                                    <th class="px-4 py-3 text-right">Action</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100 dark:divide-slate-700/60">
                                @forelse($jours->flatten(1) as $item)
                                    @php $epreuve = $item['epreuve']; @endphp
                                    <tr class="hover:bg-slate-50/50 dark:hover:bg-slate-700/30">
                                        <td class="px-4 py-3 font-semibold text-slate-800 dark:text-slate-200">
                                            {{ \Carbon\Carbon::parse($item['date_iso'])->format('d/m/Y') }}
                                        </td>
                                        <td class="px-4 py-3 font-bold text-indigo-600 dark:text-indigo-400 tabular-nums">
                                            {{ $item['heure_debut'] }} – {{ $item['heure_fin'] }}
                                        </td>
                                        <td class="px-4 py-3 font-bold text-slate-900 dark:text-white">
                                            {{ $epreuve->code }} — {{ $epreuve->matiere?->nom }}
                                        </td>
                                        <td class="px-4 py-3 text-slate-600 dark:text-slate-300">
                                            {{ $epreuve->groupe?->niveau ?? ($epreuve->matiere?->niveau ?? '—') }} ({{ $epreuve->groupe?->code ?? 'Tous' }})
                                        </td>
                                        <td class="px-4 py-3 text-slate-600 dark:text-slate-300">
                                            {{ $epreuve->salle?->code ?? '—' }}
                                        </td>
                                        <td class="px-4 py-3 text-center font-bold tabular-nums">
                                            {{ $item['actifs']->count() }}
                                        </td>
                                        <td class="px-4 py-3 text-center">
                                            <span class="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-bold
                                                {{ $item['statut'] === 'complet' ? 'bg-emerald-100 text-emerald-800' :
                                                   ($item['statut'] === 'conflit' ? 'bg-rose-100 text-rose-800' :
                                                   ($item['statut'] === 'partiel' ? 'bg-amber-100 text-amber-800' : 'bg-slate-200 text-slate-700')) }}">
                                                {{ ucfirst($item['statut']) }}
                                            </span>
                                        </td>
                                        <td class="px-4 py-3 text-right">
                                            <a href="{{ route('admin.evaluations.surveillances.emploi', array_merge(request()->query(), ['vue' => 'examen', 'epreuve_id' => $epreuve->id])) }}"
                                               class="inline-flex items-center gap-1 rounded-lg px-2.5 py-1 text-xs font-bold bg-indigo-50 text-indigo-700 dark:bg-indigo-950/60 dark:text-indigo-300 hover:bg-indigo-100">
                                                Inspecter
                                            </a>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="8" class="px-4 py-8 text-center text-slate-400 italic">
                                            Aucun examen disponible pour ces critères.
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            @endif
        </div>
    @endif
</div>

<!-- Styles spécifiques pour l'impression (§14) -->
<style>
@media print {
    /* Masquer tous les éléments interactifs et menus */
    .no-print,
    nav,
    aside,
    header,
    footer,
    .admin-nav-section,
    .btn,
    button,
    form,
    #sidebar,
    .sidebar {
        display: none !important;
    }

    .print-only {
        display: block !important;
    }

    body {
        background: #ffffff !important;
        color: #000000 !important;
        font-size: 11pt !important;
    }

    .print-card {
        border: 1px solid #cccccc !important;
        break-inside: avoid;
        margin-bottom: 1.5rem !important;
        box-shadow: none !important;
    }
}
</style>
@endsection
