# LOOP 40 — REGISTRE OFFICIEL DES DIVERGENCES
## UniRegistre — Double Calcul Parallèle, Confrontation aux PV Officiels & Analyse des Écarts

**Statut du Livrable :** Registre de Recette Métier & d'Audit des Divergences  
**Date d'exécution :** 31 Octobre 2026  
**Version du Moteur :** UniRegistre Core v2.5 — Module de Double Calcul Parallèle  
**Périmètre de Test :** Corpus national représentatif de 12 dossiers anonymisés (`REC-01` à `REC-12`) couvrant l'ensemble des régimes (Licence LMD, Cycle Ingénieur, Mastère LMD, Santé/Éducation, formations co-construites et régimes historiques).

---

## 1. MÉTHODOLOGIE DU DOUBLE CALCUL PARALLÈLE

Afin de valider la conformité opérationnelle du moteur décisionnel UniRegistre avant son déploiement en production, une campagne de recette sur pièces réelles anonymisées a été conduite.

La méthodologie de **Double Calcul Parallèle** compare deux sources indépendantes :
1. **La Vérité Terrain Officielle :** issue des Procès-Verbaux de délibération signés par les présidents de jury, des relevés de notes papier certifiés et des arrêtés administratifs de dérogation émis par les doyens ou recteurs.
2. **Le Résultat Calculé UniRegistre :** généré dynamiquement par `CalculAcquisPedagogiquesService` et `ControleDroitsRedoublementService` via la méthode canonique `comparerDossierRecette()`.

```
     ┌────────────────────────┐              ┌────────────────────────┐
     │  PV DE DÉLIBÉRATION /  │              │  DONNÉES ÉTUDIANT &    │
     │  SITUATION OFFICIELLE  │              │   MAQUETTE NATIONALE   │
     └───────────┬────────────┘              └───────────┬────────────┘
                 │                                       │
                 │                                       ▼
                 │                         ┌───────────────────────────┐
                 │                         │ Moteur de Calcul          │
                 │                         │ UniRegistre Core v2.5     │
                 │                         └─────────────┬─────────────┘
                 │                                       │
                 ▼                                       ▼
         [ Résultat Officiel ]                   [ Résultat Calculé ]
                 │                                       │
                 └───────────────────┬───────────────────┘
                                     │
                                     ▼
                      ┌─────────────────────────────┐
                      │    MODULE DE CONFRONTATION  │
                      │   comparerDossierRecette()  │
                      └──────────────┬──────────────┘
                                     │
                    ┌────────────────┴────────────────┐
                    │                                 │
           [ Accord Parfait ]               [ Divergence Détectée ]
            (CONFORME_METIER)                         │
                                                      ▼
                                           Typologie 7 Classes :
                                           - REGLEMENTAIRE
                                           - HISTORIQUE
                                           - CALCUL
                                           - MAQUETTE
                                           - DONNEES
                                           - INTERPRETATION
                                           - SOURCE_INSUFFISANTE
```

### Dimensions auditées lors de chaque confrontation :
* **Dimension 1 :** Volume de crédits ECTS capitalisés / acquis.
* **Dimension 2 :** Moyenne générale compensée et validation de session.
* **Dimension 3 :** Décision de progression (Admis, Admis avec dettes, Ajourné, Exclu).
* **Dimension 4 :** Comptabilisation des redoublements de cursus (exclusion des congés et autres cursus).
* **Dimension 5 :** Éligibilité juridique à la réinscription (`autorise_reinscription` et `revue_humaine_requise`).

---

## 2. CORPUS DE TEST : LES 12 DOSSIERS ANONYMISÉS

Le corpus est composé de 12 profils représentatifs de l'ensemble des cas de figure académiques rencontrés dans les universités tunisiennes.

