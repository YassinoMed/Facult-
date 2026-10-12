# Audit — progression académique LMD (sans modification de code)

## Où est calculée la moyenne

`AcademicCalculationService::moyennePonderee/moyenneGenerale/synthese`
+ `CalculEvaluationService::moyenneUe/syntheseEtudiant` (depuis `notes`
officielles). Règles : `RegleEvaluationService` (seuils 10.0/8.0, arrondi).
Uniquement validation UE/session — AUCUNE règle de passage.

## Où sont calculés les crédits

`creditsObtenus`, `recalculerSemestre/Inscription`, `DecisionDeliberation`
(`credits_obtenus/total`, `ues_validees/total`).

## Où est déterminée l'admission

Nulle part automatiquement. `proposerDecision()` (privé) : `admis` si
moyenne ≥ seuil ET crédits complets sinon `admis_compensation` ;
`rattrapage` / `ajourne` / `a_examiner`. `redouble/exclu/
admis_conditionnel` = jury manuel (`decider()` + optimistic locking).
`valider()` (quorum) → `publier()` (`publie=true`). Zéro effet inscription.

## Décisions stockées

`evaluation_decisions` : `deliberation/etudiant/inscription_academique`,
`moyenne, credits, ues, decision` ∈ {admis, admis_compensation,
admis_conditionnel, ajourne, rattrapage, redouble, exclu, a_examiner},
`publie`. Consommée en lecture seule (archive, timeline).

## Niveau actuel

Triple source : `etudiants.niveau` (legacy) + `etudiants.programme_id/
niveau_id` (pointeurs) vs `inscriptions_academiques` par année (historique).
Référence : l'inscription source. `AffectationService` compare encore le
string legacy (hors scope).

## Niveau suivant

`InscriptionService::niveauSuivant()` : même `programme_id`, `ordre`
supérieur, sinon niveau courant (plafond). Cross-programme = manuel
(`reinscrire` avec `programme_id/niveau_id`, `transferer`, `rattacher`).

## Redoublement / changement formation / Licence→Master

Aucun moteur. `reinscrire()` manuelle, aveugle à la décision jury.
`transfert` (reset niveau) + `rattacher` (garde niveau∈programme).
Admission Master : 0 occurrence (ni modèle, ni workflow).

## Règles existantes à réutiliser

`TRANSITIONS` inscriptions (`en_cours→valide/ajourne/abandon`),
unicité `(etudiant, annee)`, `AnneeUniversitaire::suivante/active`,
`SituationEtudiant(reinscription)`, audit `creation_inscription`,
`PopulationChanged`, throttle/validation contrôleurs.

## Manques justifiant UN service

Mapping `decision→action` (admis→N+1, redouble/ajourne→même niveau,
exclu/a_examiner→blocage, fin de cycle→admission requise), dry-run,
batch promo, idempotence centralisée, notification `inscription.*`
(inexistante). Conclusion : AUCUN moteur — créer
`AcademicProgressionService`, AUCUNE table (historique = inscriptions +
décisions + situations + archive).
