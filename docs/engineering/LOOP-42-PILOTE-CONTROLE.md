# LOOP 42 — PILOTE ACADÉMIQUE CONTRÔLÉ

**Date :** 31 octobre 2026
**Environnement :** `local` — MySQL `inscription_etudiants` (lecture seule)
**Objet :** préparer un pilote de recalcul académique sur cohorte restreinte, avec double calcul
et comparaison à la référence officielle, sans écriture et sans contournement de validation.

---

## 1. Audit préalable — ce qui existait déjà

| Besoin du pilote | Existant réutilisé | Décision |
|---|---|---|
| Recalcul académique | `MoteurDecisionAcademiqueService::determinerDecision()` | Réutilisé tel quel, aucun doublon |
| Calcul des acquis | `CalculAcquisPedagogiquesService::analyserAcquis()` | Déjà consommé par le moteur |
| Statut réglementaire | `ReglementationAcquisService::evaluerStatutRegle()` | Réutilisé, complété en LOOP 41 par `evaluerCouvertureReglementaire()` |
| Référence officielle | `DecisionDeliberation` (`evaluation_decisions`) | Source officielle unique, champ `publie` respecté |
| Périmètre / activation | `config/loop146.php` (pattern fail-closed), `ModuleRegistry` | Pattern repris, pas de nouveau moteur de flags |
| Audit / traçabilité | `AuditService` | Non appelé : le pilote est en lecture seule, aucune trace à écrire |

**Aucun service de pilote n'existait.** Aucune duplication de moteur, de calcul ou de référentiel
n'a été introduite : `PilotAcademiqueService` compose l'existant.

---

## 2. Préconditions observées sur le jeu réel

Exécution réelle (lecture seule) le 31/10/2026 :

| Contrôle | Valeur |
|---|---:|
| Programmes réels | 12 |
| Programmes avec fiche de formation confirmée (`FORMATION`) | **0** |
| Programmes couverts au niveau régime national (`REGIME`) | 7 |
| Programmes sans régime ni fiche (`AUCUNE`) | 5 |
| Inscriptions académiques | 2 090 |
| Décisions officielles publiées | 14 |
| Taux de couverture officielle maximal | **0,67 %** |

Détail par programme :

| Programme | Couverture | Statut |
|---|---|---|
| `DEMO-LGL` | `REGIME` | `REGIME_NATIONAL_CONFIRME` |
| `LIC-INFO-REG` | `REGIME` | `REGIME_NATIONAL_CONFIRME` |
| `PAR-FDEG-DEG` | `REGIME` | `REGIME_NATIONAL_CONFIRME` |
| `PAR-FLSH-LSH` | `REGIME` | `REGIME_NATIONAL_CONFIRME` |
| `PAR-FSE-EDU` | `REGIME` | `REGIME_NATIONAL_CONFIRME` |
| `PAR-FSIT-ING` | `REGIME` | `REGIME_NATIONAL_CONFIRME` |
| `PTEST` | `REGIME` | `REGIME_NATIONAL_CONFIRME` |
| `PAR-ECO` | `AUCUNE` | `SOURCE_MANQUANTE` |
| `PAR-FMD-DENT` | `AUCUNE` | `SOURCE_MANQUANTE` |
| `PAR-FMP-MED` | `AUCUNE` | `SOURCE_MANQUANTE` |
| `PAR-GEST` | `AUCUNE` | `SOURCE_MANQUANTE` |
| `PAR-INFO` | `AUCUNE` | `SOURCE_MANQUANTE` |

**Conséquence :** même si le pilote était activé, seuls 14 dossiers seraient comparables.
Le pilote n'a donc aucune portée matérielle tant que les règles de formation ne sont pas confirmées.

---

## 3. Configuration livrée

`config/pilote_academique.php` — valeurs par défaut :

| Clé | Défaut | Effet |
|---|---|---|
| `actif` | `false` | Aucun dossier traité |
| `statut_validation` | `A_VALIDER` | Exécution métier refusée |
| `reference_validation` | `null` | Aucune décision institutionnelle invoquée |
| `mode` | `SIMULATION` | Écriture impossible |
| `perimetre.*` | tableaux vides | Cohorte indéterminée |
| `tolerance_moyenne` | `0.01` | Précision décimale technique, pas une règle pédagogique |
| `limite_dossiers` | `null` | Aucun quota inventé |

Aucun seuil, quota, capacité, délai ou RPO/RTO n'est fixé dans ce fichier.

---

## 4. Service livré

`app/Services/Acquis/PilotAcademiqueService.php`

### 4.1 Surface publique

| Méthode | Rôle |
|---|---|
| `etat()` | Configuration courante, sans exécution |
| `verifierPreconditions()` | Tous les blocages, pas seulement le premier |
| `construirePerimetre()` | Dossiers retenus + dossiers exclus avec motif |
| `comparerDossier()` | Double calcul d'un dossier |
| `executerSimulation()` | Rapport complet ; un blocage n'est pas une exception |
| `indicateurs()` | Taux à numérateur / dénominateur explicites |

### 4.2 Blocages fail-closed

| Code | Condition |
|---|---|
| `PILOTE_INACTIF` | `actif=false` |
| `VALIDATION_INSTITUTIONNELLE_MANQUANTE` | `statut_validation ≠ VALIDE` |
| `REFERENCE_VALIDATION_MANQUANTE` | `reference_validation` vide |
| `PERIMETRE_VIDE` | Aucun filtre de périmètre |
| `MODE_NON_SUPPORTE` | `mode ≠ SIMULATION` |

