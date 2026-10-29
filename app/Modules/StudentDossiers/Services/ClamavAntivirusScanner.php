<?php

namespace App\Modules\StudentDossiers\Services;

use App\Modules\StudentDossiers\Contracts\AntivirusResult;
use App\Modules\StudentDossiers\Contracts\AntivirusScannerInterface;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Moteur ClamAV (clamdscan/clamscan), branché uniquement quand
 * `student-dossiers.antivirus.driver=clamav`.
 *
 * Politique : toutes les catégories de pièces sont fail-closed. Si le
 * binaire est absent, dépasse le délai, retourne un code d'erreur ou un
 * fichier illisible, le fichier N'EST JAMAIS publié (le stockage le
 * supprime). Aucune catégorie ne peut être marquée « saine » sans preuve
 * d'analyse. Le mode fail-open n'est volontairement pas exposé : il
 * reviendrait à approuver un fichier non vérifié.
 */
class ClamavAntivirusScanner implements AntivirusScannerInterface
{
    public function analyser(array $contexte): AntivirusResult
    {
        $disk = (string) ($contexte['disk'] ?? 'private');
        $chemin = (string) ($contexte['storage_path'] ?? '');
        $categorie = (string) ($contexte['categorie'] ?? 'other');

        if ($chemin === '' || ! Storage::disk($disk)->exists($chemin)) {
            return new AntivirusResult(
                sain: false,
                erreur: 'fichier introuvable pour l’analyse antivirus',
            );
        }

        try {
            $contenu = Storage::disk($disk)->get($chemin);
        } catch (Throwable) {
            return new AntivirusResult(
                sain: false,
                erreur: 'fichier illisible pour l’analyse antivirus',
            );
        }

        if (! is_string($contenu) || $contenu === '') {
            return new AntivirusResult(
                sain: false,
                erreur: 'fichier vide ou illisible pour l’analyse antivirus',
            );
        }

        $binaire = (string) config('student-dossiers.antivirus.clamav.binary', 'clamdscan');
        $timeout = max(1, (int) config('student-dossiers.antivirus.clamav.timeout', 30));

        try {
            $process = new Process(
                $this->commande($binaire),
                null,
                null,
                $contenu,
                $timeout,
            );
            $process->run();
        } catch (ProcessTimedOutException) {
            return $this->indisponible($categorie, 'délai d’analyse antivirus dépassé');
        } catch (ProcessFailedException) {
            return $this->indisponible($categorie, 'moteur antivirus en échec');
        } catch (Throwable) {
            return $this->indisponible($categorie, 'moteur antivirus indisponible');
        }

        $code = $process->getExitCode();

        // ClamAV : 0 = sain, 1 = menace détectée, 2+ = erreur moteur.
        if ($code === 0) {
            return new AntivirusResult(sain: true);
        }

        if ($code === 1) {
            $sortie = trim($process->getOutput()."\n".$process->getErrorOutput());
            $signature = null;

            if (preg_match('/:\s*(.+?)\s+FOUND\b/', $sortie, $correspondance) === 1) {
                $signature = mb_substr(trim($correspondance[1]), 0, 180);
            }

            return new AntivirusResult(sain: false, signature: $signature);
        }

        if (in_array($code, [126, 127], true)) {
            return $this->indisponible($categorie, 'moteur antivirus introuvable ou non exécutable');
        }

        return $this->indisponible(
            $categorie,
            'moteur antivirus en erreur (code '.($code ?? 'inconnu').')',
        );
    }

    /**
     * Ligne de commande ClamAV, isolée pour les tests de politique sans
     * dépendre d'un vrai binaire installé sur la machine de CI.
     *
     * @return array<int, string>
     */
    protected function commande(string $binaire): array
    {
        $mode = (string) config('student-dossiers.antivirus.clamav.mode', '');

        // `clamscan` et `clamdscan` lisent le flux d'entrée uniquement
        // lorsqu'on leur passe explicitement `-`. Sans ce `-`, clamdscan
        // peut scanner le répertoire courant et retourner « sain » sans
        // avoir analysé le fichier téléversé : le `-` est donc obligatoire.
        // Le mode documenté pour stdin est `clamdscan -` (transport FILDES
        // ou INSTREAM choisi par ClamAV) ; `--stream` reste une option
        // explicite de débogage, uniquement pour clamdscan.
        $forcerStream = $mode === 'stream' && basename($binaire) !== 'clamscan';

        return $forcerStream
            ? [$binaire, '--no-summary', '--stream', '-']
            : [$binaire, '--no-summary', '-'];
    }

    /**
     * Toutes les catégories sont configurées fail-closed par défaut. Le
     * paramètre existe pour rendre la politique explicite et testable,
     * jamais pour approuver silencieusement un fichier non analysé.
     */
    private function indisponible(string $categorie, string $raison): AntivirusResult
    {
        // Même une catégorie absente de la liste fail-closed est refusée :
        // une mauvaise configuration ne doit jamais approuver un fichier
        // non analysé.
        /** @var array<int, string> $failClosed */
        $failClosed = config('student-dossiers.antivirus.categories_fail_closed', []);

        if (! in_array($categorie, $failClosed, true)) {
            $raison .= ' (catégorie non couverte — refus par sécurité)';
        }

        return new AntivirusResult(sain: false, erreur: $raison);
    }
}
