# Platform Sentinel — Phase 2 : audit de fiabilité et validation

- **Date** : 10/10/2026
- **Périmètre** : module `sentinel` (`app/Modules/Sentinel/`, `config/sentinel.php`, routes, commande, listener) — audit en lecture seule, **aucune réécriture**, aucun changement de règle métier.
- **Précision de contexte** : pendant l'audit, une co-édition livrait le chantier « Sauvegardes SQL planifiées » (2 contrôles `SAUVEGARDE_*` ajoutés en séance, tests réalignés ensuite). Tous les résultats ci-dessous correspondent à l'**état final convergé** (13 contrôles) ; l'épisode transitoire est documenté en §8.4.

**Légende de preuve** : ✅ Confirmé dans le code · ▶ Confirmé par un test exécuté · ◩ Déduit de l'architecture · ⚠ Anomalie confirmée · ✖ Non vérifié / absent.

---

## 1. Inventaire des contrôles : enregistrés, exécutés, couverts

Source d'enregistrement : `config/sentinel.php` (tableau `checks`), chargé par `App\Modules\Sentinel\Services\SentinelRegistry::controles()` — config + enregistrements à chaud (`enregistrer()`, utilisé par les tests). Les surcharges `actif/gravité/seuil` vivent en table `sentinel_regles` ; les défauts vivent dans chaque classe.

| # | Classe (✅ `app/Modules/Sentinel/Services/Checks/`) | Code | Gravité défaut | Test dédié (▶) |
|---|---|---|---|---|
| 1 | `BaseDonneesCheck` | `INFRA_BASE` | critique | ✖ pas de test Sentinel direct (délègue à `SupervisionService::sondes()`, couvert par `QualiteApiSupervisionTest`, 13/13 ▶) |
| 2 | `CacheCheck` | `INFRA_CACHE` | erreur | ✖ idem (délègue à la sonde cache) |
| 3 | `FileAttenteCheck` | `INFRA_FILE` | avertissement | ✖ aucun test dédié trouvé |
| 4 | `StockagePriveCheck` | `INFRA_STOCKAGE_PRIVE` | critique | ▶ `SentinelTest::test_stockage_prive_check_est_inscriptible` + `SentinelConvergenceTest::test_stockage_prive_sain_silencieux` |
| 5 | `ConfigurationPlateformeCheck` | `CONFIG_COHERENCE` | erreur | ▶ `SentinelTest::test_configuration_plateforme_detecte_absence_annee_active` + Convergence |
| 6 | `OperationsSensiblesCheck` | `SECURITE_COMPTES_ADMIN` | critique | ▶ `SentinelTest::test_operations_sensibles_detecte_absence_super_admin` + Convergence |
| 7 | `AffectationSansGroupeCheck` | `AFFECT_SANS_GROUPE` | avertissement | ▶ `SentinelMetierTest::test_sans_groupe_signale_puis_silence_apres_affectation` |
| 8 | `AffectationNiveauCheck` | `AFFECT_NIVEAU` | erreur | ▶ `SentinelMetierTest::test_niveau_incompatible_signale` |
| 9 | `GroupeSurcapaciteCheck` | `GROUPE_SURCAPACITE` | erreur | ▶ `SentinelMetierTest::test_surcapacite_signalee_complet_normal_silencieux` |
| 10 | `ConflitExamensCheck` | `EVAL_CONFLIT_EXAMENS` | erreur | ▶ `SentinelConvergenceTest::test_epreuve_horaires_incoherents_signalee` (⚠ périmètre réduit, cf. §6.2) |
| 11 | `DossierPiecesRequisesCheck` | `DOSSIER_PIECES_REQUISES` | avertissement | ▶ `SentinelConvergenceTest::test_dossier_sans_piece_signale_sans_pii` |
| 12 | `SauvegardeFraicheurCheck` | `SAUVEGARDE_FRAICHEUR` | critique | ▶ `BackupSentinelTest::test_fraicheur_sans_succes_puis_couverte` |
| 13 | `SauvegardeEchecsCheck` | `SAUVEGARDE_ECHECS` | erreur | ▶ `BackupSentinelTest::test_echecs_recents_agreges` |

Hors registre (sonde **événementielle**) : le listener `CapterJobEchoueSentinel` sur `Illuminate\Queue\Events\JobFailed` produit le code `INFRA_JOB_ECHOUE` (non listé dans `config/sentinel.php` ▶ couvert par `test_job_echoue_genere_incident_immediat_avec_sanitisation` + `test_job_echoue_deduplique_sur_echecs_multiples` + `test_listener_job_echoue_sans_boucle_ni_pii`).

