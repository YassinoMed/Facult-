<?php

namespace App\Services\Affectations;

use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\InscriptionAcademique;
use App\Services\DocumentsRequisService;
use App\Services\IdentificationIntelligenteService;
use App\Services\NormalisationService;

/**
 * Service central d'éligibilité administrative à l'affectation (LOOP MATURE — Phases 2 & 3) :
 *
 * Détermine si un étudiant remplit toutes les conditions administratives préalables
 * pour entrer dans le moteur d'affectation :
 *
 * 1. IDENTITÉ      : Nom, prénom, CIN normalisée, cohérence MENS.
 * 2. CONTACT       : Email et/ou téléphone valides selon la politique de contact.
 * 3. PROGRAMME     : Cohérence académique (faculté, niveau strict, programme LMD).
 * 4. INSCRIPTION   : Inscription académique active et validée pour l'année universitaire ciblée.
 * 5. DOCUMENTS     : Dossier documentaire complet avec pièces requises validées en GED.
 *
 * Retourne une structure normalisée, déterministe et testable.
 */
class AffectationEligibilityService
{
    // Codes de statut par axe
    public const IDENTITY_VALID = 'IDENTITY_VALID';

    public const IDENTITY_INCOMPLETE = 'IDENTITY_INCOMPLETE';

    public const IDENTITY_CONFLICT = 'IDENTITY_CONFLICT';

    public const IDENTITY_CIN_DUPLICATE = 'IDENTITY_CIN_DUPLICATE';

    public const CONTACT_VALID = 'CONTACT_VALID';

    public const CONTACT_INCOMPLETE = 'CONTACT_INCOMPLETE';

    public const CONTACT_INVALID = 'CONTACT_INVALID';

    public const PROGRAM_VALID = 'PROGRAM_VALID';

    public const PROGRAM_INCOMPLETE = 'PROGRAM_INCOMPLETE';

    public const PROGRAM_INCONSISTENT = 'PROGRAM_INCONSISTENT';

    public const REGISTRATION_VALID = 'REGISTRATION_VALID';

    public const REGISTRATION_MISSING = 'REGISTRATION_MISSING';

    public const REGISTRATION_INACTIVE = 'REGISTRATION_INACTIVE';

    public const REGISTRATION_INVALID = 'REGISTRATION_INVALID';

    public const DOCUMENTS_VALID = 'DOCUMENTS_VALID';

    public const DOCUMENTS_INCOMPLETE = 'DOCUMENTS_INCOMPLETE';

    public const DOCUMENTS_UNVALIDATED = 'DOCUMENTS_UNVALIDATED';

    public const DOCUMENTS_REJECTED = 'DOCUMENTS_REJECTED';

    public function __construct(
        private readonly IdentificationIntelligenteService $identification,
        private readonly DocumentsRequisService $documentsRequis,
        private readonly NormalisationService $normalisation,
    ) {}

    /**
     * Évalue l'ensemble des conditions d'éligibilité administrative d'un étudiant.
     *
     * @param  array{exiger_contact_complet?: bool, ignorer_documents?: bool, type_inscription?: string}  $options
     * @return array{
     *     eligible: bool,
     *     checks: array{identity: bool, contact: bool, program: bool, registration: bool, documents: bool},
     *     statuses: array{identity: string, contact: string, program: string, registration: string, documents: string},
     *     blocking_reasons: array<int, string>,
     *     warnings: array<int, string>,
     *     details: array<string, mixed>
     * }
     */
    public function evaluer(Etudiant $etudiant, ?AnneeUniversitaire $annee = null, array $options = []): array
    {
        $anneeEffective = $annee ?? AnneeUniversitaire::where('active', true)->first() ?? AnneeUniversitaire::latest('id')->first();
        if (! $anneeEffective) {
            return [
                'eligible' => false,
                'checks' => ['identity' => false, 'contact' => false, 'program' => false, 'registration' => false, 'documents' => false],
                'statuses' => ['identity' => self::IDENTITY_INCOMPLETE, 'contact' => self::CONTACT_INVALID, 'program' => self::PROGRAM_INCOMPLETE, 'registration' => self::REGISTRATION_MISSING, 'documents' => self::DOCUMENTS_INCOMPLETE],
                'blocking_reasons' => ['ANNEE_UNIVERSITAIRE_MISSING'],
                'warnings' => [],
                'details' => ['error' => 'Aucune année universitaire active.'],
            ];
        }

        return $this->verifier($etudiant, $anneeEffective, $options);
    }

