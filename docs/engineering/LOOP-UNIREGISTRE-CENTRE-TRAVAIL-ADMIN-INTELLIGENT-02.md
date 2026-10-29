# LOOP — Centre de travail administratif intelligent (02 — contre-audit et compléments)

> Date : 23/10/2026 · Complément de `LOOP-UNIREGISTRE-CENTRE-TRAVAIL-ADMIN-INTELLIGENT-01.md`
> (programme concurrent, Lot 0 audit-only puis sous-lots livrés). Rôle du présent document :
> **confronter au code actuel** les affirmations du programme -01, vérifier le Centre livré
> (`admin.centre-travail.*`) contre les critères du cahier des charges, corriger les défauts
> confirmés **non décisionnels**, et consigner matrice, preuves et verdict.

## A. Synthèse exécutive

- **Objectif** : Centre de travail administratif unifié pour le contrôle/validation des inscriptions.
- **État initial vérifié** : le Centre existe déjà et est **structurellement conforme** — routes
  `admin.centre-travail.{index,show}` (routes/web.php:566-570), contrôleur en lecture seule stricte,
  service d'éligibilité en façade des services canoniques, isolation facultaire étanche, pagination,
  filtres validés serveur, tâche liée par `cle_unique` canonique.
- **Défaut confirmé et corrigé ici** : les 6 cartes d'indicateurs étaient de simples `<div>` sans
  lien — les compteurs ne correspondaient à aucune liste filtrée (critère 13.2 du cahier des charges,
  LOT 1.2.B « chaque indicateur mène à une liste filtrée exacte »).
- **Verdict** : `PASS_WITH_RESERVATIONS` (détails §H).

## B. Inventaire et matrice de couverture (LOT 0.5 — vérifié dans le code, pas recopié)

| Fonction | Implémentation existante (vérifiée) | Source de vérité | Tests existants | Risque | Action |
|---|---|---|---|---|---|
| File de travail | `CentreTravailController::index` — Task + filtres validés + tri priorité/échéance/FIFO + pagination 15 | table `tasks` (`Task::STATUTS/TRANSITIONS`) | `CentreTravailAdminTest`, `CentreResolutionTest` | Faible | Aucune |
| Contrôle d'inscription | `CentreTravailController::show` → `InscriptionEligibilityService::evaluer()` (dry-run strict) | façade DI : `PeriodeInscriptionService`, `BlocageInscriptionService`, `DocumentsRequisService` (source FQCN tracée par contrôle) | `CentreTravailDetailTest` | Faible | Aucune |
| Validation métier | `InscriptionService::transiter()` — verrou + re-vérification sous verrou, transitions terminales ; **absent du Centre** (aucune validation implicite) ✓ | `InscriptionService::TRANSITIONS` | `InscriptionResumeValidationTest` (création), suites gestion | Décisions M-02/M-03 **ouvertes** (programme -01) | Bloqué (décision) |
| Permissions | `tasks.view` \| `archives.view` (index et show) + `peutAccederFaculte()` fail-closed (404) | `Permissions.php`, `User::peutAccederFaculte()` | suites du programme -01 | Faible | Vérifiées par tests nouveaux |
| Affectation | `AffectationService::affecter` (verrous ordre fixe, idempotence) + `AffectationAutomatiqueService::tenter` + runs `AssignmentSimulation` — **non déclenché par le Centre** ✓ | `AffectationService` | `AffectationMoteurTest`, `AffectationAutomatiqueJobTest` | Faible | Aucune |
| Notifications | Non déclenchées par le Centre ✓ ; service canonique `NotificationService` (dédup `cleDedup`) / `CommunicationDispatcher` (dédup 60 min) | idem | `CentreNotificationsPortailTest` | Faible | Aucune |
| Audit | `show` affiche `HistoriqueAcademique` (lecture) ; les écritures restent tracées par les services métier | `AuditService::tracer`, modèle immuable | suites globales | Faible | Aucune |
| Performance | 6 requêtes KPI (counts indexés sur `tasks`), eager `with(['faculte','assigne'])`, pagination serveur ; show : `loadMissing` ciblé + `evaluer()` | — | — | Faible | Mesures §F |
| **Indicateurs → listes** | **DÉFAUT** : 6 cartes `<div>` sans lien (vue l.49-88) | — | **aucun** | **C2 (clarté/cohérence)** | **Corrigé ici (§C)** |

Éléments bloqués (décisions métier du programme -01, non inventées) : M-01 (sémantique « traité »),
M-02 (verdict BLOQUANT vs validation), M-03 (correction approuvée bloquante), M-06/M-07 (SLA
dossiers), D-14 (statut « contrôle indisponible »).

## C. Défaut confirmé et correction

### Defect CT-01 — Indicateurs sans liste exacte (critère 13.2, LOT 1.2.B)

**Preuve initiale** : `resources/views/admin/centre-travail/index.blade.php:49-88` — six
`<div class="rounded-2xl …">` affichant `$kpis['…']`, aucun `href` ; aucun test ne couvrait la
correspondance compteur↔liste.

