<?php

namespace App\Modules\SimulationTemporelle\Support;

use App\Models\AnneeUniversitaire;
use App\Models\Faculte;
use App\Modules\Referentiel\Models\Niveau;
use App\Modules\Schedule\Models\Group;
use Illuminate\Support\Facades\DB;

/**
 * Contrôleur de Qualité des Données pour la Simulation Temporelle (Lot 1).
 *
 * RÈGLES DU MANDAT :
 * - Détection préventive et explicable sans altérer les sources ;
 * - Distingue strictement zéro effectif réel, donnée absente, source indisponible, relation manquante ;
 * - Aucun champ nominatif (zéro PII) dans le rapport d'anomalies ;
 * - Codes stables, sévérités typées, impacts et actions recommandées.
 */
final class AuditeurQualiteDonnees
{
    public const GRAVITE_CRITIQUE = 'critique';

    public const GRAVITE_AVERTISSEMENT = 'avertissement';

    public const GRAVITE_INFO = 'info';

    /**
     * Audite la qualité des données sur un périmètre donné.
     *
     * @return array{
     *   conforme: bool,
     *   score_qualite: float,
     *   anomalies: array<int, array{code: string, gravite: string, description: string, perimetre: string, impact: string, action_recommandee: string}>,
     *   statistiques: array<string, mixed>
     * }
     */
    public function auditer(?int $faculteId, int $anneeId, ?int $niveauId = null, ?array $groupesIds = null): array
    {
        $anomalies = [];

        // 1. Contrôle année universitaire
        $annee = AnneeUniversitaire::find($anneeId);
        if ($annee === null) {
            $anomalies[] = [
                'code' => 'QUAL_ANNEE_INEXISTANTE',
                'gravite' => self::GRAVITE_CRITIQUE,
                'description' => "L'année universitaire #{$anneeId} est introuvable dans le référentiel.",
                'perimetre' => "Année #{$anneeId}",
                'impact' => 'Impossibilité absolue d\'extraire un état initial fiable.',
                'action_recommandee' => 'Sélectionner une année universitaire active enregistrée.',
            ];
        }

        // 2. Contrôle faculté
        if ($faculteId !== null) {
            $faculte = Faculte::find($faculteId);
            if ($faculte === null) {
                $anomalies[] = [
                    'code' => 'QUAL_FACULTE_INEXISTANTE',
                    'gravite' => self::GRAVITE_CRITIQUE,
                    'description' => "La faculté #{$faculteId} spécifiée n'existe pas.",
                    'perimetre' => "Faculté #{$faculteId}",
                    'impact' => 'Périmètre géographique et académique non résolu.',
                    'action_recommandee' => 'Choisir une faculté valide parmi les facultés actives.',
                ];
            } elseif (! $faculte->active) {
                $anomalies[] = [
                    'code' => 'QUAL_FACULTE_INACTIVE',
                    'gravite' => self::GRAVITE_AVERTISSEMENT,
                    'description' => "La faculté {$faculte->nom} est marquée comme inactive.",
                    'perimetre' => "Faculté {$faculte->code}",
                    'impact' => 'Les cohortes projetées concernent une structure inactive.',
                    'action_recommandee' => 'Vérifier si la faculté doit faire l\'objet de simulations prospectives.',
                ];
            }
        }

        // 3. Contrôle niveau
        if ($niveauId !== null) {
            $niveau = Niveau::with('programme')->find($niveauId);
            if ($niveau === null) {
                $anomalies[] = [
                    'code' => 'QUAL_NIVEAU_INEXISTANT',
                    'gravite' => self::GRAVITE_CRITIQUE,
                    'description' => "Le niveau d'études #{$niveauId} n'existe pas.",
                    'perimetre' => "Niveau #{$niveauId}",
                    'impact' => 'Filtre de cohorte inapplicable.',
                    'action_recommandee' => 'Sélectionner un niveau LMD présent dans le référentiel.',
                ];
            } elseif ($niveau->programme === null) {
                $anomalies[] = [
                    'code' => 'QUAL_NIVEAU_SANS_PROGRAMME',
                    'gravite' => self::GRAVITE_AVERTISSEMENT,
                    'description' => "Le niveau {$niveau->code} n'est rattaché à aucun programme académique.",
                    'perimetre' => "Niveau {$niveau->code}",
                    'impact' => 'Perte de traçabilité sur la maquette pédagogique du cursus.',
                    'action_recommandee' => 'Rattacher le niveau à un programme valide dans le référentiel.',
                ];
            } elseif ($faculteId !== null && $niveau->programme->faculte_id !== null && (int) $niveau->programme->faculte_id !== $faculteId) {
                $anomalies[] = [
                    'code' => 'QUAL_NIVEAU_HORS_FACULTE',
                    'gravite' => self::GRAVITE_CRITIQUE,
                    'description' => "Le niveau {$niveau->code} appartient à une faculté distincte du périmètre choisi.",
                    'perimetre' => "Niveau {$niveau->code} / Faculté #{$faculteId}",
                    'impact' => 'Incohérence hiérarchique de périmètre.',
                    'action_recommandee' => 'Aligner le niveau sélectionné sur la faculté du scénario.',
                ];
            }
        }

        // 4. Contrôle groupes
        if ($groupesIds !== null && $groupesIds !== []) {
            if ($niveauId === null) {
                $anomalies[] = [
                    'code' => 'QUAL_GROUPES_SANS_NIVEAU',
                    'gravite' => self::GRAVITE_CRITIQUE,
                    'description' => 'Des groupes ont été sélectionnés sans spécifier de niveau LMD parent.',
                    'perimetre' => 'Cascade niveau -> groupes',
                    'impact' => 'Rupture de la cascade réglementaire.',
                    'action_recommandee' => 'Sélectionner le niveau LMD avant de filtrer par groupe.',
                ];
            } else {
                $groupes = Group::whereIn('id', $groupesIds)->get();
                foreach ($groupes as $grp) {
                    if (! $grp->actif) {
                        $anomalies[] = [
                            'code' => 'QUAL_GROUPE_INACTIF',
                            'gravite' => self::GRAVITE_AVERTISSEMENT,
                            'description' => "Le groupe {$grp->code} sélectionné est inactif.",
                            'perimetre' => "Groupe {$grp->code}",
                            'impact' => 'Simulation sur des capacités de groupe closes.',
                            'action_recommandee' => 'Retirer les groupes inactifs de la sélection.',
                        ];
                    }
                    if ($grp->niveau_id === null || (int) $grp->niveau_id !== $niveauId) {
                        $anomalies[] = [
                            'code' => 'QUAL_GROUPE_NIVEAU_INCOHERENT',
                            'gravite' => self::GRAVITE_CRITIQUE,
                            'description' => "Le groupe {$grp->code} n'est pas rattaché au niveau #{$niveauId}.",
                            'perimetre' => "Groupe {$grp->code}",
                            'impact' => 'Association incohérente groupe/niveau.',
                            'action_recommandee' => 'Ne sélectionner que les groupes rattachés au niveau choisi.',
                        ];
                    }
                }
            }
        }

        // 5. Contrôle effectifs et cohérence en base (sans PII)
        $statistiques = $this->collecterStatistiques($faculteId, $anneeId, $niveauId);

        if ($statistiques['total_inscriptions'] === 0) {
            $anomalies[] = [
                'code' => 'QUAL_EFFECTIFS_ABSENTS',
                'gravite' => self::GRAVITE_CRITIQUE,
                'description' => 'Aucune inscription validée n\'existe sur ce périmètre pour l\'année sélectionnée.',
                'perimetre' => 'Inscriptions académiques',
                'impact' => 'État initial impossible à construire sans donnée réelle.',
                'action_recommandee' => 'Sélectionner un périmètre ou une année comportant des inscriptions validées.',
            ];
        }

        if ($statistiques['inscriptions_sans_niveau'] > 0) {
            $anomalies[] = [
                'code' => 'QUAL_INSC_SANS_NIVEAU',
                'gravite' => self::GRAVITE_AVERTISSEMENT,
                'description' => "{$statistiques['inscriptions_sans_niveau']} inscription(s) n'ont ni niveau LMD textuel ni clé niveau_id.",
                'perimetre' => 'Inscriptions orphelines',
                'impact' => 'Ces étudiants ne peuvent être agrégés dans aucune cohorte LMD.',
                'action_recommandee' => 'Associer un niveau académique à toutes les inscriptions validées.',
            ];
        }

        // Calcul score qualité global [0..1]
        $critiques = count(array_filter($anomalies, fn ($a) => $a['gravite'] === self::GRAVITE_CRITIQUE));
        $avertissements = count(array_filter($anomalies, fn ($a) => $a['gravite'] === self::GRAVITE_AVERTISSEMENT));

        $scoreQualite = max(0.0, round(1.0 - ($critiques * 0.4) - ($avertissements * 0.1), 2));
        $conforme = $critiques === 0;

        return [
            'conforme' => $conforme,
            'score_qualite' => $scoreQualite,
            'anomalies' => $anomalies,
            'statistiques' => $statistiques,
        ];
    }

