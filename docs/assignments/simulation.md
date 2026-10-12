# Simulation

Persistée en `assignment_simulations` + `assignment_items` (technique,
pas métier). Contenu : reference, statut, paramètres, hash d'entrées,
compteurs (candidats/éligibles/affectés/non affectés/conflits),
métriques, items (groupe proposé, alternatives, checklist, raison).

Statuts : `brouillon → simulee → partiellement_confirmee → confirmee
| expiree | annulee`. Obsolescence : `hashEntrees` (paramètres + capacités
+ `max(updated_at)`) comparé à la confirmation → « relancez ».

## Pré-check (prompt master §22)

`AffectationService::precheck()` : sélectionnés, inscrits
2026-2027, sans inscription, programme/niveau compatibles, déjà
affectés, verrouillés, suspendus, éligibles. Zéro éligible → pas
d'algorithme (retour formulaire motivé). Tableau affiché sur l'écran
de résultat. Inéligibles collés → items `non_affecte` motivés
(`ELIGIBILITE`) : aucun candidat ne disparaît.