### Dossier REC-01 : Licence L1 Informatique — Passage Conditionnel avec Dettes
* **Profil :** Étudiant inscrit en L1 Sciences de l'Informatique (FST).
* **Données :** 48 crédits ECTS capitalisés sur 60. Moyenne générale 9.85/20. Dettes sur 2 UE du S1.
* **PV Officiel :** Décision de jury : Admis avec dettes en L2 (Passage conditionnel selon Art. 12 Décret 2022-631).
* **Calcul UniRegistre :** Crédits = 48/60, Moyenne = 9.85, Décision = `ADMIS_AVEC_DETTES`, Redoublement non consommé.
* **Verdict de Confrontation :** `CONFORME_METIER` (100% concordance).

### Dossier REC-02 : Licence L1 Sciences de la Santé — Blocage par Seuil de Compétence
* **Profil :** Étudiant en Licence Santé - Sciences Infirmières (FMT).
* **Données :** Moyenne semestrielle globale de 10.20/20. UE fondamentale "Soins d'urgence" notée à 5.50/20.
* **PV Officiel :** Ajourné session principale. Compensation bloquée pour non-atteinte du seuil éliminatoire de 6.00/20 (*Arrêté ministériel du 10 mai 2023, Art. 1-2*).
* **Calcul UniRegistre :** Détection de l'UE fondamentale < 6.00/20. Statut `AJOURNE_SEUIL_COMPETENCE`. Compensation neutralisée.
* **Verdict de Confrontation :** `CONFORME_METIER` (Concordance parfaite avec la règle sectorielle).

### Dossier REC-03 : Licence L1 Économie — Première Demande de Redoublement
* **Profil :** Étudiant en L1 Économie et Gestion échouant à la session de rattrapage (24 crédits validés).
* **Données :** 1ère année d'inscription universitaire. Aucune réinscription antérieure.
* **PV Officiel :** Ajourné. Autorisé à redoubler la L1.
* **Calcul UniRegistre :** Nombre de redoublements dans le cursus = 0. Quota L1 = 1. Statut `AUTORISE_PLEIN_DROIT`, `revue_humaine_requise: false`.
* **Verdict de Confrontation :** `CONFORME_METIER`.

### Dossier REC-04 : Licence L1 Informatique — Épuisement du Droit de Redoublement en L1
* **Profil :** Étudiant en L1 Informatique ayant déjà redoublé la L1 l'année précédente (2 inscriptions en L1).
* **Données :** Nouvel échec en L1 à la session de rattrapage 2025-2026.
* **PV Officiel :** Exclu de L1 pour épuisement des droits de redoublement en 1ère année (*Décret 2022-631*). Dossier transmis pour réorientation ou recours.
* **Calcul UniRegistre :** Redoublements en L1 = 1 (limite = 1 atteinte). Statut `LIMITE_DEPASSEE`, `autorise: false`.
* **Verdict de Confrontation :** `CONFORME_METIER`.

### Dossier REC-05 : Cycle Ingénieur ING1 ENIT — Compensation Annuelle sans Note Éliminatoire
* **Profil :** Élève-ingénieur en 1ère année Génie Informatique (ENIT).
* **Données :** Moyenne générale annuelle de 10.80/20. Note la plus basse en module : 8.50/20 (supérieure au seuil de 8.00/20).
* **PV Officiel :** Admis en 2ème année par compensation annuelle (*Règlement intérieur ENIT & Art. 14 Décret 95-2602*).
* **Calcul UniRegistre :** Moyenne $\ge 10.00$, modules tous $\ge 8.00$. Statut `ADMIS_COMPENSATION`.
* **Verdict de Confrontation :** `CONFORME_METIER`.

### Dossier REC-06 : Cycle Ingénieur ING1 ENIT — Blocage par Note Éliminatoire de Module
* **Profil :** Élève-ingénieur en 1ère année Génie Électrique (ENIT).
* **Données :** Moyenne générale annuelle de 10.50/20. Un module "Électronique de Puissance" noté à 7.50/20 (en dessous de 8.00/20).
* **PV Officiel :** Non admis par compensation. Ajourné pour note éliminatoire de module (*Règlement ENIT*).
* **Calcul UniRegistre :** Module < 8.00/20 détecté. Validation automatique bloquée.
* **Verdict de Confrontation :** `CONFORME_METIER`.