    /**
     * Évalue l'ensemble des conditions d'éligibilité administrative d'un étudiant.
     *
     * @param  array{exiger_contact_complet?: bool, ignorer_documents?: bool, type_inscription?: string}  $options
     * @return array{
     *     eligible: bool,
     *     checks: array{identity: bool, contact: bool, program: bool, registration: bool, documents: bool},
     *     statuses: array{identity: string, contact: string, program: string, registration: string, documents: string},
     *     blocking_reasons: array<int, string>,
     *     warnings: array<int, string>,
     *     details: array<string, mixed>
     * }
     */
    public function verifier(Etudiant $etudiant, AnneeUniversitaire $annee, array $options = []): array
    {
        $blockingReasons = [];
        $warnings = [];
        $details = [];

        // 1. CONTRÔLE SUSPENSION DISCIPLINAIRE (Garde préalable)
        if ($etudiant->estSuspendu()) {
            $blockingReasons[] = 'STUDENT_SUSPENDED';
            $details['suspension'] = 'Étudiant sous le coup d’une suspension disciplinaire active.';
        }

        // 2. CONTRÔLE IDENTITÉ
        $checkIdentity = $this->verifierIdentite($etudiant, $blockingReasons, $warnings, $details);

        // 3. CONTRÔLE CONTACT
        $checkContact = $this->verifierContact($etudiant, $options, $blockingReasons, $warnings, $details);

        // 4. CONTRÔLE PROGRAMME & NIVEAU
        $checkProgram = $this->verifierProgramme($etudiant, $blockingReasons, $warnings, $details);

        // 5. CONTRÔLE INSCRIPTION ACADÉMIQUE
        $checkRegistration = $this->verifierInscription($etudiant, $annee, $blockingReasons, $warnings, $details);

        // 6. CONTRÔLE DOSSIER DOCUMENTAIRE
        $checkDocuments = $this->verifierDocuments($etudiant, $options, $blockingReasons, $warnings, $details);

        $eligible = empty($blockingReasons);

        return [
            'eligible' => $eligible,
            'checks' => [
                'identity' => $checkIdentity['valid'],
                'contact' => $checkContact['valid'],
                'program' => $checkProgram['valid'],
                'registration' => $checkRegistration['valid'],
                'documents' => $checkDocuments['valid'],
            ],
            'statuses' => [
                'identity' => $checkIdentity['status'],
                'contact' => $checkContact['status'],
                'program' => $checkProgram['status'],
                'registration' => $checkRegistration['status'],
                'documents' => $checkDocuments['status'],
            ],
            'blocking_reasons' => array_values(array_unique($blockingReasons)),
            'warnings' => array_values(array_unique($warnings)),
            'details' => $details,
        ];
    }

