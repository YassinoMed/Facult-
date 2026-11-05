# LOOP INDUSTRIALISATION ÉVALUATION — 16 · Déploiement et retour arrière

## Pré-requis compatibilité (AI1)

- PHP/Laravel/Dépendances : **aucune mise à jour effectuée** (aucune nécessité).
- Schéma : **aucune migration** requise par cette campagne (la garde append-only est au niveau modèle Eloquent — aucun DDL).
- Cache/config : `php artisan config:clear` non requis (aucune configuration nouvelle) ; aucune feature flag nouvelle (réutilisation du mécanisme `EnsureFeatureEnabled` existant).

## Activation contrôlée (AI3)

| Élément | Activation | Kill switch |
|---|---|---|
| Garde append-only historique notes | immédiate (code) — comportement de protection uniquement | rollback code (aucune donnée perdue) |
| Corrections du test obsolète | immédiate (tests) | — |
| Toute évolution D-11/D-12 | **bloquée** jusqu'à décision métier | — |

## Ordre de déploiement

1. Déployer le code (aucun DDL) → les gardes sont actives dès le boot.
2. Vérifications smoke : saisir un lot de test → valider → tenter `update` d'une ligne d'historique (attendu : `DomainException`).
3. Surveiller les logs `evaluation.*` (aucune erreur inattendue attendue).

## Retour arrière (AI4) — non destructif

- Rollback du code : restaure le comportement antérieur ; **aucune donnée historique supprimée** (les lignes créées restent).
- Aucune migration à annuler ; aucune vue à restaurer ; les décisions et notes officielles produites restent intactes.
- Jobs en attente : re-traités par le code antérieur sans effet destructif (idempotence de saisie conservée).
