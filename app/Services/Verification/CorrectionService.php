<?php

namespace App\Services\Verification;

use App\Models\DossierCorrection;
use App\Models\Etudiant;
use App\Models\HistoriqueAcademique;
use App\Models\InscriptionAcademique;
use App\Modules\Referentiel\Models\Niveau;
use App\Modules\Referentiel\Models\Programme;
use App\Services\AuditService;
use App\Services\InscriptionCorrectionService;
use App\Services\NormalisationService;
use App\Services\NotificationService;
use App\Services\StudentStatusService;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * LOOP vérificateur §15-16 : corrections assistées avec validation
 * humaine (PROPOSER → VALIDER → CORRIGER → AUDITER). Jamais
 * d'écriture métier sans approbation explicite ; chaque transition
 * est verrouillée (`lockForUpdate`) et auditée sans PII inutile.
 */
final class CorrectionService
{
    public function __construct(
        private readonly VerificationEngine $moteur,
        private readonly AuditService $audit,
    ) {}

    /**
     * LOOP saisies : règles de validation PAR TYPE DE CHAMP corrigeable,
     * partagées par la correction directe (`corriger`) et le workflow
     * 4-eyes (`proposer`/`appliquer`) — une seule source, jamais deux
     * règles divergentes. CIN = exactement 8 chiffres ASCII (référentiel
     * tunisien, formé uniquement), email validé + unique (hors
     * enregistrement courant), date de naissance réelle et passée,
     * groupe existant, statut dans l'enum métier.
     *
     * @return array<string, array<int, mixed>>
     */
    public function reglesChampCorrigeable(Etudiant $etudiant): array
    {
        // P1 : un groupe cible ne doit jamais appartenir à une autre faculté.
        // Le filtre n'est ajouté que si la faculté de l'étudiant est connue.
        $regleGroupe = Rule::exists('groups', 'id');

        if ($etudiant->faculte_id !== null) {
            $regleGroupe->where('faculte_id', $etudiant->faculte_id);
        }

        return [
            'nom' => ['nullable', 'string', 'max:100'],
            'prenom' => ['nullable', 'string', 'max:100'],
            'email' => ['nullable', 'string', 'email', 'max:254', Rule::unique('etudiants', 'email')->ignore($etudiant->id)],
            'telephone' => ['nullable', 'string', 'max:30'],
            'cin' => ['nullable', 'string', 'regex:/\A[0-9]{8}\z/', Rule::unique('etudiants', 'cin')->ignore($etudiant->id)],
            'sexe' => ['nullable', 'string', 'in:M,F'],
            'date_naissance' => ['nullable', 'date', 'before:today'],
            'adresse' => ['nullable', 'string', 'max:500'],
            'groupe_id' => ['nullable', 'integer', $regleGroupe],
            'statut' => ['nullable', 'string', 'in:'.implode(',', Etudiant::STATUTS)],
        ];
    }

    /**
     * LOOP saisies : messages français par règle (le message brut
     * Laravel « validation.regex » n'est pas compréhensible côté agent).
     *
     * @return array<string, string>
     */
    public function messagesChampCorrigeable(): array
    {
        return [
            'cin.regex' => 'Le numéro de CIN doit être composé d’exactement 8 chiffres.',
            'cin.unique' => 'Ce numéro de CIN est déjà enregistré pour un autre étudiant.',
            'email.email' => 'Veuillez saisir une adresse email valide.',
            'email.unique' => 'Cette adresse email est déjà utilisée pour un autre étudiant.',
            'email.max' => 'L’adresse email ne doit pas dépasser 254 caractères.',
            'date_naissance.date' => 'Veuillez saisir une date de naissance valide.',
            'date_naissance.before' => 'La date de naissance doit être antérieure à aujourd’hui.',
            'sexe.in' => 'Veuillez sélectionner Masculin ou Féminin.',
            'groupe_id.exists' => 'Le groupe sélectionné est introuvable.',
            'statut.in' => 'Le statut sélectionné n’est pas valide.',
            'nom.max' => 'Le nom ne doit pas dépasser 100 caractères.',
            'prenom.max' => 'Le prénom ne doit pas dépasser 100 caractères.',
            'adresse.max' => 'L’adresse ne doit pas dépasser 500 caractères.',
        ];
    }