    /**
     * Contrôle d'identité légale (nom, prénom, CIN normalisée, conflits MENS).
     *
     * @param  array<int, string>  $blockingReasons
     * @param  array<int, string>  $warnings
     * @param  array<string, mixed>  $details
     * @return array{valid: bool, status: string}
     */
    private function verifierIdentite(Etudiant $etudiant, array &$blockingReasons, array &$warnings, array &$details): array
    {
        $nomPresent = ! empty(trim((string) $etudiant->nom));
        $prenomPresent = ! empty(trim((string) $etudiant->prenom));
        $cinPresent = ! empty(trim((string) $etudiant->cin));

        if (! $nomPresent || ! $prenomPresent) {
            $blockingReasons[] = 'IDENTITY_NAME_MISSING';
            $details['identity'] = 'Nom ou prénom manquant sur le dossier étudiant.';

            return ['valid' => false, 'status' => self::IDENTITY_INCOMPLETE];
        }

        if (! $cinPresent) {
            $blockingReasons[] = 'IDENTITY_CIN_MISSING';
            $details['identity'] = 'CIN ou identifiant national légal manquant.';

            return ['valid' => false, 'status' => self::IDENTITY_INCOMPLETE];
        }

        // Vérification de la normalisation CIN (8 chiffres standards)
        $cinNettoye = preg_replace('/\s+/', '', (string) $etudiant->cin);
        if (! ctype_digit($cinNettoye) || strlen($cinNettoye) < 7 || strlen($cinNettoye) > 10) {
            $blockingReasons[] = 'IDENTITY_CIN_INVALID';
            $details['identity'] = 'Format de CIN non conforme (doit comporter 8 chiffres numériques).';

            return ['valid' => false, 'status' => self::IDENTITY_INCOMPLETE];
        }

        // Conflit CIN (Phase 1) : même CIN normalisée → plusieurs
        // dossiers = BLOQUANT, y compris sans référentiel MENS (la
        // contrainte unique DB ne couvre pas les variantes espacées/
        // cassées : '12 345678' vs '12345678'). L'homonymie
        // (même nom/prénom, CIN différentes) reste parfaitement valide.
        $cinNormalise = $this->normalisation->cin((string) $etudiant->cin);

        if ($cinNormalise !== null && Etudiant::whereNotNull('cin')
            ->when($etudiant->exists, fn ($requete) => $requete->whereKeyNot($etudiant->id))
            ->whereRaw("REPLACE(UPPER(TRIM(cin)), ' ', '') = ?", [$cinNormalise])
            ->exists()) {
            $blockingReasons[] = self::IDENTITY_CIN_DUPLICATE;
            $details['identity'] = 'CIN déjà attribuée à un autre dossier étudiant : conflit d’identité bloquant.';

            return ['valid' => false, 'status' => self::IDENTITY_CONFLICT];
        }

        // Vérification des conflits ou divergences MENS si le référentiel national est alimenté
        try {
            $divergences = $this->identification->divergencesDossier($etudiant);
            foreach ($divergences as $div) {
                if (($div['gravite'] ?? '') === 'BLOQUANT') {
                    $blockingReasons[] = 'IDENTITY_MENS_CONFLICT';
                    $details['identity_mens'] = 'Conflit critique d’identité avec le référentiel national (MENS).';

                    return ['valid' => false, 'status' => self::IDENTITY_CONFLICT];
                }
                $warnings[] = 'IDENTITY_MENS_DIVERGENCE';
                $details['identity_mens'] = "Divergence mineure MENS sur le champ « {$div['champ']} ».";
            }
        } catch (\Throwable) {
            // Tolérance silencieuse si le module MENS est optionnel
        }

        return ['valid' => true, 'status' => self::IDENTITY_VALID];
    }

    /**
     * Contrôle des canaux de contact (email et téléphone).
     *
     * @param  array<string, mixed>  $options
     * @param  array<int, string>  $blockingReasons
     * @param  array<int, string>  $warnings
     * @param  array<string, mixed>  $details
     * @return array{valid: bool, status: string}
     */
    private function verifierContact(Etudiant $etudiant, array $options, array &$blockingReasons, array &$warnings, array &$details): array
    {
        $email = trim((string) $etudiant->email);
        $telephone = trim((string) $etudiant->telephone);

        $emailValide = filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
        $telephoneValide = ! empty($telephone) && strlen(preg_replace('/\D/', '', $telephone)) >= 8;

        $details['contact_email'] = $emailValide ? 'valide' : ($email === '' ? 'manquant' : 'invalide');
        $details['contact_telephone'] = $telephoneValide ? 'valide' : ($telephone === '' ? 'manquant' : 'invalide');

        $exigerComplet = $options['exiger_contact_complet'] ?? false;

        if (! $emailValide && ! $telephoneValide) {
            $blockingReasons[] = 'CONTACT_MISSING';
            $details['contact'] = 'Aucun moyen de contact valide (email ou téléphone absent ou invalide).';

            return ['valid' => false, 'status' => self::CONTACT_INVALID];
        }

        if ($exigerComplet && (! $emailValide || ! $telephoneValide)) {
            $blockingReasons[] = 'CONTACT_INCOMPLETE';
            $details['contact'] = 'Email ou téléphone manquant alors que le contact complet est exigé.';

            return ['valid' => false, 'status' => self::CONTACT_INCOMPLETE];
        }

        if (! $emailValide) {
            $warnings[] = 'CONTACT_EMAIL_MISSING';
        }
        if (! $telephoneValide) {
            $warnings[] = 'CONTACT_PHONE_MISSING';
        }

        $status = (! $emailValide || ! $telephoneValide) ? self::CONTACT_INCOMPLETE : self::CONTACT_VALID;

        return ['valid' => true, 'status' => $status];
    }