Les cinq blocages sont cumulés : le rapport expose toutes les raisons, pas la première seulement.

### 4.3 Périmètre et isolation

Filtres appliqués en ET : facultés, programmes, années, niveaux (insensible à la casse), étudiants.
Après la requête, chaque dossier est **revérifié** :

- faculté de l'étudiant hors liste ⇒ `HORS_PERIMETRE_FACULTE` ;
- programme de l'inscription hors liste ⇒ `HORS_PERIMETRE_PROGRAMME` ;
- inscription sans étudiant ⇒ `DOSSIER_ORPHELIN`.

Un dossier exclu n'est jamais réaffecté à un autre périmètre.

### 4.4 Double calcul

| Dimension | Source moteur | Source officielle |
|---|---|---|
| Crédits | `credits.capitalises` | `evaluation_decisions.credits_obtenus` |
| UE validées | statuts `CAPITALISEE` + `VALIDEE_PAR_COMPENSATION` | `evaluation_decisions.ues_validees` |
| Décision | `inscriptions_academiques.decision` (historique) | `evaluation_decisions.decision` (normalisée) |
| Moyenne | `inscriptions_academiques.moyenne_annuelle` | `evaluation_decisions.moyenne` |

La décision et la moyenne ne sont comparées que si **les deux** sources sont renseignées.
Comparaison de décision : insensible à la casse et aux espaces. Comparaison de moyenne :
tolérance `0.01`, documentée comme précision décimale.

### 4.5 Les 7 classes de divergence

| Classe | Définition |
|---|---|
| `ECART_CREDITS` | Seuls les crédits diffèrent |
| `ECART_UES` | Seul le nombre d'UE validées diffère |
| `ECART_DECISION` | Seule la décision officielle vs historique diffère |
| `ECART_MOYENNE` | Seule la moyenne diffère au-delà de la tolérance |
| `ECART_MULTIPLE` | Au moins deux dimensions diffèrent |
| `REFERENCE_NON_PUBLIEE` | Décision existante mais `publie=false` ⇒ non comparable |
| `REFERENCE_ABSENTE` | Aucune décision pour cette inscription ⇒ non comparable |

La concordance n'est pas une classe : c'est l'absence d'écart mesurable sur une référence publiée.
Aucune classe ne produit d'exclusion, de sanction ou d'écriture.

### 4.6 Indicateurs

| Indicateur | Formule | Dénominateur |
|---|---|---|
| `taux_concordance` | concordants / **comparables** | Références publiées uniquement |
| `taux_couverture_reference` | comparables / **analyses** | Tous les dossiers analysés |
| `repartition_classes` | Comptage des 7 classes, zéro initialisé | — |
| `dossiers_non_comparables` | analyses − comparables | — |
| `dossiers_exclus_hors_perimetre` | Taille de `perimetre.exclus` | — |

Un dénominateur nul donne `valeur = null`, jamais `0` ni `100 %`.

### 4.7 Sécurité et vie privée

- Aucun appel `create/save/update/delete/insert` : vérifié par grep, `NO_WRITE_CALLS`.
- Aucun champ `nom`, `prenom`, `cin`, `email` dans le service : `NO_PII_FIELDS`.
- Les résultats n'exposent que `inscription_id`, `etudiant_id`, `faculte_id`, `programme_id`,
  `annee_universitaire_id`, `niveau`.
- En cas d'exception technique, seuls l'identifiant d'inscription et le type d'exception sont
  conservés ; le message n'est pas exposé (il peut contenir des données).

---

## 5. Preuve d'exécution sur l'environnement réel

```php
$svc = app(PilotAcademiqueService::class);
$svc->etat();                 // actif=false, statut=A_VALIDER, perimetre_configure=false
$svc->verifierPreconditions(); // autorise=false
$svc->executerSimulation();    // statut=BLOQUE, 0 dossier
```

Blocages retournés :

```
PILOTE_INACTIF
VALIDATION_INSTITUTIONNELLE_MANQUANTE
REFERENCE_VALIDATION_MANQUANTE
PERIMETRE_VIDE
```

Aucun dossier n'a été lu en exécution métier, aucune écriture n'a eu lieu.

---

## 6. Ce que le pilote ne fait pas

1. Il n'active aucun périmètre : la configuration par défaut est vide.
2. Il ne contourne pas la validation institutionnelle : les préconditions sont vérifiées à chaque appel.
3. Il n'écrit rien, même si `mode=ECRITURE` : ce mode est refusé, pas implémenté.
4. Il ne conclut pas à la conformité réglementaire : `regle_formation_confirmee` est `false` pour
   les 12 programmes réels (0 fiche `FORMATION`).
5. Il ne traite pas les 2 076 inscriptions sans décision publiée : elles sont classées
   `REFERENCE_ABSENTE`, pas interprétées.

---

## 7. Reproductibilité

```bash
php artisan test tests/Feature/Acquis/PiloteAcademiqueLoop42Test.php --compact
php artisan test --filter="Acquis" --compact
vendor/bin/pint --test app/Services/Acquis/PilotAcademiqueService.php \
  config/pilote_academique.php tests/Feature/Acquis/PiloteAcademiqueLoop42Test.php
```