    /**
     * LOOP saisies : validation d'une VALEUR proposée ou appliquée pour
     * un champ corrigeable (anti-TOCTOU — la valeur peut changer entre
     * la proposition et l'application). Normalisation contrôlée :
     * email en minuscules sans espaces, téléphone canonique — jamais
     * la CIN (les espaces y sont REFUSÉS, pas corrigés silencieusement).
     *
     * @throws DomainException
     */
    public function validerValeurCorrigeable(Etudiant $etudiant, string $champ, ?string $valeur): void
    {
        if (! in_array($champ, DossierCorrection::CHAMPS, true)) {
            throw new DomainException('Champ non corrigeable via ce workflow.');
        }

        if ($valeur === null) {
            return;
        }

        $regle = $this->reglesChampCorrigeable($etudiant)[$champ] ?? null;

        if ($regle === null) {
            throw new DomainException('Champ non corrigeable via ce workflow.');
        }

        $normalise = app(NormalisationService::class);
        $soumis = $champ === 'email' ? ($normalise->email($valeur) ?? '') : $valeur;

        // La CIN est l'identifiant clé du dossier : la vider
        // silencieusement (null) n'est jamais une correction valide.
        if ($champ === 'cin' && ($soumis === null || $soumis === '')) {
            throw new DomainException('Le numéro de CIN ne peut pas être vidé.');
        }

        $valideur = Validator::make([$champ => $soumis], [$champ => $regle], $this->messagesChampCorrigeable());

        if ($valideur->fails()) {
            throw new DomainException((string) $valideur->errors()->first($champ));
        }
    }

    /**
     * B8 (LOT 1, 05/10/2026) : proposition d'une correction
     * D'INSCRIPTION (niveau/programme/type) — quatre yeux identiques
     * aux corrections d'étudiant ; application via le même `decider`.
     *
     * @param  array{programme_id?: ?int, niveau_id?: ?int, type?: ?string, motif: string}  $correction
     *
     * @throws DomainException
     */
    public function proposerInscription(InscriptionAcademique $inscription, array $correction, int $auteurId): DossierCorrection
    {
        $motif = trim((string) ($correction['motif'] ?? ''));

        if ($motif === '') {
            throw new DomainException('Motif obligatoire pour proposer une correction d’inscription.');
        }

        // Cibles effectives uniquement (jamais de proposition vide).
        $cibles = array_filter([
            'programme_id' => $correction['programme_id'] ?? null,
            'niveau_id' => $correction['niveau_id'] ?? null,
            'type' => $correction['type'] ?? null,
        ], fn ($v) => $v !== null && $v !== '');

        if ($cibles === []) {
            throw new DomainException('Aucune cible explicite (programme, niveau ou type) : la correction doit nommer ce qui change.');
        }

        $etudiant = $inscription->etudiant;

        if ($etudiant === null) {
            throw new DomainException('Dossier étudiant introuvable pour cette inscription.');
        }

        // P1 : le programme et le niveau cibles doivent rester dans la
        // faculté de l'étudiant. Un ID valide d'une autre faculté ne doit
        // jamais permettre un rattachement hors périmètre.
        $programme = null;

        if (isset($cibles['programme_id'])) {
            $programme = Programme::find((int) $cibles['programme_id']);

            if ($programme === null) {
                throw new DomainException('Programme cible introuvable.');
            }

            if ((int) $programme->faculte_id !== (int) $etudiant->faculte_id) {
                throw new DomainException('Le programme cible n’appartient pas à la faculté de l’étudiant.');
            }
        }

        $niveau = null;

        if (isset($cibles['niveau_id'])) {
            $niveau = Niveau::find((int) $cibles['niveau_id']);

            if ($niveau === null) {
                throw new DomainException('Niveau cible introuvable.');
            }

            $programmeNiveau = $niveau->programme;

            if ($programmeNiveau === null || (int) $programmeNiveau->faculte_id !== (int) $etudiant->faculte_id) {
                throw new DomainException('Le niveau cible n’appartient pas à la faculté de l’étudiant.');
            }

            if ($programme !== null && (int) $niveau->programme_id !== (int) $programme->id) {
                throw new DomainException('Le niveau cible doit appartenir au programme cible.');
            }
        }

        $nouvelle = json_encode($cibles, JSON_UNESCAPED_UNICODE);

        $existante = DossierCorrection::where('inscription_academique_id', $inscription->id)
            ->whereIn('statut', ['proposee', 'approuvee'])
            ->where('nouvelle_valeur', $nouvelle)
            ->first();

        if ($existante !== null) {
            throw new DomainException("Proposition déjà en cours (#{$existante->id}, statut {$existante->statut}).");
        }

        $record = DossierCorrection::create([
            'etudiant_id' => $inscription->etudiant_id,
            'inscription_academique_id' => $inscription->id,
            'champ' => DossierCorrection::CHAMP_INSCRIPTION,
            'ancienne_valeur' => json_encode([
                'programme_id' => $inscription->programme_id,
                'niveau_id' => $inscription->niveau_id,
                'type' => $inscription->type,
            ], JSON_UNESCAPED_UNICODE),
            'nouvelle_valeur' => $nouvelle,
            'regle' => 'inscription_quatre_yeux',
            'motif' => $motif,
            'statut' => 'proposee',
            'propose_par' => $auteurId,
        ]);

        $this->audit->tracer($inscription->etudiant, HistoriqueAcademique::ACTION_CORRECTION_PROPOSEE, 'correction', $record->id, null, [
            'champ' => DossierCorrection::CHAMP_INSCRIPTION,
            'inscription' => $inscription->id,
        ]);

        return $record;
    }