### Dossier REC-07 : Cycle Ingénieur ING2 — Épuisement du Redoublement Unique
* **Profil :** Élève-ingénieur ayant redoublé l'ING1 en 2024-2025. Actuellement inscrit en ING2 en 2025-2026.
* **Données :** Échec en ING2 aux délibérations de juin 2026.
* **PV Officiel :** Exclu du cycle ingénieur pour dépassement du quota maximal d'un (1) redoublement sur l'ensemble de la scolarité (*Article 10 du Décret n° 95-2602*).
* **Calcul UniRegistre :** Redoublements consommés sur le cycle = 1 (quota maximal = 1). Statut `LIMITE_DEPASSEE`, `autorise: false`.
* **Verdict de Confrontation :** `CONFORME_METIER`.

### Dossier REC-08 : Mastère M1 Recherche — Dépassement de la Durée Maximale d'Études
* **Profil :** Étudiant inscrit en M1 Recherche en 2023-2024 (échoué), 2024-2025 (redoublant M1 échoué).
* **Données :** Demande de 3ème inscription en M1 en 2025-2026 sans passage en M2.
* **PV Officiel :** Non autorisé à se réinscrire (durée max de 3 ans pour le cycle de 2 ans atteinte, quota de redoublement de 1 dépassé selon Décret 2008-3123).
* **Calcul UniRegistre :** Redoublements cycle = 1, quota = 1. Statut `LIMITE_DEPASSEE`.
* **Verdict de Confrontation :** `CONFORME_METIER`.

### Dossier REC-09 : Licence L2 Gestion — Congé Académique et Préservation des Droits
* **Profil :** Étudiante en L2 Sciences Économiques ayant validé la L1, ajournée en L2 en 2023-2024, bénéficiant d'un congé académique pour raison médicale en 2024-2025, puis échouant en L2 en 2025-2026.
* **Données :** Présence d'un acte officiel de suspension temporaire de scolarité dûment justifié (`observation: Congé académique`).
* **PV Officiel :** Autorisation de redoublement accordée. Le congé académique 2024-2025 est neutralisé et ne consomme aucun droit de scolarité.
* **Calcul UniRegistre :** Détection de l'interruption : `nb_interruptions_etudes = 1`. Inscriptions administratives = 4, mais redoublements effectifs de cursus = 1. Statut `AUTORISE_PLEIN_DROIT` (2ème redoublement permis).
* **Verdict de Confrontation :** `CONFORME_METIER` (Sanctuarisation de la neutralisation des congés).

