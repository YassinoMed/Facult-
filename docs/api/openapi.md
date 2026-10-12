# API — OpenAPI (Phase 11 §11.32)

Référence : `docs/openapi.yaml` (paths + schéma `request_id`) ;
détail opérationnel : `docs/API.md`. Dérive verrouillée par
`QualiteApiSupervisionTest` : couverture routes + **contrat scopes**
(toute route authentifiée exige `scope:`). Nouvelle route =
entrée OpenAPI + scopes + throttle, sinon la CI échoue.
