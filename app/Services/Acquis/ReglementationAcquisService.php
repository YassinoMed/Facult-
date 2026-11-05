<?php

namespace App\Services\Acquis;

use App\Modules\Referentiel\Models\Programme;
use App\Modules\Referentiel\Models\UniteEnseignement;
use App\Services\GouvernanceReglesService;
use Carbon\Carbon;
use DomainException;
use Throwable;

/**
 * Service de détermination et de versionnement du régime réglementaire (LOOP 01 & LOOP 37).
 *
 * Applique les principes directeurs stricts :
 * - Jamais de constante globale aveugle ou arbitraire.
 * - Le régime est déterminé par le diplôme, la mention et le programme.
 * - Si le régime applicable est inconnu : fail-closed (bloque toute décision automatique).
 * - Audit et traçabilité des seuils avec références officielles et dates d'effet.
 */
class ReglementationAcquisService
{
    /**
     * Détermine le régime réglementaire applicable à un programme.
     *
     * @return array{
     *     code_regime: string,
     *     nom: string,
     *     diplomes_concernes: array<string>,
     *     textes_officiels: array<string, string>,
     *     articles_cles?: array<string, string>,
     *     seuil_capitalisation_ue: float,
     *     seuil_capitalisation_ecue: float,
     *     ecue_exige_credits_propres: bool,
     *     compensation_autorisee: bool,
     *     seuil_compensation_generale: float,
     *     seuil_competence_defaut: ?float,
     *     reference_seuil?: string,
     *     date_effet_seuil?: string,
     *     transferabilite_ue_compensee: bool,
     *     exclure_rattrapage_cc_pur: bool,
     *     rachat_automatique: bool,
     *     dispense_repassage_capitalise: bool
     * }
     *
     * @throws DomainException si le régime de la formation ne peut pas être établi de manière certaine.
     */
    public function resoudreRegime(?Programme $programme): array
    {
        if ($programme === null) {
            throw new DomainException('Impossible d’établir le régime réglementaire : le programme d’études est indéterminé.');
        }

        $codeType = mb_strtoupper(trim((string) ($programme->typeDiplome?->code ?? '')));
        $regimes = config('acquis_reglementation.regimes', []);

        foreach ($regimes as $cleRegime => $config) {
            if (in_array($codeType, $config['diplomes_concernes'] ?? [], true)) {
                return array_merge(['code_regime' => $cleRegime], $config);
            }
        }

        // Détection par intitulés reconnus de la nomenclature nationale tunisienne
        if (str_contains(mb_strtoupper((string) $programme->nom), 'LICENCE') || str_starts_with($codeType, 'L')) {
            return array_merge(['code_regime' => 'LMD_LICENCE'], $regimes['LMD_LICENCE']);
        }

        if (str_contains(mb_strtoupper((string) $programme->nom), 'INGÉNIEUR') || str_starts_with($codeType, 'ING')) {
            return array_merge(['code_regime' => 'CYCLE_INGENIEUR'], $regimes['CYCLE_INGENIEUR']);
        }

        if (str_contains(mb_strtoupper((string) $programme->nom), 'MAST') || str_starts_with($codeType, 'M')) {
            return array_merge(['code_regime' => 'MASTER_LMD'], $regimes['MASTER_LMD']);
        }

        // Garde-fou strict fail-closed (Gate G01 / PHASE 2)
        throw new DomainException("Régime réglementaire inconnu pour le programme « {$programme->nom} » (type diplôme : « {$codeType} »). Aucune capitalisation automatique n'est autorisée.");
    }

