<?php

namespace App\Console\Commands;

use App\Models\AnomalieQualite;
use App\Models\Etudiant;
use App\Services\Verification\VerificationEngine;
use App\Services\Verification\VerificationResult;
use Illuminate\Console\Command;

/**
 * Commande artisan de vérification des dossiers étudiants.
 *
 * Usage :
 *   php artisan dossier:verifier                       # Tous les étudiants
 *   php artisan dossier:verifier --etudiant=42          # Un seul
 *   php artisan dossier:verifier --faculte=1            # Scopé faculté
 *   php artisan dossier:verifier --sync                 # Persiste dans anomalies_qualite
 *   php artisan dossier:verifier --limite=50            # Limiter le nombre
 *
 * LECTURE SEULE par défaut — --sync persiste via le système existant.
 */
class VerifierDossier extends Command
{
    protected $signature = 'dossier:verifier
        {--etudiant= : ID d\'un étudiant spécifique}
        {--faculte= : Limiter à une faculté}
        {--sync : Persister les anomalies dans la table anomalies_qualite}
        {--limite=100 : Nombre maximum d\'étudiants à vérifier}';

    protected $description = 'Vérification intelligente des dossiers étudiants.';

    public function handle(VerificationEngine $engine): int
    {
        $debut = microtime(true);

        // Mode unitaire.
        if ($etudiantId = $this->option('etudiant')) {
            $etudiant = Etudiant::find((int) $etudiantId);

            if ($etudiant === null) {
                $this->error("Étudiant #{$etudiantId} introuvable.");

                return self::FAILURE;
            }

            $resultat = $engine->verifier($etudiant);
            $this->afficherResultat($etudiant, $resultat);

            if ($this->option('sync')) {
                $this->synchroniser($etudiant, $resultat);
            }

            return self::SUCCESS;
        }

        // Mode bulk.
        $query = Etudiant::query()
            ->when($this->option('faculte'), fn ($q, $f) => $q->where('faculte_id', (int) $f));

        $limite = max(1, (int) $this->option('limite'));
        $resultats = $engine->verifierBulk($query, $limite);

        $conformes = 0;
        $aVerifier = 0;
        $bloquants = 0;

        foreach ($resultats as $etudiantId => $resultat) {
            match ($resultat->verdict) {
                VerificationResult::CONFORME => $conformes++,
                VerificationResult::A_VERIFIER => $aVerifier++,
                VerificationResult::BLOQUANT => $bloquants++,
            };
        }

        $this->info("Vérification terminée : {$conformes} conformes, {$aVerifier} à vérifier, {$bloquants} bloquants.");

        if ($this->option('sync')) {
            $syncs = 0;

            foreach ($resultats as $etudiantId => $resultat) {
                if (! $resultat->estConforme()) {
                    $etudiant = Etudiant::find($etudiantId);

                    if ($etudiant !== null) {
                        $syncs += $this->synchroniser($etudiant, $resultat);
                    }
                }
            }

            $this->info("{$syncs} anomalie(s) synchronisée(s) dans anomalies_qualite.");
        }

        $duree = round(microtime(true) - $debut, 2);
        $this->line("Durée totale : {$duree}s.");

        return self::SUCCESS;
    }

    private function afficherResultat(Etudiant $etudiant, VerificationResult $resultat): void
    {
        $badge = match ($resultat->verdict) {
            VerificationResult::CONFORME => '<fg=green>✓ CONFORME</>',
            VerificationResult::A_VERIFIER => '<fg=yellow>⚠ À VÉRIFIER</>',
            VerificationResult::BLOQUANT => '<fg=red>✗ BLOQUANT</>',
        };

        $this->line('');
        $this->line("Étudiant #{$etudiant->id} ({$etudiant->nom_complet}) : {$badge}");
        $this->line('  Règles exécutées : '.count($resultat->reglesExecutees));
        $this->line("  Anomalies : {$resultat->nombreAnomalies()}");
        $this->line("  Durée : {$resultat->dureeMs} ms");

        foreach ($resultat->anomalies as $anomalie) {
            $gravite = match ($anomalie->gravite) {
                'bloquant' => '<fg=red>[BLOQUANT]</>',
                'erreur' => '<fg=red>[ERREUR]</>',
                'avertissement' => '<fg=yellow>[AVERT.]</>',
                default => '<fg=gray>[INFO]</>',
            };

            $this->line("    {$gravite} [{$anomalie->categorie}] {$anomalie->message}");
            $this->line("      → {$anomalie->recommandation}");
        }
    }

    private function synchroniser(Etudiant $etudiant, VerificationResult $resultat): int
    {
        $creees = 0;

        foreach ($resultat->anomalies as $anomalie) {
            $cleUnique = sha1("verification_dossier|{$anomalie->gravite}|{$anomalie->message}|{$etudiant->id}");
            $gravite = $anomalie->gravite === 'bloquant' ? 'critique' : $anomalie->gravite;

            $existante = AnomalieQualite::where('cle_unique', $cleUnique)->first();

            if ($existante === null) {
                AnomalieQualite::create([
                    'cle_unique' => $cleUnique,
                    'source' => 'verification_dossier',
                    'gravite' => $gravite,
                    'entite_type' => 'etudiant',
                    'entite_id' => $etudiant->id,
                    'entite_libelle' => 'Étudiant '.($etudiant->num_inscription ?? "#{$etudiant->id}"),
                    'message' => $anomalie->message,
                    'lien' => route('admin.etudiants.show', $etudiant),
                    'action_recommandee' => $anomalie->recommandation,
                    'statut' => 'ouverte',
                    'faculte_id' => $etudiant->faculte_id,
                    'derniere_detection_a' => now(),
                ]);
                $creees++;
            } else {
                $existante->update(['derniere_detection_a' => now()]);
            }
        }

        return $creees;
    }
}
