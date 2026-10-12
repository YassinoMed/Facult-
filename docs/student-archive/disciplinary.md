# Disciplinaire (Phase F)

Constat F-AUDIT : NÉANT total (aucune table, colonne, sanction, commission).
Création minimale justifiée §7/§13 : `student_sensitive_records`
(`type=disciplinaire`) — pas de table dédiée (§8).

## Cycle de vie

`ouvert → en_cours → cloture → archive` (transaction `update` validée,
`statut` whitelisté). AUCUNE suppression physique (soft delete natif,
jamais exposé en UI). Chaque transition auditée (`sensitive_update`).

## Champs utilisés

Titre neutre (`Incident bibliothèque — commission du …`), description =
faits et procédure, `date_debut/date_fin`, statut, niveau (défaut
confidentiel), pièce GED optionnelle (décision scannée), `created_by/
updated_by`, rétention extensible.

## Accès

`disciplinary.view` / `disciplinary.download` — super_admin +
admin_scolarite uniquement (même faculté : rien par défaut, §16).
Timeline `sensible` séparée (titres neutres type + statut).
Pas d'API, pas de recherche, pas d'export standard.
