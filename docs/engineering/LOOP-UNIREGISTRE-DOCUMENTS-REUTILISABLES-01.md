# LOOP-UNIREGISTRE-DOCUMENTS-REUTILISABLES-01 — Réutilisation annuelle des documents

> **Boucle** : Audit → Plan → Implémentation → Tests → Revue de sécurité → Validation → Rapport
> **Date** : 20/10/2026
> **Dépôt** : `inscription-etudiants` — branche `main`, HEAD `907bf0c`
> **État** : changements non commités ; travaux parallèles de l'arbre conservés
> **Verdict** : **PASS_WITH_RESERVATIONS** — voir §8 et §9

## 1. Synthèse

Le dossier documentaire permanent existait déjà (`student_dossiers`,
`student_documents`, `student_document_versions`) mais aucune inscription
annuelle ne savait quelles pièces avaient été utilisées, validées ou
refusées pour une année donnée. À chaque réinscription, la scolarité devait
donc redemander des pièces déjà présentes, ou vérifier manuellement leur
validité.

La boucle ajoute une **association additive** entre une inscription
académique et les pièces permanentes :

- une ligne `inscription_documents` par couple
  `(inscription_academique_id, document_type)` ;
- aucune copie du fichier physique : l'association pointe vers
  `student_documents` et `student_document_versions` ;
- une décision annuelle explicite (`reutilise`, `associe`, `en_attente`,
  `rejete`, `expire`, `non_conforme`, `manquant`) et un instantané non
  personnel de la version retenue ;
- aucune année clôturée n'est réécrite ;
- un simple téléversement `pending` n'est jamais considéré comme validé.

### 1.1 Chiffres clés

| Contrôle | Résultat |
|---|---|
| Tests dédiés à la boucle | **14 / 14 réussis — 100 assertions** |
| Suites ciblées documents / inscriptions / dossiers | **121 / 121 réussis — 665 assertions** |
| Suite complète (mémoire 1 G) | **3 763 tests · 3 723 réussis · 12 échecs · 3 erreurs · 25 ignorés · 18 111 assertions · 238,5 s** |
| Migration additive en dev | **`Ran`** — table `inscription_documents`, 13 colonnes, InnoDB |
| Dry-run de rattrapage en dev | **20 inscriptions ouvertes · 0 pièce réutilisée · 0 erreur** |
| Pint ciblé sur les fichiers de la boucle | **OK** |
| `php -l` sur le service après le dernier durcissement | **OK** |

Les 12 échecs et 3 erreurs de la suite complète sont **antérieurs ou
extérieurs** à la fonctionnalité (détail et attribution en §5.3). Aucun test
de la boucle n'échoue. Les réserves fonctionnelles et de preuve sont en §8.

### 1.2 Couverture des exigences

| Exigence | Mise en œuvre | Preuve principale |
|---|---|---|
| Première inscription : demander les pièces obligatoires manquantes | Checklist `checklistPourType()` ; lignes `manquant` ; aucune validation implicite | `test_premiere_inscription...` |
| Réinscription : réutiliser sans nouveau téléversement | Événement `InscriptionAcademiqueCreee` → `ReutilisationDocumentsService` | `test_reinscription...` |
| Ne réutiliser que des pièces valides, lisibles, approuvées, conformes | Statut `validated`, `validated_at`, expiration, fichier, taille, SHA-256, format, MIME | `test_refuse_expire...`, `test_format_non_conforme...`, `test_fichier_altere...` |
| Demander uniquement les pièces manquantes, refusées, expirées ou non conformes | Décisions `manquant` / `rejete` / `expire` / `non_conforme` ; vues « à fournir » | `test_reinscription...`, `test_espace_etudiant...` |
| Ne jamais considérer « téléversé » comme validé | `pending` → `en_attente` ; test dédié | `test_premiere_inscription...`, `test_televersement_posterieur...` |
| Ne jamais supprimer un document historique remplacé | Remplacement = nouvelle version ; ancienne version et fichier conservés ; année clôturée figée | `test_remplacement...` |
| Référentiel central lié à l'identité permanente | `student_dossiers` / `student_documents` existants ; association annuelle seulement | Modèle + migration |
| Séparer fichier, métadonnées/historique et associations annuelles | Fichier privé + `student_document_versions` + `inscription_documents` | Migration + service |
| Migrations non destructives et contraintes d'intégrité | `Schema::create` uniquement ; unique (`inscription`, `type`) ; FK | Migration + `db:table` |
| Policies et isolation | `InscriptionDocumentPolicy` ; périmètre faculté ; téléchargement existant | `test_changement_didentite...` |
| Couvrir remplacement, changement d'identité, accès inter-étudiants | Tests dédiés | `test_remplacement...`, `test_changement_didentite...` |
| Transactions et idempotence | `DB::transaction`, `firstOrNew`, unique + rattrapage de course | `test_synchronisation_idempotente`, `test_synchronisation_est_transactionnelle...` |
| Commande de rattrapage sûre | `documents:associer-inscriptions` avec `--dry-run`, `--tout`, garde module | `test_commande...`, `test_commande_necrit_rien...` |

## 2. Audit initial et décision d'architecture

### 2.1 Constat

Le module `StudentDossiers` portait déjà :