    /**
     * Collecte d'agrégats anonymes de contrôle de qualité.
     *
     * @return array<string, int>
     */
    private function collecterStatistiques(?int $faculteId, int $anneeId, ?int $niveauId): array
    {
        $base = DB::table('inscriptions_academiques')
            ->where('inscriptions_academiques.annee_universitaire_id', $anneeId)
            ->whereIn('inscriptions_academiques.statut', ['validee', 'valide']);

        if ($faculteId !== null) {
            $base->where(function ($q) use ($faculteId) {
                $q->whereExists(function ($sub) use ($faculteId) {
                    $sub->select(DB::raw(1))
                        ->from('programmes')
                        ->whereColumn('programmes.id', 'inscriptions_academiques.programme_id')
                        ->where('programmes.faculte_id', $faculteId);
                })->orWhereExists(function ($sub) use ($faculteId) {
                    $sub->select(DB::raw(1))
                        ->from('etudiants')
                        ->whereColumn('etudiants.id', 'inscriptions_academiques.etudiant_id')
                        ->where('etudiants.faculte_id', $faculteId);
                });
            });
        }

        $total = (clone $base)->count();

        $sansNiveau = (clone $base)
            ->whereNull('inscriptions_academiques.niveau_id')
            ->where(function ($q) {
                $q->whereNull('inscriptions_academiques.niveau')
                    ->orWhere('inscriptions_academiques.niveau', '');
            })
            ->count();

        return [
            'total_inscriptions' => $total,
            'inscriptions_sans_niveau' => $sansNiveau,
        ];
    }
}