**Comptage (mission §2)** : 13 configurés = 13 enregistrés = 13 planifiés (▶ mesuré : `executes=13` sur base réelle) + 1 sonde événementielle (`JobFailed`) + 1 job asynchrone optionnel `ExecuterRondeSentinelJob` **sans appelant** (⚠ dormant, cf. §7.4).

## 2. Chaîne d'exécution (✅ vérifiée)

1. **Planification** : `routes/console.php` → `Schedule::command('sentinel:controler')->everyFifteenMinutes();` (◩ sans `withoutOverlapping()`, cf. §7.2).
2. **Commande** : `app/Console/Commands/ControlerSentinel.php` — `--check=` (contrôle unique), `--strict` (code retour 1 si un contrôle est en erreur ; ⚠ option non couverte par test, §7.5).
3. **Moteur** : `SentinelService::executer(?string $code)` — chaque contrôle isolé en `try/catch` (une panne = log `sentinel.controle_echec` + listée dans `erreurs`, **jamais** transformée en incident, anti-boucle) ; constats dédupliqués par `cle_unique` (sha1 code+entite_type+entite_id) ; contrôle silencieux → auto-résolution de ses incidents `ouvert`/`acquitte` (`resolu` + `resolu_le`) ; `Cache::put('sentinel:derniere-execution')` best-effort.
4. **Persistance** : `sentinel_incidents` — `cle_unique` UNIQUE (✅ migration `2026_10_10_093042`) ; find-then-create avec rattrapage `QueryException` (course concurrente gérée) ; re-détection d'un `resolu`/`ignore` → `ouvert`, `compteur+1`, `derniere_detection_le`.
5. **Notification critique** : `alerterSiCritique()` — cooldown **en base** (`derniere_notification_le`, paramètre `sentinel.notif_cooldown_minutes`, défaut 60), notifie les super-admins actifs via `NotificationService`, fail-soft (▶ `test_critique_notifie_une_fois_cooldown_base`, `test_notification_en_panne_n_echoue_pas_la_ronde`).
6. **Cockpit** : `admin/sentinelle*` (6 routes, middleware `auth+admin+module:sentinel`, gates `sentinel.view|manage`, throttle 20/20/5 par minute) — index (filtres q/statut/gravité/catégorie/module, paginé 15, scope faculté), show, transiter, règles+bascule, exécution manuelle.

## 3. Capture `JobFailed`, déduplication, notifications, résilience (mission §4)

| Exigence | Verdict | Preuve |
|---|---|---|
| Capture `JobFailed` | ✅▶ | `ModuleServiceProvider:321` lie `JobFailed → CapterJobEchoueSentinel`, gardé par `config('modules.sentinel.active')` **dans** le listener ; `test_job_echoue_genere_incident_immediat_avec_sanitisation` vert |
| Sanitisation du diagnostic | ✅▶ | remplace chemins + `password|secret|token|cle=*` par `***`, troncature 250 ; test vert |
| Déduplication | ✅▶ | clé sha1 `nom_job|file` ; `test_job_echoue_deduplique_sur_echecs_multiples` ; idem côté ronde (`test_controle_emet_incident_deduplique`) |
| Notifications critiques 1× | ✅▶ | cooldown en base ; `test_critique_notifie_une_fois_cooldown_base` ; mesure réelle : 2 rondes consécutives, `derniere_notification_le` posée une seule fois (incident `SAUVEGARDE_FRAICHEUR`, §8.2) |
| Panne d'un service ≠ perte événement | ◩/⚠ | **persistance OK** : incidents en base, jamais en cache seul ; **mais** un contrôle en panne n'émet rien (choix anti-boucle documenté) et un worker tué sauvagement (kill) n'émet pas `JobFailed` (limite Laravel structurelle, non spécifique) — voir §7.1 |
| Panne du bus de notifications | ✅▶ | `test_notification_en_panne_n_echoue_pas_la_ronde` : ronde intacte |

## 4. Concurrence, verrous, idempotence, perte d'événements (mission §4)

