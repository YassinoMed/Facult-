# LOOP-UNIREGISTRE-INSCRIPTIONS-PAIEMENTS-01 — Dossiers de paiement annuels et reçus

> **Boucle** : Audit → Plan → Implémentation → Tests → Revue de sécurité → Validation → Rapport
> **Date** : 21/10/2026
> **Dépôt** : `inscription-etudiants`
> **Branche** : `feature/admin-modifier-etudiant`, HEAD `0d634e7`
> **État** : arbre de travail avec travaux parallèles ; le schéma et le modèle
> `DossierPaiement` sont déjà dans HEAD, le service, les contrôleurs, les vues,
> la commande et les tests de la boucle restent non suivis/modifiés.
> **Verdict** : **PASS_WITH_RESERVATIONS** — voir §8 et §9.

## 1. Synthèse

Le parcours d'inscription savait créer une inscription annuelle et, pour la
scolarité, marquer manuellement `inscriptions_academiques.paye`. Il n'existait
ni dossier de paiement par année, ni reçu rattaché à une inscription précise,
ni statut de vérification, ni historique de refus. Un dépôt de reçu pouvait
donc être confondu avec un paiement confirmé, et rien n'empêchait de réutiliser
le justificatif d'une autre année.

La boucle ajoute un **parcours de paiement additif** :

- un `DossierPaiement` par inscription annuelle (L1 → M2), avec contrainte
  `UNIQUE(inscription_academique_id)` ;
- des `RecuPaiement` versionnés, rattachés à **une seule** inscription et
  stockés dans un espace privé séparé des pièces permanentes ;
- le cycle `NON_PAYE → RECU_DEPOSE → EN_VERIFICATION → VALIDE | REFUSE`,
  avec motif de refus, nouveau dépôt possible et historique complet ;
- une file administratrice avec isolation par faculté et permission dédiée
  `finances.receipts.verify` ;
- un rattrapage idempotent `paiements:initialiser-dossiers` pour les
  inscriptions existantes.

**Règle centrale** : un dépôt n'est **jamais** un paiement. Seule une
validation explicite d'un agent autorisé écrit `paye = true`.

### Chiffres clés

| Preuve | Résultat |
|---|---|
| Suite dédiée paiements | **20 tests / 224 assertions** — PASS |
| Suite ciblée paiements + régressions + popup | **276 tests / 1 963 assertions** — PASS |
| Suite documents (non-régression) | **85 tests / 488 assertions** — PASS |
| Pint (fichiers de la boucle) | PASS |
| Build front (`npm run build`) | PASS |
| Compilation Blade (`view:cache`) | PASS |
| Suite complète | **3 843 tests : 3 802 PASS, 13 échecs, 3 erreurs, 18 703 assertions, 25 ignorés** |

La suite complète n'est **pas verte**. Les 13 échecs et 3 erreurs sont hors
périmètre de la boucle, détaillés au §7. Aucun test de paiement ni aucun test
du popup ajouté dans cette session (`LoopPopupHomeOnly04Test`,
`DossierExistant*`) n'échoue. Les 4 échecs `SaisieIdentificationStricteTest`
sont antérieurs et portent sur le format de date attendu, pas sur le contrôle
CIN/date ajouté ici.

## 2. Périmètre et décisions d'architecture

### 2.1 Ce qui existait déjà

- `InscriptionAcademique` et son booléen historique `paye` ;
- `Facture` (montant net, échéance), `Tarif` (montants officiels par année,
  programme, niveau, type de frais) ;
- `DocumentStorageService` pour le stockage privé et la validation profonde ;
- `FinancePolicy` et les permissions `finances.view`, `finances.manage`,
  `finances.confirm` ;
- le module Finances, désactivé par défaut (`MODULE_FINANCES=false`).

### 2.2 Décisions

1. **Aucune colonne existante modifiée.** Les deux tables sont nouvelles ;
   `paye` reste un indicateur externe, jamais alimenté par un dépôt.
2. **Le montant attendu n'est jamais inventé.** Il vient d'abord d'une
   `Facture` non annulée liée à l'inscription, sinon des `Tarif` actifs de
   l'année, en retenant la règle la plus spécifique par type de frais. Si rien
   n'est configuré, le montant vaut `null` et l'interface affiche
   « non configuré ».
