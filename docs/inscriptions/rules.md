# Inscriptions — règles (Phase 3)

Unicité ANNUELLE : au plus UNE inscription académique par couple
(`etudiant`, `année universitaire`) — quel que soit le programme, le
niveau ou le statut (`en_cours`, `valide`, `ajourne`, `abandon`), et sans
suppression logique sur cette table (une suppression est physique et
libère la paire). Règle **centralisée** dans `InscriptionService`
(constante `MESSAGE_INSCRIPTION_ANNUELLE_EXISTANTE`, `inscriptionAnnuelle`,
`verifierUniciteAnnuelle`, `creerInscriptionAnnuelle`) et appliquée par
TOUS les chemins de création : formulaire admin, réinscription,
régularisation en masse, affectation automatique, import Io legacy et
définitions DataExchange. Filet final en base : index UNIQUE
`uniq_inscription_etudiant_annee` (migration
`2026_09_24_100005_create_inscriptions_academiques_table` + migration de
garde `2026_10_18_100000_verifier_unicite_inscription_annuelle`). Course
entre deux créations simultanées = erreur métier contrôlée
(`DomainException` → message utilisateur sans donnée personnelle), jamais
une erreur technique ni un écrasement silencieux. Cas légitimes : même
étudiant l'année suivante (réinscription), autres étudiants de la même
année (la clé est la PAIRE).

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
