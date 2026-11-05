# LOOP 41 — RÉCONCILIATION INVENTAIRE ↔ BASE RÉELLE

**Date :** 31 octobre 2026
**Objet :** confronter les livrables LOOP 41 aux données réellement observées et aux services
réellement exécutés, puis consigner les écarts.
**Mode :** lecture seule sur les données ; les seules écritures sont les correctifs de code décrits §4.

---

## 1. Documents LOOP 41 attendus

| Document attendu | Présent | Statut constaté |
|---|---|---|
| `LOOP-41-INVENTAIRE-HISTORIQUE.md` | Oui | Contenu vérifié contre la base |
| `LOOP-41-MAPPING-REFERENTIEL.md` | Oui | Cohérent avec `cartographierUe()` |
| `LOOP-41-ANOMALIES.md` | Oui | 4 anomalies documentées |
| `LOOP-41-DRY-RUN.md` | **Créé** | Produit à partir d'une exécution réelle (2 152 dossiers) |
| `LOOP-41-RECONCILIATION.md` | **Ce document** | Écarts identifiés et traités |
| `LOOP-41-RESULTATS-TESTS.md` | **Créé** | Exécutions réelles consignées |
| `LOOP-41-VERDICT.md` | **Créé** | Verdict technique et métier distincts |

---

## 2. Vérification de l'inventaire contre la base

Requêtes exécutées via `php artisan tinker` (connexion `mysql`, base `inscription_etudiants`) :

| Table | Documenté LOOP 41 | Observé le 31/10/2026 | Écart |
|---|---:|---:|---|
| `etudiants` | 2 152 | 2 152 | 0 |
| `inscriptions_academiques` | 2 090 | 2 090 | 0 |
| `notes` | 553 | 553 | 0 |
| `matieres` | 74 | 74 | 0 |
| `unites_enseignement` | 11 | 11 | 0 |
| `maquettes` | 1 | 1 | 0 |
| `evaluation_deliberations` | 5 | 5 | 0 |
| `evaluation_decisions` | 16 | 16 | 0 |
| `historique_academique` | 131 | 131 | 0 |
| `matieres.ue_id IS NULL` | 52 | 52 | 0 |
| `notes` hors échelle | 0 | 0 | 0 |
| `inscriptions.decision` NULL | 2 009 | 2 009 | 0 |
| `inscriptions.decision` renseignée | 81 | 81 | 0 |
| Doublons CIN | 0 | 0 | 0 |
| Doublons email | 0 | 0 | 0 |
| Notes `normale` / `rattrapage` | 504 / 49 | 504 / 49 | 0 |

**Conclusion :** l'inventaire LOOP 41 correspond à des données réellement observées. Il n'est pas reconstitué.

---

## 3. Vérification du mapping

`SimulationMigrationHistoriqueService::cartographierUe()` applique effectivement les 3 niveaux décrits :

| Cas testé | Statut retourné | Conforme au document |
|---|---|---|
| Codes identiques | `MAPPING_CONFIRME` | Oui |
| Intitulés identiques, codes absents | `MAPPING_A_VALIDER` | Oui |
| Intitulés proches (similarité > 70 %) | `MAPPING_AMBIGU` | Oui |
| Aucune correspondance | `MAPPING_IMPOSSIBLE` | Oui |

Couvert par `test_exigence3_et_4_mapping_ambigu_bloque_et_ue_similaire_non_assimilee` (vert).

**Limite :** la matrice de correspondance du document décrit des règles de transformation
(`notes >= 10` → ECTS, etc.), mais le service ne transfère rien : il **simule** des crédits à 3 ECTS
par élément validé. Aucune écriture `parcours_plans.credits_acquis` n'est effectuée. Le document ne
doit donc pas être lu comme une migration réalisée.

---

## 4. Écarts détectés et corrections

### ÉCAR-41-01 — ANOM-41-02 non appliquée par le moteur (corrigé)

- **Constat :** `LOOP-41-ANOMALIES.md` §2.2 impose `MIGRABLE_APRES_VALIDATION` lorsqu'un dossier
  requiert un statut officiel sans décision normalisée. Le service classait pourtant ces dossiers
  `MIGRABLE_AUTOMATIQUEMENT_SOUS_REGLE_CONFIRME`.
- **Preuve :** première exécution du dry-run, 2 018 dossiers en migration automatique sans contrôle
  de source officielle.
