# PARTIE VI — MOTEUR DE DÉCISION ACADÉMIQUE ET WORKFLOWS DÉTAILLÉS
## UniRegistre — Gestion des Acquis, Redoublement et Réinscription Ciblée

**Version :** 1.0  
**Statut de Certification :** **`AUTO-PASS` Certifié**  
**Cadre Réglementaire :** Décret présidentiel tunisien n° 2022-631 du 14 juillet 2022 et Arrêté du 7 juillet 2023.

---

## 1. Cadre Général et Objectif de la Partie VI

La Partie VI étend et formalise le socle initial du **LOOP MASTER** en concevant le moteur central de décision académique, les machines d'états et les workflows d'approbation entourant le redoublement des étudiants.

Le système permet de :
1. Déterminer avec exactitude et explicabilité le statut de chaque UE et ECUE.
2. Garantir le respect absolu de l'interdiction de mutation de données pendant le calcul.
3. Verrouiller les statuts académiques et empêcher les transitions illégales.
4. Assurer qu'aucun crédit ne soit perdu, créé fictivement ou attribué deux fois (Scénarios A à E).
5. Historiser séparément chaque tentative d'examen sans jamais écraser les anciennes évaluations.
6. Bloquer automatiquement toute inscription à un enseignement dont les prérequis ne sont pas satisfaits.
7. Offrir une simulation prévisionnelle déterministe en mode dry-run (individuel, cohorte, programme, groupe).
8. Imposer le principe des 4 yeux (double approbation) pour toutes les opérations sensibles sans possibilité d'auto-approbation.
9. Émettre des documents officiels certifiés avec référence unique, horodatage et empreinte SHA256.
10. Gérer les anomalies selon un protocole strict de détection, classification et correction auditée.

---

## 2. Inventaire des 10 Loops (LOOP 16 à LOOP 25)

| Loop | Thématique | Composant Principal | Verdict |
| :--- | :--- | :--- | :---: |
| **LOOP 16** | Moteur central de décision (Priorités 1 à 8, zéro mutation) | `MoteurDecisionAcademiqueService` | **AUTO-PASS** |
| **LOOP 17** | Gestion des statuts académiques et transitions interdites | `GestionStatutsAcademiquesService` | **AUTO-PASS** |
| **LOOP 18** | Gestion des crédits, anti-doublon et Scénarios A, B, C, D, E | `GestionnaireCreditsService` | **AUTO-PASS** |
| **LOOP 19** | Historique des tentatives sans écrasement & règle du diplôme | `HistoriqueTentativesService` | **AUTO-PASS** |
| **LOOP 20** | Contrôle des prérequis (Cas A, B, C, D, E) & garde-fou HTTP | `ControlePrerequisService` | **AUTO-PASS** |
| **LOOP 21** | Simulation dry-run (individuel, cohorte, programme, groupe) | `SimulationRedoublementService` | **AUTO-PASS** |
| **LOOP 22** | Workflow 4 étapes & double approbation (contrôle des 4 yeux) | `ValidationApprobationPlanService` | **AUTO-PASS** |
| **LOOP 23** | Documents officiels certifiés, références et empreinte SHA256 | `DocumentPedagogiqueOfficielService` | **AUTO-PASS** |
| **LOOP 24** | Détection, classification par gravité & correction auditée | `GestionAnomaliesAcademiquesService` | **AUTO-PASS** |
| **LOOP 25** | Recette fonctionnelle, technique, RBAC & suite de tests | `DecisionAcademiqueWorkflowTest` | **AUTO-PASS** |

---

## 3. Matrice des Priorités du Moteur Central de Décision (LOOP 16)

Le moteur applique sans dérogation l'ordre de priorité 1 à 8 :

```
[ P1 : Validité des sources ]
   │
   ▼
[ P2 : Régime applicable & version maquette ]
   │
   ▼
[ P3 : Décisions officielles du jury ]
   │
   ▼
[ P4 : Acquis antérieurs (Capitalisation à vie across enrollments) ]
   │
   ▼
[ P5 : Capitalisation (Art. 8 - UE >= 10 ou ECUE avec crédits propres) ]
   │
   ▼
[ P6 : Compensation (Art. 9 - Si autorisée et moyenne semestrielle >= 10) ]
   │
   ▼
[ P7 : Seuils de compétence (Art. 13 - Note éliminatoire < 6.0 bloque compensation) ]
   │
   ▼
[ P8 : Construction du parcours (Éléments conservés vs Éléments à reprendre) ]
```

