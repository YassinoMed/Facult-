<?php

namespace App\Services;

use App\Models\DossierCorrection;
use App\Models\DossierVerification;
use App\Models\Etudiant;
use App\Modules\StudentDossiers\Models\StudentDocument;
use App\Modules\StudentDossiers\Models\StudentDossier;

/**
 * Service de suivi du dossier étudiant (LOOP 10) :
 * - État réel du dossier sans déclarer validé prématurément ;
 * - Chronologie des étapes : création, dépôt, vérification, demande de complément, décision ;
 * - Détection des actions requises et pièces à corriger ;
 * - Recherche sécurisée par identifiant public + second facteur sans énumération.
 */
class SuiviDossierService
{
    /**
     * Libellé humain compréhensible du statut du dossier.
     */
    public function libelleStatut(string $statut, bool $aDesCorrections = false): string
    {
        if ($aDesCorrections) {
            return 'Complément d\'information requis';
        }

        return match ($statut) {
            'en_attente' => 'En attente de validation académique',
            'valide' => 'Inscription validée',
            'rejete' => 'Dossier non retenu',
            default => 'En cours d\'instruction',
        };
    }

    /**
     * Synthèse complète du suivi pour un étudiant authentifié ou validé.
     *
     * @return array<string, mixed>
     */
    public function synthese(Etudiant $etudiant): array
    {
        $corrections = $this->recupererCorrectionsDemandees($etudiant);
        $piecesRejetees = $this->recupererPiecesRejetees($etudiant);
        $aDesCorrections = count($corrections) > 0 || count($piecesRejetees) > 0;

        $chronologie = $this->construireChronologie($etudiant, $corrections, $piecesRejetees);
        $prochaineAction = $this->determinerProchaineAction($etudiant, $corrections, $piecesRejetees);

        $derniereMaj = $etudiant->updated_at ?? $etudiant->created_at ?? now();

        return [
            'reference' => $etudiant->num_inscription,
            'statut_brut' => $etudiant->statut,
            'statut_libelle' => $this->libelleStatut($etudiant->statut, $aDesCorrections),
            'statut_badge_class' => $this->classesBadge($etudiant->statut, $aDesCorrections),
            'derniere_mise_a_jour' => $derniereMaj->format('d/m/Y à H:i'),
            'derniere_mise_a_jour_diff' => $derniereMaj->diffForHumans(),
            'date_depot' => $etudiant->created_at ? $etudiant->created_at->format('d/m/Y à H:i') : '—',
            'faculte_nom' => $etudiant->faculte?->nom ?? 'Faculté universitaire',
            'programme_nom' => $etudiant->programmeRef?->nom ?? 'Formation universitaire',
            'niveau' => $etudiant->niveau,
            'annee_academique' => $etudiant->annee_academique,
            'etudiant' => [
                'nom_complet' => $etudiant->nom_complet,
                'nom' => $etudiant->nom,
                'prenom' => $etudiant->prenom,
                'email_masque' => $etudiant->email_masque,
                'cin_masquee' => $etudiant->cin_masquee,
            ],
            'a_des_corrections' => $aDesCorrections,
            'corrections_demandees' => $corrections,
            'pieces_a_corriger' => $piecesRejetees,
            'prochaine_action' => $prochaineAction,
            'chronologie' => $chronologie,
            'groupe' => $this->resumerGroupe($etudiant),
        ];
    }

    /**
     * Résumé du groupe attribué (LOOP 14) : exposé UNIQUEMENT après
     * confirmation de l'écriture en base (zéro affectation anticipée).
     *
     * @return array{id: ?int, code: ?string, nom: ?string, statut: string, message: string}|null
     */
    public function resumerGroupe(Etudiant $etudiant): ?array
    {
        $etudiant->loadMissing('groupe');
        $groupe = $etudiant->groupe;

        if ($groupe !== null) {
            return [
                'id' => $groupe->id,
                'code' => $groupe->code,
                'nom' => $groupe->nom ?? $groupe->code,
                'statut' => 'affecte',
                'message' => "Groupe pédagogique attribué : {$groupe->code}.",
            ];
        }

        if ($etudiant->statut === 'valide') {
            return [
                'id' => null,
                'code' => null,
                'nom' => null,
                'statut' => 'en_attente',
                'message' => 'Dossier validé — En attente d’attribution de groupe par la scolarité.',
            ];
        }

        return null;
    }