    /**
     * Audit et détermination précise du seuil de compétence applicable (PHASE 2 - LOOP 37 & LOOP 38).
     *
     * Identifie la formation, le diplôme, les unités concernées, la référence légale officielle
     * et sa date d'effet. Bloque toute décision automatique si le régime ou la règle est inconnue.
     *
     * @return array{
     *     formation: string,
     *     diplome_code: string,
     *     regime_code: string,
     *     seuil: ?float,
     *     statut: string,
     *     reference_officielle: string,
     *     date_effet: string,
     *     decision_automatique_autorisee: bool,
     *     unites_concernees: string,
     *     motif: string
     * }
     *
     * @throws DomainException si le régime de la formation ne peut pas être établi de manière certaine.
     */
    public function determinerSeuilCompetence(?Programme $programme, ?UniteEnseignement $ue = null): array
    {
        $codeProgramme = mb_strtoupper(trim((string) ($programme?->code ?? '')));
        $matriceFormations = config('acquis_reglementation.matrice_formations', []);

        // Vérification prioritaire si la formation spécifique est enregistrée dans la matrice
        if (isset($matriceFormations[$codeProgramme])) {
            return $this->determinerSeuilFormation($codeProgramme);
        }

        $regime = $this->resoudreRegime($programme);

        $formationNom = $programme?->nom ?? 'Inconnue';
        $diplomeCode = mb_strtoupper(trim((string) ($programme?->typeDiplome?->code ?? 'N/A')));
        $regimeCode = $regime['code_regime'] ?? 'INCONNU';

        $seuil = $regime['seuil_competence_defaut'] ?? null;
        $reference = $regime['reference_seuil'] ?? ($regime['textes_officiels']['arrete_seuil'] ?? 'Réglementation nationale');
        $dateEffet = $regime['date_effet_seuil'] ?? '2023-09-01';

        $unitesConcernees = match ($regimeCode) {
            'LMD_LICENCE' => 'Unités et éléments constitutifs disciplinaires fondamentaux selon arrêté du 10 mai 2023',
            'CYCLE_INGENIEUR' => 'Ensemble des modules d\'enseignement d\'ingénierie (seuil éliminatoire 8.00/20)',
            'MASTER_LMD' => 'Modules de mastère selon maquette d\'habilitation (aucun seuil éliminatoire général par défaut)',
            default => 'Non documenté',
        };

        $statut = $seuil !== null ? 'CONFIRME' : 'ABSENCE_CONFIRMEE';

        return [
            'formation' => $formationNom,
            'diplome_code' => $diplomeCode,
            'regime_code' => $regimeCode,
            'seuil' => $seuil,
            'statut' => $statut,
            'reference_officielle' => $reference,
            'date_effet' => $dateEffet,
            'decision_automatique_autorisee' => true,
            'unites_concernees' => $unitesConcernees,
            'motif' => $seuil !== null
                ? "Seuil éliminatoire fixé à {$seuil}/20 selon {$reference} (effet {$dateEffet})."
                : "Aucun seuil éliminatoire par défaut imposé nationalement selon {$reference}.",
        ];
    }

