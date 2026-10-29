# EA-EXAMENS-01 — Phase 2 : Matrice des Règles Métier et Contraintes

**Projet :** UniRegistre — Laravel  
**Module :** Examens Advanced  
**Date :** 28 Octobre 2026  

---

## 1. Classification des Contraintes (Dures vs Souples)

### 1.1 Contraintes Dures (C-HARD) — Bloquantes (Inviolables)

Toute violation d'une contrainte dure entraîne l'invalidation automatique du planning et interdit toute publication.

| Code Règle | Nom de la Contrainte | Description & Seuil de Tolérance | Service Responsable | Menu de Résolution |
|:---|:---|:---|:---|:---|
| **C-HARD-01** | Non-chevauchement des Salles | Une même salle ne peut accueillir deux épreuves distinctes sur des plages horaires qui se chevauchent. Tolérance = 0 minute. | `ConflitEpreuveService` | Menu 7 (Planification) / Menu 6 (Salles) |
| **C-HARD-02** | Capacité Examen Respectée | Le nombre d'étudiants convoqués dans une salle ne doit jamais dépasser sa `capacite_examen` utile vérifiée. Tolérance = 0 étudiant. | `ExamRoomAssignmentService` | Menu 6 (Salles) / Menu 5 (Groupes) |
| **C-HARD-03** | Salle Vérifiée & Disponible | Une salle affectée doit être active, validée et non marquée indisponible pour travaux ou maintenance. Tolérance = Aucune affectation sur salle non vérifiée. | `ExamRoomAssignmentService` | Menu 6 (Salles) |
| **C-HARD-04** | Non-chevauchement des Candidats | Un étudiant ne peut être convoqué à deux épreuves différentes au même moment. Tolérance = 0 chevauchement. | `ConflitEpreuveService` | Menu 7 (Planification) / Menu 4 (Éligibilité) |
| **C-HARD-05** | Non-chevauchement Surveillants | Un enseignant / surveillant ne peut être affecté à plusieurs épreuves simultanées. Tolérance = 0 double affectation. | `SurveillanceExamenService` | Menu 8 (Surveillance) |
| **C-HARD-06** | Épreuve en Période de Session | La date et heure de début/fin d'une épreuve doivent obligatoirement être comprises entre la date de début et de fin de la session parente. | `PreparationExamenService` | Menu 2 (Sessions) / Menu 7 (Planification) |
| **C-HARD-07** | Cloisonnement Faculté | Toutes les entités d'une planification (session, épreuves, candidats, salles) doivent appartenir au même périmètre facultaire ou être autorisées inter-facultés. | `FacultePerimetre` | Menu 1 (Dashboard) / Menu 2 (Sessions) |

### 1.2 Contraintes Souples (C-SOFT) — Objectifs d'Optimisation

Les contraintes souples servent à classer et départager les scénarios valides.

| Code Règle | Nom de l'Optimisation | Objectif / Fonction de Coût | Poids Relatif | Menu d'Ajustement |
|:---|:---|:---|:---|:---|
| **C-SOFT-01** | Taux d'Occupation Équilibré | Maximiser le taux de remplissage des salles mobilisées sans dépasser 85% de la capacité utile pour le confort des candidats. | Élevé | Menu 9 (Simulation) |
| **C-SOFT-02** | Minimisation des Salles | Réduire le nombre total de salles et de bâtiments ouverts simultanément pour réduire les coûts logistiques et de surveillance. | Moyen | Menu 9 (Simulation) |
| **C-SOFT-03** | Regroupement des Cohortes | Conserver les étudiants d'un même groupe/filière dans la même salle ou des salles contiguës. | Moyen | Menu 5 (Groupes) |
| **C-SOFT-04** | Étalement des Épreuves | Éviter qu'un groupe d'étudiants n'ait plus de 2 épreuves le même jour ou moins de 30 min entre 2 épreuves. | Élevé | Menu 7 (Planification) |
| **C-SOFT-05** | Ratio de Surveillance Idéal | Assurer un surveillant pour 25 à 30 étudiants présents. | Moyen | Menu 8 (Surveillance) |

---

## 2. Dimensions du Score de Préparation des Sessions (D1 à D7)

Le score global d'une session est calculé sur une base de 100 points, décomposé selon 7 dimensions académiques vérifiables :

1. **D1 — Paramétrage de Session & Épreuves (Poids : 15%) :**
   - Dates de session valides, statuts des épreuves définis, durées et coefficients configurés.
2. **D2 — Éligibilité & Inscriptions Candidats (Poids : 15%) :**
   - Étudiants inscrits pédagogiquement rattachés aux épreuves, absence d'étudiants orphelins.
3. **D3 — Planification Temporelle (Poids : 20%) :**
   - Toutes les épreuves ont un créneau horaire validé, sans chevauchement de session.
4. **D4 — Allocation des Salles & Capacités (Poids : 20%) :**
   - Toutes les épreuves ont une salle attribuée dont la capacité examen est suffisante.
5. **D5 — Surveillance & Émargement (Poids : 15%) :**
   - Surveillants affectés respectant les ratios minimaux réglementaires.
6. **D6 — Documents & Convocations (Poids : 10%) :**
   - Modèles de convocations prêts, QR codes opérationnels.
7. **D7 — Validation Administrative & Audit (Poids : 5%) :**
   - Workflow 4 yeux initié, conformité réglementaire vérifiée.

**Verdict d'Éligibilité Opérationnelle :**
- `ELIGIBLE` : Score >= 85% ET 0 anomalie bloquante (C-HARD).
- `A_COMPLETER` : Score entre 50% et 84% ET 0 anomalie bloquante.
- `BLOQUE` : >= 1 anomalie bloquante C-HARD (quel que soit le score numérique).

---

## 3. Workflow de Gouvernance à 4 Yeux

Machine à états régissant la validation des propositions d'allocation et de planning :

```
[ DRAFT ] ──(Génération / Simulation)──> [ PROPOSED ]
                                               │
                                       (Soumission demandeur)
                                               ▼
                                        [ SUBMITTED ]
                                        /           \
                 (Rejet avec motif)   /               \   (Validation second acteur)
                                     ▼                 ▼
                               [ REJECTED ]       [ APPROVED ]
                                                        │
                                                  (Publication contrôlée)
                                                        ▼
                                                  [ PUBLISHED ]
```

**Règles de Transition Impératives :**
1. **Anti-Auto-Approbation :** L'utilisateur qui a généré ou soumis une proposition ne peut pas l'approuver lui-même.
2. **Double Autorisation :** L'approbation requiert la permission `evaluations.validate` ou rôle Administrateur/Doyen.
3. **Détection d'Obsolescence (`STALE`) :** Si les effectifs ou les salles sont modifiés entre la proposition et l'approbation, la proposition passe automatiquement en état `STALE` et doit être recalculée.
4. **Publication Distribuée :** La publication est une action finale explicite qui gèle les épreuves et débloque les convocations étudiantes.
