# LOOP-UNIREGISTRE-PORTAIL-ETUDIANT-ADVANCED-01

> Cahier des charges : audit, spécification, implémentation progressive, auto-validation et non-régression
> des 9 fonctionnalités du portail étudiant UniRegistre.

| Métadonnée | Valeur |
|---|---|
| Date de la phase | 2026-10-22 |
| Branche | `feature/admin-modifier-etudiant` |
| Commit de tête | `38ef149` |
| Phase courante | **Phase 0 — Inventaire et audit (lecture seule)** |
| Code livré dans cette phase | Aucun (audit uniquement) |
| MCP CodeScene | **Indisponible** dans cet environnement — audit Code Health non exécuté, aucun score inventé |

---

## 1. Résumé exécutif

**Verdict de la Phase 0 : `PASS_WITH_RESERVATIONS` (audit) — 0 lot livré à ce stade.**

> **Mise à jour (Phase 1) :** le Lot 03 a ensuite été exécuté — 2 défauts corrigés, 2 tests de
> non-régression ajoutés, 74 tests verts. Voir §15.
>
> **Mise à jour (Phase 2) :** le Lot 01 a ensuite été exécuté — D-01 corrigé sur les 4 surfaces
> étudiantes, simulation self-service exposée sans second moteur, simulations back-office
> confinées. 166 tests de proximité verts. Voir §17 et §19.

- Les 9 fonctionnalités ont été auditées contre le code réel.
- **3 fonctionnalités existent déjà** et ne doivent **pas** être reconstruites :
  - Lot 04 — guichet administratif (`app/Modules/OnlineRequests`) ;
  - Lot 05 — coffre-fort documentaire (`app/Modules/StudentDossiers`) ;
  - Lot 02 — absences et justificatifs (`app/Modules/Attendance`), complet côté données, incomplet côté restitution.
- **1 fonctionnalité a un moteur déjà écrit mais pas exposé aux étudiants** : Lot 01 — simulation.
  `SimulationParcoursService` existe, gère 13 scénarios, n'écrit jamais dans les notes, mais n'est
  accessible qu'en administration et son module est **désactivé par défaut**.
- **5 fonctionnalités sont réellement absentes** : centre de notifications étudiant complet (Lot 03,
  partiel), devoirs (Lot 06), carte étudiante (Lot 07), stages/PFE (Lot 08), coach pédagogique (Lot 09).
- **8 défauts préexistants** identifiés, dont 5 re-vérifiés en direct dans cette phase (§8).
- **8 décisions métier** manquantes, documentées en fiches (§9). Aucune règle n'a été inventée.
- **1 lot bloqué** : Lot 09, décision institutionnelle NO-GO déjà consignée (`PORT-19`).

### Ce qui n'est **pas** fait

Aucune route, migration, vue, policy ni test n'a été créé ou modifié dans cette phase.
Le présent document est le seul livrable.

---

## 2. État initial du dépôt

### 2.1 Git

| Élément | Valeur observée |
|---|---|
| Branche courante | `feature/admin-modifier-etudiant` |
| Commit de tête | `38ef149` |
| `main` distant | `178dd52` |
| Position relative | 1 commit d'avance / 1 de retard sur `main` |
| Entrées non commitées | **435** (231 ` M`, 202 `??`, 2 `A`) |
| Entrées pré-stagées préexistantes | `.gitmodules`, `rea` |

**Constat majeur :** le dépôt est un WIP massif non commité. Une session opencode concurrente édite le
même arbre de travail pendant cet audit. Toute mesure de test est un **snapshot daté**, pas une vérité stable.

### 2.2 Base de données et migrations

| Élément | Valeur |
|---|---|
| Connexion dev | MySQL, base `inscription_etudiants` |
| Connexion tests | SQLite `:memory:` (forcée par `phpunit.xml`) |
| Migrations présentes | **183** |
| Migrations en attente | **0** |
| Opérations destructives exécutées | **Aucune** |

### 2.3 Rapports LOOP antérieurs examinés

| Rapport | Portée | Conclusion pour ce LOOP |
|---|---|---|
| `LOOP-UNIREGISTRE-PORTAILS-ENTERPRISE-01.md` | Sécurité des portails | Aucune des 9 fonctionnalités n'y est construite |
| `LOOP-UNIREGISTRE-PORTAILS-CLOSURE-02.md` | Clôture sécurité portails | Idem |
| `LOOP-AUTO-VALIDATION-AUTO-PASS-01.md` | Auto-validation | Fournit la méthode et la baseline de tests |
| `docs/registre/registre-canonique.md` | Décisions institutionnelles | `PORT-19` = assistant IA **NO-GO** ; `EXM-08` = STAGE/RADIATION reportés, zéro source |

**Conséquence :** aucun risque de double implémentation avec un travail antérieur.

---

## 3. Socle transverse réel

### 3.1 Modules

`config/modules.php` déclare 15 modules. Activation par variable d'environnement :

| Module | Clé | Défaut | `.env` |
|---|---|---|---|
| Présences | `attendance` | actif | `true` |
| Dossiers étudiants | `student-dossiers` | actif | `true` |
| Demandes en ligne | `online-requests` | actif | `true` |
| Emploi du temps | `schedule` | actif | `true` |
| Enseignement | `enseignement` | actif | non déclaré (défaut) |
| Référentiel | `referentiel` | actif | non déclaré |
| Évaluation | `evaluation` | actif | `true` |
| Annonces | `announcements` | actif | non déclaré |
| Calendrier | `calendar` | actif | non déclaré |
| Tâches | `tasks` | actif | non déclaré |
| Finances | `finances` | actif | non déclaré |
| Archives étudiant | `student-archive` | actif | non déclaré |
| **Parcours académique** | `parcours-academique` | **inactif** | **absent → OFF** |
| Versioning | `versioning` | actif | non déclaré |
| Sentinel | `sentinel` | actif | `true` |

**Point critique Lot 01 :** le moteur de simulation vit dans `parcours-academique`, **désactivé par défaut**
(`config/modules.php:76`). Toute exposition étudiante devra soit activer ce module, soit exposer le
service via une surface étudiante sans dépendre du flag admin — décision technique à documenter.

### 3.2 Autorisations

- `app/Support/Permissions.php` : **223 permissions**, **5 rôles** (`super_admin`, `admin_scolarite`,
  `agent_scolarite`, `consultation`, `admin_faculte`), Gates enregistrés dans `AppServiceProvider`.
- **Aucune permission dédiée** aux 9 nouvelles fonctionnalités (pas de `devoirs.*`, `carte.*`,
  `stages.*`, `coach.*`, `simulation.*`).
- Les portails étudiant/enseignant utilisent une **session** (`etudiant_auth_id`, `enseignant_auth_id`),
  pas le guard `User` standard. Conséquence : une policy `User` ne protège pas ces routes ; le contrôle
  est fait par `abort_if(... session ...)` dans les contrôleurs.
- Middleware `module:<clé>` : module inactif ⇒ 404 même en appel direct.

### 3.3 Routes

**909 routes** au total (`php artisan route:list --json`).

| Portail | Préfixe | Contrôle d'accès |
|---|---|---|
| Étudiant | `etudiant.*` | `etudiant.auth` + session |
| Enseignant | `enseignant.*` | `enseignant.auth` + session |
| Administration | `admin.*` | `auth` + `admin` + permissions |
| API v1 | `api.v1.*` | Sanctum + scopes + throttle |

### 3.4 Stockage privé et fichiers

- `app/Modules/StudentDossiers/Services/DocumentStorageService.php` : validation taille + MIME réel +
  signature, SHA-256, **hook antivirus** (`AntivirusScannerInterface`), refus si l'analyse échoue.
- Driver antivirus par défaut : `config('student-dossiers.antivirus.driver', 'null')` — **fail-open en
  apparence** si `clamav` n'est pas configuré. À traiter comme une décision de sécurité (FICHE-06).
- `app/Modules/Enseignement/Controllers/MesRessourcesController.php` **n'utilise pas**
  `DocumentStorageService` : les supports de cours ne bénéficient ni du scanner ni de la validation
  renforcée. Défaut D-08.

### 3.5 Notifications, workflow, audit

| Capacité | Implémentation | Réutilisable |
|---|---|---|
| Notifications | `AppNotification`, `NotificationService`, 3 contrôleurs par rôle | Oui, mais UI partagée défectueuse (D-04) |
| Workflow demandes | `RequestWorkflowService::TRANSITIONS` (9 états) | Oui, aucune raison d'en écrire un second |
| SLA demandes | `SlaService` | Oui |
| Audit | `AuditService`, `HistoriqueAcademique` | Oui, mais PII possible (D-05) |
| Data Mesh | Produits catalogue/inscriptions/affectations/résultats | Non utilisé par les 9 features |

---

## 4. Cartographie des 9 fonctionnalités — état réel

### Lot 01 — Simulation des moyennes, crédits et rattrapage

**Ce qui existe déjà (et ne doit pas être réécrit) :**

| Élément | Fichier | Preuve |
|---|---|---|
| Moteur de calcul unique | `app/Services/AcademicCalculationService.php` | `SEUIL_VALIDATION = 10.0` (l.22), `SEUIL_RATTRAPAGE = 8.0` (l.25) — **codés en dur** |
| Moteur de simulation | `app/Modules/ParcoursAcademique/Services/SimulationParcoursService.php` | 13 scénarios, append-only, « n'écrit JAMAIS dans notes » (docblock l.17-20) |
| Modèle de scénario | `app/Modules/ParcoursAcademique/Models/ParcoursSimulation.php` | `SCENARIOS` = passage, redoublement, conditionnel, conservation_dettes, reorientation, changement_parcours, admission_formation, passerelle_master, validation_semestre, compensation, validation_matiere, equivalence, dispense |
| Contrat | `app/Contracts/SimulationParcoursInterface.php` | Interface injectable |
| Tests | `tests/Feature/ParcoursAcademiqueTest.php` | Couverture existante |

**Ce qui manque réellement :**
1. **Aucune surface étudiante.** Les routes sont `admin/parcours/simulations*`, protégées par
   `auth` + `admin` + `can:parcours.view|simuler` + `module:parcours-academique`.
2. **Aucun scénario nommé « rattrapage »** — le rattrapage est atteignable via `validation_semestre` /
   `compensation` + hypothèses, mais pas comme parcours explicite.
3. **Aucune comparaison multi-scénarios côté étudiant.**
4. **Aucun marquage « non officiel »** dans une UI étudiante (le moteur, lui, ne persiste rien d'officiel).

**Verdict d'audit : `PASS_WITH_RESERVATIONS`** — moteur réutilisable et non écrivant, surface étudiante absente.

### Lot 02 — Absences et justificatifs

**Existant :** `app/Modules/Attendance` (34 fichiers) — `Presence`, `Justification`, `SeanceAppel`,
`AssiduiteService`, `JustificationPolicy`, `DeposerJustificationRequest`, `MesPresencesController`,
`JustificationController`, événements + listeners d'audit, routes dédiées, tests.

**Trous démontrés :**
1. **Le justificatif n'est jamais téléchargeable.** `piece_chemin` et `piece_nom` sont écrits
   (`AssiduiteService.php:67-68`) mais aucun route/contrôleur ne les sert :
   `grep -rn "justificatif" routes/web.php` → **0 résultat** ; `JustificationController` n'expose que
   `index`, `valider`, `deposer`.
2. **Aucune règle de délai, de catégorie ni de sanction.** `assiduite.seuil_alerte` est défini
   (`SettingDefinition.php:40`), semé en base, validé par les tests… et **lu nulle part** dans
   `app/` : paramètre mort (D-03).
3. Pas d'historique de décisions versionné : la validation écrase l'état.

**Verdict d'audit : `PASS_WITH_RESERVATIONS`** (socle solide) avec parties **BLOCKED** (FICHE-01, FICHE-02).

### Lot 03 — Centre de notifications

**Existant :** 3 vues (admin/étudiant/enseignant) via `NotificationController`, `NotificationService`,
filtres non-lues/priorité/type, marquage individuel et en lot, gabarits admin, tests
(`NotificationTest`, `EnseignantPortailTest`).

**Trous démontrés :**
1. **Bug de routage enseignant.** `resources/views/notifications/index.blade.php:43` et `:48` :
   ```blade
   request()->routeIs('admin.*') ? route('admin.notifications.lire', $notification) : route('etudiant.notifications.lire', $notification)
   ```
   Un enseignant poste donc vers la route **étudiante**, qui fait
   `abort_if($notification->etudiant_id !== session('etudiant_auth_id'), 404)` → **404 systématique**.
   Les tests existants passent par `route('enseignant.notifications.lire')` directement, ils ne couvrent
   pas ce chemin UI.
2. **Aucune entrée de navigation étudiante.** `_navigation.blade.php` déclare `admin.notifications.index`
   (l.233) et `enseignant.notifications.index` (l.365) — **rien pour l'étudiant**.
3. Pas de colonne `categorie` : filtre par `type` uniquement.
4. Pas d'UI de préférences étudiant, pas de déduplication explicite.

**Verdict d'audit : `PASS_WITH_RESERVATIONS`** — fonctionnel, avec un défaut bloquant pour les enseignants.

### Lot 04 — Guichet administratif numérique

**Existant complet :** module `OnlineRequests` (30 fichiers) — `OnlineRequest` (9 états),
`RequestWorkflowService` (transitions explicites), `SlaService`, `AuditDemandes`, catalogue de types,
pièces jointes, vues étudiant/admin, tests `OnlineRequestTest` + `OnlineRequestSecurityTest`.

```
draft → submitted → received → in_review → {waiting_student | approved | rejected}
waiting_student → {in_review | cancelled}
approved → completed
rejected, cancelled, completed → ∅ (terminaux)
```

**Trous :** module activé mais **pas d'entrée de navigation étudiante dédiée** constatée ; vérification
des transitions concurrentes à confirmer par test dédié.

**Verdict d'audit : `PASS_WITH_RESERVATIONS`** — réutiliser, ne pas réécrire.

### Lot 05 — Coffre-fort documentaire

**Existant complet et durci :** `app/Modules/StudentDossiers` (64 fichiers) — `StudentDocumentService`,
`DocumentStorageService` (MIME réel + SHA-256 + antivirus), `ReutilisationDocumentsService`,
`OfficialDocumentController`, `StudentDossierPdfController`, policies, tests.

**Trou :** versionnement et état de vérification à confirmer ; politique de rétention absente (FICHE-05).

**Verdict d'audit : `PASS_WITH_RESERVATIONS`** — compléter marginalement, ne pas dupliquer.

### Lot 06 — Cours, supports et devoirs

**Existant :** `app/Modules/Enseignement` (23 fichiers) — supports de cours complets, visibilité par
affectation, `MesRessourcesController`.

**Absent :** **aucun concept de devoir.** `grep -rln "Devoir|devoir" app/Modules/Enseignement app/Models routes/`
→ **0 résultat**. Aucune table `devoirs`, aucun modèle, aucune route, aucun test.

**Défaut annexe :** les supports ne passent pas par `DocumentStorageService` (D-08).

**Verdict d'audit : `FAIL` (partiel)** — les supports sont livrés, les devoirs sont inexistants.
Parties **BLOCKED** : politique de retard, nombre de tentatives, publication des corrections (FICHE-03).

### Lot 07 — Carte étudiante numérique

**Existant réutilisable :**
- `app/Http/Controllers/VerifyDocumentController.php`
- `app/Services/Documents/DocumentVerificationService.php` — vérification publique par code court,
  rate limitée, IP hachée, nom masqué (`masquerNom`, l.64).

**Absent :** **aucun code de carte.** `grep -rln "carte_etudiant|CarteEtudiant|carte etudiante" app/ resources/ routes/ database/`
→ **0 résultat**. Aucune vue, aucun QR, aucune expiration/révocation.

**Verdict d'audit : `FAIL` (inexistant)** — à construire **sur** le socle de vérification existant.
Partie **BLOCKED** : expiration/révocation et données affichables (FICHE-04).

### Lot 08 — Stages et PFE

**Absent :** `find app -ipath "*stage*" -o -ipath "*pfe*" -o -ipath "*soutenance*"` → **2 faux positifs**
(`EvaluationCompostage`, `CompostageService`). Aucun modèle entreprise, offre, candidature, encadrant,
jalon, livrable, soutenance.

**Décision déjà consignée :** `EXM-08` — STAGE/RADIATION **reportées, zéro source**. Le présent LOOP ne
peut donc pas inventer l'éligibilité, le classement ni l'attribution.

**Verdict d'audit : `BLOCKED`** — décision institutionnelle requise avant toute implémentation (FICHE-07).

### Lot 09 — Coach pédagogique

**Existant :** aucun service IA dans le catalogue d'outils de ce dépôt côté application. Aucune
dépendance fournisseur IA déclarée.

**Décision déjà consignée :** `PORT-19` — assistant IA **NO-GO**.

**Verdict d'audit : `BLOCKED`** — fournisseur, consentement et rétention non tranchés (FICHE-08).

---

## 5. Modèles et tables concernés

| Domaine | Tables | Modèles |
|---|---|---|
| Notes / résultats | `notes`, `semestres`, `inscriptions_academiques` | `Note`, `Semestre`, `InscriptionAcademique` |
| Référentiel crédits | `programmes.credits_requis`, `types_diplome.credits_requis`, `niveaux.credits_attendus`, `semestres_lmd.credits_attendus` | `Programme`, `TypeDiplome`, `Niveau`, `SemestreLmd` |
| Simulation | `parcours_simulations` (append-only) | `ParcoursSimulation` |
| Présences | `presences`, `justifications`, `seances_appel`, `attendance_templates` | `Presence`, `Justification`, `SeanceAppel`, `AttendanceTemplate` |
| Notifications | table des notifications | `AppNotification` |
| Demandes | table `online_requests` + pièces | `OnlineRequest` |
| Documents | tables `student_dossiers` | `StudentDocument` |
| Supports | tables `enseignement` | ressources de cours |
| Devoirs | **inexistante** | **inexistant** |
| Carte | **inexistante** | **inexistant** |
| Stages/PFE | **inexistante** | **inexistant** |

**Constat d'intégrité :** trois sources divergentes pour les crédits attendus (`semestres.credits_total`
persisté, `niveaux/semestres_lmd.credits_attendus`, `programmes/types_diplome.credits_requis`) — D-06.

---

## 6. Autorisations — matrice cible par fonctionnalité

| Fonctionnalité | Consulter | Créer | Approuver | Télécharger | Périmètre |
|---|---|---|---|---|---|
| Simulation | étudiant (soi) + admin `parcours.view` | étudiant (soi) | — | — | session étudiant / faculté admin |
| Absences | étudiant (soi), enseignant (ses séances), scolarité | étudiant (justificatif) | scolarité / admin | étudiant (sa pièce), scolarité | séance → groupe → faculté |
| Notifications | chacun (les siennes) | système | — | — | utilisateur |
| Guichet | étudiant (ses demandes), service affecté | étudiant | service autorisé | étudiant (ses pièces), service | demande → service → faculté |
| Coffre-fort | étudiant (soi), admin `documents.view` | étudiant | scolarité | propriétaire + rôle autorisé | étudiant |
| Cours / devoirs | étudiant affecté, enseignant responsable | enseignant responsable | enseignant responsable | étudiant affecté, enseignant | affectation réelle |
| Carte | étudiant (soi), public (vérification minimale) | système | — | — | inscription active |
| Stages / PFE | à définir | à définir | à définir | à définir | **BLOCKED** |
| Coach | étudiant (soi) | étudiant | — | — | **BLOCKED** |

