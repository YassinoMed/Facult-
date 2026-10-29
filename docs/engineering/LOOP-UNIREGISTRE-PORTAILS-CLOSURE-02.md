# LOOP-UNIREGISTRE-PORTAILS-CLOSURE-02

## Portails Étudiant et Enseignant — clôture des réserves tractables

- **Date** : 22 octobre 2026
- **Périmètre** : espace étudiant `/etudiant/*`, espace enseignant `/enseignant/*`, services transverses appelés par les portails.
- **Point de départ** : `LOOP-UNIREGISTRE-PORTAILS-ENTERPRISE-01`, verdict `PASS_WITH_RESERVATIONS`.
- **Mode** : chaque phase suit `AUDIT → CORRECTION → TEST → ANALYSE → RETEST → NON-RÉGRESSION → VERDICT`.
- **Règle de sûreté** : aucune suppression réelle, aucune mutation financière, aucun envoi réel, aucun déploiement, aucun commit ni push. Les audits de rétention et de rapprochement sont strictement en lecture seule.

---

## 1. Phase 0 — Baseline et périmètre

### 1.1 Audit

Le point de sortie de la boucle précédente était de **3 861 tests**, **13 échecs**, **0 erreur** (après correction des 3 erreurs de fixture initiales). Ces 13 échecs étaient hors périmètre fonctionnel portail, mais empêchaient une conclusion de non-régression globale.

La présente boucle devait :

1. lever les réserves tractables sans décision métier ;
2. implémenter les mécanismes de sûreté qui ne dépendent pas d'un fournisseur externe ;
3. conserver les décisions métier/juridiques non tranchées comme réserves explicites ;
4. produire une preuve d'exécution globale à jour.

### 1.2 Résultat

- Les 13 échecs antérieurs ont été classés et corrigés (Phase 6).
- Les fonctionnalités suivantes ont été ajoutées : réinitialisation self-service des mots de passe, politique antivirus fail-closed, audit de rétention, audit financier, widgets calendrier/annonces enseignant.
- Deux régressions de budget de requêtes introduites par les filtres restaurés ont été analysées et ramenées dans un budget documenté.

---

## 2. Phase 1 — Rétention : audit sans destruction

### 2.1 Audit

La suppression définitive d'un étudiant existe toujours dans le back-office et peut entraîner des lignes académiques, financières et documentaires. Aucune durée de conservation ni règle d'anonymisation n'est documentée. Une suppression ne peut donc pas être automatisée sans décision métier/juridique.

### 2.2 Correction

Ajout d'un audit en lecture seule :

| Fichier | Rôle |
|---|---|
| `config/retention.php` | Classes de rétention, ressources, actions proposées, durées explicitement `null`. |
| `app/Console/Commands/AuditRetentionPortails.php` | Inventaire des volumes, dépendances et échéances `retention_until`. |
| `tests/Feature/RetentionAuditTest.php` | Preuve d'absence de mutation, y compris avec une échéance dépassée. |

La commande `php artisan portails:audit-retention` :

- ne supprime, n'anonymise et n'archive rien ;
- distingue `preuve_academique`, `preuve_financiere`, `donnee_personnelle_sensible` et `document_operationnel` ;
- signale les dépendances et les échéances explicites ;
- laisse toute durée non décidée à `null` ;
- expose `--json` pour un audit externe et `--simulate` comme rappel du mode lecture seule.

### 2.3 Preuves exécutées

- `tests/Feature/RetentionAuditTest.php` : **1 test / 10 assertions — PASS**.
- Exécution réelle sur la base locale : **2 047 étudiants**, **2 085 inscriptions**, **27 documents académiques**, **0 reçu**, **0 dossier sensible**, **aucune échéance explicite dépassée** (durées laissées `null`).
- Aucune ligne n'a été supprimée ou modifiée.

### 2.4 Verdict

**PASS** pour l'audit read-only. La **décision de rétention** reste **BLOQUÉE** : suppression physique, anonymisation, archivage et durées ne sont pas tranchés. Aucune suppression réelle n'a été effectuée.

---

## 3. Phase 2 — Antivirus : politique fail-closed

### 3.1 Audit

Avant cette phase :