    /**
     * Détermine les paramètres réglementaires et le seuil d'une formation précise de la matrice (LOOP 38).
     *
     * @return array<string, mixed>
     *
     * @throws DomainException si le statut est SOURCE_MANQUANTE ou inconnu
     */
    public function determinerSeuilFormation(string|Programme $programmeOuCode): array
    {
        $code = is_string($programmeOuCode)
            ? mb_strtoupper(trim($programmeOuCode))
            : mb_strtoupper(trim((string) $programmeOuCode->code));

        $matrice = config('acquis_reglementation.matrice_formations', []);

        if (! isset($matrice[$code])) {
            throw new DomainException("Formation « {$code} » inconnue du référentiel réglementaire. Aucune décision automatique n'est autorisée.");
        }

        $info = $matrice[$code];
        $statut = $info['statut'] ?? 'SOURCE_MANQUANTE';
        $statutLoop39 = $info['statut_loop39'] ?? ($statut === 'CONFIRME' ? 'REGLE_CONFIRMEE' : $statut);

        if ($statut === 'SOURCE_MANQUANTE' && $statutLoop39 === 'REGLE_EXPIREE') {
            throw new DomainException("Règle réglementaire expirée pour la formation « {$code} » (date de fin : {$info['date_fin']}). Non applicable aux cohortes actuelles.");
        }

        if ($statut === 'SOURCE_MANQUANTE') {
            throw new DomainException("Source réglementaire manquante pour la formation « {$code} » (diplôme : « {$info['diplome']} »). Calcul bloqué (fail-closed).");
        }

        $decisionAutorisee = ($statut === 'CONFIRME' || $statut === 'ABSENCE_CONFIRMEE') && $statutLoop39 !== 'REGLE_A_VALIDER';

        return [
            'formation' => $info['diplome'],
            'formation_specialite' => $info['formation'] ?? $info['diplome'],
            'etablissement' => $info['etablissement'],
            'diplome_code' => $code,
            'regime_code' => $info['regime'],
            'seuil' => $info['seuil'],
            'statut' => $statut,
            'statut_loop39' => $statutLoop39,
            'reference_officielle' => "{$info['texte']} - {$info['article']}",
            'texte' => $info['texte'],
            'article' => $info['article'],
            'compensation' => $info['compensation'] ?? null,
            'capitalisation' => $info['capitalisation'] ?? null,
            'redoublement' => $info['redoublement'] ?? null,
            'passage_conditionnel' => $info['passage_conditionnel'] ?? null,
            'rachat' => $info['rachat'] ?? null,
            'date_effet' => $info['date_effet'],
            'date_fin' => $info['date_fin'] ?? null,
            'version' => $info['version'],
            'decision_automatique_autorisee' => $decisionAutorisee,
            'unites_concernees' => $info['unite_concernee'],
            'motif' => match ($statutLoop39) {
                'REGLE_CONFIRMEE' => "Seuil éliminatoire de {$info['seuil']}/20 confirmé par {$info['texte']} ({$info['article']}).",
                'ABSENCE_CONFIRMEE' => "Absence confirmée de seuil éliminatoire général selon {$info['texte']} ({$info['article']}).",
                'REGLE_A_VALIDER' => "Règlement intérieur spécifique en attente de validation officielle pour {$info['etablissement']} : décision automatique bloquée.",
                'REGLE_EXPIREE' => "Règle expirée depuis le {$info['date_fin']} : non applicable aux cohortes actuelles.",
                'NON_CONFIGURE' => "Règlement intérieur spécifique non configuré pour {$info['etablissement']} : décision automatique bloquée.",
                default => 'Statut réglementaire non documenté.',
            },
        ];
    }

    /**
     * Évalue le statut de validité d'une règle selon la cohorte / date de référence (PHASE 1 - LOOP 39).
     *
     * @return array{
     *     statut: string,
     *     applicable: bool,
     *     motif: string,
     *     fiche: array<string, mixed>
     * }
     */
    public function evaluerStatutRegle(string|Programme $programmeOuCode, ?string $dateReference = null): array
    {
        $code = is_string($programmeOuCode)
            ? mb_strtoupper(trim($programmeOuCode))
            : mb_strtoupper(trim((string) $programmeOuCode->code));

        $matrice = config('acquis_reglementation.matrice_formations', []);

        if (! isset($matrice[$code])) {
            return [
                'statut' => 'SOURCE_MANQUANTE',
                'applicable' => false,
                'motif' => "Formation « {$code} » non répertoriée dans le référentiel réglementaire.",
                'fiche' => [],
            ];
        }

        $fiche = $matrice[$code];
        $statut = $fiche['statut_loop39'] ?? ($fiche['statut'] === 'CONFIRME' ? 'REGLE_CONFIRMEE' : $fiche['statut']);
        $dateRef = $dateReference ? Carbon::parse($dateReference) : now();

        if (! empty($fiche['date_fin'])) {
            $dateFin = Carbon::parse($fiche['date_fin']);
            if ($dateRef->greaterThan($dateFin)) {
                return [
                    'statut' => 'REGLE_EXPIREE',
                    'applicable' => false,
                    'motif' => "La règle a expiré le {$fiche['date_fin']} et n'est plus applicable à la date du {$dateRef->toDateString()}.",
                    'fiche' => $fiche,
                ];
            }
        }

        if ($statut === 'REGLE_A_VALIDER' || $fiche['statut'] === 'NON_CONFIGURE') {
            return [
                'statut' => 'REGLE_A_VALIDER',
                'applicable' => false,
                'motif' => "La règle pour {$fiche['diplome']} est en attente d'approbation et ne peut être appliquée automatiquement.",
                'fiche' => $fiche,
            ];
        }

        if ($statut === 'SOURCE_MANQUANTE') {
            return [
                'statut' => 'SOURCE_MANQUANTE',
                'applicable' => false,
                'motif' => "Source réglementaire manquante pour {$fiche['diplome']}.",
                'fiche' => $fiche,
            ];
        }

        return [
            'statut' => $statut,
            'applicable' => true,
            'motif' => "Règle applicable et certifiée pour {$fiche['diplome']}.",
            'fiche' => $fiche,
        ];
    }

