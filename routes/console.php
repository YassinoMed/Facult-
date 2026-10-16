<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// §82 — Purge horaire des exports expirés (données personnelles)
Schedule::command('exports:purge')->hourly();

// Phase 5 — Escalade SLA quotidienne + purge hebdomadaire notifs
Schedule::command('demandes:verifier-sla')->dailyAt('07:30');
Schedule::command('notifications:purger')->weekly();

// Phases 7-9 — Génération quotidienne des tâches (idempotente)
Schedule::command('taches:generer')->dailyAt('06:30');

// Phase 15 — Campagnes + publication planifiée des annonces
Schedule::command('campagnes:envoyer')->everyFifteenMinutes();
Schedule::command('annonces:publier')->everyFifteenMinutes();
Schedule::command('campagnes-courriel:traiter-planifiees')->everyFiveMinutes();

// Module Présences : rapprochement périodique des présences enseignants
Schedule::command('presences:rapprocher-enseignants')->everyThirtyMinutes();

// Phase 19 — Balayage qualité hebdomadaire + tâches
Schedule::command('qualite:auditer --sync-tasks')->weeklyOn(1, '03:00');

// Phase 21 — Supervision : heartbeat scheduler, alertes ops, rétention logs
Schedule::call(fn () => Cache::put('supervision:scheduler-dernier', now()->toDateTimeString(), 600))
    ->everyFiveMinutes()->name('supervision-heartbeat');
Schedule::command('supervision:controler')->everyFiveMinutes();
Schedule::command('maintenance:purger-logs')->weeklyOn(0, '04:00');

// Platform Sentinel : ronde de contrôles transverse (incidents dédupliqués).
Schedule::command('sentinel:controler')->everyFifteenMinutes();

// Phase 22 — Expiration documentaire quotidienne (sans suppression)
Schedule::command('documents:marquer-expires')->dailyAt('02:30');

// Roadmap « restes connus » (05/10/2026) — purge des fichiers GED sans
// ligne en base (racines documentaires uniquement, journalisé).
Schedule::command('documents:purger-orphelins --execute')->weeklyOn(0, '04:30');

// B1 — Notifications des périodes d'inscription (ouverture réelle +
// clôture imminente), idempotent par période (avertie_*_at).
Schedule::command('periodes:avertir')->dailyAt('07:45');

// Phase 10 — Préchauffage quotidien du cache BI (heures creuses)
Schedule::command('bi:prewarmer')->dailyAt('04:30');

// Sauvegardes planifiées (horaire par défaut, configurable) : l'évaluateur
// décide, verrouille et délègue au job — jamais d'exécution concurrente.
// Remplace le créneau fixe (le planning vit en `sauvegarde.plan.*`).
Schedule::command('sauvegarde:evaluer')->everyFifteenMinutes();
