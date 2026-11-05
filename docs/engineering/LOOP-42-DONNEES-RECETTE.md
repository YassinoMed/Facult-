# LOOP 42 — JEU DE DONNÉES DE RECETTE ET CORPUS PILOTE
## UniRegistre — Spécification du corpus d'évaluation, anonymisation et séparation des sources

**Date :** 31 octobre 2026  
**Auteur :** Antigravity Engine — UniRegistre Core Team  
**Statut de la phase :** AUDITÉ ET CONFORME  
**Sécurité :** Zéro PII — Données anonymisées et isolées  

---

## 1. Principes de Constitution du Jeu de Recette

Le jeu de données de recette de la LOOP 42 a été constitué dans le respect strict des directives suivantes :
1. **Zéro PII (Personally Identifiable Information)** : Aucun nom, prénom, numéro de CIN réel ou email personnel n'apparaît dans les données de test, les configurations ou les rapports.
2. **Distinction formelle entre données réelles et scénarios synthétiques** :
   - *Corpus Réel Anonymisé* : Promotion L1 Licence Informatique FST 2025-2026 (`programme_id = 21`, `deliberation_id = 6`, 5 décisions publiées).
   - *Scénarios Synthétiques de Contrôle* : Jeux de tests construits pour simuler et éprouver les 7 typologies de divergence et les cas limites sans corrompre les données réelles.
3. **Immutabilité des dossiers sources** : Les fiches administratives officielles d'origine sont sanctuarisées en lecture seule.

---

## 2. Décomposition du Corpus Pilote Réel (Programme 21 - FST)

Le tableau ci-dessous récapitule la cohorte réelle issue de la délibération officielle publiée `DELIB-L1-2025-2026` (`published_at = 2026-10-30 07:25:25`) :

| Identifiant Anonyme | Inscription ID | Moyenne Référence | Crédits Référence | UEs Validées | Décision Jury Officielle | Profil Académique & Particularité |
|---|---:|---:|---:|---:|---|---|
| `ETU-PILOTE-6748` | 2086 | 9.25 | 9 | 1 | `autorise_redoublement` | Redoublant L1 : conservation définitive des modules validés |
| `ETU-PILOTE-6749` | 2087 | 10.45 | 60 | 4 | `admis` | Admis de plein droit par compensation semestrielle (Art. 9) |
| `ETU-PILOTE-6750` | 2088 | 7.80 | 0 | 0 | `autorise_redoublement` | Note éliminatoire bloquant la compensation ; prérequis requis |
| `ETU-PILOTE-6751` | 2089 | 9.00 | 12 | 2 | `autorise_redoublement` | Capitalisation partielle de 2 UE sur 4 |
| `ETU-PILOTE-6752` | 2090 | 9.40 | 6 | 1 | `autorise_redoublement` | Rattrapage : note de 13.50/20 retenue (Art. 14, règle de la meilleure note) |

### Structure des Évaluations Associées :
- **UE 18 (`UE-ALGO-PROG`, 6 crédits)** :
  - Matière 77 : *Algorithmique et Structures de Données* (Code `ALGO-101`, Coeff 1.5, 3 crédits)
  - Matière 78 : *Programmation C Avancée* (Code `PROG-102`, Coeff 1.5, 3 crédits)
- **UE 19 (`UE-MATH-INFO`, 6 crédits)** :
  - Matière 79 : *Algèbre Linéaire et Matrices* (Code `MATH-101`, Coeff 1.5, 3 crédits)
  - Matière 80 : *Analyse et Séries Numériques* (Code `MATH-102`, Coeff 1.5, 3 crédits)
- **UE 20 (`UE-SYS-ARCH`, 6 crédits)** :
  - Matière 81 : *Architecture des Ordinateurs* (Code `SYS-201`, Coeff 1.5, 3 crédits)
  - Matière 82 : *Systèmes d'Exploitation Unix* (Code `SYS-202`, Coeff 1.5, 3 crédits)
