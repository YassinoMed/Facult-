# Platform Sentinel — rapport final (10/10/2026)

Couche transverse de surveillance, vérification d'intégrité et alertes.
Zéro donnée de production modifiée ; migrations dédiées uniquement.

## 1. Architecture initiale et finale

Initial : sondes (`SupervisionService`, `/ready`, `/admin/system`), alerting
ops (`supervision:controler`, cooldown cache), qualité (`AnomalieQualite` +
agrégateur + cockpit), notifications (service + dispatcher), bus d'events —
mais aucun registre de contrôles, aucune vérification métier périodique,
aucun incident transverse dédupliqué, aucun cockpit unifié.
Final : module `sentinel` (`MODULE_SENTINEL=false`) qui **orchestre**
l'existant — contrat `SentinelCheckInterface`, `SentinelRegistry`
(config), `SentinelService` (ronde isolée, dédup, auto-résolution,
cooldown DB, fail-soft), 2 tables, 11 contrôles, listener `JobFailed`,
cockpit 6 routes, commande planifiée toutes les 15 minutes.
AUCUN doublon : pas de 2ᵉ audit, log, centre d'anomalies, sender,
alerting, handler ou bus (revue §10 de l'audit).

## 2. Fichiers créés et modifiés

Créés : contrat `app/Contracts/SentinelCheckInterface.php`, module
`app/Modules/Sentinel/` (2 modèles, registry, service, 6 contrôles,
policy, request, contrôleur, routes, 3 vues), `app/Console/Commands/
ControlerSentinel.php`, `config/sentinel.php`, migration
`2026_10_10_093042_creer_tables_sentinel.php`, `tests/Feature/
Sentinel{,Metier,Convergence}Test.php` (29 tests), ce rapport.
Co-édités 2ᵉ main (convergés, couverts) : 5 contrôles
(`StockagePrive`, `ConfigurationPlateforme`, `OperationsSensibles`,
`ConflitExamens`, `DossierPiecesRequises` — PII `nom → #id` corrigée),
`Jobs/ExecuterRondeSentinelJob.php` (optionnel, non dispatché),
`Listeners/CapterJobEchoueSentinel.php` (gardé module, sans boucle).
Modifiés : `config/modules.php` (+bloc/nav), `.env.example`
(`MODULE_SENTINEL=false`), `.env` local (`=true`), `Permissions.php`
(`sentinel.view|manage` + matrices), `ModuleServiceProvider` (singleton,
policy, listener, routes), `routes/console.php` (+1 ligne), `AGENTS.md`.

## 3. Migrations et commandes nécessaires

`php artisan migrate` (2 tables : `sentinel_regles`, `sentinel_incidents`).
Ronde : `php artisan sentinel:controler [--check=CODE] [--strict]`
(manuel, bouton cockpit throttlé 5/min, ou planificateur). Workers :
file `database` existante, `queue:work` standard (aucune file dédiée
requise). Scheduler : cron `schedule:run` (GUIDE-DEPLOY §7.3).

## 4. Mécanisme réel et fréquence

Ronde synchronisée toutes les 15 minutes (11 contrôles en ~20 ms sains,
comptages bornés) + exécution manuelle + `--check` ciblé. Chaque contrôle
isolé try/catch (panne journalisée `sentinel.controle_echec`, jamais
d'incident) ; constats dédupliqués `sha1(code|type|id)` (compteur++,
réouverture si résolu) ; contrôles silencieux → auto-résolution ;
critiques → notif super-admins (cooldown DB 60 min, `Log::warning`) ;
`JobFailed` → incident immédiat (erreur, cockpit seul). Incidents agrégés
par code (jamais d'explosion par ligne), sauf épreuves/dossiers (bornés
10/20, scopés faculté).

## 5. Cache et queues

Incidents TOUJOURS en base (jamais de cache seul) ; `Cache` best-effort
(dernière exécution indicative, source = `derniere_detection_le`) ;
cooldown en base (`derniere_notification_le`) — panne cache sans effet.
Queues : aucun job requis pour la ronde (rapide) ; job async disponible
pour analyses coûteuses futures ; listener `JobFailed` synchrone
fail-soft, sans dispatch (anti-boucle prouvée par test).

## 6. Permissions et risques de sécurité

`sentinel.view` (lecture cockpit, tous rôles) / `sentinel.manage`
(ronde, bascule, transitions — scolarité + super-admin). Scope faculté
sur incidents rattachés (403 hors périmètre, testé). Throttles 20/5-1.
Diagnostics sans PII (contrat + test PII dossier) ; pile jamais exposée
(messages ≤250c nettoyés) ; secrets masqués (regex) ; transitions
serveur verrouillées (422) ; audit `Log::info sentinel.*` (ids seuls).
Risque assumé : épreuves sans `faculte_id` (transverses visibles) ;
CSRF prod par défaut (non prouvable en suite).

## 7. Tests et benchmarks mesurés

29/29 verts (`SentinelTest` 16 : 404 off, cockpit, dedup, panne isolée,
règles, cooldown, IDOR, fiabilité, bornes ; `SentinelMetierTest` 4 ;
`SentinelConvergenceTest` 6 + PII + boucles). Voisins 67/67
(cohérence permissions, IDOR, drift OpenAPI, navigation). `pint` vert,
`route:list` 6 routes. Ronde saine : < 100 requêtes, < 30 s (mesuré
~20 ms/≈15 req). Aucun test désaffaibli ; 2 bugs trouvés par les tests
et corrigés (`firstOrCreate` sans refresh ; PII dossier).

## 8. Problèmes restants et limites connues

- Comparaison `heure_debut >= heure_fin` en chaînes (pilote-fragile si
  formats non normalisés) — à durcir si formats exotiques observés.
- `DossierPiecesRequisesCheck` heuristique (ignore la matrice des
  documents requis par type) — à brancher sur `DocumentsRequisService`
  si faux positifs signalés.
- Pas d'auto-refresh cockpit (bouton manuel) ; pas de widget dashboard ;
  pas d'API Sentinel (cockpit web seul).
- `ExecuterRondeSentinelJob` non dispatché (option documentée).
- Dépôt sans git : pas de diff revoyable.

## 9. Lancement workers et scheduler

Standard projet (aucune infra nouvelle) : `php artisan schedule:work`
(dev) ou cron `* * * * * php artisan schedule:run` (prod, GUIDE-DEPLOY
§7.3) ; `php artisan queue:work --queue=default,documents,
verification,notifications` ; `sentinel:controler` tourne dans le
scheduler (15 min) — vérifiable au cockpit (dernière détection).

## 10. Maintenance et prochaines améliorations

- Purge : incidents `resolu/ignore` anciens via `maintenance:purger-logs`
  (à étendre) ou commande dédiée ; seuils en `parametres`
  (`sentinel.file_echecs_seuil`, `file_attente_seuil`,
  `notif_cooldown_minutes`).
- Améliorations : `faculte_id` sur épreuves, comparateur base dans le
  détail, widget dashboard, API lecture, règle SLA/périodes, seuils par
  faculté, corrélation `request_id` inter-incidents.
