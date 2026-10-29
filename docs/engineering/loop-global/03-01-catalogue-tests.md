# LOOP GLOBAL 03.1 — Catalogue réel des tests

**Date :** 2026-10-25 · **Source :** énumération `tests/**/*Test.php` + greps
de gardes. Aucun test modifié.

## 1. Volumétrie réelle

| Dimension | Valeur |
|---|---|
| Fichiers de test | **542** (525 `tests/Feature`, 17 `tests/Unit`) |
| Méthodes `test_*` | **4 748** |
| Utilisant `RefreshDatabase` | 508 fichiers |
| Suites déclarées phpunit | Unit, Feature |
| Tests auto-skip sans opt-in (effet externe) | **12 fichiers** (concurrence MySQL, backup roundtrip, concurrence examens réelle) |

## 2. Partition d'exécution (9 groupes disjoints, ordre du mandat)

| Groupe | Périmètre (mandat LOOP-03.2) | Fichiers | Association F-xx dominante |
|---|---|---|---|
| G1 | Authentification, MFA, PIN, sessions, rôles, permissions, sécurité | 33 | F-01, F-02, F-03 |
| G2 | Inscriptions, dossiers, portail étudiant, portail enseignant | 98 | F-08, F-09, F-10, F-11 |
| G3 | Référentiel LMD, maquettes, compétences, imports, exports | 64 | F-04→F-07 |
| G4 | Évaluations, examens, notes, publication, délibérations | 39 | F-14, F-15 |
| G5 | Documents, coffre, attestations, QR, archives | 44 | F-09, F-17, F-18 |
| G6 | EDT, salles, groupes, affectations, charges | 45 | F-12, F-13, F-29 |
| G7 | Notifications, campagnes, Data Mesh, intégrations, async | 27 | F-19→F-22, F-35 (socle) |
| G8 | Assistant IA, simulateur, décrochage, assurance qualité | 10 | F-24, F-26, F-27, F-28 |
| G9 | Cockpit KPI, workflows, finances, sauvegardes, Sentinel, versioning, annonces, calendrier, aide, divers + Unit | 182 | F-21, F-23, F-30→F-33, F-38→F-40 |
| **Total** | | **542** | |

Partition produite par script (appartenance au premier motif trouvé,
restant → G9) ; listes de fichiers : `/tmp/g{1..9}.txt`. Chaque fichier
appartient à EXACTEMENT un groupe (déduplication garantie par construction).

## 3. Tests à exigences d'environnement spécifiques

| Exigence | Fichiers | Comportement sans environnement |
|---|---|---|
| MySQL dédié (concurrence) | 5 (`Maquette*Concurrence*`, `AffectationConcurrence*`, `CentreTravailConcurrence*`, `SettingsCacheRace*`) + `ExamensConcurrenceReelleTest` | `markTestSkipped` (comptés « skipped », jamais en échec) |
| `mysqldump` + opt-in | `BackupRoundtripTest` | `markTestSkipped` |
| Navigateur (Dusk/E2E) | **0 détecté** | UX navigateur = NOT_EXECUTED (aucune dépendance installée) |
| Fournisseur externe réel | 0 (fakes/array driver) | — |
| Redis | 0 (cache array) | — |

## 4. Filtres réellement supportés

`php artisan test <fichiers…>` (multi-chemins) et `--filter=` vérifiés
utilisés par les sessions précédentes du dépôt. L'exécution par listes de
fichiers explicites est retenue (pas d'ambiguïté de correspondance de nom).

**Prêt pour LOOP-03.2** — exécution séquentielle G1→G9, une seule suite à
la fois (aucune parallélisation sur base partagée ; chaque test a sa
sqlite `:memory:` propre de toute façon).
