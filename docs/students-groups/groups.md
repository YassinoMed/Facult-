# Groupes (§4-8, §30-31)

Modèle : `Schedule\Models\Group` (`code unique, nom, faculte_id, niveau L1..D3,
programme_id?, niveau_id?, annee_universitaire_id?, capacite?, actif`).
Types TD/TP via `Enseignement\Models\SousGroupe` (`groupe_id, code, type td/tp/autre`).

## Source unique

Effectif = `affectations actives` (`effectifActuel()`, `withCount affectations_actives`).
Ne jamais compter via `etudiants.groupe_id` (pointeur seulement).

## Liste (`GroupeController::index`)

Filtres : `search, programme_id, niveau, annee_universitaire_id, faculte_id
(super-admin), statut actif/inactif, occupation vide/partiel/presque_complet
(≥80 %)/complet/sureffectif/avec_etudiants, capacite_min, effectif_min, places_min`.
Stats : groupes, effectif, complets, vides, presque_complets, capacité totale,
places disponibles, remplissage moyen.

## Fiche (`show`)

Effectif/capacité, H/F, programme, niveau/année, alertes (inactif, vide,
complet, sureffectif, sans planning, sans programme), onglets étudiants /
planning / enseignement / sous-groupes (masqués si vides).

## Étudiants d'un groupe (`etudiants`)

Moteur central forcé `groupe_id` + `search/sous_groupe_id/statut/tri` ;
colonne sous-groupe via affectation active ; liens CSV/PDF (même population).

## Suppression

Refusée si affectations actives ou plannings (jamais de cascade silencieuse).