- `student_dossiers` : le dossier permanent d'un étudiant ;
- `student_documents` : une pièce par type, avec statut, dates et fichier
  privé ;
- `student_document_versions` : l'historique des versions et les métadonnées
  de validation.

En revanche, `inscriptions_academiques` ne portait **aucun état
documentaire annuel**. Les conséquences étaient :

- redemande de pièces déjà validées à chaque réinscription ;
- impossibilité de savoir quelle version avait servi pour une année ;
- risque de confondre un téléversement récent avec une validation ;
- perte de traçabilité en cas de remplacement ;
- risque d'accès inter-étudiants si une simple relation document → inscription
  était exposée sans périmètre.

### 2.2 Décision

Étendre le référentiel existant plutôt que créer un second système :

1. **Migration strictement additive** : nouvelle table
   `inscription_documents`, aucune colonne existante modifiée, aucun
   document supprimé.
2. **Une ligne par inscription et par type de pièce** : la contrainte unique
   `uniq_inscription_document_type` rend la synchronisation idempotente.
3. **Aucune copie de fichier** : `student_document_id` et
   `student_document_version_id` référencent les lignes existantes ;
   `student_documents` reste l'unique propriétaire du fichier physique.
4. **Décision annuelle + instantané** : la ligne annuelle conserve la
   décision, le motif contrôlé et un snapshot non personnel (version, dates,
   MIME, extension, taille, SHA-256). Aucun nom de fichier ni donnée
   d'identité dans le snapshot.
5. **Historique figé** : les années clôturées ne sont jamais réécrites ; le
   service refuse même un appel direct en écriture sur une inscription dont
   le statut n'est pas `en_cours`.

Alternatives écartées :

- **copier le fichier dans un dossier annuel** : duplication, coût de
  stockage, risque de divergence et de suppression ;
- **stockage JSON sur `inscriptions_academiques`** : pas de contrainte
  d'unicité, pas d'index, pas de policy par ligne ;
- **nouveau référentiel documentaire** : doublon fonctionnel du module
  `StudentDossiers` et migration lourde sans bénéfice.

## 3. Modèle de données et règles métier

### 3.1 Schéma `inscription_documents`

| Colonne | Type | Rôle |
|---|---|---|
| `id` | bigint unsigned, PK | identifiant |
| `inscription_academique_id` | FK `inscriptions_academiques`, cascade | année concernée |
| `student_document_id` | FK `student_documents`, nullable, `nullOnDelete` | pièce permanente |
| `student_document_version_id` | FK `student_document_versions`, nullable, `nullOnDelete` | version retenue |
| `document_type` | varchar(60) | code de la pièce (`cin`, `releve_notes`, etc.) |
| `obligatoire` | boolean, défaut `true` | exigence de l'année |
| `decision` | enum 7 valeurs, défaut `manquant` | décision annuelle |
| `motif` | varchar(500), nullable | motif contrôlé / raison de refus |
| `snapshot` | JSON, nullable | version, dates, MIME, extension, taille, SHA-256 |
| `decide_par` | FK `users`, nullable, `nullOnDelete` | auteur de la décision |
| `decide_le` | timestamp, nullable | date de décision |
| `created_at` / `updated_at` | timestamps | audit technique |

Contraintes et index observés en dev :

- `uniq_inscription_document_type` sur
  `(inscription_academique_id, document_type)` ;
- index sur `student_document_id` et `decision` ;
- clés étrangères `inscription_academique_id` (cascade),
  `student_document_id` / `student_document_version_id` / `decide_par`
  (`nullOnDelete`).

Le `nullOnDelete` est volontaire : la suppression contrôlée d'un document ne
doit pas casser la ligne annuelle ; le snapshot conserve la décision et les
métadonnées. La ligne annuelle est supprimée en cascade avec l'inscription
(voir réserve R6).

### 3.2 Décisions

| Décision | Signification | Action étudiant |
|---|---|---|
| `reutilise` | pièce valide lors de la première synchronisation de l'année (aucune association antérieure), réutilisée automatiquement | aucune |
| `associe` | pièce valide rattachée à l'année alors qu'une association existait déjà (téléversement/validation postérieur, nouvelle version) | aucune |
| `en_attente` | téléversée mais **pas encore validée** | attendre le contrôle |
| `rejete` | refusée par la scolarité | remplacer |
| `expire` | date de validité dépassée | renouveler |
| `non_conforme` | format, MIME, fichier, dossier ou statut non conforme | remplacer |
| `manquant` | aucune pièce pour ce type | fournir |

`reutilise` et `associe` sont les seules décisions qui satisfont une pièce
obligatoire (`DECISIONS_VALIDES`). `manquant`, `rejete`, `expire` et
`non_conforme` imposent une action (`DECISIONS_A_FOURNIR`). `en_attente`
n'est jamais comptée comme validée.

### 3.3 Règles d'éligibilité

Une pièce n'est réutilisée que si **toutes** les conditions suivantes sont
remplies :

1. elle appartient au dossier du même étudiant
   (`student_dossier_id` identique) ;
2. son statut est `validated` ;
3. `validated_at` est renseigné (une validation non tracée n'est pas
   acceptée) ;