    /**
     * Recherche publique sécurisée à double facteur (anti-énumération).
     *
     * Retourne null si aucun dossier ne correspond exactement au couple
     * (numéro d'inscription + CIN ou date de naissance).
     *
     * @return array<string, mixed>|null
     */
    public function rechercherPublic(string $numInscription, string $identifiantSecondaire): ?array
    {
        $num = strtoupper(trim($numInscription));
        $secondaire = strtoupper(preg_replace('/\s+/', '', trim($identifiantSecondaire)) ?? '');

        if ($num === '' || $secondaire === '') {
            return null;
        }

        // Format date normalisé éventuel : DD/MM/YYYY vers YYYY-MM-DD
        $dateNormalisee = null;
        if (preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/', $secondaire, $matches)) {
            $dateNormalisee = "{$matches[3]}-{$matches[2]}-{$matches[1]}";
        } elseif (preg_match('/^\d{4}-\d{2}-\d{2}$/', $secondaire)) {
            $dateNormalisee = $secondaire;
        }

        $etudiant = Etudiant::with(['faculte', 'programmeRef'])
            ->where('num_inscription', $num)
            ->where(function ($query) use ($secondaire, $dateNormalisee) {
                $query->whereRaw("REPLACE(UPPER(TRIM(cin)), ' ', '') = ?", [$secondaire]);
                if ($dateNormalisee !== null) {
                    $query->orWhereDate('date_naissance', $dateNormalisee);
                }
            })
            ->first();

        if ($etudiant === null) {
            return null;
        }

        return $this->synthese($etudiant);
    }

    /**
     * Construit la chronologie d'instruction du dossier.
     *
     * @param  array<int, array<string, mixed>>  $corrections
     * @param  array<int, array<string, mixed>>  $piecesRejetees
     * @return array<int, array<string, mixed>>
     */
    public function construireChronologie(Etudiant $etudiant, array $corrections, array $piecesRejetees): array
    {
        $etapes = [];

        // 1. Initialisation & Dépôt
        $etapes[] = [
            'cle' => 'depot',
            'titre' => 'Dépôt de la candidature',
            'description' => 'Dossier enregistré sous la référence '.$etudiant->num_inscription,
            'date' => $etudiant->created_at ? $etudiant->created_at->format('d/m/Y H:i') : null,
            'statut' => 'complete',
            'icone' => 'depot',
        ];

        // 2. Vérification administrative
        $verifExiste = DossierVerification::where('etudiant_id', $etudiant->id)->exists();
        $estTraite = in_array($etudiant->statut, ['valide', 'rejete'], true);
        $aDesCorrections = count($corrections) > 0 || count($piecesRejetees) > 0;

        $verifStatut = ($verifExiste || $estTraite || $aDesCorrections) ? 'complete' : 'en_cours';
        $etapes[] = [
            'cle' => 'verification',
            'titre' => 'Examen administratif',
            'description' => $verifStatut === 'complete'
                ? 'Contrôle de conformité et des prérequis effectué par la scolarité.'
                : 'Examen de vos pièces et vérification d\'éligibilité en cours.',
            'date' => $verifStatut === 'complete' ? ($etudiant->updated_at?->format('d/m/Y H:i')) : null,
            'statut' => $verifStatut,
            'icone' => 'verification',
        ];

        // 3. Demande de complément (si requise ou historique)
        if ($aDesCorrections) {
            $total = count($corrections) + count($piecesRejetees);
            $etapes[] = [
                'cle' => 'complement',
                'titre' => 'Demande de complément',
                'description' => "{$total} élément(s) signalé(s) nécessitant votre intervention.",
                'date' => $etudiant->updated_at ? $etudiant->updated_at->format('d/m/Y H:i') : null,
                'statut' => 'action_requise',
                'icone' => 'alerte',
            ];
        } else {
            $anciennesCorrections = DossierCorrection::where('etudiant_id', $etudiant->id)
                ->whereIn('statut', ['appliquee', 'approuvee'])
                ->exists();

            if ($anciennesCorrections) {
                $etapes[] = [
                    'cle' => 'complement',
                    'titre' => 'Complément traité',
                    'description' => 'Les corrections apportées ont été validées par la scolarité.',
                    'date' => $etudiant->updated_at ? $etudiant->updated_at->format('d/m/Y H:i') : null,
                    'statut' => 'complete',
                    'icone' => 'check',
                ];
            }
        }

        // 4. Instruction académique
        $instructionStatut = 'a_venir';
        if ($estTraite) {
            $instructionStatut = 'complete';
        } elseif ($aDesCorrections) {
            $instructionStatut = 'suspendue';
        } elseif ($verifStatut === 'complete') {
            $instructionStatut = 'en_cours';
        }

        $etapes[] = [
            'cle' => 'instruction',
            'titre' => 'Instruction pédagogique',
            'description' => match ($instructionStatut) {
                'complete' => 'Instruction clôturée par le jury d\'admission.',
                'suspendue' => 'Instruction suspendue dans l\'attente de vos pièces justificatives.',
                'en_cours' => 'Étude de l\'orientation et des capacités d\'accueil en cours.',
                default => 'En attente de finalisation de l\'examen administratif.',
            },
            'date' => $instructionStatut === 'complete' ? ($etudiant->updated_at?->format('d/m/Y H:i')) : null,
            'statut' => $instructionStatut,
            'icone' => 'instruction',
        ];

        // 5. Décision finale
        $decisionStatut = match ($etudiant->statut) {
            'valide' => 'valide',
            'rejete' => 'rejete',
            default => 'a_venir',
        };

        $etapes[] = [
            'cle' => 'decision',
            'titre' => 'Décision officielle',
            'description' => match ($decisionStatut) {
                'valide' => 'Inscription administrative validée avec succès.',
                'rejete' => 'Candidature non retenue pour cette session universitaire.',
                default => 'Notification de décision en attente.',
            },
            'date' => $estTraite ? ($etudiant->updated_at?->format('d/m/Y H:i')) : null,
            'statut' => $decisionStatut,
            'icone' => match ($decisionStatut) {
                'valide' => 'succes',
                'rejete' => 'refus',
                default => 'attente',
            },
        ];

        return $etapes;
    }

