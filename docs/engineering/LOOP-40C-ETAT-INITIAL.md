# LOOP 40-C — ÉTAT INITIAL & GEL DES PREUVES
## UniRegistre — Audit Préalable des Services, Règles Métier, Tests et Réserves Ouvertes

**Statut du Livrable :** Document d'Ingénierie & Audit de Référence (Phase 1 - LOOP 40-C)  
**Date d'établissement :** 31 Octobre 2026  
**Branche Git :** `main` (commit d'audit LOOP 40)  
**Contexte :** Consolidation corrective des LOOP 39 et LOOP 40 préalablement à toute migration d'acquis historiques (LOOP 41).

---

## 1. INVENTAIRE DU DÉPÔT ET DES MODIFICATIONS LOCALES

### 1.1 État Git de Référence
* **Branche courante :** `main`
* **Dépôt :** `/Users/mohamedyassine/Desktop/PFE/inscription-etudiants`
* **Intégrité :** Aucune opération destructive (`git reset`, `git clean`, `migrate:fresh`, `db:wipe`) n'a été exécutée. Les modifications préexistantes du dépôt sont rigoureusement préservées.

### 1.2 Fichiers sous Revue Directe

| Composant | Fichier Source | Rôle Métier | Dépendances |
|---|---|---|---|
| **Contrôleur de Droits** | [`app/Services/Acquis/ControleDroitsRedoublementService.php`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/app/Services/Acquis/ControleDroitsRedoublementService.php) | Calcul des droits de redoublement, quotas, congés, dérogations | `ReglementationAcquisService`, `Etudiant`, `InscriptionAcademique`, `HistoriqueAcademique`, `DecisionDeliberation` |
| **Moteur d'Acquis** | [`app/Services/Acquis/CalculAcquisPedagogiquesService.php`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/app/Services/Acquis/CalculAcquisPedagogiquesService.php) | Analyse des crédits capitalisés, compensés, éléments conservés | `ReglementationAcquisService`, `UniteEnseignement`, `Matiere`, `Note` |
| **Plan Redoublement** | [`app/Services/Acquis/PlanRedoublementService.php`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/app/Services/Acquis/PlanRedoublementService.php) | Établissement du contrat pédagogique (unités non acquises uniquement) | `CalculAcquisPedagogiquesService`, `ControleDroitsRedoublementService` |
| **Référentiel Juridique** | [`app/Services/Acquis/ReglementationAcquisService.php`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/app/Services/Acquis/ReglementationAcquisService.php) | Résolution de régime et vérification des statuts de règles | `config/acquis_reglementation.php`, `Programme` |
| **Configuration** | [`config/acquis_reglementation.php`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/config/acquis_reglementation.php) | Matrice des régimes, formations, seuils, quotas et crypto | Fichiers d'environnement `.env` |
| **Tests de Recette 40** | [`tests/Feature/Acquis/RecetteMetierValidationLoop40Test.php`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/tests/Feature/Acquis/RecetteMetierValidationLoop40Test.php) | Recette double calcul 12 dossiers anonymisés | PHPUnit / Laravel TestCase |
| **Tests Régimes 39** | [`tests/Feature/Acquis/CertificationLoop39RegimesAcademiquesTest.php`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/tests/Feature/Acquis/CertificationLoop39RegimesAcademiquesTest.php) | Scénarios limites de redoublement (1 à 10) | PHPUnit / Laravel TestCase |

---

## 2. GÉOGRAPHIE DES TESTS EXISTANTS (SUITE ACQUIS)

L'audit initial constate la présence de **7 classes de tests de fonctionnalité** dans [`tests/Feature/Acquis/`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/tests/Feature/Acquis/) totalisant **94 tests passants** et **1 289 assertions** (temps d'exécution moyen : 16.9s) :

1. `CertificationLoop37FinalAcquisWorkflowTest.php` : Intégrité cryptographique A4, HMAC, canonical JSON, traçabilité.
2. `CertificationLoop38FinalAcquisWorkflowTest.php` : Seuil de compétence sectoriel, dissociation santé/informatique, benchmarks 100/500/1000.
3. `CertificationLoop39RegimesAcademiquesTest.php` : Distinction des 3 métriques, Décret 95-2602 Art. 10 (cycle ingénieur 1 redoublement), Décret 2022-631 (licence 2 redoublements max, 1 en L1), Décret 2008-3123 (mastère).
4. `DecisionAcademiqueWorkflowTest.php` : Workflow de délibération, transitions d'états, rôles et permissions.
5. `GestionAcquisRedoublementTest.php` : Calcul des moyennes, capitalisation des UE et ECUE, compensation.
6. `IndustrialisationAcquisWorkflowTest.php` : Traitement de masse asynchrone, résilience des jobs, non-régression.
7. `RecetteMetierValidationLoop40Test.php` : Double calcul sur les 12 dossiers réels anonymisés (`REC-01` à `REC-12`).

---

## 3. AUDIT DES ANOMALIES, ÉCARTS ET LIMITES IDENTIFIÉES

L'analyse minutieuse des travaux des LOOP 39 et 40 révèle plusieurs écarts fondamentaux nécessitant une correction rigoureuse dans la présente **LOOP 40-C** :