3. **L'échéance ne vient que de `Facture.date_echeance`.** Aucune date
   d'échéance n'est codée en dur.
4. **Un seul dossier par inscription**, un seul reçu actif à la fois, un seul
   reçu validé par dossier. Les contraintes sont posées en base et vérifiées
   par verrou de ligne.
5. **Permission dédiée** `finances.receipts.verify` pour les décisions :
   valider/refuser un reçu d'inscription n'est pas la même action que
   confirmer un paiement de facture (`finances.confirm`).
6. **Stockage séparé** : racine `paiements-inscriptions/`, jamais
   `student-dossiers/`. Un reçu n'est pas une pièce permanente réutilisable.
7. **Rattrapage idempotent** : la commande ne crée que les dossiers absents,
   ne touche ni aux statuts, ni aux montants, ni aux reçus. Par défaut, elle
   ne traite que les inscriptions `en_cours` ; `--tout` est une décision
   explicite de la scolarité.

## 3. Modèle de données et règles métier

### 3.1 `dossiers_paiement`

| Colonne | Rôle |
|---|---|
| `inscription_academique_id` | **UNIQUE**, FK `inscriptions_academiques`, cascade à la suppression |
| `statut` | enum `NON_PAYE`, `RECU_DEPOSE`, `EN_VERIFICATION`, `VALIDE`, `REFUSE` |
| `pris_en_charge_par` / `pris_en_charge_le` | agent et date de prise en charge |
| `decide_par` / `decide_le` | agent et date de la décision finale |

### 3.2 `recus_paiement`

Chaque reçu est un **instantané** de la déclaration :

- `dossier_paiement_id`, `inscription_academique_id`,
  `annee_universitaire_id` : rattachement fort à l'année et à l'inscription ;
- `version` : 1, 2, 3… avec `UNIQUE(dossier_paiement_id, version)` ;
- `storage_path` : chemin privé, jamais exposé dans les vues ;
- `original_filename`, `mime_type`, `size`, `sha256` ;
- `montant_declare_centimes`, `reference_paiement`, `date_paiement` ;
- `montant_attendu_centimes`, `source_montant` : instantané des règles au
  moment du dépôt, y compris `null` si rien n'est configuré ;
- `statut` : `RECU_DEPOSE`, `EN_VERIFICATION`, `VALIDE`, `REFUSE` ;
- `motif_refus`, `commentaire` ;
- `est_valide` : `NULL` tant que le reçu n'est pas validé ; `true` après
  validation ; jamais `false` pour un refus (le refus reste `NULL`).

Contraintes :

- `UNIQUE(dossier_paiement_id, version)` : pas d'écrasement d'historique ;
- `UNIQUE(dossier_paiement_id, est_valide)` : un seul reçu validé par dossier
  (les `NULL` des reçus refusés sont distincts) ;
- `UNIQUE(sha256)` : un même fichier ne peut pas être déposé deux fois ;
- `UNIQUE(reference_paiement, date_paiement, montant_declare_centimes,
  est_valide)` : un même paiement ne peut être **validé** qu'une fois, toutes
  inscriptions confondues. Les reçus non validés restent multiples, ce qui
  permet de redéposer un document corrigé après refus avec les mêmes
  métadonnées.

### 3.3 Cycle de vie

```text
NON_PAYE
   │ dépôt étudiant (fichier + déclaration)
   ▼
RECU_DEPOSE
   │ prise en charge par un agent autorisé
   ▼
EN_VERIFICATION
   ├── validation ──────────────► VALIDE   (paye = true)
   └── refus motivé ────────────► REFUSE
                                      │ nouveau dépôt (version +1)
                                      ▼
                                  RECU_DEPOSE → …
```

- `VALIDE` est terminal : il n'existe pas d'endpoint de dévalidation.
- Un refus ne supprime ni le fichier ni la ligne : l'historique complet reste
  consultable.
- Un nouveau dépôt après refus peut conserver la référence, la date et le
  montant d'origine (document corrigé) ; le contrôle anti-doublon ne bloque
  que les **autres inscriptions**.

### 3.4 Résolution du montant attendu

Ordre appliqué par `DossierPaiementService::montantAttendu()` :

