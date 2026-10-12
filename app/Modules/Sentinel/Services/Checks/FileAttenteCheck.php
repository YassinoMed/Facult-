<?php

namespace App\Modules\Sentinel\Services\Checks;

use App\Contracts\SentinelCheckInterface;
use App\Services\ParametreService;
use Illuminate\Support\Facades\DB;

/**
 * INFRA_FILE (avertissement/erreur) : jobs en échec et embouteillage.
 * Seuils paramétrés (`sentinel.file_echecs_seuil` défaut 5,
 * `sentinel.file_attente_seuil` défaut 1000). Comptages bornés, lecture
 * seule ; file inaccessible = erreur (jamais silencieuse).
 */
class FileAttenteCheck implements SentinelCheckInterface
{
    public function __construct(
        private readonly ParametreService $parametres,
    ) {}

    public function code(): string
    {
        return 'INFRA_FILE';
    }

    public function categorie(): string
    {
        return 'infrastructure';
    }

    public function gravite(): string
    {
        return 'avertissement';
    }

    public function module(): ?string
    {
        return 'infrastructure';
    }

    public function controler(): array
    {
        try {
            $echecs = DB::table('failed_jobs')->count();
            $attente = DB::table('jobs')->count();
        } catch (\Throwable) {
            return [[
                'gravite' => 'erreur',
                'titre' => 'File d’attente inaccessible',
                'diagnostic' => 'Les tables `jobs`/`failed_jobs` sont illisibles (pilote ou migration).',
                'recommandation' => 'Vérifier QUEUE_CONNECTION et appliquer les migrations de file.',
            ]];
        }

        $constats = [];
        $seuilEchecs = max(1, (int) $this->parametres->get('sentinel.file_echecs_seuil', 5));
        $seuilAttente = max(1, (int) $this->parametres->get('sentinel.file_attente_seuil', 1000));

        if ($echecs >= $seuilEchecs) {
            $constats[] = [
                'gravite' => 'erreur',
                'titre' => "{$echecs} job(s) en échec (seuil {$seuilEchecs})",
                'diagnostic' => "{$echecs} lignes en `failed_jobs`. Les traitements concernés n’ont pas abouti.",
                'recommandation' => 'Examiner `failed_jobs` (exception, file), corriger la cause puis relancer (`queue:retry`) ou purger.',
            ];
        }

        if ($attente >= $seuilAttente) {
            $constats[] = [
                'titre' => "{$attente} job(s) en attente (seuil {$seuilAttente})",
                'diagnostic' => 'Embouteillage : les workers ne suivent pas (ou sont arrêtés).',
                'recommandation' => 'Vérifier que les workers consomment les files (`queue:work`), puis dimensionner.',
            ];
        }

        return $constats;
    }
}