**Règle transverse :** un menu masqué n'est jamais une protection. Chaque accès direct par identifiant
doit être testé (`assertNotFound` attendu pour un objet d'autrui).

---

## 7. Tests de référence

### 7.1 Commandes officielles du dépôt

```bash
vendor/bin/pint --test
php artisan test --compact
php artisan test --filter=<Classe>
php -l <fichier>
```

> `vendor/bin/pint --dirty` est **proscrit** dans ce contexte : il reformaterait du WIP hors périmètre.

### 7.2 Baseline mesurée (snapshot 2026-10-22)

| Métrique | Valeur |
|---|---|
| Tests | 3882 |
| Réussis | 3856 |
| Échecs | 0 |
| Erreurs | 1 |
| Ignorés | 25 |
| Assertions | 18901 |
| Durée | ~340 s |

**Erreur unique :** `ArchiveAuthorizationTest::test_matrice_des_permissions_par_role` — **instable**.
Cause : collision aléatoire de `num_inscription` (`INS-TEST-`) sur une contrainte UNIQUE, probabilité
~0,13 % par exécution. Passe en isolation et par classe. Ne masque aucun défaut fonctionnel.

**Réserve de fiabilité :** la suite a tourné pendant qu'une session concurrente écrivait dans le même
arbre de travail (plusieurs fichiers modifiés pendant la mesure). Cette baseline est un **snapshot daté**,
pas une garantie. Elle devra être re-mesurée sur un arbre calme avant toute conclusion de non-régression.

### 7.3 Tests existants par lot

| Lot | Fichiers de test |
|---|---|
| 01 | `ParcoursAcademiqueTest`, `AcademicCalculationTest`, `AcademicProgressionTest` |
| 02 | `AttendanceTest`, `PresenceIntelligenteTest`, `AppelQrTest`, `AttendanceRisquesP1Test`, `TeacherAttendanceIntelligentTest` |
| 03 | `NotificationTest`, `EnseignantPortailTest`, `EspaceEnseignantModulesTest` |
| 04 | `OnlineRequestTest`, `OnlineRequestSecurityTest` |
| 05 | `StudentDossierTest` |
| 06 | couverture supports uniquement — **aucun test de devoir** |
| 07 | **aucun** |
| 08 | **aucun** |
| 09 | **aucun** |

---

## 8. Défauts préexistants

> Statut « confirmé » = re-vérifié en direct dans cette phase avec référence de fichier/ligne.

| ID | Défaut | Preuve | Gravité | Lot |
|---|---|---|---|---|
| **D-01** | Le tableau de bord étudiant affiche `moyenne`, `credits_obtenus`, `credits_total`, `decision` **sans filtre de publication**, alors que `ArchiveController::notes()` exige `DecisionDeliberation.publie = true` | `TableauDeBordController.php:41-47` ; `ArchiveController.php:99-104` | **Haute** | 01 |
| **D-02** | Le justificatif d'absence est stocké mais **jamais téléchargeable** (aucune route, aucun contrôleur) | `AssiduiteService.php:67-68` ; `grep justificatif routes/web.php` → 0 | **Haute** | 02 |
| **D-03** | `assiduite.seuil_alerte` est un **paramètre mort** : défini, semé, testé, jamais lu | `SettingDefinition.php:40` ; aucun usage dans `app/` | Moyenne | 02 |
| **D-04** | Marquage « lu » d'une notification **enseignant** pointe vers la route **étudiante** → 404 | `notifications/index.blade.php:43,48` + `NotificationController.php:74` | **Haute** | 03 |
| **D-05** | `HistoriqueAcademique` peut contenir des données personnelles sans liste blanche de champs | Audit antérieur — **à confirmer** | Moyenne | 03/05 |
| **D-06** | **Trois sources divergentes** des crédits attendus | `semestres.credits_total`, `niveaux.credits_attendus`/`semestres_lmd`, `programmes.credits_requis`/`types_diplome` | Moyenne | 01 |
| **D-07** | **Aucune entrée de navigation** « Notifications » côté étudiant | `_navigation.blade.php:233` (admin), `:365` (enseignant), rien étudiant | Moyenne | 03 |
| **D-08** | Les supports de cours et pièces d'identité ne passent pas par `DocumentStorageService` (pas de scanner, validation allégée) | `MesRessourcesController` n'injecte pas le service | **Haute** | 06 |

---

## 9. Décisions métier manquantes

> Aucune n'est tranchée ici. Chaque fiche indique le comportement sûr appliqué en attendant.

### FICHE-01 — Délais et catégories de justificatif d'absence

1. **Règle manquante :** délai maximal de dépôt, catégories recevables, pièces exigées par catégorie.
2. **Comportement observé :** dépôt possible sans limite de temps, `motif` libre, aucune catégorie.
3. **Options :** (a) délai fixe global ; (b) délai par catégorie ; (c) pas de délai, traitement manuel.
4. **Conséquences :** (a) simple, mais pénalise les cas longs ; (b) modèle de données supplémentaire ;
   (c) aucune automatisation possible.
5. **Comportement sûr temporaire :** accepter le dépôt, **ne jamais** appliquer de sanction automatique.
6. **À trancher par :** Vice-doyen / Responsable scolarité.

### FICHE-02 — Effet d'un justificatif validé sur les données académiques

1. **Règle manquante :** un justificatif validé change-t-il le taux d'assiduité, une note de participation,
   un statut de défaillance ?
2. **Observé :** la validation change uniquement le statut du justificatif ; aucun impact académique.
3. **Options :** (a) aucun impact (statu quo) ; (b) recalcul du taux ; (c) impact sur éligibilité aux examens.
4. **Conséquences :** (b)/(c) exigent une règle officielle et un recalcul auditable.
5. **Comportement sûr temporaire :** impact **aucun**, historique conservé.
6. **À trancher par :** Commission pédagogique.

### FICHE-03 — Politique de devoirs (retard, tentatives, publication)

1. **Règle manquante :** nombre de dépôts autorisés, effet du retard, date de publication des corrections.
2. **Observé :** aucun concept de devoir.
3. **Options :** (a) un seul dépôt remplaçable jusqu'à échéance ; (b) N dépôts versionnés ; (c) dépôt
   après échéance marqué « en retard » sans refus.
4. **Conséquences :** influence le schéma (`devoir_depots.version`, `depose_le`, `en_retard`).
5. **Comportement sûr temporaire :** dépôt **versionné et horodaté serveur**, remplacement autorisé,
   **aucune pénalité calculée**, mention « déposé après échéance » factuelle.
6. **À trancher par :** Direction des études.

### FICHE-04 — Carte étudiante : données affichables, expiration, révocation

1. **Règle manquante :** quelles données sur la carte, durée de validité, procédure de révocation.
2. **Observé :** aucune carte.
3. **Options :** (a) nom + numéro + statut + photo ; (b) nom + numéro seulement ; (c) ajout d'une date.
4. **Conséquences :** (a) implique une photo et donc une PII supplémentaire ; le QR ne doit contenir
   **qu'un code opaque**, jamais le CIN ni la date de naissance.
5. **Comportement sûr temporaire :** nom (masquable) + numéro étudiant + statut dérivé de
   `inscriptions_academiques.statut` + code de vérification opaque.
6. **À trancher par :** Doyen + DPO.

### FICHE-05 — Rétention et suppression des documents

1. **Règle manquante :** durée de conservation, anonymisation, suppression définitive.
2. **Observé :** aucune purge automatique.
3. **Options :** (a) conservation illimitée ; (b) N ans puis anonymisation ; (c) N ans puis suppression.
4. **Conséquences :** (b)/(c) exigent une politique approuvée et un journal de purge.
5. **Comportement sûr temporaire :** **aucune suppression**, aucune anonymisation automatique.
6. **À trancher par :** DPO + Archives.

### FICHE-06 — Politique antivirus

1. **Règle manquante :** le scan est-il obligatoire ? Que faire si le moteur est indisponible ?
2. **Observé :** `DocumentStorageService` interroge un driver `null` par défaut ; `MesRessourcesController`
   contourne le service.
3. **Options :** (a) fail-closed (refus si scanner indisponible) ; (b) fail-open journalisé ;
   (c) quarantaine puis revue manuelle.
4. **Conséquences :** (a) bloque les dépôts en dev ; (b) laisse passer des fichiers non analysés ;
   (c) coût humain.
5. **Comportement sûr temporaire :** brancher tous les uploads sur `DocumentStorageService`,
   **ne jamais afficher « fichier analysé »** si le driver est `null`, journaliser le mode.
6. **À trancher par :** RSSI.

### FICHE-07 — Stages/PFE : éligibilité, attribution, soutenance

1. **Règle manquante :** critères d'éligibilité, classement, rôle des encadrants et jurys, calendrier.
2. **Observé :** aucun module ; `EXM-08` documente un report sans source.
3. **Options :** (a) candidature libre + validation pédagogique ; (b) affectation par mérite ;
   (c) affectation administrative.
4. **Conséquences :** (b) nécessite une formule de classement officielle ; aucune option ne peut être
   déduite du code existant.
5. **Comportement sûr temporaire :** **aucune implémentation**. Le lot reste `BLOCKED`.
6. **À trancher par :** Direction des études + Doyen.

### FICHE-08 — Coach pédagogique : fournisseur IA, consentement, rétention

1. **Règle manquante :** fournisseur autorisé, base légale, durée de rétention des conversations et tentatives.
2. **Observé :** `PORT-19` = **NO-GO** sur l'assistant IA.
3. **Options :** (a) mode sans IA uniquement (planning + quiz statiques) ; (b) IA interne auto-hébergée ;
   (c) fournisseur externe avec DPA.
4. **Conséquences :** (c) implique un transfert de données personnelles hors établissement.
5. **Comportement sûr temporaire :** **mode sans IA** exclusivement, aucune donnée envoyée à l'extérieur.
6. **À trancher par :** Doyen + DPO + RSSI.

---

## 10. Matrice des exigences

### Lot 01 — Simulation

| ID | Exigence | Règle métier | Source de vérité | Acteur | Service à réutiliser | Si règle manquante | Tests | État | Preuve |
|---|---|---|---|---|---|---|---|---|---|
| 01-1 | Simuler depuis les données de l'étudiant connecté | Coefficients réels | `notes` + `matieres` | Étudiant (soi) | `AcademicCalculationService` | Afficher indisponibilité | Isolation inter-étudiants | **Manquant** | Aucune route étudiante |
| 01-2 | Séparer officiel / hypothétique | Aucune écriture officielle | `parcours_simulations` append-only | Étudiant | `SimulationParcoursService` | — | Vérifier `notes` inchangées | **Confirmé** (moteur) | Docblock l.17-20 |
| 01-3 | Comparer plusieurs scénarios | — | `parcours_simulations` | Étudiant | idem | — | 2 scénarios comparés | **Partiel** (admin) | `SimulationParcoursController` |
| 01-4 | Scénario de rattrapage explicite | Seuils 10 / 8 | `AcademicCalculationService` | Étudiant | idem | Afficher « règle non configurée » | Cas limite 8.0 / 10.0 | **Manquant** | Pas de scénario `rattrapage` |
| 01-5 | Résultat marqué non officiel | — | — | Étudiant | — | — | Contenu de la vue | **Manquant** | Aucune vue étudiante |
| 01-6 | Ne pas exposer les notes non publiées | Publication jury | `DecisionDeliberation.publie` | Étudiant | — | Masquer | Test de non-fuite | **Défaillant** | D-01 |

### Lot 02 — Absences

| ID | Exigence | Règle métier | Source | Acteur | Service | Si manquante | Tests | État | Preuve |
|---|---|---|---|---|---|---|---|---|---|
| 02-1 | Historique des absences | — | `presences` | Étudiant (soi) | `MesPresencesController` | — | Isolation | **Confirmé** | Module |
| 02-2 | Dépôt de justificatif | Formats autorisés | `justifications` | Étudiant | `AssiduiteService` | Refus si format interdit | MIME, taille | **Confirmé** | `DeposerJustificationRequest` |
| 02-3 | Téléchargement de la pièce | Autorisation propriétaire | stockage privé | Étudiant + scolarité | `JustificationController::telechargerPiece*` | — | Accès tiers → 404 ; chemins, MIME, en-têtes | **Confirmé** | D-02, §21 |
| 02-4 | Validation par autorité | Rôle scolarité | `JustificationPolicy` | Scolarité | `JustificationController::valider` | — | Décision non autorisée | **Confirmé** | Route l.123 |
| 02-5 | Délai / catégories | **absente** | — | — | — | Accepter sans sanction | — | **BLOCKED** | FICHE-01 |
| 02-6 | Effet académique | **absent** | — | — | — | Aucun impact | — | **BLOCKED** | FICHE-02 |
| 02-7 | Seuil d'alerte assiduité | **absente** | `assiduite.seuil_alerte` (déclarée, non lue) | Scolarité | à brancher | Ne pas alerter | Aucun consommateur (audit) | **BLOCKED** | D-03, §21.9 |

### Lot 03 — Notifications

| ID | Exigence | Règle | Source | Acteur | Service | Si manquante | Tests | État | Preuve |
|---|---|---|---|---|---|---|---|---|---|
| 03-1 | Liste paginée personnelle | — | notifications | Chacun | `NotificationController` | — | Isolation | **Confirmé** | 3 index |
| 03-2 | Marquage lu individuel | — | — | Chacun | idem | — | Chemin UI enseignant | **Défaillant** | D-04 |
| 03-3 | Marquage en lot | — | — | Chacun | `toutLire*` | — | Idempotence | **Confirmé** | Routes l.310/415/654 |
| 03-4 | Filtres catégorie + état | Catégories | — | Chacun | `appliquerFiltres` | Filtrer par type | Catégorie absente | **Partiel** | Pas de colonne `categorie` |
| 03-5 | Navigation étudiante | — | — | Étudiant | `_navigation` | — | Lien visible | **Manquant** | D-07 |
| 03-6 | Préférences | Politique institutionnelle | — | Étudiant | à créer | Aucune préférence | — | **BLOCKED** | FICHE-05 (rétention) |
| 03-7 | Déduplication | — | — | Système | `NotificationService` | — | Double exécution | **À vérifier** | — |

### Lot 04 — Guichet

| ID | Exigence | Règle | Source | Acteur | Service | Si manquante | Tests | État | Preuve |
|---|---|---|---|---|---|---|---|---|---|
| 04-1 | Catalogue de demandes | Types existants | `online_requests` | Étudiant | module | — | Types valides | **Confirmé** | Module |
| 04-2 | Transitions contrôlées | `TRANSITIONS` | `RequestWorkflowService` | Service | idem | — | Transitions interdites | **Confirmé** | l.14-24 |
| 04-3 | Historique immuable | — | audit | Service | `AuditDemandes` | — | Rejeu | **Confirmé** | Module |
| 04-4 | Pièces privées | — | stockage | Étudiant/service | module | — | Tiers → 404 | **Confirmé** | Tests sécurité |
| 04-5 | Accès service affecté | — | affectation | Agent | policies | Refuser | Agent non affecté | **À vérifier** | — |
| 04-6 | Concurrence | — | verrou | Service | à vérifier | — | Double traitement | **À vérifier** | — |

### Lot 05 — Coffre-fort

| ID | Exigence | Règle | Source | Acteur | Service | Si manquante | Tests | État | Preuve |
|---|---|---|---|---|---|---|---|---|---|
| 05-1 | Liste personnelle | — | documents | Étudiant | module | — | Isolation | **Confirmé** | Module |
| 05-2 | Versionnement | — | — | Étudiant | `StudentDocumentService` | — | Nouvelle version | **À vérifier** | — |
| 05-3 | État de vérification | — | — | Scolarité | module | Non validé par défaut | Validation/refus | **Confirmé** | Policies |
| 05-4 | Téléchargement contrôlé | — | — | Propriétaire/rôle | `DocumentStorageService` | — | URL directe | **Confirmé** | Tests |
| 05-5 | MIME + antivirus | FICHE-06 | config | — | `DocumentStorageService` | Ne pas prétendre analysé | Fichier malveillant | **Partiel** | D-08 |
| 05-6 | Rétention | **absente** | — | — | — | Aucune suppression | — | **BLOCKED** | FICHE-05 |

### Lot 06 — Cours et devoirs

| ID | Exigence | Règle | Source | Acteur | Service | Si manquante | Tests | État | Preuve |
|---|---|---|---|---|---|---|---|---|---|
| 06-1 | Catalogue des cours | Affectation réelle | affectations | Étudiant | `Enseignement` | — | Non affecté → 403 | **Confirmé** | Module |
| 06-2 | Supports téléchargeables | — | stockage | Étudiant affecté | à durcir | — | Tiers | **Partiel** | D-08 |
| 06-3 | Devoirs | **absente** | — | — | — | Ne pas créer | — | **Manquant** | 0 occurrence |
| 06-4 | Dépôt versionné | FICHE-03 | — | Étudiant | à créer | Versionner sans pénalité | Concurrence | **BLOCKED** | FICHE-03 |
| 06-5 | Retour enseignant | FICHE-03 | — | Enseignant responsable | à créer | Pas de note | — | **BLOCKED** | FICHE-03 |
| 06-6 | Notifications échéance | — | — | Système | `NotificationService` | — | Envoi unique | **Manquant** | — |

### Lot 07 — Carte

| ID | Exigence | Règle | Source | Acteur | Service | Si manquante | Tests | État | Preuve |
|---|---|---|---|---|---|---|---|---|---|
| 07-1 | Vue personnelle | — | inscription | Étudiant | à créer | — | Autre étudiant → 404 | **Manquant** | 0 occurrence |
| 07-2 | Statut dérivé | `inscriptions_academiques.statut` | inscription | — | — | Afficher « inconnu » | Inscription inactive | **Confirmé** (source) | Migration |
| 07-3 | Code de vérification | — | — | Système | `DocumentVerificationService` | Ne pas générer | Code invalide | **Partiel** | Service existe |
| 07-4 | Page publique minimale | FICHE-04 | — | Public | `VerifyDocumentController` | Nom masqué | Pas de CIN/DOB | **Partiel** | `masquerNom` l.64 |
| 07-5 | Expiration / révocation | **absente** | — | — | — | Aucune révocation | — | **BLOCKED** | FICHE-04 |

### Lot 08 — Stages / PFE

Toutes les exigences sont **`BLOCKED`** (FICHE-07). Aucun modèle, aucune table, aucune route.

### Lot 09 — Coach

Toutes les exigences sont **`BLOCKED`** (FICHE-08). Seul le **mode sans IA** est techniquement réalisable
sans décision externe, mais le périmètre (planning + quiz) dépend des contenus autorisés, non tranchés.

---

## 11. Plan d'exécution par lots

### Ordre retenu (dépendances réelles)

| Ordre | Lot | Dépend de | Blocage | Verdict d'audit |
|---|---|---|---|---|
| 1 | **03 — Notifications** (corrections + compléments) | — | Préférences (FICHE-05) | **Livré** — `PASS_WITH_RESERVATIONS` |
| 2 | **01 — Simulation étudiante** | moteur existant | Aucun (règles codées en dur, à documenter) | `PASS_WITH_RESERVATIONS` |
| 3 | **02 — Absences** (téléchargement + seuil) | — | FICHE-01, FICHE-02 | `PASS_WITH_RESERVATIONS` |
| 4 | **07 — Carte** (socle vérification) | Lot 05 | FICHE-04 | `BLOCKED` partiel |
| 5 | **05 — Coffre-fort** (versionnement) | — | FICHE-05, FICHE-06 | `PASS_WITH_RESERVATIONS` |
| 6 | **04 — Guichet** (vérifications) | — | — | `PASS_WITH_RESERVATIONS` |
| 7 | **06 — Devoirs** | Lot 06 supports | FICHE-03 | `BLOCKED` partiel |
| 8 | **08 — Stages/PFE** | — | FICHE-07 | `BLOCKED` |
| 9 | **09 — Coach** | — | FICHE-08 / `PORT-19` | `BLOCKED` |

### Justification de l'ordre

- **Lot 03 en premier** : les défauts D-04 et D-07 sont des bugs prouvés, corrigeables sans aucune
  décision métier, à faible risque, et ils débloquent les notifications de tous les lots suivants.
- **Lot 01 ensuite** : le moteur existe ; il s'agit d'ajouter une surface étudiante en lecture seule,
  donc sans risque d'écriture officielle. D-01 (fuite) doit être corrigé dans le même lot.
- **Lot 02** : D-02 (téléchargement) et D-03 (seuil mort) sont sûrs ; les règles de délai restent bloquées.
- **Lot 07** : ne peut démarrer qu'après stabilisation du coffre-fort (source des documents).

### Critères d'acceptation par lot

**Lot 03** — *exécuté, voir §15*
- [x] Un enseignant peut marquer une notification comme lue depuis l'UI (test Feature sur le chemin réel).
- [x] L'entrée de navigation « Notifications » est visible pour l'étudiant et mène à `etudiant.notifications.index`.
- [x] Un étudiant ne peut pas lire la notification d'un autre (404) — couvert par les tests existants.
- [x] Aucun changement de comportement pour admin/enseignant hors correction du bug.
- [ ] Filtre par catégorie — **réservé** (pas de colonne `categorie`).
- [ ] UI de préférences — **bloqué** par FICHE-05.
- [ ] Déduplication prouvée — **non couverte**.

**Lot 01** — *exécuté, voir §17 et §19 (routes réelles : `etudiant.parcours.simulations.*`)*
- [x] Une route étudiante `etudiant.simulations.*` charge uniquement l'inscription de la session.
- [x] Le service réutilisé est `SimulationParcoursService` — **aucun second moteur**.
- [x] Aucune ligne de `notes` / `semestres` / `inscriptions_academiques` n'est modifiée (test explicite).
- [x] Le résultat est libellé « simulation non officielle ».
- [x] Les notes non publiées ne sont pas exposées (D-01 corrigé).
- [x] Un accès à la simulation d'un autre étudiant renvoie 404.

**Lot 02**
- [ ] Le justificatif est téléchargeable par son propriétaire et par la scolarité autorisée.
- [ ] Un tiers obtient 404.
- [ ] `assiduite.seuil_alerte` est lu et produit une alerte — ou reste explicitement inactif avec test.
- [ ] Aucune sanction automatique n'est appliquée.

**Lot 07**
- [ ] La carte n'affiche que les données de FICHE-04 (une fois tranchée).
- [ ] Le QR ne contient qu'un code opaque.
- [ ] La page publique n'expose ni CIN ni date de naissance.
- [ ] Le statut provient de `inscriptions_academiques`, jamais d'un statut parallèle.

---

## 12. CodeScene / Code Health

Le serveur MCP `codescene` **n'est pas disponible** dans cet environnement (catalogue d'outils limité à
`opencode` et `rea`). Conformément à la règle du skill `codehealth-mcp` :