1. **Facture** liée à l'inscription, non annulée/brouillon/remboursée, la plus
   récente : `total_centimes - remise_centimes`, échéance `date_echeance`.
2. Sinon **Tarif** actifs de l'année. Pour chaque type de frais, la règle la
   plus spécifique gagne : `programme + niveau` > `programme` > `niveau` >
   règle globale. Les montants sont additionnés.
3. Sinon `centimes = null`, `source = non_configure`, aucun montant inventé.

Si le montant attendu est `null`, la validation est possible **uniquement
avec un commentaire de justification** : aucun agent ne peut valider
silencieusement un montant sans référence officielle.

## 4. Implémentation

### 4.1 Fichiers principaux

| Élément | Fichier |
|---|---|
| Migration | `database/migrations/2026_10_21_100000_creer_dossiers_paiement_et_recus.php` |
| Modèles | `app/Modules/Finances/Models/DossierPaiement.php`, `RecuPaiement.php` |
| Service métier | `app/Modules/Finances/Services/DossierPaiementService.php` |
| Contrôleur étudiant | `app/Modules/Finances/Controllers/PaiementInscriptionEtudiantController.php` |
| Contrôleur admin | `app/Modules/Finances/Controllers/PaiementInscriptionAdminController.php` |
| Policy | `app/Modules/Finances/Policies/DossierPaiementPolicy.php` |
| Request | `app/Modules/Finances/Requests/DeposerRecuPaiementRequest.php` |
| Routes | `app/Modules/Finances/Routes/web.php` |
| Vues | `resources/views/modules/finances/paiements-inscriptions/` |
| Commande de rattrapage | `app/Console/Commands/InitialiserDossiersPaiement.php` |
| Configuration | `config/inscriptions-paiements.php` |
| Tests | `tests/Feature/PaiementsInscriptionsTest.php` |

### 4.2 Routes

**Admin** (7 routes, préfixe `admin/finances/paiements-inscriptions`) :

- `GET /` : file de vérification (filtres par statut) ;
- `GET /{dossier}` : détail, reçu courant, historique, actions ;
- `POST /{dossier}/prendre` : prise en charge ;
- `POST /{dossier}/valider` : validation ;
- `POST /{dossier}/refuser` : refus motivé ;
- `GET /{dossier}/recus/{recu}/apercu` : aperçu privé inline ;
- `GET /{dossier}/recus/{recu}/telecharger` : téléchargement attaché.

**Étudiant** (5 routes, préfixe `etudiant/paiements`) :

- `GET /` : liste des inscriptions et statuts ;
- `GET /inscriptions/{inscription}` : détail du dossier annuel ;
- `POST /inscriptions/{inscription}/recus` : dépôt d'un reçu ;
- `GET /inscriptions/{inscription}/recus/{recu}/apercu` ;
- `GET /inscriptions/{inscription}/recus/{recu}/telecharger`.

Toutes les routes sont dans le groupe web (CSRF), le groupe du module Finances
(`module:finances`) et le groupe de la feature flag
`inscriptions-paiements.actif`. Les actions d'écriture sont en plus limitées
par `throttle`.

### 4.3 Création automatique et rattrapage

Un listener sur `InscriptionAcademique::created` crée le dossier si le module
et la feature flag sont actifs. Il est **fail-soft** : une erreur est
journalisée avec l'identifiant de l'inscription et ne bloque jamais la
création de l'inscription. La commande :

```bash
php artisan paiements:initialiser-dossiers --dry-run
php artisan paiements:initialiser-dossiers
php artisan paiements:initialiser-dossiers --etudiant=123 --annee=4
php artisan paiements:initialiser-dossiers --tout
```

`--tout` inclut les inscriptions historiques ; sans cette option, seules les
inscriptions `en_cours` sont traitées.

### 4.4 Permissions

- Lecture : `finances.view` (déjà existante).
- Décision : `finances.receipts.verify` (nouvelle permission dédiée).
- `finances.confirm` n'est pas élargie : elle reste réservée à la confirmation
  des paiements de factures.
- `admin_scolarite` et `admin_faculte` reçoivent la permission de décision ;
  `admin_faculte` reste limité à sa faculté par `peutAccederFaculte()`.
- Un agent sans `finances.view` reçoit 403 sur la file.