- `DocumentStorageService`, `DocumentService` et `RequestAttachmentService` validaient extension, MIME réel, signature et taille ;
- le contrat `AntivirusScannerInterface` était appelé après écriture ;
- `NullAntivirusScanner` était la seule implémentation liée ;
- aucun binaire ClamAV n'était installé ;
- le contexte ne portait ni disque ni catégorie ;
- une panne moteur n'avait pas de politique explicite.

### 3.2 Correction

| Fichier | Changement |
|---|---|
| `AntivirusResult` | Ajout de `erreur` pour distinguer une menace d'une indisponibilité moteur. |
| `AntivirusScannerInterface` | Contexte enrichi : `disk` et `categorie`. |
| `ClamavAntivirusScanner` | Moteur `clamdscan`/`clamscan`, timeout, codes de sortie, extraction de signature, stdin `-` par défaut, `--stream` optionnel. |
| `ModuleServiceProvider` | Binding `null` par défaut ; `clamav` uniquement si `student-dossiers.antivirus.driver=clamav`. |
| `config/student-dossiers.php` | Driver, binaire, mode, timeout et liste des catégories fail-closed. |
| `DocumentStorageService`, `DocumentService`, `RequestAttachmentService` | Passage du disque et de la catégorie ; suppression du fichier en cas de refus ; message distinct menace/indisponibilité. |
| `tests/Feature/AntivirusPolicyTest.php` | Driver null, résolution ClamAV, binaire absent, détection, extraction de signature, adaptation de commande. |

Politique appliquée :

- **toutes les catégories sont fail-closed** ;
- la commande inclut toujours le `-` de stdin (`clamdscan -` / `clamscan -`) : sans lui, `clamdscan` peut scanner le répertoire courant et répondre « sain » sans avoir analysé le fichier téléversé ;
- un binaire absent, un timeout, un code d'erreur, un fichier illisible ou une catégorie non couverte ne produit jamais `sain = true` ;
- le fichier est supprimé du stockage privé avant que l'exception ne remonte ;
- aucune catégorie fail-open n'est exposée ;
- `NullAntivirusScanner` ne peut être actif que si le moteur est explicitement désactivé.

### 3.3 Preuves exécutées

- `tests/Feature/AntivirusPolicyTest.php` : **7 tests / 15 assertions — PASS**, incluant un moteur qui dépasse le timeout.
- Tests de dépôt existants : `InscriptionDepotSecuriseTest`, `StudentDocumentSecurityTest`, `Security\FileUploadSecurityTest` — **PASS** dans le run élargi de 109 tests / 625 assertions.
- Le test d'absence de binaire vérifie que le fichier est supprimé (`Storage::allFiles` vide).

### 3.4 Limites

- `clamscan` et `clamdscan` ne sont pas installés sur la machine de validation.
- Aucun fichier réellement infecté n'a été scanné ; les tests simulent les codes de sortie et les sorties ClamAV.
- Aucun fournisseur de production n'est sélectionné, aucun secret/opération de signatures n'est validé.

### 3.5 Verdict

**PASS** pour le service et la politique testable. Le **branchement d'un moteur réel** reste **BLOQUÉ** par le choix fournisseur, l'installation et la validation opérationnelle. Le driver par défaut reste `null`.

---

## 4. Phase 3 — Réinitialisation self-service des mots de passe

### 4.1 Audit

Les portails étudiant et enseignant n'avaient pas de parcours « mot de passe oublié ». Le back-office disposait de son propre broker. Les sessions devaient être invalidées après un changement sensible.

### 4.2 Correction

| Fichier | Changement |
|---|---|
| `database/migrations/2026_10_21_200000_creer_tokens_reinitialisation_portails.php` | Table dédiée `portal_password_reset_tokens` (guard, email, hash, expiration, usage). |
| `app/Models/PortalPasswordResetToken.php` | Modèle et prédicat `estUtilisable()`. |
| `app/Services/PortalPasswordResetService.php` | Génération, stockage hashé, expiration 60 min, usage unique, limitation, envoi mail, invalidation de session. |
| `app/Http/Controllers/Auth/PortailMotDePasseController.php` | Pages et endpoints publics étudiant/enseignant. |
| `app/Mail/PortailMotDePasseMail.php` + vue mail | Lien sans donnée métier sensible. |
| `routes/web.php` | Routes publiques, throttle HTTP 5/min, guard fixé par `defaults` serveur. |
| Vues `auth/portail-mot-de-passe-*` | Formulaires accessibles, labels, aides, erreurs `role="alert"`. |
| `tests/Feature/PortailMotDePasseTest.php` | 8 scénarios de sécurité. |