4. `expires_at` est nul ou futur ;
5. le fichier privé existe et est lisible ;
6. le fichier n'est pas vide et sa taille réelle correspond à `size` ;
7. si `sha256` est renseigné, l'empreinte réelle correspond exactement
   (`hash_equals`) — une taille identique ne suffit pas ;
8. l'extension fait partie des formats exigés par la pièce ;
9. le MIME est dans la liste blanche configurée pour l'extension.

Sinon, la décision est explicite : `en_attente` pour `pending`, `rejete` pour
`rejected`, `expire` pour `expired` ou date dépassée, `non_conforme` pour
`archived`, statut inconnu, dossier étranger, fichier absent/vide/incohérent,
empreinte différente, format ou MIME invalide.

### 3.4 Cycle de vie

- **Première inscription** : le dossier permanent peut être vide ; la
  checklist crée les lignes `manquant`. Un téléversement crée une ligne
  `en_attente` ; il ne vaut jamais validation. Après validation, la pièce est
  `associe` à l'année ouverte.
- **Réinscription** : l'événement de création de la nouvelle inscription
  synchronise la checklist ; les pièces permanentes déjà validées deviennent
  `reutilise`, sans nouveau téléversement. Seules les pièces non valides sont
  demandées.
- **Remplacement** : une nouvelle version est créée ; l'année ouverte passe à
  `associe` (ou `en_attente` si la nouvelle version n'est pas encore validée).
  L'ancienne version et le fichier restent en base. Une année clôturée garde
  sa version et sa décision d'origine.
- **Année clôturée** : jamais réécrite. Le service refuse l'écriture ; la
  commande ne cible que `statut = en_cours` ; les listeners ne ciblent que la
  dernière inscription ouverte.
- **Changement d'identité** : la correction du nom/CIN de l'étudiant ne casse
  pas le lien au dossier permanent (l'identité technique est l'ID étudiant) ;
  les documents d'un autre étudiant restent isolés.

## 4. Implémentation

### 4.1 Service `ReutilisationDocumentsService`

| Méthode | Écriture | Rôle |
|---|---|---|
| `synchroniser(InscriptionAcademique, ?int)` | oui | évalue la checklist, persiste une décision par pièce dans une transaction, retourne le bilan ; refuse l'écriture si l'année n'est pas `en_cours` |
| `previsualiser(Etudiant, ?string)` | non | contexte avant création d'inscription (écran de réinscription) |
| `etatInscription(InscriptionAcademique)` | non | état live pour l'affichage ; aucun effet de bord sur un GET |
| `derniereInscriptionActive(Etudiant)` | non | dernière inscription ouverte de l'étudiant |
| `synchroniserDerniereInscriptionActive(Etudiant\|int)` | oui | resynchronisation après un événement documentaire |
| `evaluerDocument(...)` | non | applique les règles §3.3 |
| `persisterDecision(...)` | oui | `firstOrNew` + unique + reprise de course |
| `construireBilan(...)` | non | listes `reutilises`, `associes`, `a_fournir`, `en_attente`, compteurs |
| `tracer(...)` | oui | audit sans PII |

Points de robustesse :

- le statut d'une inscription juste créée peut être absent de l'instance
  Eloquent (valeur par défaut `en_cours` en base) : le service fait une lecture
  ciblée uniquement dans ce cas, puis applique la garde « année clôturée » ;
- la décision `reutilise` est prise à la première synchronisation, quand
  aucune association n'existe encore pour la pièce ; si une association
  existe déjà avec la même version et une décision valide, cette décision est
  conservée ; un nouveau document ou une nouvelle version devient `associe` ;
- la transaction englobe toutes les pièces ; une erreur annule l'ensemble ;
- la contrainte unique arbitre une course entre deux synchronisations ; le
  `catch` reprend la ligne gagnante.

### 4.2 Événements et listeners

- `InscriptionAcademiqueCreee` est dispatché par
  `InscriptionService::creerInscriptionAnnuelle()`, dans la transaction de
  l'appelant.
- `AssocierDocumentsInscription` écoute cet événement et appelle
  `synchroniser()`.
- `SynchroniserDocumentsAnnuels` écoute :
  `DocumentUploaded`, `DocumentValidated`, `DocumentRejected`,
  `DocumentReplaced`, `DocumentDeleted`, `DocumentArchived`,
  `DocumentExpired`.
- Les listeners sont **fail-soft** : `report()` + `Log::warning` avec
  identifiants ou classe d'événement seulement, jamais de PII ; une anomalie
  documentaire ne bloque pas la création de l'inscription ni le parcours.
- Les listeners et la commande vérifient
  `config('modules.student-dossiers.active')` et ne font rien si le module est
  désactivé.
- Les listeners documentaires ne ciblent que la **dernière inscription
  ouverte** de l'étudiant.

### 4.3 Intégration

- `InscriptionService` : dispatch de l'événement de création.
- `ReinscriptionService` : contexte de prévisualisation branché sur
  `previsualiser()` ; aucune écriture avant la création effective.
- `MonDossierController` / `mon-dossier.blade.php` : pièces réutilisées, en
  attente de contrôle, à fournir ou à renouveler.
- `StudentDossierController` / `show.blade.php` : pièces à contrôler côté
  scolarité.
- `reinscrire.blade.php` : contexte de réutilisation avant validation.
- `DocumentsRequisService::piecesDossier()` : nouveau paramètre
  `bool $avecVersions = false` ; seuls les chemins de réutilisation chargent
  les versions. Les appelants existants restent inchangés.
- `DocumentStorageService` : ajout de `empreinte()` (SHA-256) à côté de
  `taille()` / présence.
- `config/student-dossiers.php` : listes blanches MIME par extension.
- `ModuleServiceProvider` : policy + écouteurs de la boucle.

### 4.4 Policy

`InscriptionDocumentPolicy` :

- `viewAny` : `student_dossiers.view` ;
- `view` : `student_dossiers.view` + périmètre faculté
  (`peutAccederFaculte`) ;
- `synchroniser` : `student_dossiers.update` + périmètre faculté ;
- `download` : document non nul + `student_documents.download` + périmètre
  faculté.

Aucune permission n'est inventée. La policy est enregistrée dans
`ModuleServiceProvider`. Cette boucle n'ajoute **aucune route HTTP** : les
écritures sont internes (événements/commande) ; les téléchargements passent
par les routes et policies existantes. L'ability `synchroniser` est prête pour
une future action de scolarité (réserve R7).

### 4.5 Commande de rattrapage

`php artisan documents:associer-inscriptions` :

- cible uniquement les inscriptions `statut = en_cours` ;
- par défaut, ignore les inscriptions déjà associées
  (`whereDoesntHave('documentsInscription')`) ;
- `--tout` resynchronise aussi les inscriptions ouvertes déjà associées ;
- `--etudiant=` et `--annee=` filtrent ;
- `--dry-run` utilise `etatInscription()` (aucune écriture) ;
- ne fait rien si le module est désactivé ;
- compte les erreurs, `report($e)` et un avertissement avec l'ID seulement,
  retourne `FAILURE` si au moins une erreur ;
- ne cible jamais une année clôturée.

### 4.6 Fichiers de la boucle

**Créés (9 fichiers de code/config/vue/test + ce rapport)**

| Fichier | Rôle |
|---|---|
| `database/migrations/2026_10_20_090000_creer_table_inscription_documents.php` | schéma additif, unique, FK, index |
| `app/Modules/StudentDossiers/Models/InscriptionDocument.php` | modèle de l'association annuelle |
| `app/Modules/StudentDossiers/Services/ReutilisationDocumentsService.php` | cœur métier |
| `app/Events/InscriptionAcademiqueCreee.php` | événement de création d'inscription |
| `app/Modules/StudentDossiers/Listeners/AssocierDocumentsInscription.php` | association initiale |
| `app/Modules/StudentDossiers/Listeners/SynchroniserDocumentsAnnuels.php` | resynchronisation documentaire |
| `app/Modules/StudentDossiers/Policies/InscriptionDocumentPolicy.php` | isolation faculté / permissions |
| `app/Console/Commands/AssocierDocumentsInscriptions.php` | rattrapage idempotent |
| `tests/Feature/DocumentsReutilisablesTest.php` | 14 tests de la fonctionnalité |

**Modifiés (13 fichiers de code/config/vue + 1 doc de comportement)**

| Fichier | Modification |
|---|---|
| `app/Models/InscriptionAcademique.php` | relation `documentsInscription` |
| `app/Modules/StudentDossiers/Models/StudentDocument.php` | relation `inscriptionDocuments` |
| `app/Modules/StudentDossiers/Services/DocumentStorageService.php` | présence, taille, empreinte SHA-256 |
| `app/Services/DocumentsRequisService.php` | `piecesDossier(..., bool $avecVersions)` |
| `app/Services/InscriptionService.php` | dispatch `InscriptionAcademiqueCreee` |
| `app/Services/ReinscriptionService.php` | contexte de prévisualisation |
| `app/Modules/StudentDossiers/Controllers/MonDossierController.php` | données de réutilisation étudiant |
| `app/Modules/StudentDossiers/Controllers/StudentDossierController.php` | données de contrôle admin |
| `app/Providers/ModuleServiceProvider.php` | policy + 8 écouteurs |
| `config/student-dossiers.php` | listes blanches MIME |
| `resources/views/modules/student-dossiers/mon-dossier.blade.php` | pièces réutilisées / en attente / à fournir |
| `resources/views/modules/student-dossiers/show.blade.php` | pièces à contrôler côté scolarité |
| `resources/views/admin/inscriptions/reinscrire.blade.php` | contexte de réinscription |
| `docs/inscriptions/re-enrollment.md` | documentation canonique de la réutilisation |

Aucun fichier utilisateur parallèle (DataMesh, Scrutins, SMTP, vues,
dossier existant) n'a été supprimé ou écrasé volontairement. Les fichiers
communs ont été modifiés de façon additive.

## 5. Tests et preuves

### 5.1 Tests dédiés — 14 / 14, 100 assertions

```bash
php artisan test --filter=DocumentsReutilisablesTest --compact
```

| Test | Ce qui est prouvé |
|---|---|
| `test_premiere_inscription_reutilise_les_pieces_validees_et_ne_valide_jamais_un_televersement` | première inscription, pièces validées réutilisées, `pending` non validé |
| `test_reinscription_reutilise_sans_duplication_et_ne_demande_que_la_piece_manquante` | réinscription, aucune duplication, seule la pièce manquante demandée |
| `test_refuse_expire_fichier_absent_et_format_invalide_ne_sont_jamais_reutilises` | refus, expiration, fichier absent, format invalide ; motif PII conservé mais absent de l'audit |
| `test_format_non_conforme_est_demande_pour_remplacement` | format non conforme → `non_conforme` / remplacement |
| `test_fichier_altere_de_meme_taille_nest_jamais_reutilise` | altération de même taille détectée par SHA-256 |
| `test_remplacement_conserve_lhistorique_et_ne_reecrit_pas_une_annee_cloturee` | nouvelle version, ancienne conservée, année clôturée figée, appel direct au service sans réécriture |
| `test_televersement_posterieur_alimente_lannee_sans_validation_automatique` | téléversement après inscription → `en_attente`, pas de validation implicite |
| `test_changement_didentite_conserve_la_reutilisation_et_isole_les_etudiants` | correction d'identité, réutilisation conservée, `Gate::view` refusé hors faculté, téléchargement inter-étudiant 404 |
| `test_synchronisation_idempotente` | deux synchronisations = mêmes lignes / décisions ; aucun doublon |
| `test_commande_de_rattrapage_dry_run_backfill_et_tout_sans_reecrire_une_annee_cloturee` | `--dry-run`, backfill, `--tout`, année clôturée intacte |
| `test_commande_necrit_rien_si_le_module_est_desactive` | garde `modules.student-dossiers.active` |
| `test_inscription_creee_sans_statut_explicite_utilise_le_defaut_en_base` | statut `en_cours` par défaut en base, association quand même créée |
| `test_espace_etudiant_et_dossier_admin_affichent_reutilisation_et_pieces_a_fournir` | vues étudiant et admin : réutilisées, en attente, à fournir |
| `test_synchronisation_est_transactionnelle_en_cas_dechec` | rollback complet si une persistance échoue |

Assertions clés :

- le motif libre de refus
  `« CIN 98765432 illisible — dossier de Sara Benali »` reste dans
  `inscription_documents.motif` mais **n'apparaît pas** dans
  `historique_academique` (absence de PII dans l'audit) ;
- l'ancienne version et l'ancien fichier restent après remplacement ;
- une année clôturée garde sa version et sa décision, même après un appel
  direct à `synchroniser()` ;
- le compteur de documents/versions ne double pas après réinscription.

### 5.2 Suites ciblées — 121 / 121, 665 assertions

```bash
php artisan test --filter="DocumentsReutilisablesTest|DocumentsRequisTest|ReinscriptionTest|StudentDocumentTest|StudentDocumentSecurityTest|StudentDocumentIsolationTest|InscriptionChecklistDocumentaireTest|ExperienceInscriptionLoopTest|InscriptionDepotSecuriseTest|StudentDossierTest|StudentDossierPhase8Test|StudentDossierPdfTest|AffectationApresDossierCompletTest|WorkflowDossierPlanningTest" --compact
```

Résultat : **121 tests, 121 réussis, 665 assertions**, 6,1 s. Cette suite
couvre la boucle documentaire, les documents permanents, la réinscription,
la checklist, le dépôt sécurisé et les dossiers.

### 5.3 Suite complète — résultat final

```bash
php -d memory_limit=1G artisan test --compact
```

Résultat final :

- **3 763 tests**
- **3 723 réussis**
- **12 échecs**
- **3 erreurs**
- **25 ignorés**
- **18 111 assertions**
- **238,5 s**

Le run final a été exécuté avec **1 G** de mémoire car `phpunit.xml` plafonne
à `512M` et les tests PDF (dompdf) peuvent épuiser cette limite. Un run
intermédiaire à 512M a échoué en OOM dans
`StudentDossierPdfTest::test_job_regenere_en_arriere_plan` ; ce n'est pas un
échec de la fonctionnalité. Le run final complet ci-dessus est la preuve
retenue.

#### Attribution des 12 échecs

| Test | Message | Attribution |
|---|---|---|
| `AccessibiliteParcoursTest::test_aria_invalid_sur_champs_en_erreur` | 0 vs 16 champs | préexistant, accessibilité |
| `AccessibiliteParcoursTest::test_erreurs_associees_aux_champs_via_describedby` | 0 vs 10 | préexistant, accessibilité |
| `EnseignantInterfaceTest::test_fiche_requetes_bornees_sans_n_plus_un` | 16 requêtes | N+1 ; 15 à HEAD ; +1 `parametres` non documentaire |
| `FaqAideScolariteTest::test_wizard_affiche_contact_configure_et_lien_faq` | contenu HTML | préexistant, FAQ/UI |
| `InscriptionFileTraitementTest::test_absence_de_requetes_n_plus_un_sur_la_file` | 26 < 25 | N+1 déjà rouge à HEAD (25 < 25) |
| `InscriptionPerformanceFiabiliteTest::test_file_administrative_absence_de_n_plus_un_sur_volume` | 26 requêtes | N+1 déjà rouge à HEAD (25) |
| `PopulationPhase2CompleteTest::test_dashboard_ventilations_bornees` | 24 vs 21 | N+1 déjà rouge à HEAD (23 vs 21) |
| `SaisieIdentificationStricteTest::test_date_impossible_est_rejetee_sans_roulement_silencieux` | message absent | préexistant, validation/UI |
| `SaisieIdentificationStricteTest::test_date_future_est_rejetee` | message absent | préexistant, validation/UI |
| `SaisieIdentificationStricteTest::test_annee_anterieure_a_1900_est_rejetee` | message absent | préexistant, validation/UI |
| `SaisieIdentificationStricteTest::test_le_formulaire_existant_embarque_le_controle_strict` | HTML | préexistant, validation/UI |
| `Security\ArchivesFicheLoop3Test::test_fiche_sans_n_plus_un` | 26 requêtes | N+1 déjà rouge à HEAD (25) |

#### Attribution des 3 erreurs

`PortailEtudiantPublicationTest` :

- `test_portail_expose_uniquement_apres_publication`
- `test_portail_expose_les_plannings_verrouilles`
- `test_dashboard_ne_doit_pas_exposer_les_cours_non_publies`

Message : `1 conflit(s) bloquent la publication.` Ces erreurs relèvent des
travaux Schedule/publication parallèles, sans lien avec les documents.

#### N+1 : preuve d'attribution

Les 5 budgets N+1 affichent **+1 requête** par rapport au baseline HEAD
`907bf0c` ; 4 étaient déjà rouges, le 5e passe de vert à rouge :

| Budget | HEAD | Arbre courant |
|---|---|---|
| `EnseignantInterface` | 15 (passait) | 16 |
| `InscriptionFileTraitement` | 25 (déjà rouge) | 26 |
| `InscriptionPerformanceFiabilite` | 25 (déjà rouge) | 26 |
| `PopulationPhase2Complete` | 23 vs 21 (déjà rouge) | 24 vs 21 |
| `ArchivesFicheLoop3` | 25 (déjà rouge) | 26 |

La requête supplémentaire est :

`SecondaireVerrou:71` → `VerrouillageService:22` → `ParametreService:27/28`
→ `select * from "parametres" where "cle" = ? limit 1`.

Elle vient du middleware de verrouillage (travaux sécurité/parallèles), **pas**
de la boucle documents. Aucune requête supplémentaire ne touche
`inscription_documents`, `student_documents` ou
`student_document_versions` sur ces pages. Le seul budget qui passe de vert à
rouge (`EnseignantInterface`) est donc imputable à cette requête extérieure,
pas à la fonctionnalité.

### 5.4 Migration et dry-run en dev

Migration appliquée uniquement par chemin, sans `migrate:fresh` ni
`db:wipe` :

```bash
php artisan migrate --path=database/migrations/2026_10_20_090000_creer_table_inscription_documents.php
```

Sortie observée : `2026_10_20_090000_creer_table_inscription_documents .. [13] Ran`.

Vérification du schéma :

```bash
php artisan db:table inscription_documents
```

- table InnoDB, 13 colonnes ;
- enum de décision complet ;
- `uniq_inscription_document_type` ;
- index `student_document_id`, `decision` ;
- FK `inscription_academique_id` (cascade),
  `student_document_id` / `student_document_version_id` / `decide_par`
  (`nullOnDelete`).

État de la base de dev au moment de la preuve :

```text
inscriptions_ouvertes = 20
dossiers              = 3
documents             = 0
associations          = 0
```

Dry-run :

```bash
php artisan documents:associer-inscriptions --dry-run
```

Sortie : **« Simulation : 20 inscription(s) ouverte(s), 0 pièce(s) réutilisée(s), 0 erreur(s). »**

Le `0 pièce réutilisée` est **attendu** : la base de dev contient 0 document
permanent. Le dry-run prouve le câblage et l'absence d'erreur ; l'idempotence
est prouvée par `test_synchronisation_idempotente` et
`test_commande_de_rattrapage_dry_run_backfill_et_tout_sans_reecrire_une_annee_cloturee`.
La preuve fonctionnelle de réutilisation est
apportée par les tests sur SQLite (§5.1). Aucune association n'a été écrite en
dev (0 ligne `inscription_documents`).

### 5.5 Style et lint

- `php -l` sur `ReutilisationDocumentsService.php` après le dernier
  durcissement : **No syntax errors detected**.
- `vendor/bin/pint --test` sur les fichiers de la boucle : **passed**,
  y compris le service et le test après l'ajout de la garde « année
  clôturée ».
- `vendor/bin/pint --dirty` global reste bloqué par un **parse error
  préexistant** dans `app/Http/Livewire/Admin/Scrutins/Builder.php:172`
  (travaux Scrutins parallèles). Le Pint ciblé de la boucle est vert.

## 6. Revue de sécurité

### 6.1 Contrôles

| Menace | Contrôle | Preuve |
|---|---|---|
| Accès d'un étudiant aux documents d'un autre | routes/policies existantes ; `student_dossier_id` vérifié ; téléchargement 404 | `test_changement_didentite...` |
| Accès d'un admin hors faculté | `peutAccederFaculte` dans `InscriptionDocumentPolicy` | `Gate::view` refusé dans le test |
| Téléchargement sans permission | `student_documents.download` + document non nul + périmètre | policy `download` |
| Confondre téléversement et validation | `pending` → `en_attente` ; `validated_at` obligatoire | tests dédiés |
| Réutiliser une pièce refusée/expirée/non conforme | décisions `rejete` / `expire` / `non_conforme` | `test_refuse_expire...` |
| Fichier altéré de même taille | SHA-256 + `hash_equals` | `test_fichier_altere...` |
| Fichier absent / vide / taille incohérente | `taille()` + contrôles | `test_refuse_expire...` |
| Format/MIME trompeur | extension exigée + liste blanche MIME | `test_format_non_conforme...` |
| PII dans l'audit | audit limité à type/décision/année ; motif libre exclu | assertion `historique_academique` |
| PII dans les journaux explicites | contextes limités aux IDs/classes d'événement | revue du code des listeners |
| Race / doublon annuel | unique (`inscription`, `type`) + `firstOrNew` + catch | `test_synchronisation_idempotente` |
| Échec partiel | `DB::transaction` | `test_synchronisation_est_transactionnelle...` |
| Blocage du parcours par un incident documentaire | listeners fail-soft `report()` + log sans PII | code + tests |
| Module désactivé | garde `modules.student-dossiers.active` | test commande |
| Année clôturée réécrite | garde service + commande `en_cours` + listeners dernière ouverte | test remplacement + commande |
| Secret/PII dans le snapshot | version, dates, MIME, extension, taille, SHA-256 uniquement | code + modèle |
| Nouvelle surface HTTP | aucune route ajoutée ; écritures internes/CLI | revue du diff (aucune route modifiée) |

### 6.2 Points vérifiés

- **Pas de copie de fichier** : le service ne fait que lire les métadonnées et
  créer des lignes d'association. Aucun `Storage::copy`, aucun déplacement.
- **Pas de suppression** : le remplacement crée une version ; l'ancienne
  version et le fichier restent. La migration ne supprime rien.
- **Pas de PII dans l'audit** : le test utilise un motif contenant un numéro
  de CIN et un nom, et vérifie que `historique_academique` ne contient ni
  l'un ni l'autre. Le motif reste dans `inscription_documents.motif`, accessible
  uniquement aux utilisateurs autorisés.
- **Actions d'audit** : `inscription.document_reutilise`,
  `inscription.document_associe`, `inscription.document_decision` ; le payload
  contient le type, la décision et l'année, jamais le motif libre.
- **Pas de PII dans les contextes de log explicites** : les listeners
  journalisent l'ID d'inscription ou la classe d'événement, jamais le motif,
  le nom du fichier ou l'identité. Les exceptions sont transmises à `report()`
  sans ajout de contexte métier.
- **Pas de nouvelle route** : les vues utilisent les routes existantes ; la
  policy `synchroniser` n'est pas exposée par une route dans cette boucle.
- **Pas de secret** : aucune clé, aucun token, aucune configuration sensible
  ajoutée.

### 6.3 Limites de sécurité

- Les documents **legacy** dont `validated_at` est nul ne sont jamais
  réutilisés (choix conservateur). Ceux dont `sha256` est nul peuvent être
  réutilisés après contrôles de taille/format/MIME, mais **sans vérification
  d'empreinte** (réserve R1).
- Le motif libre de refus peut contenir des PII si un agent en saisit ; il est
  stocké dans une colonne accessible aux utilisateurs autorisés, mais jamais
  recopié dans l'audit. Une consigne de saisie / minimisation reste utile.
- La course réelle MySQL n'est pas couverte par un test dédié : la contrainte
  unique et le `catch` sont en place, l'idempotence est testée sur SQLite
  (réserve R5).

## 7. Non-régression et limites d'exécution

- Aucun `migrate:fresh`, `db:wipe`, suppression de document ou campagne/e-mail
  réel n'a été exécuté.
- La migration est additive et appliquée uniquement par `--path` sur le
  fichier de la boucle.
- L'arbre contient des travaux parallèles (289+ fichiers modifiés/untracked) ;
  la boucle n'a pas commité, poussé, ni écrasé ces travaux.
- Les 12 échecs et 3 erreurs de la suite complète sont attribués en §5.3.
  Aucun n'est imputable à une requête ou à un test de la boucle documents.
- **Flake d'ordre/état** : un run intermédiaire à 512M a montré un échec isolé
  de `Security\ArchivesIsolationLoop1Test::test_configuration_ecritures_refusees_au_perimetre_restreint`.
  Le test passe seul, passe avec `DocumentsReutilisablesTest`, et passe dans le
  run final complet. Il est classé comme flake d'ordre/état préexistant, sans
  lien avec les documents (réserve R9).
- **Mémoire** : `phpunit.xml` plafonne à 512M ; les tests PDF peuvent épuiser
  cette limite. Le run final a été exécuté avec `-d memory_limit=1G`. Ce point
  est une limite d'environnement de test, pas un défaut de la fonctionnalité.
- Les changements de la boucle n'ont pas été commités ; ils restent dans
  l'arbre de travail.

## 8. Réserves et suivis

| ID | Réserve | Impact | Action recommandée |
|---|---|---|---|
| **R1** | Documents legacy : `validated_at` nul → jamais réutilisés ; `sha256` nul → réutilisés sans vérification d'empreinte | Réutilisation incomplète ou intégrité non prouvée pour l'historique | Backfill/renormalisation des documents legacy, puis exiger SHA-256 ; test de non-régression |
| **R2** | `DocumentsRequisService::requisPour()` retourne `[]` sans variable `INS_DOCUMENTS_REQUIS*`, alors que `checklistPourType()` retombe sur `MATRICE_DEFAUT` | Le gate historique `verifier()` et la nouvelle checklist peuvent diverger | Unifier la source de vérité des exigences ; tests du gate et de la checklist |
| **R3** | Base de dev : 0 `student_documents` ; dry-run à 0 réutilisation | Pas de preuve live de réutilisation en dev | Scénario de recette avec les pièces obligatoires validées pour un étudiant de test, puis dry-run ciblé |
| **R4** | Suite complète non verte : 12 échecs + 3 erreurs préexistants/extérieurs ; 5 budgets N+1, dont 1 passage vert→rouge dû à `parametres` | Gate global non vert ; bruit pour les prochaines boucles | Traiter N+1 (`SecondaireVerrou`/`ParametreService`) et les tests Schedule/accessibilité/FAQ/saisie dans leurs boucles |
| **R5** | Pas de test de course MySQL réelle sur l'unique | Confiance partielle dans la concurrence | Test MySQL gated : deux synchronisations simultanées, une seule ligne, aucune exception utilisateur |
| **R6** | `inscription_documents` est supprimé en cascade avec l'inscription ; le document physique reste | Perte de l'historique annuel si une inscription est supprimée | Définir une politique de rétention (soft delete ou restrict) selon le besoin d'audit |
| **R7** | Pas d'action UI de resynchronisation ; la policy `synchroniser` n'est pas exposée | Les agents doivent passer par la commande | Ajouter une action de scolarité protégée si le besoin opérationnel est confirmé |
| **R8** | La commande ne backfill que les années `en_cours` | Les années clôturées jamais synchronisées ne sont pas backfillées | Backfill historique séparé, en lecture seule, si un reporting annuel l'exige |
| **R9** | Flake `ArchivesIsolationLoop1Test` observé une fois à 512M, non reproduit | Bruit possible en CI | Isoler l'état partagé / l'ordre des tests dans une boucle dédiée |
| **R10** | `pint --dirty` global bloqué par le parse error Scrutins `Builder.php:172` | Le gate Pint global ne peut pas être exécuté | Corriger le parse error dans la boucle Scrutins |

## 9. Verdict

**PASS_WITH_RESERVATIONS.**

La fonctionnalité est livrée de bout en bout :

- schéma additif appliqué en dev, contraintes et FK vérifiées ;
- service de réutilisation, événements, listeners, policy, commande et vues ;
- 14 tests dédiés verts, 121 tests ciblés verts ;
- suite complète sans échec imputable à la boucle ;
- aucune duplication de fichier, aucune suppression, aucune PII dans l'audit ;
- aucune année clôturée réécrite, aucun téléversement considéré comme validé.

Les réserves déterminantes sont R1–R3 (preuve live et documents legacy), R4
(suite complète non verte pour des causes extérieures) et R5 (course MySQL
non testée). R6–R10 sont des suivis de rétention, d'UX et de qualité. Ces
réserves ne bloquent pas la livraison fonctionnelle, mais doivent être
tracées avant une généralisation en production.

## 10. Commandes reproductibles

```bash
# 1. Tests dédiés
php artisan test --filter=DocumentsReutilisablesTest --compact

# 2. Suites ciblées documents / inscriptions / dossiers
php artisan test --filter="DocumentsReutilisablesTest|DocumentsRequisTest|ReinscriptionTest|StudentDocumentTest|StudentDocumentSecurityTest|StudentDocumentIsolationTest|InscriptionChecklistDocumentaireTest|ExperienceInscriptionLoopTest|InscriptionDepotSecuriseTest|StudentDossierTest|StudentDossierPhase8Test|StudentDossierPdfTest|AffectationApresDossierCompletTest|WorkflowDossierPlanningTest" --compact

# 3. Suite complète (mémoire augmentée pour dompdf)
php -d memory_limit=1G artisan test --compact

# 4. Migration additive (déjà appliquée en dev)
php artisan migrate --path=database/migrations/2026_10_20_090000_creer_table_inscription_documents.php

# 5. Schéma
php artisan db:table inscription_documents

# 6. Dry-run de rattrapage
php artisan documents:associer-inscriptions --dry-run

# 7. État dev (lecture seule)
php artisan tinker --execute="echo json_encode([
  'inscriptions_ouvertes' => App\\Models\\InscriptionAcademique::where('statut','en_cours')->count(),
  'dossiers' => App\\Modules\\StudentDossiers\\Models\\StudentDossier::count(),
  'documents' => App\\Modules\\StudentDossiers\\Models\\StudentDocument::count(),
  'associations' => App\\Modules\\StudentDossiers\\Models\\InscriptionDocument::count(),
]);"

# 8. Style ciblé
vendor/bin/pint --test app/Modules/StudentDossiers/Services/ReutilisationDocumentsService.php tests/Feature/DocumentsReutilisablesTest.php
```

---

**Fin du rapport.** Les changements de cette boucle ne sont ni commités ni
poussés. Les preuves ci-dessus ont été exécutées le 20/10/2026 sur l'arbre de
travail local.