    /**
     * Contrôle académique du programme, de la faculté et du niveau.
     *
     * @param  array<int, string>  $blockingReasons
     * @param  array<int, string>  $warnings
     * @param  array<string, mixed>  $details
     * @return array{valid: bool, status: string}
     */
    private function verifierProgramme(Etudiant $etudiant, array &$blockingReasons, array &$warnings, array &$details): array
    {
        if (empty($etudiant->faculte_id)) {
            $blockingReasons[] = 'PROGRAM_FACULTY_MISSING';
            $details['program'] = 'Faculté de rattachement non renseignée.';

            return ['valid' => false, 'status' => self::PROGRAM_INCOMPLETE];
        }

        $niveau = trim((string) $etudiant->niveau);
        if ($niveau === '') {
            $blockingReasons[] = 'PROGRAM_LEVEL_MISSING';
            $details['program'] = 'Niveau académique LMD non renseigné.';

            return ['valid' => false, 'status' => self::PROGRAM_INCOMPLETE];
        }

        // Si un programme est rattaché, vérifier qu'il appartient bien à la même faculté
        if ($etudiant->programme_id !== null) {
            $programme = $etudiant->programme;
            if ($programme !== null && (int) $programme->faculte_id !== (int) $etudiant->faculte_id) {
                $blockingReasons[] = 'PROGRAM_FACULTY_MISMATCH';
                $details['program'] = 'Incohérence : le programme ne correspond pas à la faculté de l’étudiant.';

                return ['valid' => false, 'status' => self::PROGRAM_INCONSISTENT];
            }
        } else {
            $warnings[] = 'PROGRAM_TRACK_GENERIC';
            $details['program'] = 'Étudiant en tronc commun sans parcours spécifique déterminé.';
        }

        return ['valid' => true, 'status' => self::PROGRAM_VALID];
    }

    /**
     * Contrôle de l'inscription académique active et validée pour l'année ciblée.
     *
     * @param  array<int, string>  $blockingReasons
     * @param  array<int, string>  $warnings
     * @param  array<string, mixed>  $details
     * @return array{valid: bool, status: string}
     */
    private function verifierInscription(Etudiant $etudiant, AnneeUniversitaire $annee, array &$blockingReasons, array &$warnings, array &$details): array
    {
        /** @var InscriptionAcademique|null $inscription */
        $inscription = $etudiant->inscriptionsAcademiques
            ? $etudiant->inscriptionsAcademiques->where('annee_universitaire_id', $annee->id)->first()
            : InscriptionAcademique::where('etudiant_id', $etudiant->id)->where('annee_universitaire_id', $annee->id)->first();

        if ($inscription === null) {
            $blockingReasons[] = 'REGISTRATION_MISSING';
            $details['registration'] = "Aucune inscription enregistrée pour l'année universitaire {$annee->libelle}.";

            return ['valid' => false, 'status' => self::REGISTRATION_MISSING];
        }

        $details['registration_statut'] = $inscription->statut;

        if (in_array($inscription->statut, ['ajourne', 'abandon', 'rejete', 'annule'], true)) {
            $blockingReasons[] = 'REGISTRATION_INACTIVE';
            $details['registration'] = "Inscription inactive ou clôturée (Statut: {$inscription->statut}).";

            return ['valid' => false, 'status' => self::REGISTRATION_INACTIVE];
        }

        if ($inscription->statut === 'en_cours') {
            // L'inscription n'est pas encore validée officiellement
            $warnings[] = 'REGISTRATION_PENDING_VALIDATION';
            $details['registration'] = 'Inscription administrative encore en cours de validation.';
            // Selon la stricte règle de maturité, seule une inscription validée permet l'automatisme pur
            $blockingReasons[] = 'REGISTRATION_NOT_VALIDATED';

            return ['valid' => false, 'status' => self::REGISTRATION_INVALID];
        }

        if ($inscription->statut !== 'valide') {
            $blockingReasons[] = 'REGISTRATION_INVALID_STATUS';
            $details['registration'] = "Statut d’inscription « {$inscription->statut} » non éligible à l'affectation.";

            return ['valid' => false, 'status' => self::REGISTRATION_INVALID];
        }

        return ['valid' => true, 'status' => self::REGISTRATION_VALID];
    }

