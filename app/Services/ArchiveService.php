<?php

namespace App\Services;

use App\Models\AnneeUniversitaire;
use App\Models\DossierAcademique;
use App\Models\Etudiant;
use App\Models\HistoriqueAcademique;
use App\Models\InscriptionAcademique;
use App\Models\Matiere;
use App\Models\Note;
use App\Models\RegistrationPeriod;
use App\Models\Semestre;
use App\Modules\Evaluation\Services\DeliberationService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Service d'orchestration du module Archives Académiques.
 *
 * Toute opération multi-table critique (inscription + semestres, note +
 * recalcul des moyennes + journalisation) s'exécute dans DB::transaction() :
 * tout réussit ou tout est annulé.
 *
 * Les verrouillages pessimistes (lockForUpdate) protègent les notes contre
 * les modifications concurrentes entre agents.
 */
class ArchiveService
{
    public function __construct(
        private readonly AcademicCalculationService $calculs,
        private readonly AuditService $audit,
        private readonly PeriodeInscriptionService $periodes,
    ) {}

    /**
     * Crée le dossier académique d'un étudiant (matricule généré si absent).
     */
    public function creerDossier(Etudiant $etudiant, array $donnees): DossierAcademique
    {
        return DB::transaction(function () use ($etudiant, $donnees) {
            $dossier = DossierAcademique::create([
                'etudiant_id' => $etudiant->id,
                'matricule' => $donnees['matricule']
                    ?? sprintf('MAT-%s', str_pad((string) $etudiant->id, 5, '0', STR_PAD_LEFT)),
                'date_archivage' => $donnees['date_archivage'] ?? now()->toDateString(),
                'statut' => 'actif',
                'observation' => $donnees['observation'] ?? null,
            ]);

            $this->audit->tracer(
                $etudiant,
                HistoriqueAcademique::ACTION_CREATION_DOSSIER,
                'dossier',
                $dossier->id,
                null,
                ['matricule' => $dossier->matricule],
            );

            return $dossier;
        });
    }

    /**
     * Crée une inscription académique avec ses deux semestres (S1, S2).
     * Unicité garantie par la contrainte (etudiant_id, annee_universitaire_id).
     */
    public function creerInscription(Etudiant $etudiant, array $donnees): InscriptionAcademique
    {
        // LOOP périodes : garde SERVEUR avant toute écriture (jamais de
        // contournement silencieux — seule une exception officielle ouvre).
        $annee = AnneeUniversitaire::findOrFail($donnees['annee_universitaire_id']);
        $type = $donnees['type'] ?? 'premiere';
        $this->periodes->verifierCreation($etudiant, $annee, $type);

        $exceptionId = null;

        $inscription = DB::transaction(function () use ($etudiant, &$exceptionId, $donnees, $annee, $type) {
            // Re-vérification verrouillée DANS la transaction (§19-20) :
            // une révocation concurrente bloque ici, jamais d'inscription
            // partielle sous période révoquée.
            RegistrationPeriod::where('annee_universitaire_id', $annee->id)->lockForUpdate()->get();
            PeriodeInscriptionService::oublierMemo();
            $exceptionId = $this->periodes->verifierCreation($etudiant, $annee, $type);
            try {
                $inscription = InscriptionAcademique::create([
                    'etudiant_id' => $etudiant->id,
                    'annee_universitaire_id' => $donnees['annee_universitaire_id'],
                    'programme_id' => $donnees['programme_id'] ?? $etudiant->programme_id,
                    'niveau_id' => $donnees['niveau_id'] ?? $etudiant->niveau_id,
                    'niveau' => $donnees['niveau'],
                    'filiere' => $donnees['filiere'] ?? null,
                    'groupe' => $donnees['groupe'] ?? null,
                    'statut' => $donnees['statut'] ?? 'en_cours',
                    'type' => $donnees['type'] ?? 'premiere',
                    'regime' => $donnees['regime'] ?? 'initiale',
                    'observation' => $donnees['observation'] ?? null,
                ]);
            } catch (UniqueConstraintViolationException $e) {
                throw new \DomainException('Cet étudiant possède déjà une inscription pour cette année universitaire.');
            }

            foreach ([1, 2] as $numero) {
                Semestre::create([
                    'inscription_academique_id' => $inscription->id,
                    'numero' => $numero,
                ]);
            }

            $this->audit->tracer(
                $etudiant,
                HistoriqueAcademique::ACTION_CREATION_INSCRIPTION,
                'inscription',
                $inscription->id,
                null,
                [
                    'annee' => $inscription->anneeUniversitaire->libelle ?? null,
                    'niveau' => $inscription->niveau,
                    'periode_exception_id' => $exceptionId,
                ],
            );

            return $inscription;
        });

        // Exception individuelle à usage unique : marquée USED après succès.
        $this->periodes->marquerUtilisee($exceptionId);

        return $inscription;
    }

