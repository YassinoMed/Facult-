<?php

namespace App\Services;

use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\HistoriqueAcademique;
use App\Models\InscriptionAcademique;
use App\Models\SituationEtudiant;
use App\Models\User;
use App\Modules\Referentiel\Models\Niveau;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\Schedule\Models\Group;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Création administrative d'un étudiant complet.
 *
 * Le service orchestre exclusivement les composants existants :
 * `ArchiveService` (inscription + semestres + audit), `AffectationService`
 * (groupe + historique), `StudentStatusService` (statut + historique) et
 * `AuditService` (traçabilité). Le paiement est un simple indicateur
 * oui/non porté par l'inscription : aucun montant, aucune facture et
 * aucune transaction locale ne sont créés (paiement externe).
 * Aucune table ni règle métier n'est dupliquée.
 *
 * La validation immédiate de l'inscription est un acte administratif
 * explicite : elle est tracée avec un motif obligatoire, sans passer par
 * le contrôle documentaire public (décision produit validée).
 */
final class CreationEtudiantAdminService
{
    public function __construct(
        private readonly ArchiveService $archives,
        private readonly AffectationService $affectations,
        private readonly StudentStatusService $statuts,
        private readonly AuditService $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $donnees  Données validées par StoreEtudiantAdminRequest.
     * @return array{etudiant: Etudiant, inscription: InscriptionAcademique, mot_de_passe: string}
     *
     * @throws DomainException
     */
    public function creer(array $donnees, User $auteur): array
    {
        $faculte = Faculte::findOrFail((int) $donnees['faculte_id']);
        $programme = Programme::findOrFail((int) $donnees['programme_id']);
        $niveau = Niveau::findOrFail((int) $donnees['niveau_id']);
        $annee = AnneeUniversitaire::findOrFail((int) $donnees['annee_universitaire_id']);
        $groupe = ! empty($donnees['groupe_id']) ? Group::findOrFail((int) $donnees['groupe_id']) : null;

        // Défense en profondeur : le FormRequest valide déjà ces règles,
        // mais le service reste sûr s'il est appelé depuis un autre point.
        if (! $auteur->peutAccederFaculte((int) $faculte->id)) {
            throw new DomainException('Cette faculté est hors de votre périmètre.');
        }

        if (! $programme->actif || (int) $programme->faculte_id !== (int) $faculte->id) {
            throw new DomainException('Le parcours sélectionné n’appartient pas à cette faculté ou est inactif.');
        }

        if (! $niveau->actif || (int) $niveau->programme_id !== (int) $programme->id) {
            throw new DomainException('Le niveau sélectionné n’appartient pas à ce parcours ou est inactif.');
        }

        if ($groupe !== null) {
            if (! $groupe->actif) {
                throw new DomainException('Le groupe sélectionné est inactif.');
            }

            if ((int) $groupe->faculte_id !== (int) $faculte->id) {
                throw new DomainException('Le groupe sélectionné n’appartient pas à cette faculté.');
            }

            if ($groupe->programme_id !== null && (int) $groupe->programme_id !== (int) $programme->id) {
                throw new DomainException('Le groupe sélectionné n’appartient pas à ce parcours.');
            }

            if ($groupe->niveau_id !== null && (int) $groupe->niveau_id !== (int) $niveau->id) {
                throw new DomainException('Le groupe sélectionné n’appartient pas à ce niveau.');
            }

            if ($groupe->niveau_id === null && $groupe->niveau !== null && $groupe->niveau !== $niveau->code) {
                throw new DomainException('Le groupe sélectionné n’appartient pas à ce niveau.');
            }

            // Les groupes historiques peuvent ne pas porter d’année ; dans
            // ce cas seulement, ils restent utilisables. Un groupe rattaché
            // à une autre année ne doit jamais être affecté silencieusement.
            if ($groupe->annee_universitaire_id !== null
                && (int) $groupe->annee_universitaire_id !== (int) $annee->id) {
                throw new DomainException('Le groupe sélectionné n’appartient pas à cette année universitaire.');
            }
        }

        $motDePasse = Str::password(16, symbols: false);
        $motif = trim((string) $donnees['motif_validation']);

        [$etudiant, $inscription] = DB::transaction(function () use ($donnees, $auteur, $faculte, $programme, $niveau, $annee, $groupe, $motDePasse, $motif): array {
            $etudiant = Etudiant::create([
                'cin' => $donnees['cin'],
                'identifiant_national' => ($donnees['identifiant_national'] ?? null) ?: null,
                'nom' => $donnees['nom'],
                'prenom' => $donnees['prenom'],
                'email' => $donnees['email'],
                'telephone' => ($donnees['telephone'] ?? null) ?: null,
                'date_naissance' => $donnees['date_naissance'],
                'sexe' => $donnees['sexe'],
                'faculte_id' => $faculte->id,
                'programme_id' => $programme->id,
                'niveau_id' => $niveau->id,
                'niveau' => $niveau->code,
                'annee_academique' => $annee->libelle,
                'adresse' => $donnees['adresse'],
                'ville' => $donnees['ville'],
                'code_postal' => $donnees['code_postal'],
                'annee_bac' => $donnees['annee_bac'],
                'mention_bac' => ($donnees['mention_bac'] ?? null) ?: null,
                'password' => $motDePasse,
            ]);

            $inscription = $this->archives->creerInscription($etudiant, [
                'annee_universitaire_id' => $annee->id,
                'programme_id' => $programme->id,
                'niveau_id' => $niveau->id,
                'niveau' => $niveau->code,
                'filiere' => $programme->nom,
                'groupe' => $groupe?->code,
                'statut' => 'en_cours',
                'type' => $donnees['type_inscription'],
                'regime' => $donnees['regime'],
                'paye' => filter_var($donnees['paye'] ?? false, FILTER_VALIDATE_BOOLEAN),
                'observation' => $motif,
            ]);

            // Validation administrative explicite, tracée et motivée.
            // On n'appelle pas InscriptionService::transiter() : le choix
            // produit est de valider sans exiger les pièces, mais jamais
            // sans motif ni sans historique.
            $inscription->update(['statut' => 'valide']);

            SituationEtudiant::create([
                'etudiant_id' => $etudiant->id,
                'type' => 'note',
                'ancienne_valeur' => 'inscription:en_cours',
                'nouvelle_valeur' => 'inscription:valide',
                'motif' => $motif,
                'auteur_id' => $auteur->id,
                'date_effet' => now()->toDateString(),
            ]);

            $this->audit->tracer(
                $etudiant,
                HistoriqueAcademique::ACTION_MODIFICATION_INSCRIPTION,
                'inscription',
                $inscription->id,
                ['statut' => 'en_cours'],
                ['statut' => 'valide', 'motif' => $motif, 'source' => 'creation_administrative'],
            );

            if ($groupe !== null) {
                $this->affectations->affecter(
                    $etudiant,
                    $groupe,
                    $annee,
                    (int) $auteur->id,
                    // Le motif d'affectation est un varchar(255) : on garde
                    // le motif complet dans l'inscription, l'audit et le
                    // statut, mais on tronque la copie d'affectation.
                    mb_substr('Création administrative — '.$motif, 0, 255),
                    false,
                    null,
                    null,
                    false,
                );
            }

            $this->statuts->changer($etudiant, 'valide', (int) $auteur->id, $motif);

            $this->audit->tracer(
                $etudiant,
                HistoriqueAcademique::ACTION_CREATION_DOSSIER,
                'etudiant',
                $etudiant->id,
                null,
                [
                    'source' => 'admin.etudiants.creer',
                    'statut' => 'valide',
                    'faculte' => $faculte->code,
                    'programme' => $programme->code,
                    'niveau' => $niveau->code,
                    'groupe' => $groupe?->code,
                    'motif' => $motif,
                ],
            );

            return [$etudiant->fresh(), $inscription->fresh()];
        });

        return [
            'etudiant' => $etudiant,
            'inscription' => $inscription,
            'mot_de_passe' => $motDePasse,
        ];
    }
}