- **Aucun score Code Health n'est inventé.**
- Le contrôle est déclaré **skipped**.
- Le passage de qualité s'appuie sur `pint --test` + `php artisan test` + revue manuelle.

---

## 13. Risques et limites

| Risque | Impact | Mitigation |
|---|---|---|
| Session opencode concurrente sur le même arbre | Mesures de test non fiables, collisions d'écriture | Re-mesurer sur arbre calme ; snapshots datés |
| 435 entrées non commitées | Impossible de distinguer nos changements du WIP | Ne jamais committer en masse ; `git diff` ciblé par fichier |
| `parcours-academique` désactivé | La surface étudiante du Lot 01 pourrait être invisible en dev | Décider explicitement du flag avant implémentation |
| Antivirus `null` par défaut | Faux sentiment de sécurité | FICHE-06 + interdiction d'afficher « analysé » |
| Seuils 10/8 codés en dur | Non configurables institutionnellement | Documenter comme règle de fait ; ne pas la modifier sans décision |
| Baseline de tests instable (1 erreur aléatoire) | Faux positifs de régression | Relancer le test isolé avant de conclure |

---

## 14. Livrable annexe — visite guidée

Un CodeTour est fourni pour parcourir le terrain sans relire ce rapport :
`.tours/feature-explainer-portail-etudiant.tour` (11 étapes).

Il couvre : le registre des modules, la matrice RBAC, le moteur de calcul, le moteur de simulation
existant, le dépôt de justificatif sans téléchargement, le bug de routage des notifications, la fuite
de moyennes non publiées, le socle de vérification réutilisable pour la carte, et la machine à états
du guichet.

Le champ `ref` est volontairement **omis** : une partie des fichiers est encore non suivie par Git,
et CodeTour doit lire l'arbre de travail réel plutôt qu'une révision.

---

## 15. Journal d'exécution — Lot 03 (Phase 1)

**Périmètre :** deux défauts prouvés, corrigeables sans décision métier.
Aucune nouvelle règle métier, aucune migration, aucune dépendance ajoutée.

### 15.1 Fichiers modifiés

| Fichier | Nature | Justification |
|---|---|---|
| `resources/views/notifications/index.blade.php` | 2 lignes | Ajout de la branche `enseignant.*` dans l'action du formulaire (D-04) |
| `resources/views/layouts/_navigation.blade.php` | 1 ligne | Entrée « Notifications » dans l'espace étudiant (D-07) |
| `tests/Feature/CentreNotificationsPortailTest.php` | nouveau | 2 tests de non-régression |
| `docs/engineering/LOOP-UNIREGISTRE-PORTAIL-ETUDIANT-ADVANCED-01.md` | ce document | Traçabilité |

**Aucun autre fichier n'a été touché.** Les modifications préexistantes de
`_navigation.blade.php` et de `EnseignantPortailTest.php` (session concurrente) ont été préservées :
les retouches sont chirurgicales, sans réécriture de fichier.

### 15.2 Nature de la correction

**D-04 — routage du marquage « lu ».**

```blade
{{-- avant --}}
request()->routeIs('admin.*') ? route('admin.notifications.lire', $notification) : route('etudiant.notifications.lire', $notification)

{{-- après --}}
request()->routeIs('admin.*')
    ? route('admin.notifications.lire', $notification)
    : (request()->routeIs('enseignant.*')
        ? route('enseignant.notifications.lire', $notification)
        : route('etudiant.notifications.lire', $notification))
```

Le comportement admin et le comportement étudiant sont **inchangés**. Seul le portail enseignant,
auparavant en 404, atteint désormais sa propre route.

**D-07 — navigation étudiante.** Ajout d'une entrée dans le tableau `@foreach` existant, donc
soumise aux mêmes classes actives/inactives que les autres liens. Aucun style nouveau.

### 15.3 Tests

```bash
php artisan test --filter=CentreNotificationsPortailTest --compact
→ 2 tests, 2 réussis, 5 assertions, 1195 ms

php artisan test --filter="NotificationTest|EnseignantPortailTest|EspaceEnseignantModulesTest" --compact
→ 41 tests, 41 réussis, 188 assertions, 2365 ms

php artisan test --filter="AccessibilitePortailsTest|AccessibiliteParcoursTest|PortailsPerformanceAccessibiliteTest|EspaceEtudiantTest" --compact
→ 31 tests, 31 réussis, 237 assertions, 1643 ms

vendor/bin/pint --test tests/Feature/CentreNotificationsPortailTest.php app/Http/Controllers/Etudiant/TableauDeBordController.php
→ passed
```

**Total : 74 tests verts, 0 échec, 0 ignoré.**

Les deux nouveaux tests échoueraient sans la correction :
- le premier exige la présence de l'URL `enseignant.notifications.lire` **et** l'absence de l'URL
  `etudiant.notifications.lire` dans le HTML rendu ;
- le second exige la présence de l'URL `etudiant.notifications.index` sur le tableau de bord,
  page qui ne contient cette URL nulle part ailleurs (vérifié : `etudiant/dashboard.blade.php`
  ne référence aucune route de notification).

### 15.4 Audit de sécurité

| Contrôle | Résultat |
|---|---|
| Isolation inter-utilisateurs | Inchangée : `abort_if` sur la session reste en place dans les trois méthodes |
| Élévation de privilèges | Aucune : la branche ajoutée route vers une route déjà protégée par `enseignant.auth` |
| Fuite d'information | Aucune : la vue n'affiche que les notifications déjà filtrées par `teacher_id` |
| Données personnelles dans les logs | Aucun log ajouté |
| CSRF | `@csrf` conservé, `@method('PATCH')` inchangé |
| Lien de navigation | Un menu visible ne protège rien : la route reste contrôlée côté serveur |

### 15.5 Performance

Aucune requête ajoutée. La liste reste paginée à 15 éléments
(`paginate(15)->withQueryString()`). Le lien de navigation est statique.

### 15.6 Réserves

1. **Pas de colonne `categorie`** : le filtre reste par `type`. Ajouter une catégorie métier
   nécessiterait une migration et une nomenclature — non tranchée.
2. **Pas d'UI de préférences** : dépend de la politique de rétention (FICHE-05).
3. **Déduplication non vérifiée** : aucun test ne prouve l'absence de doublons sur double exécution.
4. **D-05 (PII dans l'historique)** reste à confirmer.

### 15.7 Verdict

**`PASS_WITH_RESERVATIONS`**

- Les deux défauts prouvés sont corrigés et couverts par des tests qui échoueraient sans le correctif.
- Aucune régression sur les 72 tests de proximité.
- Les compléments (catégorie, préférences, déduplication) restent ouverts et documentés.

---

## 16. Prochaine étape

1. **Lot 01** — livré : surface étudiante en lecture seule sur `SimulationParcoursService`, D-01 corrigé
   et confinement des simulations back-office (voir §17 et §19). Reste la décision d'activation du flag
   `parcours-academique`, non tranchée.
2. **Lot 02** — téléchargement du justificatif (D-02) et branchement du seuil d'alerte (D-03).
3. Puis les compléments du Lot 03 : catégorie, déduplication, préférences (après FICHE-05).
4. Les lots 06, 07, 08, 09 restent partiellement ou totalement `BLOCKED` jusqu'aux décisions §9.

> **Les Lots 03 et 01 sont déclarés livrés**, avec réserves explicites (§15 et §19). Tous les autres
> verdicts de ce document sont des verdicts **d'audit**, pas des verdicts d'implémentation.

---

## 17. Journal d'exécution — Lot 01 : simulation étudiante self-service (livré)

> Section ajoutée par la session de livraison du Lot 01 ; elle complète §15/§16 sans les réécrire.
> **Contribution croisée** : le squelette (routes/contrôleur/vue/tests) et le durcissement D-01
> (`etatSimulation`, garde de publication) proviennent de deux sessions concurrentes travaillant
> sur le même lot ; l'état combiné a été revalidé dans son intégralité.

### 17.1 Périmètre livré

| Fichier | Nature |
|---|---|
| `app/Modules/ParcoursAcademique/Routes/web.php` | Groupe `etudiant.auth` + `module:parcours-academique` : 4 routes `etudiant.parcours.simulations.{index,create,store,show}` |
| `app/Modules/ParcoursAcademique/Controllers/MaSimulationParcoursController.php` | Contrôleur fin, étudiant résolu **uniquement** depuis la session ; source = dernière inscription réelle ; `auteur_id` null |
| `app/Modules/ParcoursAcademique/Requests/MaSimulationParcoursRequest.php` | Aucun `etudiant_id`/`inscription_source_id` accepté du client ; note hypothétique 0-20 |
| `app/Modules/ParcoursAcademique/Models/ParcoursSimulation.php` | `LIBELLES` : source unique de libellés partagée back-office/portail |
| `resources/views/modules/parcours-academique/ma-simulations-{index,create,show}.blade.php` | Vues portail : avertissement non officiel, hypothèses, méthode/règles affichées, états d'indisponibilité explicites |
| `tests/Feature/MaSimulationParcoursTest.php` | 9 tests de non-régression |
| `app/Modules/Evaluation/Models/DecisionDeliberation.php` | Scope de publication (garde D-01, session concurrente) |

### 17.2 Critères d'acceptation du Lot 01 (§11) — vérifiés

- [x] Route étudiante chargée uniquement depuis la session (aucun identifiant client).
- [x] Moteur unique : `SimulationParcoursService` via `SimulationParcoursInterface` — aucun second moteur.
- [x] Zéro écriture officielle : test explicite `test_store_persiste_sans_aucune_ecriture_officielle` (notes, inscription et délibérations inchangées ; persistance append-only `auteur_id` null).
- [x] Résultat libellé « non officiel » (avertissement moteur + vues).
- [x] **D-01 corrigé** : inscription avec notes sans délibération publiée → état « résultats non publiés », simulation indisponible, aucune fuite.
- [x] Accès à la simulation d'un autre étudiant → 404 ; module désactivé → 404 ; invité → redirection.

### 17.3 Preuves exécutées (état combiné final)

| Contrôle | Résultat |
|---|---|
| `php artisan test tests/Feature/MaSimulationParcoursTest.php` | **9 tests / 34 assertions — PASS** |
| Run combiné (Lot 01 + Lot 03 + Parcours + FSEGS + isolation documents) | **76 tests / 240 assertions — PASS** |
| `vendor/bin/pint --test` (5 fichiers du lot) | **PASS** |
| `php artisan route:list` | 4 routes `etudiant/parcours/simulations*` enregistrées |
| `php -l` | PASS |

### 17.4 Verdict du Lot 01

**PASS_WITH_RESERVATIONS** — réserves :
1. le module `parcours-academique` reste **désactivé par défaut** : l'activation en production est un arbitrage à trancher (aucune activation globale sans autorisation) ;
2. les scénarios de rattrapage/compensation ne valent que ce que valent les règles configurées : sans règle, le socle LMD s'applique et est **signalé** dans le résultat (aucune invention de formule) ;
3. aucune moyenne/credit non publié n'est exposé (D-01), y compris via les crédits agrégés du moteur.

---

## 18. Journal complémentaire — thèmes V1/V2 de la fiche étudiant (livré)

> Section ajoutée après §17, sans réécrire les sections précédentes. Elle documente une demande
> complémentaire sur `/etudiant/profil` : conserver la V1 comme rendu par défaut et ajouter une V2
> moderne, professionnelle et explicitement marquée **bêta**. Le périmètre des neuf fonctionnalités
> n'est pas modifié ; aucune route, aucun contrôleur, aucune donnée et aucune autorisation ne change.

### 18.1 Périmètre livré

| Fichier | Nature |
|---|---|
| `resources/views/layouts/app.blade.php` | Thème serveur initial (`?theme=v2`), attributs `data-profil-theme` / `data-profil-page-theme`, amorçage anti-flash limité à la route profil |
| `resources/views/etudiant/profil.blade.php` | Sélecteur V1/V2 accessible, badge « Beta », `aria-current` rendu côté serveur puis synchronisé, synchronisation du thème sur `<body>` |
| `resources/css/app.css` | Thème V2 entièrement porté par `html[data-profil-theme="v2"]`, variantes sombres `html.dark[data-profil-theme="v2"]`, transition entre thèmes ; styles V1 conservés |
| `resources/js/profil.js` | Bascule sans rechargement, persistance `localStorage`, `history.replaceState`, View Transition avec repli, respect de `prefers-reduced-motion` |
| `tests/Feature/ProfilThemesTest.php` | 4 tests de non-régression du sélecteur, du repli serveur, du thème inconnu et de l'authentification |

Aucun nouveau endpoint, aucune migration, aucun nouveau service, aucune dépendance npm supplémentaire.

### 18.2 Comportement

- **V1 par défaut** : sans paramètre et sans préférence locale, le serveur rend la V1 ; le sélecteur
  marque V1 avec `aria-current="true"`.
- **V2 partageable sans JavaScript** : `GET /etudiant/profil?theme=v2` rend la V2 côté serveur. Le lien
  V1 (`?theme=v1`) revient au thème classique ; une valeur inconnue retombe sur la V1.
- **Préférence locale** : la clé `localStorage` `profil-theme` mémorise le dernier choix. Le script de
  tête l'applique **avant peinture** ; le `<body>` est synchronisé avant le contenu du profil pour que
  le fond V2 ne clignote pas.
- **Bascule sans rechargement** : un clic gauche (ou activation clavier) est intercepté ; l'URL est
  mise à jour avec `history.replaceState`. Si l'API View Transition est disponible et que le mouvement
  n'est pas réduit, la bascule est animée ; sinon elle est immédiate. Un échec de transition est
  rattrapé et ne bloque jamais le changement de thème.
- **Accessibilité** : le sélecteur est un groupe nommé « Thème graphique de la fiche », chaque option
  reste un lien réel, l'option active porte `aria-current`, et le badge « Beta » est visible. La V2
  n'est pas présentée comme un thème stable.
- **Mode sombre** : les surfaces V2 possèdent leurs variantes `html.dark[...]` ; le thème reste
  strictement confiné au profil et ne modifie pas les autres portails.

### 18.3 Sécurité et non-régression

| Contrôle | Résultat |
|---|---|
| Authentification | Inchangée : `?theme=v1`, `?theme=v2` et `?theme=inconnu` redirigent un invité vers la connexion |
| Autorisation objet | Inchangée : aucun identifiant étudiant n'est accepté depuis la requête |
| Données | Aucune écriture en base ; les données affichées restent celles de la session |
| Entrée | Seul le paramètre d'affichage `theme` est lu, avec liste blanche `v1|v2` |
| PII / journaux | Aucun journal ajouté ; aucune donnée personnelle supplémentaire |
| IA externe | Aucun transfert, aucun appel réseau ajouté |
| CSRF | Aucun formulaire ni verbe d'écriture ajouté |
| V1 | Structure, données et logique conservées ; seule la barre de sélection de thème est ajoutée |

### 18.4 Preuves exécutées

| Commande | Résultat |
|---|---|
| `php artisan test --compact --filter=ProfilThemesTest` | **4 tests / 4 réussis / 40 assertions — PASS** (895 ms) |
| `php artisan test --compact --filter="ProfilThemesTest|MotionEtudiantTest|EspaceEtudiantTest"` | **23 tests / 23 réussis / 125 assertions — PASS** (1 674 ms) |
| `vendor/bin/pint --test tests/Feature/ProfilThemesTest.php` | **PASS** |
| `php artisan view:cache` | **PASS** — tous les templates Blade compilés |
| `npm run build` | **PASS** — 17 modules, build Vite en 1,76 s ; `profil-DN46BgKP.js` produit |

Le build signale uniquement l'absence du paquet optionnel `fontaine` (optimisation des fallbacks de
polices) ; ce n'est pas une erreur et aucun asset n'est manquant.

