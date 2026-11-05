# LOOP EX-LIFECYCLE — 04 · Règles de transition (automate effectif)

## Automate EFFECTIF du moteur `ExamenService`

⚠️ **Constat d'audit** : l'automate réellement exécutable = matrice déclarée ∩ garde `estCritique()`. Les examens critiques (`published, ongoing, completed, locked, archived`) ne peuvent atteindre que `archived`, **et seulement si** la matrice l'admet. Résultat : `published` et `ongoing` sont **scellés** pour le moteur simple (aucune cible accessible) — le cycle opérationnel jour-J vit dans les **épreuves**, et la seule voie d'évolution post-publication est la **nouvelle version de planning** via la gouvernance (`creerNouvelleVersionPostGel`).

| De \ Vers | planned | scheduled | published | ongoing | completed | locked | cancelled | archived |
|---|---|---|---|---|---|---|---|---|
| **draft** | ✅ moteur | — | — | — | — | — | ✅ moteur | ❌ matrice |
| **planned** | — | ✅ moteur | — | — | — | — | ✅ moteur | ❌ matrice |
| **scheduled** | — | — | ✅ **circuit gouvernance uniquement** | — | — | — | ✅ moteur | ❌ matrice |
| **published** | ❌ garde | ❌ garde | — | ❌ garde | ❌ garde | ❌ garde | ❌ garde | ❌ matrice |
| **ongoing** | ❌ garde | ❌ garde | — | — | ❌ garde | ❌ garde | ❌ garde | ❌ matrice |
| **completed** | ❌ garde | ❌ garde | — | — | — | ❌ garde | ❌ garde | ✅ moteur |
| **locked** | ❌ garde | ❌ garde | — | — | — | — | ❌ garde | ✅ moteur |
| **cancelled** | ❌ garde | ❌ garde | — | — | — | ❌ garde | — | ✅ moteur |
| **archived** | ❌ matrice | — | — | — | — | — | — | terminal |

Légende : ✅ appliqué par le moteur simple · ✅ circuit (gouvernance) · ❌ refus (garde critique ou matrice) · — sans objet.

## Contrat par transition effective

| Transition | Permission | Préconditions | Effets | Refus | Réversible ? |
|---|---|---|---|---|---|
| draft → planned | `evaluations.update` + Policy update + périmètre | matrice + non critique | statut, événement append-only, `EvaluationChanged` | erreur session « Transition … interdite » | via cancelled puis création (pas de retour direct) |
| draft/planned → cancelled | idem | idem | idem | idem | → archived seulement |
| planned → scheduled | idem | idem | idem | idem | → cancelled |
| scheduled → published | **circuit §2.7/§2.8** : `evaluations.certify`/`approve`/`publish`, acteurs tous distincts, empreinte, gel, escalade non bloquante, audit conforme | statut validation = approuvé ; 2 approbateurs distincts | statut + `published_at`, épreuves `scheduled→published` (D-06), `PlanningEvenement`, scellement SHA-256 | messages dédiés (empreinte, gel, escalade, doublon) | **non** (réouverture D-01 non prise en charge) |
| scheduled → cancelled | `evaluations.update` | matrice + non critique | statut, événement | idem draft | → archived |
| completed → archived | `evaluations.update` | matrice | statut, événement | idem | terminal |
| locked → archived | idem | idem | idem | idem | terminal |
| cancelled → archived | idem | idem | idem | idem | terminal |

## Refus — codes et raisons (explicabilité G4)

| Situation | Message | Portée |
|---|---|---|
| Publication en 1 clic | « La publication officielle exige le circuit de gouvernance (certification + double approbation). Utilisez « Soumettre au circuit de validation ». » | contrôleur |
| Examen critique → cible ≠ archived | « Examen verrouillé. » | service |
| Cible absente de la matrice | « Transition {statut} → {cible} interdite. » | service |
| Contraintes dures violées | « Publication impossible : contraintes dures violées (…) » | service (via audit) |
| Planning changé depuis certification | « Le planning a changé après certification : l'approbation est refusée… » | gouvernance |
| Publication déjà scellée rejouée | idempotence : `published_at` mis à jour, statut non rejoué | orchestrateur |

## Journalisation (avant/après)

Chaque transition effective écrit dans `evaluation_examen_evenements` : `examen_id`, `action`, `acteur_id`, `statut_avant`, `statut_apres`, `motif` (facultatif — D-02), `created_at`. **Aucun événement n'est écrit pour un refus.** Le journal est append-only au niveau modèle (update/delete lèvent une exception).

## Actions disponibles côté UI (G3)

Le serveur expose `GET …/transitions` calculé depuis le service central — l'UI n'interprete jamais la matrice elle-même. Une action affichée peut néanmoins échouer à l'application (évolution concurrente de l'état) : le message d'erreur de session est alors affiché.
