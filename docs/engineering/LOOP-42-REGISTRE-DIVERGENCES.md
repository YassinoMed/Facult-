# LOOP 42 — REGISTRE DES DIVERGENCES ET AUDIT DES 52 MATIÈRES SANS UE
## UniRegistre — Classification formelle des 7 typologies, causes racines et plan d'action

**Date :** 31 octobre 2026  
**Auteur :** Antigravity Engine — UniRegistre Core Team  
**Statut de la phase :** AUDITÉ ET CLASSÉ  
**Classification formelle :** 7 typologies institutionnelles exhaustives  

---

## 1. Cadre de Classification des 7 Typologies Institutionnelles

Conformément à la PHASE 42.4 de la Roadmap Master et aux conditions spéciales de l'ADDENDUM LOOP 42, toute divergence observée entre les résultats calculés et les décisions officielles est obligatoirement qualifiée sous l'une des 7 typologies suivantes :

```
                                [CONFRONTATION ACADÉMIQUE]
                                            │
                     ┌──────────────────────┴──────────────────────┐
            Données comparables ?                         Données non comparables ?
                     │                                             │
      ┌──────────────┴──────────────┐                       ┌──────┴──────┐
Concordance ?                 Discordance ?           Règle incertaine ? Source absente ?
      │                             │                       │             │
┌─────┴─────┐        ┌──────────────┴──────────────┐  ┌─────┴─────┐ ┌─────┴─────┐
│CONCORDANT │        │   TYPOLOGIES DE DISCORDANCE │  │DIVERGENCE │ │  SOURCE   │
│(Classe =  │        │ - DIVERGENCE_CALCUL         │  │RÉGLEMEN-  │ │INSUFFI-   │
│  null)    │        │ - DIVERGENCE_MAQUETTE       │  │TAIRE      │ │SANTE      │
└───────────┘        │ - DIVERGENCE_HISTORIQUE     │  └───────────┘ └───────────┘
                     │ - DIVERGENCE_INTERPRETATION │
                     │ - DIVERGENCE_DONNEES        │
                     └─────────────────────────────┘
```

| Typologie Institutionnelle | Définition précise | Seuil d'alerte | Traitement automatique |
|---|---|---|---|
| **1. `DIVERGENCE_REGLEMENTAIRE`** | Règle non publiée au JORT (`REGLE_A_VALIDER`), source manquante ou maquette expirée | Critique | **Interdit (Fail-Closed)** |
| **2. `DIVERGENCE_HISTORIQUE`** | Décision scolarité legacy (`ajourne`) distincte de la délibération officielle normalisée (`admis`) | Majeure | Arbitrage scolarité requis |
| **3. `DIVERGENCE_CALCUL`** | Écart sur la moyenne générale excédant la tolérance décimale de 0.01/20 | Majeure | Révision de la formule de calcul |
| **4. `DIVERGENCE_MAQUETTE`** | Écart sur le volume de crédits ECTS ou le décompte des UEs validées | Majeure | Reconstitution de la maquette |
| **5. `DIVERGENCE_DONNEES`** | Données incomplètes, note manquante ou incohérence d'identifiant | Moyenne | Reprise du dossier source |
| **6. `DIVERGENCE_INTERPRETATION`** | Délibération existante mais maintenue à l'état non publié (`publie = false`) | Moyenne | Validation par le président de jury |
| **7. `SOURCE_INSUFFISANTE`** | Absence totale de décision officielle de référence ou de note | Critique | Non comparable (exclu du taux) |

---

## 2. Audit d'Investigation des 52 Matières sans UE (`ue_id IS NULL` — R42.2)

Conformément à la réserve **R42.2**, une investigation technique exhaustive a été menée sur les 52 matières dont le champ `ue_id` est nul :

### 2.1 Faits Observés
- **Volume** : 52 matières sur les 74 enregistrées dans la table `matieres`.
- **Notes associées** : **527 notes** (sur un total de 553 dans la base) se rapportent directement à ces 52 matières !
- **Attributs existants** : Ces matières possèdent toutes un code standard (ex. `MATH101`, `INFO101`, `ALGB101`), un nom officiel (ex. *Analyse I*, *Algorithmique I*), un `niveau` (`L1`, `L2`, `L3`) et un numéro de `semestre` (`1`, `2`).
- **Absence de liaison** : Aucun enregistrement dans `unites_enseignement` ne pointe vers elles, car elles ont été insérées à une étape antérieure de transition sans rattachement formel à une UE LMD.

### 2.2 Règle Impérative de Sécurité (R42.2)
> [!CAUTION]
> **Interdiction formelle d'attribution automatique par libellé :**
> Aucune unité d'enseignement ne doit être attribuée automatiquement à une matière sur la base de son seul libellé ou de sa ressemblance textuelle.

### 2.3 Mesure Conservatoire
La méthode [`PilotAcademiqueService::auditerMatieresSansUe()`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/app/Services/Acquis/PilotAcademiqueService.php) isole formellement ces 52 matières :
- Statut attribué : `ATTENTE_AFFECTATION_PEDAGOGIQUE`.
- Action requise : Un atelier métier conjoint scolarité centrale + directeurs de départements pour affecter formellement chaque matière à son UE dans la maquette officielle avant tout traitement en masse.

---

## 3. Registre Détaillé des Divergences Relevées

| ID Divergence | Périmètre concerné | Typologie | Gravité | Cause racine | Action corrective | Statut |
|---|---|---|---|---|---|---|
| **DIV-42-01** | Filière IA & Embarqué | `DIVERGENCE_REGLEMENTAIRE` | Critique | Maquette pilote en co-construction en attente d'homologation ministérielle (Réserves RES-40-01/02) | Bloquer toute décision automatique jusqu'à parution de l'arrêté au JORT | **BLOCKED_BUSINESS** |
| **DIV-42-02** | 52 matières historiques | `DIVERGENCE_MAQUETTE` | Majeure | Absence de clé étrangère `ue_id` dans la table `matieres` pour 527 notes historiques | Tenue d'un atelier d'affectation manuelle par la direction des études | **ATTENTE_ARBITRAGE** |
| **DIV-42-03** | 2 074 inscriptions anciennes | `SOURCE_INSUFFISANTE` | Majeure | Inscriptions administratives sans délibération formelle dans `evaluation_decisions` | Préservation du statut d'origine (R42.1 : inscription réputée valide) | **CONSERVATOIRE** |
| **DIV-42-04** | Délibérations en brouillon | `DIVERGENCE_INTERPRETATION` | Moyenne | Délibérations non encore publiées par le jury de délibération | Signature et publication par le président du jury requises | **EN_INSTRUCTION** |

---

## 4. Recommandations Pré-LOOP 43

1. **Aucune généralisation de déploiement** : Le périmètre de déploiement progressif (LOOP 43) devra strictement exclure les formations visées par **DIV-42-01** et les matières visées par **DIV-42-02**.
2. **Garantie d'intégrité** : Maintenir le contrôle déterministe via empreintes SHA-256 pour toute reprise ultérieure.

