# SETTINGS — Contrats techniques (LOOP B, réconciliation 12/10/2026)

Statut : documentaire, non destructif. Aucune implémentation dans cette boucle.
Relation au document existant `docs/engineering/settings-loop-b-architecture.md`
(draft, 21:34) : **conservé intact** ; le présent fichier le complète et liste
les corrections en §9. En cas de contradiction, le code vérifié fait foi
(références ligne par ligne ci-dessous).

Légende preuves : `VERIFIED` (relu dans le code à la date du jour) /
`REPORTED` (affirmé par un rapport antérieur, non rejoué ici) /
`NOT_RUN` / `NOT_VERIFIED` / `HYPOTHESIS`.

---

## B1 — Réconciliation de l'existant

### 1.1 Faits constatés dans le code (VERIFIED sauf mention contraire)

| Composant | Fait vérifié | Référence exacte |
|---|---|---|
| Table `parametres` | colonnes : `id`, `cle` unique(100), `valeur` text nullable, `type`(20), `categorie`(30), `description`, `updated_by` FK users nullOnDelete, timestamps, index `categorie`. **Aucune colonne `faculte_id`** | `database/migrations/2026_09_30_000004_create_parametres_table.php:18-26` |
| Table `parametre_versions` | append-only : `valeur`, `valeur_precedente`, `acteur_id`, `motif`(500), `created_at`, index `(parametre_id,id)` ; aucun `updated_at` applicatif | `database/migrations/2026_10_12_205213_creer_versions_parametres.php:16-25` ; `app/Models/ParametreVersion.php:15` (`UPDATED_AT = null`) |
| `Parametre` | `TYPES = texte|entier|booleen|liste` ; 9 catégories déclarées ; `valeurTypee()` cast ; relations `auteur()`, `versions()` latest | `app/Models/Parametre.php:12-42` |
| `ParametreService::get()` | cache `rememberForever("parametre.{cle}")` → DB → `$defaut` ; absent = défaut sans exception | `app/Services/ParametreService.php:19-24` |
| `ParametreService::set()` | transaction + `lockForUpdate` + 1erOrFail (clé inconnue = 404) ; contrôle optimiste `$attenduLe` vs `updated_at` → `DomainException` ; no-op silencieux si valeur inchangée ; création `ParametreVersion` ; `Cache::forget` ; log notice `parametre.modifie` (acteur, clé, ancien, nouveau) | `app/Services/ParametreService.php:34-68` |
| `ParametreService::restaurer()` | `findOrFail` version filtrée par `parametre_id` (croisement inter-paramètre = 404) ; réapplique via `set()` → **nouvelle version** avec motif « Restauration de la version #{$id}. » | `app/Services/ParametreService.php:77-88` |
| `SettingDefinition` | registre code : 6 clés uniquement (`etablissement.nom/fuseau`, `academique.seuil_validation`, `assiduite.seuil_alerte`, `notifications.purge_jours`, `interface.pagination`), chacune : type, portee=`global`, defaut, regles, sensibilite, proprietaire ; `reglesPour()` retourne null hors registre | `app/Services/SettingDefinition.php:18-64` |
| Contrôleur | `index` : groupé par catégorie + historique borné (200 versions, 1 requête, groupé) ; `update` : règles registre sinon repli par `type` ; champs `attendu`+`motif` optionnels ; 422 validation, 409 concurrence ; `restaurer` POST avec 404/redirect | `app/Http/Controllers/Admin/ParametreController.php:24-93` |
| Policy | `viewAny` → `settings.view` ; `update` → `settings.manage` (même permission pour modifier et restaurer) | `app/Policies/ParametrePolicy.php:10-18` |
| Routes | 3 routes : GET `admin.parametres.index` (`can:settings.view`) ; PATCH `admin.parametres.update` (`can:settings.manage`, throttle 20/1) ; POST `admin.parametres.restaurer` (`can:settings.manage`, throttle 10/1) | `routes/web.php:920-925` |
| RBAC | `settings.view`/`settings.manage` présents dans `Permissions::TOUTES` :206-207 ; absents des listes `admin_scolarite` :283-352, `agent_scolarite` :354-393, `consultation` :394-426, `admin_faculte` :437-489 → **super_admin seul** `(:281)`. `configuration.manage` (:59) est distinct, accordé à `admin_scolarite` (:295) | `app/Support/Permissions.php` |
| Cache comportement panne | `rememberForever` avec closure → en panne cache, relecture DB (pas d'erreur). Repli sûr, non rejoué en runtime | `app/Services/ParametreService.php:21` (VERIFIED code ; runtime : NOT_RUN) |

### 1.2 Clés réellement seedées (29 clés — VERIFIED migrations)

| Famille | Clés | Migration | Consommateurs vérifiés (grep `app/`) |
|---|---|---|---|
| Institutionnelles (6) | `etablissement.nom`, `etablissement.fuseau`, `academique.seuil_validation`, `assiduite.seuil_alerte`, `notifications.purge_jours`, `interface.pagination` | `2026_09_30_000004` | `assiduite.seuil_alerte` → `PresenceController` (module Attendance) ; `notifications.purge_jours` → `app/Console/Commands/PurgerNotifications.php:23` (option CLI `--jours` prime) ; `academique.seuil_validation` → **indicative seulement**, règle effective versionnée dans `RegleEvaluation` (module Évaluation, cat. E) ; `etablissement.*`, `interface.pagination` : aucun consommateur direct trouvé dans ce grep (indicatives — voir §1.4) |
| Sauvegarde racine (3) | `sauvegarde.conserver`, `sauvegarde.alerte_age_heures`, `sauvegarde.notifier_echec` | `2026_10_07_110016` | `SauvegardesController:168/195` ; `ExecuterSauvegardeJob:79/166` ; `SauvegardeFraicheurCheck:54` |
| Sauvegarde plan (10) | `sauvegarde.plan.actif/frequence/jours/heure/retention_jours/destination/chiffrer/alerter_echec/alerter_retard/alerter_succes` | `2026_10_10_102835` (updateOrCreate, réversible par `whereIn`) | lues : `BackupSchedulerService:40-73` ; écrites (UI planification) : `SauvegardesController::mettreAJourPlanification:190-200` via `ParametreService::set` (donc auditées + versionnées) |
| Sauvegarde planifiée (10) | `sauvegarde.active/frequence/heure/jours/retention_jours/retention_max_backups/stockage_destination/alerte_succes/alerte_echec/compression` | `2026_10_10_120000` (updateOrCreate, réversible) | **AUCUN consommateur trouvé** dans `app/` via `grep "sauvegarde\."` (VERIFIED grep ; consommateur possible hors `app/` non exclu → NOT_VERIFIED) |

### 1.3 Comportements non exécutés (NOT_RUN pendant LOOP B, REPORTED par LOOP H)

- 6 suites `tests/Feature/Settings*Loop*Test.php` (B/C/D/E/F/G) existent ; le rapport LOOP H les déclare 21/21 stables ×3 avec voisins verts (REPORTED, non rejoué ici — contrainte de boucle).
- Concurrence MySQL réelle (deux éditeurs simultanés) : jamais rejouée (LOOP H §5) → verrou prouvé en lecture de code et tests unitaires seulement.
- Valeurs runtime actuelles en base : NOT_VERIFIED (aucune requête SQL exécutée).

### 1.4 Doublons potentiels documentés (sans conclusion)

| Candidat doublon | Indice | Statut |
|---|---|---|
| `sauvegarde.plan.*` (102835) vs `sauvegarde.active/frequence/heure/jours/…` (120000) | même domaine, deux schémas de nommage ; seule la famille `plan.*` a des lecteurs/écrivains trouvés | **DOUBLON SUSPECT NON PROUVÉ** — la famille 120000 peut être un schéma initial orphelin ou consommé ailleurs ; toute suppression/fusion exige une preuve d'absence de consommateur (recherche exhaustive + décision) |
| `interface.pagination` (globale) vs préférence utilisateur `lignes_par_page` | deux niveaux différents (indicateur global vs effectif individuel) | conservés distincts (tranché LOOP H §5 : indicatif) ; réévaluation seulement sur preuve d'usage |
| `academique.seuil_validation` (paramètre) vs `regles_evaluation.seuil_validation` (table métier) | le libellé `description` du paramètre dit « indicatif — les règles versionnées font foi » | pas un doublon : séparation officielle (E vs A indicatif) déjà documentée |

### 1.5 Candidates nouvelles clés (règle : aucune invention)

Aucune clé ajoutée dans cette boucle. Candidates justifiées par un besoin ET
une source de vérité existante (HYPOTHESIS à valider en LOOP C avec décision) :

- néant côté socle : les 29 clés seedées couvrent les consommateurs trouvés.
- Les clés `sauvegarde.*`/`sauvegarde.plan.*` (13) ont une UI dédiée
  (`SauvegardesController`, permission `system.backups.settings`) et sont
  absentes du registre `SettingDefinition` → repli validation par type ;
  proposition LOOP C : entrées registre OU exclusion assumée d'`admin/parametres`
  (décision, pas implémentation).

---

## B2 — Contrat de portée et d'héritage

### 2.1 Matrice des portées (A–F)

| Catégorie | Définition contractuelle | Clés actuelles | Preuve d'affectation |
|---|---|---|---|
| **A — Global uniquement** | une seule ligne par clé, `faculte_id` absent du schéma ; toute surcharge exigerait migration additive + décision | les 29 clés seedées | schéma `parametres` sans colonne faculté (`2026_09_30_000004:18-26`) ; `SettingDefinition` : `portee=global` sur ses 6 clés |
| **B — Global + surcharge faculté** | admissible UNIQUEMENT si : (1) justification métier écrite, (2) policy scopée faculté, (3) plan de tests interfacultaires (isolation A/B, IDOR), (4) résolution documentée | **aucune** | aucune clé ne satisfait les 4 conditions aujourd'hui |
| **C — Déploiement, jamais SETTINGS** | modifiables uniquement via env/ops | `MODULE_*`, connexions, throttles intégrations, timeouts, binaires OCR, `SANCTUM_*`, `SESSION_*` | `config/modules.php`, `config/*.php`, doc `settings-sources-verite.md` §2 |
| **D — Secrets interdits en base** | jamais stockés dans `parametres` ; garde par construction : audit log sans secret, docblock du service | `APP_KEY`, `*_PASSWORD`, `*_SECRET`, `SAUVEGARDE_CLE`, `INTEGRATION_WEBHOOK_SECRET` | `ParametreService.php:12-15` (docblock) ; log contexte limité (`:60-65`) |
| **E — Règle module propriétaire** | SETTINGS ne duplique pas ; lecture/délégation uniquement | `RegleEvaluation.*` (Évaluation) ; `surveillance_constraint_configs` ; `registration_periods` ; `notification_templates` ; matrice pièces | `app/Modules/Evaluation/Models/RegleEvaluation.php` (+ contrôleurs/services dédiés) |
| **F — Humain/réglementaire bloquant** | aucune valeur par défaut possible | ARB-001…009 (LMD), activation modules sensibles, hors-ligne/IA (NO-GO) | `docs/engineering/decisions-humaines.md` (DEC-01…06) conservées en suspens |

### 2.2 Résolution d'héritage (contrat cible — design, non implémenté)

Aujourd'hui (VERIFIED) : `get(cle)` = cache → DB globale → défaut code. Aucune
dimension faculté.

Contrat cible UNIQUEMENT si une clé B est admise (sinon rester A) :

```
Résoudre(clé, utilisateur, défaut):
  portée <- registre[clé].portee            # A|B connu du code, jamais du client
  si portée == A: retourner valeur globale (cache → DB → défaut)
  si portée == B:
     f <- utilisateur.faculte_id (serveur, JAMAIS paramètre requête)
     surcharge <- DB[clé, faculte=f] (lockable uniquement en écriture)
     si surcharge existe et valeur explicite (y compris false/0/"")
         -> retourner valeurTYPÉE(surcharge)
     sinon -> valeur globale (cache → DB → défaut)
```

Distinctions contractuelles obligatoires :
- **absence de surcharge** ≠ `false` : une ligne faculté absente = hériter du global ;
- **`false` explicite** = valeur (booleen cast liste fermée `0/1/vrai/faux/true/false/oui/non`, `Parametre::valeurTypee:36-41`) ;
- **`0` explicite** = valeur entière valide (jamais assimilé à « absent ») ;
- **chaîne vide** : admise seulement si le registre de la clé l'autorise (`nullable`), sinon 422 ;
- erreurs : clé hors registre en écriture = 404 (fail-closed), type invalide = 422, concurrence = 409 (`DomainException`).

---

## B3 — Contrat d'autorisation

### 3.1 État vérifié (VERIFIED)

| Rôle | Lecture (`settings.view`) | Modification (`settings.manage`) | Historique | Restauration |
|---|---|---|---|---|
| `super_admin` | ✅ (:281 TOUTES) | ✅ | ✅ (même écran `index`, historique embarqué) | ✅ (route `restaurer`, même gate `settings.manage`) |
| `admin_scolarite` | ❌ | ❌ | ❌ | ❌ |
| `agent_scolarite` | ❌ | ❌ | ❌ | ❌ |
| `consultation` | ❌ | ❌ | ❌ | ❌ |
| `admin_faculte` | ❌ | ❌ | ❌ | ❌ |

Notes : `configuration.manage` (:59) accordé à `admin_scolarite` (:295) est une
permission DISTINCTE (autres écrans de configuration), non assimilable à SETTINGS.

### 3.2 Règle cible proposée (documentaire — ne pas appliquer ici)

1. **Statu quo recommandé** : lecture/écriture/historique/restauration =
   `super_admin` exclusivement, via les deux permissions existantes.
   Justification : périmètre global (A) dont les consommateurs sont transverses
   (sauvegardes, purge, seuils indicatifs) ; le modèle actuel n'indique aucune
   demande contraire.
2. **Séparation future possible, jamais silencieuse** : si un besoin de
   délégation émerge → introduire `settings.restore` distinct de
   `settings.manage` et/ou `settings.history.view`, avec décision + matrice de
   tests ; conséquences documentées : élargissement de surface d'attaque
   (kill-switch opérationnels), audit à mettre à jour.
3. **Règle B future** : si une clé B est admise, la policy DOIT être scopée
   (`faculte_id` serveur) et les tests d'isolation interfacultaires obligatoires
   (lecture croisée = 404, écriture croisée = 403).

### 3.3 Tests d'autorisation requis (plan)

| Test | But | Statut |
|---|---|---|
| refus 403 lecture/écriture pour chaque rôle restreint | fail-closed | couvert (REPORTED : `SettingsContratLoopBTest::test_matrice_acces_lecture_ecriture`) |
| restauration réservée super_admin | élévation | couvert (REPORTED : `SettingsVersionsLoopFTest::test_restauration_reservee_super_admin`) |
| restauration version d'un AUTRE paramètre = 404 | IDOR/BOLA | couvert (REPORTED : `test_restauration_version_autre_parametre_404`) |
| lecture F croisée (si B admise) | isolation | inexistant (pas de B) |
| secrets jamais exposés dans UI/logs | D | garde construction + `test_journal_sans_secret_et_valeur_par_defaut` (REPORTED) |

---

## B4 — Contrat de versionnement et de restauration

| Propriété | Contrat (préconditions → postconditions) | Garantie réelle (code) | Statut |
|---|---|---|---|
| Identifiant version | `parametre_versions.id` incrémental ; jamais réutilisé | migration `205213:17-25` | VERIFIED code / NOT_RUN runtime intensif |
| Acteur | `acteur_id` nullable (nullOnDelete) ; authentifié requis par route | `:21` migration ; gates routes | VERIFIED |
| Portée | héritée du paramètre (globale aujourd'hui) | schéma | VERIFIED (A seul) |
| Moment | `created_at` useCurrent ; pas d'`updated_at` | `:23` ; `ParametreVersion.php:15` | VERIFIED |
| Raison | `motif` libre ≤500 ; optionnel mais recommandé | `:22` migration ; contrôleur le passe | VERIFIED |
| Avant/Après | `valeur_precedente` + `valeur` ; masquage secrets par construction (interdiction de stocker des secrets, garde docblock) | `:19-20` | VERIFIED construction / attestation log REPORTED (test) |
| Concurrence | précondition : `attendu == updated_at` ; sinon refus 409 ; postcondition : une seule version par changement effectif | `set()` lockForUpdate `:37`, contrôle `:39-41`, no-op `:46-48` | VERIFIED code ; race réelle NOT_RUN |
| Transaction | tout ou rien (paramètre + version + cache invalidé ; rollback sur exception) | `DB::transaction :36-68` ; `Cache::forget` après écriture `:58` | VERIFIED code |
| Append-only | pas d'UPDATE/DELETE applicatif sur `parametre_versions` (test verrouillé selon docblock modèle) | `ParametreVersion.php:9-12` docblock | VERIFIED absence d'API de mutation / test de verrou REPORTED |
| Restauration = nouvelle version | `restaurer()` → `set()` avec motif horodaté de la version source ; l'historique antérieur n'est jamais supprimé | `:77-88` | VERIFIED code ; test REPORTED (`test_restauration_cree_une_nouvelle_version`) |
| Répétitions/incohérences | no-op si valeur inchangée (pas de version fantôme, pas d'audit) ; relecture exigée après 409 | `:46-48`, message `DomainException` | VERIFIED code / REPORTED test |
| Journal | `parametre.modifie` notice : acteur_id, cle, ancien, nouveau — sans secret | `:60-65` | VERIFIED code / REPORTED test |

Avertissement contractuel : l'existence des méthodes ne prouve pas le
comportement sous charge/panne ; les preuves runtime restent à collecter en
LOOP C+ (suite verte rapportée, race MySQL non rejouée).

---

## B5 — Activation des modules (contrat non destructif)

- Source de vérité : `MODULE_*` en environnement → `config/modules.php` →
  `ModuleRegistry` → middleware `module:{nom}` (404) → nav/widgets filtrés.
- SETTINGS **n'autorisera pas** de bascule DB des flags (pas de clé `module.*`
  dans `parametres` ; aucune route d'activation) — conforme LOOP A
  (`settings-dependances-modules.md`) et draft LOOP B §4.
- Dépendances bloquantes documentées (ex. evaluation → referentiel) ; un
  changement partiel (ex. couper `student-dossiers`) casse des consommateurs
  transverses.
- Modules sensibles + NO-GO hors-ligne/IA : soumis à DEC-05 (conservée en
  suspens, non tranchée ici).
- `.env` dev : valeurs module lues par noms uniquement (commande grep de noms) ;
  aucune valeur secrète affichée.

---

## B6 — Livrables (index)

1. **`docs/engineering/settings-loop-b-architecture.md`** — EXISTANT (draft
   21:34), **non écrasé** ; corrections proposées au §9 ci-dessous.
2. **Matrice des portées et règles d'héritage** — §2.1 + §2.2 (ce fichier).
3. **Matrice d'accès lecture/modification/historique/restauration** — §3.1.
4. **Diagramme textuel de résolution des valeurs** — §2.2 (bloc Résoudre).
5. **Contrat de versionnement et de restauration** — §4 (tableau complet).
6. **Matrice des paramètres candidats + propriétaires** — §1.2 + §1.5
   (aucune clé inventée ; candidats justifiés = aucun ajout socle requis).
7. **Plan de tests et d'intégration classé par risque** — §8.
8. **Registre des décisions et risques ouverts** — §10.

## 8. Plan de tests et d'intégration classé par risque (cible, sans exécution)

| Risque | Test requis | Couverture actuelle |
|---|---|---|
| **P0** — perte de donnée par race condition d'écriture | race réelle MySQL deux éditeurs même clé (gaté env) | NOT_RUN (unitaire locking REPORTED) |
| **P0** — restauration destructive | test restauration = nouvelle version (jamais UPDATE) | REPORTED `SettingsVersionsLoopFTest` |
| **P1** — élévation de privilèges | matrice 5 rôles × 4 actions | REPORTED partiel (super/restr.) |
| **P1** — fuite de secret | absence secret dans UI/log | REPORTED journal ; UI NOT_VERIFIED manuellement |
| **P2** — divergence validation | registre vs repli type (clés hors registre) | REPORTED `test_cle_hors_registre_repli_par_type` |
| **P2** — purge pilotée | `PurgerNotifications` consomme bien la clé | REPORTED `SettingsIntegrationLoopETest` |
| **P3** — `attendu` optionnel | durcir : rendre obligatoire côté UI | décision ouverte (LOOP H §6) |
| **P3** — clés sauvegarde hors registre | entrées registre ou exclusion UI | décision ouverte |

## 9. Corrections proposées au draft `settings-loop-b-architecture.md` (existant, conservé)

| Ligne du draft | Dit | État vérifié aujourd'hui | Correction proposée (à appliquer lors d'une mise à jour dédiée, pas ici) |
|---|---|---|---|
| tableau tête, ligne `SettingsService` | « Manquant : `lockForUpdate` + 409 » | **présent** (`ParametreService.php:37-41`) | marquer IMPLÉMENTÉ (reste preuve race P0) |
| tableau tête, ligne `SettingsAuditService` | « Manquant : table `parametre_versions` (LOOP F) » | **présente** (migration `205213`) | marquer IMPLÉMENTÉ |
| §1 règle `notifications.purge_jours` | « integer ≥30 » | registre actuel `min:1` (`SettingDefinition.php:47`) ; commande impose `max(1,…)` | aligner le draft sur `min:1` (ou décision métier pour ≥30) |
| §1 | « Aucune clé B » | toujours exact (schéma sans `faculte_id`) | conserver + ajouter conditions d'admission (§2.1 ci-dessus) |
| §3 | versionnement « design (LOOP F) » | implémenté depuis (modèle + routes + vues) | réécrire en « implémenté, garanties §4 » |

Historique conservé : le draft reste inchangé sur disque ; toute modification
future doit créer une note de révision plutôt qu'un écrasement silencieux.

## 10. Registre des décisions et risques ouverts (reportés intacts)

| ID | Objet | Statut |
|---|---|---|
| DEC-01 | ARB-001…009 référentiel LMD | en suspens (F) |
| DEC-04 | CSP `@alpinejs/csp` | en suspens |
| DEC-05 | NO-GO hors-ligne/IA | en suspens |
| DEC-06 | mise en production (secrets/TLS/drill) | en suspens |
| DEC-NEW-B1 (proposée) | famille `sauvegarde.*` (120000) orpheline : fusion/suppression ? | à trancher avec preuve d'absence de consommateur |
| DEC-NEW-B2 (proposée) | clés `sauvegarde.*`/`plan.*` : intégrer au registre `SettingDefinition` ou exclure de l'UI générique | à trancher |
| DEC-NEW-B3 (proposée) | admettre une première clé B (surcharge faculté) — aucune candidate ne remplit les 4 conditions | NE PAS admettre sans preuve |
| C4/C5/C7/C8 | contradictions LOOP A | **conservées** (C8 : git binaire disponible localement mais historique non exploité — garder la limite) |

---

*Fin du contrat LOOP B. Cette clôture valide un contrat d'architecture ;
elle n'autorise ni migration ni modification applicative.*