### Écart 1 : Confusion de statut sur le seuil de module de 8.00/20 (Cycle Ingénieur / ENIT)
* **Constat :** Dans `config/acquis_reglementation.php`, `ING_INFO_ENIT` était configuré avec le statut `REGLE_CONFIRMEE` (`statut => 'CONFIRME'`) et la clé `seuil_competence_defaut => 8.0` figurait au niveau général du régime `CYCLE_INGENIEUR`.
* **Analyse Juridique :** Le **Décret n° 95-2602 du 25 décembre 1995** ne contient *aucun article fixant nationalement un seuil éliminatoire de 8.00/20 pour les modules*. L'article 14 renvoie les modalités d'examen à des arrêtés ministériels sur proposition des établissements. Le seuil de 8.00/20 est une règle interne de l'ENIT.
* **Correction Requise :**
  - Supprimer la valeur codée en dur de 8.0 dans le régime général national `CYCLE_INGENIEUR` (`seuil_competence_defaut => null`).
  - Requalifier le seuil ENIT en `REGLE_A_VALIDER` (homologation ministérielle en instance) et ne pas déclarer la conformité légale nationale tant que l'arrêté ministériel spécifique n'est pas versé au dossier.

### Écart 2 : Gestion des quotas indéterminés dans `ControleDroitsRedoublementService`
* **Constat :** Lorsqu'un régime est inconnu ou non configuré, le service attribuait `limite_redoublements_cycle => 0`, ce qui pouvait être interprété comme une interdiction totale (quota zéro) plutôt qu'une incertitude.
* **Correction Requise :** Un quota inconnu doit explicitement être marqué `null` ou indéterminé, et renvoyer le statut formalisé `INDETERMINE` / `REVUE_HUMAINE_REQUISE` sans préjudice pour l'étudiant.

### Écart 3 : Taxonomie des statuts de décision académique
* **Constat :** Le service utilisait des statuts hétérogènes (`LIMITE_DEPASSEE`, `BLOQUE_REGLEMENTATION_INCONNUE`, `AUTORISE_PLEIN_DROIT`).
* **Correction Requise :** Adopter la nomenclature normalisée :
  - `AUTORISE`
  - `NON_AUTORISE_REGLE_CONFIRME`
  - `INDETERMINE`
  - `REVUE_HUMAINE_REQUISE`
  - `DEROGATION_A_VERIFIER`
  - `DECISION_EXCEPTIONNELLE_A_VERIFIER`

### Écart 4 : Contrôle documentaire strict des dérogations
* **Constat :** La vérification des dérogations reposait principalement sur un booléen `$contexte['derogation_accordee']` complété de quelques clés optionnelles.
* **Correction Requise :** Exiger et vérifier l'ensemble des 11 attributs cardinaux de la dérogation (identifiant unique, bénéficiaire, diplôme, établissement, autorité, fondement, référence, date décision, période validité, périmètre, preuve documentaire).

### Écart 5 : Requalification des verdicts sur le corpus REC-01 à REC-12
* **Constat :** Dans la LOOP 40, des dossiers comme `REC-05` (ENIT compensation à 8.50) avaient été qualifiés de `CONFORME_METIER` (100% concordance) sans mentionner que la règle d'école sous-jacente (seuil de 8.00/20) n'a pas encore de source ministérielle publiée au JORT.
* **Correction Requise :** Distinguer la conformité réglementaire absolue (`CONFORME_REGLEMENTAIRE`) de la simple concordance numérique sur règle non certifiée (`CONCORDANT_NON_CERTIFIE`).

---

## 4. REGISTRE DES RÉSERVES OUVERTES HÉRITÉES

Trois réserves institutionnelles formelles restent ouvertes à l'issue de la LOOP 40 :

1. **`RES-40-01` :** Homologation formelle par arrêté ministériel des règlements intérieurs d'écoles d'ingénieurs (seuil module 8.00/20).
2. **`RES-40-02` :** Publication au JORT des arrêtés d'accréditation des nouvelles maquettes co-construites (ex. IA & Embarqué).
3. **`RES-40-03` :** Numérisation et certification cryptographique des archives papier antérieures à 2018.

---

## 5. PLAN D'ACTION DE LA LOOP 40-C

```
                      PLAN D'EXÉCUTION LOOP 40-C
                      ═════════════════════════

  [ Phase 1 : Gel des preuves & État initial ] ──────> Livrable 40C-ETAT-INITIAL
                     │
  [ Phase 2 : Matrice réglementaire par formation ] ──> Livrable 40C-MATRICE-REGLEMENTAIRE
                     │                                (Assainissement ENIT 8.0/20)
  [ Phase 3 : Correction du moteur de droits ] ───────> ControleDroitsRedoublementService
                     │                                (10 comportements & nouveaux statuts)
  [ Phase 4 : Workflow de dérogation ] ───────────────> Sécurité 11 attributs & tests BOLA
                     │
  [ Phase 5 : Requalification REC-01 à REC-12 ] ─────> Livrable 40C-RECETTE-REC01-REC12
                     │                                (5 nouveaux statuts de recette)
  [ Phase 6 : Tests de non-régression ] ──────────────> RecetteMetierCorrectiveLoop40CTest
                     │                                (Suite complète Acquis)
  [ Phase 7 : Documentation & Verdict ] ──────────────> Livrable 40C-VERDICT
```