    /**
     * Niveau de couverture réglementaire d'un programme (LOOP 41 — ÉCAR-41-02, restauré).
     *
     * Distingue trois niveaux, strictement dérivés du référentiel existant :
     *  - FORMATION : fiche de formation confirmée (règle ou absence confirmée) —
     *    l'automatisme décisionnel est autorisé ;
     *  - NATIONALE : aucune fiche de formation confirmée, mais le régime national
     *    est identifiable — revue humaine requise, aucun automatisme ;
     *  - AUCUNE : ni fiche confirmée ni régime identifiable — revue humaine requise.
     *
     * Méthode additive (aucune méthode existante modifiée). Elle ne définit
     * aucune règle nouvelle : elle compose `evaluerStatutRegle()` et
     * `resoudreRegime()` et n'autorise l'automatisme que pour une couverture
     * FORMATION confirmée, conformément au principe de précaution académique.
     *
     * @return array{
     *     niveau_couverture: string,
     *     applicable_automatiquement: bool,
     *     revue_humaine_requise: bool,
     *     motif: string,
     *     fiche: array<string, mixed>,
     *     regime_code?: ?string
     * }
     */
    public function evaluerCouvertureReglementaire(string|Programme $programmeOuCode, ?string $dateReference = null): array
    {
        $statut = $this->evaluerStatutRegle($programmeOuCode, $dateReference);
        $statutRegle = (string) ($statut['statut'] ?? 'SOURCE_MANQUANTE');

        // 1) Couverture FORMATION : fiche confirmée (règle OU absence confirmée).
        if (in_array($statutRegle, ['REGLE_CONFIRMEE', 'ABSENCE_CONFIRMEE'], true)) {
            return [
                'niveau_couverture' => 'FORMATION',
                'applicable_automatiquement' => true,
                'revue_humaine_requise' => false,
                'motif' => (string) ($statut['motif'] ?? 'Fiche de formation confirmée au référentiel.'),
                'fiche' => (array) ($statut['fiche'] ?? []),
            ];
        }

        // 2) Couverture NATIONALE : le régime général est identifiable.
        $programme = is_string($programmeOuCode)
            ? Programme::query()->where('code', mb_strtoupper(trim($programmeOuCode)))->first()
            : $programmeOuCode;

        if ($programme !== null) {
            try {
                $regime = $this->resoudreRegime($programme);

                return [
                    'niveau_couverture' => 'NATIONALE',
                    'applicable_automatiquement' => false,
                    'revue_humaine_requise' => true,
                    'motif' => $statutRegle === 'REGLE_EXPIREE'
                        ? 'Fiche de formation expirée : seul le régime national reste opposable — revue humaine requise.'
                        : 'Aucune fiche de formation confirmée : couverture limitée au régime national — revue humaine requise.',
                    'fiche' => (array) ($statut['fiche'] ?? []),
                    'regime_code' => $regime['code_regime'] ?? null,
                ];
            } catch (Throwable) {
                // Aucun régime identifiable : aucune couverture (fail-closed).
            }
        }

        // 3) AUCUNE couverture : ni fiche confirmée, ni régime identifiable.
        return [
            'niveau_couverture' => 'AUCUNE',
            'applicable_automatiquement' => false,
            'revue_humaine_requise' => true,
            'motif' => (string) ($statut['motif'] ?? 'Aucune couverture réglementaire identifiable.'),
            'fiche' => (array) ($statut['fiche'] ?? []),
        ];
    }