### 18.5 Vérification visuelle

- Rendu contrôlé à **1440 px** et **500 px**, en V1 et V2, ainsi qu'en V2 sombre à 1440 px.
- Aucun débordement horizontal : diagnostic DOM V2 à 500 px = `clientWidth 500 / scrollWidth 500` ;
  les captures V1 et V2 à 500 px ne montrent aucun débordement.
- Le navigateur headless utilisé impose une largeur minimale de **500 px** ; les captures à 390 px
  étaient des recadrages d'une mise en page à 500 px, pas une preuve de débordement. La largeur 500 px
  est sous le breakpoint mobile de 640 px, donc les règles mobiles ont bien été exercées.
- Le sélecteur, le badge Beta, la couverture, les panneaux, le rail de situation et les formulaires
  restent lisibles et alignés aux deux largeurs.

### 18.6 Réserves

1. **Suite complète non re-mesurée et baseline contradictoire** : elle n'a pas été relancée dans
   cette livraison. Le snapshot §7.2 (3882 tests, 1 erreur `ArchiveAuthorizationTest`) reste daté.
   `docs/audit-referentiel/06-RAPPORT-FINAL.md` §5.4 rapporte de son côté une interruption fatale
   `StudentDossierPdfTest::test_admin_voit_boutons_selon_etat` (« Premature end of PHP process »),
   tandis que `docs/engineering/LOOP-UNIREGISTRE-DOCUMENTS-REUTILISABLES-01.md` §5.3 retient un run
   final à 3763 tests / 3723 réussis / 12 échecs / 3 erreurs / 25 ignorés / 18 111 assertions après
   passage à 1 G de mémoire. Ces mesures ne portent pas sur le même arbre ni le même instant :
   aucune ne vaut PASS global. Une re-mesure sur arbre calme reste requise.
2. **Repli sans JavaScript limité au paramètre d'URL** : la préférence `localStorage` nécessite
   JavaScript. Le repli `?theme=v2` reste fonctionnel sans script.
3. **V2 bêta** : le thème sombre couvre les surfaces du profil ; le shell global de l'espace étudiant
   conserve son thème existant.
4. **Aucun commit** : les fichiers restent dans l'arbre de travail, avec le WIP préexistant préservé.

### 18.7 Verdict

**`PASS_WITH_RESERVATIONS`** — la V1 reste le défaut, la V2 est un thème bêta commutable, partageable
et accessible, sans modification des données, routes ou autorisations. Les tests ciblés et la
non-régression de proximité passent ; la suite complète et l'arbitrage de commit restent ouverts.

---

## 19. Complément de clôture — Lot 01 (D-01 généralisé et état vérifié)

> Cette section complète §17. Elle consigne l'état **final vérifié** après la seconde passe :
> correction de D-01 sur toutes les surfaces étudiantes (pas seulement la simulation),
> confinement des simulations back-office et exécution de la suite de proximité.
> Les chiffres de §17 restent valables pour le squelette ; ceux ci-dessous font foi pour la clôture.

### 19.1 Décision sur le flag `parcours-academique`

| Élément | Constat |
|---|---|
| Définition | `config/modules.php` → `'active' => (bool) env('MODULE_PARCOURS_ACADEMIQUE', false)` |
| `.env` local | `MODULE_PARCOURS_ACADEMIQUE` **absent** → module désactivé |
| Contrôle | `EnsureModuleEnabled` lit uniquement `config("modules.{module}.active")` ; jamais une valeur cliente |
| Portée | **toutes** les routes du module (admin et étudiant) sont derrière `module:parcours-academique` |

**Décision retenue : aucune activation globale n'est faite.** Conséquences démontrées :

- l'activation exposerait les **menus et routes back-office** du module (aménagements, plans, règles,
  risque, simulations admin) ; l'accès resterait protégé par les permissions `parcours.*` (403 sans
  permission), donc **pas d'escalade de privilège**, mais c'est un **changement de périmètre admin** ;
- le portail étudiant resterait, lui, utilisable sans permission supplémentaire (session étudiante) ;
- l'activation est donc une **décision d'exploitation/institution** qui doit être explicite.

En l'état, la surface étudiante est livrée mais **inatteignable** tant que le module est désactivé :
c'est le comportement attendu du registre de modules, pas un défaut. Le test
`test_module_desactive_repond_404_sur_toutes_les_routes_etudiantes` le prouve sur les 4 routes.

### 19.2 D-01 — source de vérité et correction

**Règle unique :** un résultat n'est communicable à l'étudiant que si une ligne
`evaluation_decisions` avec `publie = true` existe pour l'inscription. Le **snapshot** de cette ligne
(`moyenne`, `credits_obtenus`, `credits_total`, `decision`) est la valeur affichée. Les colonnes
`inscriptions_academiques.moyenne_annuelle / credits_obtenus / credits_total / decision` sont
recalculées à chaque note et **ne valent pas publication**.

Implémentation : `DecisionDeliberation::scopePubliee()` + `inscriptionsPublieesPour(int $etudiantId)`
(une seule requête, réutilisée par le tableau de bord, les inscriptions et les archives).

| Surface | Avant | Après |
|---|---|---|
| Tableau de bord, « Dernier résultat » | colonnes de `inscriptions_academiques` | dernière décision **publiée** (snapshot) ; sinon « Résultats en attente de publication » |
| `etudiant.inscriptions.index` | moyenne, crédits, décision bruts | métadonnées visibles ; moyenne/crédits/décision masqués + « En attente de publication » |
| `etudiant.archives.show` (synthèse) | toutes les inscriptions | synthèse sur les seules inscriptions publiées |
| `etudiant.archives.show` (semestres/notes) | semestres et notes de toutes les inscriptions | relation vidée pour les non publiées ; notes limitées aux inscriptions publiées |
| Simulation étudiante | moteur lisant les notes brutes, disponible même sans publication | état `resultats_non_publies` → simulation refusée avec message explicite |

La publication est **binaire par inscription** : le cas « partiel » réel est une année publiée +
une année non publiée. Les deux fichiers de test couvrent ce cas (dashboard/inscriptions/archives
et simulation).

**Comportement back-office inchangé** : le moteur et les écrans admin continuent de lire les données
de travail (notes brutes). D-01 est une règle de **communication à l'étudiant**, pas une règle de
calcul ; aucune formule n'a été modifiée.

### 19.3 Surface étudiante de simulation

- 4 routes sous `etudiant.auth` + `module:parcours-academique` (voir §19.4) ;
- étudiant résolu **exclusivement** depuis `etudiant.auth` (attribut `etudiant` de la requête) ;
  aucun `etudiant_id` / `inscription_source_id` accepté du client (test dédié) ;
- source = dernière inscription réelle, résolue serveur ; `auteur_id = null` (self-service) ;
- `throttle:10,1` sur `POST` ;
- moteur unique injecté par `SimulationParcoursInterface` (`SimulationParcoursService`), 13 scénarios,
  aucun second moteur, aucune formule dupliquée dans le contrôleur ou les vues ;
- écritures limitées à `parcours_simulations` + `historique_academique` (audit) ; test explicite
  d'invariance des notes, inscriptions, semestres et décisions ;
- toutes les vues affichent l'avertissement « résultat non officiel, sans effet sur le dossier ».

**Confinement des simulations back-office (nouveau dans cette passe).** Une simulation créée en
administration peut s'appuyer sur des notes non publiées. Pour ne pas rouvrir D-01 par une autre
route :

- `index()` filtre `whereNull('auteur_id')` ;
- `show()` exige `etudiant_id` **et** `auteur_id === null`, sinon 404.

Le back-office n'est pas modifié : ses simulations restent visibles et inchangées. Test :
`test_simulation_creee_au_back_office_non_exposee_sur_le_portail`. Réserve produit en §19.7.

### 19.4 Fichiers et routes

| Fichier | Nature |
|---|---|
| `app/Modules/ParcoursAcademique/Controllers/MaSimulationParcoursController.php` | nouveau — contrôleur fin + `etatSimulation()` + confinement `auteur_id` |
| `app/Modules/ParcoursAcademique/Requests/MaSimulationParcoursRequest.php` | nouveau — validation stricte, aucun identifiant client |
| `app/Modules/ParcoursAcademique/Routes/web.php` | 4 routes étudiantes sous gate module |
| `resources/views/modules/parcours-academique/ma-simulation-create.blade.php` | état d'indisponibilité explicite |
| `resources/views/modules/parcours-academique/ma-simulations-index.blade.php` | historique self-service |
| `resources/views/modules/parcours-academique/ma-simulation-show.blade.php` | résultat + avertissement non officiel |
| `app/Modules/Evaluation/Models/DecisionDeliberation.php` | `scopePubliee()` + `inscriptionsPublieesPour()` |
| `app/Http/Controllers/Etudiant/ArchiveController.php` | D-01 : notes/inscriptions/archives/synthèse |
| `app/Http/Controllers/Etudiant/TableauDeBordController.php` | `resumeAcademiquePublie()` |
| `resources/views/etudiant/dashboard.blade.php` | garde publication |
| `resources/views/etudiant/inscriptions.blade.php` | garde publication |
| `resources/views/etudiant/archives.blade.php` | garde publication |
| `tests/Feature/PortailSimulationParcoursTest.php` | nouveau — 29 tests / 203 assertions |
| `tests/Feature/PortailEtudiantResultatsPubliesTest.php` | nouveau — 9 tests / 55 assertions |
| `tests/Feature/EspaceEtudiantTest.php` | fixture décision publiée (l'ancien test encodait le comportement vulnérable) |
| `tests/Feature/TableauDeBordTest.php` | fixture décision publiée + budget 22 requêtes respecté |

| Méthode | URI | Nom | Middleware |
|---|---|---|---|
| GET | `/etudiant/parcours/simulations` | `etudiant.parcours.simulations.index` | `etudiant.auth`, `module:parcours-academique` |
| GET | `/etudiant/parcours/simulations/creer` | `etudiant.parcours.simulations.create` | idem |
| POST | `/etudiant/parcours/simulations` | `etudiant.parcours.simulations.store` | idem + `throttle:10,1` |
| GET | `/etudiant/parcours/simulations/{simulation}` | `etudiant.parcours.simulations.show` | idem |

Aucune migration, aucun changement de schéma, aucune dépendance ajoutée.

### 19.5 Tests et preuves

| Contrôle | Résultat |
|---|---|
| `PortailSimulationParcoursTest` | **29 tests / 203 assertions — PASS** |
| `PortailEtudiantResultatsPubliesTest` | **9 tests / 55 assertions — PASS** |
| `MaSimulationParcoursTest` (session concurrente) | **9 tests / 34 assertions — PASS** |
| Suite de proximité (13 fichiers) | **166 tests / 737 assertions / ~7,2 s — PASS** |
| `vendor/bin/pint --test` (fichiers du lot) | **PASS** |
| `php artisan route:list --path=etudiant/parcours/simulations` | 4 routes enregistrées |

Mapping des exigences :

| Exigence | Test(s) |
|---|---|
| Invité → connexion | `test_invite_redirige_vers_la_connexion_etudiant` |
| Back-office interdit sur le portail | `test_compte_back_office_ne_peut_pas_utiliser_le_portail_etudiant` |
| Étudiant interdit sur l'admin | `test_etudiant_ne_peut_pas_acceder_aux_routes_admin_du_parcours` |
| Module ON/OFF | `test_module_desactive_repond_404_sur_toutes_les_routes_etudiantes` |
| Publié | `test_resultats_publies_ouvrent_la_simulation_et_affichent_l_avertissement` |
| Non publié | `test_resultats_non_publies_bloquent_la_simulation_sans_divulgation` |
| Partiel | `test_publication_partielle_par_inscription_bloque_aussi_la_simulation` + cas partiel D-01 |
| Absence de délibération | `test_absence_de_deliberation_sans_note_reste_simulable_sans_exposer_de_decision` |
| Sans inscription | `test_sans_inscription_la_simulation_est_indisponible_sans_substitution` |
| Historique | `test_historique_ne_liste_que_les_simulations_de_l_etudiant_connecte` |
| Inter-étudiants (show) | `test_show_dune_simulation_appartenant_a_un_autre_etudiant_repond_404` |
| Simulation back-office non exposée | `test_simulation_creee_au_back_office_non_exposee_sur_le_portail` |
| Identifiants client ignorés | `test_identifiants_envoyes_par_le_client_sont_ignores` |
| Validation scénario | `test_scenario_inconnu_est_rejete_sans_ecriture` |
| Validation note | `test_note_hypothetique_hors_bornes_est_rejetee` |
| 13 scénarios | `test_chaque_scenario_est_calcule_et_persiste_sans_effet_officiel` (DataProvider, 13 cas) |
| Aucune écriture officielle | `test_aucune_ecriture_officielle_apres_une_simulation` |
| D-01 tableau de bord | `test_dashboard_masque_un_resultat_non_publie`, `test_dashboard_affiche_le_snapshot_publie_et_non_les_colonnes_recalculees` |
| D-01 inscriptions | `test_inscriptions_masque_moyenne_credits_et_decision_non_publies`, `test_inscriptions_affiche_les_resultats_publies` |
| D-01 archives | `test_archives_masque_les_semestres_et_la_synthese_non_publies`, `test_archives_affiche_les_resultats_publies` |
| D-01 notes | `test_notes_ne_divulgue_aucune_note_dune_inscription_non_publiee` |

Les deux tests existants corrigés (`TableauDeBordTest`, `EspaceEtudiantTest`) échouaient parce qu'ils
**exigeaient** l'affichage de résultats sans décision publiée : ils encodaient D-01. Ils ont été
réalignés sur la règle de publication, sans affaiblir leurs autres assertions.

### 19.6 Audit de sécurité

| Contrôle | Résultat |
|---|---|
| Anti-IDOR | `show()` 404 pour un autre étudiant ; `index()` filtré par session |
| Simulation back-office | `auteur_id` non null → invisible (index) et 404 (show) |
| Élévation de privilèges | Aucune route admin accessible à un étudiant ; gate module inchangé |
| Validation serveur | scénario dans la liste des 13 ; note 0–20 ; identifiants client ignorés |
| CSRF | routes web sous le middleware standard ; `@csrf` dans les formulaires |
| Écritures officielles | aucune : seules `parcours_simulations` et `historique_academique` sont écrites |
| PII / logs | aucun `Log::`/`logger()` dans le contrôleur, la requête ou le service ; aucune note dans les messages |
| Second moteur | aucun : `SimulationParcoursInterface` uniquement |
| Limitation de débit | `throttle:10,1` sur la création |
| Fuite par une autre vue | audit : aucune autre vue `etudiant/` ne lit `moyenne_annuelle`, `credits_obtenus`, `decision` ou les notes |

### 19.7 Réserves

1. **Activation du flag** — le module reste désactivé par défaut. L'activation globale est une
   décision d'exploitation/institution à formaliser (périmètre admin inclus) ; aucune activation
   n'a été faite.
2. **Partage des simulations back-office** — le confinement actuel est volontairement conservateur :
   une simulation admin n'est pas « mes simulations ». Si le produit veut la partager, il faudra
   garantir qu'elle a été créée sur des résultats publiés (ou revalider la publication à l'affichage).
3. **Publication par inscription** — la granularité est l'inscription (année). Une publication
   par semestre serait un changement de modèle métier, non décidé.
4. **Consommateurs futurs** — le moteur continue de lire les notes brutes (usage back-office).
   Toute nouvelle surface étudiante doit passer par `etatSimulation()` ou la règle
   `inscriptionsPublieesPour()` ; un test de non-régression D-01 devra l'accompagner.
5. **Baseline globale non fiable** — une session concurrente édite le même arbre ; le verdict
   s'appuie sur la suite ciblée (166 tests), pas sur la suite complète.

### 19.8 Verdict

**`PASS_WITH_RESERVATIONS`**

- D-01 est corrigé sur les 4 surfaces étudiantes et couvert par 9 tests dédiés.
- La surface de simulation est livrée sans second moteur, sans écriture officielle, avec 29 tests.
- Les simulations back-office ne fuitent plus vers le portail.
- Les réserves (flag, partage back-office, granularité de publication, consommateur futur) sont
  documentées et n'invalident pas le périmètre livré.

---

## 20. Journal complémentaire — thème V2 global du portail étudiant (livré)

> Section ajoutée après §19, sans réécrire les sections précédentes. Elle étend le thème V2 du §18
> (limité à `/etudiant/profil`) à **toutes les pages du portail étudiant**. Le §18 reste l'historique
> de la phase « profil seul » ; pour les pages étudiantes, la V2 devient le rendu par défaut et la
> V1 reste disponible. Aucune route, aucun contrôleur, aucune donnée et aucune autorisation ne change.

### 20.1 Périmètre livré

| Fichier | Nature |
|---|---|
| `resources/views/layouts/app.blade.php` | Portée `$estPortailEtudiant`, thème serveur initial, amorçage anti-flash, attributs `data-etudiant-theme` / `data-etudiant-page-theme`, classes `etudiant-shell` / `etudiant-header` / `etudiant-footer`, sélecteurs compact dans la barre latérale desktop et le tiroir mobile |
| `resources/views/components/etudiant-theme-switch.blade.php` | Composant unique, variantes complète et compacte, options V1/V2 réelles, `aria-current` rendu côté serveur |
| `resources/js/etudiant-theme.js` | Contrôleur global : préférence `etudiant-theme`, migration de l'ancienne clé `profil-theme`, synchronisation d'URL, `history.replaceState`, View Transition avec repli, événement `etudiant:theme-change` |
| `resources/js/app.js` | Import du contrôleur global |
| `resources/css/app.css` | Sélecteurs généralisés, couche V2 du shell et du contenu, barre latérale pleine hauteur, pied de page translucide, variantes sombres, correctifs de contraste, styles V1 conservés |
| `resources/views/etudiant/profil.blade.php` | Utilise le composant partagé, V2 par défaut côté serveur, suppression des scripts de thème inline |
| `resources/js/profil.js` | Réduit au mouvement du profil, impulsion de couverture sur `etudiant:theme-change` et `ScrollTrigger.refresh()` |
| `tests/Feature/EtudiantThemesTest.php` | 8 tests dédiés : profil, V1 partageable, thème inconnu, pages du portail, suivi du dossier, écrans d'authentification, non-contournement de l'authentification, audit de 20 pages accessibles |

Aucun nouvel endpoint, aucune migration, aucun nouveau service, aucune dépendance npm supplémentaire.
Le WIP préexistant de l'arbre de travail est conservé.

### 20.2 Comportement

