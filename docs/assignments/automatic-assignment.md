# Affectation automatique équilibrée

Workflow : paramètres → éligibles → contraintes → **simulation sans écriture**
→ conflits → contraints d'abord → équilibrage → MOVE/SWAP → validation
globale → prévisualisation → **confirmation par groupe** → transaction → audit.

## Architecture (amélioration de l'existant, zéro doublon)

| Couche | Réutilisé | Créé |
|---|---|---|
| Éligibilité | `Etudiant::estSuspendu()`, inscriptions | `AssignmentEligibilityResolver` |
| Contraintes unitaires | `AffectationService::controler` (10 codes) | — |
| Conflits horaires | `ScheduleSlot::chevauche()` | `AssignmentConstraintEngine` (SCHEDULE, CAPACITY simulée, FACULTY_SCOPE) |
| Simulation | — | `AssignmentSimulationService` |
| Confirmation | `AffectationService::affecter` (idempotent, verrou capacité) | `AssignmentConfirmationService` |
| Persistance | `affectations_groupe` (+`verrouille`, `confirme_*`) | `assignment_simulations`, `assignment_items` |
| Audit | `AuditService::tracer` | actions `assignment.*` |
| Export | `ValueNormalizer::celluleExport` | CSV streaming |
| Routes | `admin.affectations.*`, permissions `archives.view/update`, `data.export` | `admin.affectations.automatique.*` |

## Ordre absolu

VALIDITÉ > CONFLITS > CAPACITÉ > MÉTIER > ÉQUILIBRAGE > OPTIMISATION.
Une préférence souple ne viole jamais une contrainte dure (§16).

## Piège Laravel (02/10/2026)

`collect()->sortBy([fn...])` appelle chaque closure comme comparateur
`($a, $b)` — utiliser UNE closure retournant un tableau (comparaison
lexicographique), sinon aucun équilibrage réel (952 étudiants dans un
groupe illimité). Non-régression testée.

## Aléatoire contrôlé par seed (§8, 02/10/2026)

`assignment_simulations.seed` (défaut aléatoire stocké) : le départage
utilise `hash(seed:id)` — même seed + mêmes données = même répartition
; nouveau seed = répartition équivalente différente (contraintes
identiques). Bouton « Régénérer » (lignée `simulation_origine_id`).
L'aléatoire ne s'applique qu'APRÈS contraintes et équilibrage.

## Plan capacitaire (§12-14, §24)

`planCapacite()` : reste-à-placer (non-affectés qui auraient un groupe
sans la contrainte capacitaire) → `ceil(reste / médiane)` groupes
supplémentaires + capacité recommandée + code suggéré (préfixe commun
+ suivant). Stocké en métriques (`manque`, `groupes_supplementaires`,
`capacite_recommandee`, `code_groupe_suggere`, `taux_affectation_pct`,
`groupes_utilises/satures`, `capacite_totale/disponible`). Panneau
d'alerte affiché si besoin. Création = acte administratif.

## API v1 (§26)

`AssignmentApiController` : `GET/POST assignments/*` (scopes
`assignments:read|write`, throttle, périmètre 404 anti-BOLA, sans PII
— ids uniquement). Confirmation par tranches (`tranche`, répéter si
`terminee=false`). Spécifié dans `docs/openapi.yaml` (dérive testée).

## Matrice candidats (§6/§20-21, 02/10/2026)

Chaque item persiste `candidats` (TOUS les groupes : compatible,
motif, places restantes) + `flexibilite` (filtrable : 1 / 2-3 / 4+ /
0) + libellé (Très contraint → Très flexible). Construction :
`motifIncompatible()` (miroir documenté de `controler()`, parité
testée) + matrices partagées par signature + forçage du sélectionné.
Vue : colonne « Groupes possibles » dépliable (✓/✗ + motifs +
places + « ← proposé »). Mesuré FSEGS : ~10 s / < 100 Mo.

## Performance (§39, mesuré FSEGS : 4 958 étudiants × 162 groupes)

`contexteBulk` (4 requêtes) + créneaux préchargés + verdict léger par
signature + matrices partagées + inserts bulk + `estSuspendu()` sur
relations chargées (même règle, zéro requête). Nouveau moteur avec
matrice complète : **~10 s** ; ancien `repartitionAutomatique` :
9,9 s (mêmes 3 939 proposés). Mémoire < 100 Mo.

## Pistes correctives (affichage seul, règles inchangées)

Un étudiant bloqué n'est jamais une impasse muette : chaque écran de
résultat (`masse`, `auto`, `automatique`) affiche une **piste** —
inscription manquante (lien vers la fiche pour (ré)inscrire) et
groupes compatibles par signature. Les IDs collés mais inéligibles
apparaissent comme `non_affecte` motivés (`ELIGIBILITE`).