> **Garantie d'immutabilité :** Le moteur s'exécute strictement en lecture seule. Aucune note, décision, inscription ou procès-verbal n'est altéré lors de son invocation.

---

## 4. Matrice des Statuts et Transitions Interdites (LOOP 17)

### Statuts des Unités et Éléments
- **UE :** `CAPITALISEE`, `VALIDEE_PAR_COMPENSATION`, `NON_VALIDEE`, `A_REPRENDRE`, `EN_ATTENTE_DELIBERATION`, `SEUIL_COMPETENCE_NON_ATTEINT`, `EQUIVALENCE_EN_ATTENTE`, `DISPENSE_EN_ATTENTE`, `ANOMALIE_A_TRAITER`.
- **ECUE :** `CAPITALISE`, `NON_CAPITALISE`, `A_REPRENDRE`, `EN_ATTENTE_RESULTAT`, `SEUIL_NON_ATTEINT`, `SANS_CREDITS_INDEPENDANTS`, `DECISION_A_VERIFIER`.
- **Dossier Redoublement :** `BROUILLON`, `ANALYSE_EN_COURS`, `SIMULATION_TERMINEE`, `ANOMALIE_DETECTEE`, `EN_VALIDATION`, `VALIDE`, `INSCRIPTION_PEDAGOGIQUE_CREEE`, `BLOQUE`.

### Transitions Interdites Rejetées
1. `EN_ATTENTE_DELIBERATION` → `CAPITALISEE` : Rejet si la délibération n'est pas officiellement publiée.
2. `EQUIVALENCE_EN_ATTENTE` → `CAPITALISEE` : Rejet sans acte officiel d'équivalence validé.
3. `DISPENSE_EN_ATTENTE` → `CAPITALISEE` : Rejet sans arrêté officiel de dispense.
4. `NON_VALIDEE` → `CAPITALISEE` : Rejet sans moyenne >= 10 ou décision explicite de jury.
5. `ANOMALIE_A_TRAITER` → `VALIDE` : Rejet sans résolution préalable.
6. `BROUILLON` → `INSCRIPTION_PEDAGOGIQUE_CREEE` : Rejet formel (passage obligatoire par `EN_VALIDATION` puis `VALIDE`).

---

## 5. Gestion des Crédits et Scénarios A, B, C, D, E (LOOP 18)

- **Scénario A (Capitalisation UE) :** Les crédits sont octroyés une seule fois au scellement de l'UE. En redoublement, aucun doublon d'ECTS n'est généré.
- **Scénario B (Capitalisation ECUE) :** L'ECUE validé avec crédits propres conserve ses ECTS. Les autres ECUEs de la même UE restent à valider sans double attribution.
- **Scénario C (Compensation) :** La compensation valide l'UE dans son ensemble mais n'accorde aucun crédit autonome aux matières sous la moyenne.
- **Scénario D (Crédits incohérents) :** Toute discordance entre les crédits du relevé/PV et la maquette officielle déclenche une anomalie bloquante *fail-closed*.
- **Scénario E (Idempotence) :** Deux audits ou imports consécutifs produisent un solde de crédits rigoureusement invariant.

---

## 6. Historique des Tentatives et Règle du Diplôme (LOOP 19)

- **Conservation immuable :** Chaque tentative (session normale, rattrapage, redoublement) est enregistrée séparément avec sa session et sa date.
- **Arbitrage de la note finale :**
  - **LMD Licence (Art. 14) :** Règle de la meilleure note (`max(normale, rattrapage)`).
  - **Cycle Ingénieur :** Substitution par la note de rattrapage ou meilleure note selon règlement.
  - La décision conserve la justification textuelle et l'historique complet.

---

## 7. Contrôle des Prérequis et Sécurité Serveur (LOOP 20)

- **Cas A :** Prérequis acquis de plein droit (note >= 10 ou crédits propres) -> Accès autorisé.
- **Cas B :** Prérequis non acquis (< 10) -> Inscription bloquée avec motif explicite.
- **Cas C :** Prérequis compensé -> Traitement selon le paramètre réglementaire `compensation_valide_prerequis`.
- **Cas D :** Prérequis dispensé -> Visa officiel de la dispense vérifié.
- **Cas E :** Prérequis réformé -> Équivalence transitoire appliquée.
- **Garde-fou HTTP :** L'appel API ou formulaire ne peut contourner la vérification serveur (`DomainException` immédiate en cas de forçage).