### 4.5 Feature flags

```dotenv
MODULE_FINANCES=true
INS_PAIEMENTS_ACTIF=true
# INS_PAIEMENTS_TYPES_FRAIS=inscription,reinscription
# INS_PAIEMENTS_INSTRUCTIONS=
```

`MODULE_FINANCES=false` (valeur par défaut) rend les routes indisponibles
(404) sans supprimer l'historique. Désactiver `INS_PAIEMENTS_ACTIF` masque le
parcours de la même manière.

## 5. Revue de sécurité

### 5.1 Isolation étudiant

- L'étudiant est **toujours** résolu depuis `session('etudiant_auth_id')` ;
  aucun identifiant client ne permet de choisir un autre étudiant.
- Chaque action vérifie que l'inscription appartient à l'étudiant
  (`verifierProprietaire` / contrôle dans le service) et renvoie 404 en cas
  d'incohérence.
- Un reçu est systématiquement re-résolu à partir du dossier et de
  l'inscription ; un `recu` d'un autre dossier ne peut pas être affiché via
  l'URL.

### 5.2 Isolation administrative

- `DossierPaiementPolicy` vérifie `finances.view` / `finances.receipts.verify`
  **et** le périmètre faculté de l'étudiant.
- La file applique en plus un filtre `faculte_id` pour `admin_faculte`.
- Un agent d'une autre faculté ne voit ni le dossier, ni le reçu, ni l'aperçu,
  ni le téléchargement.

### 5.3 Stockage et fichiers

- Disque `private`, racine `paiements-inscriptions/{annee}/{etudiantId}/recus-paiement/`.
- Nom interne UUID + extension ; les segments de chemin sont nettoyés
  (`preg_replace`) pour empêcher toute traversée.
- Aucune URL publique : l'aperçu et le téléchargement passent par un
  contrôleur autorisé.
- Validation : extension whitelist (PDF, JPG, PNG, WebP), MIME réel via finfo,
  signature magique (`%PDF-`, magic bytes image), taille maximale, cohérence
  extension/MIME.
- `Content-Type` limité à la whitelist, `X-Content-Type-Options: nosniff`,
  `Content-Disposition` adapté (inline pour l'aperçu, attachment pour le
  téléchargement), `Cache-Control: private, no-store`.
- **Limite d'environnement** : le binding actuel de
  `AntivirusScannerInterface` est `NullAntivirusScanner`. Les reçus ne sont
  donc **pas analysés par un antivirus** dans cette installation. Le contrat
  existe et le service supprime le fichier si un scanner répond « non sain »,
  mais aucun moteur n'est branché. C'est un risque d'infrastructure à couvrir
  avant une mise en production exposée.

### 5.4 Anti-doublon et double validation

- `sha256` unique : le même fichier ne peut pas être déposé deux fois.
- Pré-contrôle `reference + date + montant` : bloque un doublon sur une
  **autre inscription**, avec normalisation de la date en `Y-m-d` et
  `whereDate()` pour être identique sur MySQL et SQLite.
- `UNIQUE(reference_paiement, date_paiement, montant_declare_centimes,
  est_valide)` : empêche deux validations du même paiement, même sur deux
  inscriptions.
- `UNIQUE(dossier_paiement_id, est_valide)` : un seul reçu validé par dossier.
- Verrou `lockForUpdate()` sur le dossier avant décision : deux agents ne
  peuvent pas valider le même reçu en parallèle.
- La validation exige `EN_VERIFICATION` (donc une prise en charge préalable).
- Un dépôt ne modifie jamais `paye` ; seule la validation le fait.

### 5.5 Audit et données personnelles

- `AuditService::tracer()` journalise le dépôt, la prise en charge, la
  validation, le refus, la consultation et le téléchargement, avec
  l'étudiant, l'objet et l'acteur.
- Les logs techniques du listener ne contiennent que `inscription_id`.
- Aucun contenu de reçu, aucun CIN, aucune date de naissance, aucun montant
  ne sont écrits dans les logs applicatifs.
- Les motifs de refus sont visibles par l'étudiant concerné et les agents
  autorisés, pas par les autres étudiants.

### 5.6 Ce que la boucle ne fait pas

