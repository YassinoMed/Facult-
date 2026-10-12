<?php

namespace App\Modules\Evaluation\Services;

use App\Models\Etudiant;
use App\Modules\Evaluation\Events\EvaluationChanged;
use App\Modules\Evaluation\Models\Epreuve;
use App\Modules\Evaluation\Models\EvaluationCompostage;
use App\Services\AuditService;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Numéros de compostage anonymes (§3-§5, §14, §20) : un numéro
 * aléatoire `CMP-XXXXXX` par couple (épreuve × étudiant), jamais
 * séquentiel (ni ordre ni effectif déductibles).
 *
 * Idempotent et sûr en concurrence : contrainte UNIQUE + reprise
 * sur conflit, jamais deux numéros pour le même couple.
 */
class CompostageService
{
    public function __construct(private readonly AuditService $audit) {}

    /**
     * Numéro existant ou création atomique (idempotent).
     */
    public function getOrCreate(Epreuve $epreuve, Etudiant $etudiant): EvaluationCompostage
    {
        $existant = EvaluationCompostage::where('epreuve_id', $epreuve->id)
            ->where('etudiant_id', $etudiant->id)
            ->first();

        if ($existant !== null) {
            return $existant;
        }

        if ($epreuve->estVerrouillee()) {
            throw new DomainException('Épreuve verrouillée : génération interdite.');
        }

        for ($essai = 0; $essai < 10; $essai++) {
            try {
                return DB::transaction(fn () => EvaluationCompostage::create([
                    'epreuve_id' => $epreuve->id,
                    'etudiant_id' => $etudiant->id,
                    'numero' => $this->genererNumero($epreuve->id),
                ]));
            } catch (QueryException $e) {
                // Collision de numéro ou création concurrente : on relit.
                $relire = EvaluationCompostage::where('epreuve_id', $epreuve->id)
                    ->where('etudiant_id', $etudiant->id)
                    ->first();

                if ($relire !== null) {
                    return $relire;
                }
                // Sinon collision sur `numero` : nouvel essai.
            }
        }

        throw new DomainException('Génération impossible : réessayez.');
    }

    /**
     * Génère les numéros manquants pour tous les candidats convoqués.
     *
     * @return array{crees: int, existants: int}
     */
    public function genererPourEpreuve(Epreuve $epreuve, int $auteurId): array
    {
        if ($epreuve->estVerrouillee()) {
            throw new DomainException('Épreuve verrouillée : génération interdite.');
        }

        $epreuve->loadMissing('candidats');
        $crees = 0;

        foreach ($epreuve->candidats as $candidat) {
            $avant = EvaluationCompostage::where('epreuve_id', $epreuve->id)
                ->where('etudiant_id', $candidat->etudiant_id)
                ->exists();

            $compostage = $this->getOrCreate($epreuve, $candidat->etudiant);

            if (! $avant) {
                $crees++;
                $this->audit->tracer($candidat->etudiant, 'compostage.generated', 'epreuve', $epreuve->id, null, [
                    'numero' => $compostage->numero,
                ]);
            }
        }

        EvaluationChanged::dispatch('compostages.generes', $epreuve->id);

        return ['crees' => $crees, 'existants' => $epreuve->candidats->count() - $crees];
    }

    /**
     * Génère un numéro de compostage (alias de getOrCreate conforme à §5).
     */
    public function generate(Epreuve $epreuve, ?Etudiant $etudiant = null): EvaluationCompostage
    {
        if ($etudiant === null) {
            throw new DomainException('Un étudiant doit être fourni pour générer un compostage.');
        }

        return $this->getOrCreate($epreuve, $etudiant);
    }

    /**
     * Vérifie l'existence et la validité d'un numéro pour l'épreuve (§5).
     */
    public function verify(Epreuve $epreuve, string $numero): ?EvaluationCompostage
    {
        return $this->resoudre($epreuve, $numero);
    }

    /**
     * Résolution serveur numero → ligne (jamais depuis un student_id
     * client). Null si inconnu ou hors épreuve (IDOR/BOLA → 404).
     */
    public function resoudre(Epreuve $epreuve, string $numero): ?EvaluationCompostage
    {
        return EvaluationCompostage::where('epreuve_id', $epreuve->id)
            ->where('numero', mb_strtoupper(trim($numero)))
            ->first();
    }

    public function verrouiller(EvaluationCompostage $compostage): void
    {
        if ($compostage->estVerrouille()) {
            return;
        }

        $compostage->update(['verrouille_at' => now()]);
    }

    private function genererNumero(int $epreuveId): string
    {
        for ($essai = 0; $essai < 20; $essai++) {
            $numero = 'CMP-'.random_int(100000, 999999);

            $existe = EvaluationCompostage::where('epreuve_id', $epreuveId)
                ->where('numero', $numero)
                ->exists();

            if (! $existe) {
                return $numero;
            }
        }

        return 'CMP-'.random_int(100000, 999999);
    }
}
