# Audit fonctionnel — Module Enseignants

- Date : 2026-10-10
- Périmètre : page `http://localhost:8000/admin/enseignants` + Espace Enseignant (`/enseignant/*`) + intégrations (emplois du temps, surveillances, présences/appels, notes, ressources, annonces, import/export).
- Nature : **audit lecture seule**. Aucun fichier applicatif, aucune migration, aucune donnée métier, aucune configuration n'a été modifié pendant cet audit.
- Convention de lecture : chaque constat est marqué **FAIT** (observé dans le code ou mesuré), **HYPOTHÈSE** (probable, non prouvé) ou **RECOMMANDATION**.

## 1. Résumé exécutif

Le module Enseignants repose sur **une seule entité** `teachers` (`App\Modules\Schedule\Models\Teacher`) gérée par un seul contrôleur back-office (`Admin\EnseignantController`, CRUD complet + fiche), un connecteur import/export DataExchange (`TeacherDefinition`, entité `enseignants`), un portail enseignant session-scopé complet (tableau de bord, profil, emploi du temps, surveillances, notes anonymes, présences/appels, ressources, annonces, demandes, réclamations, recherche, notifications) et des intégrations riches (créneaux, affectations d'enseignement, surveillances, présences, compostages de notes, listes d'étudiants par enseignant).

**FAIT** — Socle sain : authentification temps constant + anti-énumération, sessions régénérées, validation serveur systématique, protection anti-homonymes à l'import (fail-closed prouvé par test vert), pas de `->all()` en écriture, CSRF présent, sorties échappées, 148 tests ciblés inventoriés dans 11 fichiers + suites surveillance.

**FAIT** — Points durs identifiés : (1) la suppression ne garde que les créneaux alors que 3 familles de FK sont en `restrictOnDelete` → 500 probable ; (2) entité **globale sans isolation faculté** alors que `admin_faculte` possède `enseignants.manage` (écart assumé par le commentaire §5 mais incohérent avec le durcissement appliqué aux salles) ; (3) route index sans Gate `can:` (signalée REVUE par `audit:routes-securite`, même pattern salles/groupes) ; (4) incohérence `actif` vs `statut` (statut non éditable dans le formulaire) ; (5) 3 contrôleurs portail morts sans route ni test.

Aucune anomalie **critique** prouvée. Détail : 0 critique, 2 élevées, 8 moyennes, 6 faibles (section 7).

## 2. Périmètre et méthode d'audit

- Source de vérité : fichiers du dépôt (routes, contrôleurs, modèles, migrations, services, vues, tests, permissions, config). Aucune fonctionnalité marquée opérationnelle sur la seule apparence UI.
- Exécuté réellement (**FAIT**) :
  - `php artisan route:list` : 776 routes ; 14 contiennent `enseignants` (7 CRUD admin + 7 `presences.enseignants.*`).
  - `curl http://localhost:8000/admin/enseignants` sans session : **302 → `/admin/connexion`** (0,38 s). Accès authentifié non testé (aucun compte de test utilisé — aucun faux parcours annoncé).
  - `php artisan test --filter=TeacherHomonymeImportTest` : **4/4 verts** (8 assertions, 727 ms).
  - `php artisan test --filter=EspaceEnseignantTest` : **10/10 verts** (44 assertions, 1 133 ms).
  - `php artisan audit:routes-securite` : 3 lignes REVUE sur le périmètre (`GET /admin/enseignants`, salles, groupes).
- Non exécuté : parcours authentifié au navigateur, mesures de charge, imports réels volumineux (volontairement exclus : environnement = données dev partagées).

## 3. Cartographie

### 3.1 Routes web back-office (FAIT — `routes/web.php:788-801`, groupe `auth,admin`)

| Méthode | URI | Nom | Middleware `can:` / throttle |
|---|---|---|---|
| GET | `admin/enseignants` | `admin.enseignants.index` | **aucun** (auth+admin seuls) → M1 |
| GET | `admin/enseignants/creer` | `admin.enseignants.create` | `enseignants.manage`, sans throttle |
| POST | `admin/enseignants` | `admin.enseignants.store` | `enseignants.manage`, sans throttle |
| GET | `admin/enseignants/{enseignant}/modifier` | `admin.enseignants.edit` | `enseignants.manage`, sans throttle |
| PUT/PATCH | `admin/enseignants/{enseignant}` | `admin.enseignants.update` | `enseignants.manage`, sans throttle |
| DELETE | `admin/enseignants/{enseignant}` | `admin.enseignants.destroy` | `enseignants.manage`, sans throttle |
| GET | `admin/enseignants/{enseignant}` | `admin.enseignants.show` | `enseignants.view`, sans throttle |

