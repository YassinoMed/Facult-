<?php

namespace App\Services\DataExchange\Definitions;

use App\Models\User;
use App\Modules\Evaluation\Models\Candidat;
use App\Modules\Evaluation\Models\Epreuve;
use App\Modules\Evaluation\Models\EvaluationCompostage;
use App\Modules\Evaluation\Models\NoteEpreuve;
use App\Modules\Evaluation\Services\SaisieNoteService;
use App\Services\DataExchange\Contracts\ImportContextuel;
use App\Services\DataExchange\Contracts\ImportDefinitionInterface;
use App\Services\DataExchange\Contracts\ImportSansPii;
use App\Services\DataExchange\Contracts\ImportValidationAvancee;
use App\Services\Io\IoException;

/**
 * Import anonyme des notes d'épreuve (§LOOP IMPORT MULTI-FORMAT) :
 * colonnes `compostage,note` uniquement. Le contexte épreuve vient
 * de la route (jamais du fichier) ; la résolution CMP → étudiant et
 * toutes les règles métier sont déléguées à SaisieNoteService
 * (jamais recopiées, jamais d'écriture directe en `notes`).
 */
class NoteEpreuveAnonymeDefinition implements ImportContextuel, ImportDefinitionInterface, ImportSansPii, ImportValidationAvancee
{
    private ?Epreuve $epreuve = null;

    public function entite(): string
    {
        return 'notes-epreuve-anonymes';
    }

    public function libelle(): string
    {
        return 'Notes d’épreuve anonymes (compostage)';
    }

    public function colonnes(): array
    {
        return ['compostage', 'note'];
    }

    public function colonnesRequises(): array
    {
        return ['compostage'];
    }

    public function aliasesColonnes(): array
    {
        return [
            'compostage' => ['numero', 'n_compostage', 'num_compostage', 'code_compostage'],
            'note' => ['valeur', 'note_20', 'note_sur_20'],
        ];
    }

    public function colonnesInterdites(): array
    {
        return [
            'student_id', 'etudiant_id', 'id_etudiant', 'etudiant',
            'cin', 'c_i_n', 'email', 'e_mail', 'telephone', 'tel',
            'nom', 'prenom', 'matricule', 'num_inscription',
            'date_naissance', 'naissance', 'adresse',
        ];
    }

    public function regles(): array
    {
        return [
            'compostage' => ['required', 'string', 'regex:/^CMP-\d{6}$/i', 'max:11'],
            'note' => ['nullable', 'numeric'],
        ];
    }

    public function cleDoublon(): array
    {
        return ['compostage'];
    }

    public function definirContexte(array $contexte): void
    {
        $epreuveId = $contexte['epreuve_id'] ?? null;
        $this->epreuve = is_numeric($epreuveId) ? Epreuve::find((int) $epreuveId) : null;
    }

    public function epreuve(): ?Epreuve
    {
        return $this->epreuve;
    }

    public function trouverExistant(array $ligne): ?object
    {
        $compostage = $this->resoudre($ligne);

        if ($compostage === null || $this->epreuve === null) {
            return null;
        }

        return NoteEpreuve::where('epreuve_id', $this->epreuve->id)
            ->where('etudiant_id', $compostage->etudiant_id)
            ->first();
    }

