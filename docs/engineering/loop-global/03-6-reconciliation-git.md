# LOOP GLOBAL 03.6.1 — Réconciliation des références Git

**Date :** 2026-10-25 · **Mode :** lecture seule. Références utilisées :
`loop-global/.reference-git-03.txt` (prise ~08:10), inventaire A (08:25:52,
`/tmp/inv-a.txt`), inventaire B (08:29:51, `/tmp/inv-b.txt`), HEAD `38ef149`
(référence de contenu immuable pour les fichiers suivis).

## 1. Listes réconciliées (compteurs → contenu)

| Liste | Réf. 03 (≈08:10) | Inv. A (08:25:52) | Inv. B (08:29:51) |
|---|---|---|---|
| Modifiés (` M`) | 343 | 343 | 343 |
| Non suivis (`??`) | 402 | 405 | 405 |
| Ajoutés index (`A `) | 2 | 2 | 2 |
| **Total** | **747** | **750** | **750** |

**Apparus entre référence et A (diff exact, rien de disparu) :**
`app/Modules/HubIntegration/` ; `database/migrations/2026_10_25_020000_creer_tables_hub_integration.php` ;
`docs/engineering/LOOP-07-COFFRE-FORT-DOCUMENTAIRE-QR.md`.
**A → B : listes strictement identiques** (diff vide, exit 0).

## 2. Arbre HubIntegration (travail concurrent, préservé intégralement)

| Fichier | mtime |
|---|---|
| `database/migrations/2026_10_25_020000_creer_tables_hub_integration.php` | 08:02:01 |
| `Models/ContratConnecteur.php` | 08:06:06 |
| `Models/AuditIdempotence.php` | 08:08:08 |
| `Models/RejeuIntegration.php` | 08:10:25 |
| `Services/ServiceHub.php` | 08:16:36 |
| `Http/Controllers/HubIntegrationController.php` | 08:21:31 |
| `Routes/web.php` | 08:23:35 |

## 3. Preuve que « même statut Git » ≠ « contenu identique »

`config/modules.php` et `app/Providers/ModuleServiceProvider.php` sont ` M`
dans les TROIS inventaires (compteurs inchangés), mais leurs mtime prouvent
des **ré-écritures pendant la fenêtre d'observation** : 08:27:12 et
08:29:02 (soit APRÈS l'inventaire A 08:25:52 ; la seconde 23 s avant
l'inventaire B). Les compteurs Git ne détectent PAS ces changements de
contenu : seule la datation fichier les révèle. **Toute réconciliation
fondée sur les seuls statuts serait fausse.**

## 4. Attribution des changements — preuves et limites

| Signature | Période | Preuves | Destinataire probable |
|---|---|---|---|
| (a) Refonte UI/locale non commitée | avant la mission (vue à 22:21 la veille ; 116 vues M ; `app/DataMesh/` entièrement ??) | diffs HEAD (§5) | session(s) antérieure(s) — **non attribuable** à un acteur précis sans historique de commits |
| (b) Écriture CONCURRENTE active Lot 18 `HubIntegration` | 08:02 → ≥08:29 (pendant l'audit) | mtimes §2, ré-écritures provider/config §3 | session en cours, non identifiée |
| (c) Écritures de CET audit | 07:35→08:30 | listées : uniquement `docs/engineering/LOOP-GLOBAL-*`, `loop-global/*` (+ `.reference-git-03.txt`) | cette mission |

**Limites d'attribution :** (1) les fichiers ` M` peuvent avoir été réécrits
plusieurs fois par plusieurs acteurs sans que les statuts changent ; (2)
aucun commit n'existe pour ancrer une paternité ; (3) les mtimes prouvent le
moment d'écriture, pas l'auteur. Les affirmations d'attribution se limitent
donc aux périodes, jamais aux personnes.

## 5. Fichiers des 4 échecs stables — examen de contenu (référence HEAD)

| Fichier | Statut | Diff de contenu vs HEAD (preuve) |
|---|---|---|
| `resources/views/components/alert.blade.php` | M (pré-audit) | tableau d'icônes `'✕'` (texte) REMPLACÉ par des SVG heroicons (dont le x-mark `M6 18L18 6M6 6l12 12` pour `error`) — composant modernisé |
| `resources/views/components/modal.blade.php` | M (pré-audit) | `aria-label="Fermer"` → `aria-label="Fermer la modale"` ; classes responsives ajoutées — accessibilité conservée/améliorée |
| `resources/views/inscription/existant/accueil.blade.php` | M (pré-audit) | restructuration 25 insertions/15 suppressions ; les actions présentes sont `/inscription/existant`, `/email`, `/email/confirmer`, `/verification`, `/identifier` — **`/inscription/existant/recherche` n'existe plus** dans la vue |
| `tests/Feature/LoopPopupHomeOnly04Test.php` | M (pré-audit) | test ÉTENDU localement (ajout 4bis) ; l'assertion fautive attend `action="/inscription/existant/recherche"` |
| `app/DataMesh/Produits/AnalytiqueFaculte.php` + `DataMeshContractsTest.php` | ?? tous deux (aucune référence Git) | comparaison directe : le produit expose 3 champs (`snapshot_kpi` ajouté) ; le contrat du test en énumère 2 ; la doc du produit impose « Ajouts MINEURs compatibles » (bump 1.0→1.1 requis) — **règle de versioning du produit non suivie** |

## 6. Conclusion de la réconciliation

- Aucun fichier perdu, supprimé ou réécrit par cette mission ; les 750
  entrées (743 initiales + livrables mission + travail concurrent) sont
  préservées.
- Deux flux d'écriture distincts coexistent : refonte préexistante (a) et
  session concurrente active (b) — cette dernière a continué d'écrire
  jusqu'à 08:29:02 au moins.
- Les 4 échecs stables sont intégralement expliqués au niveau du CONTENU
  (trois tests obsolètes face à une refonte UI + une vraie dérive de
  versioning de contrat Data Mesh).
