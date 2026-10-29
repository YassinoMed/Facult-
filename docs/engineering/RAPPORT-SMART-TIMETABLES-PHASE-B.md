# RAPPORT-SMART-TIMETABLES-PHASE-B — Moteur de génération branché (module Schedule)

**Date :** 19/10/2026 · **Protocole :** LAN → DEV → PERFORMANCE → AUDIT → CORRECTION → VALIDATION
**Verdict : PASS_WITH_RESERVATIONS**

---

## 1. Résumé exécutif

La Phase B **branche** le moteur de génération existant (travaux 4/5 du
module, jusqu'ici sans aucun accès HTTP) sur les acquis de la Phase A : les
pondérations déclarées (`planning.generation.*`) et les plages `souhaitee`
(enseignant, co-enseignants, salle, groupe) entrent réellement dans le
scoring, les valeurs appliquées sont **tracées** dans l'historique, l'écriture
est **interdite** sur un planning publié/verrouillé/archivé, et la génération
s'expose en HTTP en **simulation** (zéro écriture) ou en **brouillon** — la
publication reste un acte humain. Aucune table, aucun service dupliqué,
**aucune migration**, permissions existantes.

Un défaut latent bloquant du moteur a été découvert et corrigé :
`preparerDemandesDepuisEnseignements()` filtrait sur `matieres.faculte_id`,
colonne **inexistante** — hors rattachement explicite au groupe, la
génération produisait un brouillon vide. Corrigé via le scope canonique
`Matiere::pourFaculte()` (zéro duplication de règle).

## 2. Réalisation vs plan validé (Phase B)

| Livrable du plan | Statut |
|---|---|
| B1 — pondérations déclarées intégrées au moteur | ✅ livré — paramètres Phase A lus et **appliqués en facteurs autour de leurs défauts** (`poids/5`, `poids/5`, `poids/3`) ; le registre de contraintes reste maître au défaut ; pondération 0 = axe neutralisé ; valeurs appliquées tracées dans `TimetableGeneration.parametres['ponderations']` |
| B1 — préférences `souhaitee` honorées (R2 Phase A levée) | ✅ livré — enseignant + co-enseignants, salle et groupe : bonus dans une plage souhaitée, pénalité hors plage ; comptes déclarés tracés (`preferences_souhaitees`) ; plages **préchargées** (jamais relues par candidat) |
| B2 — garde-fous brouillon | ✅ livré — écriture refusée sur `publie`/`verrouille`/`archive` (`DomainException`, message « aucune publication automatique ») ; **simulation toujours autorisée** (y compris sur planning publié) ; statut du planning jamais modifié par la génération |
| B2 — bornes d'options | ✅ livré — itérations plafonnées par `iteration_max` (50 000), timeout encadré 1-60 s, valeurs aberrantes → défauts sûrs |
| B3 — exposition HTTP brouillon + historique | ✅ livré — page de pilotage (pondérations en vigueur + historique), simulation sans écriture, génération de brouillon, 3 routes gardées (périmètre faculté + `Gate` + throttle), entrée depuis la page du planning |
| « Ne jamais publier un planning automatiquement » | ✅ par conception + garde-fou codé + test dédié (statut inchangé après génération HTTP) |

## 3. Fichiers

**Créés (5 code + 2 docs) :**
- `app/Modules/Schedule/Controllers/ScheduleGenerationController.php` (155 lignes — `index`/`simuler`/`generer` + gardes)
- `resources/views/modules/schedule/generation.blade.php` (171 lignes — pilotage + historique)
- `tests/Feature/ScheduleGenerationMoteurBTest.php` (21 tests — 741 lignes)
- `tests/Feature/ScheduleGenerationPerformanceBTest.php` (2 tests de performance — 277 lignes)
- `docs/engineering/LOOP-SMART-TIMETABLES-PHASE-B.md` + ce rapport

**Modifiés (additif strict) :**
- `app/Modules/Schedule/Services/ScheduleGeneratorService.php` — contexte qualitatif préchargé + garde de fraîcheur, scoring `penalitesCandidat()`, garde-fous statut, bornes d'options, correctif de qualification, correctif du chemin de demande (`pourFaculte()`)
- `app/Modules/Schedule/Routes/web.php` — 3 routes `admin.emplois-du-temps.generation.*` (segments fixes AVANT `{planning}`)
- `resources/views/modules/schedule/show.blade.php` — lien « Génération automatique »

*Aucun fichier de schéma, aucune permission, aucun service existant
réécrit ; le moteur préexistant n'a reçu que des ajouts.*

## 4. Preuves d'exécution

| Commande | Résultat |
|---|---|
| `php artisan test tests/Feature/ScheduleGenerationMoteurBTest.php tests/Feature/ScheduleGenerationPerformanceBTest.php` | **23/23, 111 assertions, 0 échec** (21 moteur + 2 performance) |
| `php artisan test --filter="Schedule" --compact` | **265 tests → 263 OK / 2 skipped / 0 échec** — baseline avant Phase B : 241 tests → 239 OK / 2 skipped ; delta = 21 tests Phase B + 2 performance + 1 test ajouté par un flux concurrent (hors périmètre) |
| `php artisan test tests/Feature/SchedulePhase4MoteurGenerationCspTest.php` | **7/7 (34 assertions)** — garanties moteur préexistantes préservées après branchement |
| `php artisan test --compact` (suite complète) | **3 737 tests → 3 695 OK · 13 échecs · 4 erreurs · 25 skipped · 17 919 assertions** — les **13 échecs sont strictement la liste préexistante** connue (WIP inscriptions/archives/évaluation) ; les 4 erreurs sont **étrangères à la Phase B** (preuve §6 R6) |
| `vendor/bin/pint` (5 fichiers de la phase) | OK (aucun correctif requis) |
| `php -l` (générateur, contrôleur, routes) | OK |

**Couverture livrée (21 tests moteur) :** pondérations tracées · poids des
trous (journée compacte, avec **contre-épreuve** poids 0 vs poids maximal) ·
préférences `souhaitee` **enseignant**, **salle** et **groupe** (chacune avec
contre-épreuve de désactivation) · indisponibilité déclarée toujours
bloquante · audit indépendant sans violation après génération · enseignant
non qualifié jamais affecté · refus d'écriture sur `publie`/`verrouille` ·
simulation autorisée sur planning publié sans écriture · bornes itérations /
budget de temps · statut du planning jamais modifié · chaîne LMD de la
préparation des demandes (matière de la faculté et matière transversale
retenues, **matière d'une autre faculté exclue**) · HTTP : page de pilotage
accessible avec pondérations affichées, lecture seule autorisée / écriture
403 / hors périmètre 404 / module désactivé 404, simulation HTTP sans
écriture (mode tracé), génération HTTP = brouillon sans publication,
refus HTTP sur planning publié, options invalides rejetées sans écriture,
paramètres lus par le contrôleur.

## 5. PERFORMANCE & INTÉGRITÉ

- **Scoring sans coût par candidat** : preuve exécutable livrée — le nombre
  de lectures des plages `souhaitee` est **identique** pour 1 et pour
  10 séances évaluées (garde de fraîcheur + préchargement unique, plus la
  lecture par enseignant de l'audit indépendant) ; un N+1 par candidat
  ferait exploser ce compteur.
- **Mesures sur grille réaliste** (3 jours × 4 créneaux, 2 salles, 1
  enseignant, 1 groupe — fixture dédiée, `simuler()` en dry-run) :

  | Charge | Séances placées | Itérations | Durée | Requêtes SQL |
  |---|---|---|---|---|
  | 1 séance | 1 | 1 | **34,2 ms** | 86 |
  | 8 séances | 8 | 8 | **149,4 ms** | 1 575 |

  Le volume de requêtes est **dominé par la vérification des contraintes
  dures par candidat** (`verifier()` préexistant, ~197 requêtes/séance), pas
  par le scoring qualitatif — la marge reste très large sous le timeout.
- **Intégrité** : aucune écriture dans un planning non modifiable,
  simulation sans trace en base (sauf la ligne d'historique de la
  génération, `dry_run` = true), statut du planning jamais modifié par la
  génération, aucune PII journalisée, bench `Schedule` de la suite vert.
- **Fraîcheur** : la clé de cache du contexte inclut
  `max(updated_at)` des plages souhaitées et des contraintes, plus les
  paramètres appliqués et les jours autorisés.

## 6. Réserves (justifient PASS_WITH_RESERVATIONS)

1. **R1 — Audit de qualité partiel sur les préférences.** Le scoring de
   **génération** honore les préférences enseignant, salle et groupe, mais
   l'**audit indépendant** (`ScheduleConstraintChecker`, axe
   `soft_teacher_preference`) ne score encore que les préférences
   **enseignant** : le « score qualité » affiché après coup peut donc
   sous-estimer le respect des préférences salle/groupe. Cohérence à
   compléter en **Phase C** (aucun comportement bloquant).
2. **R2 — Priorité des demandes non utilisée.** Les `SeanceDemande`
   générées portent une `priorite`, mais l'ordre de placement reste celui
   du domaine + score qualitatif : aucune séance n'est « prioritaire » au
   sens métier. À traiter en Phase C (ordonnancement par priorité).
3. **R3 — Notifications non déclenchées par la génération.** Un brouillon
   généré ne notifie personne (aucun workflow de notification sur la
   création de créneaux) : l'entrée en vigueur reste la publication, acte
   humain existant. Inchangé volontairement (hors périmètre B).
4. **R4 — Chemin de demande dépendant des données pédagogiques.** La
   préparation automatique des demandes exige des `Enseignement` (volumes
   CM/TD/TP) rattachés au groupe **ou** une matière de la faculté via la
   chaîne LMD ; sans maquette ni volumes saisis, la génération produit un
   brouillon vide (le correctif `pourFaculte()` lève le blocage technique,
   pas l'absence de données). Signalé aux utilisateurs par l'historique
   (0 séance à placer).
5. **R5 — Coût préexistant de la vérification dure (dette de performance).**
   ~197 requêtes SQL par séance placée proviennent de
   `estPlacementSansConflit()` (vérification par candidat — conception
   antérieure à la Phase B). Sans impact fonctionnel ici (149 ms pour
   8 séances), mais à mémoïser si les volumes augmentent (Phase C/D).
6. **R6 — Environnement concurrent : échecs étrangers et édition du fichier
   moteur.** Pendant la phase, deux phénomènes hors périmètre ont été
   constatés et documentés :
   - **Édition concurrente du dépôt** : `ScheduleGeneratorService.php` a été
     réécrit par un tiers au moins deux fois pendant la phase (retrait de
     commentaires/dumps, bascule `pourFaculte()` ↔ `whereHas('ue.programme')`).
     L'intégrité finale a été revérifiée (marqueurs Phase B présents, 23/23
     verts, `pint` OK) ; la présente version retient la **sémantique
     canonique** `Matiere::pourFaculte()`.
   - **Échecs étrangers de la suite complète (4)** :
     `PortailEtudiantPublicationTest` ×3 (« 1 conflit(s) bloquent la
     publication ») et `ArchiveRegistrePaginationTest` ×1 (unicité
     `etudiants.num_inscription`). **Attribution prouvée pour les 3 premiers**
     par retour contrôlé à `HEAD` (`git checkout --` des deux seuls fichiers
     concernés, sauvegarde préalable, restauration vérifiée par empreinte) :
     **6/6 tests passaient à l'état HEAD** ; l'échec provient de la règle
     dure `hard_orphan_slot` (« séance sans enseignant affecté ») désormais
     remontée par l'audit indépendant **et** de la garde
     `! ($controle['est_valide'] ?? true)` de `publierPlanning()` —
     code **non commité du flux parallèle** (phases 6-12), sans marqueur
     Phase A/B et absent de `git diff` côté Phase B. Le 4e échec
     (`ArchiveRegistrePaginationTest`) est situé entièrement dans la zone
     inscriptions/archives en WIP (`ActAsScolarite`), sans aucun appel au
     module Schedule. **Non corrigés** (travail étranger) — à arbitrer par
     leur auteur : soit mettre à jour ces tests anciens, soit conditionner
     l'audit « séance sans enseignant » à une option.

## 7. Contraintes respectées

Aucun commit/push/déploiement ; **aucune migration** en Phase B ; aucune
donnée existante modifiée ; cours et examens restent séparés (module
Evaluation intact) ; aucune PII journalisée ; permissions existantes
réutilisées (`schedules.view`/`schedules.manage`, aucune clé créée) ; module
désactivé → 404 préservé ; **aucune publication automatique** — la génération
produit toujours un brouillon soumis à validation humaine.