    public function proposer(Etudiant $etudiant, string $champ, ?string $nouvelleValeur, string $motif, ?string $regle, int $auteurId): DossierCorrection
    {
        abort_unless(in_array($champ, DossierCorrection::CHAMPS, true), 422, 'Champ non corrigeable via ce workflow.');

        // Idempotence : pas de doublon ouvert pour (champ, valeur).
        $existante = DossierCorrection::where('etudiant_id', $etudiant->id)
            ->where('champ', $champ)
            ->whereIn('statut', ['proposee', 'approuvee'])
            ->where('nouvelle_valeur', $nouvelleValeur)
            ->first();

        if ($existante !== null) {
            throw new DomainException("Proposition déjà en cours (#{$existante->id}, statut {$existante->statut}).");
        }

        $correction = DossierCorrection::create([
            'etudiant_id' => $etudiant->id,
            'champ' => $champ,
            'ancienne_valeur' => $etudiant->{$champ} !== null ? (string) $etudiant->{$champ} : null,
            'nouvelle_valeur' => $nouvelleValeur,
            'regle' => $regle,
            'motif' => $motif,
            'statut' => 'proposee',
            'propose_par' => $auteurId,
        ]);

        $this->audit->tracer($etudiant, HistoriqueAcademique::ACTION_CORRECTION_PROPOSEE, 'correction', $correction->id, null, [
            'champ' => $champ, 'motif' => $motif,
        ]);

        return $correction;
    }