Garanties :

- token aléatoire de 64 caractères, **jamais stocké en clair** (SHA-256) ;
- expiration **60 minutes**, usage unique, verrou de ligne en transaction ;
- réponse générique que le compte existe ou non ;
- limite **3 demandes / 10 minutes par e-mail** + throttle HTTP **5/min** ;
- enseignant inactif : aucun lien envoyé ;
- après succès, incrémentation de `mot_de_passe_version` : les anciennes sessions sont rejetées ;
- aucun mot de passe ni token en clair dans les logs.

### 4.3 Preuves exécutées

`tests/Feature/PortailMotDePasseTest.php` : **8 tests / 42 assertions — PASS**, couvrant :

- pages publiques accessibles ;
- e-mail inconnu : réponse générique, aucun envoi ;
- e-mail connu : envoi, token hashé, réinitialisation ;
- rejeu du token refusé ;
- token expiré refusé ;
- session ouverte invalidée ;
- enseignant : réinitialisation + version ;
- enseignant inactif : aucun lien ;
- demandes répétées limitées à 3 envois.

### 4.4 Limites

- Le canal e-mail dépend de la configuration SMTP réelle ; les tests utilisent `Mail::fake()`.
- Aucune vérification d'adresse par code séparé, aucune MFA.
- Aucun envoi réel n'a été effectué.

### 4.5 Verdict

**PASS** pour la réinitialisation self-service. La **MFA** reste **BLOQUÉE** : méthode, fournisseur, rôles obligatoires et procédure de secours ne sont pas décidés. Aucun déploiement MFA silencieux n'a été réalisé.

---

## 5. Phase 4 — Rapprochement financier en lecture seule

### 5.1 Audit

Deux périmètres financiers coexistent : la facturation historique (`Facture`/`Paiement`) et le parcours canonique (`DossierPaiement`/`RecuPaiement`). La source de vérité n'est pas tranchée. Une correction de masse serait dangereuse et non autorisée.

### 5.2 Correction

| Fichier | Rôle |
|---|---|
| `app/Modules/Finances/Services/FinancialReconciliationService.php` | Constats d'anomalies sans écriture. |
| `app/Console/Commands/AuditRapprochementFinancier.php` | Commande `finances:audit-rapprochement`, `--json`, `--strict`. |
| `tests/Feature/RapprochementFinancierTest.php` | Preuve de détection et d'absence de mutation. |

Anomalies détectées :

- reçu `VALIDE` incohérent avec `est_valide` ;
- reçu validé sans dossier validé ;
- dossier validé sans reçu validé ;
- dossier en attente sans reçu en attente ;
- reçu rattaché à une inscription différente de celle du dossier ;
- dossier ou reçu orphelin ;
- fichier réutilisé (même SHA-256) ;
- métadonnées de paiement dupliquées ;
- indicateur `inscription.paye` contradictoire avec le dossier.

Règles :

- un reçu déposé n'est **jamais** un paiement confirmé ;
- les montants sont déclaratifs/instantanés, pas une preuve comptable ;
- aucune validation, aucun paiement, aucune correction n'est écrite.

### 5.3 Preuves exécutées

- `tests/Feature/RapprochementFinancierTest.php` : **1 test / 14 assertions — PASS**.
- Exécution réelle sur la base locale : **0 dossier**, **0 reçu**, **0 anomalie**. La table est nouvelle et vide ; ce n'est pas une preuve de correction des historiques.
- Le test injecte volontairement des états contradictoires et vérifie que les compteurs et les données restent inchangés.

### 5.4 Verdict

**PASS** pour l'audit read-only. La **source de vérité financière** et une éventuelle réconciliation restent **BLOQUÉES** par décision métier. Aucune mutation financière n'a été effectuée.

