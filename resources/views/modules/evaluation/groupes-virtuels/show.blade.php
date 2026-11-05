@extends('layouts.app')

@section('title', 'Groupe virtuel #' . $resume['id'] . ' — ' . $resume['libelle'])

@section('content')
@php
    $effectifs = $fiche['effectifs'] ?? [];
    $peutVoirAnonyme = (bool) ($fiche['peut_voir_anonyme'] ?? false);
@endphp

<div class="space-y-6">
    <x-page-header
        :titre="'Groupe virtuel #' . $resume['id'] . ' — ' . $resume['libelle']"
        description="Fiche détaillée, liste des étudiants éligibles, groupes académiques sources, contrôles de correspondance et exports officiels."
        :fil="[
            'Évaluation' => route('admin.evaluations.index'),
            'Examens' => route('admin.evaluation.examens.tableau-de-bord'),
            'Groupes virtuels' => route('admin.evaluation.examens.groupes-virtuels.index'),
            $resume['libelle'] => '',
        ]"
    />

    @include('modules.evaluation.examens-advanced._nav-tabs')

    {{-- Bandeau de statut officiel / proposition non validée --}}
    @if($resume['est_proposition_non_validee'])
        <div class="rounded-2xl border border-amber-300 bg-amber-50/90 p-4 text-xs text-amber-950 shadow-2xs">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div class="flex items-start gap-3">
                    <span class="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-xl bg-amber-500 text-white font-bold">
                        !
                    </span>
                    <div>
                        <p class="font-bold text-sm text-amber-950">
                            Attention : ce groupe virtuel est une proposition non validée officiellement ({{ $resume['statut_validation_libelle'] }} · {{ $resume['statut_publication_libelle'] }})
                        </p>
                        <p class="mt-0.5 text-amber-900">
                            Un regroupement virtuel proposé ne constitue ni une validation de population définitive ni une publication officielle d'examen.
                            Les groupes académiques d'origine ({{ $resume['groupes_sources_codes'] }}) et les inscriptions pédagogiques demeurent strictement inchangés.
                        </p>
                    </div>
                </div>
                <a href="{{ route('admin.evaluation.examens.groupes-virtuels.index') }}"
                   class="rounded-xl border border-amber-300 bg-white px-3 py-1.5 text-xs font-bold text-amber-900 hover:bg-amber-100 transition">
                    ← Retour à la liste
                </a>
            </div>
        </div>
    @else
        <div class="rounded-2xl border border-emerald-300 bg-emerald-50/80 p-4 text-xs text-emerald-950 shadow-2xs flex items-center justify-between">
            <div>
                <p class="font-bold text-sm text-emerald-950">
                    Groupe virtuel rattaché à une population validée et publiée ({{ $resume['statut_validation_libelle'] }} · {{ $resume['statut_publication_libelle'] }})
                </p>
                <p class="mt-0.5 text-emerald-800">
                    Dernière validation de population : {{ $resume['date_validation'] ?? '—' }} · Publication : {{ $resume['date_publication'] ?? '—' }}
                </p>
            </div>
            <a href="{{ route('admin.evaluation.examens.groupes-virtuels.index') }}"
               class="rounded-xl border border-emerald-300 bg-white px-3 py-1.5 text-xs font-bold text-emerald-900 hover:bg-emerald-100 transition">
                ← Retour à la liste
            </a>
        </div>
    @endif

    {{-- Alertes de dérive de population ou d'incohérence de correspondance --}}
    @if(!empty($fiche['alertes']) || !$fiche['export_officiel_autorise'])
        <div class="rounded-2xl border-2 border-rose-300 bg-rose-50 p-5 text-xs text-rose-950 shadow-xs space-y-3">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <div class="flex items-center gap-2">
                    <span class="inline-flex rounded-full bg-rose-600 px-2.5 py-0.5 text-[11px] font-extrabold uppercase text-white">
                        Export officiel bloqué — Consultation diagnostique non validée
                    </span>
                    @if($fiche['en_derive'])
                        <span class="inline-flex rounded-full bg-amber-200 px-2.5 py-0.5 text-[11px] font-bold text-amber-950">
                            Dérive de population détectée
                        </span>
                    @endif
                </div>
                <div class="flex items-center gap-2">
                    @if($fiche['en_derive'])
                        <form method="POST" action="{{ route('admin.evaluation.examens.groupes-virtuels.groupe.resynchroniser', $resume['id']) }}">
                            @csrf
                            <button type="submit"
                                    onclick="return confirm('Régénérer et réactualiser ce groupe virtuel avec la population éligible actuelle ?')"
                                    class="rounded-xl bg-amber-600 hover:bg-amber-700 px-3.5 py-1.5 text-xs font-bold text-white shadow-xs transition flex items-center gap-1.5 cursor-pointer">
                                <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0 3.181 3.183a8.25 8.25 0 0 0 13.803-3.7M4.031 9.865a8.25 8.25 0 0 1 13.803-3.7l3.181 3.182m0-4.991v4.99" />
                                </svg>
                                Actualiser la proposition
                            </button>
                        </form>
                    @endif
                    @if(!empty($resume['epreuve_id']) && auth()->user()?->can('evaluations.update'))
                        <form method="POST" action="{{ route('admin.examens.preparation.epreuves.population', $resume['epreuve_id']) }}">
                            @csrf
                            <button type="submit" class="rounded-xl bg-rose-700 hover:bg-rose-800 px-3.5 py-1.5 text-xs font-bold text-white shadow-xs transition">
                                Revalider la population (Workflow officiel)
                            </button>
                        </form>
                    @endif
                </div>
            </div>

            @if(!empty($fiche['anomalies_detaillees']))
                <div class="mt-4 space-y-3">
                    <h4 class="text-xs font-bold uppercase tracking-wider text-rose-950">Diagnostic réglementaire & actions requises :</h4>
                    <div class="grid grid-cols-1 gap-3">
                        @foreach($fiche['anomalies_detaillees'] as $anom)
                            <div class="rounded-xl border border-rose-200/90 bg-white/90 p-4 text-xs shadow-2xs">
                                <div class="flex items-center justify-between gap-2 border-b border-rose-100 pb-2 mb-2">
                                    <div class="flex items-center gap-2">
                                        <span class="inline-flex items-center px-2 py-0.5 rounded text-[11px] font-bold {{ ($anom['type'] ?? '') === 'bloquant' ? 'bg-rose-100 text-rose-800' : 'bg-amber-100 text-amber-800' }}">
                                            {{ ($anom['type'] ?? '') === 'bloquant' ? '🔴 Bloquant' : '🟡 Avertissement' }}
                                        </span>
                                        <span class="font-bold text-slate-900 text-sm">{{ $anom['titre'] }}</span>
                                    </div>
                                    @if(($anom['action_type'] ?? '') === 'salle_d04' && !empty($resume['epreuve_id']))
                                        <a href="{{ route('admin.evaluation.examens.groupes', ['mode' => 'B', 'epreuve_id' => $resume['epreuve_id']]) }}"
                                           class="inline-flex items-center gap-1 rounded-lg bg-purple-600 hover:bg-purple-700 text-white font-bold px-2.5 py-1 text-[11px] shadow-2xs transition">
                                            🏛️ Ouvrir comparateur D-04
                                        </a>
                                    @endif
                                </div>
                                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-2 text-slate-700">
                                    <div>
                                        <span class="font-bold text-slate-900">1. Détection :</span>
                                        <p class="mt-0.5 text-slate-600">{{ $anom['detecte'] }}</p>
                                    </div>
                                    <div>
                                        <span class="font-bold text-slate-900">2. Règle concernée :</span>
                                        <p class="mt-0.5 text-slate-600">{{ $anom['regle'] }}</p>
                                    </div>
                                    <div>
                                        <span class="font-bold text-slate-900">3. Impact sur l'examen :</span>
                                        <p class="mt-0.5 text-slate-600">{{ $anom['impact'] }}</p>
                                    </div>
                                    <div>
                                        <span class="font-bold text-slate-900">4. Caractère :</span>
                                        <p class="mt-0.5 font-semibold {{ ($anom['type'] ?? '') === 'bloquant' ? 'text-rose-700' : 'text-amber-700' }}">
                                            {{ ($anom['type'] ?? '') === 'bloquant' ? 'Bloquant officiel' : 'Informatif' }}
                                        </p>
                                    </div>
                                    <div>
                                        <span class="font-bold text-slate-900">5. Action corrective autorisée :</span>
                                        <p class="mt-0.5 text-slate-600">{{ $anom['action_corrective'] }}</p>
                                    </div>
                                    <div>
                                        <span class="font-bold text-slate-900">6. Conditions de revalidation :</span>
                                        <p class="mt-0.5 text-slate-600">{{ $anom['conditions_revalidation'] }}</p>
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            @else
                <ul class="list-disc list-inside space-y-1 text-rose-900 font-medium">
                    @foreach($fiche['alertes'] as $alerte)
                        <li>{{ $alerte }}</li>
                    @endforeach
                </ul>
            @endif
        </div>
    @endif

    {{-- Panneau de diagnostic capacitaire D-04 et propositions de salles en lecture seule --}}
    @if(!empty($diagnosticSalle) && ($diagnosticSalle['salle_actuelle']['est_non_confirmee'] || !empty($diagnosticSalle['candidates_compatibles'])))
        <div class="rounded-2xl border-2 border-indigo-200 bg-indigo-50/60 p-5 text-xs text-indigo-950 shadow-xs space-y-4">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-indigo-200/80 pb-3">
                <div class="flex items-center gap-2">
                    <span class="inline-flex items-center gap-1.5 rounded-full bg-indigo-700 px-3 py-1 text-xs font-bold text-white shadow-2xs">
                        🏛️ Diagnostic capacitaire D-04 — Propositions en lecture seule
                    </span>
                    <span class="inline-flex rounded-full px-2.5 py-0.5 text-[11px] font-bold {{ $diagnosticSalle['salle_actuelle']['statut_capacite'] === 'validee' ? 'bg-emerald-100 text-emerald-900 border border-emerald-300' : 'bg-rose-100 text-rose-900 border border-rose-300' }}">
                        @if($diagnosticSalle['salle_actuelle']['statut_capacite'] === 'non_confirmee')
                            🔴 Capacité non confirmée
                        @elseif($diagnosticSalle['salle_actuelle']['statut_capacite'] === 'insuffisante')
                            🔴 Capacité insuffisante
                        @elseif($diagnosticSalle['salle_actuelle']['statut_capacite'] === 'non_affectee')
                            🟡 Salle non affectée
                        @else
                            🟢 Capacité confirmée
                        @endif
                    </span>
                </div>
                <div class="flex items-center gap-2 text-[11px] text-indigo-800 font-semibold bg-white/80 px-3 py-1 rounded-xl border border-indigo-200">
                    <span>🔒 Mode consultation stricte</span>
                    <span class="text-indigo-400">•</span>
                    <span>Planning inchangé</span>
                </div>
            </div>

            {{-- État de la salle actuelle et critères vérifiés --}}
            <div class="grid grid-cols-1 md:grid-cols-4 gap-3">
                <div class="rounded-xl border border-indigo-200 bg-white p-3 shadow-2xs">
                    <span class="text-[11px] font-bold text-indigo-700 uppercase tracking-wider">Salle actuelle</span>
                    <p class="mt-1 text-sm font-extrabold text-slate-900">{{ $diagnosticSalle['salle_actuelle']['code'] }}</p>
                    <p class="text-[11px] text-slate-500">{{ $diagnosticSalle['salle_actuelle']['nom'] }}</p>
                    <p class="mt-2 text-[11px] font-semibold {{ $diagnosticSalle['salle_actuelle']['est_non_confirmee'] ? 'text-rose-600' : 'text-emerald-700' }}">
                        {{ $diagnosticSalle['salle_actuelle']['motif_statut'] }}
                    </p>
                </div>

                <div class="rounded-xl border border-indigo-200 bg-white p-3 shadow-2xs">
                    <span class="text-[11px] font-bold text-indigo-700 uppercase tracking-wider">Capacité actuelle</span>
                    <div class="mt-1 flex items-baseline gap-2">
                        <span class="text-base font-extrabold text-slate-900">{{ $diagnosticSalle['salle_actuelle']['capacite_utile_d04'] }}</span>
                        <span class="text-[11px] text-slate-500">places utiles (nominale : {{ $diagnosticSalle['salle_actuelle']['capacite_nominale'] ?: 'Non renseignée' }})</span>
                    </div>
                    <p class="mt-2 text-[11px] text-slate-600">
                        Règle D-04 : ratio 50% appliqué sur capacité nominale certifiée.
                    </p>
                </div>

                <div class="rounded-xl border border-indigo-200 bg-white p-3 shadow-2xs">
                    <span class="text-[11px] font-bold text-indigo-700 uppercase tracking-wider">Effectif requis</span>
                    <p class="mt-1 text-base font-extrabold text-slate-900">{{ $diagnosticSalle['effectif_requis'] }} candidats</p>
                    <p class="text-[11px] text-slate-500">{{ $diagnosticSalle['candidats_eligibles_count'] }} étudiant(s) éligible(s) vérifié(s)</p>
                    <p class="mt-2 text-[11px] text-slate-600">
                        Créneau : {{ $diagnosticSalle['epreuve']['heure_debut'] ?? '—' }} → {{ $diagnosticSalle['epreuve']['heure_fin'] ?? '—' }} ({{ $diagnosticSalle['epreuve']['date'] ?? '—' }})
                    </p>
                </div>

                <div class="rounded-xl border border-indigo-200 bg-white p-3 shadow-2xs">
                    <span class="text-[11px] font-bold text-indigo-700 uppercase tracking-wider">Résultat du diagnostic</span>
                    <p class="mt-1 text-xs font-bold text-indigo-950">{{ $diagnosticSalle['motif_diagnostic'] }}</p>
                    <div class="mt-2 flex items-center gap-2">
                        <span class="inline-flex rounded-lg bg-indigo-100 text-indigo-900 px-2 py-0.5 text-[11px] font-bold">
                            {{ $diagnosticSalle['nb_candidates'] }} compatible(s)
                        </span>
                        <span class="inline-flex rounded-lg bg-slate-100 text-slate-700 px-2 py-0.5 text-[11px] font-bold">
                            {{ $diagnosticSalle['nb_exclues'] }} exclue(s)
                        </span>
                    </div>
                </div>
            </div>

            {{-- Liste des salles candidates compatibles --}}
            @if(!empty($diagnosticSalle['candidates_compatibles']))
                <div class="space-y-2">
                    <h4 class="text-xs font-bold uppercase tracking-wider text-indigo-950 flex items-center gap-1.5">
                        <span>✅ Salles compatibles détectées (capacité confirmée suffisante & sans conflit) :</span>
                    </h4>
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3">
                        @foreach($diagnosticSalle['candidates_compatibles'] as $cand)
                            <div class="rounded-xl border border-indigo-200 bg-white p-3 shadow-2xs hover:border-indigo-400 transition">
                                <div class="flex items-center justify-between gap-2 border-b border-indigo-100 pb-1.5 mb-1.5">
                                    <div class="flex items-center gap-1.5">
                                        <span class="font-bold text-slate-900 text-sm">{{ $cand['code'] }}</span>
                                        <span class="text-[10px] uppercase font-bold px-1.5 py-0.5 rounded {{ $cand['est_amphitheatre'] ? 'bg-purple-100 text-purple-800' : 'bg-blue-100 text-blue-800' }}">
                                            {{ $cand['est_amphitheatre'] ? 'Amphi' : 'Salle' }}
                                        </span>
                                    </div>
                                    <span class="text-[11px] font-bold text-emerald-700 bg-emerald-50 px-2 py-0.5 rounded-full border border-emerald-200">
                                        +{{ $cand['surplus_places'] }} places
                                    </span>
                                </div>
                                <p class="text-[11px] text-slate-600 truncate">{{ $cand['nom'] }} • {{ $cand['batiment'] }}</p>
                                <div class="mt-2 flex items-center justify-between text-[11px] text-slate-500">
                                    <span>Capacité utile D-04 : <strong class="text-slate-800">{{ $cand['capacite_utile_d04'] }}</strong></span>
                                    <span>Nominale : {{ $cand['capacite_nominale'] }}</span>
                                </div>
                                @can('epreuves.update')
                                    <div class="mt-3 pt-2 border-t border-slate-100 flex items-center justify-end">
                                        <form action="{{ route('admin.evaluation.examens.groupes.capacite.accepter') }}" method="POST" onsubmit="return confirm('Confirmez-vous l\'affectation officielle de la salle {{ $cand['code'] }} à cette épreuve ?');">
                                            @csrf
                                            <input type="hidden" name="epreuve_id" value="{{ $resume['epreuve_id'] }}">
                                            <input type="hidden" name="salle_ids[]" value="{{ $cand['id'] }}">
                                            <input type="hidden" name="type" value="affectation_salle_unique">
                                            <input type="hidden" name="motif" value="Affectation de la salle candidate {{ $cand['code'] }} (capacité utile de {{ $cand['capacite_utile_d04'] }} places) pour lever l'anomalie D-04.">
                                            <input type="hidden" name="empreinte" value="{{ $diagnosticSalle['empreinte'] ?? '' }}">
                                            <button type="submit" class="inline-flex items-center gap-1 rounded-lg bg-indigo-700 hover:bg-indigo-800 px-2.5 py-1 text-[11px] font-bold text-white shadow-2xs transition cursor-pointer">
                                                <span>Affecter cette salle</span>
                                                <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" />
                                                </svg>
                                            </button>
                                        </form>
                                    </div>
                                @endcan
                            </div>
                        @endforeach
                    </div>
                </div>
            @elseif($diagnosticSalle['diagnostic_global'] === 'donnees_insuffisantes')
                <div class="rounded-xl border border-amber-300 bg-amber-50 p-3 text-xs text-amber-950">
                    <p class="font-bold">⚠️ Données de planification insuffisantes :</p>
                    <p class="mt-0.5">{{ $diagnosticSalle['motif_diagnostic'] }}</p>
                </div>
            @else
                <div class="rounded-xl border border-rose-300 bg-rose-50 p-3 text-xs text-rose-950">
                    <p class="font-bold">⚠️ Aucune salle candidate compatible trouvée :</p>
                    <p class="mt-0.5">{{ $diagnosticSalle['motif_diagnostic'] }}</p>
                </div>
            @endif

            {{-- Accordéon des salles exclues et motifs --}}
            @if(!empty($diagnosticSalle['salles_exclues']))
                <details class="rounded-xl border border-indigo-200/90 bg-white/80 p-3 text-xs">
                    <summary class="font-bold text-indigo-950 cursor-pointer select-none flex items-center justify-between">
                        <span>🔍 Voir le détail des salles analysées et motifs d'exclusion ({{ $diagnosticSalle['nb_exclues'] }} salles)</span>
                        <span class="text-indigo-600 text-[11px]">Déplier / Replier</span>
                    </summary>
                    <div class="mt-3 grid grid-cols-1 md:grid-cols-2 gap-2 pt-2 border-t border-indigo-100">
                        @foreach($diagnosticSalle['salles_exclues'] as $exclue)
                            <div class="rounded-lg border border-slate-200 bg-slate-50/70 p-2.5 text-[11px]">
                                <div class="flex items-center justify-between">
                                    <span class="font-bold text-slate-900">{{ $exclue['code'] }} ({{ $exclue['nom'] }})</span>
                                    <span class="text-slate-500">Nominale : {{ $exclue['capacite_nominale'] ?: 'Inconnue' }}</span>
                                </div>
                                <ul class="mt-1 list-disc list-inside text-rose-700 font-medium space-y-0.5">
                                    @foreach($exclue['motifs_exclusion'] as $motif)
                                        <li>{{ $motif }}</li>
                                    @endforeach
                                </ul>
                            </div>
                        @endforeach
                    </div>
                </details>
            @endif

            {{-- Mention réglementaire et de sécurité lecture seule --}}
            <div class="rounded-xl border border-indigo-300 bg-indigo-100/70 p-3 text-[11px] text-indigo-950 flex items-start gap-2.5">
                <span class="text-base shrink-0">🛡️</span>
                <div>
                    <strong class="font-bold">Gouvernance officielle et intégrité du planning :</strong>
                    <p class="mt-0.5 text-indigo-900 leading-relaxed">
                        Ce diagnostic et ces propositions sont fournis <strong>exclusivement en lecture seule</strong> pour assister les équipes de scolarité. Conformément aux règles de gestion des examens UniRegistre, aucune réaffectation automatique n'est appliquée et l'export officiel reste suspendu tant qu'une décision formelle n'a pas été confirmée et validée par le circuit officiel.
                    </p>
                </div>
            </div>
        </div>
    @endif

    {{-- Barre d'actions d'export multi-formats et d'impression professionnelle --}}
    <div class="rounded-2xl border border-slate-200/80 bg-white p-4 shadow-xs flex flex-wrap items-center justify-between gap-3">
        <div>
            <h3 class="text-xs font-bold uppercase tracking-wider text-slate-700">Exports multi-formats & Impression A4</h3>
            <p class="text-[11px] text-slate-500">
                Même source de vérité et mêmes contrôles d'éligibilité pour l'écran, XLSX, CSV, PDF et l'impression.
            </p>
        </div>

        @if(! ($fiche['export_officiel_autorise'] ?? false))
            <div class="rounded-2xl border border-rose-200 bg-rose-50/90 p-4 text-xs text-rose-900 flex flex-wrap items-center justify-between gap-3 shadow-sm">
                <div class="flex items-center gap-3">
                    <div class="h-8 w-8 rounded-xl bg-rose-100 text-rose-700 flex items-center justify-center shrink-0 font-bold">
                        ⚠️
                    </div>
                    <div>
                        <p class="font-bold text-rose-800 text-sm">Génération de documents officiels verrouillée</p>
                        <p class="text-[11px] text-rose-700 leading-relaxed">
                            Ce groupe virtuel présente des anomalies bloquantes (incompatibilité pédagogique ou dérive de population). L'impression et l'export officiel sont strictement suspendus pour préserver l'intégrité de la session d'examen.
                        </p>
                    </div>
                </div>
                <div class="flex items-center gap-2">
                    <a href="{{ route('admin.evaluation.examens.groupes-virtuels.anomalies') }}"
                       class="inline-flex items-center gap-1 rounded-xl bg-rose-700 hover:bg-rose-800 px-3 py-1.5 text-xs font-bold text-white transition">
                        🛠️ Voir dans le Centre des anomalies
                    </a>
                </div>
            </div>
        @endif

        <div class="flex flex-wrap items-center gap-2">
            {{-- Export de la liste des étudiants --}}
            <a href="{{ route('admin.evaluation.examens.groupes-virtuels.groupe.exporter', [$resume['id'], 'xlsx', 'type' => 'etudiants']) }}"
               class="inline-flex items-center gap-1 rounded-xl border border-emerald-200 bg-emerald-50 hover:bg-emerald-100 px-3 py-1.5 text-xs font-bold text-emerald-900 transition">
                Étudiants XLSX
            </a>
            <a href="{{ route('admin.evaluation.examens.groupes-virtuels.groupe.exporter', [$resume['id'], 'csv', 'type' => 'etudiants']) }}"
               class="inline-flex items-center gap-1 rounded-xl border border-slate-200 bg-slate-50 hover:bg-slate-100 px-3 py-1.5 text-xs font-bold text-slate-800 transition">
                Étudiants CSV
            </a>
            <a href="{{ route('admin.evaluation.examens.groupes-virtuels.groupe.exporter', [$resume['id'], 'pdf', 'type' => 'etudiants']) }}"
               class="inline-flex items-center gap-1 rounded-xl border border-rose-200 bg-rose-50 hover:bg-rose-100 px-3 py-1.5 text-xs font-bold text-rose-900 transition">
                Étudiants PDF
            </a>
            <a href="{{ route('admin.evaluation.examens.groupes-virtuels.groupe.exporter', [$resume['id'], 'html', 'type' => 'etudiants']) }}"
               target="_blank"
               class="inline-flex items-center gap-1 rounded-xl border border-indigo-200 bg-indigo-50 hover:bg-indigo-100 px-3 py-1.5 text-xs font-bold text-indigo-900 transition">
                HTML Imprimable
            </a>

            <span class="text-slate-300">|</span>

            {{-- Export de la fiche du groupe --}}
            <a href="{{ route('admin.evaluation.examens.groupes-virtuels.groupe.exporter', [$resume['id'], 'xlsx', 'type' => 'fiche']) }}"
               class="inline-flex items-center gap-1 rounded-xl border border-slate-200 bg-white hover:bg-slate-50 px-2.5 py-1.5 text-xs font-semibold text-slate-700 transition">
                Fiche XLSX
            </a>
            <a href="{{ route('admin.evaluation.examens.groupes-virtuels.groupe.exporter', [$resume['id'], 'csv', 'type' => 'fiche']) }}"
               class="inline-flex items-center gap-1 rounded-xl border border-slate-200 bg-white hover:bg-slate-50 px-2.5 py-1.5 text-xs font-semibold text-slate-700 transition">
                Fiche CSV
            </a>

            <span class="text-slate-300">|</span>

            {{-- Impressions professionnelles dédiées --}}
            <a href="{{ route('admin.evaluation.examens.groupes-virtuels.groupe.imprimer', [$resume['id'], 'fiche']) }}"
               target="_blank"
               class="inline-flex items-center gap-1 rounded-xl bg-slate-800 hover:bg-slate-900 px-3 py-1.5 text-xs font-bold text-white transition">
                Imprimer fiche récapitulative
            </a>
            <a href="{{ route('admin.evaluation.examens.groupes-virtuels.groupe.imprimer', [$resume['id'], 'etudiants']) }}"
               target="_blank"
               class="inline-flex items-center gap-1 rounded-xl bg-indigo-600 hover:bg-indigo-700 px-3 py-1.5 text-xs font-bold text-white transition">
                Imprimer liste des étudiants
            </a>
            <a href="{{ route('admin.evaluation.examens.groupes-virtuels.groupe.imprimer', [$resume['id'], 'emargement']) }}"
               target="_blank"
               class="inline-flex items-center gap-1 rounded-xl bg-primary-700 hover:bg-primary-800 px-3 py-1.5 text-xs font-bold text-white transition">
                Imprimer feuille d'émargement
            </a>

            <span class="text-slate-300">|</span>

            {{-- Passerelle D-04 Répartition Capacitaire --}}
            <a href="{{ route('admin.evaluation.examens.groupes', ['mode' => 'B', 'epreuve_id' => $resume['epreuve_id']]) }}"
               title="Ouvrir la répartition capacitaire et les permutations D-04 Mode B"
               class="inline-flex items-center gap-1.5 rounded-xl border border-purple-300 bg-purple-50 hover:bg-purple-100 px-3 py-1.5 text-xs font-bold text-purple-900 transition">
                🏛️ Répartition Salles D-04
            </a>
        </div>
    </div>

    {{-- Métadonnées académiques & Effectifs séparés --}}
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-5">
        {{-- Contexte académique --}}
        <div class="lg:col-span-2 rounded-2xl border border-slate-200/80 bg-white p-5 shadow-xs">
            <h3 class="font-bold text-sm text-slate-900 mb-3">Périmètre académique et institutionnel</h3>
            <dl class="grid grid-cols-2 sm:grid-cols-3 gap-3 text-xs">
                <div class="rounded-xl bg-slate-50 p-3">
                    <dt class="text-slate-500 font-medium">Année & Session</dt>
                    <dd class="mt-0.5 font-bold text-slate-900">{{ $resume['annee_libelle'] }} — {{ $resume['session_code'] }} ({{ $resume['session_nom'] }})</dd>
                </div>
                <div class="rounded-xl bg-slate-50 p-3">
                    <dt class="text-slate-500 font-medium">Faculté</dt>
                    <dd class="mt-0.5 font-bold text-slate-900">{{ $resume['faculte_nom'] }} ({{ $resume['faculte_code'] }})</dd>
                </div>
                <div class="rounded-xl bg-slate-50 p-3">
                    <dt class="text-slate-500 font-medium">Programme & Niveau</dt>
                    <dd class="mt-0.5 font-bold text-slate-900">{{ $resume['programme_code'] }} ({{ $resume['programme_nom'] }}) · {{ $resume['niveau_code'] }}</dd>
                </div>
                <div class="rounded-xl bg-slate-50 p-3">
                    <dt class="text-slate-500 font-medium">Matière examinée</dt>
                    <dd class="mt-0.5 font-bold text-indigo-900">[{{ $resume['matiere_code'] }}] {{ $resume['matiere_nom'] }}</dd>
                </div>
                <div class="rounded-xl bg-slate-50 p-3">
                    <dt class="text-slate-500 font-medium">Épreuve & Créneau</dt>
                    <dd class="mt-0.5 font-bold text-slate-900">{{ $resume['epreuve_code'] }} · {{ $resume['epreuve_date'] }} ({{ $resume['epreuve_horaire'] }})</dd>
                </div>
                <div class="rounded-xl bg-slate-50 p-3">
                    <dt class="text-slate-500 font-medium">Identifiant & Clé</dt>
                    <dd class="mt-0.5 font-mono font-bold text-slate-800">#{{ $resume['id'] }} · {{ $resume['cle_courte'] }}…</dd>
                </div>
            </dl>
        </div>

        {{-- Effectifs séparés et explication des écarts --}}
        <div class="rounded-2xl border border-slate-200/80 bg-white p-5 shadow-xs space-y-3">
            <h3 class="font-bold text-sm text-slate-900">Bilan séparé des effectifs</h3>
            <div class="grid grid-cols-2 gap-2.5 text-xs">
                <div class="rounded-xl border border-slate-200 p-3">
                    <span class="text-[11px] font-semibold text-slate-500">Effectif groupes d'origine</span>
                    <div class="mt-0.5 text-xl font-black text-slate-900">{{ $effectifs['origine_total'] ?? 0 }}</div>
                </div>
                <div class="rounded-xl border border-blue-200 bg-blue-50/40 p-3">
                    <span class="text-[11px] font-semibold text-blue-800">Effectif attendu (référence)</span>
                    <div class="mt-0.5 text-xl font-black text-blue-950">{{ $effectifs['attendus_reference'] ?? 0 }}</div>
                </div>
                <div class="rounded-xl border border-emerald-200 bg-emerald-50/50 p-3">
                    <span class="text-[11px] font-semibold text-emerald-800">Effectif enregistré éligible</span>
                    <div class="mt-0.5 text-xl font-black text-emerald-950">{{ $effectifs['enregistres_eligibles'] ?? 0 }}</div>
                </div>
                <div class="rounded-xl border border-amber-200 bg-amber-50/40 p-3">
                    <span class="text-[11px] font-semibold text-amber-800">Exclusions motivées</span>
                    <div class="mt-0.5 text-xl font-black text-amber-950">{{ $effectifs['exclus_total'] ?? 0 }}</div>
                </div>
            </div>

            @if(!empty($fiche['explications_ecarts']))
                <div class="rounded-xl bg-slate-50 border border-slate-200/80 p-3 text-[11px] text-slate-700 space-y-1">
                    <div class="font-bold text-slate-900">Explication des différences d'effectifs :</div>
                    @foreach($fiche['explications_ecarts'] as $explication)
                        <p>• {{ $explication }}</p>
                    @endforeach
                </div>
            @endif
        </div>
    </div>

    {{-- Passerelle D-04 : Affectation Salles & Jauges --}}
    <div class="rounded-2xl border {{ !empty($resume['salle_code']) ? 'border-purple-200/80 bg-gradient-to-r from-purple-50/40 via-white to-indigo-50/30' : 'border-amber-200 bg-amber-50/50' }} p-5 shadow-xs">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div class="flex items-start gap-3.5">
                <div class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-xl {{ !empty($resume['salle_code']) ? 'bg-purple-600 text-white' : 'bg-amber-500 text-white' }} font-bold text-lg shadow-2xs">
                    🏛️
                </div>
                <div>
                    <div class="flex items-center gap-2">
                        <h3 class="font-bold text-sm text-slate-900">Capacitaire Salles et Amphis (D-04 Mode B)</h3>
                        @if(!empty($resume['salle_code']))
                            <span class="inline-flex items-center rounded-full bg-emerald-100 px-2 py-0.5 text-[10px] font-bold text-emerald-800">
                                Salle attribuée
                            </span>
                            @if(($resume['salle_capacite'] ?? 0) >= $resume['effectif_eligible'])
                                <span class="inline-flex items-center rounded-full bg-blue-100 px-2 py-0.5 text-[10px] font-bold text-blue-800">
                                    Jauge suffisante ({{ $resume['effectif_eligible'] }}/{{ $resume['salle_capacite'] }})
                                </span>
                            @else
                                <span class="inline-flex items-center rounded-full bg-rose-100 px-2 py-0.5 text-[10px] font-bold text-rose-800">
                                    Dépassement de jauge ({{ $resume['effectif_eligible'] }} > {{ $resume['salle_capacite'] }})
                                </span>
                            @endif
                        @else
                            <span class="inline-flex items-center rounded-full bg-amber-200 px-2 py-0.5 text-[10px] font-bold text-amber-900">
                                Aucune salle attribuée
                            </span>
                        @endif
                    </div>
                    <p class="mt-1 text-xs text-slate-600">
                        @if(!empty($resume['salle_code']))
                            Salle affectée : <strong class="font-mono text-purple-950">{{ $resume['salle_code'] }}</strong> ({{ $resume['salle_nom'] ?? 'Salle' }}) · Capacité utile : <strong class="text-slate-900">{{ $resume['salle_capacite'] ?? '—' }} places</strong> pour {{ $resume['effectif_eligible'] }} étudiant(s) éligible(s).
                        @else
                            Cette épreuve n'a pas encore de salle affectée. Utilisez le module D-04 pour répartir les étudiants en amphithéâtre ou combinaison de salles normales.
                        @endif
                    </p>
                </div>
            </div>
            <div class="flex items-center gap-2">
                <a href="{{ route('admin.evaluation.examens.groupes', ['mode' => 'B', 'epreuve_id' => $resume['epreuve_id']]) }}"
                   class="inline-flex items-center gap-1.5 rounded-xl bg-purple-700 hover:bg-purple-800 px-4 py-2 text-xs font-bold text-white shadow-xs transition cursor-pointer">
                    <span>Ouvrir la répartition D-04 (Mode B)</span>
                    <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5 21 12m0 0-7.5 7.5M21 12H3" />
                    </svg>
                </a>
            </div>
        </div>
    </div>

    {{-- Tableau des groupes académiques sources d'origine --}}
    <div class="rounded-2xl border border-slate-200/80 bg-white p-5 shadow-xs">
        <div class="flex items-center justify-between mb-3">
            <div>
                <h3 class="font-bold text-sm text-slate-900">Groupes académiques d'origine agrégés</h3>
                <p class="text-xs text-slate-500">Les groupes administratifs et pédagogiques sources ne subissent aucune modification.</p>
            </div>
            <span class="text-xs font-semibold text-slate-600">{{ count($fiche['groupes_sources_detail'] ?? []) }} groupe(s) source(s)</span>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-left text-xs">
                <thead>
                    <tr class="border-b border-slate-200 text-[11px] font-bold uppercase tracking-wider text-slate-500">
                        <th class="pb-2.5 pl-1">Code groupe</th>
                        <th class="pb-2.5">Nom du groupe source</th>
                        <th class="pb-2.5">Programme</th>
                        <th class="pb-2.5">Niveau</th>
                        <th class="pb-2.5 text-center">Effectif d'origine</th>
                        <th class="pb-2.5 text-center">Éligibles retenus</th>
                        <th class="pb-2.5 pr-1 text-center">Exclus (non inscrits / inéligibles)</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse($fiche['groupes_sources_detail'] ?? [] as $gs)
                        <tr>
                            <td class="py-2.5 pl-1 font-mono font-bold text-indigo-900">{{ $gs['code'] }}</td>
                            <td class="py-2.5 font-semibold text-slate-800">{{ $gs['nom'] }}</td>
                            <td class="py-2.5 font-mono text-slate-600">{{ $gs['programme'] }}</td>
                            <td class="py-2.5 font-mono text-slate-600">{{ $gs['niveau'] }}</td>
                            <td class="py-2.5 text-center font-bold text-slate-800">{{ $gs['effectif_origine'] }}</td>
                            <td class="py-2.5 text-center font-bold text-emerald-800">{{ $gs['eligibles_retenus'] }}</td>
                            <td class="py-2.5 pr-1 text-center font-semibold text-amber-800">{{ $gs['exclus'] }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="py-4 text-center text-slate-400">Aucun groupe source associé.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- Liste nominative des étudiants éligibles rattachés au groupe virtuel --}}
    <div class="rounded-2xl border border-slate-200/80 bg-white p-6 shadow-xs">
        <div class="flex items-center justify-between mb-4">
            <div>
                <h3 class="font-bold text-sm text-slate-900">
                    Liste des étudiants rattachés au groupe virtuel et éligibles à la matière ({{ count($fiche['etudiants_eligibles'] ?? []) }})
                </h3>
                <p class="text-xs text-slate-500 mt-0.5">
                    Seuls les étudiants disposant d'une inscription pédagogique active à la matière « {{ $resume['matiere_code'] }} — {{ $resume['matiere_nom'] }} » et déclarés éligibles figurent dans cette liste.
                </p>
            </div>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-left text-xs">
                <thead>
                    <tr class="border-b border-slate-200 text-[11px] font-bold uppercase tracking-wider text-slate-500">
                        <th class="pb-3 pl-1">N°</th>
                        <th class="pb-3">Numéro étudiant</th>
                        <th class="pb-3">Nom</th>
                        <th class="pb-3">Prénom</th>
                        <th class="pb-3">Faculté / Groupe d'origine</th>
                        <th class="pb-3">Matière</th>
                        <th class="pb-3">Statut d'éligibilité</th>
                        @if($peutVoirAnonyme)
                            <th class="pb-3 text-right pr-1">Numéro d'anonymat</th>
                        @endif
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse($fiche['etudiants_eligibles'] ?? [] as $etudiant)
                        <tr class="hover:bg-slate-50/60 transition">
                            <td class="py-3 pl-1 font-mono text-slate-500">{{ $etudiant['numero'] }}</td>
                            <td class="py-3 font-mono font-bold text-slate-900">{{ $etudiant['num_etudiant'] }}</td>
                            <td class="py-3 font-bold text-slate-900">{{ $etudiant['nom'] }}</td>
                            <td class="py-3 font-medium text-slate-800">{{ $etudiant['prenom'] }}</td>
                            <td class="py-3 text-slate-700">
                                <span class="font-semibold">{{ $etudiant['faculte_code'] }}</span>
                                ·
                                <span class="inline-flex items-center rounded bg-slate-100 px-1.5 py-0.5 font-mono text-[11px] font-semibold text-slate-800">
                                    {{ $etudiant['groupe_origine_code'] }}
                                </span>
                            </td>
                            <td class="py-3 text-slate-700 font-medium">{{ $etudiant['matiere'] }}</td>
                            <td class="py-3">
                                @if($etudiant['est_eligible_actuel'])
                                    <span class="inline-flex items-center rounded-full bg-emerald-100 px-2 py-0.5 text-[11px] font-bold text-emerald-800">
                                        {{ $etudiant['statut_eligibilite'] }}
                                    </span>
                                @else
                                    <span class="inline-flex items-center rounded-full bg-rose-100 px-2 py-0.5 text-[11px] font-bold text-rose-800">
                                        {{ $etudiant['statut_eligibilite'] }}
                                    </span>
                                @endif
                            </td>
                            @if($peutVoirAnonyme)
                                <td class="py-3 pr-1 text-right font-mono font-semibold text-indigo-900">
                                    {{ $etudiant['numero_anonyme'] ?? '—' }}
                                </td>
                            @endif
                        </tr>
                    @empty
                        <tr>
                            <td colspan="{{ $peutVoirAnonyme ? 8 : 7 }}" class="py-8 text-center text-sm text-slate-500">
                                Aucun étudiant éligible rattaché à ce groupe virtuel.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- Étudiants exclus et motifs métier --}}
    @if(!empty($fiche['etudiants_exclus']))
        <div class="rounded-2xl border border-amber-200/80 bg-white p-6 shadow-xs">
            <div class="flex items-center justify-between mb-4">
                <div>
                    <h3 class="font-bold text-sm text-amber-950">
                        Étudiants des groupes sources exclus de l'épreuve ({{ count($fiche['etudiants_exclus']) }})
                    </h3>
                    <p class="text-xs text-slate-500 mt-0.5">
                        Traçabilité explicable des exclusions individuelles calculées par ServiceEligibiliteExamen.
                    </p>
                </div>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-left text-xs">
                    <thead>
                        <tr class="border-b border-slate-200 text-[11px] font-bold uppercase tracking-wider text-slate-500">
                            <th class="pb-2.5 pl-1">Numéro étudiant</th>
                            <th class="pb-2.5">Nom & Prénom</th>
                            <th class="pb-2.5">Groupe d'origine</th>
                            <th class="pb-2.5">Règle appliquée</th>
                            <th class="pb-2.5 pr-1">Motif métier d'exclusion</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach($fiche['etudiants_exclus'] as $exclu)
                            <tr>
                                <td class="py-2.5 pl-1 font-mono font-bold text-slate-800">{{ $exclu['num_etudiant'] }}</td>
                                <td class="py-2.5 font-semibold text-slate-900">{{ $exclu['nom'] }} {{ $exclu['prenom'] }}</td>
                                <td class="py-2.5 font-mono text-slate-700">{{ $exclu['groupe_origine_code'] }}</td>
                                <td class="py-2.5 font-mono text-amber-800">{{ $exclu['regle'] }}</td>
                                <td class="py-2.5 pr-1 text-slate-700">{{ $exclu['motif'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif
</div>
@endsection