    public function validerLigneMetier(array $ligne, ?int $auteurId): array
    {
        if ($this->epreuve === null) {
            return [$this->erreur($ligne, 'epreuve', 'Contexte épreuve manquant (HORS_CONTEXTE).', 'HORS_CONTEXTE')];
        }

        $epreuve = $this->epreuve;

        if ($epreuve->estVerrouillee()) {
            return [$this->erreur($ligne, 'epreuve', 'Épreuve verrouillée : import interdit (EPREUVE_LOCKED).', 'EPREUVE_LOCKED')];
        }

        if ($auteurId !== null) {
            $auteur = User::find($auteurId);

            if ($auteur !== null && $epreuve->faculteId() !== null && ! $auteur->peutAccederFaculte($epreuve->faculteId())) {
                return [$this->erreur($ligne, 'epreuve', 'Épreuve hors de votre périmètre faculté (HORS_PERIMETRE).', 'HORS_PERIMETRE')];
            }
        }

        $compostage = EvaluationCompostage::where('epreuve_id', $epreuve->id)
            ->where('numero', mb_strtoupper(trim((string) ($ligne['compostage'] ?? ''))))
            ->first();

        if ($compostage === null) {
            return [$this->erreur($ligne, 'compostage', "Numéro {$ligne['compostage']} inconnu pour cette épreuve (COMPOSTAGE_NOT_FOUND).", 'COMPOSTAGE_NOT_FOUND')];
        }

        $candidat = Candidat::where('epreuve_id', $epreuve->id)
            ->where('etudiant_id', $compostage->etudiant_id)
            ->first();

        if ($candidat === null) {
            return [$this->erreur($ligne, 'compostage', "Numéro {$ligne['compostage']} sans candidature à cette épreuve (CANDIDAT_NOT_ELIGIBLE).", 'CANDIDAT_NOT_ELIGIBLE')];
        }

        if ($ligne['note'] !== null && ((float) $ligne['note'] < 0 || (float) $ligne['note'] > (float) $epreuve->note_max)) {
            return [$this->erreur($ligne, 'note', "Note hors bornes 0..{$epreuve->note_max} (NOTE_OUT_OF_RANGE).", 'NOTE_OUT_OF_RANGE')];
        }

        $existante = NoteEpreuve::where('epreuve_id', $epreuve->id)
            ->where('etudiant_id', $compostage->etudiant_id)
            ->first();

        if ($existante !== null && $existante->estVerrouillee()
            && (string) ($ligne['note'] ?? '') !== (string) $existante->valeur) {
            return [$this->erreur($ligne, 'note', "Note verrouillée pour {$ligne['compostage']} : utilisez la correction autorisée (NOTE_VERROUILLEE).", 'NOTE_VERROUILLEE')];
        }

        return [];
    }

    public function cleMetier(array $ligne): ?string
    {
        if ($this->epreuve === null) {
            return null;
        }

        $compostage = $this->resoudre($ligne);

        if ($compostage === null) {
            return null;
        }

        return "epreuve:{$this->epreuve->id}|etudiant:{$compostage->etudiant_id}";
    }

    public function persister(array $ligne, string $mode, ?int $auteurId): string
    {
        if ($this->epreuve === null) {
            throw IoException::malforme('contexte épreuve manquant.');
        }

        $compostage = $this->resoudre($ligne);

        if ($compostage === null) {
            throw IoException::malforme("numéro {$ligne['compostage']} inconnu pour cette épreuve.");
        }

        $resultat = app(SaisieNoteService::class)->saisirLotParCompostages(
            $this->epreuve,
            [['numero' => $compostage->numero, 'valeur' => $ligne['note'] ?? null]],
            (int) $auteurId
        );

        if ($resultat['erreurs'] !== []) {
            throw IoException::malforme((string) reset($resultat['erreurs']));
        }

        return "saisie : {$compostage->numero} ({$this->epreuve->code})";
    }

    public function modes(): array
    {
        return ['creer', 'upsert'];
    }

    public function vider(?int $auteurId): void
    {
        throw IoException::malforme('le remplacement est interdit sur les notes (jamais de vidage).');
    }

    private function resoudre(array $ligne): ?EvaluationCompostage
    {
        if ($this->epreuve === null) {
            return null;
        }

        return EvaluationCompostage::where('epreuve_id', $this->epreuve->id)
            ->where('numero', mb_strtoupper(trim((string) ($ligne['compostage'] ?? ''))))
            ->first();
    }

    /**
     * @param  array<string, mixed>  $ligne
     * @return array{champ: string, message: string, code: string}
     */
    private function erreur(array $ligne, string $champ, string $message, string $code = 'COMPOSTAGE_NOT_FOUND'): array
    {
        return ['champ' => $champ, 'message' => $message." (ligne {$ligne['ligne']}).", 'code' => $code];
    }
}
