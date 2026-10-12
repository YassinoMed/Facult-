<?php

namespace App\Services\Verification;

use App\Models\Etudiant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;

/**
 * Moteur de vérification intelligent du dossier étudiant (§ vérificateur).
 *
 * Orchestrateur : exécute chaque règle enregistrée, agrège les
 * anomalies, calcule le verdict. LECTURE SEULE — ne modifie jamais
 * les données.
 *
 * Les règles sont injectées via le container (tagged binding
 * 'verification.regles') dans le ServiceProvider.
 */
class VerificationEngine
{
    /** @var VerificationRule[] */
    private array $regles;

    /**
     * @param  iterable<VerificationRule>  $regles
     */
    public function __construct(iterable $regles)
    {
        $this->regles = $regles instanceof \Traversable
            ? iterator_to_array($regles)
            : (array) $regles;
    }

    /**
     * Vérifier un dossier étudiant complet.
     *
     * Eager-loads les relations nécessaires pour éviter les N+1.
     */
    public function verifier(Etudiant $etudiant): VerificationResult
    {
        $debut = microtime(true);

        // Pré-chargement des relations fréquemment utilisées par les règles.
        $etudiant->loadMissing([
            'inscriptionsAcademiques.anneeUniversitaire',
            'situations',
            'documentsAcademiques',
        ]);

        $anomalies = [];
        $reglesExecutees = [];

        foreach ($this->regles as $regle) {
            $reglesExecutees[] = $regle->code();

            try {
                $resultats = $regle->verifier($etudiant);

                foreach ($resultats as $anomalie) {
                    $anomalies[] = $anomalie;
                }
            } catch (\Throwable $e) {
                // Fail-soft : une règle qui échoue ne bloque pas le reste.
                // On enregistre l'échec comme anomalie info (sans PII dans le message).
                $anomalies[] = new Anomalie(
                    regle: $regle->code(),
                    categorie: $regle->categorie(),
                    gravite: 'info',
                    message: "Règle « {$regle->libelle()} » : erreur d'exécution.",
                    justification: 'Exception technique lors de l\'exécution de la règle (détail dans les logs).',
                    recommandation: 'Contacter l\'administrateur système.',
                );

                // Log sans PII (§24-25).
                Log::warning('Vérification dossier : erreur règle', [
                    'regle' => $regle->code(),
                    'etudiant_id' => $etudiant->id,
                    'erreur' => $e->getMessage(),
                ]);
            }
        }

        $dureeMs = round((microtime(true) - $debut) * 1000, 2);

        return new VerificationResult(
            verdict: VerificationResult::calculerVerdict($anomalies),
            anomalies: $anomalies,
            reglesExecutees: $reglesExecutees,
            dureeMs: $dureeMs,
            etudiantId: $etudiant->id,
        );
    }

    /**
     * Vérification en masse via cursor (pas de N+1 global).
     *
     * @return array<int, VerificationResult> Résultats indexés par ID étudiant.
     */
    public function verifierBulk(Builder $query, int $limite = 100): array
    {
        $resultats = [];

        $query->with([
            'inscriptionsAcademiques.anneeUniversitaire',
            'situations',
            'documentsAcademiques',
        ])->limit($limite)->chunk(50, function ($etudiants) use (&$resultats) {
            foreach ($etudiants as $etudiant) {
                $resultats[$etudiant->id] = $this->verifier($etudiant);
            }
        });

        return $resultats;
    }

    /**
     * Règles enregistrées.
     *
     * @return VerificationRule[]
     */
    public function regles(): array
    {
        return $this->regles;
    }

    /**
     * Convertit les anomalies d'un résultat vers le format
     * attendu par AgregateurQualite.
     *
     * @return array<int, array{gravite: string, entite: string, lien: string, probleme: string, action: string}>
     */
    public function versFormatQualite(VerificationResult $resultat, string $numInscription): array
    {
        return array_map(
            fn (Anomalie $a) => $a->versFormatQualite($resultat->etudiantId, $numInscription),
            $resultat->anomalies,
        );
    }
}
