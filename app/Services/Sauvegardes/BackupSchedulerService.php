<?php

namespace App\Services\Sauvegardes;

use App\Models\DatabaseBackup;
use App\Services\ParametreService;
use Carbon\CarbonImmutable;

/**
 * Interprétation du planning administrateur (`sauvegarde.plan.*`) :
 * fréquences horaires, quotidienne et personnalisée (jours + heure).
 * Pur (aucune écriture) : `estDue()` décide, l'évaluateur exécute.
 * Valeurs invalides → défauts sûrs (jamais d'exception vers l'UI).
 */
class BackupSchedulerService
{
    public const FREQUENCES = [
        'horaire' => 1,
        'toutes_2h' => 2,
        'toutes_6h' => 6,
        'toutes_12h' => 12,
        'quotidienne' => 24,
        'personnalise' => null,
    ];

    private const JOURS_ISO = [
        'lundi' => 1, 'mardi' => 2, 'mercredi' => 3, 'jeudi' => 4,
        'vendredi' => 5, 'samedi' => 6, 'dimanche' => 7,
    ];

    public function __construct(
        private readonly ParametreService $parametres,
    ) {}

    /**
     * @return array{actif: bool, frequence: string, intervalle_heures: int, jours: array<int, int>, heure: string, retention_jours: int, destination: string, chiffrer: bool, alerter_echec: bool, alerter_retard: bool, alerter_succes: bool}
     */
    public function plan(): array
    {
        $frequence = (string) $this->parametres->get('sauvegarde.plan.frequence', 'horaire');

        if (! array_key_exists($frequence, self::FREQUENCES)) {
            $frequence = 'horaire';
        }

        $jours = [];

        foreach ((array) $this->parametres->get('sauvegarde.plan.jours', []) as $jour) {
            $cle = mb_strtolower(trim((string) $jour));

            if (isset(self::JOURS_ISO[$cle])) {
                $jours[] = self::JOURS_ISO[$cle];
            }
        }

        $heure = (string) $this->parametres->get('sauvegarde.plan.heure', '01:00');

        if (preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $heure) !== 1) {
            $heure = '01:00';
        }

        return [
            'actif' => (bool) $this->parametres->get('sauvegarde.plan.actif', true),
            'frequence' => $frequence,
            'intervalle_heures' => self::FREQUENCES[$frequence] ?? 24,
            'jours' => $jours === [] ? [1, 2, 3, 4, 5, 6, 7] : array_values(array_unique($jours)),
            'heure' => $heure,
            'retention_jours' => max(1, (int) $this->parametres->get('sauvegarde.plan.retention_jours', 14)),
            'destination' => $this->stockageDestination(),
            'chiffrer' => (bool) $this->parametres->get('sauvegarde.plan.chiffrer', false),
            'alerter_echec' => (bool) $this->parametres->get('sauvegarde.plan.alerter_echec', true),
            'alerter_retard' => (bool) $this->parametres->get('sauvegarde.plan.alerter_retard', true),
            'alerter_succes' => (bool) $this->parametres->get('sauvegarde.plan.alerter_succes', false),
        ];
    }

    /**
     * Clé de destination effective : valeur planifiée si elle désigne une
     * destination configurée, sinon `destination_par_defaut` (fail-closed
     * vers une destination explicite, jamais un chemin arbitraire).
     */
    public function stockageDestination(): string
    {
        $destinations = (array) config('sauvegardes.destinations', []);
        $voulee = (string) $this->parametres->get('sauvegarde.plan.destination', '');

        if ($voulee !== '' && isset($destinations[$voulee]['chemin'])) {
            return $voulee;
        }

        $defaut = (string) config('sauvegardes.destination_par_defaut', 'local_private');

        return isset($destinations[$defaut]) ? $defaut : (string) array_key_first($destinations);
    }

    public function estDue(?CarbonImmutable $maintenant = null): bool
    {
        $plan = $this->plan();

        if (! $plan['actif']) {
            return false;
        }

        $maintenant ??= CarbonImmutable::now();
        $dernier = DatabaseBackup::where('status', 'SUCCESS')->latest('completed_at')->first();

        if ($plan['frequence'] === 'personnalise' || $plan['frequence'] === 'quotidienne') {
            if (! in_array($maintenant->dayOfWeekIso, $plan['jours'], true)) {
                return false;
            }

            $creneau = $maintenant->setTime(
                (int) substr($plan['heure'], 0, 2), (int) substr($plan['heure'], 3, 2)
            );

            if ($maintenant->lessThan($creneau)) {
                return false;
            }

            return $dernier === null || $dernier->completed_at->lessThan($creneau);
        }

        // Fréquences horaires : échue si aucun succès ou fenêtre dépassée.
        return $dernier === null
            || $dernier->completed_at->lessThanOrEqualTo($maintenant->subHours($plan['intervalle_heures']));
    }

    public function prochaineExecution(?CarbonImmutable $maintenant = null): ?CarbonImmutable
    {
        $plan = $this->plan();

        if (! $plan['actif']) {
            return null;
        }

        $maintenant ??= CarbonImmutable::now();
        $dernier = DatabaseBackup::where('status', 'SUCCESS')->latest('completed_at')->first();

        if ($plan['frequence'] === 'personnalise' || $plan['frequence'] === 'quotidienne') {
            for ($decalage = 0; $decalage < 8; $decalage++) {
                $jour = $maintenant->addDays($decalage);

                if (! in_array($jour->dayOfWeekIso, $plan['jours'], true)) {
                    continue;
                }

                $creneau = $jour->setTime(
                    (int) substr($plan['heure'], 0, 2), (int) substr($plan['heure'], 3, 2)
                );

                if ($creneau->greaterThan($maintenant)
                    && ($dernier === null || $dernier->completed_at->lessThan($creneau))) {
                    return $creneau;
                }
            }

            return null;
        }

        $base = $dernier !== null && $dernier->completed_at->greaterThan($maintenant->subHours($plan['intervalle_heures']))
            ? CarbonImmutable::instance($dernier->completed_at)
            : $maintenant;

        return $base->addHours($plan['intervalle_heures']);
    }
}