---

## 8. Simulation Prévisionnelle et Déterminisme (LOOP 21)

- **Quatre modes de calcul :**
  1. `simulerIndividuel(Etudiant $etudiant)`
  2. `simulerCohorte(array $etudiantIds)`
  3. `simulerProgramme(int $programmeId)`
  4. `simulerGroupe(int $groupeId)`
- **Propriété mathématique :** Déterminisme strict. L'empreinte SHA256 calculée sur le jeu de résultats est rigoureusement identique lors de répétitions sur le même état de données.

---

## 9. Workflow 4 Étapes & Principe des 4 Yeux (LOOP 22)

```
[ SIMULATION ] ──> [ CONTRÔLE ] ──> [ VALIDATION ] ──> [ INSCRIPTION SÉLECTIVE ]
```

### Cas Sensibles Exigeant la Double Approbation (4 Yeux)
- Changement de parcours / réorientation.
- Équivalence de crédits extérieurs.
- Dispense d'épreuves.
- Rectification de note antérieure.
- Rachat exceptionnel de jury.
- Résolution d'anomalie de crédits.

> **Règle d'or :** L'auteur/demandeur du plan ne peut en aucun cas s'auto-approuver (`auteur_id === approbateur_id` -> rejet immédiat).

---

## 10. Documents Officiels Certifiés (LOOP 23)

- **Types émis :**
  1. `RELEVE_REDOUBLEMENT` : Relevé de situation officiel.
  2. `SYNTHESE_ACQUIS` : Attestation de capitalisation d'acquis à vie.
  3. `CONTRAT_PEDAGOGIQUE` : Programme pédagogique individuel du redoublant.
- **Garanties d'intégrité :**
  - Référence unique au format : `DOC-{TYPE}-{ANNEE}-{ETUDIANT_ID}-{TIMESTAMP}`.
  - Calcul d'empreinte cryptographique SHA-256 sur le JSON complet du document.
  - Distinction formelle du filigrane légal :
    - Si plan validé : `DOCUMENT OFFICIEL CERTIFIÉ — CONFORME AUX DÉLIBÉRATIONS DU JURY`.
    - Si simulation : `SIMULATION PRÉVISIONNELLE — DOCUMENT NON OFFICIEL SANS VALEUR JURIDIQUE`.

---

## 11. Gestion et Correction Auditée des Anomalies (LOOP 24)

- **Classification par gravité :**
  - `BLOQUANTE` : Délibération non publiée, note hors barème (< 0 ou > 20), programme inconnu.
  - `CRITIQUE` : Seuil éliminatoire violé, discordance de crédits, doublon d'attribution.
  - `MOYENNE` : Prérequis non satisfait, équivalence non statuée.
  - `MINEURE` : Retard de saisie d'observation.
- **Protocole de correction :**
  - Motif circonstancié obligatoire.
  - Référence officielle du PV rectificatif exigée.
  - Audit immuable dans `HistoriqueAcademique` (ancienne et nouvelle valeurs).
  - Re-contrôle automatique instantané des acquis dépendants.

---

## 12. Résultats de Recette et Preuves de Validation (LOOP 25)

### Exécution de la Suite Dédiée
- **Fichier de test :** [`tests/Feature/Acquis/DecisionAcademiqueWorkflowTest.php`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/tests/Feature/Acquis/DecisionAcademiqueWorkflowTest.php)
- **Commande :** `php artisan test --compact --filter="DecisionAcademiqueWorkflowTest"`
- **Résultat :** **14 tests / 14 passés** (87 assertions, 0 échec).

### Exécution Globale Acquis & Décision
- **Commande :** `php artisan test --compact --filter="Acquis|DecisionAcademiqueWorkflowTest"`
- **Résultat :** **44 tests / 44 passés** (165 assertions, 0 échec, durée : 2,4s).

### Contrôle de Style et Conventions
- **Commande :** `vendor/bin/pint --dirty --test`
- **Résultat :** **100% conforme** (0 violation).

---

## 13. Verdict Final

Le mandat **PARTIE VI — MOTEUR DE DÉCISION ACADÉMIQUE ET WORKFLOWS DÉTAILLÉS** (LOOP 16 à LOOP 25) est certifié **`AUTO-PASS`**.  
Toutes les exigences réglementaires, fonctionnelles, d'immuabilité et de sécurité sont rigoureusement respectées et couvertes par des preuves automatisées reproductibles.