- Aucun appel à un prestataire de paiement, aucun débit, aucun remboursement.
- Aucune modification des colonnes, montants ou statuts historiques.
- Aucune réutilisation automatique d'un reçu d'une année sur l'autre.
- Aucune dévalidation après validation (voir §8).

## 6. Couverture de test

`tests/Feature/PaiementsInscriptionsTest.php` — **20 tests, 224 assertions** :

| # | Scénario | Ce qui est prouvé |
|---|---|---|
| 1 | `test_dossier_unique_par_inscription_pour_tous_les_niveaux` | L1 → M2, un dossier par inscription, contrainte UNIQUE |
| 2 | `test_inscriptions_annuelles_successives_ont_des_dossiers_distincts_et_recus_non_reutilises` | deux années = deux dossiers, reçu non réutilisé |
| 3 | `test_montant_attendu_priorise_facture_puis_tarif_specifique_sans_valeur_inventee` | facture > tarif spécifique > global > `null` |
| 4 | `test_depot_recu_prive_ne_confirme_jamais_le_paiement` | dépôt = `RECU_DEPOSE`, `paye` intact, fichier privé |
| 5 | `test_etudiant_ne_peut_pas_acceder_aux_inscriptions_ou_recus_d_un_autre` | 404 cross-étudiant |
| 6 | `test_file_admin_est_protegee_par_permission_et_faculte` | 403 sans permission, 404 hors faculté |
| 7 | `test_file_administrative_ne_fait_pas_de_requete_par_dossier` | pas de N+1 sur la file |
| 8 | `test_validation_exige_prise_en_charge_et_ne_peut_pas_etre_doublee` | prise en charge obligatoire, double validation refusée |
| 9 | `test_validation_avec_ecart_de_montant_exige_un_commentaire` | écart = justification obligatoire |
| 10 | `test_validation_sans_montant_attendu_exige_un_commentaire` | montant non configuré = justification obligatoire |
| 11 | `test_refus_conserve_motif_et_permet_un_nouveau_recu_versionne` | motif, version 2, même paiement corrigé accepté |
| 12 | `test_deux_refus_successifs_conservent_tout_l_historique` | 2 refus = 2 versions + historique complet |
| 13 | `test_un_seul_recu_en_attente_par_dossier` | pas deux reçus actifs |
| 14 | `test_fichier_identique_ou_meme_reference_date_montant_est_refuse` | sha256 + doublon inter-inscriptions |
| 15 | `test_mime_reel_et_signature_sont_verifies_meme_si_extension_trompeuse` | MIME réel + signature, pas de confiance à l'extension |
| 16 | `test_recu_est_consultable_par_ayant_droit_et_jamais_hors_perimetre` | autorisation de consultation, 404 hors périmètre |
| 17 | `test_commande_rattrapage_cree_les_dossiers_manquants_sans_toucher_aux_historiques` | backfill idempotent, historiques intacts |
| 18 | `test_module_ou_parcours_desactive_renvoie_404_et_ne_cree_aucun_dossier` | flags module/parcours |
| 19 | `test_creation_admin_avec_paye_manuel_reste_intacte_apres_depot` | `paye` historique non réécrit par un dépôt |
| 20 | `test_etudiant_voit_son_parcours_et_l_historique_sur_ses_pages` | vues étudiant, statuts, historique |

### Commandes exécutées

```bash
php artisan test --filter=PaiementsInscriptionsTest --compact
# {"tool":"phpunit","result":"passed","tests":20,"passed":20,"assertions":224}

php artisan test --filter="GedFinancesBiTest|AdminCreationEtudiantTest|AdminModificationEtudiantTest|NavigationTest|ArchiveTest|ArchiveAuthorizationTest|ArchiveIsolationFaculteTest|InscriptionEtudiantsTest|InscriptionsPhase3Test|PaiementsInscriptionsTest|DossierExistantParcoursTest|DossierExistantEmailTest|DossierExistantAutonomeTest|LoopPopupHomeOnly04Test" --compact
# {"tool":"phpunit","result":"passed","tests":276,"passed":276,"assertions":1963}

php artisan test --filter="DocumentsReutilisablesTest|StudentDocumentSecurityTest|SensitiveDocumentDownloadTest|StudentDocumentIsolationTest|DocumentsCatalogueTest|AdminModificationEtudiantTest" --compact
# {"tool":"phpunit","result":"passed","tests":85,"passed":85,"assertions":488}

vendor/bin/pint --test <fichiers de la boucle>
# {"tool":"pint","result":"passed"}

php artisan view:cache
# INFO Blade templates cached successfully.

npm run build
# vite build : OK (manifest + bundles générés)
```