    /**
     * Contrôle du dossier documentaire (pièces obligatoires GED validées).
     *
     * @param  array<string, mixed>  $options
     * @param  array<int, string>  $blockingReasons
     * @param  array<int, string>  $warnings
     * @param  array<string, mixed>  $details
     * @return array{valid: bool, status: string}
     */
    private function verifierDocuments(Etudiant $etudiant, array $options, array &$blockingReasons, array &$warnings, array &$details): array
    {
        if (! empty($options['ignorer_documents'])) {
            return ['valid' => true, 'status' => self::DOCUMENTS_VALID];
        }

        $typeInscription = $options['type_inscription'] ?? ($etudiant->inscriptionsAcademiques?->first()?->type ?? 'premiere');

        try {
            $bilan = $this->documentsRequis->verifier($etudiant, $typeInscription);
            $details['documents_bilan'] = [
                'requis' => $bilan['requis'],
                'valides' => $bilan['valides'],
                'manquants' => $bilan['manquants'],
                'en_attente' => $bilan['en_attente'],
                'rejetes' => $bilan['rejetes'],
                'expires' => $bilan['expires'],
            ];

            // Si aucune pièce n'est imposée par la matrice de configuration pour ce type, le dossier est réputé complet
            if ($bilan['requis'] === 0) {
                return ['valid' => true, 'status' => self::DOCUMENTS_VALID];
            }

            if (! empty($bilan['rejetes'])) {
                $blockingReasons[] = 'DOCUMENTS_REJECTED';
                $details['documents'] = 'Une ou plusieurs pièces requises ont été rejetées : '.implode(', ', $bilan['rejetes']);

                return ['valid' => false, 'status' => self::DOCUMENTS_REJECTED];
            }

            if (! empty($bilan['manquants'])) {
                $blockingReasons[] = 'DOCUMENTS_MISSING';
                $details['documents'] = 'Pièces obligatoires manquantes : '.implode(', ', $bilan['manquants']);

                return ['valid' => false, 'status' => self::DOCUMENTS_INCOMPLETE];
            }

            if (! empty($bilan['en_attente']) || ! empty($bilan['expires'])) {
                $blockingReasons[] = 'DOCUMENTS_UNVALIDATED';
                $details['documents'] = 'Pièces en attente de vérification ou expirées : '.implode(', ', array_merge($bilan['en_attente'], $bilan['expires']));

                return ['valid' => false, 'status' => self::DOCUMENTS_UNVALIDATED];
            }

            if (! $bilan['complet']) {
                $blockingReasons[] = 'DOCUMENTS_INCOMPLETE';
                $details['documents'] = 'Dossier documentaire incomplet.';

                return ['valid' => false, 'status' => self::DOCUMENTS_INCOMPLETE];
            }
        } catch (\Throwable $e) {
            // Si la table ou module GED n'est pas encore provisionné dans l'environnement de test
            $warnings[] = 'DOCUMENTS_CHECK_UNAVAILABLE';
            $details['documents_note'] = $e->getMessage();
        }

        return ['valid' => true, 'status' => self::DOCUMENTS_VALID];
    }
}