Ordre d'enregistrement sûr : `creer`/`modifier` avant `{enseignant}` (pas de capture parasite). Binding implicite `Teacher`, **sans scoping** (E2).

### 3.2 Espace Enseignant (FAIT)

- Socle `routes/web.php:262-326` : connexion (`throttle:5,1`), `enseignant.auth`, tableau de bord, notifications, profil (`throttle:10,1` / mot de passe `5,1`), documents, recherche (`30,1`), demandes, réclamations.
- Modules (session + `module:*` → 404 si inactif) : emploi du temps `Modules/Schedule/Routes/web.php:113-119`, surveillances + notes `Modules/Evaluation/Routes/web.php:350-377`, présences + appels `Modules/Attendance/Routes/web.php:159-187`, ressources `Modules/Enseignement/Routes/web.php:88-110`, annonces `Modules/Announcements/Routes/web.php:53-56`.
- **Aucune route API v1** pour les enseignants (**FAIT** : `routes/api.php` sans occurrence `teacher|enseignant` ; seuls `schedules/import/*` existent côté planning).

### 3.3 Contrôleurs, Requests, Policies

- `Admin\EnseignantController` (`app/Http/Controllers/Admin/EnseignantController.php`) : `index:22-39`, `store:46-70`, `show:72-87`, `edit:89-92`, `optionsReferentiel:97-104`, `update:106-132`, `destroy:134-146`. Validation **inline** (`$request->validate`), **aucun FormRequest admin** (**FAIT** : aucun `Store/UpdateEnseignantRequest` dans `app/Http/Requests`).
- Portail : `Enseignant\{ConnexionController, ProfilController, DemandeController, ReclamationController, DocumentController, DocumentsController, TableauDeBordController}` + contrôleurs modules (`MonEmploiEnseignantController`, `MesSurveillancesController`, `MesNotesController`, `MesPresencesController`, `AppelController`, `MesRessourcesController`, `MesAnnoncesController`).
- Requests portail : `ConnexionEnseignantRequest:21-27`, `ProfilEnseignantRequest:31-40` (email/bureau/téléphone seuls modifiables), `MotDePasseEnseignantRequest`.
- **Aucune `TeacherPolicy`** (**FAIT** : `ModuleServiceProvider:215-286` lie ~20 policies, aucune pour `Teacher` ; autorisation = Gates par permission via `AppServiceProvider:155` `Gate::define($permission, hasPermission)`). Seule policy du périmètre : `TeacherPresencePolicy` (`Modules/Attendance/Policies/TeacherPresencePolicy.php:12-73`, isolation faculté via créneau puis département).

### 3.4 Modèle et base de données (FAIT)

- `Teacher` (`Modules/Schedule/Models/Teacher.php:26-136`) : table `teachers`, `$hidden(password)`, fillable `nom,prenom,email,password,actif,matricule,grade_id,departement_id,specialite_id,bureau,telephone,user_id,date_embauche,statut:32-36`, casts `actif/boolean, date_embauche/date, password/hashed:41-44`, `faculteId()` via département (NULL = transversal:56-58), relations `grade, departement, specialite, affectationsEnseignement, slots, surveillances, pointages, presencesSeances, user, documents, demandes, reclamations:65-135`.
- Migrations : base `2026_09_29_082856` (nom, prenom, email unique nullable, actif, index nom+prenom) ; extensions `2026_09_29_210249:91-102` (matricule unique, grade/département/spécialité `restrictOnDelete`, bureau, date_embauche, statut défaut `actif`) ; `2026_10_09_110335` (password nullable = compte non activé) ; `2026_10_09_130000` (user_id, telephone + tables demandes/réclamations/documents `cascadeOnDelete`).
- FK entrantes : créneaux `teacher_id` `nullOnDelete` (`082859:19`), exceptions `new_teacher_id` `nullOnDelete` (`082900:21`), affectations d'enseignement `restrictOnDelete` (`220714:49,109`), surveillances `restrictOnDelete` (`evaluation:105,181`), présences/pointages `restrictOnDelete` (`120000:22,44` + durcissement `130000`), ressources/appels/demandes `cascadeOnDelete`, incidents `nullOnDelete`, `presences.teacher_id` nullable `nullOnDelete`, notifications `teacher_id` `cascadeOnDelete`.