    public function approuver(DossierCorrection $correction, int $decideurId, ?string $motif = null): DossierCorrection
    {
        return DB::transaction(function () use ($correction, $decideurId, $motif) {
            $verrouillee = DossierCorrection::whereKey($correction->id)->lockForUpdate()->firstOrFail();

            if ($verrouillee->statut !== 'proposee') {
                throw new DomainException("Correction #{$verrouillee->id} déjà traitée ({$verrouillee->statut}).");
            }

            // 4-eyes : l'approbateur ne peut pas être le proposeur.
            abort_if((int) $verrouillee->propose_par === $decideurId, 403, 'Approbation par un second agent requise.');

            $verrouillee->update([
                'statut' => 'approuvee', 'decide_par' => $decideurId,
                'motif_decision' => $motif, 'decide_le' => now(),
            ]);

            $this->audit->tracer($verrouillee->etudiant, HistoriqueAcademique::ACTION_CORRECTION_APPROUVEE, 'correction', $verrouillee->id);

            return $verrouillee->fresh();
        });
    }

    public function rejeter(DossierCorrection $correction, int $decideurId, string $motif): DossierCorrection
    {
        return DB::transaction(function () use ($correction, $decideurId, $motif) {
            $verrouillee = DossierCorrection::whereKey($correction->id)->lockForUpdate()->firstOrFail();

            if ($verrouillee->statut !== 'proposee') {
                throw new DomainException("Correction #{$verrouillee->id} déjà traitée ({$verrouillee->statut}).");
            }

            abort_if((int) $verrouillee->propose_par === $decideurId, 403, 'Décision par un second agent requise.');

            $verrouillee->update([
                'statut' => 'rejetee', 'decide_par' => $decideurId,
                'motif_decision' => $motif, 'decide_le' => now(),
            ]);

            $this->audit->tracer($verrouillee->etudiant, HistoriqueAcademique::ACTION_CORRECTION_REJETEE, 'correction', $verrouillee->id, null, [
                'motif' => $motif,
            ]);

            return $verrouillee->fresh();
        });
    }

    /**
     * Application : écriture transactionnelle + re-vérification.
     * Refusée si la donnée a changé depuis la proposition.
     *
     * @return array{correction: DossierCorrection, verdict_apres: string, score_apres: int}
     */
    public function appliquer(DossierCorrection $correction, int $auteurId): array
    {
        $resultat = DB::transaction(function () use ($correction, $auteurId) {
            $verrouillee = DossierCorrection::whereKey($correction->id)->lockForUpdate()->firstOrFail();

            if ($verrouillee->statut !== 'approuvee') {
                throw new DomainException('Seule une correction approuvée peut être appliquée.');
            }

            // B8 : cible INSCRIPTION → délègue l'application au service
            // métier dédié (jamais d'écriture directe de colonnes).
            if ($verrouillee->estPourInscription()) {
                return $this->appliquerInscription($verrouillee);
            }

            $etudiant = $verrouillee->etudiant()->lockForUpdate()->firstOrFail();
            $actuelle = $etudiant->{$verrouillee->champ} !== null ? (string) $etudiant->{$verrouillee->champ} : null;

            if ($actuelle !== $verrouillee->ancienne_valeur) {
                throw new DomainException('Donnée modifiée depuis la proposition : re-proposez une correction.');
            }

            // LOOP saisies : revalidation anti-TOCTOU de la VALEUR (la
            // proposition ne validait que la longueur) — CIN 8 chiffres,
            // email unique, date réelle, groupe existant. Le refus annule
            // la transaction : aucune écriture partielle.
            $this->validerValeurCorrigeable($etudiant, $verrouillee->champ, $verrouillee->nouvelle_valeur);

            if ($verrouillee->champ === 'statut') {
                // Transition canonique (§10) : statut n'est PAS
                // mass-assignable — un update brut no-op silencieusement
                // et audite un changement fantôme. Passage par la matrice
                // serveur (Situation + events + notification).
                app(StudentStatusService::class)->changer(
                    $etudiant,
                    (string) $verrouillee->nouvelle_valeur,
                    $auteurId,
                    'Correction #'.$verrouillee->id.' appliquée',
                );
            } else {
                $etudiant->update([
                    $verrouillee->champ => $verrouillee->champ === 'email'
                        ? app(NormalisationService::class)->email($verrouillee->nouvelle_valeur)
                        : $verrouillee->nouvelle_valeur,
                ]);
            }

            $verrouillee->update(['statut' => 'appliquee', 'appliquee_le' => now()]);

            $this->audit->tracer(
                $etudiant, HistoriqueAcademique::ACTION_CORRECTION_DOSSIER, 'etudiant', $etudiant->id,
                [$verrouillee->champ => $verrouillee->ancienne_valeur],
                [$verrouillee->champ => $verrouillee->nouvelle_valeur, 'correction_id' => $verrouillee->id],
            );

            $resultat = $this->moteur->verifier($etudiant->fresh());

            return [
                'correction' => $verrouillee->fresh(),
                'verdict_apres' => $resultat->verdict,
                'score_apres' => $resultat->score,
            ];
        });

        // Notification in-app (LOOP 11) : l'étudiant apprend que son dossier
        // a été corrigé par la scolarité — jamais les valeurs (PII), la
        // section seule suffit à orienter. Fail-soft : la correction est
        // déjà commitée, source de vérité = DossierCorrection + audit.
        try {
            $corr = $resultat['correction'];
            $etudiantNotifie = $corr->etudiant()->first();

            if ($etudiantNotifie !== null) {
                app(NotificationService::class)->notifierEtudiant(
                    $etudiantNotifie,
                    'dossier.correction',
                    'Votre dossier a été mis à jour',
                    "La scolarité a appliqué une correction sur la section « {$this->libelleSection($corr->champ)} » de votre dossier. Consultez votre fiche pour vérifier vos informations.",
                    route('etudiant.profil.show'),
                    ['correction_id' => $corr->id],
                    1,
                    'dossier.correction:'.$corr->id,
                );
            }
        } catch (\Throwable) {
            // Fail-soft : l'application reste la source de vérité.
        }

        return $resultat;
    }

