@extends('layouts.app')

@section('title', 'Mes présences — Espace Enseignant')

@section('content')
    <div class="mx-auto max-w-7xl px-4 sm:px-6 py-8">
        <x-page-header titre="Mes présences & pointages" description="Suivi de vos séances officielles d'emploi du temps, pointages réels et demandes de régularisation."
            :fil="['Tableau de bord' => route('enseignant.dashboard'), 'Présences' => '#']">
            <x-slot:actions>
                <div class="flex items-center gap-2">
                    <x-btn href="{{ route('enseignant.appel.selection') }}">Faire l’appel</x-btn>
                    <x-btn variant="secondary" href="{{ route('enseignant.reclamations.create', ['categorie' => 'presence', 'objet' => 'Demande de régularisation de présence']) }}">
                        Déposer une régularisation
                    </x-btn>
                    <x-btn variant="secondary" href="{{ route('enseignant.presences.export', request()->only(['date_debut', 'date_fin', 'statut'])) }}">
                        Exporter CSV
                    </x-btn>
                </div>
            </x-slot:actions>
        </x-page-header>

        {{-- Alerte explicative : Principe fondamental d'absence non automatique --}}
        <div class="mt-4 p-4 rounded-2xl border border-blue-200 dark:border-blue-900 bg-blue-50/70 dark:bg-blue-950/40 text-blue-900 dark:text-blue-200 flex items-start gap-3">
            <svg class="w-5 h-5 mt-0.5 text-blue-600 dark:text-blue-400 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
            </svg>
            <div class="text-xs space-y-1">
                <p class="font-bold">Principe officiel de scolarité : une séance sans pointage n’est jamais une absence confirmée.</p>
                <p class="text-blue-800/90 dark:text-blue-300">Elle est signalée comme <em>anomalie à vérifier</em>. Seule une validation officielle par la scolarité fait foi. En cas d'oubli ou d'anomalie matérielle, déposez une demande de régularisation avec justificatif.</p>
            </div>
        </div>

        {{-- Indicateurs KPIs de présence (Section 3.B) --}}
        <div class="mt-6 grid grid-cols-2 sm:grid-cols-4 lg:grid-cols-8 gap-2.5">
            <x-stat-card libelle="Taux de présence" :valeur="$stats['taux'] !== null ? $stats['taux'] . ' %' : '—'" :detail="$stats['prises_en_compte'] . ' échues'" couleur="{{ ($stats['taux'] ?? 100) >= 85 ? 'emerald' : 'amber' }}" />
            <x-stat-card libelle="Planifiées" :valeur="$stats['total']" detail="Total période" couleur="slate" />
            <x-stat-card libelle="Confirmées" :valeur="$stats['confirmees'] + $stats['dispenses']" detail="Présences / missions" couleur="emerald" />
            <x-stat-card libelle="Retards" :valeur="$stats['retards']" :detail="$stats['retard_minutes'] . ' min cumulées'" couleur="{{ $stats['retards'] > 0 ? 'amber' : 'slate' }}" />
            <x-stat-card libelle="Pointages manq." :valeur="$stats['pointages_manquants']" detail="Sans pointage" couleur="{{ $stats['pointages_manquants'] > 0 ? 'amber' : 'slate' }}" />
            <x-stat-card libelle="À vérifier" :valeur="$stats['absences_a_verifier']" detail="Non confirmées" couleur="{{ $stats['absences_a_verifier'] > 0 ? 'amber' : 'slate' }}" />
            <x-stat-card libelle="Absences valid." :valeur="$stats['absences_validees']" detail="Décision scolarité" couleur="{{ $stats['absences_validees'] > 0 ? 'rose' : 'slate' }}" />
            <x-stat-card libelle="Régularisations" :valeur="$demandesEnAttente" detail="En cours examen" couleur="{{ $demandesEnAttente > 0 ? 'primary' : 'slate' }}" />
        </div>

        {{-- Précision du calcul et périodes couvertes --}}
        <p class="mt-2 text-[11px] text-slate-500 dark:text-slate-400">
            @if($debut !== null || $fin !== null)
                Période couverte du <strong>{{ \Carbon\Carbon::parse($debut)->format('d/m/Y') }}</strong> au <strong>{{ \Carbon\Carbon::parse($fin)->format('d/m/Y') }}</strong>.
            @else
                Période : ensemble de l'historique enregistré ({{ $stats['total'] }} séances planifiées).
            @endif
            Calcul officiel : <em>Taux = (Confirmées + Dispenses) ÷ Séances échues non annulées</em>. Les séances futures non échues ({{ $stats['non_echues'] }}) et les séances annulées ({{ $stats['annulees'] }}) sont strictement exclues du dénominateur pour garantir un calcul équitable.
        </p>

        {{-- Section Appel intelligent des étudiants (Étape A) --}}
        <div class="mt-8 rounded-2xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 shadow-sm p-6">
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 pb-4 border-b border-slate-100 dark:border-slate-800">
                <div>
                    <h2 class="text-lg font-black text-slate-900 dark:text-slate-100 flex items-center gap-2">
                        <svg class="w-5 h-5 text-indigo-600 dark:text-indigo-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4" />
                        </svg>
                        Faire l’appel de mes séances — Pointage Étudiants
                    </h2>
                    <p class="mt-0.5 text-xs text-slate-500">
                        Séances officielles issues de l'emploi du temps pour le pointage des présences en temps réel.
                    </p>
                </div>

                <form method="GET" action="{{ route('enseignant.presences.index') }}" class="flex items-center gap-2">
                    <input type="date" name="date_appel" value="{{ $dateAppel }}" max="{{ now()->toDateString() }}"
                           class="rounded-xl border border-slate-300 dark:border-slate-700 px-3 py-1.5 text-xs bg-white dark:bg-slate-900 min-h-[38px]">
                    <x-btn type="submit" variant="secondary" class="text-xs py-1.5">Changer la date</x-btn>
                </form>
            </div>

            <div class="mt-4 grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3">
                @forelse($seancesAppel as $ligne)
                    <article class="rounded-xl border border-slate-200 dark:border-slate-800 bg-slate-50/50 dark:bg-slate-800/40 p-4 flex flex-col justify-between">
                        <div>
                            <div class="flex items-start justify-between gap-2">
                                <span class="text-[11px] font-bold uppercase tracking-wider text-indigo-600 dark:text-indigo-400">
                                    {{ strtoupper($ligne['slot']->type ?? 'COURS') }}
                                </span>
                                <x-status-badge :statut="$ligne['appel']?->statut ?? 'non_commence'" />
                            </div>
                            <h3 class="mt-1 font-bold text-slate-900 dark:text-slate-100 text-sm">
                                {{ $ligne['slot']->matiere?->nom ?? '—' }}
                            </h3>
                            <p class="text-xs text-slate-600 dark:text-slate-300 mt-1">
                                <strong>Groupe :</strong> {{ $ligne['slot']->schedule?->groupe?->nom ?? '—' }}
                            </p>
                            <p class="text-xs text-slate-500 mt-0.5">
                                <strong>Horaire :</strong> {{ substr((string) $ligne['slot']->start_time, 0, 5) }} → {{ substr((string) $ligne['slot']->end_time, 0, 5) }}
                                · Salle {{ $ligne['slot']->room?->code ?? '—' }}
                            </p>
                            <p class="text-xs text-slate-500 mt-0.5">
                                <strong>Effectif attendu :</strong> {{ $ligne['effectif'] }} étudiant(s)
                            </p>
                        </div>

                        <div class="mt-4 pt-3 border-t border-slate-200 dark:border-slate-700 flex items-center justify-between">
                            @if($ligne['appel'] && $ligne['appel']->estCloture())
                                <span class="text-[11px] text-emerald-700 dark:text-emerald-400 font-bold">
                                    ✓ Clôturé ({{ $ligne['appel']->presents }} P / {{ $ligne['appel']->absents }} A / {{ $ligne['appel']->retards }} R)
                                </span>
                                <x-btn variant="secondary" class="text-xs" href="{{ route('enseignant.appel.feuille', $ligne['appel']) }}">
                                    Consulter / Corriger
                                </x-btn>
                            @elseif($ligne['appel'])
                                <span class="text-[11px] text-amber-700 dark:text-amber-400 font-bold">
                                    En cours ({{ $ligne['appel']->presents }} P)
                                </span>
                                <x-btn variant="primary" class="text-xs" href="{{ route('enseignant.appel.feuille', $ligne['appel']) }}">
                                    Reprendre l’appel
                                </x-btn>
                            @else
                                <span class="text-[11px] text-slate-400 font-medium">Non commencé</span>
                                <x-btn variant="primary" class="text-xs" href="{{ route('enseignant.appel.ouvrir', ['slot' => $ligne['slot']->id, 'date' => $dateAppel]) }}">
                                    Faire l’appel
                                </x-btn>
                            @endif
                        </div>
                    </article>
                @empty
                    <div class="md:col-span-2 lg:col-span-3">
                        <x-empty-state message="Aucune séance d'emploi du temps trouvée pour vous à cette date ({{ \Carbon\Carbon::parse($dateAppel)->format('d/m/Y') }})." />
                    </div>
                @endforelse
            </div>
        </div>

        {{-- Filtres & Choix de vue (Section 3.C) --}}
        <div class="mt-8 flex flex-col md:flex-row items-stretch md:items-center justify-between gap-4 p-4 rounded-2xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 shadow-sm">
            <form method="GET" action="{{ route('enseignant.presences.index') }}" class="flex flex-wrap items-center gap-2">
                <input type="hidden" name="vue" value="{{ $vue }}">
                <div>
                    <label for="date_debut" class="sr-only">Date début</label>
                    <input type="date" id="date_debut" name="date_debut" value="{{ $debut }}" placeholder="Du"
                        class="rounded-xl border border-slate-300 dark:border-slate-700 px-3 py-2 text-xs bg-white dark:bg-slate-900 min-h-[40px]">
                </div>
                <div>
                    <label for="date_fin" class="sr-only">Date fin</label>
                    <input type="date" id="date_fin" name="date_fin" value="{{ $fin }}" placeholder="Au"
                        class="rounded-xl border border-slate-300 dark:border-slate-700 px-3 py-2 text-xs bg-white dark:bg-slate-900 min-h-[40px]">
                </div>
                <div>
                    <label for="statut" class="sr-only">Statut</label>
                    <select id="statut" name="statut" class="rounded-xl border border-slate-300 dark:border-slate-700 px-3 py-2 text-xs bg-white dark:bg-slate-900 min-h-[40px]">
                        <option value="">Tous les statuts</option>
                        @foreach(['presence_confirmee' => 'Présence confirmée', 'absence_a_verifier' => 'Absence à vérifier', 'absence_validee' => 'Absence validée', 'retard_potentiel' => 'Retard potentiel', 'retard_justifie' => 'Retard justifié', 'pointage_manquant' => 'Pointage manquant', 'dispense_mission' => 'Dispense / Mission', 'seance_annulee' => 'Séance annulée'] as $code => $libelle)
                            <option value="{{ $code }}" @selected($filtreStatut === $code)>{{ $libelle }}</option>
                        @endforeach
                    </select>
                </div>
                <x-btn type="submit" variant="secondary">Filtrer</x-btn>
                <a href="{{ route('enseignant.presences.index', array_merge(request()->query(), ['filtre' => $filtreAnomalies ? null : 'anomalies'])) }}"
                   class="rounded-xl px-3 py-2 text-xs font-bold min-h-[40px] inline-flex items-center {{ $filtreAnomalies ? 'bg-amber-500 text-white' : 'border border-amber-300 text-amber-800 dark:text-amber-300 hover:bg-amber-50 dark:hover:bg-amber-950/30' }}">
                    {{ $filtreAnomalies ? '✓ Anomalies (filtré)' : 'Anomalies seules' }}
                </a>
                @if(request()->anyFilled(['date_debut', 'date_fin', 'statut', 'filtre']))
                    <a href="{{ route('enseignant.presences.index') }}" class="text-xs text-slate-500 hover:underline px-2">Réinitialiser</a>
                @endif
            </form>

            {{-- Bascule Vue Liste / Semaine / Mois --}}
            <div class="inline-flex rounded-xl bg-slate-100 dark:bg-slate-800 p-1 border border-slate-200 dark:border-slate-700 shrink-0">
                <a href="{{ route('enseignant.presences.index', array_merge(request()->query(), ['vue' => 'liste'])) }}"
                   class="px-3 py-1.5 rounded-lg text-xs font-bold transition {{ $vue === 'liste' ? 'bg-white dark:bg-slate-900 text-slate-900 dark:text-white shadow-sm' : 'text-slate-600 dark:text-slate-400 hover:text-slate-900' }}">
                    Liste détaillée
                </a>
                <a href="{{ route('enseignant.presences.index', array_merge(request()->query(), ['vue' => 'semaine'])) }}"
                   class="px-3 py-1.5 rounded-lg text-xs font-bold transition {{ $vue === 'semaine' ? 'bg-white dark:bg-slate-900 text-slate-900 dark:text-white shadow-sm' : 'text-slate-600 dark:text-slate-400 hover:text-slate-900' }}">
                    Vue semaine
                </a>
                <a href="{{ route('enseignant.presences.index', array_merge(request()->query(), ['vue' => 'mois'])) }}"
                   class="px-3 py-1.5 rounded-lg text-xs font-bold transition {{ $vue === 'mois' ? 'bg-white dark:bg-slate-900 text-slate-900 dark:text-white shadow-sm' : 'text-slate-600 dark:text-slate-400 hover:text-slate-900' }}">
                    Vue calendrier
                </a>
            </div>
        </div>

        {{-- Affichage selon le mode de vue (Section 3.C) --}}
        @if($vue === 'semaine' || $vue === 'mois')
            {{-- Vue groupée Semaine / Mois --}}
            <div class="mt-6 space-y-4">
                @php
                    $groupesParDate = $presences->groupBy(fn ($p) => $p->date->format('Y-m-d'));
                @endphp
                @if($groupesParDate->isNotEmpty())
                    @foreach($groupesParDate as $dateStr => $seancesDuJour)
                        <div class="rounded-2xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 shadow-sm overflow-hidden">
                            <div class="px-5 py-3 border-b border-slate-100 dark:border-slate-800 bg-slate-50 dark:bg-slate-800/40 flex items-center justify-between">
                                <h4 class="text-xs font-bold uppercase tracking-wider text-slate-700 dark:text-slate-300">
                                    {{ \Carbon\Carbon::parse($dateStr)->locale('fr')->isoFormat('dddd D MMMM YYYY') }}
                                </h4>
                                <span class="text-xs text-slate-500 font-semibold">{{ $seancesDuJour->count() }} séance(s)</span>
                            </div>
                            <div class="divide-y divide-slate-100 dark:divide-slate-800">
                                @foreach($seancesDuJour as $p)
                                    <div class="p-4 flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3 hover:bg-slate-50/60 dark:hover:bg-slate-800/30">
                                        <div class="flex-1">
                                            <div class="flex items-center gap-2">
                                                <span class="font-mono text-xs font-bold text-slate-800 dark:text-slate-200">
                                                    {{ substr((string) ($p->slot?->start_time ?? ''), 0, 5) }} → {{ substr((string) ($p->slot?->end_time ?? ''), 0, 5) }}
                                                </span>
                                                <span class="font-bold text-slate-900 dark:text-white text-sm">{{ $p->slot?->matiere?->nom ?? '—' }}</span>
                                                <span class="text-xs text-slate-500 font-semibold">({{ $p->slot?->schedule?->groupe?->nom ?? '—' }})</span>
                                            </div>
                                            <div class="mt-1 flex flex-wrap items-center gap-3 text-xs text-slate-500">
                                                <span>Salle : <strong class="font-mono">{{ $p->slot?->room?->code ?? '—' }}</strong></span>
                                                @if($p->heure_arrivee)
                                                    <span>Pointage arrivée : <strong class="font-mono">{{ substr((string) $p->heure_arrivee, 0, 5) }}</strong></span>
                                                    @if($p->retard_minutes > 0)
                                                        <span class="text-amber-600 font-bold">+{{ $p->retard_minutes }} min retard</span>
                                                    @endif
                                                @else
                                                    <span class="italic text-slate-400">Aucun pointage détecté</span>
                                                @endif
                                            </div>
                                        </div>
                                        <div class="flex items-center gap-3 shrink-0">
                                            <x-status-badge :statut="$p->statut_effectif" />
                                            <a href="{{ route('enseignant.presences.show', $p) }}" class="text-xs font-bold text-primary-700 dark:text-primary-300 underline underline-offset-2">Détail</a>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                    <div class="mt-4">{{ $presences->links() }}</div>
                @else
                    <div class="rounded-2xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 p-8">
                        <x-empty-state message="Aucune séance sur la période demandée." />
                    </div>
                @endif
            </div>
        @else
            {{-- Vue Tableau standard détaillée --}}
            <section aria-label="Historique de mes présences" class="mt-6 overflow-x-auto rounded-2xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 shadow-sm">
                @if($presences->isNotEmpty())
                    <table class="w-full min-w-[720px] text-left text-xs sm:text-sm">
                        <thead>
                            <tr class="border-b border-slate-100 dark:border-slate-800 text-[11px] uppercase tracking-wider text-slate-400 bg-slate-50/70 dark:bg-slate-800/40">
                                <th scope="col" class="px-5 py-3.5 font-bold">Date & Horaire</th>
                                <th scope="col" class="px-5 py-3.5 font-bold">Séance & Groupe</th>
                                <th scope="col" class="px-5 py-3.5 font-bold">Salle</th>
                                <th scope="col" class="px-5 py-3.5 font-bold">Pointage réel</th>
                                <th scope="col" class="px-5 py-3.5 font-bold">Statut effectif</th>
                                <th scope="col" class="px-5 py-3.5 font-bold text-right">Régularisation / Action</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                            @foreach($presences as $presence)
                                <tr class="hover:bg-slate-50/60 dark:hover:bg-slate-800/30 transition">
                                    <td class="px-5 py-3.5 whitespace-nowrap">
                                        <p class="font-bold text-slate-900 dark:text-slate-100 tabular-nums">{{ $presence->date->format('d/m/Y') }}</p>
                                        <p class="text-xs text-slate-400 font-mono">{{ substr((string) ($presence->slot?->start_time ?? ''), 0, 5) }} → {{ substr((string) ($presence->slot?->end_time ?? ''), 0, 5) }}</p>
                                    </td>
                                    <td class="px-5 py-3.5">
                                        <p class="font-bold text-slate-800 dark:text-slate-100">{{ $presence->slot?->matiere?->nom ?? '—' }}</p>
                                        <p class="text-xs text-slate-500">{{ $presence->slot?->schedule?->groupe?->nom ?? '—' }}</p>
                                    </td>
                                    <td class="px-5 py-3.5 whitespace-nowrap font-mono font-semibold text-slate-700 dark:text-slate-300">
                                        {{ $presence->slot?->room?->code ?? '—' }}
                                    </td>
                                    <td class="px-5 py-3.5 whitespace-nowrap text-xs">
                                        @if($presence->heure_arrivee)
                                            <span class="font-mono text-slate-800 dark:text-slate-200">Arrivée {{ substr((string) $presence->heure_arrivee, 0, 5) }}</span>
                                            @if($presence->retard_minutes > 0)
                                                <span class="block text-[11px] text-amber-600 font-bold">+{{ $presence->retard_minutes }} min retard</span>
                                            @endif
                                        @else
                                            <span class="text-slate-400 italic">Aucun pointage</span>
                                        @endif
                                    </td>
                                    <td class="px-5 py-3.5 whitespace-nowrap">
                                        <x-status-badge :statut="$presence->statut_effectif" />
                                        @if($presence->valide_par)
                                            <span class="block text-[10px] text-slate-400 mt-0.5">Validé par l'autorité</span>
                                        @endif
                                    </td>
                                    <td class="px-5 py-3.5 text-right whitespace-nowrap space-x-2">
                                        <a href="{{ route('enseignant.presences.show', $presence) }}"
                                           class="font-bold text-indigo-700 dark:text-indigo-300 hover:underline">
                                            Détail
                                        </a>
                                        @if(in_array($presence->statut_effectif, ['absence_a_verifier', 'pointage_manquant', 'retard_potentiel'], true))
                                            <a href="{{ route('enseignant.reclamations.create', ['categorie' => 'presence', 'presence_id' => $presence->id, 'objet' => 'Régularisation présence du ' . $presence->date->format('d/m/Y') . ' (' . ($presence->slot?->matiere?->nom ?? '') . ')']) }}"
                                               class="font-bold text-amber-600 dark:text-amber-400 hover:underline">
                                                Régulariser
                                            </a>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                    <div class="px-5 py-3 border-t border-slate-100 dark:border-slate-800">{{ $presences->links() }}</div>
                @else
                    <div class="p-8"><x-empty-state message="Aucune présence enregistrée sur la période sélectionnée." /></div>
                @endif
            </section>
        @endif

        {{-- Dernières modifications apportées au dossier (Section 3.B) --}}
        @if($dernieresModifications->isNotEmpty())
            <section aria-label="Dernières modifications du dossier" class="mt-8 rounded-2xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 shadow-sm p-5">
                <h4 class="text-xs font-black uppercase tracking-wider text-slate-500 mb-3">Dernières modifications tracées sur votre dossier de présence</h4>
                <div class="space-y-2">
                    @foreach($dernieresModifications as $mod)
                        <div class="p-3 rounded-xl border border-slate-100 dark:border-slate-800 bg-slate-50/70 dark:bg-slate-800/40 text-xs flex flex-col sm:flex-row sm:items-center justify-between gap-2">
                            <div>
                                <span class="font-bold text-slate-800 dark:text-slate-100">Séance du {{ $mod->date->format('d/m/Y') }}</span>
                                <span class="text-slate-500">({{ $mod->slot?->matiere?->nom ?? '—' }})</span>
                                <span class="mx-1 text-slate-300">·</span>
                                <span class="text-slate-600 dark:text-slate-300">Statut effectif : <strong>{{ $mod->libelle_statut_effectif }}</strong></span>
                                @if($mod->motif)
                                    <p class="text-[11px] text-slate-500 mt-0.5">Motif : « {{ $mod->motif }} »</p>
                                @endif
                            </div>
                            <span class="text-[11px] text-slate-400 shrink-0 tabular-nums">Mis à jour le {{ $mod->updated_at->format('d/m/Y H:i') }}</span>
                        </div>
                    @endforeach
                </div>
            </section>
        @endif

        {{-- Pointages sans séance correspondante (Pointages orphelins Section 3.D) --}}
        @if($orphelins->isNotEmpty())
            <section aria-label="Pointages sans séance" class="mt-6 rounded-2xl border border-amber-200 dark:border-amber-800 bg-amber-50/50 dark:bg-amber-950/30 shadow-sm overflow-hidden">
                <div class="px-5 py-3 border-b border-amber-200 dark:border-amber-800 flex items-center justify-between">
                    <div>
                        <h3 class="text-sm font-black text-amber-900 dark:text-amber-200">Pointages bruts sans séance correspondante ({{ $orphelins->count() }})</h3>
                        <p class="text-xs text-amber-700 dark:text-amber-300">Pointages matériels enregistrés en dehors de vos créneaux planifiés (conservés intégralement sans suppression silencieuse).</p>
                    </div>
                </div>
                <ul class="divide-y divide-amber-100 dark:divide-amber-900 max-h-60 overflow-y-auto">
                    @foreach($orphelins as $pointage)
                        <li class="px-5 py-2.5 text-xs tabular-nums text-amber-900 dark:text-amber-200 flex items-center justify-between">
                            <span>{{ \Carbon\Carbon::parse($pointage->horodatage)->format('d/m/Y H:i:s') }} · Type : <strong>{{ $pointage->type }}</strong> · Source : {{ $pointage->source }}</span>
                            @if($pointage->notes)
                                <span class="text-[11px] text-amber-700 italic">{{ $pointage->notes }}</span>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif

        {{-- Derniers pointages bruts (badgeuse / terminal / manuel) --}}
        <section aria-label="Mes derniers pointages" class="mt-6 rounded-2xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 shadow-sm overflow-hidden">
            <div class="px-5 py-3 border-b border-slate-100 dark:border-slate-800">
                <h3 class="text-sm font-black text-slate-900 dark:text-slate-100">Derniers pointages bruts reçus (10 plus récents)</h3>
            </div>
            @if($pointages->isNotEmpty())
                <ul class="divide-y divide-slate-100 dark:divide-slate-800">
                    @foreach($pointages as $pointage)
                        <li class="px-5 py-2.5 text-xs tabular-nums text-slate-700 dark:text-slate-300 flex items-center justify-between">
                            <span>{{ \Carbon\Carbon::parse($pointage->horodatage)->format('d/m/Y H:i:s') }} · Type : <strong>{{ $pointage->type }}</strong> · Source : {{ $pointage->source }}</span>
                            @if($pointage->notes)
                                <span class="text-[11px] text-slate-400 italic">{{ $pointage->notes }}</span>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @else
                <div class="p-5"><x-empty-state message="Aucun pointage matériel enregistré récemment." /></div>
            @endif
        </section>
    </div>
@endsection