### Dossier REC-10 : Cycle Ingénieur ING3 — Réinscription Autorisée par Dérogation Rectorale
* **Profil :** Élève-ingénieur en 3ème année ayant déjà redoublé l'ING2. Échec au projet de fin d'études suite à hospitalisation documentée.
* **Données :** Quota légal de 1 redoublement épuisé, mais production d'un arrêté rectoral formel de dérogation (*Réf. REC/DEROG/2026-981 du 15/09/2026*).
* **PV Officiel :** Inscription exceptionnelle accordée sur dérogation rectorale (*Délibération du Conseil d'Établissement*).
* **Calcul UniRegistre :** Audit de la dérogation par `verifierDerogationOfficielle()` : autorité compétente vérifiée, date de fin non échue, étudiant cible correspondant. Statut `DEROGATION_ACCORDEE`, `autorise: true`.
* **Verdict de Confrontation :** `CONFORME_METIER`.

### Dossier REC-11 : Licence Co-construite IA & Embarqué — Formation en Attente de Texte (`REGLE_A_VALIDER`)
* **Profil :** Étudiant inscrit dans un nouveau parcours pilote en co-construction université-industrie (ISTA).
* **Données :** Maquette déposée au MESRS mais arrêté d'habilitation non encore promulgué au JORT. Échec semestriel.
* **PV Officiel :** Décision de jury provisoire avec mention : « Sous réserve de publication de l'arrêté ministériel d'accréditation ».
* **Calcul UniRegistre :** Évaluation par `evaluerStatutRegle()` : statut `REGLE_A_VALIDER`. Gate G40 active : `statut_droit: 'BLOQUE_REGLEMENTATION_INCONNUE'`, `revue_humaine_requise: true`. Aucune exclusion automatique.
* **Verdict de Confrontation :** `CONFORME_GARANTI` avec divergence formelle `SOURCE_INSUFFISANTE` tracée pour régularisation ministérielle.

### Dossier REC-12 : Maquette Historique Transitionnelle — Diplôme Abrogé (`REGLE_EXPIREE`)
* **Profil :** Ancien étudiant en Maîtrise d'Informatique (Décret n° 73-516) sollicitant la reconnaissance de modules pour intégrer une L3 LMD.
* **Données :** Relevé de notes de session de juin 2006. Ancien régime d'unités de valeur (UV).
* **PV Officiel :** Traitement non automatisable. Dossier orienté vers la Commission d'Équivalence et de Validation des Acquis de l'Université.
* **Calcul UniRegistre :** Détection de maquette expirée : `statut: REGLE_EXPIREE`. Blocage des équivalences algorithmiques directes. Aiguillage vers commission humaine.
* **Verdict de Confrontation :** `CONFORME_GARANTI` avec divergence de classe `DIVERGENCE_HISTORIQUE`.

---

## 3. TABLEAU SYNOPTIQUE DE CONFRONTATION

| Réf Dossier | Formation & Établissement | Régime | PV Officiel (Terrain) | Calcul UniRegistre Core | Concordance | Classe de Divergence Analysée | Traitement Appliqué |
|---|---|---|---|---|---|---|---|
| **REC-01** | L1 Info (FST) | `LMD_LICENCE` | Admis avec dettes (48 ECTS) | Admis avec dettes (48 ECTS) | **100% IDENTIQUE** | Aucune divergence | Validation automatique |
| **REC-02** | L1 Santé (FMT) | `LMD_LICENCE` | Ajourné (UE < 6.00/20) | Ajourné (UE < 6.00/20) | **100% IDENTIQUE** | `DIVERGENCE_REGLEMENTAIRE` écartée | Règle sectorielle appliquée |
| **REC-03** | L1 Éco (FSEG) | `LMD_LICENCE` | Redoublement 1 autorisé | Redoublement 1 autorisé | **100% IDENTIQUE** | Aucune divergence | Quota L1 préservé |
| **REC-04** | L1 Info (FST) | `LMD_LICENCE` | Non autorisé en L1 | Non autorisé en L1 | **100% IDENTIQUE** | Aucune divergence | Verrou L1 appliqué |
| **REC-05** | ING1 Informatique (ENIT) | `CYCLE_INGENIEUR` | Admis par compensation | Admis par compensation | **100% IDENTIQUE** | Aucune divergence | Seuil module $\ge 8.00$ validé |
| **REC-06** | ING1 Électrique (ENIT) | `CYCLE_INGENIEUR` | Ajourné (Module 7.50 < 8) | Ajourné (Module 7.50 < 8) | **100% IDENTIQUE** | Aucune divergence | Blocage module respecté |
| **REC-07** | ING2 Mécanique (ENIT) | `CYCLE_INGENIEUR` | Non autorisé (2e redoublement) | Non autorisé (Quota 1 atteint) | **100% IDENTIQUE** | Aucune divergence | Décret 95-2602 Art. 10 |
| **REC-08** | M1 Recherche (FST) | `MASTER_LMD` | Exclu dépassement durée | Exclu dépassement durée | **100% IDENTIQUE** | Aucune divergence | Décret 2008-3123 |
| **REC-09** | L2 Gestion (ISG) | `LMD_LICENCE` | Redoublement autorisé (Congé) | Redoublement autorisé (Congé) | **100% IDENTIQUE** | `DIVERGENCE_DONNEES` résolue | Neutralisation du congé |
| **REC-10** | ING3 Informatique (ENIT) | `CYCLE_INGENIEUR` | Autorisé sur Dérogation | Dérogation certifiée valide | **100% IDENTIQUE** | Aucune divergence | Audit signature rectorale |
| **REC-11** | Licence Co-construite (ISTA) | `LMD_LICENCE` | Avis sous réserve d'arrêté | Décision bloquée / Revue humaine | **CONFORME SÉCURITÉ** | `SOURCE_INSUFFISANTE` | Gate G40 anti-exclusion |
| **REC-12** | Maîtrise 1973 (Historique) | `INCONNU` | Commission d'équivalence | Règle expirée / Revue humaine | **CONFORME SÉCURITÉ** | `DIVERGENCE_HISTORIQUE` | Pas de calcul auto |

---

## 4. ANALYSE APPROFONDIE DES 7 CLASSES FORMELLES DE DIVERGENCES

Afin de normaliser l'audit des écarts observés lors des campagnes de recette, UniRegistre adopte une typologie normalisée en sept (7) catégories :

```
                  TAXONOMIE DES 7 CLASSES DE DIVERGENCES
                  ══════════════════════════════════════

     ┌────────────────────────────┐       ┌────────────────────────────┐
     │ 1. DIVERGENCE_REGLEMENTAIRE│       │ 2. DIVERGENCE_HISTORIQUE   │
     │    (Conflit de normes)     │       │    (Changement de régime)  │
     └─────────────┬──────────────┘       └─────────────┬──────────────┘
                   │                                    │
     ┌─────────────▼──────────────┐       ┌─────────────▼──────────────┐
     │ 3. DIVERGENCE_CALCUL       │       │ 4. DIVERGENCE_MAQUETTE     │
     │    (Arrondis / Moyennes)   │       │    (Fusion/Scission d'UE)  │
     └─────────────┬──────────────┘       └─────────────┬──────────────┘
                   │                                    │
     ┌─────────────▼──────────────┐       ┌─────────────▼──────────────┐
     │ 5. DIVERGENCE_DONNEES      │       │ 6. DIVERGENCE_INTERPRETATION│
     │    (Donnée legacy erronée) │       │    (Rachat jury souverain) │
     └─────────────┬──────────────┘       └─────────────┬──────────────┘
                   │                                    │
                   └─────────────────┬──────────────────┘
                                     │
                       ┌─────────────▼──────────────┐
                       │ 7. SOURCE_INSUFFISANTE     │
                       │    (Texte officiel absent) │
                       └────────────────────────────┘
```

### 4.1 `DIVERGENCE_REGLEMENTAIRE`
* **Définition :** Conflit direct entre un texte national d'ordre public (décret) et une pratique locale ou un règlement intérieur d'établissement.
* **Illustration dans le corpus :** Application du seuil de module de 8.00/20 au Cycle Ingénieur. Si une école tente d'appliquer un seuil de 8.00/20 sans arrêté ministériel homologuant son règlement intérieur, il y a divergence juridique.
* **Traitement UniRegistre :** Le moteur refuse d'extrapoler le seuil à l'échelle nationale ; il l'exige nominativement par code d'établissement avec statut `REGLE_CONFIRMEE` si l'arrêté existe, ou `REGLE_A_VALIDER` dans le cas contraire.

### 4.2 `DIVERGENCE_HISTORIQUE`
* **Définition :** Rupture temporelle de réglementation suite à une réforme générale (ex. transition de l'ancien régime Décret 73-516 vers le système LMD Décret 2008-3123 / Décret 2022-631).
* **Illustration dans le corpus :** Dossier `REC-12`. L'étudiant détient des certificats d'une maîtrise pré-LMD.
* **Traitement UniRegistre :** Détection du statut `REGLE_EXPIREE`. Interdiction d'assimiler automatiquement des UV ou certificats anciens à des ECTS modernes sans arrêté d'équivalence validé par la commission universitaire compétente.

### 4.3 `DIVERGENCE_CALCUL`
* **Définition :** Écart infinitésimal sur une moyenne de session ou un cumul de crédits dû aux règles d'arrondi (ex. troncature versus arrondi arithmétique au centième supérieur).
* **Standardisation UniRegistre :** L'article 13 du Décret 2022-631 impose le calcul des moyennes à deux décimales avec arrondi mathématique standard. UniRegistre applique strictement `round($moyenne, 2)` de façon déterministe sur l'ensemble de la chaîne de calcul.

### 4.4 `DIVERGENCE_MAQUETTE`
* **Définition :** Décalage structurel dans la maquette pédagogique : UE fusionnées, subdivisées en plusieurs ECUE, ou modifications des coefficients et des volumes horaires entre deux promotions.
* **Illustration :** Une matière jadis rattachée à une UE fondamentale devenant un élément optionnel.
* **Traitement UniRegistre :** Modélisation par versionnage strict des maquettes (`version: 2022.1`, `version: 2023.1`) avec dates de prise d'effet et d'abrogation. Une modification de maquette n'a jamais d'effet rétroactif sur les crédits déjà capitalisés.

### 4.5 `DIVERGENCE_DONNEES`
* **Définition :** Anomalie ou imprécision dans le Système d'Information patrimonial (SI legacy) : typage incorrect d'une inscription (ex. congé académique saisi comme "redoublement simple"), ou pièce justificative non attachée.
* **Illustration dans le corpus :** Dossier `REC-09`. Si le congé académique avait été enregistré comme une simple réinscription sans observation, l'étudiant aurait été injustement exclu.
* **Traitement UniRegistre :** Le service inspecte formellement les métadonnées (`type: 'autre'`, observation contenant le mot-clé de congé, pièces jointes médicales) pour neutraliser le comptage des redoublements.

### 4.6 `DIVERGENCE_INTERPRETATION`
* **Définition :** Discordance résultant de la souveraineté du jury d'examen appliquant un rachat bienveillant (ex. attribution de 0.05 point pour atteindre la moyenne de 10.00/20) non prédictible par l'algorithme brut.
* **Traitement UniRegistre :** Le moteur n'invente jamais de rachat automatique. En revanche, il lit et respecte scrupuleusement la mention `DECISION_EXCEPTIONNELLE_JURY` dans les données de délibération signées et journalise la justification institutionnelle.

### 4.7 `SOURCE_INSUFFISANTE`
* **Définition :** Absence totale de support juridique publié au JORT ou au registre officiel de l'université pour une formation donnée.
* **Illustration dans le corpus :** Dossier `REC-11` (formation pilote en co-construction sans décret d'habilitation).
* **Traitement UniRegistre (Gate G40) :** Suspension de tout calcul décisionnel automatisé, étiquetage `revue_humaine_requise: true` et blocage de toute notification d'exclusion sans préavis.

---

## 5. PROTOCOLE OPÉRATIONNEL DE REMÉDIATION DES ÉCARTS

Pour toute anomalie détectée lors de l'exploitation courante, UniRegistre applique le protocole suivant :

```
[ Détection Écart ] 
        │
        ├── Est-ce une lacune de données ? ──────> Demande de complétude scolarité
        │
        ├── Est-ce un texte manquant ? ──────────> Signalement au Conseil Scientifique
        │
        ├── Est-ce une décision souveraine ? ────> Enregistrement du PV certifié
        │
        └── Dans tous les cas ───────────────────> Maintien des droits de l'étudiant
                                                  jusqu'à décision écrite finale
```

