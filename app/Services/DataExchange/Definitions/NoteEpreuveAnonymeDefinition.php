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
use App\Services\DataExchange\Contracts\ImportLotInterface;
use App\Services\DataExchange\Contracts\ImportSansPii;
use App\Services\DataExchange\Contracts\ImportValidationAvancee;
use App\Services\Io\IoException;
use Illuminate\Support\Collection;

/**
 * Import anonyme des notes d'épreuve (§LOOP IMPORT MULTI-FORMAT) :
 * colonnes `compostage,note` uniquement. Le contexte épreuve vient
 * de la route (jamais du fichier) ; la résolution CMP → étudiant et
 * toutes les règles métier sont déléguées à SaisieNoteService
 * (jamais recopiées, jamais d'écriture directe en `notes`).
 */
class NoteEpreuveAnonymeDefinition implements ImportContextuel, ImportDefinitionInterface, ImportLotInterface, ImportSansPii, ImportValidationAvancee
{
    private ?Epreuve $epreuve = null;

    /** @var Collection<string, EvaluationCompostage>|null */
    private ?Collection $compostages = null;

    /** @var Collection<int, Candidat>|null */
    private ?Collection $candidats = null;

    /** @var Collection<int, NoteEpreuve>|null */
    private ?Collection $notesExistantes = null;

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

        if ($this->epreuve !== null) {
            $this->compostages = EvaluationCompostage::where('epreuve_id', $this->epreuve->id)
                ->get()
                ->keyBy(fn ($c) => mb_strtoupper(trim($c->numero)));
            $this->candidats = Candidat::where('epreuve_id', $this->epreuve->id)
                ->with('etudiant')
                ->get()
                ->keyBy('etudiant_id');
            $this->notesExistantes = NoteEpreuve::where('epreuve_id', $this->epreuve->id)
                ->get()
                ->keyBy('etudiant_id');
        } else {
            $this->compostages = null;
            $this->candidats = null;
            $this->notesExistantes = null;
        }
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

        if ($this->notesExistantes !== null) {
            return $this->notesExistantes->get($compostage->etudiant_id);
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

        $auteur = $auteurId !== null ? User::find($auteurId) : null;

        if ($auteur !== null && $epreuve->faculteId() !== null && ! $auteur->peutAccederFaculte($epreuve->faculteId())) {
            return [$this->erreur($ligne, 'epreuve', 'Épreuve hors de votre périmètre faculté (HORS_PERIMETRE).', 'HORS_PERIMETRE')];
        }

        $compostage = $this->resoudre($ligne);

        if ($compostage === null) {
            return [$this->erreur($ligne, 'compostage', "Numéro {$ligne['compostage']} inconnu pour cette épreuve (COMPOSTAGE_NOT_FOUND).", 'COMPOSTAGE_NOT_FOUND')];
        }

        $candidat = $this->candidats !== null
            ? $this->candidats->get($compostage->etudiant_id)
            : Candidat::where('epreuve_id', $epreuve->id)
                ->where('etudiant_id', $compostage->etudiant_id)
                ->with('etudiant')
                ->first();

        if ($candidat === null) {
            return [$this->erreur($ligne, 'compostage', "Numéro {$ligne['compostage']} sans candidature à cette épreuve (CANDIDAT_NOT_ELIGIBLE).", 'CANDIDAT_NOT_ELIGIBLE')];
        }

        if ($auteur !== null && isset($candidat->etudiant?->faculte_id) && ! $auteur->peutAccederFaculte((int) $candidat->etudiant->faculte_id)) {
            return [$this->erreur($ligne, 'compostage', 'Étudiant hors de votre périmètre faculté (HORS_PERIMETRE).', 'HORS_PERIMETRE')];
        }

        if ($candidat->estAbsent() && $ligne['note'] !== null && $ligne['note'] !== '') {
            return [$this->erreur($ligne, 'note', "Candidat absent pour {$ligne['compostage']} : saisie interdite (CANDIDAT_ABSENT).", 'CANDIDAT_ABSENT')];
        }

        if ($ligne['note'] !== null && ((float) $ligne['note'] < 0 || (float) $ligne['note'] > (float) $epreuve->note_max)) {
            return [$this->erreur($ligne, 'note', "Note hors bornes 0..{$epreuve->note_max} (NOTE_OUT_OF_RANGE).", 'NOTE_OUT_OF_RANGE')];
        }

        $existante = $this->notesExistantes !== null
            ? $this->notesExistantes->get($compostage->etudiant_id)
            : NoteEpreuve::where('epreuve_id', $epreuve->id)
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

        // Le mode est une garantie serveur : en création seule, une note
        // déjà présente ne doit jamais être silencieusement écrasée.
        if ($mode === 'creer' && $this->trouverExistant($ligne) !== null) {
            throw IoException::malforme(
                "note déjà existante pour le compostage {$compostage->numero} (MODE_CREATION_ONLY)."
            );
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

    /**
     * Persistance groupée : une seule résolution de compostages et un
     * seul appel à `saisirLot`, au lieu d'un appel par ligne.
     */
    public function persisterLot(array $lignes, string $mode, ?int $auteurId): int
    {
        if ($this->epreuve === null) {
            throw IoException::malforme('contexte épreuve manquant.');
        }

        if ($lignes === []) {
            return 0;
        }

        $anonymes = [];
        $numerosParEtudiant = [];

        foreach ($lignes as $ligne) {
            $compostage = $this->resoudre($ligne);

            if ($compostage === null) {
                throw IoException::malforme("numéro {$ligne['compostage']} inconnu pour cette épreuve.");
            }

            $anonymes[] = [
                'numero' => $compostage->numero,
                'valeur' => $ligne['note'] ?? null,
            ];
            $numerosParEtudiant[$compostage->etudiant_id] = $compostage->numero;
        }

        if ($mode === 'creer') {
            $existants = NoteEpreuve::where('epreuve_id', $this->epreuve->id)
                ->whereIn('etudiant_id', array_keys($numerosParEtudiant))
                ->pluck('etudiant_id')
                ->all();

            if ($existants !== []) {
                $etudiantId = (int) reset($existants);
                $numero = $numerosParEtudiant[$etudiantId] ?? "#{$etudiantId}";

                throw IoException::malforme(
                    "note déjà existante pour le compostage {$numero} (MODE_CREATION_ONLY)."
                );
            }
        }

        $resultat = app(SaisieNoteService::class)->saisirLotParCompostages(
            $this->epreuve,
            $anonymes,
            (int) $auteurId
        );

        if ($resultat['erreurs'] !== []) {
            throw IoException::malforme((string) reset($resultat['erreurs']));
        }

        return count($lignes);
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

        $numero = mb_strtoupper(trim((string) ($ligne['compostage'] ?? '')));

        if ($this->compostages !== null) {
            return $this->compostages->get($numero);
        }

        return EvaluationCompostage::where('epreuve_id', $this->epreuve->id)
            ->where('numero', $numero)
            ->first();
    }

    /**
     * @param  array<string, mixed>  $ligne
     * @return array{champ: string, message: string, code: string}
     */
    private function erreur(array $ligne, string $champ, string $message, string $code = 'COMPOSTAGE_NOT_FOUND'): array
    {
        $numLigne = $ligne['ligne'] ?? 1;

        return ['champ' => $champ, 'message' => $message." (ligne {$numLigne}).", 'code' => $code];
    }
}
