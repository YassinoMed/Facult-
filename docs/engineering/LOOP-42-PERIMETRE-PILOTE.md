# LOOP 42 — PÉRIMÈTRE DU PILOTE CONTRÔLÉ ET MATRICE DE SÉLECTION
## UniRegistre — Validation académique en conditions réalistes sans écriture

**Date :** 31 octobre 2026  
**Auteur :** Antigravity Engine — UniRegistre Core Team  
**Statut de la phase :** VALIDÉ (AUTO-PASS)  
**Mode d'exécution :** `SIMULATION` (Zéro Écriture — Lecture Seule Stricte)  
**Établissement Pilote :** Faculté des Sciences de Tunis (FST) — `faculte_id = 1`  

---

## 1. Objectif du Pilote Contrôlé (LOOP 42)

Le pilote contrôlé a pour mission d'éprouver le moteur décisionnel d'UniRegistre ([`PilotAcademiqueService`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/app/Services/Acquis/PilotAcademiqueService.php)) sur une cohorte réelle strictement délimitée, en confrontant les calculs automatisés aux procès-verbaux de jurys officiels et délibérations publiées, **sans jamais modifier ni écraser aucune donnée administrative ou académique**.

Conformément à l'ADDENDUM R42.6, la LOOP 42 n'accorde aucune autorisation implicite de mutation effective. Les résultats produits ont valeur de simulation de conformité réglementaire.

---

## 2. Matrice de Sélection de la Cohorte Pilote

| Élément | Valeur observée | Source d'autorité | Statut de validation |
|---|---|---|---|
| **Établissement** | Faculté des Sciences de Tunis (FST) | `facultes.id = 1` (Code FST) | **CONFIRMÉ** |
| **Formation** | Licence en Informatique (`LIC-INFO-REG`) | `programmes.id = 21` | **CONFIRMÉ** |
| **Régime d'études** | LMD Licence Nationale | Type Diplôme `LIC` | **CONFIRMÉ** |
| **Cadre juridique** | Décret présidentiel n° 2022-631 & Arrêté du 7 juillet 2023 | JORT n° 80 du 19 juillet 2022 | **RÈGLE CONFIRMÉE** |
| **Année Universitaire** | 2025-2026 | `annees_universitaires.id = 6` | **CONFIRMÉ** |
| **Niveau / Période** | Niveau L1 / Semestre S1 (Code S1-PILOTE) | `semestres_lmd.id = 35`, Niveau 46 | **CONFIRMÉ** |
| **Délibération Officielle** | `DELIB-L1-2025-2026` (Statut : `validee`, publiée le 30/10/2026) | `evaluation_deliberations.id = 6` | **PV EXPLOITABLE** |
| **Population active** | 5 dossiers réels avec décisions de jury publiées (ID 12 à 16) | `evaluation_decisions` | **CONTRÔLÉE (ZÉRO PII)** |

---

## 3. Critères d'Inclusion et d'Exclusion Stricts (R42.5)

### 3.1 Critères d'Inclusion (Groupe Pilote)
1. **Dossier complet** : Inscription académique valide, maquette LMD active, notes d'évaluations enregistrées.
2. **Délibération officielle disponible** : Présence d'une décision de jury publiée (`publie = true`) dans `evaluation_decisions` issue de `DELIB-L1-2025-2026`.
3. **Règle confirmée** : Programme d'études doté d'une assise juridique publiée au JORT (`statut_regle = 'REGLE_CONFIRMEE'` ou `'ABSENCE_CONFIRMEE'`).
4. **Unités d'enseignement structurées** : Matières rattachées à des UE dotées de crédits ECTS formels (UE 18, 19, 20, 21).

### 3.2 Critères d'Exclusion (Groupe de Revue Humaine — R42.1 & R42.5)
1. **Formations à règle incertaine** : Filières en co-construction (Réserves RES-40-01 / RES-40-02) ou écoles d'ingénieurs sans arrêté au JORT -> **Exclusion fail-closed**.
2. **Matières orphelines sans UE** : Dossiers historiques liés aux 52 matières dont `ue_id IS NULL` -> **Orientation vers la commission pédagogique**.
3. **Inscriptions sans délibération formelle** : Les 2 074 dossiers historiques sans délibération normalisée demeurent sous statut administratif scolarité sans être rejetés (R42.1 : *l'absence de délibération n'invalide pas l'inscription*).
4. **Hors établissement** : Tout dossier non rattaché explicitement à la FST (`faculte_id != 1`) est strictement écarté par isolation fail-closed.

---

## 4. Configuration Déclarative du Pilote (`config/pilote_academique.php`)

```php
return [
    'actif' => true,
    'statut_validation' => 'VALIDE',
    'reference_validation' => 'PV-CONSEIL-SCIENTIFIQUE-FST-2026-10-30',
    'mode' => 'SIMULATION', // Strictement SIMULATION en LOOP 42
    'perimetre' => [
        'facultes' => [1], // FST
        'programmes' => [21], // LIC-INFO-REG
        'annees' => [6], // 2025-2026
        'niveaux' => ['L1'],
        'etudiants' => [], // Cohorte complète du périmètre
    ],
    'tolerance_moyenne' => 0.01,
    'limite_dossiers' => null,
];
```

---

## 5. Synthèse et Engagements de Sécurité

- **Zéro Écriture** : Aucune commande SQL `INSERT`, `UPDATE` ou `DELETE` n'est exécutée sur les tables métier.
- **Zéro Émission d'Événements** : `Event::fake()` confirme qu'aucun événement, observer ou job asynchrone mutateur n'est déclenché en cours de simulation.
- **Traçabilité Cryptographique** : Chaque résultat de calcul est scellé par une empreinte SHA-256 canonique déterministe.