- **V2 par défaut sur le portail étudiant** : toute page `etudiant.*` rendue par le layout étudiant
  expose `data-etudiant-theme="v2"` et `data-etudiant-page-theme="v2"` sans paramètre.
- **V1 toujours disponible** : `?theme=v1` rend la V1 côté serveur et reste partageable ; une valeur
  inconnue retombe sur la V2. La préférence locale est mémorisée sous `etudiant-theme`, avec migration
  de l'ancienne clé `profil-theme`.
- **Périmètre strict** : `$estPortailEtudiant` couvre les pages étudiantes connectées et le suivi du
  dossier. Les écrans plein écran de connexion et de mot de passe en sont exclus, ainsi que les
  portails administrateur, enseignant et les pages publiques non étudiantes.
- **Un seul contrôleur** : le sélecteur complet du profil et le sélecteur compact de la barre latérale
  partagent le même contrôleur, la même clé de préférence et le même événement. Aucune duplication de
  logique de thème.
- **Barre latérale lisible** : sur le portail étudiant, la barre latérale desktop est collante et
  pleine hauteur ; le sélecteur compact et la session restent visibles même sur une page longue. Le
  tiroir mobile conserve son sélecteur en bas.
- **Mode sombre** : les surfaces V2 du shell, des cartes, des tableaux, des formulaires et du profil
  possèdent leurs variantes `html.dark[...]` ; le halo sombre historique est repassé en cyan pour
  rester cohérent avec la V2.
- **Mouvement** : la bascule utilise View Transition lorsqu'elle est disponible et que
  `prefers-reduced-motion` n'est pas demandé ; sinon elle est immédiate. Le profil conserve son
  animation de couverture et rafraîchit ScrollTrigger après le changement.

### 20.3 Audit des routes étudiantes

Audit structurel des **30 routes GET étudiantes sans paramètre** avec un étudiant de test authentifié :

| Résultat | Nombre | Détail |
|---|---:|---|
| Pages rendues `200` | 20 | Absences, appel QR, corrections, demandes (index et création), documents, dossier, pièces officielles, examens, historique, inscriptions, notes, notifications, parcours, profil (show et édition), recherche, nouveau rendez-vous, ressources, tableau de bord |
| Pages `404` de fixture | 9 | Annonces, archives, calendrier, emploi du temps, actualisations, finances, paiements, simulations de parcours (index et création) — le rendu d'erreur expose encore les attributs V2, `etudiant-shell` et le sélecteur |
| Endpoint `422` | 1 | `etudiant.rendez-vous.creneaux` exige des paramètres ; réponse de validation sans layout, ce n'est pas une page |

Toutes les réponses rendues avec le layout étudiant (200 et 404 de fixture) contenaient
`data-etudiant-theme="v2"`, `data-etudiant-page-theme="v2"`, `etudiant-shell` et
`data-etudiant-theme-switch`. La page de suivi du dossier (`etudiant.suivi`) est également en V2.
Les écrans de connexion et de mot de passe ne contiennent ni attribut de thème ni sélecteur.

### 20.4 Tests et build

| Commande | Résultat |
|---|---|
| `vendor/bin/pint --dirty` | **PASS** |
| `php artisan test --compact --filter="EtudiantThemesTest|EspaceEtudiantTest|MotionEtudiantTest|NavigationTest|SeparationProfilsTest"` | **48 tests / 48 réussis / 359 assertions — PASS** (2 336 ms) |
| `npm run build` | **PASS** — build Vite en 1,47 s ; assets `app`, `profil`, `etudiant`, `ScrollTrigger`, `lenis` produits |
| `php artisan view:cache` | **PASS** — tous les templates Blade compilés |

`EtudiantThemesTest` couvre le profil, la V1 partageable, le thème inconnu, les pages du portail, le
suivi du dossier, les écrans d'authentification et le non-contournement de l'authentification. Le test
d'audit parcourt 20 pages accessibles et vérifie sur chacune les attributs V2, le shell et le sélecteur.

### 20.5 Vérification visuelle

- Rendu contrôlé à **1440 px** et **500 px** : tableau de bord, notes, documents, demandes, absences,
  profil V2 et profil V1 ; mode sombre contrôlé sur tableau de bord, notes et profil V2.
- **Aucun débordement horizontal** : `scrollWidth == innerWidth` à 1440 et 500 px sur les pages
  contrôlées.
- **Barre latérale desktop** : hauteur 1100 px, sélecteur compact à `1011–1053 px` ; il reste visible
  sans faire défiler la page.
- **Tiroir mobile** : sélecteur compact à `892–934 px` dans un viewport de 950 px ; logo mobile
  `#f8fafc` sur le fond sombre.