- **Correctif :** ajout du contrôle `sources_officielles` dans
  `SimulationMigrationHistoriqueService::simulerDossierEtudiant()` :
  - `decision_normalisee_publiee` (délibération publiée),
  - `decision_textuelle_origine` (décision historique non nulle),
  - si crédits > 0 et aucune des deux → `MIGRABLE_APRES_VALIDATION` + avertissement `ANOM-41-02`.
- **Portée :** aucune exclusion, aucune sanction, aucune écriture ; seule la classification change.
- **Tests :** `test_anom41_02_absence_de_decision_officielle_exige_validation`,
  `test_anom41_02_decision_textuelle_origine_suffit_a_la_simulation` (verts).
- **Effet sur le jeu réel :** 0 dossier déclenché, car les 42 étudiants porteurs de crédits disposent
  tous d'une trace officielle (45 décisions textuelles, 14 décisions publiées). Le contrôle reste actif
  pour les jeux futurs.

### ÉCAR-41-02 — Couverture réglementaire surestimée (documenté, bloquant pour le pilote)

- **Constat :** 100 % des dossiers sont évalués `REGLE_CONFIRMEE`, alors qu'aucun des 12 programmes
  réels ne porte un code présent dans `matrice_formations`.
- **Cause :** `evaluerStatutRegle()` retourne `REGLE_CONFIRMEE` dès que le **régime** national est
  identifiable, sans vérifier l'existence d'une fiche de **formation**.
- **Correctif :** méthode additive `ReglementationAcquisService::evaluerCouvertureReglementaire()`
  qui distingue `FORMATION`, `REGIME`, `AUCUNE` et n'autorise l'automatisme que pour `FORMATION`
  confirmée ou absence confirmée. Aucune méthode existante n'est modifiée.
- **Effet :** les 12 programmes réels sont en `REGIME_NATIONAL_CONFIRME` →
  `applicable_automatiquement = false`, `revue_humaine_requise = true`.
- **Conséquence :** le pilote LOOP 42 est `BLOCKED_BUSINESS` tant qu'aucune fiche de formation réelle
  n'est confirmée. Ce n'est pas un défaut technique : c'est l'absence de source métier.

### ÉCAR-41-03 — Volume de notes très faible (constat, sans correctif)

- **Constat :** 553 notes pour 2 090 inscriptions et 2 152 étudiants ; seuls **42 étudiants** portent
  une note validante.
- **Conséquence :** les 2 018 dossiers « migrables automatiquement » n'ont **aucun crédit** à
  transférer. Toute communication chiffrée doit dire « 42 dossiers porteurs de crédits », jamais
  « 2 018 dossiers validés ».

### ÉCAR-41-04 — Absence de commande de dry-run (corrigé)

- **Constat :** le service et ses tests existaient, mais aucune commande reproductible ne produisait
  de rapport archivé.
- **Correctif :** `app/Console/Commands/DryRunMigrationHistoriqueCommand.php`
  (`php artisan acquis:dry-run-migration`), avec contrôle d'intégrité avant/après et sortie sans PII.

---

## 5. Réconciliation des totaux

| Agrégat | Valeur |
|---|---:|
| Dossiers simulés | 2 152 |
| Dossiers avec inscription | 2 052 |
| Dossiers sans inscription (`REVUE_HUMAINE_REQUISE`) | 100 |
| Dossiers avec note sur matière orpheline (`REVUE_HUMAINE_REQUISE`) | 34 |
| Dossiers porteurs de crédits simulés | 42 |
| Crédits simulés reconnus | 1 230 |
| Dossiers sans crédit | 2 110 |
| Erreurs d'exécution | 0 |
| Écritures en base | 0 |

Les 134 dossiers `REVUE_HUMAINE_REQUISE` = 100 sans inscription + 34 avec matière orpheline
(les deux catégories sont disjointes sur le jeu observé).

---

## 6. Ce que la réconciliation ne prouve pas

1. Elle ne prouve pas que les notes historiques sont exactes : elle prouve qu'elles sont présentes et
   cohérentes.
2. Elle ne prouve pas qu'une règle de formation est applicable : aucune fiche réelle n'est confirmée.
3. Elle ne prouve pas que la migration est autorisée : aucune migration n'a été exécutée.
4. Elle ne prouve pas l'absence d'anomalies hors des contrôles implémentés (périmètre limité aux
   contrôles du service).