    /**
     * Retourne la matrice consolidée des limites de redoublement par régime (PHASE 2 - LOOP 39).
     *
     * @return array<string, array<string, mixed>>
     */
    public function obtenirMatriceLimitesRedoublement(): array
    {
        return config('acquis_reglementation.limites_redoublement', []);
    }

    /**
     * Retourne la matrice consolidée des seuils de compétence (PHASE 3 - LOOP 39).
     *
     * @return array<string, array<string, mixed>>
     */
    public function obtenirMatriceSeuils(): array
    {
        return config('acquis_reglementation.matrice_seuils', []);
    }

    /**
     * Retourne la matrice consolidée des seuils par formation (PHASE 1 - LOOP 38).
     *
     * @return array<string, array<string, mixed>>
     */
    public function obtenirMatriceFormations(): array
    {
        return config('acquis_reglementation.matrice_formations', []);
    }

    /**
     * Matrice réglementaire consolidée par régime général (PHASE 1 - LOOP 37).
     *
     * @return array<string, array<string, mixed>>
     */
    public function obtenirMatriceReglementaire(): array
    {
        return config('acquis_reglementation.regimes', []);
    }

    /**
     * Consultation d'une règle versionnée dans le registre canonique (PHASE 7 - LOOP 44).
     *
     * Permet au moteur académique d'interroger la gouvernance versionnée.
     * En cas d'absence ou de conflit, renvoie REVUE_HUMAINE_REQUISE ou REVUE_JURIDIQUE_REQUISE
     * sans jamais appliquer de valeur par défaut arbitraire.
     *
     * @return array{
     *     code: string,
     *     statut: string,
     *     applicable: bool,
     *     revue_humaine_requise: bool,
     *     valeur: mixed,
     *     version: ?int,
     *     reference_officielle: ?string,
     *     motif: string
     * }
     */
    public function consulterRegleVersionnee(string $code, ?string $date = null, ?array $contexte = null): array
    {
        $gouvernance = app(GouvernanceReglesService::class);
        $diagnostic = $gouvernance->detecterConflits($code, $date);

        if ($diagnostic['statut'] === 'REVUE_JURIDIQUE_REQUISE') {
            return [
                'code' => $code,
                'statut' => 'REVUE_JURIDIQUE_REQUISE',
                'applicable' => false,
                'revue_humaine_requise' => true,
                'valeur' => null,
                'version' => null,
                'reference_officielle' => null,
                'motif' => $diagnostic['motif'],
            ];
        }

        $regle = $diagnostic['regle_applicable'] ?? null;

        if ($regle === null) {
            return [
                'code' => $code,
                'statut' => 'REGLE_NON_TROUVEE',
                'applicable' => false,
                'revue_humaine_requise' => true,
                'valeur' => null,
                'version' => null,
                'reference_officielle' => null,
                'motif' => "Aucune règle versionnée confirmée et applicable pour le code {$code} à la date indiquée.",
            ];
        }

        return [
            'code' => $code,
            'statut' => $regle->statut,
            'applicable' => $regle->estPubliee(),
            'revue_humaine_requise' => false,
            'valeur' => $regle->contenu,
            'version' => (int) $regle->version,
            'reference_officielle' => $regle->reference_officielle,
            'motif' => "Règle version {$regle->version} active selon {$regle->reference_officielle}.",
        ];
    }
}
