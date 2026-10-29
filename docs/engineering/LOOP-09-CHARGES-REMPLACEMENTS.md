# LOOP-09 — CHARGES D'ENSEIGNEMENT ET REMPLACEMENTS

> **Verdict : PASS** (conditions et réserves en fin de rapport)
> Date : 25/10/2026 — protocole audit-first, réutilisation maximale, 0 commit/push/déploiement.
> HEAD `38ef149` **inchangé** pendant tout le lot. Chaîne : … L07 → L01 → L06 → **L09** → L08…

---

## 1. Périmètre (entrée audit §F9, L122-128)

**Statut d'entrée : EXISTANT_PARTIEL / À_CLARIFIER.** Manques confirmés
par l'audit (re-vérifiés ce jour : toujours absents) :

1. **candidature de remplacement pédagogique** — aucun volontariat
    enseignant (grep suppléance = 0) ; seul existait le remplacement
    admin-piloté : `ScheduleRepairService::suggererRemplacants` +
    `remplacerEnseignant` (transactionnel, historisation
    `ScheduleChange`, gardes qualification/disponibilité) ;
2. **charge() ignore l'EDT** — `ServiceEnseignantService::charge()`
    ne voit que les affectations contractuelles ; aucune imputation
    ou vue EDT.

## 2. Parti pris minimal (réutilisation maximale)

- **Le volontariat ne duplique AUCUNE mécanique de remplacement** :
  l'acceptation d'une candidature invoque `remplacerEnseignant` tel
  quel (gardes + historisation + transaction existantes) dans une
  transaction commune — échec du remplacement = candidature reste
  soumise, EDT inchangé.
- **Le pont EDT n'impute rien silencieusement** : `chargeAvecEdt`
  expose `planifie_hebdo` (calcul unique réutilisé :
  `Teacher::chargeHebdoPlanifiee`, coefficient de parité) à côté de
  la charge contractuelle — jamais fusionnées (philosophie
  « jamais de volume inventé » du LOT 12 appliquée aux heures).
- Zéro permission nouvelle (`schedules.view/manage` existantes ;
  portail enseignant par session), 1 migration additive.

## 3. Réalisations

- **Migration** `2026_10_25_150000_lot09_candidatures_remplacement.php` —
  table `remplacement_candidatures` (teacher, matière, message,
  statut `soumise|acceptee|ecartee|retiree`, décision + motif, decided_by/at).
  Additive ; rollback = drop (l'historique des remplacements vit
  déjà dans `schedule_changes`). Non appliquée en local (APP_ENV
  production), exécutée par sqlite en test.
- **Modèle** `RemplacementCandidature` (STATUTS, casts, relations,
  `estSoumise`).
- **Service** `CandidatureRemplacementService` — `deposer`
  (actif + qualification + doublon actif refusés), `retirer`
  (propriétaire uniquement), `accepter` (matière du créneau alignée +
  remplacement EDT réutilisé sous transaction commune), `ecarter`
  (motif conservé), `activesPourMatiere`.
- **`ScheduleRepairService::suggererRemplacants`** — priorisation des
  candidats déclarés (+30, flag `candidat_declare`, justification) ;
  touche chirurgicale, tri et gardes inchangés.
- **`ServiceEnseignantService::chargeAvecEdt`** — pont EDT→charge
  (clé additive `planifie_hebdo`, contractuel intact).
- **Contrôleurs/routes** : portail enseignant
  (`enseignant/remplacement-candidatures` GET/POST/DELETE, session
  `enseignant.auth`, throttle 10/min) ; back-office
  (`admin/remplacement-candidatures` registre `schedules.view`,
  accepter/écarter `schedules.manage`, throttle 20/min).
- **Tests** `tests/Feature/RemplacementCandidatureTest.php` (7 tests,
  39 assertions) : gardes de dépôt, retrait propriétaire (IDOR
  enseignant), acceptation = remplacement réel + `ScheduleChange`
  historisé, refus matière divergente / remplaçant devenu inactif
  (candidature préservée), priorisation des suggestions (cap 100
  honoré), pont EDT séparé, flux HTTP complet (portail + admin +
  403 sans `schedules.manage`).

## 4. Preuves

| Vérification | Résultat |
|---|---|
| `php -l` fichiers du lot | Aucune erreur |
| `vendor/bin/pint` (zone du lot) | PASSED |
| Suite du lot `RemplacementCandidatureTest` | **7/7 PASSED, 39 assertions** |
| **RUN CANONIQUE LOT 09** (5 suites : RemplacementCandidatureTest, SchedulePhase8ReparationRemplacementsTest, EnseignementPhase4Test, AffectationEnseignantExamenTest, SurveillanceEquilibrageTest) | **65/65 PASSED, 296 assertions, ~3,1 s** |
| HEAD / commits | `38ef149` inchangé, 0 commit, 0 push |
| Migrations | **1 additive** (rollback dans l'en-tête), non appliquée en local |
| Tests ignorés | 0 |

Docs : `MODULE-SCHEDULE.md` (§ candidatures) + `MODULE-ENSEIGNEMENT.md`
(§ pont EDT → charge).

## 5. Verdict

**PASS.** Les deux manques nommés par l'audit sont livrés par câblage
réutilisatif : volontariat enseignant dont l'acceptation délègue au
remplacement EDT transactionnel existant, et pont EDT→charge en
diagnostic séparé — zéro duplication, zéro permission nouvelle, une
migration additive documentée.

## 6. Conditions d'application

1. Déployer la migration `2026_10_25_150000` (additive, rollback = drop).
2. Aucune configuration ; les endpoints sont actifs au déploiement.

## 7. Réserves

- **R-09/1** — Pas d'UI de candidature (endpoints + registre JSON
  prêts ; formulaires portail/admin à venir).
- **R-09/2** — L'acceptation ne prend pas encore en compte la charge
  du remplaçant (`chargeAvecEdt` est disponible pour l'arbitrage
  admin ; un contrôle automatique de surcharge à l'acceptation serait
  une extension métier).
- **R-09/3** — Candidature par matière (pas par créneau) : choix
  volontariste (volontariat durable) ; le rapprochement créneau par
  créneau passe par les suggestions.
- **R-09/4** — `charge()`, `seuils()` et `tableauServices()` restent
  purement contractuels ; l'écart planifié/affecté n'est pas encore
  surfacé dans le tableau de bord (données disponibles).
- Héritage : R-03, R-04, R-07, R-01, R1-R4.
