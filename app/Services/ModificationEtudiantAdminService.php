<?php

namespace App\Services;

use App\Events\PopulationChanged;
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

/**
 * Modification administrative d'un étudiant existant.
 *
 * L'unité modifiée est double : la fiche `etudiants` ET l'inscription
 * courante (`inscriptions_academiques`) de l'étudiant, dans UNE seule
 * transaction. Les inscriptions antérieures sont de l'historique et
 * relèvent de l'écran `admin.archives.show` ; l'année suivante se crée
 * via `admin.inscriptions.progresser`.
 *
 * Principes invariants :
 * - aucune duplication de règle métier : le statut, le groupe, le mot de
 *   passe et le numéro d'inscription ne sont jamais touchés ici (workflows
 *   dédiés) ;
 * - le rattachement LMD n'est propagé vers l'inscription courante QUE si
 *   le LMD de la fiche a réellement changé (anti-dérive sans écraser les
 *   colonnes en texte libre de l'écran `admin.archives.show`) ;
 * - l'incompatibilité de groupe n'est opposée QUE pour un rattachement
 *   NOUVEAU : une incompatibilité préexistante ne bloque pas les autres
 *   corrections ;
 * - une seule source pour l'inscription courante (scope
 *   `InscriptionAcademique::courante()`) ;
 * - aucune PII dans l'audit : uniquement les NOMS des champs modifiés et
 *   des valeurs non nominatives (codes faculté/parcours/niveau, type,
 *   régime, paiement) — `AGENTS.md` « No PII in logs ».
 */