---

## 6. Phase 5 — Calendrier et annonces enseignant

### 6.1 Audit

Le tableau de bord enseignant ne présentait ni prochaines échéances ni annonces. Les modules `calendar` et `announcements` existaient mais étaient désactivés par défaut. Les règles de visibilité devaient exclure brouillons, annulations, audiences étudiantes et autres facultés.

### 6.2 Correction

| Fichier | Changement |
|---|---|
| `app/Modules/Calendar/Models/EvenementCalendrier.php` | Scopes `publies()` et `visiblesPourEnseignant()`. |
| `app/Modules/Announcements/Models/Annonce.php` | Scopes `publieesActives()` et `visiblesPourEnseignant()`. |
| `app/Modules/Calendar/Services/AgendaService.php` | `agendaPourEnseignant()`. |
| `app/Http/Controllers/Enseignant/TableauDeBordController.php` | Cartes « prochaines échéances » et « annonces récentes ». |
| `app/Modules/Calendar/Routes/web.php` | Route `enseignant.calendrier.agenda`. |
| `config/modules.php` | Entrée de navigation conditionnée au module. |
| `tests/Feature/EspaceEnseignantModulesTest.php` | Visibilité, brouillons, annulations, audience, isolation faculté, sans-faculté, budget de requêtes. |

Règles :

- seuls les événements **publiés** et annonces **publiées/actives** sont visibles ;
- un enseignant sans faculté ne voit que les éléments **globaux** (`faculte_id = NULL`), jamais une autre faculté ;
- les éléments étudiant-only et les autres facultés sont exclus ;
- les modules restent désactivés par défaut ; les tests les activent explicitement.

### 6.3 Preuves exécutées

`tests/Feature/EspaceEnseignantModulesTest.php` : **20 tests / 96 assertions — PASS**.

Le budget du tableau de bord enseignant est mesuré à **22 requêtes** (limite de test : 24), avec un maximum de 3 éléments par widget.

### 6.4 Verdict

**PASS** pour le périmètre implémenté. Le **périmètre métier** du calendrier enseignant et le filtrage des événements `audience = tous` restent à confirmer ; les modules restent désactivés par défaut.

---

## 7. Phase 6 — Traitement des échecs antérieurs et régression des filtres

### 7.1 Audit

Les 13 échecs du baseline précédent ont été classés :

- tests devenus obsolètes après refactorisation de vues ou de services ;
- assertions de performance devenues trop strictes après ajout de requêtes de référentiel bornées ;
- fragilités de fixtures.

Aucune assertion de sécurité ou de comportement fonctionnel n'a été supprimée. Le seul ajustement de seuil est le budget de la file, passé de `< 25` à `<= 25` pour deux requêtes de référentiel bornées ; la constance à 40 dossiers prouve l'absence de N+1.

### 7.2 Correction

- Les 13 échecs sont passés.
- La file administrative a retrouvé ses filtres **faculté**, **programme** et **niveau**, avec un référentiel strictement scopé côté serveur.
- `FileTraitementSecuriteTest` vérifie qu'un admin facultaire ne reçoit que sa faculté et ses programmes actifs.
- Le budget de la file est passé de `< 25` à `<= 25` pour intégrer **deux requêtes de référentiels** (facultés et programmes), tout en prouvant que le nombre reste constant avec 40 dossiers : **aucune N+1**.

### 7.3 Preuves exécutées

- `tests/Feature/FileTraitementSecuriteTest.php` : **8 tests / 21 assertions — PASS**.
- `tests/Feature/InscriptionFileTraitementTest.php` + `InscriptionPerformanceFiabiliteTest.php` : **PASS** avec 25 requêtes constantes.
- Suite complète finale : **3 892 tests / 3 867 PASS / 25 skip / 18 988 assertions — 0 échec, 0 erreur**.

### 7.4 Verdict

**PASS** — la suite globale est verte, les régressions sont corrigées et les budgets sont explicités.

---

## 8. Phase 7 — Performance et accessibilité

### 8.1 Mesures exécutées