### 3.5 Services, import/export, recherche, vues

- `ServiceEnseignantService` (charges prévue/affectée par type, seuils grade, `tableauServices` scopé via département) ; `TeacherDefinition` (`Services/DataExchange/Definitions/TeacherDefinition.php`) : entité `enseignants`, colonnes `nom,prenom,email,matricule,grade,departement,bureau,faculte_code,statut:30`, requises `nom,prenom:35-38`, garde homonymes `validerLigneMetier:65-75` + ceinture `persister:100-107`, résolution serveur grade/département (`resoudreReference:144-163`, **grade auto-créé `firstOrCreate:153-156`**), modes `creer/upsert`, formats `csv,xlsx,json,pdf:175-178`, `requete` eager grade+département, `ligne()` **sans statut/faculte_code:202-213**.
- Recherche globale : `EnseignantsProvider:17-33` (éligible `schedules.view`, matche nom/prénom/email/**matricule**, lien fiche).
- Population : dataset `students_by_teacher` (`PopulationDataset.php:62-68`, permission `enseignants.view`).
- Vues : `admin/enseignants/{index,form,show}.blade.php` ; nav Formation `_navigation.blade.php:90` ; nav portail `config/modules.php:277-317` (6 entrées, `permission: null`, filtre d'activation natif).

### 3.6 Tests, permissions, journaux (FAIT)

- 11 fichiers enseignants/portail ≈ **148 tests** : `EspaceEnseignantTest` 10, `EnseignantPortailTest` 17, `EnseignantNotesTest` 7, `TeacherHomonymeImportTest` 4, `AffectationEnseignantExamenTest` 28, `EmploiEnseignantIntelligentTest` 15, `EspaceEnseignantModulesTest` 16, `AffectationEnseignantsTest` 8, `RessourcesPedagogiquesTest` 7, `PresenceIntelligenteTest` 20, `AppelIntelligentTest` 16 — plus suites surveillance/charge/contraintes/emploi, `SurveillanceChargeEnseignementTest`, `TeacherStudentListTest`, `TeacherAttendance*Test`.
- Permissions (`Support/Permissions.php`) : `enseignants.view|manage:114-115` ; `admin_scolarite` view+manage:308, `admin_faculte` view+manage:456, `agent_scolarite` view seule:369, `consultation` view seule:405, super_admin tout.
- **Aucun appel audit** dans `EnseignantController` (**FAIT** : zéro occurrence `AuditService|IntegrationLog|DB::|lockForUpdate|transaction|peutAccederFaculte|authorize`) ; notifications admin du périmètre : aucune (le portail notifie : affectations, régularisations).

## 4. Inventaire fonctionnel (16 rubriques)

Légende : ✅ opérationnelle · 🟡 partielle · ❌ absente · 🔴 en erreur (prouvée ou quasi-certaine) · ⚪ non vérifiée.

| # | Fonctionnalité | État | Preuves / fichiers |
|---|---|---|---|
| 1 | Liste + fiche | ✅ | `index` (search nom/prénom/email, `withCount(slots)`, `paginate(12)`, `withQueryString`) ; `show` (grade, département, spécialité, charge, créneaux, enseignements, lien listes). |
| 2 | Création / modification / profil | ✅ | `store/update` validés, uniques ignorants au update, mot de passe conservé si vide:62-64,123-126 ; profil portail email/bureau/téléphone (`ProfilEnseignantRequest`). |
| 3 | Identité, matricule, grade, spécialité, statut | 🟡 | Champs persistés et affichés, mais **statut non éditable** au formulaire (M2) et **téléphone jamais validé** côté admin (F3). |
| 4 | Recherche, filtres, tri, pagination, vues | 🟡 | Recherche simple + pagination 12 + état vide ; **sans** filtre statut/département/grade, **sans** tri, **sans** vues sauvegardées, recherche page **sans matricule** (F1). |
| 5 | Import + doublons + erreurs | ✅/🟡 | Pipeline DataExchange (`enseignants`, preview→confirm, `TeacherHomonymeImportTest` 4/4 verts exécutés) ; bémols M3/M5. |
| 6 | Exports | 🟡 | CSV via DataExchange (`index:17`, `data.export`) + listes/exports ; **pas** d'export XLSX/PDF dédié éprouvé ici ; `ligne()` lossy (M3). |
| 7 | Activation / désactivation / archivage / restauration | 🟡 | `actif` (checkbox édition, `EnsureTeacher` refuse inactifs:27, login refuse:46) ; **pas** d'archivage/restauration, **pas** d'effet sur affectations existantes (⚪). |
| 8 | Départements, spécialités, grades | ✅ | Selects alimentés (`optionsReferentiel`), FK `restrictOnDelete`, grades configurables (`GradeEnseignant`), création silencieuse de grades à l'import (M3). |
| 9 | Charges + matières enseignées | ✅ | Fiche : charge h + créneaux + enseignements actifs ; `ServiceEnseignantService::charge/seuils/tableauServices`. |
| 10 | Emplois du temps + conflits | ✅ | Créneaux (`teacher_id`), conflits jour-exact, emploi portail + PDF, exceptions, `MonEmploiEnseignantController`, 15 tests EDT exécutables inventoriés. |
| 11 | Examens + surveillances | ✅ | Assignation/optimisation/runs, emploi des surveillances, mes-gardes, émargement, incidents (`teacher_id`), 28 + ~100 tests suites surveillance. |
| 12 | Historique / audit | ❌ | Zéro trace CRUD enseignant (ni `AuditService`, ni event) — section 3.6. |
| 13 | Notifications / alertes | 🟡 | Côté portail uniquement (surveillances, régularisations) ; **aucune** côté admin (création, import, suppression). |
| 14 | Permissions / isolation | 🟡 | Gates `enseignants.view|manage` + portail session-scopé solides ; mais E2 (global sans scope) + M1 (index sans Gate). |
| 15 | Ergonomie / a11y / responsive | ✅ | `@csrf`, erreurs champ + `x-alert`, `role=search`, `aria-label`, `sr-only`, grilles `sm:`, `min-h-[44px]`, modale de confirmation ; `x-input` sans `aria-invalid` (traité par script global — non ré-audité ici). |
| 16 | Performances / N+1 | 🟡 | Index sobre (`withCount`, 12/lot) ; fiche perfectible (M4). Aucun N+1 chaud mesuré (pas de mesure live authentifiée). |

## 5. Schéma logique des relations

```
grades_enseignants ──< teachers >── departements ──< facultes (via departement.faculte_id, NULL = transversal)
                         │
                         ├── schedule_slots (teacher_id, nullOnDelete) + exceptions (new_teacher)
                         ├── affectations_enseignement (restrict) ──< enseignements ──< matieres (ECUE)
                         ├── surveillances/evaluation (restrict) ── epreuves / sessions
                         ├── teacher_presences / teacher_pointages (restrict) ── slots
                         ├── seance_appels (cascade) ; presences.teacher_id (nullOnDelete)
                         ├── ressources_pedagogiques (cascade) ; teacher_documents/demandes/reclamations (cascade)
                         ├── evaluation_incidents (nullOnDelete, auteur) ; notifications.teacher_id (cascade)
                         └── users (user_id, lien mort : jamais posé — F3)
```

## 6. Sécurité et permissions

**FAIT (conforme)** : back-office sous `auth+admin`, Gates par permission, portail `enseignant.auth` avec résolution `teacher_id` depuis la session (jamais l'URL), login temps constant + message générique + `actif`/password-nul refusés (`ConnexionController:35-50`), `regenerate/invalidate` session, throttles portail (5–30/min), validation systématique, écritures sur données validées seules (mass assignment sûre malgré `user_id/telephone` fillables), CSRF, `{{ }}` échappées, `password` hidden + cast `hashed`.

**FAIT (écarts)** : E2 (global sans scope + `optionsReferentiel` non scopé:97-104), M1 (index sans `can:` — REVUE `audit:routes-securite`), M7 (écritures admin sans throttle), M6 (provider éligible `schedules.view`), E1 (500 FK), M5 (conflit matricule à l'import — **HYPOTHÈSE**).

## 7. Anomalies classées

### Élevée
- **E1 — Suppression 500 sur FK `restrict` non gardées.** `destroy:136-140` ne refuse que `slots()->exists()`. Or affectations d'enseignement (`220714:49,109`), surveillances (`evaluation:105,181`), présences/pointages (`120000`, `130000`) sont en `restrictOnDelete` → `QueryException` brute (500) au lieu d'un refus métier. Repro : enseignant avec une surveillance ou une affectation active → DELETE. **RECOMMANDATION** : étendre la garde (ou rattachement `nullOnDelete` décidé) + test.
- **E2 — Isolation faculté inexistante sur entité globale.** `Teacher::faculteId()` existe mais n'est jamais appelé côté admin ; `admin_faculte` (manage:456) voit/crée/modifie/supprime les enseignants de **toutes** facultés et choisit **tout** département (`optionsReferentiel` non scopé). Le commentaire §5 l'assume (« entités globales »), mais c'est incohérent avec le durcissement salles (restreinte forcée à SA faculté). **RECOMMANDATION** : trancher (global assumé documenté, ou forçage au périmètre comme les salles) + test IDOR A/B.

### Moyenne
- **M1 — Index sans Gate.** `GET admin/enseignants` (web.php:789) sans `can:` alors que `show` exige `enseignants.view`. Tout compte back-office authentifié liste noms/emails. Déjà signalé REVUE par `audit:routes-securite`.
- **M2 — `statut` vs `actif` désynchronisés.** Formulaire sans champ `statut` (création forcée `actif/statut`, édition checkbox `actif` seule — `form:43-48`, `store:66`, `show:21` affiche `statut`). `statut` n'est modifiable que par import. **RECOMMANDATION** : unifier (un seul interrupteur ou mapper l'un sur l'autre).
- **M3 — Asymétries import/export.** Grade auto-créé (`firstOrCreate:153-156`) vs département strict (« jamais d'approximation » contredit) ; `ligne()` omet `statut/faculte_code` déclarés en `colonnes` (export→réimport avec perte) ; `formats` annonce `pdf` (efficacité non éprouvée ici).
- **M4 — Fiche perfectible (perf/maintenabilité).** `$slot->matiere` non eager (`show:74` charge `slots.schedule.groupe` mais la vue lit `matiere`), requête `Enseignement` **dans la Blade** (`show:51`), calcul d'heures par `substr` (`72-80`), collection `slots` entière hydratée pour 10 affichés.
- **M5 — Conflit `matricule` unique non pré-vérifié à l'import (HYPOTHÈSE).** `persister` met à jour par email sans contrôler `matricule` → violation unique DB possible (erreur brute vs erreur métier). À prouver par test.
- **M6 — Provider éligible `schedules.view` au lieu de `enseignants.view`** (`EnseignantsProvider:17-19`) : джерело de visibilité incohérente entre recherche globale et page.
- **M7 — Écritures admin sans throttle** (store/update/destroy) contrairement au reste du back-office.
- **M8 — 3 contrôleurs portail morts** : `app/Http/Controllers/Enseignant/{Surveillance,Presence,EmploiDuTemps}Controller.php` sans aucune route ni test les référençant (canoniques = modules). Poids mort + risque de confusion.

### Faible
- **F1** : recherche page sans `matricule` (le provider global l'a).
- **F2** : pas de tri/filtres avancés, pagination fixe 12 (hors préférences utilisateur).
- **F3** : `telephone`/`user_id` fillables mais jamais alimentés côté admin/import (téléphone modifiable uniquement par l'enseignant lui-même).
- **F4 (HYPOTHÈSE)** : login cherche `email = strtolower()` alors que le store ne normalise pas la casse (risque de doublons/échecs selon collation).
- **F5** : fiche sans liens surveillances/présences/appels, créneaux plafonnés à 10.
- **F6 (transverse)** : 776 routes mesurées vs ~745 documentées (dérive doc, hors périmètre strict).

## 8. Fonctionnalités manquantes ou incomplètes

❌ Archivage/restauration, journal d'audit CRUD, notifications admin (création/import/suppression), endpoints API v1, vues sauvegardées/tri/filtres avancés, import `telephone`, export `statut/faculte_code`, bulk/annulation de lot, dashboard « charges par enseignant » exposé (service existant, exposition UI **non vérifiée**). 🟡 Désactivation : `actif` bloque le portail mais n'empêche ni n'invalide les affectations/surveillances existantes (**non vérifié** — à spécifier avant toute correction).

## 9. Dette technique et performance

- Pas de `TeacherPolicy` (autorisation par permissions plates, suffisante vu l'entité globale, à créer si E2 bascule vers scoping).
- Validation admin inline (pas de FormRequest) ; `show` avec logique en Blade (M4) ; contrôleurs morts (M8).
- Index : ~2 requêtes + pagination 12 — sain. Fiche : ~6–8 requêtes + N+1 `matiere` (≤ créneaux affichés) + 1 requête inline — à eager-charger, pas de chantier.
- Import : `resoudreReference` par ligne (grade/département) — N+1 d'import sur gros volumes (**HYPOTHÈSE**, pattern déjà traité ailleurs par caches anti-N+1) ; plafond `LIGNES_MAX` du framework central non ré-audité ici.
- Aucune mesure live authentifiée annoncée (principe : pas de chiffres sans mesure).

## 10. Tests exécutés et résultats réels

| Test | Résultat réel |
|---|---|
| `TeacherHomonymeImportTest` (garde anti-fusion homonymes, fail-closed) | ✅ 4/4, 8 assertions, 727 ms |
| `EspaceEnseignantTest` (auth, profil, IDOR, périmètre) | ✅ 10/10, 44 assertions, 1 133 ms |
| `curl /admin/enseignants` sans auth | ✅ 302 → `/admin/connexion` (0,38 s) |
| `audit:routes-securite` (périmètre) | 3 REVUE connues (enseignants/salles/groupes index sans Gate) |
| Suites non rejouées (inventaire) | 134 tests portail/intégrations + suites surveillance/charge/contraintes/emploi — se référer à la section 3.6 |

Base de test SQLite éphémère (`RefreshDatabase`) : aucune donnée métier/dev touchée.

## 11. Recommandations (priorité · effort · dépendances)

| # | Recommandation | Priorité | Effort | Dépendances |
|---|---|---|---|---|
| R1 | Garde `destroy` complète (affectations, surveillances, présences) + message métier + test | P1 | S (½ j) | Décision produit : refus vs détachement |
| R2 | Trancher E2 : global assumé (documenter) ou scope faculté (forcer département + tests IDOR A/B) | P1 | M (1–2 j) | Arbitrage métier |
| R3 | `can:enseignants.view` sur `index` (ou Gate explicite) | P2 | XS | R2 |
| R4 | Unifier `actif/statut` (un interrupteur, migration de cohérence si besoin) | P2 | S | R2 |
| R5 | Import : grade strict comme département (ou documenter l'auto-création), `ligne()` complète, preuve PDF, pré-check `matricule` + tests | P2 | S–M | — |
| R6 | Fiche : eager `slots.matiere`, déplacer la requête `Enseignement` au contrôleur, limiter/borner, heures via service | P3 | S | — |
| R7 | Throttles écritures admin + recherche page avec `matricule` + tri/filtres | P3 | S | — |
| R8 | Supprimer les 3 contrôleurs morts (ou les documenter s'ils servent de référence) | P3 | XS | — |
| R9 | Audit trail CRUD + notif admin création/suppression (bus existant) | P3 | M | R2 |
| R10 | API v1 lecture enseignants (si besoin produit ; scopes + BOLA + openapi) | P4 | M | R2 |

## 12. Plan de consolidation proposé (par phases, après approbation)

- **Phase A — Fiabilité (R1, R3, R7)** : gardes suppression, Gate index, throttles. Tests first, zéro migration.
- **Phase B — Cohérence métier (R2, R4)** : arbitrage isolation + interrupteur unique, avec tests IDOR et matrice mise à jour.
- **Phase C — Import/export (R5)** : strictness, parité colonnes, preuve PDF, test conflit matricule.
- **Phase D — Fiche + hygiène (R6, R8, F1–F5)** : eager, contrôleur maigre, suppression code mort.
- **Phase E — Traçabilité/interop (R9, R10)** : audit + notifs + API si besoin confirmé.

*Fin du rapport — attente de votre approbation avant toute correction.*