### Limites de la preuve

- Les tests utilisent SQLite en mémoire (`CACHE_STORE=array`,
  `RefreshDatabase`). Le comportement MySQL des index uniques avec `NULL` et
  des colonnes `date` n'a pas été exécuté sur un MySQL réel dans cette
  session. La normalisation `date:Y-m-d` + `whereDate()` réduit l'écart, mais
  une validation sur l'environnement cible reste recommandée.
- La migration est **Pending** en développement
  (`php artisan migrate:status`) : elle n'a pas été appliquée à la base de
  développement. Elle est jouée par la suite de tests. Il faudra l'exécuter
  explicitement avant recette.
- Les tests ne couvrent pas un antivirus réel (binding `NullAntivirusScanner`).

## 7. Suite complète et échecs hors périmètre

Commande :

```bash
php artisan test
```

Résultat final :

```text
3 843 tests — 3 802 passés — 13 échecs — 3 erreurs
18 703 assertions — 25 ignorés — 225,27 s
```

### 7.1 Les 13 échecs

Ils se répartissent en trois familles, aucune ne traverse le code de la boucle :

1. **5 tests de comptage de requêtes** :
   `EnseignantInterfaceTest`, `InscriptionFileTraitementTest`,
   `InscriptionPerformanceFiabiliteTest`, `PopulationPhase2CompleteTest`,
   `Security/ArchivesFicheLoop3Test`. Les pages concernées (fiche enseignant,
   file d'inscription, dashboard, fiche d'archives admin) ne chargent aucune
   relation de la boucle (`dossierPaiement`) ; le code ajouté n'y introduit
   donc pas de requête supplémentaire. Ces comptages restent à traiter par
   leurs propriétaires, hors de cette boucle.
2. **7 tests de contenu/accessibilité** : `AccessibiliteParcoursTest` (2),
   `FaqAideScolariteTest` (1), `SaisieIdentificationStricteTest` (4). Ils
   portent sur le formulaire de nouvelle inscription, le wizard FAQ et le
   format de date du popup existant, pas sur le paiement. Le test
   `SaisieIdentificationStricteTest::test_le_formulaire_existant_embarque_le_controle_strict`
   attend un champ date `JJ/MM/AAAA` qui n'était déjà pas celui du popup avant
   cette boucle.
3. **1 test de démonstration non suivi** : `ScrutinTest` (fichier non suivi,
   hors périmètre).

### 7.2 Les 3 erreurs

`PortailEtudiantPublicationTest` (3 tests) échoue avec
`1 conflit(s) bloquent la publication`. La trace est dans
`app/Modules/Schedule/Services/ScheduleService.php`, modifié en dehors de
cette boucle. Le test échoue aussi en isolation, et aucun fichier de paiement
n'apparaît dans la trace. C'est un travail utilisateur parallèle sur le module
Schedule, pas une régression de la boucle.

### 7.3 Conclusion sur la non-régression

- Aucun test de `PaiementsInscriptionsTest` n'échoue.
- Aucun test de `DossierExistantParcoursTest`, `DossierExistantEmailTest`,
  `DossierExistantAutonomeTest` ou `LoopPopupHomeOnly04Test` n'échoue.
- Les 276 tests ciblés (paiements + régressions + popup) passent.
- Les échecs restants sont documentés et attribués à des travaux hors boucle ;
  ils ne sont pas corrigés ici pour ne pas modifier du travail utilisateur.

## 8. Risques résiduels et limites

1. **Pas de dévalidation.** Une fois `VALIDE`, le dossier et `paye = true` ne
   peuvent pas être annulés par l'interface. Une validation erronée exige une
   correction manuelle autorisée en base. Un futur workflow de correction
   audité (motif, acteur, historique) est nécessaire.