- **Profil** : nom de couverture `#f8fafc` (le titre global V2 ne l'écrase plus), lettrage
  `-0.045em`, V1 intacte.
- **Pied de page** : fond translucide `rgba(255,255,255,0.74)` en clair, `rgba(11,18,32,0.74)` en
  sombre.
- **Contraste** : dégradé actif V2 `#0e7490 → #0369a1`, texte clair ; ratio minimal mesuré 5,03:1
  pour le texte du sélecteur. Les libellés secondaires de la barre latérale restent à 6,86:1.
- Le navigateur headless impose une largeur minimale de **500 px** ; les captures à 390 px étaient des
  recadrages d'une mise en page à 500 px. La largeur 500 px est sous le breakpoint mobile de 640 px,
  donc les règles mobiles ont bien été exercées.

### 20.6 Sécurité et non-régression

| Contrôle | Résultat |
|---|---|
| Authentification | Inchangée ; le thème ne contourne aucune session ni aucun middleware |
| Autorisation objet | Inchangée ; aucun identifiant étudiant n'est lu depuis la requête de thème |
| Données | Aucune écriture en base ; aucune donnée supplémentaire affichée |
| Entrée | Seuls `theme` (liste blanche `v1|v2`) et la préférence locale sont lus |
| PII / journaux | Aucun journal ajouté ; aucune donnée personnelle supplémentaire |
| CSRF | Aucun formulaire ni verbe d'écriture ajouté |
| Portée | Les portails admin, enseignant et les pages publiques non étudiantes ne reçoivent pas la couche V2 |
| V1 | Structure, données et logique conservées ; la V1 reste sélectionnable |
| Écrans d'authentification | Exclus du thème et du sélecteur, vérifié par test |

### 20.7 Réserves

1. **Suite complète non re-mesurée** : les rapports complets restent contradictoires (§18.6, §19.7 et
   `docs/audit-referentiel/06-RAPPORT-FINAL.md` §5.4). Aucun PASS global n'est revendiqué ; la preuve
   de cette livraison est la suite ciblée de 48 tests et les contrôles visuels.
2. **Routes 404 de fixture** : neuf routes sans données de démonstration répondent 404 ; leur page
   d'erreur est thémée, mais leur état métier avec données n'a pas été contrôlé visuellement.
3. **Endpoint de créneaux** : `rendez-vous.creneaux` répond 422 sans paramètres ; ce n'est pas une page
   et il n'a pas été inclus dans l'audit visuel.
4. **V2 bêta** : la V2 est le défaut du portail étudiant mais reste marquée bêta ; la V1 est le repli
   explicite.
5. **Aucun commit** : les fichiers restent dans l'arbre de travail, avec le WIP préexistant préservé.
   Aucun tag ni push n'a été effectué.

### 20.8 Verdict

**`PASS_WITH_RESERVATIONS`** — le thème V2 couvre toutes les pages étudiantes rendues, le sélecteur est
partagé et accessible, la V1 reste disponible, et les tests ciblés ainsi que les contrôles visuels
passent. La suite complète, les routes sans fixture et l'arbitrage de commit restent ouverts.

---

## 21. Journal d'exécution — Lot 02 : justificatifs d'absence (D-02) et seuil d'alerte (D-03)

> Date : 22/10/2026 · Branche `feature/admin-modifier-etudiant` · HEAD
> `38ef14933de9980a4c62cb805bd19bc86c2b3081`.
> Périmètre : D-02 livré (téléchargement sécurisé), D-03 audité **sans implémentation**.
> Aucun commit, push, reset, restore, clean, stash, migration destructive ni modification de données
> académiques.

### 21.1 Décisions de périmètre

| Sujet | Décision | Justification |
|---|---|---|
| D-02 — route étudiante | `GET /etudiant/absences/justificatifs/{justification}/piece` (`etudiant.absences.justificatif`) | Route dédiée, session étudiante existante, aucun chemin/disque/identifiant étudiant fourni par le client |
| D-02 — route scolarité | `GET /admin/justifications/{justification}/piece` (`admin.justifications.piece`) | Le rapport §10 prévoit « étudiant + scolarité » ; réutilisation de la permission de validation existante |
| D-02 — statut étudiant | **`approved` uniquement** | Aucune règle ne dit qu'un justificatif en attente/rejeté est consultable par l'étudiant ; comportement le plus restrictif compatible avec le workflow |
| D-02 — statut scolarité | Tous statuts nécessaires à la décision | `JustificationPolicy::validate` existante ; un validateur doit voir la pièce avant de statuer |
| D-02 — autorisation back-office | `attendance.justify` + `JustificationPolicy::validate` | Permission déjà utilisée par `JustificationController::valider` ; **pas** `attendance.view`, pour ne pas élargir l'accès aux pièces aux profils lecture seule |
| D-02 — disque/chemin | Disque `local` (`storage/app/private`), chemin `piece_chemin` lu en base | Aucun `Storage::url`, aucune URL publique, `serve => false` |
| D-02 — échecs | `404` pour toute anomalie côté étudiant ; `404` pour le refus policy côté scolarité | Ne pas confirmer l'existence d'un document tiers ; la permission manquante reste `403` (convention de la route `valider`) |
| D-02 — audit | `attendance.justificatif_telecharge`, entité `justification`, `par` + `statut` | Réutilisation d'`AuditService` ; ni chemin, ni nom de pièce, ni PII supplémentaire |
| D-02 — schéma | Aucune migration, aucune colonne, aucune permission nouvelle | Le besoin est couvert par l'existant |
| D-03 | Audit seul, **aucun** seuil codé/configuré/branché | Aucune règle institutionnelle confirmée : unité, périmètre, période, justificatifs, destinataires, conséquences et source officielle manquent |

### 21.2 État initial et baseline

| Contrôle | Commande | Résultat |
|---|---|---|
| Baseline ciblée Attendance | `php artisan test --compact` sur `AttendanceTest`, `AttendanceRisquesP1Test`, `PresenceIntelligenteTest`, `TeacherAttendanceIntelligentTest`, `TeacherAttendanceScheduleTest`, `AppelQrTest` | **84 tests, 83 réussis, 1 ignoré, 339 assertions** |
| Baseline élargie documents/sécurité/settings | `php artisan test --compact` sur `SettingsDefinitionsLoopCTest`, `StudentDossierTest`, `StudentDocumentTest`, `StudentDocumentIsolationTest`, `StudentDocumentSecurityTest`, `DocumentAcademiqueTest`, `Security/ArchivesIsolationLoop1Test`, `Security/ArchivesExportLoop6Test`, `Security/FaculteScopeEcritureTest` | **75 tests, 75 réussis, 422 assertions** |
| Baseline consolidée | Les deux commandes ci-dessus | **159 tests, 158 réussis, 1 ignoré, 761 assertions**, aucun échec préexistant |
| Git initial | `git status --short` sur les fichiers du lot | Six fichiers cibles **propres** avant modification ; branche et HEAD ci-dessus |

Les six fichiers cibles étaient propres : le lot n'a écrasé aucun travail concurrent. Le nouveau test
est le seul fichier non suivi créé par ce lot ; le rapport, déjà non suivi, a seulement reçu §21.

### 21.3 D-02 — routes et middlewares exacts

| Méthode | URI | Nom | Middlewares constatés (`route:list -v`) |
|---|---|---|---|
| `GET|HEAD` | `/etudiant/absences/justificatifs/{justification}/piece` | `etudiant.absences.justificatif` | `web`, `App\Http\Middleware\EnsureStudent`, `EnsureModuleEnabled:attendance`, `ThrottleRequests:20,1` |
| `GET|HEAD` | `/admin/justifications/{justification}/piece` | `admin.justifications.piece` | `web`, `Illuminate\Auth\Middleware\Authenticate`, `EnsureBackOffice`, `SecondaireVerrou`, `EnsureModuleEnabled:attendance`, `ThrottleRequests:20,1`, `Authorize:attendance.justify` |

La route étudiante est donc protégée par la session étudiante existante (`etudiant_auth_id`), le module
Attendance et le throttle. La route back-office cumule l'authentification back-office, le verrou
secondaire, le module, le throttle et la permission de validation. Aucune route n'accepte de paramètre
`disk`, `path`, `etudiant_id` ou `piece`.

### 21.4 D-02 — autorisation et réponses

| Situation | Route étudiante | Route scolarité |
|---|---|---|
| Visiteur non authentifié | Redirection `etudiant.connexion.show` | Redirection `admin.connexion.show` |
| Étudiant propriétaire, justification `approved`, pièce valide | **200**, `Content-Disposition: attachment` | Redirection connexion étudiante (pas de session back-office) |
| Étudiant propriétaire, justification `pending` ou `rejected` | **404** | — |
| Étudiant propriétaire, `piece_chemin` absent/vide/fichier absent | **404** | — |
| Étudiant tiers (IDOR), identifiant inconnu, URL forgée | **404** | — |
| `admin_scolarite` / `super_admin` (`attendance.justify`) | Redirection connexion étudiante | **200**, tous statuts, policy `validate` |
| `agent_scolarite` / `consultation` (`attendance.view` seulement) | Redirection connexion étudiante | **403** (permission `attendance.justify` absente ; testé pour `agent_scolarite`) |
| `admin_faculte` (pas de `attendance.justify` dans la matrice actuelle) | Redirection connexion étudiante | **403** ; le contrôle faculté de `validate` reste présent pour un rôle futur qui cumulerait les deux |
| Module Attendance désactivé | **404** | **404** |

Le contrôle d'appartenance est fait **côté serveur** : `$justification->etudiant_id` est comparé à
l'identifiant de la session `etudiant_auth_id`. Aucun identifiant étudiant n'est lu depuis l'URL ou la
requête. Le back-office réutilise `JustificationPolicy::validate` (`attendance.justify` +
`peutAccederFaculte`), déjà utilisée par la validation.

### 21.5 D-02 — chemin, disque et validation défensive

Le chemin n'est jamais fourni par le client. Il est écrit au dépôt par
`JustificationController::deposer` sous `justifications/etudiant_{id}/{uuid}.{ext}` sur le disque
`local`. Au téléchargement, `cheminPieceValide()` (ligne 163) applique ces rejets :

1. valeur non-string, vide ou composée uniquement d'espaces ;
2. octet NUL ou antislash ;
3. chemin absolu Unix (`/…`) ou Windows (`C:/…`) ;
4. segment vide, `.` ou `..` (double slash, slash final, traversée) ;
5. chemin hors du préfixe `justifications/etudiant_{id}/` (mauvais dossier, dossier d'un autre étudiant).

Le disque est fixé côté serveur : `Storage::disk('local')`. La configuration `config/filesystems.php`
montre que `local` et `private` partagent `storage_path('app/private')` avec `'serve' => false` : aucune
route framework `/storage/{path}` ne sert ces fichiers. Aucun `Storage::url()` n'est utilisé.

Le MIME est vérifié sur le contenu réel (`Storage::mimeType`, finfo) et limité à la liste blanche du
dépôt `DeposerJustificationRequest::MIME_PAR_EXTENSION` (`application/pdf`, `image/jpeg`, `image/png`).
Un fichier HTML/PHP renommé est refusé. La réponse force :

- `Content-Type` au MIME réel autorisé ;
- `Content-Disposition: attachment` (via `Storage::download`) ;
- `X-Content-Type-Options: nosniff` ;
- `Cache-Control: private, no-store, max-age=0`.

Le nom de téléchargement est assaini (basename, suppression des caractères de contrôle, guillemets et
antislashs, repli `justificatif`, extension du fichier stocké si absente, limite 255). Aucun chemin de
stockage n'est ajouté par l'application dans les en-têtes ; le corps est le document lui-même.

### 21.6 D-02 — audit et interface

- Audit de succès : `AuditService::tracer($etudiant, 'attendance.justificatif_telecharge', 'justification', $id, null, ['par' => 'etudiant'|'scolarite', 'statut' => …])`.
- Aucun chemin, nom de pièce, motif ou donnée médicale dans le journal.
- Aucun audit sur les refus (pas de bruit ni de fuite par journal).
- Vue étudiante : `Presence::derniereJustification()` (`hasOne(...)->latestOfMany()`), chargée en eager
  loading dans `PresenceController::mesAbsences` ; le lien n'apparaît que pour `approved` + `piece_chemin`.
- Vue scolarité : lien « Pièce » uniquement dans le bloc `@can('attendance.justify')` et si
  `piece_chemin` existe.
- Le serveur re-vérifie systématiquement au clic : le lien n'est jamais une autorisation.

### 21.7 D-02 — couverture de tests

Nouveau fichier `tests/Feature/JustificatifAbsenceTelechargementTest.php` : **23 tests, 83 assertions**.

| Famille | Tests représentatifs |
|---|---|
| Propriétaire + en-têtes + audit | `test_etudiant_proprietaire_telecharge_son_justificatif_approuve` |
| Session étudiante / back-office | `test_utilisateur_non_authentifie_redirige_vers_la_connexion_etudiante`, `test_compte_back_office_sans_session_etudiante_ne_peut_pas_utiliser_la_route_etudiante` |
| IDOR / URL forgée / inconnu | `test_etudiant_ne_peut_pas_telecharger_le_justificatif_d_un_autre_etudiant`, `test_identifiant_inexistant_repond_404` |
| Statuts | `test_justificatif_en_attente_ou_rejete_refuse_pour_etudiant` |
| Absence de justificatif | `test_absence_sans_justificatif_ne_produit_pas_de_lien` |
| Lien UI | `test_lien_etudiant_visible_uniquement_pour_justificatif_approuve_avec_piece` |
| Chemin / fichier | `test_chemin_absent_ou_fichier_manquant_refuse`, `test_chemins_absolus_malformes_et_traversee_refuses` |
| MIME | `test_mime_non_autorise_refuse` |
| Nom / en-têtes | `test_nom_de_telechargement_maitrise_et_sans_injection`, `test_aucun_chemin_de_stockage_n_est_expose_dans_la_reponse` |
| Non-mutation | `test_telechargement_ne_modifie_pas_les_statuts_ni_la_validation` |
| Audit sans PII | `test_audit_ne_contient_pas_le_chemin_ni_le_nom_de_piece` |
| Scolarité | `test_back_office_validateur_telecharge_piece_en_attente`, `test_back_office_sans_permission_de_validation_refuse`, `test_lien_piece_visible_pour_validateur_dans_file_scolarite` |
| Isolation des portails | `test_back_office_non_authentifie_redirige_vers_la_connexion_admin`, `test_etudiant_ne_peut_pas_utiliser_la_route_back_office` |
| Module désactivé | `test_routes_du_lot_sont_fermees_si_le_module_est_desactive` |
| Anti-N+1 | `test_liste_etudiante_charge_les_justifications_en_une_requete` (une seule requête `attendance_justifications`) |

Aucune assertion de sécurité n'a été affaiblie pour verdir un test. Les tests utilisent
`Storage::fake('local')` et `RefreshDatabase` ; aucun fichier réel n'est créé hors du disque simulé.

### 21.8 D-02 — résultats après implémentation

| Contrôle | Résultat |
|---|---|
| Nouveau fichier seul | **23 tests, 23 réussis, 83 assertions** |
| Suite ciblée consolidée (16 fichiers, baseline + nouveau) | **182 tests, 181 réussis, 1 ignoré, 844 assertions** |
| Delta vs baseline | **+23 tests, +23 réussis, +83 assertions**, aucun échec nouveau |
| `vendor/bin/pint` sur les 5 fichiers PHP du lot | **PASS** |
| `php -l` sur `JustificationController`, `Presence`, `PresenceController`, `Routes/web.php` | **PASS** |
| `php artisan route:list --name=...` | Les deux routes et leurs middlewares sont enregistrés |
| HEAD après travaux | `38ef14933de9980a4c62cb805bd19bc86c2b3081` (inchangé) |

Fichiers modifiés : `JustificationController.php`, `Presence.php`, `PresenceController.php`,
`Routes/web.php`, `mes-absences.blade.php`, `justifications.blade.php`. Fichier ajouté :
`tests/Feature/JustificatifAbsenceTelechargementTest.php`. Aucune migration, aucun changement de schéma,
aucune permission nouvelle.

### 21.9 D-03 — dossier de décision (seuil d'alerte assiduité)

#### 21.9.1 Constats factuels

| Élément | Constat | Preuve |
|---|---|---|
| Clé déclarée | `assiduite.seuil_alerte`, type `entier`, portée `global`, défaut `5`, règles `integer, min:0`, propriétaire `Scolarité`, sensibilité `interne` | `app/Services/SettingDefinition.php:40-44` |
| Valeur seedée | `5`, description « Absences injustifiées déclenchant une alerte scolarité » | `database/migrations/2026_09_30_000004_create_parametres_table.php:34` |
| Tests de la clé | La clé est couverte par le registre ; `-3` est refusé | `tests/Feature/SettingsDefinitionsLoopCTest.php:33,66` |
| Lecture applicative | **Aucune** lecture de `assiduite.seuil_alerte` dans `app/`, `routes/`, `resources/` ; seul le registre la déclare | `grep -rn "assiduite.seuil_alerte" app routes resources` → `SettingDefinition.php:40` uniquement |
| Méthode d'alerte | `alerterSiSeuilDepasse()` calcule puis notifie, mais **aucun appelant** | `grep -rn "alerterSiSeuilDepasse" app routes tests` → définition `AssiduiteService.php:110` uniquement |
| Type de notification | `absence.seuil` n'apparaît que dans cette méthode morte | `grep -rn "absence.seuil" app config resources database tests` → `AssiduiteService.php:125` uniquement |
| Scheduler / job / événement | Aucun job de seuil d'absence ; `schedule:list` ne montre que `presences:rapprocher-enseignants` (réconciliation enseignants) et d'autres tâches sans rapport | `php artisan schedule:list` ; aucun `alerterSiSeuilDepasse` dans `app/Console`, `routes/console.php`, `app/Jobs` |
| Règle institutionnelle | Aucune règle écrite sur le seuil, l'unité, la période, les justificatifs ou les conséquences | `docs/pedagogie/registre-arbitrages-lmd.md` ne contient qu'une mention générique de « justificatifs » (l. 63) ; aucune source officielle de seuil |
| Documentation technique | `settings-loop-b-contrats.md:38` affirme `assiduite.seuil_alerte` → `PresenceController` ; le code le contredit | `grep` ne trouve aucune lecture dans `PresenceController` ; `settings-loop-c-risques-revalidation-2026-10-13.md:23` (R-C-09) classe la clé en `ABSENCE_D_USAGE_NON_PROUVÉE` |

#### 21.9.2 Limites du calcul existant (même s'il était branché)

`AssiduiteService::statistiques()` (`:26-51`) présente deux écarts qui interdisent de le considérer
comme une base fiable sans arbitrage :

1. `$justifiees` compte **toutes** les justifications `approved` de l'étudiant, sans appliquer `$debut`
   ni `$fin`, alors que `$absents` est filtré par la période. Un seuil sur une période serait faux.
2. `$justifiees` inclut les justifications dont la présence est passée à `excuse` ; `$absents` exclut
   ces présences. Le retour `justifiees` vaut `min($justifiees, $absents) + $excuses`, tandis que
   `injustifiees` vaut `max(0, $absents - $justifiees)` : une justification d'une présence `excuse`
   peut donc annuler le décompte d'une autre absence restée injustifiée.

La méthode morte `alerterSiSeuilDepasse()` (`:110-135`) utilise en outre les statistiques **toutes
périodes**, sans déduplication, sans cooldown, avec un message contenant le nom complet de l'étudiant
(PII) et des destinataires `admin_scolarite`/`agent_scolarite` non validés par une règle. Elle ne peut
pas être branchée telle quelle.

#### 21.9.3 Questions à trancher avant toute implémentation

1. **Unité** : le seuil compte-t-il des absences, des séances, des heures, des demi-journées ou des
   crédits ?
2. **Périmètre** : par matière/module, par semestre, par année, par programme, par faculté ou global ?
3. **Période** : fenêtre glissante, cumul annuel, semestre fixe, remise à zéro ? Quelle date de référence ?
4. **Justificatifs** : seuls les `approved` retirent-ils une absence ? Que faire d'un `pending`, d'un
   `rejected`, d'un `excuse`, d'un retard ?
5. **Destinataires** : étudiant, scolarité, responsable de faculté, responsable pédagogique ? Quels rôles
   exactement, et dans quel périmètre faculté ?
6. **Conséquences** : information, convocation, sanction, inéligibilité aux examens ? Qui décide et
   comment tracer la décision humaine ?
7. **Source officielle** : quel texte, quelle version, quelle date, quel valideur ? Comment les changements
   de seuil sont-ils audités ?
8. **Fréquence** : une alerte par seuil franchi, à chaque absence, avec quel cooldown et quelle
   déduplication ?

#### 21.9.4 Décision retenue

**Aucun code, aucune configuration, aucun flag, aucune notification, aucun événement, aucun listener,
aucun job, aucun scheduler et aucun calcul métier n'a été ajouté pour D-03.** Le comportement actuel est
conservé : `assiduite.seuil_alerte` reste une clé déclarée et seedée, non lue ; aucune alerte n'est
envoyée ; aucune sanction automatique n'est appliquée. Le statut du point 02-7 est **`BLOCKED`** par
l'absence de règle institutionnelle, et non « corrigé par un seuil inventé ».

### 21.10 Réserves

1. **Lien UI et existence physique** : le lien s'appuie sur les métadonnées (`statut = approved` +
   `piece_chemin` non vide) pour éviter une interrogation du stockage par ligne. Si le fichier a été
   supprimé hors bande, le lien reste visible et le clic renvoie un **404 contrôlé**. Aucun lien ne
   contourne l'autorisation serveur.
2. **Périmètre faculté back-office** : aucun rôle courant ne cumule `attendance.justify` et une
   restriction `admin_faculte`. La policy `validate` contient bien `peutAccederFaculte`, mais ce bras
   n'est pas exerçable par la matrice RBAC actuelle. Le refus de permission est testé pour
   `agent_scolarite` (`403`) ; le bras faculté lui-même n'est couvert que par lecture de code.
3. **Suite complète** : non re-mesurée en raison d'une session concurrente sur le même arbre. La preuve
   est la suite ciblée de **182 tests / 181 réussis / 1 ignoré / 844 assertions**, pas un PASS global.
4. **D-03 bloqué** : l'audit est livré, mais aucune règle institutionnelle ne permet de coder le seuil.
   Le point reste `BLOCKED` ; toute implémentation future devra d'abord trancher §21.9.3.
5. **SEC-12 P3 préexistant** : le dépôt stocke la pièce avant les gardes métier et la racine
   `justifications/` n'est pas couverte par le purgeur d'orphelins. D-02 ne crée pas de nouvelle
   exposition (seules les justifications référencées sont servies) mais ne corrige pas ce point.
6. **Périmètre de lecture back-office** : `agent_scolarite` et `consultation` ne peuvent pas télécharger
   la pièce (pas de `attendance.justify`). C'est un choix restrictif cohérent avec le masquage du motif
   dans la file ; si l'institution veut leur ouvrir la pièce, une décision explicite est nécessaire.

### 21.11 Verdict

**`PASS_WITH_RESERVATIONS`** pour le lot 02 :

- **D-02 : `PASS`** — routes étudiantes et scolarité livrées, autorisation serveur, propriété, statut,
  chemin, MIME, en-têtes, nom, audit, UI et anti-N+1 couverts par 23 tests ; suite ciblée verte ; aucun
  élargissement de permission ; aucune migration.
- **D-03 : `BLOCKED`** — audit factuel livré, aucune règle institutionnelle confirmée, aucun seuil codé,
  comportement actuel conservé.
- **Aucun commit, push ou opération destructive** n'a été effectué ; les modifications restent dans
  l'arbre de travail, avec le WIP concurrent préservé.

---

## 22. Journal d'exécution — Harmonisation file de traitement / registre étudiants

### 22.1 Périmètre et état initial

- Branche : `feature/admin-modifier-etudiant` ; HEAD : `38ef14933de9980a4c62cb805bd19bc86c2b3081`.
- Arbre de travail : **470 entrées WIP préexistantes/concurrentes** ; aucun commit, push, migration ou
  opération destructive n'a été effectué.
- Écrans audités : `/admin/inscriptions/file-traitement` et `/admin/etudiants`.
- Baseline ciblée avant modification : **93 tests / 93 réussis / 333 assertions** sur
  `EtudiantsRegistreTest`, `FileTraitementPaginationTest`, `InscriptionFileTraitementTest`,
  `FileTraitementUxTest`, `FileTraitementSecuriteTest`, `FileTraitementCategoriesTest`,
  `FileTraitementCoherenceTest`, `SavedViewTest`, `ExportListesTest`, `AsyncExportTest`.
- Le diff final des fichiers ci-dessous contient aussi des modifications WIP antérieures/concurrentes ;
  seules les corrections listées en §22.3 sont imputables à ce lot.

### 22.2 Inventaire avant / après

| Point | Avant | Après | Preuve |
|---|---|---|---|
| Filtre `depose_depuis` | Validé sur les deux écrans mais **perdu** par `ListesController::filtresValides` → exports et aperçus non filtrés | Validé et transmis au moteur unique pour PDF/XLSX/CSV/impression/exports différés | `ListesController` + test CSV/PDF/XLSX/impression/job |
| Réinitialisation du registre | Visible seulement pour `search`, `faculte_id`, `statut`, `niveau` ; réappliquait la vue par défaut | Visible pour tous les filtres acceptés (hors faculté forcée et valeurs par défaut) ; `?reset=1` neutralise la vue par défaut | `$filtresActifs`, lien `reset=1`, tests |
| Compteur de filtres de la file | Omettait `categorie` | Inclut `categorie`, `action_attendue`, `statut`, `search`, `programme_id`, `niveau`, `depose_depuis`, `faculte_id` (si non forcée) ; exclut `tri`/`sens` | `FileTraitementController::compterFiltresActifs` + test par catégorie |
| Suppression étudiant | Bouton desktop et mobile affichés sans `archives.delete` | Les deux boutons sont sous `@can('delete', $etudiant)` ; le serveur refuse toujours | Test marqueur ×2 + 403 consultation/inter-faculté |
| Exports | Boutons PDF/Excel/CSV/Imprimer affichés sans `data.export` (403 au clic) | Composant `x-export-actions` entièrement masqué sans `data.export` | `export-actions.blade.php` + test registre/aperçu |
| Note de la file | Bouton visible sans `archives.update` (403 au clic) | Bouton sous `@can('archives.update')` ; route inchangée | Test consultation 403 |
| Recherche du registre | Libellé « Recherche instantanée » et placeholder annonçant « ville », non recherchée | Libellé « Recherche » et placeholder aligné sur les colonnes réellement interrogées | Test HTML |
| Indicateurs | Aucune mention du périmètre ; ambiguïté possible avec les filtres | Mention explicite : registre = périmètre non filtré ; file = périmètre de faculté, autres filtres sans effet | Tests HTML + KPI |
| Faculté sur compte central | `faculte_id` ignoré pour un `admin_scolarite` non super-admin dans le moteur partagé | Filtre appliqué à tous les profils ; valeur forcée conservée pour les comptes restreints | Test registre + CSV |
| Vue sauvegardée par défaut | Liste blanche partielle ; valeurs non revérifiées ; faculté forcée avant fusion | Chaque clé est revalidée isolément ; périmètre faculté réappliqué après fusion ; seuls les filtres validés sont sérialisés | Test vue par défaut et vue invalide |

### 22.3 Défauts confirmés et corrections

1. **D-H1 — `depose_depuis` absent du pipeline d'exports.**
   `ListesController::filtresValides` ne validait pas la clé : `$request->validate()` la supprimait,
   donc `preview`, `exporter`, `imprimer` et le job différé recevaient une population non filtrée.
   Correction : ajout de `'depose_depuis' => ['nullable', 'integer', 'min:1', 'max:3650']`, même borne
   que le registre et la file. Le moteur `ListeEtudiantsService::requete` reste la seule définition SQL.

2. **D-H2 — réinitialisation incomplète et inefficace avec une vue par défaut.**
   La condition ne couvrait que quatre clés et le lien vide réappliquait la vue sauvegardée par défaut.
   Correction : `$filtresActifs` calculé côté contrôleur à partir des filtres validés (faculté forcée
   exclue), bouton visible dès qu'un filtre métier ou de tri non par défaut est actif, lien
   `?reset=1` qui empêche la réapplication de la vue par défaut, marqueur exclu des vues enregistrées.

3. **D-H3 — compteur de filtres de la file incomplet.**
   `categorie` était acceptée et appliquée en SQL mais absente du compteur. Correction : ajout de la
   clé ; le compteur reste indépendant de la pagination et de la présentation (`tri`/`sens`).

4. **D-H4 — actions de suppression non autorisées visibles.**
   Les boutons desktop et mobile appelaient `destroy` sans `@can('delete')`. Correction : les deux
   points d'entrée sont gatés ; la policy serveur (`archives.delete` + périmètre faculté) reste seule
   juge.

5. **D-H5 — exports non autorisés visibles.**
   Les liens d'export étaient rendus pour des rôles sans `data.export`. Correction : le composant
   partagé `x-export-actions` est enveloppé dans `@can('data.export')`, ce qui couvre le registre et
   l'aperçu sans modifier les routes ni les contrôles serveur.

6. **D-H6 — note d'instruction non autorisée visible.**
   Le bouton de la file était rendu pour `consultation`, alors que la route exige `archives.update`.
   Correction : `@can('archives.update')` autour du bouton.

7. **D-H7 — libellés et placeholder trompeurs.**
   « Recherche instantanée » pour un formulaire GET non instantané, et « ville » alors que le moteur
   ne recherche pas ce champ. Correction : libellé « Recherche » et placeholder
   « Nom, prénom, CIN, N° inscription, email, téléphone... » ; placeholder de la file aligné de même.

8. **D-H8 — filtre faculté sans effet pour un compte central.**
   `ListeEtudiantsService` n'appliquait `faculte_id` que pour un compte restreint ou `super_admin`.
   Un `admin_scolarite` sélectionnait donc une faculté sans que la liste change. Correction :
   application pour tous les profils ; les comptes restreints restent forcés côté contrôleur, donc
   aucun élargissement d'accès.

9. **D-H9 — vue sauvegardée par défaut partiellement appliquée.**
   La liste blanche omettait `annee_universitaire_id`, `sous_groupe_id`, `sans_groupe` et
   `depose_depuis`, et les valeurs sauvegardées n'étaient pas revérifiées. Correction : validation
   isolée de chaque clé contre les règles du registre, fusion des seules clés valides, réapplication
   du périmètre faculté après fusion, sérialisation des filtres validés (le marqueur `reset` n'est
   jamais enregistré).

10. **D-H10 — ambiguïté des indicateurs.**
    Les cartes sont des totaux de périmètre, pas des résultats filtrés. Correction : mention explicite
    sur les deux écrans ; pour la file, la mention précise que seule la faculté délimite le périmètre
    (les autres filtres ne modifient pas les KPI). Aucun calcul n'a été changé.

### 22.4 Tests et résultats exacts

Nouveau fichier : `tests/Feature/HarmonisationFileRegistreTest.php` — **14 tests / 14 réussis /
161 assertions**. Il couvre :

- `depose_depuis` identique en liste, aperçu, CSV, PDF, XLSX et impression ;
- bornes strictes `1..3650` et rejet de `0`, `-1`, `3651`, `abc` sur registre/aperçu/export ;
- combinaison `depose_depuis` + faculté + statut + recherche ;
- filtre faculté effectif pour un compte central (liste + CSV) ;
- export différé (`GeneratePopulationExport`) conservant `depose_depuis` ;
- visibilité de la réinitialisation pour chaque filtre accepté, y compris `tri`/`sens`, et absence
  pour les valeurs par défaut ;
- réinitialisation traversant une vue par défaut, sans la réappliquer ;
- vue par défaut invalide ignorée et périmètre faculté préservé ;
- compteur de la file pour **chaque** catégorie, combinaison, et exclusion de `tri`/`sens` ;
- KPI de la file dépendants de la faculté mais pas des autres filtres ;
- boutons Supprimer desktop **et** mobile masqués sans permission, refus serveur 403 ;
- exports masqués sans `data.export` sur registre et aperçu, refus serveur 403 ;
- note de file masquée sans `archives.update`, refus serveur 403 ;
- suppression inter-faculté refusée ;
- placeholder/libellés et mentions de périmètre.

Suites ciblées finales (21 fichiers) :

| Commande | Résultat |
|---|---|
| `php artisan test --compact` sur les 20 fichiers des deux écrans + exports + vues sauvegardées + PDF + navigation | **145 tests / 145 réussis / 751 assertions — PASS** (5 684 ms) |
| `php artisan test --compact tests/Feature/HarmonisationFileRegistreTest.php` | **14 tests / 14 réussis / 161 assertions — PASS** |
| `vendor/bin/pint` sur les 5 fichiers PHP modifiés | **PASS** |
| `php -l` sur les 5 fichiers PHP modifiés | **PASS** |

Aucun test ne dépend de l'heure réelle : les scénarios d'ancienneté utilisent `travelTo()` avec une
horloge figée, puis `travelBack()`.

### 22.5 Audit de sécurité

- **Cloisonnement faculté** : les comptes restreints sont forcés côté contrôleur (registre, file,
  exports) et le moteur réapplique la valeur ; la vue par défaut ne peut pas élargir ce périmètre.
  Test dédié avec une vue par défaut pointant vers une autre faculté.
- **Autorisations** : les actions masquées restent refusées côté serveur (403 consultation) ; les
  policies `view`, `update`, `delete` et les middlewares de routes n'ont pas été affaiblis.
- **Exports** : `data.export` reste exigé par `ListesController::exporter` ; `exports.personal`
  continue de masquer CIN/e-mail/téléphone dans les exports ; les tests `ExportListesTest` et
  `SensitiveExportTest` passent.
- **Vues sauvegardées** : validation par clé, aucune valeur arbitraire injectée dans la requête ;
  le paramètre `reset` n'est ni validé comme filtre ni enregistré.
- **Lot** : limite `max:200`, déduplication `array_unique`, transaction unique, `Gate::authorize`
  par étudiant, audit individuel — audité, non modifié.
- **Suppression** : transaction fichiers + lignes, trace technique sans PII — audité, non modifié.
- **Exports différés** : filtres validés sérialisés, requête reconstruite par le même service,
  contrôle de propriété au téléchargement — testé.
- **Réserve sécurité** : `admin.listes.imprimer` n'exige pas `data.export` côté serveur (comportement
  préexistant). Le lien est désormais masqué sans permission, mais l'URL directe reste accessible à
  un lecteur authentifié. Ce point relève d'une décision de périmètre de permission et n'a pas été
  modifié. **→ Mise à jour : corrigé, testé et audit élargi aux routes associées en §24.**

