<?php

namespace App\Support;

/**
 * Accès au détail des sondes de supervision (LOOP-SECURITY-REMEDIATION-01).
 *
 * Les endpoints `/api/v1/ready` et `/api/v1/version` restent publics pour
 * les équilibreurs de charge, mais ne renvoient plus QUE l'état
 * (`status`) : le détail (noms de bases, pilotes de cache, chemins de
 * stockage, occupation disque, environnement, versions internes) n'est
 * servi qu'aux adresses IP déclarées dans `SUPERVISION_ALLOWED_IPS`.
 */
final class SupervisionAccess
{
    public static function autorisee(?string $ip): bool
    {
        if ($ip === null || $ip === '') {
            return false;
        }

        $autorisees = array_values(array_filter(array_map(
            fn ($valeur) => trim((string) $valeur),
            (array) config('security.supervision_allowed_ips', [])
        )));

        return in_array($ip, $autorisees, true);
    }
}