| Page / scénario | Requêtes | Budget | Résultat |
|---|---:|---:|---|
| Tableau de bord enseignant (modules actifs) | 22 | ≤ 24 | PASS |
| Tableau de bord étudiant (modules actifs) | ≤ 18 | ≤ 18 | PASS |
| File administrative, 40 dossiers | 25 | ≤ 25 | PASS, constant |
| Portail 3 pages (test existant) | < 60 | < 60 | PASS |
| Inscriptions publiques, 10 soumissions | 56 max | mesure existante | PASS |
| Tests accessibilité/performance portails | 28 tests / 160 assertions | — | PASS |

Les mesures sont issues de tests SQLite en mémoire. Elles prouvent l'absence de N+1 et un budget borné, pas une latence de production.

### 8.2 Accessibilité

- `AccessibiliteParcoursTest` passe dans la suite complète.
- `AccessibilitePortailsTest` et `PortailsPerformanceAccessibiliteTest` passent également : structure sémantique des dashboard étudiant/enseignant, pages de connexion et de réinitialisation, budget du dashboard étudiant ≤ 18 requêtes.
- Les nouvelles vues de réinitialisation utilisent des labels explicites, `autocomplete`, `aria-describedby`, `aria-invalid` et `role="alert"` pour les erreurs.
- Les tableaux de bord et filtres restaurés conservent des labels associés aux champs.

### 8.3 Limites

- Aucun audit automatisé axe-core n'a été exécuté.
- Aucun test navigateur réel de navigation clavier, de contraste ou de rendu mobile n'a été exécuté.
- Les mesures de latence sont des mesures de test, pas des mesures de production.

### 8.4 Verdict

**PASS_WITH_RESERVATIONS** — budgets et structure accessibles validés ; audit navigateur/contraste/mobile reste à planifier.

---

## 9. Phase 8 — Sécurité transverse et qualité

### 9.1 Contrôles

| Contrôle | Preuve | Résultat |
|---|---|---|
| Réinitialisation : token hashé, 60 min, usage unique | Tests 8/42 | PASS |
| Anti-énumération et limitation | Réponse générique + 3/10 min + throttle HTTP | PASS |
| Invalidation des sessions | Incrément de version + tests | PASS |
| Antivirus fail-closed | 7 tests, fichier supprimé sur panne/timeout | PASS (moteur réel non branché) |
| Rétention read-only | Test + exécution locale | PASS (décision non prise) |
| Finances read-only | Test + exécution locale | PASS (source de vérité non tranchée) |
| Isolation faculté enseignant | 20 tests, null-faculté inclus | PASS |
| Filtres file administrative scopés | 8 tests, programmes/facultés | PASS |
| Audit de routes | `php artisan audit:routes-securite` | 59 routes à revue humaine, aucune erreur bloquante nouvelle |

### 9.2 Qualité

- `vendor/bin/pint --test` : **PASS** sur les fichiers modifiés.
- `php -l` : **PASS** sur les nouveaux fichiers PHP.
- Migration additive `portal_password_reset_tokens` : **appliquée localement** (`Ran`), sans `migrate:fresh` ni `db:wipe`.
- Aucun commit, aucun push, aucun déploiement.

### 9.3 Verdict

**PASS** pour les contrôles implémentés. Les contrôles dépendant d'une décision externe restent bloqués et sont listés en §11.

---

## 10. Phase 9 — Non-régression finale

### 10.1 Suite complète

Exécution : `php -d memory_limit=2048M vendor/bin/phpunit --configuration .phpunit-full.xml --colors=never` (copie temporaire de `phpunit.xml` avec `memory_limit=2048M`, supprimée après exécution).

| Métrique | Valeur |
|---|---:|
| Tests | **3 892** |
| Passés | **3 867** |
| Échecs | **0** |
| Erreurs | **0** |
| Ignorés | **25** |
| Assertions | **18 988** |
| Durée | **294,4 s** |

À la date du run de clôture, le `phpunit.xml` du dépôt fixait `memory_limit=512M` ; le run a utilisé une configuration temporaire à `2048M`, supprimée après exécution. Depuis, `LOOP-UNIREGISTRE-PORTALS-CONTINUATION-03` a ajusté pérennement `phpunit.xml` à `memory_limit=1536M`. Ce point reste une limite d'infrastructure de test, pas un défaut produit.

