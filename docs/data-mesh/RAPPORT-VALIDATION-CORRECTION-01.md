# Rapport de validation — LOOP-DATAMESH-OBSERVABILITY-CORRECTION-01

Date : 16/10/2026. Workflow : LAN → DEV → PERFORMANCE → AUDIT →
CORRECTION → VALIDATION → AUTO-PASSAGE. Aucune donnée métier modifiée,
aucun `migrate:fresh`/`db:wipe`, aucun commit/push/déploiement.

## 1. Fichiers modifiés (3) et créés (2)

- Modifié : `app/DataMesh/Observabilite/ServiceObservabilite.php`
  (taxonomie, compteurs exclusifs, qualité+v2 cache/dedup, automatisation
  mesurée, repli acteur, politique fraîcheur, `fuseau: UTC`).
- Modifié : `resources/views/admin/datamesh/index.blade.php` (bandeau,
  acteur, automatisation, diagnostic, unités qualité — jamais formatée par
  pint, conformément à la convention).
- Modifié : `tests/Feature/DataMeshObservabiliteTest.php` (11 → 20 tests).
- Créé : `docs/data-mesh/LAN-OBSERVABILITY-CORRECTION-01.md` (inventaire).
- Créé : `docs/data-mesh/RAPPORT-VALIDATION-CORRECTION-01.md` (ce fichier).
- Modifié : `docs/data-mesh/ARCHITECTURE-OBSERVABILITY.md` (taxonomie,
  perfs, automatisation).

## 2. Causes démontrées et corrections

| # | Anomalie | Cause démontrée | Correction |
|---|---|---|---|
| A | 4+1+2=7>6 | `en_erreur` (overlay qualité) non exclusif, juxtaposé aux états fraîcheur | `resoudreEtatProduit()` : état unique, priorité ERREUR>NON_VERIFIE>OPERATIONNEL, compteurs exclusifs (somme=6 testée) + `origine_etat`/`diagnostic` par produit |
| B | 2 005 anomalies | 2000 `inscriptions_sans_programme` (seed sans programme) + 3 `capacites_inconnues` + 2 `fraicheur_produits_depassee` (tinker) | `total_violations`, `unite_comptage` par règle, `deduplique_intra_regle: true`, `deduplication_transverse: false` + note explicite ; rapport ci §4 |
| C | Publisher « non mesuré » | 18 événements planifiés au runtime, 0 datamesh (tinker) | `etatAutomatisation()` mesuré : `NON_CONFIGURE` + heartbeat + derniers/succès/prochain = INCONNU prouvé ; zéro message ≠ sain (`sain_interprete: false`) |
| D | `date_reference = now()` | Date de calcul présentée comme mesure | `calcule_le` vs `date_reference` (= max mesures connues), `politiqueFraicheur()` exposée, `fuseau: UTC` |
| E | Rôle acteur « — » | Colonne `utilisateur_id` NULL ; acteur réel dans `nouvelle_valeur.utilisateur_id` (trace 27 → id 3 = super_admin) | Repli JSON → `users.role` (rôle seul), `acteur_compte_supprime` si introuvable |
| F | Filtres graphe | Vérifiés : filtrage nœuds + relations cohérent côté client | États enrichis exposés (origine/diagnostic/dernier contrôle), testés côté backend |
| G | Lecture seule | Vérifiée par test : 0 écriture (historique/outbox inchangés sur 7 appels) | Qualité désormais cachée 60 s (borne le recalcul) |

## 3. Commandes exécutées et résultats

| Commande | Résultat |
|---|---|
| `test --filter=DataMeshObservabiliteTest` | **20/20, 304 assertions** |
| `test --filter=DataMesh` (non-régression) | **75/75, 1171 assertions** |
| `test --filter=Qualite\|BiCenter\|Navigation\|BiTest` (voisins) | **57/57, 307 assertions** |
| `pint` (PHP ciblés, jamais Blade) | 2 fichiers normalisés, 20/20 rejoués OK |
| `route:list --name=datamesh` | 7 routes |
| Mesure requêtes froid/chaud (test temporaire, supprimé) | graphe 58/1, aperçu 43/1, flux 11/11, qualité 30/2, outbox 5/5 ; 1–74 ms ; 117 Mo |
| Tinker lecture seule (dev MySQL) | 2037 étud., 2060 inscr., 0 affectation, outbox 0/0/0, 2 traces flux, 18 événements scheduler |

## 4. Rapport explicatif des 2 000 anomalies bloquantes

Origine : règle `inscriptions_sans_programme` — les inscriptions seedées
(2060 valides) portent un `programme_id` null ou hors référentiel actif.
Chaque ligne = 1 inscription distincte (pas de doublon intra-règle), mais un
même dossier peut être compté dans plusieurs règles (pas de déduplication
transverse — affichée comme telle, aucun total dédupliqué annoncé).
Aucune donnée modifiée, aucune correction automatique lancée : le
rattrapage reste une décision métier dans les modules propriétaires.

## 5. Tests non exécutés et raisons

- Capture visuelle du SVG (aucun outil de screenshot dans l'environnement).
- Charge réaliste volumineuse : mesures sur sqlite quasi vide ; à volume
  dev (2000+ lignes) les requêtes sont identiques (agrégats) mais les temps
  grandiront — non mesuré.
- Suite complète du projet (temps) : périmètre limité aux suites Data Mesh
  + voisines, conformément à la consigne de non-régression ciblée.

## 6. Réserves restantes

1. Graphe froid = 58 requêtes (marge fine vs borne 60) : dominé par les 8
   règles + 6 fraîcheurs ; toute règle supplémentaire l'approchera.
2. `DEGRADE` produit sans condition d'entrée (choix anti-faux-positif).
3. Heartbeat scheduler non observé ici (cache vide en test) : branche ACTIF
   non prouvée en exécution réelle.
4. Rendu visuel non vérifié par capture.

## 7. Verdict final : `PASS_WITH_RESERVATIONS`