    /**
     * Saisit une note : cohérence stricte semestre/inscription/matière,
     * coefficient et crédits dérivés de la matière (jamais du client),
     * recalcul immédiat du semestre et de l'année, journalisation.
     */
    public function saisirNote(InscriptionAcademique $inscription, array $donnees): Note
    {
        return DB::transaction(function () use ($inscription, $donnees) {
            $semestre = Semestre::where('inscription_academique_id', $inscription->id)
                ->where('numero', $donnees['semestre_numero'])
                ->lockForUpdate()
                ->firstOrFail();

            /** @var Matiere $matiere */
            $matiere = Matiere::findOrFail($donnees['matiere_id']);

            $this->verifierCoherenceMatiere($inscription, $semestre, $matiere);

            $note = Note::create([
                'etudiant_id' => $inscription->etudiant_id,
                'inscription_academique_id' => $inscription->id,
                'semestre_id' => $semestre->id,
                'matiere_id' => $matiere->id,
                'note' => $donnees['note'],
                'coefficient' => $matiere->coefficient,
                'credits' => $matiere->credits,
                'session' => $donnees['session'],
                'observation' => $donnees['observation'] ?? null,
            ]);

            $this->calculs->recalculerInscription($inscription);

            // Phase 14 §14.6 : toute écriture de note invalide les
            // délibérations préparées dépendantes (module gardé).
            $this->invaliderDeliberations($inscription);

            $this->audit->tracer(
                $inscription->etudiant,
                HistoriqueAcademique::ACTION_CREATION_NOTE,
                'note',
                $note->id,
                null,
                [
                    'matiere' => $matiere->code,
                    'note' => (float) $note->note,
                    'session' => $note->session,
                ],
            );

            return $note;
        });
    }

    /**
     * Modifie une note : verrouillage pessimiste anti-concurrence,
     * recalcul complet et journal ancienne/nouvelle valeur.
     *
     * L'autorisation (notes verrouillées réservées aux rôles supérieurs)
     * est arbitrée par NotePolicy — le service reste purement technique.
     */
    public function modifierNote(Note $note, array $donnees): Note
    {
        return DB::transaction(function () use ($note, $donnees) {
            /** @var Note $verrouillee */
            $verrouillee = Note::whereKey($note->id)->lockForUpdate()->firstOrFail();

            $ancienneNote = (float) $verrouillee->note;

            $verrouillee->update([
                'note' => $donnees['note'],
                'observation' => $donnees['observation'] ?? $verrouillee->observation,
            ]);

            $this->calculs->recalculerInscription($verrouillee->inscriptionAcademique);

            // Phase 14 §14.6 : invalidation des délibérations dépendantes.
            $this->invaliderDeliberations($verrouillee->inscriptionAcademique);

            $this->audit->tracer(
                $verrouillee->etudiant,
                HistoriqueAcademique::ACTION_MODIFICATION_NOTE,
                'note',
                $verrouillee->id,
                ['note' => $ancienneNote],
                [
                    'note' => (float) $donnees['note'],
                    'raison' => $donnees['raison'] ?? null,
                ],
            );

            return $verrouillee;
        });
    }

    /**
     * Supprime une note puis recalcule le semestre et l'année.
     */
    public function supprimerNote(Note $note): void
    {
        DB::transaction(function () use ($note) {
            $ancienne = ['matiere' => $note->matiere?->code, 'note' => (float) $note->note, 'session' => $note->session];

            $note->delete();

            $inscription = InscriptionAcademique::find($note->inscription_academique_id);

            if ($inscription) {
                $this->calculs->recalculerInscription($inscription);
                $this->invaliderDeliberations($inscription);
            }

            $etudiant = Etudiant::find($note->etudiant_id);

            if ($etudiant) {
                $this->audit->tracer(
                    $etudiant,
                    HistoriqueAcademique::ACTION_SUPPRESSION_NOTE,
                    'note',
                    $note->id,
                    $ancienne,
                );
            }
        });
    }

    /**
     * Verrouille / déverrouille une note (réservé aux rôles supérieurs).
     */
    public function basculerVerrou(Note $note, bool $verrouiller): void
    {
        DB::transaction(function () use ($note, $verrouiller) {
            $note->update(['locked_at' => $verrouiller ? now() : null]);

            $this->audit->tracer(
                $note->etudiant,
                HistoriqueAcademique::ACTION_VERROUILLAGE_NOTE,
                'note',
                $note->id,
                ['verrouillee' => ! $verrouiller],
                ['verrouillee' => $verrouiller],
            );
        });
    }

    /**
     * Invalidation des délibérations dépendantes (Phase 14 §14.6) :
     * déléguée au module Evaluation s'il est actif, silencieuse sinon
     * (jamais de 500 pour une écriture de note).
     */
    private function invaliderDeliberations(InscriptionAcademique $inscription): void
    {
        if (! config('modules.evaluation.active')) {
            return;
        }

        app(DeliberationService::class)
            ->marquerResultatsInvalides($inscription);
    }

    /**
     * Cohérence stricte : la matière doit correspondre au niveau de
     * l'inscription et au numéro de semestre visé.
     */
    private function verifierCoherenceMatiere(InscriptionAcademique $inscription, Semestre $semestre, Matiere $matiere): void
    {
        if ($matiere->niveau !== $inscription->niveau) {
            throw ValidationException::withMessages([
                'matiere_id' => "La matière « {$matiere->code} » n'appartient pas au niveau {$inscription->niveau}.",
            ]);
        }

        if ((int) $matiere->semestre !== (int) $semestre->numero) {
            throw ValidationException::withMessages([
                'matiere_id' => "La matière « {$matiere->code} » n'est pas enseignée au semestre {$semestre->libelle}.",
            ]);
        }
    }
}