    /** Libellé humain de section corrigée (jamais de valeur, PII exclue). */
    private function libelleSection(string $champ): string
    {
        return match ($champ) {
            'nom' => 'Nom',
            'prenom' => 'Prénom',
            'email' => 'Adresse email',
            'telephone' => 'Téléphone',
            'cin' => 'Numéro CIN',
            'sexe' => 'Sexe',
            'date_naissance' => 'Date de naissance',
            'adresse' => 'Adresse',
            'groupe_id' => 'Groupe',
            'statut' => 'Statut',
            DossierCorrection::CHAMP_INSCRIPTION => 'Inscription académique',
            default => 'Informations du dossier',
        };
    }

    /**
     * Application d'une correction D'INSCRIPTION (B8) : dérive vs la
     * proposition, puis le service métier (`InscriptionCorrectionService`)
     * écrit — l'audit avant/après + re-vérification restent sons.
     *
     * @return array{correction: DossierCorrection, verdict_avant: string, verdict_apres: string, score_apres: int}
     */
    private function appliquerInscription(DossierCorrection $correction): array
    {
        $inscription = InscriptionAcademique::whereKey($correction->inscription_academique_id)
            ->lockForUpdate()->firstOrFail();

        $actuelle = json_encode([
            'programme_id' => $inscription->programme_id,
            'niveau_id' => $inscription->niveau_id,
            'type' => $inscription->type,
        ], JSON_UNESCAPED_UNICODE);

        if ($actuelle !== $correction->ancienne_valeur) {
            throw new DomainException('Donnée modifiée depuis la proposition : re-proposez une correction.');
        }

        $payload = json_decode((string) $correction->nouvelle_valeur, true) ?: [];

        $resultat = app(InscriptionCorrectionService::class)->corriger(
            $inscription,
            [
                'programme_id' => $payload['programme_id'] ?? null,
                'niveau_id' => $payload['niveau_id'] ?? null,
                'type' => $payload['type'] ?? null,
                'motif' => (string) $correction->motif,
            ],
            (int) $correction->decide_par,
        );

        $correction->update(['statut' => 'appliquee', 'appliquee_le' => now()]);

        return ['correction' => $correction->fresh()] + $resultat;
    }
}
