# LOOP GLOBAL 03.7 — Phases B et C : Reproduction ciblée et non-régression

**Date :** 2026-10-25 · **Statut des deux phases : `NOT_EXECUTED`** —
justification : la condition d'arrêt de la Phase A est remplie (écritures
applicatives concurrentes à 08:41:03 et 08:41:18, pendant la fenêtre
d'observation ; détail dans `03-7-stabilisation.md`). Le mandat interdit
explicitement de lancer la reproduction dans ce cas. Aucun résultat n'est
fabriqué pour ces phases.

## 1. Phase B — Reproduction ciblée : `NOT_EXECUTED`

**Ce qui aurait été exécuté si la stabilité avait été démontrée** (consigné
pour la reprise, commandes exactes) :

```
php artisan test tests/Feature/ImportTemplateTest.php
php artisan test tests/Feature/PopulationTest.php
php artisan test tests/Feature/SeparationProfilsTest.php
php artisan route:list --path=imports          # vérification routes/paramètres
```

**Acquis antérieurs (ne remplacent pas la reproduction)** — traces de
LOOP-03, horodatées, sans ré-exécution ici :
- T0 (~08:00) : les 3 suites **passaient** (groupes G3/G9).
- T1 (08:06-08:12, suite complète) et T2 (08:19, isolation) : **échec 11/11**,
  signature dominante `UrlGenerationException` « Route
  [admin.imports.templates.erreurs] [URI: admin/imports/rapports/{rapport}/erreurs]
  [Missing parameter: rapport] » + `ErrorException` sur les parcours d'import.
- 03.6 : ré-écritures concurrentes de `ModuleServiceProvider.php` (chargeur
  des routes de modules) à 08:29, puis du contrôleur/test HubIntegration à
  08:41 — hypothèse causale (enregistrement de module en flux) documentée
  **non prouvée**, à instruire uniquement sur arbre figé.

**Classification en vigueur inchangée :** `BLOCKED_ENVIRONMENT` (contamination
par mutation concurrente) — ni défaut du code à T0, ni défaut démontré du
travail concurrent.

## 2. Phase C — Suite complète : `NOT_EXECUTED`

Sous-condition de la Phase B (« si les trois suites sont reproductibles et
si l'environnement reste stable ») : non remplise par construction. La
dernière exécution complète connue reste celle de LOOP-03 T1
(4 790 pass / 15 échecs / 33 skips — dont 11 échecs désormais attribués à la
contamination et 4 stables réconciliés en 03.6.4).

## 3. Conditions de reprise (inchangées, précisées)

1. Deux inventaires espacés d'au moins 15 minutes SANS AUCUNE écriture dans
   les zones applicatives — comparaison par **empreintes** (les listes Git
   ne suffisent pas, preuve faite deux fois : 03.6.1 et 03.7 Phase A).
2. Coordination avec l'auteur du travail concurrent Lot 18 (sans hypothèse
   d'identité) pour convenir d'une pause d'écriture.
3. Alors seulement : Phase B (3 suites, traces complètes, vérification des
   routes/paramètres/ordre de chargement), puis Phase C (suite complète)
   avec la typologie exigée : réussis / reproductibles / intermittents /
   ignorés / non exécutés / blocages d'environnement.
