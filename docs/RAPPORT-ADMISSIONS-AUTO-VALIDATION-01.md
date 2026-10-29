# RAPPORT FINAL — LOOP-ADMISSIONS-AUTO-VALIDATION-01

## Verdict : `PASS_WITH_RESERVATIONS`

## Baseline avant correction

`docs/BASELINE-ADMISSIONS-AUTO-VALIDATION-01.md`. `AdmissionTest` 8/8.
Anomalie A1 démontrée (refus bloque recours). Aucune donnée touchée,
aucune migration.

## Architecture inspectée

Parcours complet décision → acte → consommation → protection : routes
autorisées par permission, périmètre faculté serveur (étudiant ET
programme → 404), validation fermée (exists, `in:`, motif 500 = colonne),
cohérence niveau∈programme (service + progression), idempotence
triplet, consommation unique verrouillée, actes consommés
insupprimables, audit `historique_academique` sans PII, throttle
conservé (20/10 min).

## Fichiers créés et modifiés

- Modifié : `app/Services/AdmissionService.php` (A1 + R1 : verrou posé,
  violation convertie en `DomainException` déterministe).
- Modifié : `app/Services/AcademicProgressionService.php` (R1 : verrou
  libéré dans la transaction de consommation).
- Modifié : `app/Models/Admission.php` (`verrou_actif` fillable).
- Modifié : `app/Http/Controllers/Admin/AdmissionController.php` (R4 :
  refus journalisés + 404 inchangés).
- Créé : `database/migrations/2026_10_16_210000_ajouter_verrou_actif_admissions.php`
  (colonne nullable + backfill par moteur + unique ; appliquée sur dev :
  0 acte, 559 ms).
- Créé : `tests/Feature/AdmissionAutoValidationTest.php` (**17 tests**),
  `docs/BASELINE-ADMISSIONS-AUTO-VALIDATION-01.md`, ce rapport.
- Démo jetable créée, prouvée, **supprimée**. Rien d'autre touché.

## Anomalies et corrections (boucle §10)

**A1** — Refus antérieur bloque tout recours (preuve : test jetable,
`DomainException`, 1 acte). Cause : garde sans filtre décision,
contraire à `estActive()`. Correction minimale : `where('decision',
'!=', 'refuse')` dans la garde. Régression : `AdmissionAutoValidationTest`
(refus→admis consommable + admis→admis toujours bloqué) + `AdmissionTest`
inchangé vert.

## Tests exécutés et résultats exacts

| Suite | Résultat |
|---|---|
| `AdmissionAutoValidationTest` (17 : A1, 3 décisions, pas d'auto-inscription, champs invalides, pagination, conditionnel consommé+traçé, invité/sans-droit, double soumission, throttle 429, suppression traçée, consommation unique, **R1 verrou/contrainte, R4 refus journalisés/non-loggés**) | **17/17, 72 assertions** |
| `AdmissionAutoValidationTest\|AdmissionTest` | **25/25, 111 assertions** |
| `AdmissionTest` (non-régression) | **8/8** |
| Cumul `AdmissionAutoValidationTest\|AdmissionTest` | **20/20, 90 assertions** |
| `AcademicProgressionTest\|ParcoursAcademiqueTest\|DeliberationPhase6Test` | **70/70** |
| `pint` (fichiers ciblés) | passé, suites rejouées OK |
| `route:list` | 4 routes admissions conformes |

## Contrôles non exécutés

- Concurrence MySQL réelle (verrous `lockForUpdate` non prouvés sous
  SQLite ; garde déterministe relue, course résiduelle documentée).
- Parcours navigateur (aucun navigateur disponible).
- Rollback mid-transaction forcé (aucun déclencheur sans altérer
  `InscriptionService` — hors périmètre, refusé).
- Suite complète projet (ciblée aux modules liés ; 12 échecs connus
  hors périmètre sur d'autres chantiers).

## Migrations et contraintes

Aucune migration créée/modifiée. Table `admissions` : FK + enum +
`idx_admissions_garde`, sans unique — volontaire (historique + consommés
coexistent). Aucune donnée supprimée.

## Risques résiduels

1. **P1 — R1 CLÔTURÉ (décision 16/10/2026 : contrainte implémentée)** —
   `verrou_actif` (unique, NULL multiples admis) + garde applicative +
   violation → `DomainException` déterministe + libération atomique à la
   consommation. Preuves : contrainte DB-level testée, verrou
   posé/nul/libéré testé, migration appliquée sur dev (0 acte).
   Limites : course simultanée stricte non reproductible sous SQLite
   (chemin `catch` revu, non exécuté) ; précondition prod : aucun
   doublon actif (la migration échoue explicitement sinon).
2. **P2 — R2 CLÔTURÉ (décision métier 16/10/2026 : statu quo documenté)**
   — `admis_conditionnel` consommé comme `admis` : vérifié, aucun
   mécanisme de conditions n'existe dans tout le système (délibérations,
   réinscription, BI, éligibilité, progression traitent conditionnel
   comme admis ; « dettes à revalider » informatives ; motif libre
   500 caractères pour le contexte). Bloquer casserait le flux Master et
   contredirait la sémantique système : statu quo conservé, zéro code.
3. **P3 — R3 CLÔTURÉ (décision 16/10/2026 : statu quo documenté)** — CIN
   complète dans le sélecteur de création : vérifié cohérent avec les
   écrans opérationnels (registre, file de traitement, attestations) où
   la CIN sert à identifier les dossiers ; le masquage par permission
   (`exports.personal`) ne vise qu'exports et archives. Page réservée
   aux détenteurs de `admissions.manage`, zéro code.
4. **P3 — R4 CLÔTURÉ (décision 16/10/2026 : refus journalisés)** —
   `admission_operation_refusee` (warning : acteur, cible technique,
   motif contrôlé, IP ; sans PII ni contenu libre) sur périmètre,
   doublon et suppression d'acte consommé. Erreurs 422 et échecs auth
   framework : volontairement non journalisés (bruit / hors périmètre),
   testé.

## Tableau de clôture

| ID | Contrôle | Preuve | Résultat | Réserve |
|---|---|---|---|---|
| C01 | Routes + `view`/`manage` par endpoint | `route:list`, tests 403/302 | PASS | — |
| C02 | Périmètre facultaire (étudiant+programme, liste+CRUD) | tests 404 existants + rejoués | PASS | — |
| C03 | Validation serveur (exists, in, motif, niveau∈programme) | tests champs invalides, 0 acte créé | PASS | — |
| C04 | Idempotence triplet + A1 recours + R1 verrou | tests A1 + doublon + verrou/contrainte | PASS | Course simultanée stricte (chemin catch revu, non exécuté) |
| C05 | Création ≠ inscription auto | comptage inscriptions inchangé | PASS | — |
| C06 | Consommation : admis*/refusé/unique/atomique/réf. inscription | tests conso + existants | PASS | Concurrence MySQL réelle |
| C07 | Suppression : non-consommé traçé, consommé refusé | tests + existant | PASS | — |
| C08 | Audit succès + refus journalisés, sans PII | `historique_academique` + `admission_operation_refusee` assertions | PASS | 422/auth non journalisés (volontaire) |
| C09 | Throttle conservé | test 429 réel | PASS | — |
| C10 | Non-régression progression/LMD | 70/70 + pint | PASS | Rollback forcé non rejoué |

## Recommandations

R1–R4 clôturés. Avant déploiement prod de la migration `verrou_actif` :
vérifier l'absence de doublons actifs sur la base cible (la migration
échoue explicitement sinon — investiguer avant de forcer).