    /**
     * Détermine la prochaine action claire pour l'étudiant.
     *
     * @param  array<int, array<string, mixed>>  $corrections
     * @param  array<int, array<string, mixed>>  $piecesRejetees
     * @return array<string, mixed>
     */
    public function determinerProchaineAction(Etudiant $etudiant, array $corrections, array $piecesRejetees): array
    {
        if (count($corrections) > 0 || count($piecesRejetees) > 0) {
            $total = count($corrections) + count($piecesRejetees);

            return [
                'type' => 'action_requise',
                'titre' => "Action requise : {$total} correction(s) demandée(s)",
                'description' => 'Le service de scolarité attend des compléments pour poursuivre l\'instruction de votre dossier.',
                'badge' => 'Complément requis',
                'priorite' => 'haute',
                'lien' => route('etudiant.corrections.index'),
                'libelle_bouton' => 'Corriger mon dossier',
            ];
        }

        if ($etudiant->statut === 'valide') {
            return [
                'type' => 'succes',
                'titre' => 'Votre inscription est validée',
                'description' => 'Votre dossier est complet et approuvé. Vous pouvez télécharger votre récépissé d\'inscription officiel.',
                'badge' => 'Dossier validé',
                'priorite' => 'normale',
                'lien' => route('etudiant.recepisse'),
                'libelle_bouton' => 'Télécharger mon récépissé (PDF)',
            ];
        }

        if ($etudiant->statut === 'rejete') {
            return [
                'type' => 'rejet',
                'titre' => 'Dossier non retenu',
                'description' => 'Votre demande d\'inscription n\'a pas pu être retenue. Contactez la scolarité de votre faculté pour tout renseignement complémentaire.',
                'badge' => 'Candidature refusée',
                'priorite' => 'normale',
                'lien' => null,
                'libelle_bouton' => null,
            ];
        }

        return [
            'type' => 'information',
            'titre' => 'Dossier en cours d\'instruction',
            'description' => 'Aucune action n\'est requise de votre part pour le moment. Votre dossier est en cours d\'examen auprès des services académiques.',
            'badge' => 'En instruction',
            'priorite' => 'basse',
            'lien' => null,
            'libelle_bouton' => null,
        ];
    }

    /**
     * Récupère les corrections administratives proposées à l'étudiant.
     *
     * @return array<int, array<string, mixed>>
     */
    public function recupererCorrectionsDemandees(Etudiant $etudiant): array
    {
        return DossierCorrection::where('etudiant_id', $etudiant->id)
            ->where('statut', 'proposee')
            ->orderByDesc('id')
            ->get()
            ->map(fn (DossierCorrection $c) => [
                'id' => $c->id,
                'champ' => $c->champ,
                'champ_libelle' => ucfirst(str_replace('_', ' ', $c->champ)),
                'ancienne_valeur' => $c->ancienne_valeur,
                'nouvelle_valeur' => $c->nouvelle_valeur,
                'motif' => $c->motif ?? 'Correction requise par le service de scolarité',
                'date' => $c->created_at?->format('d/m/Y'),
            ])
            ->values()
            ->all();
    }

    /**
     * Récupère les pièces rejetées nécessitant un nouveau téléversement.
     *
     * @return array<int, array<string, mixed>>
     */
    public function recupererPiecesRejetees(Etudiant $etudiant): array
    {
        $dossierGed = StudentDossier::where('etudiant_id', $etudiant->id)->first();
        if ($dossierGed === null) {
            return [];
        }

        return StudentDocument::where('student_dossier_id', $dossierGed->id)
            ->where('status', 'rejected')
            ->orderByDesc('id')
            ->get()
            ->map(fn (StudentDocument $doc) => [
                'id' => $doc->id,
                'titre' => $doc->title,
                'document_type' => $doc->document_type,
                'motif' => $doc->rejected_reason ?? 'Document non conforme ou illisible',
                'date' => $doc->updated_at?->format('d/m/Y'),
            ])
            ->values()
            ->all();
    }

    /**
     * Classes CSS du badge de statut.
     */
    private function classesBadge(string $statut, bool $aDesCorrections): string
    {
        if ($aDesCorrections) {
            return 'border-amber-300 bg-amber-50 text-amber-900';
        }

        return match ($statut) {
            'valide' => 'border-emerald-300 bg-emerald-50 text-emerald-900',
            'rejete' => 'border-rose-300 bg-rose-50 text-rose-900',
            default => 'border-sky-300 bg-sky-50 text-sky-900',
        };
    }
}