**Correction (additive, réversible)** :
1. `CentreTravailController` : le filtre `priorite` accepte désormais la valeur composée
   `critical_high` (validation `in:…,critical_high` + `whereIn('priorite', ['critical','high'])`)
   — seul moyen d'obtenir un lien **exact** vers l'indicateur « Prioritaires » (critical OU high) ;
   l'option correspondante est ajoutée au sélecteur de la vue.
2. Vue : chaque carte (sauf l'agrégat « Clôturées récemment ») devient un lien
   `<a>` vers sa liste exacte :
   - En attente → `?statut=pending`
   - En cours → `?statut=in_progress`
   - Prioritaires → `?statut=actives&priorite=critical_high`
   - Souffrance > 48 h → `?statut=actives&souffrance=1`
   - Bloqués → `?statut=actives&bloque=1`
   - Clôturées récemment : **non lié** (agrégat 7 jours, sans filtre serveur équivalent — réserve
     documentée, même convention que la file de traitement pour ses agrégats).
3. Tests `CentreTravailKpiLinksTest` : égalité **compteur KPI = pagination totale** de la liste liée
   pour les 5 indicateurs liés, filtre `critical_high` accepté et exact, refus des valeurs hors
   liste blanche (régression), présence des liens accessibles.

## D. Modifications

| Fichier | Modification |
|---|---|
| `app/Http/Controllers/Admin/CentreTravailController.php` | Filtre `priorite=critical_high` (validation + `whereIn`) |
| `resources/views/admin/centre-travail/index.blade.php` | Cartes KPI → liens exacts + option du sélecteur |
| `tests/Feature/CentreTravailKpiLinksTest.php` | **Nouveau** |
| `docs/engineering/LOOP-UNIREGISTRE-CENTRE-TRAVAIL-ADMIN-INTELLIGENT-02.md` | Ce rapport |

Aucune migration, aucune dépendance, aucune table, aucune écriture métier, aucun nettoyage,
aucune opération destructive. Le WIP préexistant (557 entrées) est intégralement préservé.

## E. Sécurité et métier (contre-audit du Centre livré)

| Contrôle | Résultat |
|---|---|
| Authentification + permissions serveur (index et show) | `abort_unless(tasks.view \|\| archives.view, 403)` côté serveur ✓ |
| Périmètre facultaire (index) | Restriction imposée (filtre utilisateur écrase `faculte_id` client) ✓ |
| Périmètre facultaire (show, anti-IDOR) | 404 si `! peutAccederFaculte()` ; fail-closed pour `faculte_id` null (0 ≠ restreinte) ✓ |
| Validation implicite à l'affichage | Aucune — `evaluer()` en dry-run, aucune écriture ✓ |
| Écritures via le Centre | Aucune route d'écriture (lecture seule) ✓ |
| Validation des filtres | `$request->validate` liste blanche (statut/source/priorité/assignation/…) ✓ |
| Fuite via recherche | Recherche limitée aux champs de la tâche du périmètre ✓ |
| Erreurs techniques ≠ validation | `evaluer()` retourne des contrôles structurés avec `source` ; aucune exception transformée en verdict favorable ✓ |
| Audit | Les actions de validation restent dans les services canoniques (`HistoriqueAcademique` immuable) — le Centre n'en crée pas de parallèle ✓ |

## F. Tests et performance

Commandes et résultats réels : voir §G (journaux d'exécution). Mesures de requêtes : 6 requêtes
KPI + pagination ; aucun N+1 détecté à la lecture (eager loading ciblé). Pas de benchmark de gain
annoncé (aucune optimisation effectuée).

## G. Anomalies restantes

| Priorité | Anomalie |
|---|---|
| **P1** | M-02 : une validation via la fiche gestion n'est pas gardée par le verdict `BLOQUANT` du moteur (décision ouverte chez le programme -01) — risque d'incohérence si contournée hors Centre |
| **P1** | M-03 : une correction approuvée non appliquée ne bloque pas la validation (décision ouverte) |
| **P2** | Indicateur « Clôturées récemment » non cliquable (agrégat sans filtre exact) — convention documentée |
| **P2** | M-01 : sémantique « traité » provisoire (`Etudiant.valide` + groupe) |
| **P2** | `NotifierPeriodeOuverte` : boucle synchrone ≤ 500 étudiants (hors Centre, noté par l'audit transversal) |
| **P3** | Divers compteurs transversaux (dashboard/cockpit/file) issus de builders différents — réconciliés file↔Centre seulement |

## H. Verdict final

**`PASS_WITH_RESERVATIONS`** — le Centre livré satisfait les critères structurels (13.1, 13.3, 13.4,
13.5, 13.6, 13.7, 13.10, 13.12, 13.14 vérifiés dans le code et par les suites du programme -01
rejouées) ; le défaut 13.2 (indicateurs ↔ listes) est corrigé et prouvé par de nouveaux tests.
Réserves : décisions métier M-01/M-02/M-03 bloquées (non inventées), indicateur agrégat non cliquable
(documenté), suite complète globale non exécutée (aucun PASS global revendiqué). Aucun commit.
