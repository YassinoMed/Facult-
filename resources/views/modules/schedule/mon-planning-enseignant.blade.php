@extends('layouts.app')

@section('title', 'Mon emploi du temps — Espace Enseignant')

@php
$joursFr = ['monday' => 'Lundi', 'tuesday' => 'Mardi', 'wednesday' => 'Mercredi', 'thursday' => 'Jeudi', 'friday' => 'Vendredi', 'saturday' => 'Samedi', 'sunday' => 'Dimanche'];
$queryBase = array_filter(['vue' => $vue, 'semaine' => $decalage, 'semestre' => $filtres['semestre'], 'groupe' => $filtres['groupe'] > 0 ? $filtres['groupe'] : null, 'matiere' => $filtres['matiere'] > 0 ? $filtres['matiere'] : null], fn ($v) => $v !== null);
@endphp

@section('content')
    <div class="mx-auto max-w-6xl px-4 sm:px-6 py-8" x-data="{ modalOuvert: false, creneauModal: null }">
        <x-page-header titre="Mon emploi du temps" description="Vos créneaux d'enseignement universitaires, toutes classes confondues. Lecture seule."
            :fil="['Tableau de bord' => route('enseignant.dashboard'), 'Emploi du temps' => '#']">
            <x-slot:actions>
                <x-btn variant="secondary" href="{{ route('enseignant.emploi-du-temps.pdf', $queryBase) }}">
                    <svg class="h-4 w-4 mr-1.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                    Exporter PDF
                </x-btn>
                <x-btn variant="secondary" onclick="window.print()">
                    <svg class="h-4 w-4 mr-1.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/></svg>
                    Imprimer
                </x-btn>
            </x-slot:actions>
        </x-page-header>

        <p class="mt-2 text-xs font-bold uppercase tracking-wider text-slate-400 no-print">{{ $widgets['jourActuel'] }}</p>

        {{-- Widgets : compteurs réels de la période affichée --}}
        <div class="mt-2 grid grid-cols-2 sm:grid-cols-4 gap-3 no-print">
            <x-stat-card libelle="Séances aujourd'hui" :valeur="$widgets['nbSeancesJour']" detail="Effectives ce jour" couleur="primary" />
            <x-stat-card libelle="Séances semaine" :valeur="$widgets['nbSeancesPeriode']" detail="Total période" couleur="slate" />
            <x-stat-card libelle="Heures planifiées" :valeur="$widgets['heuresPlanifiees'] . ' h'" detail="Volume horaire" couleur="indigo" />
            <x-stat-card libelle="Annulées / déplacées" :valeur="$widgets['nbAnnulees'] . ' / ' . $widgets['nbDeplacees']" detail="Exceptions de calendrier" couleur="{{ ($widgets['nbAnnulees'] + $widgets['nbDeplacees']) > 0 ? 'amber' : 'emerald' }}" />
        </div>

        @if(!empty($widgets['nbIncompletes']) && $widgets['nbIncompletes'] > 0)
            <p class="mt-2 text-[11px] text-amber-700 dark:text-amber-400 font-medium no-print">
                ⚠️ {{ $widgets['nbIncompletes'] }} créneau(x) présentent des informations partielles (salle ou groupe non spécifié par la scolarité).
            </p>
        @endif

        @if($widgets['prochaine'] !== null)
            <div class="mt-3 rounded-2xl border border-indigo-200 dark:border-indigo-800 bg-indigo-50/60 dark:bg-indigo-950/40 px-5 py-3 text-sm flex flex-wrap items-center gap-x-4 gap-y-1 no-print">
                <span class="font-black text-indigo-900 dark:text-indigo-200">Prochaine séance :</span>
                <span class="font-bold text-slate-900 dark:text-slate-100">{{ $widgets['prochaine']['matiere'] }}</span>
                <span class="tabular-nums text-slate-700 dark:text-slate-300">{{ $widgets['prochaine']['date'] ? \Carbon\Carbon::parse($widgets['prochaine']['date'])->format('d/m/Y') : '' }} · {{ $widgets['prochaine']['debut'] }} → {{ $widgets['prochaine']['fin'] }}</span>
                <span class="text-slate-600 dark:text-slate-400">Salle {{ $widgets['prochaine']['salle'] ?? '—' }}</span>
                @if(!empty($widgets['prochaine']['groupe']))
                    <span class="font-semibold text-indigo-700 dark:text-indigo-300">· Groupe {{ $widgets['prochaine']['groupe'] }}</span>
                @endif
            </div>
        @endif

        @if(count($chevauchements) > 0)
            <div class="mt-3 no-print">
                <x-alert type="warning" titre="Chevauchement détecté dans vos créneaux :">
                    <p class="text-xs text-amber-800 dark:text-amber-300">Des horaires d'enseignement se chevauchent sur les mêmes jours :</p>
                    <ul class="list-disc list-inside mt-1 space-y-0.5 text-xs text-amber-900 dark:text-amber-200">
                        @foreach($chevauchements as $chevauchement)
                            <li>{{ $joursFr[$chevauchement['jour']] ?? $chevauchement['jour'] }} : {{ $chevauchement['a'] }} ⇄ {{ $chevauchement['b'] }}</li>
                        @endforeach
                    </ul>
                </x-alert>
            </div>
        @endif

        {{-- Onglets de vues --}}
        <div class="mt-4 flex gap-1 rounded-2xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 p-1 shadow-sm no-print" role="tablist" aria-label="Vues du planning">
            @foreach(['semaine' => 'Vue Hebdomadaire', 'jour' => 'Vue Journalière', 'liste' => 'Vue Liste'] as $codeVue => $libelleVue)
                <a href="{{ route('enseignant.emploi-du-temps.semaine', array_merge($queryBase, ['vue' => $codeVue])) }}"
                   role="tab" @if($vue === $codeVue) aria-selected="true" @endif
                   class="flex-1 rounded-xl px-4 py-2.5 text-sm font-bold text-center min-h-[44px] transition {{ $vue === $codeVue ? 'bg-indigo-600 text-white shadow' : 'text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800' }}">{{ $libelleVue }}</a>
            @endforeach
        </div>

        {{-- Filtres (restreints à mes valeurs, jamais d'élargissement) --}}
        <form method="GET" action="{{ route('enseignant.emploi-du-temps.semaine') }}" class="mt-3 flex flex-wrap gap-2 items-end no-print bg-slate-50/70 dark:bg-slate-900/40 p-3 rounded-2xl border border-slate-200 dark:border-slate-800">
            <input type="hidden" name="vue" value="{{ $vue }}">
            <input type="hidden" name="semaine" value="{{ $decalage }}">
            <div>
                <label for="filtre-semestre" class="block text-[11px] font-bold uppercase tracking-wider text-slate-500">Semestre</label>
                <select id="filtre-semestre" name="semestre" class="rounded-xl border border-slate-300 dark:border-slate-700 px-3 py-2 text-sm bg-white dark:bg-slate-900 min-h-[44px]">
                    <option value="">Tous les semestres</option>
                    @foreach($choix['semestres'] as $semestre)
                        <option value="{{ $semestre }}" @selected($filtres['semestre'] === (int) $semestre)>Semestre {{ $semestre }} (S{{ $semestre }})</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="filtre-groupe" class="block text-[11px] font-bold uppercase tracking-wider text-slate-500">Groupe</label>
                <select id="filtre-groupe" name="groupe" class="rounded-xl border border-slate-300 dark:border-slate-700 px-3 py-2 text-sm bg-white dark:bg-slate-900 min-h-[44px]">
                    <option value="">Tous les groupes</option>
                    @foreach($choix['groupes'] as $idGroupe => $nomGroupe)
                        <option value="{{ $idGroupe }}" @selected($filtres['groupe'] === (int) $idGroupe)>{{ $nomGroupe }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="filtre-matiere" class="block text-[11px] font-bold uppercase tracking-wider text-slate-500">Matière</label>
                <select id="filtre-matiere" name="matiere" class="rounded-xl border border-slate-300 dark:border-slate-700 px-3 py-2 text-sm bg-white dark:bg-slate-900 min-h-[44px]">
                    <option value="">Toutes les matières</option>
                    @foreach($choix['matieres'] as $idMatiere => $nomMatiere)
                        <option value="{{ $idMatiere }}" @selected($filtres['matiere'] === (int) $idMatiere)>{{ $nomMatiere }}</option>
                    @endforeach
                </select>
            </div>
            <x-btn type="submit" variant="secondary">Filtrer</x-btn>
            <a href="{{ route('enseignant.emploi-du-temps.semaine', ['vue' => $vue]) }}" class="text-xs font-bold text-slate-500 underline underline-offset-2 px-2 py-3">Réinitialiser</a>
        </form>

        @if($vue === 'jour')
            <div class="mt-3 flex gap-2 overflow-x-auto pb-1 no-print" role="tablist" aria-label="Choix du jour">
                @php $jourActif = array_key_first($jours) ?? 'monday'; @endphp
                @foreach($joursFr as $code => $libelle)
                    <a href="{{ route('enseignant.emploi-du-temps.semaine', array_merge($queryBase, ['vue' => 'jour', 'jour' => $code])) }}"
                       class="rounded-xl px-4 py-2.5 text-xs font-bold whitespace-nowrap min-h-[40px] transition {{ $code === $jourActif ? 'bg-indigo-600 text-white shadow-sm' : 'bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 text-slate-600 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-800' }}">{{ $libelle }}</a>
                @endforeach
            </div>
        @endif

        @if($vue === 'liste')
            <section aria-label="Liste chronologique" class="mt-4 overflow-x-auto rounded-2xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 shadow-sm">
                @if(count($liste) > 0)
                    <table class="w-full min-w-[640px] text-left text-xs sm:text-sm">
                        <thead>
                            <tr class="border-b border-slate-100 dark:border-slate-800 text-[11px] uppercase tracking-wider text-slate-400 bg-slate-50/50 dark:bg-slate-800/40">
                                <th scope="col" class="px-5 py-3 font-bold">Date & Jour</th>
                                <th scope="col" class="px-5 py-3 font-bold">Créneau</th>
                                <th scope="col" class="px-5 py-3 font-bold">Matière / ECUE</th>
                                <th scope="col" class="px-5 py-3 font-bold">Groupe & Salle</th>
                                <th scope="col" class="px-5 py-3 font-bold">Type</th>
                                <th scope="col" class="px-5 py-3 font-bold">Statut</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                            @foreach($liste as $ligne)
                                <tr class="hover:bg-slate-50/60 dark:hover:bg-slate-800/30 transition cursor-pointer"
                                    @click="creneauModal = {{ json_encode($ligne) }}; modalOuvert = true">
                                    <td class="px-5 py-3 tabular-nums font-bold text-slate-900 dark:text-slate-100">
                                        {{ \Carbon\Carbon::parse($ligne['date'])->format('d/m/Y') }}
                                        <span class="block text-[11px] font-normal text-slate-400">{{ $joursFr[strtolower(\Carbon\Carbon::parse($ligne['date'])->format('l'))] ?? '' }}</span>
                                    </td>
                                    <td class="px-5 py-3 tabular-nums font-semibold text-slate-800 dark:text-slate-200">
                                        {{ $ligne['debut'] }} → {{ $ligne['fin'] }}
                                        <span class="block text-[11px] font-normal text-slate-400">{{ $ligne['duree_texte'] ?? '' }}</span>
                                    </td>
                                    <td class="px-5 py-3">
                                        <a href="{{ route('enseignant.emploi-du-temps.creneau', $ligne['id']) }}" class="font-bold text-indigo-700 dark:text-indigo-300 hover:underline">
                                            {{ $ligne['matiere'] }}
                                        </a>
                                        @if(!empty($ligne['matiere_code']))
                                            <span class="text-xs text-slate-400">({{ $ligne['matiere_code'] }})</span>
                                        @endif
                                    </td>
                                    <td class="px-5 py-3 text-slate-700 dark:text-slate-300">
                                        {{ $ligne['groupe'] ?? '— Non défini' }}
                                        <span class="block text-xs text-slate-500 font-mono">Salle {{ $ligne['salle'] ?? '— Non assignée' }}</span>
                                    </td>
                                    <td class="px-5 py-3"><x-status-badge :statut="$ligne['type']" /></td>
                                    <td class="px-5 py-3">
                                        <x-status-badge :statut="$ligne['statut']" />
                                        @if(!empty($ligne['motif']))
                                            <span class="block text-[11px] text-slate-500 mt-0.5">{{ $ligne['motif'] }}</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @else
                    <div class="p-8 text-center"><x-empty-state message="Aucun créneau d'enseignement sur la période." /></div>
                @endif
            </section>
            <p class="mt-2 text-xs text-slate-500 no-print">Période couverte du {{ \Carbon\Carbon::parse($debutPeriode)->format('d/m/Y') }} au {{ \Carbon\Carbon::parse($finPeriode)->format('d/m/Y') }} (triée chronologiquement).</p>
        @else
            {{-- Navigation des semaines --}}
            <nav aria-label="Navigation des semaines" class="mt-4 flex items-center justify-between gap-2 rounded-2xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 p-2 shadow-sm no-print">
                <a href="{{ route('enseignant.emploi-du-temps.semaine', array_merge($queryBase, ['semaine' => $decalage - 1])) }}" aria-label="Semaine précédente"
                   class="inline-flex items-center gap-1.5 rounded-xl px-4 py-2.5 text-sm font-bold text-slate-700 dark:text-slate-200 hover:bg-slate-100 dark:hover:bg-slate-800 min-h-[44px]">
                    <span aria-hidden="true">←</span><span class="hidden sm:inline">Précédente</span>
                </a>
                <div class="flex items-center gap-2">
                    <p class="text-xs sm:text-sm font-bold text-slate-900 dark:text-slate-100 text-center">
                        Semaine du {{ $lundi->format('d/m/Y') }} au {{ $lundi->copy()->addDays(6)->format('d/m/Y') }}
                    </p>
                    @if($decalage !== 0)
                        <a href="{{ route('enseignant.emploi-du-temps.semaine', array_merge($queryBase, ['semaine' => 0])) }}" class="rounded-lg bg-indigo-50 dark:bg-indigo-950/60 text-indigo-700 dark:text-indigo-300 px-2.5 py-1 text-xs font-bold hover:bg-indigo-100">
                            Aujourd'hui
                        </a>
                    @endif
                </div>
                <a href="{{ route('enseignant.emploi-du-temps.semaine', array_merge($queryBase, ['semaine' => $decalage + 1])) }}" aria-label="Semaine suivante"
                   class="inline-flex items-center gap-1.5 rounded-xl px-4 py-2.5 text-sm font-bold text-slate-700 dark:text-slate-200 hover:bg-slate-100 dark:hover:bg-slate-800 min-h-[44px]">
                    <span class="hidden sm:inline">Suivante</span><span aria-hidden="true">→</span>
                </a>
            </nav>

            <div class="mt-4 grid grid-cols-1 lg:grid-cols-2 gap-4">
                @foreach($jours as $codeJour => $creneaux)
                    @php $dateSection = $lundi->copy()->addDays($loop->index)->format('Y-m-d'); @endphp
                    <section aria-label="{{ $joursFr[$codeJour] }}" class="rounded-2xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 shadow-sm overflow-hidden flex flex-col justify-between">
                        <div>
                            <div class="px-5 py-3 border-b border-slate-100 dark:border-slate-800 flex items-center justify-between {{ $dateSection === $aujourdhui ? 'bg-indigo-50/50 dark:bg-indigo-950/20' : '' }}">
                                <h3 class="text-sm font-black text-slate-900 dark:text-slate-100 flex items-center gap-2">
                                    {{ $joursFr[$codeJour] }}
                                    @if($dateSection === $aujourdhui)
                                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold bg-indigo-600 text-white">Aujourd'hui</span>
                                    @endif
                                </h3>
                                <span class="text-xs text-slate-500 font-medium">{{ $lundi->copy()->addDays($loop->index)->format('d/m/Y') }}</span>
                            </div>
                            @if(count($creneaux) > 0)
                                <ul class="divide-y divide-slate-100 dark:divide-slate-800">
                                    @foreach($creneaux as $creneau)
                                        <li class="p-4 hover:bg-slate-50/70 dark:hover:bg-slate-800/40 transition cursor-pointer"
                                            @click="creneauModal = {{ json_encode($creneau) }}; modalOuvert = true">
                                            <div class="flex items-center justify-between gap-2">
                                                <p class="font-bold text-slate-800 dark:text-slate-100 tabular-nums">
                                                    {{ $creneau['debut'] }} → {{ $creneau['fin'] }}
                                                    <span class="ml-1 text-xs text-slate-400 font-normal">({{ $creneau['duree_texte'] ?? '' }})</span>
                                                </p>
                                                <x-status-badge :statut="$creneau['type']" />
                                            </div>
                                            <p class="mt-1 text-slate-800 dark:text-slate-200 font-bold">
                                                <a href="{{ route('enseignant.emploi-du-temps.creneau', $creneau['id']) }}" class="text-indigo-700 dark:text-indigo-300 hover:underline" @click.stop>
                                                    {{ $creneau['matiere'] }}
                                                </a>
                                                @if(!empty($creneau['matiere_code']))
                                                    <span class="text-xs font-mono text-slate-400 font-normal">({{ $creneau['matiere_code'] }})</span>
                                                @endif
                                            </p>
                                            <div class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-slate-600 dark:text-slate-400">
                                                <span>👥 {{ $creneau['groupe'] ?? '— Sans groupe' }}</span>
                                                <span class="font-mono">📍 Salle {{ $creneau['salle'] ?? '— Non assignée' }}</span>
                                            </div>
                                            <div class="mt-2 flex items-center justify-between gap-2">
                                                <x-status-badge :statut="$creneau['statut']" />
                                                @if(! empty($creneau['motif']))
                                                    <span class="text-xs text-slate-500 italic max-w-xs truncate">{{ $creneau['motif'] }}</span>
                                                @endif
                                            </div>
                                        </li>
                                    @endforeach
                                </ul>
                            @else
                                <div class="p-6 text-center"><x-empty-state message="Aucun créneau ce jour." /></div>
                            @endif
                        </div>
                    </section>
                @endforeach
            </div>
        @endif

        @if($changements->isNotEmpty())
            <section aria-label="Changements récents" class="mt-6 rounded-2xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 shadow-sm overflow-hidden no-print">
                <div class="px-5 py-3 border-b border-slate-100 dark:border-slate-800 flex items-center justify-between">
                    <h3 class="text-sm font-black text-slate-900 dark:text-slate-100">Modifications récentes de mon planning</h3>
                    <span class="text-xs text-slate-500">Historique des 5 dernières modifications</span>
                </div>
                <ul class="divide-y divide-slate-100 dark:divide-slate-800">
                    @foreach($changements as $changement)
                        <li class="px-5 py-3 text-xs text-slate-600 dark:text-slate-300 flex items-start justify-between gap-3">
                            <div>
                                <div class="flex items-center gap-2">
                                    <x-status-badge :statut="$changement->change_type" />
                                    <span class="font-bold text-slate-800 dark:text-slate-200">Créneau #{{ $changement->schedule_slot_id }}</span>
                                    <span class="text-slate-400 tabular-nums">{{ $changement->created_at->format('d/m/Y à H:i') }}</span>
                                </div>
                                @if(! empty($changement->reason))
                                    <p class="mt-1 text-slate-600 dark:text-slate-400">{{ $changement->reason }}</p>
                                @endif
                            </div>
                            <a href="{{ route('enseignant.emploi-du-temps.creneau', $changement->schedule_slot_id) }}" class="text-indigo-600 dark:text-indigo-400 font-bold hover:underline shrink-0">Voir créneau</a>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif

        <div class="mt-6 p-4 rounded-2xl border border-slate-200 dark:border-slate-800 bg-slate-50 dark:bg-slate-900/40 text-xs text-slate-600 dark:text-slate-400 flex flex-col sm:flex-row sm:items-center justify-between gap-3 no-print">
            <div>
                <p class="font-bold text-slate-800 dark:text-slate-200">Un créneau paraît incorrect ou manquant ?</p>
                <p class="mt-0.5">Le planning officiel est administré par la scolarité. Vous pouvez soumettre un signalement direct.</p>
            </div>
            <a href="{{ route('enseignant.reclamations.create', ['categorie' => 'emploi_du_temps', 'objet' => 'Signalement d\'une erreur de créneau sur mon planning']) }}" class="inline-flex items-center justify-center font-bold text-rose-700 dark:text-rose-300 bg-white dark:bg-slate-900 px-3.5 py-2 rounded-xl border border-rose-300 dark:border-rose-700 hover:bg-rose-50 shadow-sm shrink-0">
                Signaler une anomalie d'emploi du temps →
            </a>
        </div>

        {{-- Modale Interactive Alpine.js pour affichage rapide d'un créneau --}}
        <div x-cloak x-show="modalOuvert" class="fixed inset-0 z-50 overflow-y-auto" aria-labelledby="modal-titre" role="dialog" aria-modal="true">
            <div class="flex items-end justify-center min-h-screen pt-4 px-4 pb-20 text-center sm:block sm:p-0">
                <div x-show="modalOuvert" x-transition.opacity class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm transition-opacity" @click="modalOuvert = false" aria-hidden="true"></div>
                <span class="hidden sm:inline-block sm:align-middle sm:h-screen" aria-hidden="true">&#8203;</span>
                <div x-show="modalOuvert" x-transition class="inline-block align-bottom bg-white dark:bg-slate-900 rounded-3xl text-left overflow-hidden shadow-2xl transform transition-all sm:my-8 sm:align-middle sm:max-w-lg sm:w-full border border-slate-200 dark:border-slate-800 p-6">
                    <div class="flex items-start justify-between border-b border-slate-100 dark:border-slate-800 pb-3">
                        <div>
                            <h3 id="modal-titre" class="text-base font-black text-slate-900 dark:text-slate-100" x-text="creneauModal?.matiere"></h3>
                            <p class="text-xs text-slate-500" x-text="creneauModal ? (creneauModal.debut + ' → ' + creneauModal.fin + ' (' + creneauModal.duree_texte + ')') : ''"></p>
                        </div>
                        <button type="button" @click="modalOuvert = false" class="text-slate-400 hover:text-slate-600 dark:hover:text-slate-200">
                            <span class="sr-only">Fermer</span>✕
                        </button>
                    </div>
                    <div class="mt-4 space-y-2.5 text-xs text-slate-600 dark:text-slate-300">
                        <div class="flex justify-between py-1 border-b border-slate-100 dark:border-slate-800">
                            <span class="text-slate-400">Groupe</span>
                            <span class="font-bold text-slate-800 dark:text-slate-100" x-text="creneauModal?.groupe || 'Non spécifié'"></span>
                        </div>
                        <div class="flex justify-between py-1 border-b border-slate-100 dark:border-slate-800">
                            <span class="text-slate-400">Salle</span>
                            <span class="font-mono font-bold text-slate-800 dark:text-slate-100" x-text="creneauModal?.salle || 'Non assignée'"></span>
                        </div>
                        <div class="flex justify-between py-1 border-b border-slate-100 dark:border-slate-800">
                            <span class="text-slate-400">Type de cours</span>
                            <span class="uppercase font-bold" x-text="creneauModal?.type"></span>
                        </div>
                        <div class="flex justify-between py-1 border-b border-slate-100 dark:border-slate-800">
                            <span class="text-slate-400">Statut</span>
                            <span class="capitalize font-bold" x-text="creneauModal?.statut"></span>
                        </div>
                        <template x-if="creneauModal?.motif">
                            <div class="p-2.5 bg-amber-50 dark:bg-amber-950/40 rounded-xl text-amber-800 dark:text-amber-200 mt-2">
                                <span class="font-bold block">Motif de l'exception :</span>
                                <span x-text="creneauModal.motif"></span>
                            </div>
                        </template>
                    </div>
                    <div class="mt-6 flex flex-col sm:flex-row gap-2 justify-end">
                        <template x-if="creneauModal?.id">
                            <a :href="'/enseignant/emploi-du-temps/creneaux/' + creneauModal.id" class="inline-flex justify-center items-center px-4 py-2 text-xs font-bold rounded-xl bg-indigo-600 text-white hover:bg-indigo-700">
                                Fiche détaillée
                            </a>
                        </template>
                        <template x-if="creneauModal">
                            <a :href="'/enseignant/reclamations/creer?categorie=emploi_du_temps&objet=Anomalie%20sur%20créneau%20' + encodeURIComponent(creneauModal.matiere)" class="inline-flex justify-center items-center px-4 py-2 text-xs font-bold rounded-xl border border-rose-300 text-rose-700 hover:bg-rose-50">
                                Signaler anomalie
                            </a>
                        </template>
                        <button type="button" @click="modalOuvert = false" class="inline-flex justify-center items-center px-4 py-2 text-xs font-bold rounded-xl border border-slate-300 text-slate-700 hover:bg-slate-50">
                            Fermer
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <style>
        @media print {
            .no-print, header, nav, footer, form, [role="tablist"] {
                display: none !important;
            }
            body {
                background: white !important;
                color: black !important;
                font-size: 11pt !important;
            }
            .shadow-sm, .shadow {
                box-shadow: none !important;
            }
        }
    </style>
@endsection
