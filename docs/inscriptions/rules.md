# Inscriptions — règles (Phase 3)

Unicité : UQ (etudiant, année) — même étudiant + même année + même
formation + même niveau = une seule inscription active ; garde interne
service + garde contrôleur + `catch UniqueConstraintViolationException`
→ 422. Cas légitimes (transfert, réorientation, reprise) = nouvelle
année ou nouveau programme, jamais deux lignes identiques actives.

Immuabilité : `UpdateInscriptionAcademiqueRequest` n'expose que
niveau/filiere/groupe/statut/observation. `type/regime/programme_id/
niveau_id` = création uniquement (Store) ; programme/niveau évoluent via
transfert (`SituationController::transferer`, faculté alignée) ou
progression (`AcademicProgressionService`), jamais par PATCH direct.

Pédagogique : `inscrireEcue` exige ECUE rattachée à une UE, maquette ACTIVE
même programme+année, UE dans la maquette, `firstOrCreate` idempotent.
Statut non bloquant (documenté) : une inscription `valide` peut encore
recevoir une ECUE — retrait interdit si évaluée (422).

Séparation acad/péda : académique = appartenance officielle ;
pédagogique = semestre/UE/matières (Phase 4). Progression auto L1→L2…
interdite ici (Phase 6).