### 22.6 Performance

- Aucune requête supplémentaire : `$filtresActifs` est calculé en PHP ; la validation d'une vue par
  défaut ne s'exécute que sur URL vide et reste bornée à 12 clés.
- Le moteur partagé conserve une seule requête de population ; les eager-loadings existants sont
  inchangés. Les tests de volumétrie (`[INDEX-ETUDIANTS]` 12 requêtes, `[VOLUMETRIE-FILE]` 25 requêtes
  pour 300 dossiers) passent.
- Les exports PDF/XLSX/CSV/impression et le job différé utilisent le même `ListeEtudiantsService`,
  sans nouvelle abstraction ni requête par ligne.

### 22.7 Réserves

1. **Aucun PASS global** : l'arbre contient 470 entrées WIP et une session concurrente ; seule la
   suite ciblée de 145 tests est revendiquée. La suite globale n'a pas été exécutée.
2. **Pas de test visuel navigateur** : les deux variantes desktop/mobile sont couvertes
   structurellement (deux boutons de suppression, formulaires responsives), mais aucun test
   Playwright/navigateur n'a été exécuté.
3. **`listes.imprimer`** : absence de contrôle serveur `data.export` (préexistant), lien masqué mais
   URL directe accessible — décision produit/sécurité à prendre. **→ Mise à jour : traité en §24.**
4. **D-03** (seuil d'alerte assiduité) reste `BLOCKED`, sans règle institutionnelle confirmée ; ce lot
   ne le modifie pas.
5. **KPI de la file** : la faculté sélectionnée délimite le périmètre des indicateurs ; c'est le
   comportement existant, désormais explicité. Si l'institution veut des KPI globaux indépendants de
   la faculté, une décision produit est nécessaire.
6. **Vues sauvegardées anciennes** : une vue dont les valeurs seraient devenues invalides est ignorée
   comme vue par défaut ; appliquée via son lien, elle peut produire une erreur de validation
   normale. Aucune migration de données n'a été faite.
7. **Marqueur `reset=1`** : solution volontairement minimale pour distinguer « aucun filtre » de
   « réinitialisation explicite » ; à retester si la sémantique des vues par défaut évolue.

### 22.8 État Git

- Aucun commit, push, merge, migration ou suppression de données.
- Fichiers modifiés par ce lot :
  - `app/Http/Controllers/Admin/EtudiantController.php`
  - `app/Http/Controllers/Admin/ListesController.php`
  - `app/Http/Controllers/Admin/FileTraitementController.php`
  - `app/Services/Exports/ListeEtudiantsService.php`
  - `resources/views/admin/etudiants/index.blade.php`
  - `resources/views/admin/inscriptions/file-traitement.blade.php`
  - `resources/views/components/export-actions.blade.php`
- Nouveau test non suivi : `tests/Feature/HarmonisationFileRegistreTest.php`.
- Les fichiers non suivis antérieurs (`lang/fr/pagination.php`, `lang/fr.json`,
  `tests/Feature/PaginationTraductionTest.php`) restent en place.
- L'artefact généré par un test existant (`public/preview-etudiant.html`) a été supprimé après la
  suite ; aucun autre artefact n'a été ajouté.

### 22.9 Verdict

**`PASS_WITH_RESERVATIONS`**

- Les défauts fonctionnels confirmés dans le périmètre (filtre d'ancienneté, réinitialisation,
  compteur, permissions visuelles, libellés, périmètre faculté, vues sauvegardées) sont corrigés et
  couverts par 14 nouveaux tests.
- Suite ciblée : **145/145 tests, 751 assertions** ; Pint et `php -l` : **PASS**.
- Réserves : pas de suite globale ni de test visuel navigateur, contrôle serveur de `listes.imprimer`
  non modifié (préexistant), D-03 indépendant toujours bloqué, KPI de la file dépendants de la
  faculté par conception existante.

---

## 23. Journal complémentaire — finition V2 des neuf routes étudiantes secondaires (livré)

> Section ajoutée après §22, sans réécrire les sections précédentes. Elle clôt la réserve §20.7.2 :
> les neuf routes étudiantes qui répondaient encore `404` faute de fixtures sont désormais couvertes
> par des données réelles, des tests permanents et une vérification visuelle. Aucune route, aucun
> contrôleur, aucune donnée, aucune autorisation et aucun journal ne change.

### 23.1 Périmètre livré

| Route | Nature | Résultat |
|---|---|---|
| `/etudiant/annonces` | Page HTML | Cartes d'annonces V2, données publiées |
| `/etudiant/archives` | Page HTML | Feuille institutionnelle V2, dossier réel |
| `/etudiant/calendrier` | Page HTML | Agenda V2, événement de la période |
| `/etudiant/emploi-du-temps` | Page HTML | Grille de planning V2, créneau publié |
| `/etudiant/emploi-du-temps/actualisations` | Endpoint JSON | Contrat inchangé, aucun markup de thème |
| `/etudiant/finances` | Page HTML | Indicateurs et facture réelle en V2 |
| `/etudiant/paiements` | Page HTML | Dossier annuel et statuts en V2 |
| `/etudiant/parcours/simulations` | Page HTML | Historique self-service et action V2 |
| `/etudiant/parcours/simulations/creer` | Page HTML | Formulaire et boutons V2 |

Fichiers modifiés :

| Fichier | Nature |
|---|---|
| `resources/views/modules/announcements/mes-annonces.blade.php` | Classes de page et de carte, aucun contenu changé |
| `resources/views/modules/calendar/agenda.blade.php` | Classes de page, d'élément et de date |
| `resources/views/etudiant/archives.blade.php` | Classes de feuille, en-tête, emblème, identité, tampon, sections et documents |
| `resources/views/modules/schedule/mon-planning.blade.php` | Classes de navigation, grille, jour, jour courant et créneau |
| `resources/views/modules/schedule/creneau.blade.php` | Modificateurs `normal` / `exception` / `annulé` |
| `resources/views/modules/finances/mes-finances.blade.php` | Classes de page, avis, KPI, facture et lignes |
| `resources/views/modules/finances/paiements-inscriptions/etudiant-index.blade.php` | Classes de page, métriques, carte et statuts |
| `resources/views/modules/parcours-academique/ma-simulations-index.blade.php` | Classes de page, ligne et résultat |
| `resources/views/modules/parcours-academique/ma-simulation-create.blade.php` | Classes de formulaire et boutons |
| `resources/css/app.css` | Jetons sémantiques, bloc V2 page par page, boutons V2 scopés |
| `tests/Feature/EtudiantPagesV2Test.php` | 11 tests permanents, fixtures réelles |

Aucun nouvel endpoint, aucune migration, aucun changement de contrôleur, aucune dépendance npm.
Les classes ajoutées sont inertes : elles ne modifient ni le texte, ni les données, ni la structure
fonctionnelle des vues.

### 23.2 Comportement et styles

- **Portée stricte** : tous les nouveaux sélecteurs sont sous
  `html[data-etudiant-theme="v2"] .etudiant-shell`. La V1 conserve donc sa feuille et son rendu ;
  seules les classes de page, sans effet hors V2, sont ajoutées au markup.
- **Annonces** : cartes V2, bordure et surface adaptées au mode sombre, titres et métadonnées
  lisibles.
- **Archives** : la feuille institutionnelle est convertie en surfaces V2 (en-tête, emblème,
  identité, résumé, statistiques, sections, documents) ; le tampon de statut n'est plus incliné et
  utilise les couleurs sémantiques succès/neutre.
- **Agenda** : dates, pastille d'événement et carte d'événement reçoivent les surfaces, bordures et
  couleurs V2.
- **Planning** : navigation de semaine, grille, en-tête de jour, jour courant et créneaux
  (`normal`, `exception`, `annulé`) sont distingués par les jetons V2.
- **Finances** : avis, KPI, carte de facture et lignes utilisent les surfaces et séparateurs V2.
- **Paiements** : métriques, carte de paiement et statuts (`validé`, `en attente`, `refusé`) sont
  mappés sur les jetons succès/attention/erreur.
- **Simulations** : lignes, résultat et formulaire sont V2. Les classes `btn-primary` et
  `btn-secondary` étaient utilisées sans définition CSS ; elles reçoivent désormais un style V2
  scopé au shell étudiant. La V1 n'est pas modifiée et conserve son rendu antérieur.
- **Actualisations** : l'endpoint reste un JSON pur, sans attribut de thème ni markup de page.

### 23.3 Tests permanents

Nouveau fichier `tests/Feature/EtudiantPagesV2Test.php` : il active explicitement les modules
`announcements`, `calendar`, `finances`, `parcours-academique`, `schedule` et
`inscriptions-paiements`, construit des fixtures réelles (faculté, année, groupe, étudiant, dossier,
inscription, annonce publiée, événement publié, matière, planning publié, créneau, facture, ligne,
simulation) puis vérifie chaque page.

| Commande | Résultat |
|---|---|
| `vendor/bin/pint tests/Feature/EtudiantPagesV2Test.php` | **PASS** |
| `php artisan test --filter=EtudiantPagesV2Test` | **11 tests / 11 réussis / 86 assertions — PASS** |
| `php artisan test --filter="EtudiantThemesTest\|EspaceEtudiantTest\|MotionEtudiantTest\|NavigationTest\|SeparationProfilsTest\|EtudiantPagesV2Test"` | **59 tests / 59 réussis / 445 assertions — PASS** |
| `npm run build` | **PASS** — build Vite en 1,43 s ; CSS `app-_QAIcZov.css` (287,77 kB) |
| `php artisan view:cache` | **PASS** — tous les templates Blade compilés |

Couverture du fichier : les huit pages HTML, le contrat JSON et son delta `depuis`, la redirection
d'un invité vers la connexion, et le repli V1 avec ses attributs
`data-etudiant-theme="v1"` / `data-etudiant-page-theme="v1"`.

### 23.4 Vérification visuelle

Méthode : les réponses réelles des huit pages ont été rendues en HTML statique, avec le CSS buildé
inline car `Tests\TestCase` applique `withoutVite()`, puis capturées via le protocole DevTools de
Chrome. La largeur a été imposée par `Emulation.setDeviceMetricsOverride` (1440 px et 500 px
exacts), en clair et en sombre, avec `prefers-color-scheme` émulé. Le test de capture temporaire et
`public/__theme-snapshots/` ont été supprimés après usage ; aucun artefact de capture n'est ajouté au
dépôt.

| Contrôle | Résultat |
|---|---|
| Captures | **32/32** — 8 pages × 2 largeurs × 2 modes |
| Débordement horizontal | **0** — `documentElement.scrollWidth == innerWidth` sur les 32 captures |
| Éléments hors cadre | **0** — aucun élément non rogné ne dépasse la largeur |
| Attribut de thème | **32/32** en `data-etudiant-theme="v2"` |
| Mode sombre | **32/32** conformes : classe `dark` présente en sombre, absente en clair |
| Hauteur de contenu | 1200 px sur 30 captures, 1202 px pour `archives` à 500 px ; aucun contenu coupé |

Contrôles visuels représentatifs :

- **Annonces** : carte lisible, contraste correct en clair et en sombre, action absente conforme.
- **Archives** : feuille complète à 500 px, identité et tampon lisibles, sections et documents
  empilés sans débordement.
- **Agenda** : pastille de date et carte d'événement lisibles ; le mode sombre conserve les
  contrastes.
- **Planning** : grille complète, jour courant distinct, créneau publié visible ; le mode sombre
  reste lisible.
- **Finances** : avis, KPI et facture réels ; le statut brut `issued` est un contenu existant, non
  modifié par le thème.
- **Paiements** : métriques et carte de statut ; l'accent latéral de la carte est visible, le bouton
  de dépôt reste contrasté.
- **Simulations** : le bouton « Nouvelle simulation » est désormais un vrai bouton V2 ; le libellé
  brut `passage` reste le contenu métier existant.
- **Création de simulation** : le bouton primaire et le bouton secondaire sont stylés et alignés à
  500 px comme à 1440 px, en clair et en sombre.

### 23.5 Sécurité et non-régression

| Contrôle | Résultat |
|---|---|
| Authentification | Inchangée ; les pages restent derrière les middlewares existants |
| Autorisation objet | Inchangée ; aucune donnée n'est lue depuis la requête de thème |
| Données | Aucune écriture en base ; les fixtures ne vivent que dans les tests |
| Entrée | Seuls `theme` (liste blanche) et la préférence locale sont lus |
| PII / journaux | Aucun journal ajouté ; aucune donnée personnelle supplémentaire |
| CSRF | Aucun formulaire ni verbe d'écriture ajouté |
| Endpoint JSON | Contrat inchangé, toujours protégé par l'authentification |
| Portée | Les portails admin, enseignant, les pages publiques et les écrans d'authentification restent hors V2 |
| V1 | Markup, contenu et rendu conservés ; le test de repli V1 passe |

### 23.6 Réserves

1. **Pages paramétrées non couvertes visuellement** : `annonces.show`, `demandes.show`,
   `paiements.inscription` et `parcours.simulations.show` restent hors du périmètre de cette
   vérification page par page.
2. **Suite complète non re-mesurée** : les rapports restent contradictoires (§20.7.1, §21.10, §22.9
   et `docs/audit-referentiel/06-RAPPORT-FINAL.md`) ; aucun PASS global n'est revendiqué.
3. **Libellés métier bruts** : `passage` et `issued` sont des contenus existants ; ils n'ont pas été
   traduits pour ne pas modifier les données ni le comportement entre V1 et V2.
4. **Captures statiques** : les interactions Alpine, les animations de révélation et le défilement
   réel ne sont pas exercés par les captures ; la vérification porte sur le rendu statique et la
   mise en page.
5. **Aucun commit** : les fichiers restent dans l'arbre de travail avec le WIP préexistant ; aucun
   tag, merge ou push n'a été effectué.

### 23.7 Verdict

**`PASS_WITH_RESERVATIONS`** — les neuf routes secondaires sont couvertes par des fixtures réelles,
les huit pages HTML exposent la coque et les styles V2, l'endpoint JSON conserve son contrat, la V1
reste sélectionnable, les 32 captures ne montrent aucun débordement horizontal et les suites ciblées
passent. Les pages paramétrées, la suite complète et l'arbitrage de commit restent ouverts.

---

## 24. Journal d'exécution — Sécurisation de l'impression et des exports de listes
### (LOOP-UNIREGISTRE-SECURISATION-IMPRESSION-EXPORTS-01)

### 24.1 Périmètre et état initial

- **Objectif** : traiter la réserve de sécurité de §22.5/§22.7.3 — `admin.listes.imprimer` n'exigeait
  pas `data.export` côté serveur alors que le lien était masqué dans l'interface — puis auditer les
  autorisations des autres routes d'export liées aux listes d'étudiants.
- **État Git au démarrage** : branche `feature/admin-modifier-etudiant`, HEAD
  `38ef14933de9980a4c62cb805bd19bc86c2b3081`, **475 entrées** dans l'arbre de travail (dont le WIP
  concurrent du §22 : le hunk `depose_depuis` de `ListesController.php` était déjà présent et a été
  préservé tel quel). Le rapport lui-même est non suivi.
- **Confirmation du défaut (lecture de code)** : la route `listes/imprimer`
  (`routes/web.php:1326`, groupe `['auth', 'admin', 'mfa.obligatoire', 'secondaire.verrou']`, sans
  middleware `can:`) aboutissait à `ListesController::imprimer` qui ne faisait que
  `Gate::authorize('viewAny', Etudiant::class)` ; or `EtudiantPolicy::viewAny` retourne toujours
  `true`. Tout compte back-office authentifié (y compris `consultation`, qui n'a ni `data.export`
  ni `exports.personal`) pouvait donc appeler l'URL directement.
- **Nature de la réponse** : l'impression serveur n'est pas un simple déclencheur `window.print` :
  elle rend une vue HTML complète (`admin.listes.print`) contenant jusqu'à 1000 lignes de la
  population réelle (CIN masquée `BK***877` et e-mail/téléphone retirés sans `exports.personal`,
  via `ListeEtudiantsService::jeuDonnees` / `ExportColumnPolicy`). Le masquage d'UI n'était donc
  pas une protection : la donnée était servie par le serveur.

### 24.2 Route et contrôle d'accès — avant / après

| Élément | Avant | Après |
|---|---|---|
| Route `admin.listes.imprimer` | `viewAny` uniquement (policy toujours `true`) | `Gate::authorize('viewAny')` **puis** `abort_unless($request->user()->can('data.export'), 403)` |
| Convention | — | Identique à `ListesController::exporter` (ligne 93) ; pas de middleware `can:` redondant sur la route |
| Comportement autorisé | 200 + vue imprimable | 200 + vue imprimable (inchangé) |
| Accès direct sans `data.export` | 200 + données | **403**, avant même la validation des filtres |
| Invité | Redirection `admin.connexion.show` | Inchangé (middleware `auth` du groupe) |

Aucune permission n'a été accordée à un rôle : `data.export` reste porté par `admin_scolarite`,
`agent_scolarite`, `admin_faculte` et `super_admin` ; `consultation` ne l'a toujours pas.

### 24.3 Audit des routes d'export associées

| Route | Contrôle serveur avant | UI avant | Décision de ce lot |
|---|---|---|---|
| `listes.exporter/{format}` (CSV/XLSX/PDF) | `data.export` (contrôleur, L93) — conforme | Boutons sous `@can('data.export')` (`x-export-actions`, `sans-groupe`, `groupes/index`, `groupes/etudiants`, `ecues/show`) | Non modifié |
| `listes.imprimer` | **Défaut** : `viewAny` seul | Gaté sauf sur `groupes/show.blade.php` | **Corrigé** : `data.export` serveur + lien `groupes/show` enveloppé |
| `listes.exports` (historique) | **Défaut** : `viewAny` seul — un compte central sans `data.export` voyait les métadonnées de tous les exports | Lien « Mes exports » visible sans permission dans `preview.blade.php` | **Corrigé** : `data.export` + lien enveloppé |
| `listes.exports.download` | **Défaut** : type + propriétaire/super_admin, sans `data.export` — un propriétaire ayant perdu la permission pouvait encore télécharger le fichier | Lien dans la page historique | **Corrigé** : `data.export` ajouté avant le contrôle de propriété |
| `listes.index`, `listes.preview` | Consultation (`viewAny`) | — | **Non modifié, volontairement** : consulter une liste ≠ exporter ; le masquage CIN/e-mail/téléphone reste appliqué par `exports.personal` |
| `listes.profils.store/destroy` | `viewAny` (filtres personnels, aucune donnée) | — | Non modifié |