Les 25 tests ignorés sont les mêmes qu'au baseline et dépendent de l'environnement (MySQL/outils externes) ; aucun skip nouveau n'a été introduit.

### 10.2 Runs ciblés finaux

| Run | Tests | Assertions | Résultat |
|---|---:|---:|---|
| Réinitialisation mot de passe | 8 | 42 | PASS |
| Antivirus | 7 | 15 | PASS |
| Rétention | 1 | 10 | PASS |
| Rapprochement financier | 1 | 14 | PASS |
| Espace enseignant modules | 20 | 96 | PASS |
| File administrative sécurité | 8 | 21 | PASS |
| Run élargi (12 fichiers, dépôts/reçus/finances/file) | 109 | 625 | PASS |
| Run ciblé 6 fichiers de clôture | 45 | 198 | PASS |

### 10.3 Commandes read-only exécutées

- `php artisan portails:audit-retention` : inventaire, aucune mutation.
- `php artisan finances:audit-rapprochement` : 0 anomalie locale, aucune mutation.
- `php artisan audit:routes-securite` : 59 routes à revue humaine, aucune erreur bloquante nouvelle.
- `php artisan migrate:status` : migration de réinitialisation appliquée.

### 10.4 Verdict

**PASS** pour la non-régression globale. Les réserves restantes sont des décisions externes, pas des régressions logicielles.

---

## 11. Décisions et blocages restants

### 11.1 Blocages de production

1. **Rétention** : choisir entre suppression physique, anonymisation et archivage ; fixer les durées et les preuves à conserver. Aucune suppression ne doit être exécutée avant cette décision.
2. **Antivirus réel** : choisir le fournisseur/moteur, installer et valider `clamdscan`/`clamscan`, définir la politique d'exploitation. Le code est fail-closed mais le driver par défaut reste `null`.
3. **MFA portail** : décider les rôles obligatoires, la méthode (TOTP/email/SMS), le fournisseur, l'enrôlement, la récupération et la révocation. Aucun déploiement silencieux.
4. **Source de vérité financière** : trancher entre facturation historique et parcours canonique, puis définir une réconciliation. Aucune correction de masse n'a été faite.

### 11.2 Décisions fonctionnelles encore ouvertes

- statut étudiant autorisé à se connecter (`en_attente`, `rejete`) ;
- périmètre du calendrier enseignant et visibilité des plannings non publiés ;
- filtrage des événements `audience = tous` par faculté ;
- périmètre des sessions d'examen (global ou programme/faculté) ;
- activation par défaut des modules en production ;
- prérequis de publication d'un planning.

### 11.3 Limites de validation

- Modules désactivés par défaut : les parcours de bout en bout ne sont pas vérifiés dans l'environnement courant ; les tests les activent explicitement.
- Aucun test navigateur réel, contraste ou mobile.
- Aucun scan de malware réel.
- Aucune validation SMTP réelle.
- Aucune réconciliation des données financières historiques.

---

## 12. Verdict global

**PASS_WITH_RESERVATIONS**

Les éléments tractables de la boucle précédente sont fermés avec des preuves :

- réinitialisation self-service sécurisée ;
- politique antivirus fail-closed et testée ;
- audit de rétention en lecture seule ;
- audit financier en lecture seule ;
- calendrier et annonces enseignant filtrés ;
- filtres de la file administrative restaurés et scopés ;
- régressions antérieures corrigées ;
- suite complète verte : **3 892 tests, 3 867 PASS, 0 échec, 0 erreur, 25 skip**.

Le verdict n'est pas `PASS` pur, car trois contrôles de production essentiels (rétention, moteur antivirus réel, MFA) et la source de vérité financière dépendent de décisions externes. Il n'est pas `FAIL` : aucun défaut critique ni régression n'a été constaté. Il n'est pas `BLOCKED` globalement : les fonctionnalités livrées sont validées et les blocages sont circonscrits à des décisions explicites, documentés ci-dessus.

Aucun commit, push, déploiement, envoi réel, paiement réel ou suppression réelle n'a été effectué.
