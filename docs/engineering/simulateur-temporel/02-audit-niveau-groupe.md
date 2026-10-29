# SIMULATEUR TEMPOREL — Audit hiérarchie Niveau → Groupe + plan (SANS modification)

**Date :** 2026-10-25 · **Mode :** lecture seule — aucun code modifié, en
attente d'approbation explicite du plan (clause de sécurité d'exécution).

## 1. Audit — relation exacte `Groupe` ↔ `Niveau` (preuves lues)

| Fait | Preuve |
|---|---|
| **Relation DIRECTE existante** | `Schedule\Models\Group` : colonne `niveau_id` (Phase 2, additive) + relation `niveauRef(): BelongsTo(Niveau::class, 'niveau_id')` (l.71-73) |
| Colonnes `groups` | `code, nom, faculte_id, niveau (string legacy), filiere, actif` + `programme_id, niveau_id, annee_universitaire_id, capacite, capacite_illimitee` (fillable l.25-27) |
| `niveau_id` **NULLABLE** | rattachement LMD « Phase 2 (additif, nullable) » — des groupes existent potentiellement SANS niveau : à traiter explicitement, jamais par une fausse valeur |
| Relations indirectes | `Etudiant.groupe_id → Group` (l.33/123) ; `AffectationGroupe → groupe` (scopeActives, par année) ; `Niveau → programme_id` (référentiel) ; `Group.faculte()` |
| Chaîne hiérarchique complète | faculté → programme (`programme_id`) → niveau (`niveau_id`) → groupe — chaque maillon EST en base |
| Pattern options en cascade | `EntitesReferentiel::options($nom, ?int $faculteId)` (référentiel) — pattern de liste filtrée par périmètre à REPRENDRE pour groups (pas doublon : groups vit dans Schedule) |
| Endpoint groups/niveau | `Admin/GroupeController` (CRUD Schedule) existant — à réutiliser/étendre si un filtre `niveau_id` s'y trouve déjà ; sinon UNE action `options` additive dans le module simulateur |

**Conclusion : la structure demandée est ÉTABLIE par le code et le schéma —
aucune migration, aucun modèle à créer.** Le travail est purement additif
(filtres/validation dans le simulateur).

## 2. Plan proposé (après approbation)

| Lot | Contenu | Complexité |
|---|---|---|
| HG-01 | `criteres` du scénario étendus : `niveau_id` (nullable = « Tous les niveaux », cohérent : la simulation pilote est par cohorte/niveau) + `groupes[]` (multi, tous du niveau choisi). Validation serveur DANS `ServiceSimulationTemporelle::validerCriteres` : chaque groupe doit avoir `niveau_id` = niveau choisi, `actif`, et sa faculté dans le périmètre (sinon 422) ; groupe inexistant → 422 explicite ; groupe sans rattachement niveau → 422 « sans rattachement LMD » (jamais rattaché implicitement) | M |
| HG-02 | Endpoint lecture `GET admin/simulation-temporelle/options/groupes?niveau_id=X` (permission `statistiques.view`, périmètre via `FacultePerimetre` + `groups.faculte_id`) retournant groupes actifs du niveau dans le périmètre — AVANT création : vérifier si l'index `GroupeController` filtre déjà par `niveau_id` (si oui : documenter et réutiliser) | S |
| HG-03 | Contrat cascade (consommé par l'UI future, JSON comme toute la série) : `GET options/niveaux` (périmètre) → changement de niveau → re-fetch groupes → **réinitialisation enfant en cascade = responsabilité client MAIS validée serveur** (un groupe incompatible soumis est rejeté 422 : le serveur reste l'autorité) | S |
| HG-04 | Traçabilité/reproductibilité : `niveau_id` + `groupes[]` entrent dans `criteres` → **déjà couverts par `hash_entrees`/`hash_resultat` et l'idempotence existants** (aucune table à ajouter) ; l'adaptateur filtre l'état initial par niveau sélectionné si présent | S |
| HG-05 | Tests exigés : groupe du niveau accepté ; groupe d'un AUTRE niveau rejeté 422 ; groupes hors périmètre rejetés (requête HTTP forgée incluse) ; niveau/groupe inexistants → 422 explicite sans fausse valeur ; changement de niveau sans groupes → critères valides (« Tous ») ; non-régression ST existants + Lot 14 | M |

**Sécurité d'exécution respectée :** aucune migration ; réutilisation des
modèles/relations/scopes existants ; aucun doublon d'endpoint (vérification
préalable HG-02) ; validation serveur = autorité ; périmètre vérifié à
chaque requête ; le protocole de stabilité (empreintes, session concurrente
Lot 19) s'applique avant chaque lot.

## 3. Décisions attendues

1. Approuver le plan HG-01→HG-05 (ou l'ajuster).
2. Confirmer la sémantique « Tous les niveaux » (niveau_id null = simulation
   toutes cohortes, comportement ACTUEL inchangé — rétrocompatible).
3. Trancher HG-02 : extension du contrôleur existant si filtre présent,
   sinon action options additive dans le module simulateur.