- **UE 21 (`UE-DEV-WEB`, 6 crédits)** :
  - Matière 83 : *Bases de Données Relationnelles* (Code `BD-201`, Coeff 1.5, 3 crédits)
  - Matière 84 : *Développement Web & API* (Code `WEB-202`, Coeff 1.5, 3 crédits)

---

## 3. Scénarios Synthétiques de Contrôle (Banc de Test Automatisé)

Pour garantir la détection infaillible de chaque classe de dysfonctionnement sans dépendre du hasard des données de production, 8 scénarios synthétiques ont été instrumentés dans [`PiloteAcademiqueLoop42Test.php`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/tests/Feature/Acquis/PiloteAcademiqueLoop42Test.php) :

1. **Scénario CONCORDANCE_PARFAITE** : Délibération conforme en tout point aux règles Décret 2022-631 -> Détection `concordant = true`, `classe = null`.
2. **Scénario ECART_CREDITS** : Référence jury accordant 9 crédits là où le barème réglementaire en calcule 6 -> Détection `classe = 'ECART_CREDITS'`, `delta = +3`.
3. **Scénario ECART_UES** : Discordance sur le nombre d'UE validées (1 calculée vs 2 déclarées) -> Détection `classe = 'ECART_UES'`.
4. **Scénario ECART_DECISION** : Décision scolarité legacy (`ajourne`) distincte de la décision jury publiée (`admis`) -> Détection `classe = 'ECART_DECISION'`, typologie `DIVERGENCE_HISTORIQUE`.
5. **Scénario ECART_MOYENNE** : Moyenne référence de 12.00 vs moyenne calculée de 14.50 (écart > tolérance 0.01) -> Détection `classe = 'ECART_MOYENNE'`, typologie `DIVERGENCE_CALCUL`.
6. **Scénario ECART_MULTIPLE** : Cumul d'écarts simultanés (crédits et décision) -> Détection `classe = 'ECART_MULTIPLE'`.
7. **Scénario REFERENCE_NON_PUBLIEE** : Délibération existante mais maintenue à l'état de projet (`publie = false`) -> Détection `comparable = false`, typologie `DIVERGENCE_INTERPRETATION`.
8. **Scénario REFERENCE_ABSENTE** : Dossier sans délibération officielle -> Détection `comparable = false`, typologie `SOURCE_INSUFFISANTE`.

---

## 4. Partitionnement du Corpus Historique (ADDENDUM R42.1)

L'exécution de la méthode `PilotAcademiqueService::classifierCorpusHistorique()` sépare l'ensemble des inscriptions selon les 5 statuts obligatoires :

| Sous-Ensemble du Corpus | Critère d'affectation | Traitement en LOOP 42 |
|---|---|---|
| **1. `PV_OFFICIEL_EXPLOITABLE`** | Délibération normalisée avec `publie = true` | Éligible au double calcul comparatif direct |
| **2. `RESULTAT_NORMALISE`** | Délibération normalisée non encore publiée | Exclu du taux de concordance (en attente de publication) |
| **3. `NOTE_SANS_DELIBERATION_NORMALISEE`** | Notes réelles et inscription valide sans délibération | **Inscription réputée valide** (R42.1) ; simulation scolarité |
| **4. `SOURCE_JUSTIFICATIVE_ABSENTE`** | Inscription sans notes ni délibération | Classé non comparable / source insuffisante |
| **5. `REGLE_INCERTAINE`** | Filière en co-construction ou école sans décret au JORT | Isolé sous fail-closed (`DIVERGENCE_REGLEMENTAIRE`) |

---

## 5. Mesures de Sécurité et Traçabilité

- **Chiffrement et Hash Canonique** : Chaque fiche de recette est identifiée par l'empreinte SHA-256 déterministe générée par `genererEmpreinteCanonique()`.
- **Indépendance des Environnements** : Le jeu de recette est confiné dans l'environnement de staging local sous SQLite / MySQL `inscription_etudiants` avec interdiction formelle d'accès au serveur de production.

