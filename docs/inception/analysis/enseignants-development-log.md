# Journal de développement — Module Enseignants

- Projet : plateforme universitaire (Laravel 13.33.0, PHP 8.5, MySQL).
- Périmètre : module Enseignants (`/admin/enseignants` + intégrations).
- Audit source : `docs/inception/analysis/enseignants-audit.md` (2026-10-10).
- Environnement : **pas de dépôt Git** (`git status` → exit 128) — tout changement est fait par edits réversibles minimaux, journalisés fichier par fichier ; aucun `reset`/`clean` possible ni nécessaire.

## Phase 0 — Audit et baseline (2026-10-10)

### Fichiers inspectés (vérification que les anomalies de l'audit sont toujours présentes)

| Anomalie | Fichier | Verdict |
|---|---|---|
| E1 suppression 500 FK restrict | `app/Http/Controllers/Admin/EnseignantController.php:134-146` (garde `slots()` seule) | **toujours présente** |
| E2 entité globale sans scope | id. + `Teacher::faculteId()` jamais appelé côté admin | **toujours présente** (décision métier requise) |
| M1 index sans Gate | `routes/web.php:789` | **toujours présente** |
| M2 statut/actif désynchronisés | `form.blade.php:43-48`, `store:66`, `update:106-132` | **toujours présente** |
| M3 export lossy + grade auto-créé | `TeacherDefinition.php:30,153-156,202-213` | **toujours présente** (décalage CSV prouvé : 9 en-têtes vs 7 valeurs) |
| M4 fiche : requête en Blade, `matiere` non eager | `show.blade.php:50-51`, `show:74` | **toujours présente** |
| M5 conflit matricule à l'import | `TeacherDefinition::persister` sans pré-check | **toujours présente** (à prouver par test) |
| M6 provider `schedules.view` | `EnseignantsProvider.php:17-19` | **toujours présente** |
| M7 écritures sans throttle | `routes/web.php:790-799` | **toujours présente** |
| M8 3 contrôleurs portail morts | `Enseignant/{Surveillance,Presence,EmploiDuTemps}Controller.php` | **toujours présentes** (aucune route) |

### Données de référence (MySQL dev, lecture seule)

- `teachers` : 111 lignes, 111 actifs, 1 compte portail activé (password non nul), 105/111 sans département (95 %), 105 sans grade, 4 sans matricule, 2 sans email, 0 doublon email, 0 homonyme nom+prénom.
- FK entrantes : `affectations_enseignement` RESTRICT, `evaluation_surveillants` RESTRICT, `evaluation_jury_membres` RESTRICT, `matieres.responsable_teacher_id` RESTRICT, `teacher_pointages` RESTRICT, `teacher_presences` RESTRICT, `schedule_slots` SET NULL, `teacher_documents/demandes/reclamations/seance_appels/notifications/ressources_pedagogiques` CASCADE.
- Comptes de test dev existants : `admin@universite.ac.ma` / `scolarite@universite.ac.ma` (seeders — utilisés en GET seul).

### Tests de référence exécutés (baseline, réels)

| Commande | Résultat |
|---|---|
| `php artisan test --compact tests/Feature/BackOfficeTest.php tests/Feature/TeacherHomonymeImportTest.php tests/Feature/EspaceEnseignantTest.php` | ✅ 23 tests, 92 assertions, 1,69 s |
| `php artisan test --compact` (EspaceEnseignant + Portail + Modules) [audit initial] | ✅ 43 tests, 188 assertions |
| `php artisan test --compact` (Emploi + Notes + AffectationExamen + Charge) [audit initial] | ✅ 65 tests, 285 assertions |
| `php artisan test --compact` (StudentList + Attendance×2) [audit initial] | ✅ 44 tests, 168 assertions |
| `php artisan audit:routes-securite` | Routes auth OK ; `GET /admin/enseignants` en REVUE (M1) |
| Parcours HTTP réel (session super_admin, GET seul) | liste 200 (12/page, 111 lignes), show/edit/create 200, search 200, CSRF 419 prouvé, CSV 200 **avec décalage colonnes constaté** |

