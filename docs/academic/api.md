# API académique (Phase 6 §38)

`GET /api/v1/students/{student}/results` (scope `grades:read`,
`throttle:api`, périmètre faculté 404 anti-BOLA) : par inscription
(année, niveau, moyenne, crédits [obtenus,total], `dettes` = UE non
validées, `decision_proposee` moteur, `decision` publiée si présente).
Référencé dans `docs/openapi.yaml` (test de dérive vert).

Documents : métadonnées via `students/{id}/documents`
(`documents:read`, jamais de chemin) ; téléchargements et génération
uniquement web (gated + audit) ; vérification publique sans token.