final class ModificationEtudiantAdminService
{
    public function __construct(
        private readonly AuditService $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $donnees  Données validées par UpdateEtudiantAdminRequest.
     *
     * @throws DomainException
     */
    public function modifier(Etudiant $etudiant, array $donnees, User $auteur): Etudiant
    {
        $faculte = Faculte::findOrFail((int) $donnees['faculte_id']);
        $programme = Programme::findOrFail((int) $donnees['programme_id']);
        $niveau = Niveau::findOrFail((int) $donnees['niveau_id']);

        // Source unique de l'inscription courante : l'année universitaire
        // la plus récente (scope `InscriptionAcademique::courante()`).
        // Les inscriptions antérieures ne sont pas éditables ici
        // (écran `admin.archives.show`).
        //
        // Résolution PROPRE à la couche service : le FormRequest en a fait
        // une autre pour ses propres règles (deux couches distinctes, donc
        // deux résolutions légitimes). Le service ne peut pas réutiliser
        // celle du FormRequest : il doit rester appelable hors formulaire.
        $inscription = InscriptionAcademique::courante()
            ->where('inscriptions_academiques.etudiant_id', $etudiant->id)
            ->first();

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

        $programmeAvantCode = $this->codeProgramme($etudiant->programme_id);

        // 1) Diff de la fiche `etudiants` (champs éditables uniquement).
        $aEcrire = [];
        $champsModifies = [];

        foreach ($this->valeursEtudiant($etudiant, $donnees, $faculte, $programme, $niveau) as $cle => $cible) {
            if (! $this->memeValeur($this->valeurEtudiantCourante($etudiant, $cle), $cible)) {
                $aEcrire[$cle] = $cible;
                $champsModifies[] = $cle;
            }
        }

        // 2) Le rattachement LMD a-t-il RÉELLEMENT bougé ?
        $lmdModifie = $this->lmdModifie($champsModifies);

        // 3) Incompatibilité de groupe : le groupe courant appartient à un
        //    triplet (faculté, parcours, niveau). Le refus ne porte QUE sur
        //    un rattachement NOUVEAU. Une incompatibilité PRÉEXISTANTE
        //    (dérive de données, fréquente en base de travail) n'est pas le
        //    fait de ce formulaire : elle ne doit pas interdire une simple
        //    correction d'identité ou de contact. Le groupe se corrige par
        //    son propre workflow (affectation historisée).
        if ($lmdModifie && $etudiant->groupe_id !== null) {
            $groupe = Group::find((int) $etudiant->groupe_id);

            if ($groupe !== null && ! $this->groupeCompatible($groupe, $faculte, $programme, $niveau)) {
                throw new DomainException(sprintf(
                    'Le groupe actuel (%s) n’appartient pas au nouveau rattachement. Changez ou retirez d’abord le groupe avant de modifier le parcours.',
                    $groupe->code
                ));
            }
        }

        // 4) Diff de l'inscription courante.
        $aEcrireInscription = [];
        $valeursInscriptionApres = [];

        if ($inscription !== null) {
            $valeursInscriptionApres = $this->valeursInscription($donnees, $inscription, $programme, $niveau, $lmdModifie);

            foreach ($valeursInscriptionApres as $cle => $cible) {
                if (! $this->memeValeur($this->valeurInscriptionCourante($inscription, $cle), $cible)) {
                    $aEcrireInscription[$cle] = $cible;
                    $champsModifies[] = 'inscription.'.$cle;
                }
            }
        }

        // Aucun changement : pas d'entrée d'audit vide, pas d'écriture.
        if ($champsModifies === []) {
            throw new DomainException('Aucune modification à enregistrer.');
        }

        $typeSituation = $lmdModifie ? 'programme' : 'note';

        $avant = $this->journalSansPii(
            $champsModifies,
            $this->codeFaculte($etudiant->faculte_id),
            $programmeAvantCode,
            (string) $etudiant->niveau,
            $inscription === null ? null : [
                'type' => $inscription->type,
                'regime' => $inscription->regime,
                'paye' => (bool) $inscription->paye,
            ],
        );

        $apres = $this->journalSansPii(
            $champsModifies,
            (string) $faculte->code,
            (string) $programme->code,
            (string) $niveau->code,
            null,
            $valeursInscriptionApres === [] ? null : [
                'type' => $valeursInscriptionApres['type'] ?? null,
                'regime' => $valeursInscriptionApres['regime'] ?? null,
                'paye' => $valeursInscriptionApres['paye'] ?? null,
            ],
        );

        $motif = trim((string) $donnees['motif_modification']);

        $etudiantModifie = DB::transaction(function () use ($etudiant, $aEcrire, $inscription, $aEcrireInscription, $typeSituation, $lmdModifie, $programmeAvantCode, $programme, $motif, $auteur, $avant, $apres): Etudiant {
            if ($aEcrire !== []) {
                $etudiant->update($aEcrire);
            }

            if ($inscription !== null && $aEcrireInscription !== []) {
                $inscription->update($aEcrireInscription);
            }

            SituationEtudiant::create([
                'etudiant_id' => $etudiant->id,
                'type' => $typeSituation,
                'ancienne_valeur' => $lmdModifie ? $programmeAvantCode : 'dossier',
                'nouvelle_valeur' => $lmdModifie ? (string) $programme->code : 'dossier',
                'motif' => $motif,
                'auteur_id' => $auteur->id,
                'date_effet' => now()->toDateString(),
            ]);

            $this->audit->tracer(
                $etudiant,
                HistoriqueAcademique::ACTION_MODIFICATION_DOSSIER,
                'etudiant',
                (int) $etudiant->id,
                $avant,
                $apres,
                'web',
            );

            return $etudiant->fresh();
        });

        // Data-mesh : signalé APRÈS commit (l'état écrit est définitif).
        PopulationChanged::dispatch(
            'etudiants',
            'modification',
            (int) $etudiantModifie->id,
            (int) $auteur->id,
            ['champs' => $champsModifies]
        );

        return $etudiantModifie;
    }

    /**
     * Le groupe appartient-il au triplet (faculté, parcours, niveau) visé ?
     */
    private function groupeCompatible(Group $groupe, Faculte $faculte, Programme $programme, Niveau $niveau): bool
    {
        if ((int) $groupe->faculte_id !== (int) $faculte->id) {
            return false;
        }

        if ($groupe->programme_id !== null && (int) $groupe->programme_id !== (int) $programme->id) {
            return false;
        }

        if ($groupe->niveau_id !== null) {
            return (int) $groupe->niveau_id === (int) $niveau->id;
        }

        // Groupe sans niveau_id : on compare le code de niveau.
        return $groupe->niveau === null || $groupe->niveau === $niveau->code;
    }

    /**
     * Valeurs cibles de la fiche `etudiants` (champs éditables uniquement).
     *
     * Les champs optionnels (`identifiant_national`, `telephone`,
     * `mention_bac`) suivent la règle §2.5.b :
     * - clé ABSENTE du POST → valeur COURANTE conservée (inchangé) ;
     * - clé présente mais VIDE → `null` (effacement volontaire).
     *
     * @param  array<string, mixed>  $donnees
     * @return array<string, mixed>
     */
    private function valeursEtudiant(
        Etudiant $etudiant,
        array $donnees,
        Faculte $faculte,
        Programme $programme,
        Niveau $niveau,
    ): array {
        return [
            'cin' => $donnees['cin'],
            'identifiant_national' => $this->valeurOptionnelleOuCourante($donnees, 'identifiant_national', $etudiant->identifiant_national),
            'nom' => $donnees['nom'],
            'prenom' => $donnees['prenom'],
            'email' => $donnees['email'],
            'telephone' => $this->valeurOptionnelleOuCourante($donnees, 'telephone', $etudiant->telephone),
            'date_naissance' => $donnees['date_naissance'],
            'sexe' => $donnees['sexe'],
            'faculte_id' => (int) $faculte->id,
            'programme_id' => (int) $programme->id,
            'niveau_id' => (int) $niveau->id,
            'niveau' => (string) $niveau->code,
            'adresse' => $donnees['adresse'],
            'ville' => $donnees['ville'],
            'code_postal' => $donnees['code_postal'],
            'annee_bac' => $donnees['annee_bac'],
            'mention_bac' => $this->valeurOptionnelleOuCourante($donnees, 'mention_bac', $etudiant->mention_bac),
        ];
    }

    /**
     * Champ textuel optionnel : ABSENT = INCHANGÉ (valeur courante),
     * présent mais VIDE = effacement VOLONTAIRE (`null`).
     *
     * §2.5.b : « une absence de champ est traitée comme "inchangé", jamais
     * comme "remit à zéro" ». Un POST partiel (client non-navigateur) ne
     * doit donc pas vider `identifiant_national`, `telephone` ni
     * `mention_bac`.
     *
     * @param  array<string, mixed>  $donnees
     */
    private function valeurOptionnelleOuCourante(array $donnees, string $cle, mixed $courant): mixed
    {
        if (! array_key_exists($cle, $donnees)) {
            return $courant;
        }

        $valeur = $donnees[$cle];

        return ($valeur === null || $valeur === '') ? null : $valeur;
    }

    /**
     * Valeurs courantes de la fiche `etudiants` (lecture, sans écriture).
     */
    private function valeurEtudiantCourante(Etudiant $etudiant, string $cle): mixed
    {
        return match ($cle) {
            'faculte_id' => (int) $etudiant->faculte_id,
            'programme_id' => $etudiant->programme_id !== null ? (int) $etudiant->programme_id : null,
            'niveau_id' => $etudiant->niveau_id !== null ? (int) $etudiant->niveau_id : null,
            'date_naissance' => $etudiant->date_naissance?->format('Y-m-d'),
            default => $etudiant->{$cle},
        };
    }

    /**
     * Valeurs cibles de l'inscription courante.
     *
     * - `type`, `regime` et `paye` sont TOUJOURS des champs de ce
     *   formulaire : en POST partiel, la valeur COURANTE de l'inscription
     *   sert de repli (jamais 'premiere'/'initiale'/0 par défaut, qui
     *   écraseraient silencieusement le type, le régime et le paiement
     *   réels) ;
     * - la propagation LMD (`programme_id`, `niveau_id`, `niveau`,
     *   `filiere`) n'a lieu QUE si le rattachement de la fiche a réellement
     *   changé : sinon ces colonnes restent la propriété de l'écran
     *   `admin.archives.show`, qui les édite en texte libre.
     *
     * `statut`, `groupe` et `observation` ne sont jamais modifiés ici.
     *
     * @param  array<string, mixed>  $donnees
     * @return array<string, mixed>
     */
    private function valeursInscription(
        array $donnees,
        InscriptionAcademique $inscription,
        Programme $programme,
        Niveau $niveau,
        bool $lmdModifie,
    ): array {
        $valeurs = [
            'type' => $donnees['type_inscription'] ?? $inscription->type,
            'regime' => $donnees['regime'] ?? $inscription->regime,
            'paye' => filter_var($donnees['paye'] ?? $inscription->paye, FILTER_VALIDATE_BOOLEAN),
        ];

        if ($lmdModifie) {
            $valeurs['programme_id'] = (int) $programme->id;
            $valeurs['niveau_id'] = (int) $niveau->id;
            $valeurs['niveau'] = (string) $niveau->code;
            $valeurs['filiere'] = (string) $programme->nom;
        }

        return $valeurs;
    }

    /**
     * Valeurs courantes de l'inscription (lecture, sans écriture).
     */
    private function valeurInscriptionCourante(InscriptionAcademique $inscription, string $cle): mixed
    {
        return match ($cle) {
            'programme_id' => $inscription->programme_id !== null ? (int) $inscription->programme_id : null,
            'niveau_id' => $inscription->niveau_id !== null ? (int) $inscription->niveau_id : null,
            'paye' => (bool) $inscription->paye,
            default => $inscription->{$cle},
        };
    }

    /**
     * Le rattachement LMD de la fiche a-t-il bougé ?
     *
     * Le déclencheur est constitué des **IDS** uniquement (`faculte_id`,
     * `programme_id`, `niveau_id`). `etudiants.niveau` est un TEXTE DÉRIVÉ
     * de `niveau_id` (`Niveau::code`) : une simple dérive de ce texte ne
     * doit donc PAS déclencher la propagation, sinon l'inscription
     * courante verrait ses colonnes en texte libre (`niveau`, `filiere`),
     * éditées par l'écran `admin.archives.show`, écrasées à tort (§2.4).
     *
     * @param  array<int, string>  $champsModifies
     */
    private function lmdModifie(array $champsModifies): bool
    {
        foreach (['faculte_id', 'programme_id', 'niveau_id'] as $cle) {
            if (in_array($cle, $champsModifies, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Contenu du journal d'audit : NOMS des champs modifiés + valeurs
     * NON nominatives uniquement (aucune valeur de CIN, email, nom, prénom,
     * adresse, téléphone, date de naissance ou identifiant national).
     *
     * @param  array<int, string>  $champsModifies
     * @param  array<string, mixed>|null  $inscriptionAvant  Type, régime et paiement AVANT.
     * @param  array<string, mixed>|null  $inscriptionApres  Type, régime et paiement APRÈS.
     * @return array<string, mixed>
     */
    private function journalSansPii(
        array $champsModifies,
        string $faculte,
        string $programme,
        string $niveau,
        ?array $inscriptionAvant = null,
        ?array $inscriptionApres = null,
    ): array {
        return [
            'champs_modifies' => $champsModifies,
            'faculte' => $faculte,
            'programme' => $programme,
            'niveau' => $niveau,
            'type' => $inscriptionApres['type'] ?? $inscriptionAvant['type'] ?? null,
            'regime' => $inscriptionApres['regime'] ?? $inscriptionAvant['regime'] ?? null,
            'paye' => $inscriptionApres['paye'] ?? $inscriptionAvant['paye'] ?? null,
        ];
    }

    /**
     * Comparaison tolérante : un booléen est comparé comme un booléen, un
     * champ numérique numériquement, un champ texte sans espaces superflus.
     */
    private function memeValeur(mixed $actuel, mixed $cible): bool
    {
        // Booléens : branche explicite AVANT le numérique — sinon
        // `(string) false` === '' et `(string) true` === '1' ne
        // compareraient qu'accidentellement.
        if (is_bool($actuel) || is_bool($cible)) {
            return (bool) $actuel === (bool) $cible;
        }

        if (is_int($actuel) || is_int($cible)) {
            return (int) $actuel === (int) $cible;
        }

        if ($actuel === null || $cible === null) {
            return $actuel === null && $cible === null;
        }

        return trim((string) $actuel) === trim((string) $cible);
    }

    private function codeFaculte(?int $faculteId): string
    {
        return $faculteId !== null ? (string) (Faculte::find($faculteId)?->code ?? '') : '';
    }

    private function codeProgramme(?int $programmeId): string
    {
        return $programmeId !== null ? (string) (Programme::find($programmeId)?->code ?? '') : '';
    }
}
