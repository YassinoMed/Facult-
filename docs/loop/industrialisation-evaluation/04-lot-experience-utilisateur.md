# LOOP INDUSTRIALISATION ÉVALUATION — 04 · Lot expérience utilisateur

**Mode :** audit des parcours existants (aucune refonte UI non demandée, aucun contrôle retiré).

## 1. Parcours par acteur — état réel

### Administrateur / scolarité
- Examens : liste + transitions + prévisualisation (`admin.evaluations.examens.*`, LOOP EX-LIFECYCLE) ; sessions ; épreuves ; planification ; audit conformité.
- Notes : écran de saisie par épreuve (`saisie`), validation individuelle/tout, verrouillage, correction (3 étapes), historique + comparaison de versions (`HistoriqueNotesController`).
- Gouvernance : circuit §2.7/§2.8 (soumission → certification → double approbation → publication) + réconciliation tri-sources.
- Anomalies : `AnomalieController` + audit conformité par examen.

### Enseignant (portail, sans compte back-office)
- `MesNotesController` : mes épreuves, saisie individuelle/anonyme, export de préparation, historique de ses opérations (`origine` traçable).
- Mes surveillances : `MesSurveillancesController`.

### Surveillant
- Cockpit jour-J (`SurveillanceController@cockpit`), émargement (en ligne + hors ligne idempotent), incidents (`IncidentController`), emploi de surveillance (PDF/CSV).

### Étudiant
- Espace étudiant : notes et résultats (visibles selon règles existantes du module Archives/EspaceEnseignant), convocations via épreuves publiées.

### Responsable académique / gouvernance
- ExamGouvernanceController (file de validation), délibérations (simulation → préparation → validation → publication), dashboard évaluation.

## 2. Frictions identifiées (audit, non corrigées sans validation UI)

| ID | Constat | Impact | Recommandation |
|---|---|---|---|
| UX-01 | Refus de publication via `transiter` : message oriente vers le circuit — correct mais en erreur de session générique | moyen | afficher un lien direct « Soumettre au circuit » |
| UX-02 | Correction à 3 étapes sur 3 routes distinctes (demander → autoriser → re-saisie) | moyen | parcours guidé affichant l'étape courante (données métier, pas clic) |
| UX-03 | Écrans gouvernance/notes riches — nécessité de filtres persistants | faible | conserver filtres par session |
| UX-04 | Messages d'erreur métier déjà fonctionnels (`DomainException` français, ex. « Étudiant non convoqué à cette épreuve. ») | ✅ positif | garder la convention |

## 3. Principe respecté

Toute simplification future **ne retire aucun contrôle** : les Gates, Policies, verrous et motifs restent serveur. L'affichage d'une action ne remplace jamais le contrôle serveur (règle §0.2-7).

## 4. Tests

- Parcours enseignant/saisie/soumission : couverts par les suites Feature existantes (contrôleur + vues).
- Messages d'erreur : assertions sur `assertSessionHasErrors('examen'|'planification')` (EX-LIFECYCLE + notes).
- Accessibilité/responsive : **non audité en profondeur** (hors périmètre technique de la campagne) — réserve documentée.