**Traitement différé** : `GeneratePopulationExport` ne revérifie pas `data.export` à l'exécution
(précédent existant : `ExecuterAffectationMasseLot` le fait pour `archives.update`). Les filtres
sont déjà validés et scopés faculté au moment du dispatch, le fichier vit sur le disque privé avec
expiration, et le téléchargement est désormais fermé sans `data.export` : le pire cas après retrait
de permission est un fichier généré pour rien puis purgé, jamais une fuite. Risque résiduel faible,
**consigné, non modifié** (pas de réécriture du pipeline d'export dans ce lot). **→ Traité en §25.**

**Incohérence UI restante (faible)** : « Candidats CSV » dans `modules/evaluation/epreuves-show.blade.php`
est gaté par `epreuves.view` et non `data.export` ; un lecteur sans `data.export` voit un lien mort
(403 serveur). Serveur déjà refusant, aucune vulnérabilité : **consigné, non modifié** (hors écrans
listes, même route cible). **→ Traité en §25.**

### 24.4 Fichiers modifiés

| Fichier | Changement |
|---|---|
| `app/Http/Controllers/Admin/ListesController.php` | +3 lignes : `abort_unless($request->user()->can('data.export'), 403);` dans `imprimer` (L201), `exports` (L255), `telechargerExport` (L269) |
| `resources/views/admin/groupes/show.blade.php` | Lien « Imprimer » enveloppé dans `@can('data.export')` |
| `resources/views/admin/listes/preview.blade.php` | Lien « Mes exports » enveloppé dans `@can('data.export')` |
| `tests/Feature/PrintExportSecurityTest.php` | Nouveau fichier non suivi : 11 tests de sécurité |

Le hunk préexistant `depose_depuis` de `ListesController.php` (WIP concurrent du §22) est intact ;
le diff de ce lot sur ce fichier se limite aux 3 lignes ci-dessus.

### 24.5 Tests et résultats exacts

**Nouveau fichier `PrintExportSecurityTest`** (utilisateurs réels : `admin_scolarite` = `data.export`
+ `exports.personal`, `agent_scolarite` = `data.export` seul, `consultation` = aucun, `admin_faculte`
= les deux + périmètre faculté) :

| Test | Vérification |
|---|---|
| `test_impression_autorisee_avec_data_export_et_filtre_valide` | 200, `window.print` présent, filtre `search` appliqué (1 ligne exacte) |
| `test_acces_direct_refuse_sans_data_export_et_aucune_donnee_rendue` | **403** et nom d'étudiant absent du rendu |
| `test_impression_refusee_pour_invite` | 302 vers `admin.connexion.show` |
| `test_permission_verifiee_avant_la_validation_des_filtres` | Sans permission + filtre invalide → **403** (pas de validation) ; avec permission + filtre invalide → erreur de validation `niveau` (comportement conservé) |
| `test_impression_respecte_le_cloisonnement_faculte` | `admin_faculte` + `faculte_id` falsifié → 200, faculté forcée côté serveur, seules les lignes de sa faculté |
| `test_impression_masque_les_donnees_personnelles_sans_exports_personal` | 200, `BK998877` absent, `BK***877` présent, e-mail et téléphone absents |
| `test_impression_affiche_les_donnees_personnelles_avec_permission` | 200, CIN brute + e-mail + téléphone présents |
| `test_exports_pdf_excel_csv_non_regression_et_permission` | CSV/XLSX/PDF : 200 autorisé (CSV contient la donnée), **403** sans permission, pour chaque format |
| `test_lien_impression_masque_sans_data_export_sur_la_fiche_groupe` | URL d'impression absente/présente sur `admin.groupes.show` selon la permission |
| `test_historique_des_exports_exige_data_export_et_lien_masque` | Historique 403/200, lien « Mes exports » masqué/affiché |
| `test_telechargement_export_differe_exige_data_export` | Propriétaire sans permission → **403** ; propriétaire autorisé → 200 |

Exécutions :

| Étape | Résultat |
|---|---|
| Baseline avant toute modification (6 fichiers : `ExportListesTest`, `AsyncExportTest`, `SensitiveExportTest`, `PdfStudentListTest`, `PdfGroupingTest`, `HarmonisationFileRegistreTest`) | **46 tests / 46 réussis / 386 assertions** |
| Nouveau fichier **avant correction** | **10 tests : 6 réussis, 4 échecs** — 403 attendu mais 200 sur l'impression directe ; 403 attendu mais 302 (validation) sans permission ; lien « Imprimer » présent sur la fiche groupe sans permission ; téléchargement différé 200 au lieu de 403 |
| Nouveau fichier **après correction** | **11 tests / 11 réussis / 47 assertions** |
| Suite ciblée combinée (mêmes 6 fichiers + nouveau) | **57 tests / 57 réussis / 433 assertions** |
| `vendor/bin/pint --test` sur les 2 fichiers PHP modifiés | **PASS** (aucun reformatage nécessaire) |
| `php -l` sur les 2 fichiers PHP modifiés | **PASS** |

Aucune régression : les 46 tests de la baseline passent toujours. Les 4 échecs pré-correction
étaient les défauts visés, tous corrigés. Aucun échec préexistant n'a été observé dans le périmètre
ciblé.

### 24.6 Sécurité

- **Contrôles ajoutés** : `data.export` devient une garde serveur effective sur toute la surface
  d'export des listes (impression, historique, téléchargement différé, exports synchrones déjà
  gatés). Le masquage d'interface n'est plus la seule protection nulle part sur ces routes.
- **Cloisonnement faculté** : intact (`filtresValides` force `faculte_id`, le moteur réapplique le
  périmètre) ; test dédié avec `faculte_id` falsifié dans l'URL.
- **Champs personnels** : politique `exports.personal` intacte (CIN masquée, e-mail/téléphone
  retirés) ; testée dans les deux sens sur l'impression.
- **Permissions RBAC** : aucun rôle modifié, aucun `data.export` accordé.
- **Aucune migration** : aucun fichier de migration créé ou modifié par ce lot (les fichiers de
  migration non suivis présents dans l'arbre préexistent au lot).

### 24.7 Réserves

1. **Pas de suite globale** : arbre de travail avec 476 entrées (dont WIP et session concurrente) ;
   seules les suites ciblées ci-dessus sont revendiquées.
2. **Pas de test visuel navigateur** : aucun test Playwright/navigateur n'a été exécuté ; la
   vérification UI se limite aux assertions HTTP (présence/absence des URLs dans le HTML rendu).
3. **Job différé** : `data.export` n'est pas revérifié à l'exécution de `GeneratePopulationExport`
   (risque résiduel faible, cf. §24.3) — non modifié pour ne pas réécrire le pipeline.
   **→ Mise à jour : traité en §25.**
4. **« Candidats CSV » (`epreuves-show`)** : gaté par `epreuves.view`, lien mort pour un compte sans
   `data.export` (403 serveur) — consigné, non modifié. **→ Mise à jour : traité en §25.**
5. **D-03** (seuil d'alerte assiduité) reste hors périmètre, `BLOCKED` jusqu'à décision
   institutionnelle confirmée.
6. **Historique central** : un compte central avec `data.export` voit toujours les métadonnées des
   exports de tous (comportement préexistant, aucune PII) ; non modifié.

### 24.8 État Git final

- Aucun commit, aucun push, aucun merge, aucune migration, aucune suppression de données ni
  d'opération destructive.
- Branche `feature/admin-modifier-etudiant`, HEAD inchangé
  `38ef14933de9980a4c62cb805bd19bc86c2b3081`.
- Arbre de travail : **476 entrées** (475 au démarrage + le nouveau fichier de test non suivi) ;
  toutes les modifications concurrentes et non suivies sont préservées, y compris le hunk
  `depose_depuis` de `ListesController.php` et le rapport non suivi lui-même.
- Fichiers touchés par ce lot : les 4 listés en §24.4 (3 modifiés + 1 nouveau test non suivi).

### 24.9 Verdict

**`PASS_WITH_RESERVATIONS`**

- Le défaut confirmé de §22 (`admin.listes.imprimer` sans `data.export`) est corrigé côté serveur,
  avec l'historique et le téléchargement différé mis en cohérence, et couvert par 11 nouveaux tests
  vérifiant les codes HTTP **et** le contenu (données rendues, CIN masquée, cloisonnement faculté).
- Suite ciblée : **57/57 tests, 433 assertions** ; Pint et `php -l` : **PASS**.
- Réserves : pas de suite globale ni de test visuel navigateur, permission non revérifiée à
  l'exécution du job différé (documentée), incohérence UI `epreuves-show` documentée, D-03
  indépendant toujours hors périmètre.

---

## 25. Journal d'exécution — Révocation de permissions des exports différés et lien « Candidats CSV »
### (LOOP-UNIREGISTRE-EXPORTS-DIFFÉRÉS-REVOCATION-PERMISSIONS-01)

### 25.1 Périmètre et état initial

- **Objectif** : traiter les réserves §24.7 items 3 et 4 — (1) revérification de `data.export` à
  l'exécution de `GeneratePopulationExport` ; (2) alignement du lien « Candidats CSV » de
  `epreuves-show` sur le refus serveur déjà effectif.
- **État Git au démarrage** : branche `feature/admin-modifier-etudiant`, HEAD
  `38ef14933de9980a4c62cb805bd19bc86c2b3081`, **476 entrées** dans l'arbre. Pendant le lot, une
  session concurrente active a porté l'arbre à **486 entrées** (dont 2 fichiers de migration datés
  du 21/10 et une réinstallation de `vendor/` sans dépendances de dev) : tout ce WIP a été préservé.
- **Fonctionnement réel de la file vérifié** : le job ne s'exécute **pas** sous le compte du
  demandeur — il tourne sous le compte du worker de file et reconstruit l'identité via
  `User::find($this->userId)` ; aucun utilisateur authentifié n'existe dans le contexte job. Toute
  politique d'autorisation doit donc être explicite dans `handle()`, pas héritée de la requête.

### 25.2 Défauts confirmés (avant correction)

| # | Défaut | Preuve |
|---|---|---|
| 1 | `GeneratePopulationExport::handle` ne contrôlait ni l'existence, ni l'activation, ni `data.export` du demandeur : un export demandé puis révoqué était **quand même généré** (fichier + notification) | Lecture du code + exécution pré-fix (§25.4) |
| 2 | Un demandeur **supprimé** laissait le job produire un fichier orphelin (rapport `completed`, fichier privé téléchargeable par un super_admin, personne d'autre) | Idem |
| 3 | Un demandeur **désactivé** (`actif = false`, bloqué du back-office par `EnsureBackOffice`) recevait quand même un fichier et une notification pointant vers une page qui lui renverrait 403 | Idem |
| 4 | « Candidats CSV » (`modules/evaluation/epreuves-show.blade.php`) gaté par `epreuves.view` alors que `admin.listes.exporter` exige `data.export` → lien mort 403 pour `consultation` | Diff vue + §24.3 |

### 25.3 Corrections réalisées

- **`app/Jobs/GeneratePopulationExport.php`** : garde d'autorisation en tête de `handle()`, **avant
  tout traitement** — le demandeur doit exister, être actif et conserver `data.export`
  (`$utilisateur === null || ! $utilisateur->estActif() || ! $utilisateur->hasPermission('data.export')`).
  En cas de refus : rapport passé en `failed` avec le message **générique** « Demandeur introuvable,
  désactivé ou permission data.export retirée. » (même convention que
  `ExecuterAffectationMasseLot`), **aucun fichier écrit, aucune notification, aucun `report()`**
  (pas de bruit de log, aucune PII) ; la double lecture `User::find` est dédoublonnée. Statuts
  existants, expiration, purge et pipeline (services, seuils, `ImportReport`) inchangés.
- **`resources/views/modules/evaluation/epreuves-show.blade.php`** : bouton « Candidats CSV »
  enveloppé dans `@can('data.export')` (imbrication dans le bloc `epreuves.view` existant ; règle
  métier des épreuves et refus serveur intacts).
- Aucune permission accordée à un rôle, aucune migration, aucune donnée historique modifiée.

### 25.4 Tests et résultats exacts

**Nouveau fichier `tests/Feature/AsyncExportRevocationTest.php`** (5 tests, `Storage::fake('local')`,
job exécuté manuellement comme dans `AsyncExportTest` — aucune file réelle, aucune notification hors
table locale) :

| Test | Vérification |
|---|---|
| `test_export_autorise_genere_fichier_et_notification` | Comportement normal conservé : `completed`, fichier présent, notification `export.listes` |
| `test_permission_revoquee_avant_execution_passe_le_rapport_en_echec_sans_fichier` | Rôle basculé vers `consultation` entre demande et exécution → `failed`, message générique exact, `file_path` null, fichier absent, zéro notification |
| `test_demandeur_supprime_passe_le_rapport_en_echec_sans_fichier` | Compte supprimé → `failed`, aucun fichier, zéro notification |
| `test_demandeur_desactive_passe_le_rapport_en_echec_sans_fichier` | `actif = false` → `failed`, aucun fichier, zéro notification |
| `test_fichier_complete_restant_inaccessible_apres_revocation_et_expiration` | Export `completed` : téléchargement OK tant que propriétaire autorisé ; après révocation + expiration → **403** (propriétaire révoqué) et **403** (autre utilisateur autorisé, propriété exigée) |

Exécutions :

| Étape | Résultat |
|---|---|
| **Pré-fix** : job temporairement ramené à HEAD (version sans garde), tests de révocation exécutés | **5 tests : 2 réussis, 3 échecs** — les 3 refus non appliqués (rapport `completed` au lieu de `failed`, fichier généré malgré la révocation/suppression/désactivation) |
| **Post-fix** (garde réappliquée, diff revérifié) | **5 tests / 5 réussis / 20 assertions** |
| `ExamCandidatePopulationTest` + 1 test (`test_candidats_csv_masque_sans_data_export_et_route_directe_refusee`) | Page `epreuves.show` visible pour `consultation` (`epreuves.view`) mais « Candidats CSV » et son URL absents ; **accès direct à l'URL CSV exacte → 403** ; l'admin autorisé conserve le lien. Fichier : **6 tests / 6 réussis** |
| Suite combinée 9 fichiers (`AsyncExportRevocationTest`, `ExamCandidatePopulationTest`, `AsyncExportTest`, `PrintExportSecurityTest`, `ExportListesTest`, `HarmonisationFileRegistreTest`, `SensitiveExportTest`, `PdfStudentListTest`, `PdfGroupingTest`) | **67 tests / 67 réussis / 472 assertions** — expiration au téléchargement, propriété, permissions et cloisonnement inclus |
| `vendor/bin/pint --test` sur les 3 fichiers PHP modifiés | **PASS** (aucun reformatage) |
| `php -l` sur les 3 fichiers PHP | **PASS** |

**Incident d'environnement** (session concurrente) : en cours de lot, `php artisan test` est devenu
indisponible — `vendor/` avait été réinstallé sans dépendances de dev (phpunit/pint absents).
Restauré par `composer install` (respect strict de `composer.lock`, aucun fichier suivi modifié).
Les résultats ci-dessus sont tous postérieurs à cette restauration.

Aucune régression : les suites existantes du périmètre passent intégralement. Les 3 échecs
pré-fix étaient les défauts visés, tous corrigés.

### 25.5 Sécurité

- **Révocation effective aux trois moments** : demande (`exporter`, §22), exécution (nouveau garde
  job), téléchargement (`telechargerExport`, §24). Aucun fichier sensible n'est produit pour un
  demandeur sans autorisation valide au moment pertinent.
- **Messages génériques** sans PII ni détail de filtres ; aucune entrée de log ajoutée pour les
  refus (pas d'exception levée).
- **Données d'autrui** : jamais exposées — propriété + `data.export` exigés au téléchargement
  (retesté), `exports.personal` inchangé côté job.
- **Expiration/purge** : mécanisme intact ; un fichier généré avant révocation reste inaccessible
  sans `data.export` + propriété et finit purgé à expiration (test §25.4 ligne 5).

### 25.6 Réserves

1. **Pas de suite globale** : 486 à 496 entrées WIP et session concurrente active pendant le lot ;
   seules les suites ciblées ci-dessus sont revendiquées.
2. **Pas de test visuel navigateur** : vérification UI limitée aux assertions HTTP.
3. **Notification d'échec** : aucun envoi au demandeur révoqué (statut visible dans l'historique
   « Mes exports » uniquement) — choix minimal, cohérent avec le comportement d'échec existant du
   job ; toute notification d'échec nouvelle relèverait d'une décision produit.
4. **Fichiers déjà générés avant révocation** : restent sur disque jusqu'à expiration
   (inaccessibles, cf. §25.5) ; une purge immédiate à la révocation serait une évolution, pas un
   défaut.
5. **D-03** (seuil d'alerte assiduité) reste hors périmètre, `BLOCKED` jusqu'à décision
   institutionnelle confirmée.

### 25.7 État Git final

- Aucun commit, aucun push, aucun merge, aucune migration, aucune suppression de données ni
  opération destructive.
- Branche `feature/admin-modifier-etudiant`, HEAD inchangé
  `38ef14933de9980a4c62cb805bd19bc86c2b3081`.
- Arbre de travail : **486 entrées à la fin des corrections du lot, 496 à la clôture du rapport**
  (476 au démarrage ; la session concurrente ajoute activement des entrées). Tout le WIP est
  préservé, y compris les 2 migrations non suivies du 21/10 (préexistantes, aucune créée par ce
  lot) et les lots précédents (§22, §24).
- Fichiers touchés par ce lot : `app/Jobs/GeneratePopulationExport.php` (modifié),
  `resources/views/modules/evaluation/epreuves-show.blade.php` (modifié),
  `tests/Feature/ExamCandidatePopulationTest.php` (modifié, propre avant le lot),
  `tests/Feature/AsyncExportRevocationTest.php` (nouveau non suivi).

### 25.8 Verdict

**`PASS_WITH_RESERVATIONS`**

- Les deux réserves visées de §24 sont traitées avec preuve avant/après (3 échecs pré-fix → 0
  post-fix) : l'autorisation `data.export` du demandeur est désormais réévaluée à l'exécution des
  exports différés (aucun fichier, aucune notification, message générique), et le lien « Candidats
  CSV » est aligné sur le refus serveur.
- Suite ciblée : **67/67 tests, 472 assertions** ; Pint et `php -l` : **PASS**.
- Réserves : pas de suite globale ni de test visuel navigateur (session concurrente active), pas de
  notification d'échec au demandeur révoqué (choix minimal documenté), D-03 indépendant toujours
  hors périmètre.
