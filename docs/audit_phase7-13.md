# Audit Phases 7 → 13 — rapport (section 1 du prompt)

Date : 2026-10-01. Méthode : lecture fichiers, zéro hypothèse.

## 1. Socle technique

- Laravel `^13.17`, PHP `^8.3` (AGENTS.md : 8.4), DB par défaut **sqlite**
  (`DB_CONNECTION`, mysql/pgsql dispo), cache `database`, queue `database`,
  session `database` + encrypt/secure.
- Front : **Blade + Alpine.js 3 + Tailwind v4 + Vite** (`package.json`).
  Ni Livewire, ni Inertia, ni TS. Layout `layouts/app.blade.php`
  (sidebar, navbar, `x-palette`, toasts). 21 composants Blade.
- Auth : guard unique `web` (session `users`) + session `etudiant_auth_id`
  (`EnsureStudent`). Permissions **maison** (`Permissions`, ~140, 5 rôles),
  Gates auto + 7 Policies + modules. Pas de Spatie.
- Conventions : Services (pas d'Actions/Repositories/DTO), 21 FormRequests,
  14 Resources, PHPUnit (sqlite `:memory:`, queue `sync`), FR en dur
  (pas de `lang/`), Pint configuré, **pas de Larastan**.
- Seeders locaux idempotents : 8 facultés, 25 étudiants, 6 comptes,
  7 années, ~35 matières, 5 enseignants/salles, chaîne `DEMO-*`.
  **Volume insuffisant** pour les perfs (§Qualité : ≥ 2 000 étudiants).

## 2. Conflit de numérotation (point d'honnêteté)

AGENTS.md atteste Phases 0→11 **terminées** (Référentiel, Population,
Inscriptions, Enseignement, Évaluation, Délibération/Progression,
Documents, Dossier/Archive/Services, Pilotage/BI/API). Le présent prompt
renumérote autrement (7=UX, 8=Cockpit, …, 12=Planning, 13=Examens).
Verdict d'audit : les périmètres 12 (planning : conflits, DnD,
duplication, publication) et 13 (générateur épreuves/candidats/
surveillants, convocations, délibérations) **existent déjà** et sont
testés. Le travail réel = **boucher les manques prouvés**, pas
reconstruire. Règle d'or appliquée : étendre, jamais dupliquer.

## 3. État par phase du prompt

| Phase prompt | Existant | Manque prouvé |
|---|---|---|
| 7 Palette/recherche | `x-palette` (Ctrl+K, instantanée, clavier actions), `RechercheController` global+instantanée scopées, `RecentsService`+`ElementRecent` (2 écrivains), `VueSauvegardee` (store/destroy), `x-page-header` breadcrumbs, `/`+`N/E/I` | pas de `>`/`?`, résultats serveur hors curseur, instantanée incomplète (Groupes/Salles/Programmes/UE/Annonces absents, liens null Matières/Salles/UE), **0 favoris**, récents absents palette, **0 `user_preferences`**, 0 jump direct CIN, 0 registre d'actions |
| 8 Cockpit | `CockpitController` (tâches, agenda, SLA, activité, récents), widgets modules via composers, `TasksCockpitTest` | 0 cache, 0 lazy/async (SLA filtré en PHP sur `get()` complet), 0 année/semestre global, KPIs non cliquables partout |
| 9 Tâches | `TaskService` (idempotent, `actionGroupee` sync ≤100), `taches:generer` + 6 émetteurs, `ImportReport` + jobs | **0 `bulk_operations`** (suivi/reprise), `actionGroupee` sans job ni rapport |
| 10 Workflows | matrices `TRANSITIONS` par entité + `lockForUpdate` demandes, test fédérateur | **0 moteur générique** (garde/audit/historique partagés) |
| 11 Dossier 360 | `FicheEtudiantService`, `admin.etudiants.show` + actions + export JSON | actions dispersées (pas de registre), timeline 360 ≠ timeline archive (2 implémentations) |
| 12 Planning | Schedule (slots, conflits, DnD `deplacer`, duplication, publication verrouillée), disponibilités `ScheduleExplorerService`, Attendance | disponibilités ignorent les `Epreuve` à venir ; `salleOccupeePlanning` dupliquée côté examens |
| 13 Examens | `SessionPreparationService` (épreuves+salles+surveillants auto), `genererCandidats`, convocations, délibérations verrouillées | génération round-robin `+1 jour` (pas d'optimisation), pas de capacité multi-salles/épreuve |

## 4. Décisions d'architecture (Phase 7 exécutée ci-dessous)

- **Étendre** : `RechercheController` (providers internes, mêmes routes),
  `x-palette` (modes, curseur unifié, récents/favoris), `RecentsService`
  (+lecteur), `VueSauvegardee` (application par défaut au chargement).
- **Créer** (inexistant prouvé) : `SearchRegistry`+providers,
  `QuickActionRegistry`+`x-quick-actions`, `user_preferences` (+page,
  densité, lignes/page), `user_favoris`, endpoint contexte palette,
  `VolumeSeeder`, modale `?`.
- **Ne pas créer** : 2e moteur de recherche, Livewire/Inertia, Spatie,
  système i18n (convention projet = FR en dur ; le prompt §2 exige
  l'inverse — **déviation documentée et assumée** : cohérence dépôt).
- Larastan : non installé (dette, pas bloquant — Pint + PHPUnit font foi).

## 5. Dette connue avant Phase 7

`AssiduiteService::alerterSiSeuilDepasse` sans appelant ; SLA demandes
filtré en mémoire ; suite mono-processus complète fragile (crash
pré-existant, validation par chunks) ; seeders < 2 000 étudiants.