2. **Cohabitation avec le booléen historique `paye`.** La boucle ne réécrit
   jamais les valeurs historiques. Si un administrateur a marqué `paye = true`
   sans reçu validé, l'interface le signale, mais la base conserve la valeur.
   La source de vérité « reçu validé » n'est pas encore unique.
3. **Montant non configuré.** Si aucune facture ni aucun tarif n'existe, le
   montant attendu reste `null`. La validation exige désormais un commentaire,
   mais elle reste un acte humain : aucune somme officielle ne peut être
   calculée. C'est conforme à la règle « aucun montant inventé », mais cela
   laisse une décision manuelle à la scolarité.
4. **Doublons en attente.** La contrainte de base ne couvre que les paiements
   **validés** (`est_valide = true`). Deux dépôts non validés concurrents sur
   deux inscriptions distinctes avec la même référence/date/montant pourraient
   cohabiter si le pré-contrôle est contourné par une course. Le pré-contrôle
   les bloque en pratique et la validation de la seconde échoue, donc il n'y a
   jamais double validation ; le risque résiduel est un doublon de
   **déclaration en attente**, visible par l'admin.
5. **Antivirus non branché.** Voir §5.3 : `NullAntivirusScanner` est le
   binding actuel. Les contrôles d'extension, MIME, signature et taille sont
   actifs, mais pas l'analyse antivirus.
6. **Rétention des fichiers refusés.** Les fichiers refusés sont conservés
   pour l'historique. Aucune politique de purge/rétention n'est définie ; le
   stockage peut croître. C'est volontaire (traçabilité), mais à cadrer
   administrativement.
7. **Pas de test MySQL réel.** Voir §6, limites de la preuve.

## 9. Verdict

**PASS_WITH_RESERVATIONS.**

La boucle répond aux exigences fonctionnelles et de sécurité du périmètre :
un dossier par inscription annuelle, des reçus privés et versionnés, un dépôt
qui ne vaut jamais paiement, des montants et échéances issus uniquement des
règles existantes, un refus motivé avec nouveau dépôt et historique, une file
admin isolée par permission et faculté, et une couverture de tests dédiée
(20 tests / 224 assertions) sans régression sur les 276 tests ciblés.

Les réserves sont explicites : absence de dévalidation, coexistence avec le
booléen `paye` historique, montant non configuré laissé à la décision humaine,
doublons en attente en cas de course, antivirus non branché, rétention non
cadrée, et validation MySQL réelle non exécutée. La suite complète reste
rouge (13 échecs + 3 erreurs) pour des raisons hors boucle, documentées au §7.

### Prochaine étape recommandée

1. Appliquer la migration sur l'environnement cible et rejouer la suite
   `PaiementsInscriptionsTest` sur MySQL.
2. Brancher un vrai scanner antivirus derrière `AntivirusScannerInterface`.
3. Décider du workflow de dévalidation/correction et de la politique de
   rétention des reçus refusés.
4. Traiter séparément les 13 échecs et 3 erreurs hors boucle (Schedule,
   accessibilité, comptages de requêtes) avec leurs propriétaires actuels.

## 10. Travaux connexes de la même session (hors boucle paiements)

La même session a ajouté un **contrôle bloquant dans le popup « Retrouver
votre dossier »** : le popup ne passe à l'étape « code » que si le CIN **et**
la date de naissance correspondent au même étudiant en base. Le contrôle
serveur existe déjà dans `DossierExistantService` ; le contrôleur JSON renvoie
désormais `422` avec `identite_correspond: false` et une erreur générique
(impossible de distinguer un CIN inconnu d'une date erronée), `429` en cas de
limite, et `200` avec `identite_correspond: true` + `email_masque` en cas de
succès. Le front (`resources/js/app.js`) n'avance que si
`identite_correspond === true`, et la vue affiche l'erreur.

Preuves ciblées : `DossierExistantParcoursTest` 38 tests / 270 assertions,
`DossierExistantEmailTest` 39 / 528, `DossierExistantAutonomeTest` 25 / 172,
`LoopPopupHomeOnly04Test` 17 / 148 — toutes PASS. Le parcours canonique
(non-JSON) conserve sa réponse neutre d'anti-énumération.

Ce contrôle n'est pas couvert par le verdict paiements ci-dessus ; il est
mentionné pour la traçabilité de la session.
