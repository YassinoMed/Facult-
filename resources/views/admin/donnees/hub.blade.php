@extends('layouts.app')

@section('title', 'Pipeline d’Import / Export LMD — Scolarité')

@section('content')
    <div class="mx-auto max-w-7xl px-4 sm:px-6 py-8">
        <x-page-header titre="Centre d’Import / Export LMD" description="Pipeline unifié et déterministe pour tous les référentiels académiques et administratifs, respectant strictement l'ordre de dépendances LMD."
            :fil="['Tableau de bord' => route('admin.dashboard'), 'Données' => '#']" />

        <!-- Graphe de dépendances info banner -->
        <div class="mt-4 p-4 rounded-2xl bg-primary-50 border border-primary-200 text-primary-950 text-xs flex items-center justify-between gap-4">
            <div>
                <span class="font-bold">Graphe de dépendances LMD :</span>
                <span class="text-primary-800">Année → Faculté → Département → Domaine → Mention → Spécialité → Parcours → Niveau → Semestre → UE → ECUE → Enseignants / Salles / Groupes / Étudiants → Inscriptions → Enseignements → Affectations → Examens → Notes → Délibérations.</span>
            </div>
            <a href="{{ route('admin.donnees.historique') }}" class="font-bold underline whitespace-nowrap text-primary-900 hover:text-primary-950">Historique des imports →</a>
        </div>

        @php
            $groupesLmd = [
                '1. Socle Institutionnel & Temporel' => [
                    ['annees-universitaires', 'Années universitaires', 'Code AAAA-AAAA, dates, statut courante'],
                    ['facultes', 'Facultés / Établissements', 'Code, nom, sigle, coordonnées'],
                    ['departements', 'Départements', 'Code, faculté, nom, responsable'],
                ],
                '2. Référentiel Académique LMD' => [
                    ['domaines', 'Domaines de formation', 'Code, faculté, nom, sigle'],
                    ['mentions', 'Mentions', 'Code, domaine, nom, sigle'],
                    ['specialites', 'Spécialités', 'Code, mention, nom'],
                    ['type-diplomes', 'Types de diplôme', 'Code (Licence, Master...), nom, ordre'],
                    ['programmes', 'Parcours / Programmes', 'Code, faculté, département, spécialité'],
                    ['niveaux', 'Niveaux d’études', 'Code (L1, L2...), programme, ordre'],
                    ['semestres', 'Semestres LMD', 'Code (S1, S2...), niveau, programme'],
                    ['ues', 'Unités d’enseignement (UE)', 'Code, semestre, crédits, coefficient'],
                    ['ecues', 'ECUE', 'Code, UE de rattachement, volumes CM/TD/TP'],
                    ['matieres', 'Matières standalone', 'Code, nom, niveau, semestre'],
                ],
                '3. Ressources d’Enseignement' => [
                    ['enseignants', 'Enseignants', 'Nom, prénom, email, grade, faculté'],
                    ['batiments', 'Bâtiments', 'Code, nom, campus, faculté'],
                    ['salles', 'Salles', 'Code, capacité, bâtiment, étage'],
                    ['groupes', 'Groupes', 'Code, faculté, niveau, capacité, année'],
                ],
                '4. Population & Scolarité' => [
                    ['etudiants', 'Étudiants', 'CIN, nom, prénom, email, bac, coordonnées'],
                    ['inscriptions-academiques', 'Inscriptions académiques', 'Matricule/CIN, année, filière, niveau'],
                    ['inscriptions-pedagogiques', 'Inscriptions pédagogiques', 'Matricule, ECUE, année universitaire'],
                    ['referentiel-mens', 'Référentiel MENS', 'Réconciliation des données nationales'],
                ],
                '5. Enseignements & Charges' => [
                    ['enseignements', 'Enseignements réels', 'ECUE, type de cours, année, volumes'],
                    ['affectations-enseignants', 'Affectations enseignants', 'Enseignement, enseignant, rôle, volume'],
                    ['affectations-groupes', 'Affectations groupes', 'Enseignement, groupe assigné'],
                    ['affectations-salles', 'Affectations salles', 'Enseignement, salle de cours'],
                    ['emplois-du-temps', 'Emplois du temps', 'Créneaux, jour, heures, enseignant, salle'],
                ],
                '6. Évaluations & Examens' => [
                    ['sessions-examen', 'Sessions d’examen', 'Code, année, type, dates, statut'],
                    ['evaluations', 'Évaluations', 'Code, enseignement, type, coefficient, date'],
                    ['examens', 'Examens planifiés', 'Session, libellé, programme, niveau, type'],
                    ['epreuves', 'Épreuves d’examen', 'Examen, session, ECUE, date, salle, groupe'],
                    ['notes', 'Notes officielles', 'Étudiant, matière, semestre, note, session'],
                ],
                '7. Délibérations & Décisions de Jury' => [
                    ['deliberations', 'Délibérations', 'Session, parcours, niveau, date délibération'],
                    ['decisions-jury', 'Décisions de jury', 'Délibération, étudiant, verdict, moyenne, crédits'],
                    ['progressions', 'Progressions académiques', 'Étudiant, passage niveau supérieur, année cible'],
                    ['documents-academiques', 'Documents académiques', 'Relevés, attestations, statut'],
                ],
                '8. Services Transverses' => [
                    ['annonces', 'Annonces', 'Titre, audience, priorité, publication'],
                    ['factures', 'Factures / Tarifs', 'Étudiant, montant, état'],
                    ['anomalies_qualite', 'Anomalies de qualité', 'Audit des données'],
                ],
            ];
        @endphp

        <div class="mt-6 space-y-8">
            @foreach($groupesLmd as $titreGroupe => $entitesGroupe)
                <div>
                    <h2 class="text-sm font-black text-slate-800 tracking-wide uppercase flex items-center gap-2">
                        <span class="inline-block w-2.5 h-2.5 rounded-full bg-primary-700"></span>
                        {{ $titreGroupe }}
                    </h2>
                    <div class="mt-3 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-3">
                        @foreach($entitesGroupe as [$entite, $libelle, $detail])
                            @php
                                $etat = $etats[$entite] ?? ['prete' => true, 'dependances' => [], 'manquantes' => []];
                                $estPrete = $etat['prete'];
                            @endphp
                            <section aria-label="{{ $libelle }}" class="ds-card p-4 flex flex-col justify-between border {{ $estPrete ? 'border-slate-200' : 'border-amber-200 bg-amber-50/20' }}">
                                <div>
                                    <div class="flex items-start justify-between gap-2">
                                        <h3 class="text-sm font-black text-slate-900">{{ $libelle }}</h3>
                                        @if($estPrete)
                                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold bg-emerald-100 text-emerald-800">
                                                ✓ Prêt
                                            </span>
                                        @else
                                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold bg-amber-100 text-amber-800" title="{{ implode(', ', $etat['manquantes']) }}">
                                                ⏳ Prérequis
                                            </span>
                                        @endif
                                    </div>
                                    <p class="text-[11px] text-slate-500 mt-1">{{ $detail }}</p>

                                    @if(! $estPrete && count($etat['manquantes']) > 0)
                                        <div class="mt-2 p-2 rounded-lg bg-amber-100/70 border border-amber-300 text-[10px] text-amber-900">
                                            <span class="font-bold">À importer d’abord :</span>
                                            {{ implode(', ', array_keys($etat['manquantes'])) }}
                                        </div>
                                    @endif
                                </div>

                                <div class="mt-4 pt-3 border-t border-slate-100">
                                    <div class="flex flex-wrap gap-1.5 text-xs font-bold">
                                        @if(in_array($entite, $exportables, true))
                                        <a href="{{ route('admin.donnees.voir', $entite) }}" 
                                           class="rounded-lg border border-sky-300 bg-sky-50 text-sky-800 hover:bg-sky-100 px-2.5 py-1.5 min-h-[36px] inline-flex items-center gap-1 text-xs font-bold transition" 
                                           title="Vérifier les données en base">
                                            <svg class="w-3.5 h-3.5 text-sky-600" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z" />
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                            </svg>
                                            <span>Voir</span>
                                        </a>
                                        @endif
                                        @if(in_array($entite, $entites, true))
                                            <a href="{{ route('admin.donnees.import.form', $entite) }}" class="rounded-lg bg-primary-800 px-3 py-1.5 text-white hover:bg-primary-700 min-h-[36px] inline-flex items-center text-xs">Importer</a>
                                            <a href="{{ route('admin.donnees.modele', [$entite, 'csv']) }}" class="rounded-lg border border-slate-300 px-2.5 py-1.5 text-slate-700 hover:bg-slate-50 min-h-[36px] inline-flex items-center text-xs" title="Modèle CSV">Modèle</a>
                                        @endif
                                        @if(in_array($entite, $exportables, true))
                                        <a href="{{ route('admin.donnees.export', [$entite, 'csv']) }}" class="rounded-lg border border-slate-300 px-2 py-1.5 text-slate-700 hover:bg-slate-50 min-h-[36px] inline-flex items-center text-xs">CSV</a>
                                        <a href="{{ route('admin.donnees.export', [$entite, 'xlsx']) }}" class="rounded-lg border border-slate-300 px-2 py-1.5 text-slate-700 hover:bg-slate-50 min-h-[36px] inline-flex items-center text-xs">XLSX</a>
                                        <a href="{{ route('admin.donnees.export', [$entite, 'json']) }}" class="rounded-lg border border-slate-300 px-2 py-1.5 text-slate-700 hover:bg-slate-50 min-h-[36px] inline-flex items-center text-xs">JSON</a>
                                        @endif
                                    </div>
                                </div>
                            </section>
                        @endforeach
                    </div>
                </div>
            @endforeach
        </div>
    </div>
@endsection