### Vérifications page réelle (baseline avant corrections)

- Liste p1 : 0,27 s (première requête, warm-up), p5 : 0,05 s, p10 : 0,03 s.
- Fiche `show` : 0,06 s. Création (GET) : 0,04 s. Export CSV (111 lignes) : 0,05 s. Export PDF : 0,43 s.
- Export CSV réel : en-têtes `nom;prenom;email;matricule;grade;departement;bureau;faculte_code;statut` (9) vs lignes à 7-8 valeurs — **décalage de colonnes confirmé sur données réelles** (M3).

### Décision de passage

Toutes les anomalies de l'audit sont toujours présentes, aucun correctif n'a été appliqué entre-temps, baseline verte → **Phase A démarrée**.

## Phase A — Fiabilité et sécurité (2026-10-10)

### CONVERGENCE DE CO-ÉDITION détectée (2e main active en parallèle)

- À 19:53-19:54, pendant la rédaction de mes tests RED, une session parallèle a appliqué les mêmes correctifs (pattern « co-édition 2 mains » documenté dans AGENTS.md) :
  - `app/Http/Controllers/Admin/EnseignantController.php` : garde `destroy()` étendue (créneaux + affectations d'enseignement + surveillances + présences/pointages + jurys + matières responsables) — E1 corrigé.
  - `routes/web.php:788-799` : `can:enseignants.view` sur index (M1) + `throttle:20,1` sur store/update/destroy (M7).
  - `tests/Feature/EnseignantFiabiliteTest.php` : 9 tests couvrant le même périmètre.
- Décision : **convergence vers une seule suite** (convention projet) — ma suite RED `EnseignantSuppressionSecuriteTest.php` (couverture strictement incluse dans la leur, complétée par leurs tests Gate consultation/invité plus riches) a été **supprimée sans perte de couverture** ; leur suite conservée comme canonique.

### Fichiers modifiés (état final)

| Fichier | Changement | Anomalie |
|---|---|---|
| `app/Http/Controllers/Admin/EnseignantController.php` | `destroy()` : garde complète sur les 6 familles FK restrict (créneaux, affectations d'enseignement, surveillances, présences/pointages, jurys, matières responsables) + refus métier listé, zéro détachement automatique | E1 |
| `routes/web.php` | index + `can:enseignants.view` ; store/update/destroy + `throttle:20,1` | M1, M7 |
| `tests/Feature/EnseignantFiabiliteTest.php` | 9 tests (créés par la 2e main, vérifiés et adoptés) | preuves E1/M1/M7 |

### Commandes exécutées (réelles)

| Commande | Résultat |
|---|---|
| `php artisan test --compact tests/Feature/EnseignantFiabiliteTest.php` | ✅ 9/9, 71 assertions, 1,12 s |
| `vendor/bin/pint --test tests/Feature/EnseignantFiabiliteTest.php app/Http/Controllers/Admin/EnseignantController.php` | ✅ vert |
| Régression `BackOfficeTest` + `Security/AuthorizationSecurityTest` | ✅ 15/15, 57 assertions |
| Régression voisins `TeacherHomonymeImportTest` + `EspaceEnseignantTest` + `EspaceEnseignantModulesTest` | ✅ 30/30, 121 assertions |
| `php artisan audit:routes-securite` | 48 REVUE (vs 49 en baseline — l'alerte index enseignants a disparu) |
| Preuve SQL dev (tinker) : DELETE enseignant avec affectation | `QueryException 1451` FK 1451 sur MySQL réel → le risque 500 pré-correctif est prouvé, couvert désormais par la garde applicative (test vert en sqlite) |

### Critères d'acceptation Phase A

1. Suppression interdite → refus métier (`302` + erreur `enseignant`), zéro 500 : ✅ (5 tests, une par famille FK).
2. Relations et historiques conservés : ✅ (`assertDatabaseHas` après refus ; zéro détachement).
3. Sans permission → actions protégées inaccessibles : ✅ (invité redirigé, consultation 403 sur écritures, lecture OK).
4. Utilisateurs autorisés → parcours normal : ✅ (suppression propre réussie, création/modification régression BackOfficeTest verte).
5. Throttle écritures : ✅ (21e requête → 429, zéro écriture parasite).

### Décision de passage

**VALIDÉE** → Phase B démarrée.

---

## Phase A — Fiabilité et sécurité (2026-10-10) — **VALIDÉE**

### ÉTAPE 1 — PLAN
- Cibles : E1 (suppression 500), M1 (index sans Gate), M7 (écritures sans throttle) + verrouillage autorisations CRUD.
- Critères : refus métier sans 500 + historique conservé ; invité redirigé ; `consultation` 403 en écriture ; parcours autorisés intacts ; régression verte.

### ÉTAPE 2 — TESTS (RED prouvé)
- `tests/Feature/EnseignantFiabiliteTest.php` créé : 500 FK prouvé sur surveillance, pointage, affectation (E1) ; `can:enseignants.view` absent (M1) ; throttle absent + 302 au lieu de 429 (M2→M7).
- Convergence : la 2e main a relevé 2 FK restrict supplémentaires (`evaluation_jury_membres.teacher_id`, `matieres.responsable_teacher_id` — vérifiés en migrations `2026_09_30_000001:181` et `2026_09_29_220714:108-109`) → test jury/matière ajouté, **500 prouvé** lui aussi avant correction.

### ÉTAPE 3 — IMPLÉMENTATION (minimum)
- `app/Http/Controllers/Admin/EnseignantController.php::destroy` : garde étendue aux 6 familles (`slots`, `affectationsEnseignement`, `surveillances`, `presencesSeances|pointages`, `JuryMembre`, `Matiere responsable`), refus `back()->withErrors(['enseignant' => ...])` listant les attaches, aucun détachement automatique.
- `routes/web.php:789-801` : `can:enseignants.view` sur `index` ; `throttle:20,1` sur `store/update/destroy`.

### ÉTAPE 4 — VALIDATION (commandes réelles)
- `EnseignantFiabiliteTest` : **9/9 verts** (71 assertions, ~1,2 s).
- Régression : Fiabilite + BackOffice + AuthorizationSecurity + TeacherHomonymeImport = **28/28 verts** ; vagues précédentes 76 + 90 verts (0 rouge).
- `vendor/bin/pint` : vert (1 fix cosmétique `single_quote`).
- `route:list` : 7 CRUD + 7 presences, noms inchangés.

### ÉTAPE 5 — AUTO-CORRECTION
- Cycle 1 : test jury/matière rouge (500 prouvé) → garde étendue → vert. Cycle 2 : pint `single_quote` → re-vert. Aucun 3e cycle nécessaire.

### ÉTAPE 6 — GATE
Tous critères satisfaits. **Phase A : VALIDÉE.** Fichiers : `EnseignantController.php`, `routes/web.php`, `tests/Feature/EnseignantFiabiliteTest.php`. Aucune migration.

---

## Phase B — Cohérence métier (2026-10-10) — **EN ATTENTE DÉCISION MÉTIER (bloquée avant modification)**

### Investigation (faits)
- `Teacher::faculteId()` (`Schedule/Models/Teacher.php:56-58`) : via `departement.faculte_id`, NULL = transversal. Utilisé par `TeacherPresencePolicy:66-68` (périmètre secondaire) et `ServiceEnseignantService::tableauServices` — **jamais par `EnseignantController`**.
- Comptes restreints : `User::faculteRestreinteId()` = `faculte_id` si `admin_faculte` (`User.php:130-134`) ; `peutAccederFaculte()` (`:140-145`).
- Modèle salles (référence durcissement 04/10) : `SalleController::store` force `faculte_id` restreinte + 404 si cible étrangère ; `update/destroy` via `abortHorsPerimetre` (salles globales restent partagées en lecture).
- Enseignants : `index` global non filtré, `show/edit/update/destroy` sans contrôle, `store/update` acceptent tout `departement_id`, `optionsReferentiel()` charge tous départements/grades/spécialités.
- Données réelles (2e main, MySQL dev) : 105/111 enseignants **sans département** (transversaux de fait), 111/111 actifs.
- Vecteur concret : un `admin_faculte` A peut aujourd'hui modifier l'email + poser un mot de passe sur un enseignant de la faculté B (formulaire admin) → prise de contrôle du portail de cet enseignant. Prouvé par lecture du formulaire + `update` (aucun scope).
- `actif` (bool) fait foi partout (login `ConnexionController:46`, `EnsureTeacher:27`, `tableauServices`) ; `statut` (string) n'est lu que pour affichage (`show:21`) et jamais posé par le formulaire ; **l'import le déclare (`regles`) mais `TeacherDefinition::persister` ne le persiste pas** (perte silencieuse → versée en Phase C).
- `ProfilController:36` et portail : statut jamais modifiable côté enseignant (sain, inchangé quel que soit le choix).

### Comparaison des options (E2 — périmètre)
- **Option 1 — Global assumé (statu quo + documentation)** : aucun code ; refermer le vecteur par procédure (retirer `enseignants.manage` aux `admin_faculte` ? = changement matrice, effet large). Coût XS. Laisse le vecteur technique ouvert.
- **Option 2 — Isolation modèle salles (recommandée)** : index = transversaux (NULL) + SA faculté ; show/edit/update/destroy → 404 hors périmètre ; store/update : `departement_id` nul ou de SA faculté (cible étrangère → 404) ; `optionsReferentiel()` scopées ; lecture globale conservée pour rôles globaux. Coût M, tests IDOR A/B. Compatible avec les 95 % transversaux (restent visibles).
- **Option 3 — Hybride** : lecture globale pour tous, écritures scopées (comme salles mais sans filtre index). Moindre friction, deux règles à maintenir.

### Comparaison des options (actif/statut)
- **Option A — `actif` fait foi (recommandée)** : portail/listings inchangés ; formulaire gagne un select `statut` (actif/inactif/suspendu/retraite) synchronisé serveur (`statut=actif ⟺ actif=true`, dernier écrit gagne) ; import persiste `statut` + synchronise `actif`. Zéro migration.
- **Option B — `statut` fait foi** : login/EnsureTeacher/`tableauServices` basculent sur `statut=actif`, colonne `actif` dépréciée (migration + backfill) — plus invasif, aucun bénéfice prouvé.

### Statut
**Phase B : BLOQUÉE avant modification** — attente décision humaine (E2 : option 1/2/3 ; statut : option A/B). Phases A validée préservée ; C/D/E non démarrées (C partiellement dépendante du choix statut pour l'import, D/E indépendantes mais l'ordre impose d'attendre).

### Décisions humaines reçues (2026-10-10)
- E2 : **Option 2 — isolation modèle salles** (transversaux NULL partagés + SA faculté ; hors périmètre → 404).
- Statut : **Option A — `actif` fait foi** (select + synchro serveur, zéro migration).

### ÉTAPE 1 — PLAN (complément)
- Critères : index/fiches/écritures scopés + 404 prouvés A/B ; formulaire départements scopés ; synchro statut (création suspendu → inactif + login refusé ; réactivation ; chemin historique `actif` seul) ; régression verte.

### ÉTAPE 2 — TESTS (RED prouvé)
- `tests/Feature/EnseignantPerimetreTest.php` (6 tests) : **0/6 verts** avant correction (index fuit fac B, accès directs 200, création/rattachement étrangers acceptés, formulaire liste DEP-B, statut ignoré).

### ÉTAPE 3 — IMPLÉMENTATION (minimum)
- `EnseignantController` : `index` scopé (NULL + `orWhereHas departement.faculte`) ; `abortHorsPerimetre()` (show/edit/update/destroy, miroir `SalleController:128-135`) ; `abortDepartementHorsPerimetre()` (store/update, nul autorisé, étranger → 404) ; `optionsReferentiel(?int)` départements scopés (grades/spécialités globales — `Specialite` sans rattachement faculté) ; synchro Option A (store : `statut ??= actif` puis `actif = statut===actif` ; update : statut explicite gagne, sinon `actif` seul → statut suit, sinon inchangé — compat `BackOfficeTest` conservée).
- `EnseignantsProvider::rechercher` : même périmètre (évite des liens vers des 404).
- `ListesController::listesReferentiels` : dropdown `enseignants` scopé comme `programmes/groupes`.
- `form.blade.php` : case `actif` remplacée par select `statut` (4 libellés explicites + note de synchronisation).

### ÉTAPE 4 — VALIDATION (commandes réelles)
- `EnseignantPerimetreTest` : **6/6 verts** au 1er cycle.
- Régression : 67 verts (Fiabilite 9 + Perimetre 6 + BackOffice + Espace/Portail/Modules) + 32 verts (Listes/Exports/Dataset/Authorization) + NavigationPhase7 8 verts — **0 rouge**.
- Pint vert sur les 4 fichiers PHP touchés. `route:list` inchangée (aucune route ajoutée/renommée).

### ÉTAPE 5 — AUTO-CORRECTION
Non nécessaire.

### ÉTAPE 6 — GATE
Critères satisfaits (tests A/B positifs+négatifs, statuts cohérents formulaire/BD/connexion, zéro perte, pint vert, documenté).
**Phase B : VALIDÉE.** Aucune migration. Résidu assumé : filtre `enseignant_id` de `ListeEtudiantsService:126-130` (ne expose que des étudiants déjà scopés ; dropdown source désormais scopé).

---

## Phase C — Import et export (2026-10-10) — **VALIDÉE**

### ÉTAPE 1 — PLAN
- Cibles : M3 (export lossy `ligne()` sans statut/faculte_code, grade auto-créé silencieux), M5 (conflit matricule), statut importé mais jamais persisté, périmètre E2 dans voir/export/import.
- Contrainte découverte : SALIMA `15_enseignants.csv` utilise grade `MAITRE_ASSISTANT` (hors seeder PR/MCF/MA/ASS/VAC) + statut `ACTIF` majuscules, importé par `SalimaDatasetImportTest:240` → grade strict casserait le dataset ; choix : création conservée mais **explicite** (format strict + signalée en message), statut normalisé insensible à la casse.
- Critères : doublons/conflits testés, roundtrip sans perte sur les 9 colonnes, échec explicite par ligne, périmètre testé, non-régression pipeline.

### ÉTAPE 2 — TESTS (RED prouvé)
- `tests/Feature/EnseignantImportExportTest.php` (9 tests) : **1/9 vert** avant correction (statut ignoré, grade garbage accepté, faculte incohérente acceptée, matricule en conflit accepté, hors-périmètre accepté, `filtrer` non scopé, `ligne()` incomplète, export HTTP fuyant).

### ÉTAPE 3 — IMPLÉMENTATION (minimum, `TeacherDefinition` seule)
- `STATUTS_VALIDES` + `normaliserStatut()` (insensible casse) ; statut inconnu → erreur métier (preview) + `IoException` (ceinture confirm) ; statut persisté + `actif` synchronisé (création défaut `actif`).
- `resoudreGrade()` : format `^[A-Z0-9_]{2,50}$` exigé, création explicite suffixée `" (grade X créé)"` ; branche morte `firstOrCreate` retirée de `resoudreReference()`.
- `resoudreDepartement()` + erreur département dès la preview (parité preview/confirm) ; `verifierCoherenceFaculte()` (département × faculte_code).
- `verifierConflitMatricule()` (validation + ceinture, mise à jour même-email préservée).
- `verifierPerimetre()` (validation + ceinture, via `User::faculteRestreinteId()`, auteur null = global).
- `filtrer()` scopé (voir/export, anti-exfiltration) ; `requete()` eager `departement.faculte` (export sans N+1) ; `ligne()` complète (9/9 colonnes).

### ÉTAPE 4 — VALIDATION (commandes réelles)
- `EnseignantImportExportTest` : **9/9 verts** (45 assertions) au 1er cycle.
- Régression : DataExchange (contrat+générique+homonymes+correcteur+revue) 60/60 ; **SALIMA dataset 1/1 (140 assertions)** — `MAITRE_ASSISTANT`/`ACTIF` absorbés ; ImportCenter+LMD+Notes 23/23.
- Pint vert (fix cosmétiques auto). Aucune route ajoutée ; `route:list` inchangée.

### ÉTAPE 5 — AUTO-CORRECTION
- Test export HTTP : corps vide (réponse streamée) → assertion via `streamedContent()` (correction de test, pas du code).

### ÉTAPE 6 — GATE
Critères satisfaits. **Phase C : VALIDÉE.** Fichiers : `TeacherDefinition.php`, `tests/Feature/EnseignantImportExportTest.php`. Aucune migration. Formats : CSV prouvé (définition + HTTP), XLSX/JSON via framework générique (non re-prouvés ici), PDF annoncé par `formats()` — efficacité non re-prouvée (résidu).

---

## Phase D — Interface, performances et hygiène (2026-10-10) — **VALIDÉE**

### ÉTAPE 1 — PLAN
- Cibles : F1 (recherche matricule), filtres département/grade/statut + pagination perso (convention `EtudiantController:80` + `PreferencesService`), M4 (N+1 fiche : `slots.matiere` lazy ×5 prouvé + requête en Blade), M8 (3 contrôleurs morts).
- Critères : filtres combinables + querystring préservée ; recherche scopée ; requêtes fiche mesurées avant/après sans N+1 ; suppression prouvée sans référence.

### ÉTAPE 2 — TESTS (RED prouvé)
- `tests/Feature/EnseignantInterfaceTest.php` (5 tests) : recherche matricule absente, filtres absents, pagination fixe 12, **5 requêtes `matieres` unitaires sur la fiche (N+1 prouvé)**, classes mortes existantes.

### ÉTAPE 3 — IMPLÉMENTATION (minimum)
- `EnseignantController::index` : `matricule` dans la recherche ; `$request->validate()` (search/departement/grade/statut) ; `paginate($preferences['lignes_par_page'])` (`PreferencesService::lire`, 5–100, défaut 12) ; vue reçoit `filtres + optionsReferentiel` (départements déjà scopés Phase B).
- `show` : `loadMissing` += `slots.matiere` + `affectationsEnseignement` ; requête `Enseignement` déplacée Blade → contrôleur (`$enseignements`).
- `index.blade.php` : barre de filtres (3 selects scopés, valeurs préservées) ; `show.blade.php` : `@php` requête supprimée.
- Suppression `Enseignant/{Surveillance,Presence,EmploiDuTemps}Controller.php` (zéro référence hors fichiers eux-mêmes — vérifié par grep, routes/tests/vues/config) + `composer dump-autoload` (classmap régénérée, vendor généré).

### ÉTAPE 4 — VALIDATION (commandes réelles)
- `EnseignantInterfaceTest` : **5/5 verts** (21 assertions).
- Régression : 88 verts (4 suites boucle + BackOffice + portail ×4) + 96 verts (listes/exports/navigation/présences/appel/EDT/affectations/ressources) — **0 rouge**.
- Pint vert. `route:list` inchangée.
- Mesure fiche (5 créneaux) : 5 requêtes `matieres` unitaires avant → **0 après**, total ≤ 15 (test borné).

### ÉTAPE 5 — AUTO-CORRECTION
- Test pagination : 1 résultat = pas de liens → 2 pages réelles (6 lignes, pref 5) ; classmap stale après suppression → `dump-autoload` (corrections tests/env, pas du code).

### ÉTAPE 6 — GATE
Critères satisfaits. **Phase D : VALIDÉE.** Fichiers : `EnseignantController.php`, `index/show.blade.php`, 3 suppressions, `tests/Feature/EnseignantInterfaceTest.php`. Aucune migration. Résidu : tri configurable non ajouté (tri `nom` stable conservé — besoin non démontré).

---

## Phase E — Traçabilité et interopérabilité (2026-10-10) — **VALIDÉE**

### ÉTAPE 1 — PLAN
- Cibles : traçabilité créations/modifications/suppressions/imports (sans PII, attribuée), centre d'audit scopé, évaluation notifications + API.
- Constats préalables : `AuditService::tracer` exige un `Etudiant` (inutilisable tel quel) ; `IntegrationLog` sans acteur (inadapté) ; salles/groupes ne tracent pas leur CRUD (aucun précédent) ; `historique_academique.etudiant_id` NOT NULL (`2026_09_24_100009:17-19`) — ma première lecture d'une autre migration était fausse, corrigée par l'échec 500 en test.
- Critères : événements traçables + attribués, PII exclues, pas de duplication, centre scopé, API évaluée honnêtement.

### ÉTAPE 2 — TESTS (RED prouvé)
- `tests/Feature/EnseignantTracabiliteTest.php` (5 tests) : 1/5 vert (import déjà tracé par le framework) ; CRUD non tracé + centre non scopé prouvés.

### ÉTAPE 3 — IMPLÉMENTATION (minimum)
- Migration `2026_10_10_191552_rendre_etudiant_audit_nullable` (additive réversible, justification : réutiliser le journal central au lieu d'une 2e table ; down supprime les seules lignes sans dossier).
- `HistoriqueAcademique` : 3 constantes `enseignant.cree/modifie/supprime` + libellés.
- `AuditService::tracerReferentiel()` (même table/centre/export, `etudiant_id` NULL, PII interdites par contrat).
- `EnseignantController` : traces store/update/destroy via `clicheAudit()` (statut/actif/departement/grade uniquement) ; refus de suppression non tracés (pas de mutation).
- `AuditController::index + exporter` : périmètre `orWhere` enseignants (ids périmètre, même règle Phase B) ; vue audit : colonne Entité (`entite #id`, exporter l'avait déjà).
- Notifications admin : **évaluées puis écartées** (aucun précédent sur CRUD référentiels ; actes d'admin par des admins ; rapports d'import comme canal existant — créer des types serait artificiel).
- API v1 enseignants : **évaluée puis écartée** (aucun consommateur ; back-office web + portail session + DataExchange générique couvrent les usages — créer des endpoints serait artificiel, critère explicite).

### ÉTAPE 4 — VALIDATION (commandes réelles)
- `EnseignantTracabiliteTest` : **5/5 verts** (36 assertions).
- Régression : AuditLog+Archive+CentreResolution+BackOffice 32/32 ; StudentArchive+VerificationBoucle+QualiteApiSupervision 31/31 — **0 rouge**.
- Pint vert. Migration prouvée sur SQLite (suite `RefreshDatabase`) ; **non appliquée au MySQL dev partagé** — à déployer via `php artisan migrate`.
- `route:list` inchangée (aucune route ajoutée).

### ÉTAPE 5 — AUTO-CORRECTION
- Faux positif PII (prénom `T` vs `T` ISO8601) → fixture `Tp` ; classmap déjà régénérée Phase D.

### ÉTAPE 6 — GATE
Critères satisfaits. **Phase E : VALIDÉE.**
