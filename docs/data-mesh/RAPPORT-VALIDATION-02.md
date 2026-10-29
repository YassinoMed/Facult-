# Rapport de validation — LOOP-DATAMESH-OBSERVABILITY-02

Date : 16/10/2026. Départ : `PASS_WITH_RESERVATIONS`. Aucune donnée métier
modifiée, aucun `migrate:fresh`/`db:wipe`, aucune correction auto, aucune
planification prod, aucun commit/push/déploiement.

## 1. Fichiers modifiés (4) et créés (3)

- Modifié : `app/DataMesh/Qualite/ControlesDonnees.php` — règle 5 en 1
  GROUP BY (résultats identiques) ; `controler()` + 4e param optionnel
  `?array $fraicheursPrecalculees` (rétrocompatible, tous les appelants
  existants ≤ 3 args) pour la règle 8.
- Modifié : `app/DataMesh/Observabilite/ServiceObservabilite.php` —
  fraîcheurs threadées, compteurs outbox 1 requête, DEGRADE formalisé,
  compteurs exclusifs (+`degrades`).
- Créé : `config/datamesh_observabilite.php` (`seuil_degrade_avertissements`, défaut 1, via `DATAMESH_SEUIL_DEGRADE`).
- Modifié : `tests/Feature/DataMeshObservabiliteTest.php` (20 → 23 tests).
- Modifié : `resources/views/admin/datamesh/index.blade.php` (compteur dégradés).
- Modifié : `docs/data-mesh/ARCHITECTURE-OBSERVABILITY.md` (perfs, preuves).
- Fichiers temporaires de mesure (test + 2 commandes) : créés, exécutés, **supprimés**.

## 2. P1 — Coût du graphe : audit, causes, avant/après

Audit froid (décomposition par table) : doublons de 2e passe fraîcheur
(règle 8 relisait le catalogue : facultés/programmes/niveaux/enseignements/
décisions MAX ×2), N+1 règle 5 (1 COUNT × 151 groupes actifs), 3 compteurs
outbox, double résolution de l'année active. Règles 1-4/6-7, BI (règle 7),
audit et auth : incompressibles sans retirer des contrôles (interdit —
conservés).

| Endpoint froid | sqlite avant | sqlite après | MySQL (2037 étud.) avant | après |
|---|---|---|---|---|
| graphe | 58 | **47** (−19 %) | 187 | **52** (−72 %) |
| aperçu | 43 | — | 187 | **37** |
| qualité | 30 | — | 185 | **34** (−82 %) |
| flux | 11 | 11 | 11 | 11 (temps réel volontaire, non caché) |
| outbox | 5 | 3 | 5 | 3 |

Temps MySQL après : 1–181 ms, pic mémoire 50,5 Mo. Chaud : 1–2 requêtes
(cache 60 s). Sécurité/états/périmètre inchangés ; aucune mesure remplacée
par un état sain ; isolation du cache testée (aucun périmètre servi à un
autre utilisateur).

## 3. P1 — Automatisation : 4 niveaux distingués (preuves datées, dev)

- Commande enregistrée : ✓ (`artisan list` → `datamesh:outbox-publier`).
- Scheduler actif : 18 événements au runtime, 0 datamesh → `NON_CONFIGURE` ;
  heartbeat `supervision:scheduler-dernier` = NULL (daemon inactif en dev,
  attendu sans `schedule:work`, cf. `docs/GUIDE-DEPLOY.md` §7.3).
- Commande exécutée : ✓ 16/10/2026 21:03:27Z, « Messages publiés : 0 »,
  table `data_mesh_outbox` inchangée (0 ligne) — sans effet, environnement
  dev autorisé (jamais la prod, jamais de nouvelle planification).
- Publication confirmée : sans objet (0 message) ; chemin `marquerPublie`
  couvert par `DataMeshOutboxAndIdempotencyTest`.

## 4. P2 — DEGRADE formalisé

Critères mesurables : avertissements avérés sur le produit ≥
`datamesh_observabilite.seuil_degrade_avertissements` (défaut 1), sans
bloquante (`capacites_inconnues` → affectations_groupes,
`incoherences_effectifs_agregats` → analytique_faculte). Priorité finale :
ERREUR > DEGRADE > NON_VERIFIE > INCONNU > OPERATIONNEL (un signal avéré
prime sur l'absence de mesure ; NON_VERIFIE reste l'absence totale de
signal). Testé (DEGRADE + priorité ERREUR).

## 5. Commandes et résultats

| Commande | Résultat |
|---|---|
| `test --filter=DataMeshObservabiliteTest` | **23/23, 314 assertions** |
| `test --filter=DataMesh` | **78/78, 1181 assertions** |
| `test --filter=Qualite\|BiCenter\|Navigation\|BiTest` | **57/57, 307 assertions** |
| `test --compact` (suite complète) | 3445 tests, **3411 passés**, 12 échecs hors périmètre (voir §7), 22 ignorés |
| `pint` (PHP ciblés, jamais Blade) | normalisé, 23/23 rejoués OK |
| `datamesh:outbox-publier` (dev) | 0 publié, 0 écriture (preuve §3) |
| Benchmarks MySQL lecture seule (commande temporaire supprimée) | §2 |

## 6. Tests non exécutés et raisons

- Capture navigateur du SVG : aucun outil de capture dans l'environnement.

## 7. Suite complète du projet : 3411/3445, 12 échecs hors périmètre

Les 12 échecs sont indépendants du périmètre LOOP-02 (vérifié : aucun ne
référence `ControlesDonnees`/DataMesh sauf un faux positif lexical —
`test_observabilite_zero_pii_dans_les_logs`, non défaillant, porte sur les
logs) : libellés de validation modifiés par des chantiers parallèles
(`SaisieIdentificationStricteTest` ×4 : messages attendus vs effectifs),
garde-fous N+1 aux bornes exactes (`InscriptionFileTraitementTest`,
`InscriptionPerformanceFiabiliteTest`, `ArchivesFicheLoop3Test` :
25 < 25 ; `PopulationPhase2CompleteTest` : 23 vs 21), assertions
d'accessibilité et de contenu (`AccessibiliteParcoursTest` ×2,
`AffectationEnseignantExamenTest`, `FaqAideScolariteTest`). Toutes les
suites exerçant le code modifié par cette boucle (DataMesh 78/78,
observabilité 23/23, qualité/BI/voisins 57/57) sont vertes.

## 8. Réserves restantes

1. Règle 7 (BI, ~12 requêtes) et règles 1-4/6 : incompressibles sans
   changer les règles — le graphe froid restera ~45-55 requêtes.
2. Mesures MySQL à froid unique ; variabilité non caractérisée.
3. Branche ACTIF de l'automatisation non prouvée en exécution réelle
   (heartbeat absent de cet environnement).
4. Rendu visuel non capturé.

## 9. Verdict final : `PASS_WITH_RESERVATIONS`