- **Transitions HTTP concurrentes** : `SentinelController::transiter()` — `DB::transaction` + `lockForUpdate()->find()` + garde `peutTransiterVers()` → 422 si illégale. ▶ tests : `test_transitions_incident_verrouillees` (matrice), `test_transiter_cycle_de_vie_http`, `test_transition_illegale_refusee`, `test_rejeu_meme_transition_refuse_sans_doublon`, `test_transitions_conflictuelles_sequentielles_dernier_gagne_coherent`. Le cas **réellement parallèle** n'est pas testé en multi-processus (◩ protégé par construction : le verrou ligne sérialise, le perdant voit l'état frais).
- **Création d'incident en course** : protégée par l'UNIQUE base + rattrapage `QueryException` (✅ code, ◩ preuve de design ; test « dispatch doublon » du job d'import utilise le même rempart ailleurs).
- **Idempotence ronde** : ▶ ré-exécutions mesurées (table dev : 21 incidents constants au premier run, compteurs incrémentés, aucune ligne dupliquée).
- ⚠ **Risques résiduels** (détaillés §7.1-7.3) : compteur `+1` et mise à jour non verrouillés dans `enregistrer()` (perte d'incrément possible sous double ronde), double notification critique possible si deux rondes parallèles franchissent le cooldown ensemble, ronde planifiée sans `withoutOverlapping()`.

## 5. RBAC, isolation faculté, accès directs, masquage (mission §5)

- **Permissions** (✅ `app/Support/Permissions.php`) : `sentinel.view` = super_admin, admin_scolarite, agent_scolarite, admin_faculte, consultation ; `sentinel.manage` = super_admin, admin_scolarite. Gates auto-enregistrés depuis la matrice (`AppServiceProvider`).
- **Isolation** : policy `SentinelIncidentPolicy` (view/manage : permission + `faculteRestreinteId` cohérente avec `incident.faculte_id`, transverse = visible) + filtre de l'index (`whereNull('faculte_id') OR = faculté`). ▶ `test_detail_incident_et_idor` (403), `test_transiter_hors_perimetre_faculte_interdit` (403), `test_transiter_sans_manage_interdit`, `test_regles_bascule_manage_uniquement`, `test_executer_ronde_manuelle_throttle_manage`, `test_module_desactive_repond_404`, `test_invite_redirige`.
- **Recherche cockpit** : LIKE sur titre/code/module/diagnostic dans le périmètre filtré (✅ index).
- ⚠ **Masquage PII incohérent** : voir §6.1 (anomalie majeure).
- ✅ Bascule de règle = action globale réservée à `sentinel.manage` (rôles globaux) — cohérent.
- ✅ Module OFF → 404 sur toutes les routes (middleware `module:sentinel`), ▶ test dédié.

## 6. Règles métier avec données réalistes (mission §6) — base dev MySQL (2047 étudiants, 2099 inscriptions, 160 groupes)

Exécutés : `php artisan sentinel:controler` puis ronde mesurée via tinker (2 passages).

| Contrôle | Résultat réel |
|---|---|
| `AFFECT_SANS_GROUPE` | 1 incident agrégé « 6 étudiant(s) validé(s) sans groupe (2026-2027) », compteur ×5 aux exécutions répétées sans duplication ✅ |
| `DOSSIER_PIECES_REQUISES` | 20 incidents (borne `limit(20)`), #id sans nom ✅ PII respectée ici |
| `SAUVEGARDE_FRAICHEUR` | 1 incident critique réel (« dernière sauvegarde réussie il y a plus de 30 h ») — détection fonctionnelle d'un vrai défaut d'exploitation du poste ✅ |
| Surcapacité / niveau / horaires épreuves / INFRA | 0 incident sur le dataset (cohérent : dataset sain) ; couverts par tests métier dédiés ▶ |

### 6.1 ⚠ ANOMALIE P1 — noms d'étudiants dans les diagnostics
`AffectationSansGroupeCheck` (ligne du `pluck('nom')` + `'Exemples : '.implode(', ', …)` dans `diagnostic`) et `AffectationNiveauCheck` (sélectionne `etudiants.nom` pour l'exemple) exposent des **noms de famille** dans des incidents **transverses** (`faculte_id` null) lisibles par tout `sentinel.view` (y compris `consultation`/périmètre restreint). Preuve réelle : diagnostic constaté = « Exemples : Daoudi, Jaidi, Naciri, Raji, Wahbi. ». Incohérence avec `DossierPiecesRequisesCheck` qui applique explicitement « jamais de nom/CIN — fiche #id » (commentaire en tête de classe) et avec le test PII de convergence. **Correction attendue : remplacer les noms par `#id` (ou par l'identifiant d'inscription) — même convention que `DOSSIER_PIECES_REQUISES`.**

### 6.2 ⚠ ANOMALIE P2 — écart intitulé ↔ implémentation
- `ConflitExamensCheck` : l'en-tête annonce « chevauchements de salles sur des créneaux identiques » ; l'implémentation ne contrôle que `heure_debut >= heure_fin` (horaires incohérents). **Le conflit de salle n'est pas détecté par Sentinel** — à corriger (implémentation) ou à renommer (intitulé).
- `DossierPiecesRequisesCheck` : signale tout étudiant validé **sans aucune pièce**, sans réutiliser la matrice officielle `DocumentsRequisService` (`documents_requis_par_type`). Règle simplifiée, borne `limit(20)` non paramétrée. Ne prétendez pas « pièces obligatoires manquantes » : c'est « dossier totalement vide ». À aligner sur la matrice ou à renommer.

## 7. Cockpit, temps réel et charge (mission §7) — mesures réelles

| Mesure | Commande | Résultat |
|---|---|---|
| Ronde dev complète (11 contrôles, état pré-convergence) | `php artisan sentinel:controler` | 21 incidents, **662 ms** |
| Ronde dev mesurée (13 contrôles, état final) | tinker + `DB::enableQueryLog()` | 13 exécutés, 22 incidents touchés, **620 ms**, **159 requêtes**, 0 erreur, +1 incident réel |
| Garde-fou test (dataset minimal) | `SentinelTest::test_ronde_saine_bornee_requetes_et_duree` | ▶ < 100 requêtes, < 30 ms |

- **Quasi temps réel** : ✖ **non** — la vue `index.blade.php` n'a **aucun** auto-rafraîchissement (pas de `wire:poll`, meta refresh ou intervalle JS) ; les incidents apparaissent au prochain chargement manuel ou à la ronde planifiée (15 min) / ronde manuelle (throttle 5/min). L'affichage « dernière ronde » combine `max(derniere_detection_le)` puis le cache `sentinel:derniere-execution` (✅ cohérent).
- **Charge** : la ronde est bornée par construction (comptages et listes limitées : 5 exemples / 20 dossiers / 51 niveaux / 10 épreuves / comptages files) ; 620 ms pour 13 contrôles sur le dataset réel. Surcharge plateforme : négligeable à ce volume.

### 7.1 ⚠ P2 — pas d'exclusion mutuelle de ronde
`everyFifteenMinutes()` sans `withoutOverlapping()` ; `SentinelService::executer()` ne prend aucun verrou global. Deux rondes simultanées : création protégée (UNIQUE + rattrapage), mais `compteur+1`/`derniere_notification_le` en lecture-modification peuvent se croiser (perte d'incrément, double notification critique possible dans la même fenêtre de cooldown). Probabilité faible (ronde ≪ 15 min, ronde manuelle throttlée 5/min) mais non nulle au ralenti MySQL.

### 7.2 ⚠ P3 — job asynchrone dormant
`app/Modules/Sentinel/Jobs/ExecuterRondeSentinelJob.php` : bien formé (tries=1, timeout=120, tags) mais **aucun appelant** dans `app/`, `routes/`, `config/` (grep exhaustif). Soit le brancher (web `executer` → dispatch au lieu du sync), soit le retirer.

### 7.3 ⚠ P3 — cooldown non administrable par l'UI
`sentinel.notif_cooldown_minutes` lu avec défaut 60 via `ParametreService::get()` ; comme la clé n'est pas seedée, `set()` (`firstOrFail`) la refuse — le cooldown ne peut être ajusté sans insertion SQL manuelle.

### 7.4 ℹ Auto-résolution et statut `ignore`
Un incident `ignore` n'est **jamais** auto-résolu (le balayage ne touche que `ouvert`/`acquitte`) et se ré-ouvre si le constat réapparaît. Choix cohérent et non bloquant, à garder documenté.

### 7.5 ℹ `--strict` non testé
Option de code retour de la commande : aucune assertion (0 occurrence dans `SentinelTest`). Risque faible (logique triviale).

## 8. Tests exécutés — commandes et résultats réels (mission §8)

Environnement de test : SQLite `:memory:` (`phpunit.xml`) — aucune donnée de production touchée.

| Commande | Résultat réel |
|---|---|
| `php artisan test --compact tests/Feature/SentinelTest.php` | **28/28** (80 assertions) |
| `php artisan test --compact tests/Feature/SentinelMetierTest.php tests/Feature/SentinelConvergenceTest.php` | **10/10** (29 assertions) |
| `php artisan test --compact tests/Feature/BackupSentinelTest.php` | **7/7** |
| `php artisan test --compact tests/Feature/QualiteApiSupervisionTest.php` | **13/13** |
| `php artisan test --compact tests/Feature/Security/` | **63/63** |
| `php artisan test --compact tests/Feature/AffectationCockpitTest.php tests/Feature/AffectationMasseLotTest.php tests/Feature/AffectationMoteurTest.php tests/Feature/DataImportQueuedTest.php tests/Feature/QualiteApiSupervisionTest.php` | **62/62** |
| Régression conjointe (Sentinel×3 + EvaluationIncidentsTest + CompostageAnonymeTest + BackupSentinelTest) | **74/74** |

**Total : 257 exécutions de tests cumulées (rejeux inclus), 0 échec à l'état final dans chacun des 7 lots ci-dessus.** En distincts, le périmètre Sentinel seul = 45 tests (28 + 4 + 6 + 7).

### 8.4 Épisode transitoire documenté (honnêteté d'exécution)
Un run intermédiaire (pendant la co-livraison des contrôles Sauvegarde) a montré **2 échecs** (`test_commande_ronde_environnement_sain`, `test_ronde_complete_saine_sans_incident`) : reproduction par `tinker` prouvant que `SAUVEGARDE_FRAICHEUR` émet « Aucune sauvegarde réussie enregistrée » dans un environnement vierge — attendu, tests ensuite réalignés par la co-édition ; suite finale : **38/38 Sentinel** puis **74/74** en régression conjointe. Aucun fichier modifié par cet audit pour masquer l'épisode.

## 9. Corrections prioritaires (recommandations, aucune appliquée par cette mission)

| # | Sévérité | Correction proposée |
|---|---|---|
| C1 | **P1** | Purger la PII des diagnostics : `AffectationSansGroupeCheck` et `AffectationNiveauCheck` → afficher `#id` (convention `DOSSIER_PIECES_REQUISES`), + test de non-fuite (miroir `test_dossier_sans_piece_signale_sans_pii`). |
| C2 | **P1** | Exclusion mutuelle de ronde : `->withoutOverlapping()` sur la commande planifiée + verrou de cooldown atomique (`update … where derniere_notification_le is null or < …` ou `lockForUpdate` sur l'incident à l'alerte). |
| C3 | **P2** | Aligner `ConflitExamensCheck` : implémenter le chevauchement de salles (réutiliser le service de conflits du module Evaluation) ou renommer le contrôle. |
| C4 | **P2** | Brancher `DossierPiecesRequisesCheck` sur `DocumentsRequisService` (matrice officielle) ou renommer en « dossier sans aucune pièce » ; paramétrer la borne. |
| C5 | **P2** | Tester `INFRA_*` au niveau Sentinel (au moins `FileAttenteCheck` : jeux `jobs`/`failed_jobs` ≥ seuils) — les 2 premières délèguent à `SupervisionService` (couvertes indirectement). |
| C6 | **P3** | Brancher ou retirer `ExecuterRondeSentinelJob` ; tester `--strict`. |
| C7 | **P3** | Seeder `sentinel.notif_cooldown_minutes` (ou passer par `sauvegarde.plan.*`-like) pour rendre le cooldown administrable depuis Paramètres. |
| C8 | ℹ | Documenter le choix « `ignore` jamais auto-résolu » et évaluer un rafraîchissement cockpit (polling léger) si le quasi-temps-réel devient une exigence. |

## 10. Décision : aptitude à la préproduction

**GO CONDITIONNEL** — le module est architecturalement propre (aucun doublon d'infrastructure : sondes, notifications, paramètres et bus réutilisés), isolé (chaque contrôle en try/catch, commande qui ne meurt pas), sécurisé en accès (RBAC + scope + 404 si désactivé, cooldown en base), et prouvé par **235 tests exécutés verts** plus des **mesures réelles** sur le dataset de dev (ronde 620 ms, dédup effective, 1 détection critique réelle d'exploitation).

**Conditions avant préproduction :**
1. **C1 (PII)** — bloquant au sens RGPD/règles projet (« jamais de PII dans les logs/cockpits transverses ») ;
2. **C2 (exclusion mutuelle)** — bloquant si le worker cron partagé peut chevaucher deux rondes (sinon : risque accepté, à consigner) ;
3. **C3/C4** — à traiter ou à renommer avant de communiquer la couverture à un auditeur externe (les intitulés actuels surestiment la détection).

Les autres points (C5-C8) sont du durcissement planifiable en préproduction.

---

*Méthode : analyse exclusive du code existant, tests exécutés tels quels, mesures réelles sur la base de développement MySQL (dataset FSEGS). Aucune modification du code métier, des migrations, des routes, des configurations ou des règles métier existantes. Les seules écritures en base dev sont celles produites par le fonctionnement nominal du module audité (`sentinel_incidents`), exécuté tel quel pour mesure.*
