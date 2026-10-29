# Architecture — Data Mesh Observability Center

Date : 16/10/2026. Module de la plateforme universitaire (pas d'application
n8n indépendante, aucune dépendance n8n ajoutée).

## 1. Composants

| Composant | Fichier | Rôle |
|---|---|---|
| Service d'agrégation | `app/DataMesh/Observabilite/ServiceObservabilite.php` | Lecture seule : graphe, aperçu, qualité, outbox, flux, détail nœud. DTO stables v1.0, périmètre serveur, cache 60 s clé `user+faculte+annee`. |
| Contrôleur | `app/Http/Controllers/Admin/DataMeshController.php` | `index` (Blade) + 6 endpoints JSON GET. `abort_unless(can('statistiques.view'), 403)`, filtres validés, `throttle:60,1`. |
| Vue | `resources/views/admin/datamesh/index.blade.php` | Bandeau serveur (sans JS) + graphe SVG sur mesure + onglets Flux/Qualité/Outbox (Alpine, aucune lib ajoutée). |
| Routes | `routes/web.php` (`admin.datamesh.*`, 7 routes) | Convention existante : préfixe `admin`, `can:statistiques.view` (aucun privilège inventé). |
| Navigation | `resources/views/layouts/_navigation.blade.php` | Lien « Data Mesh » section Pilotage, `@can('statistiques.view')`. |
| Tests | `tests/Feature/DataMeshObservabiliteTest.php` | 11 tests, 112 assertions. |

## 2. Graphe : nœuds et relations (correction 01 : taxonomie unique)

- **État final unique par produit** (`resoudreEtatProduit`, priorité
  ERREUR > NON_VERIFIE > OPERATIONNEL ; INCONNU si qualité indisponible ;
  DEGRADE réservé sans condition d'entrée active). Compteurs exclusifs :
  leur somme vaut toujours 6. Chaque produit expose `origine_etat`,
  `diagnostic` (expurgé), `dernier_controle`, `date_mesure`.
- **Nœuds** : 6 domaines sources (regroupement logique, `non_mesure`),

- **Nœuds** : 6 domaines sources (regroupement logique, `non_mesure`),
  6 produits (état = fraîcheur mesurée, `non_verifie` si inconnue),
  1 contrôle qualité, 1 flux vertical, 1 outbox, 1 consommateur BI
  (`non_mesure` — statut non mesuré par ce module).
- **Relations `confirmee`** (code cité dans chaque relation) : chaîne du
  flux `catalogue → inscriptions → affectations → analytique`
  (`FluxVerticalDataMesh`), `analytique → BI` (source de vérité
  `BIService`), produits → contrôle (`ControlesDonnees`), domaine → produit
  (`ProduitDonnees::domaine()`).
- **Relations `declaree`** : produits → outbox — aucun appel
  `OutboxService::enregistrer()` trouvé dans `app/` ; affiché en pointillés,
  jamais présenté comme confirmé.
- **États** : `operationnel / degrade / erreur / non_verifie / inconnu /
  non_mesure`. L'absence de données n'est jamais un état sain
  (`donnees_manquantes_signalees`, bandeau ambre).

## 3. Sécurité

- Permission réutilisée `statistiques.view` (tous les rôles back-office la
  possèdent ; aucun nouveau privilège).
- `perimetreEffectif()` : refus 403 hors périmètre, forçage faculté
  restreinte, jamais de rôle/ID navigateur comme preuve.
- Outbox : table globale sans `faculte_id` → chiffres transverses signalés
  (`perimetre_applicable: false`), sans charge utile, erreurs expurgées
  (emails/identifiants → `[… expurgé]`).
- Qualité : exemples déjà anonymisés (numéros d'inscription, codes groupes).
- Aucune opération de republication/purge/relance exposée (v1 lecture seule).

## 4. Performance (mesuré 16/10/2026 ; sqlite quasi vide + MySQL dev 2037 étud.)

Cache 60 s (clé user+faculté+année, isolation garantie, testée) sur graphe,
aperçu et qualité. Règle 5 (`groupesSurcharges`) mutualisée en 1 GROUP BY
au lieu d'un COUNT par groupe (résultats identiques, testés) ; règle 8
réutilise les fraîcheurs déjà mesurées (paramètre optionnel,
rétrocompatible) ; compteurs outbox en 1 requête ; année résolue une fois.

| Endpoint (froid) | sqlite avant | sqlite après | MySQL dev avant | MySQL dev après |
|---|---|---|---|---|
| graphe | 58 | 47 | 187 | **52** |
| aperçu | — | — | 187 | **37** |
| qualité | — | — | 185 | **34** |
| flux | 11 | 11 | 11 | 11 (temps réel volontaire) |
| outbox | 5 | 3 | 5 | 3 |

Temps MySQL après : 1–181 ms, pic 50,5 Mo. Garde testée : < 60 requêtes
(sqlite) et < 5 s par endpoint. Les règles qualité ne sont recalculées
qu'au plus toutes les 60 s par périmètre.

## 5. Intégration automatisation / n8n (facultative, non implémentée)

État MESURÉ au runtime (`etatAutomatisation`) : scan de `Schedule::events()`
+ heartbeat `supervision:scheduler-dernier`. Preuves 16/10/2026 (dev) :
commande enregistrée (`artisan list` : `datamesh:outbox-publier` ✓) ;
18 événements planifiés, 0 datamesh ; heartbeat NULL (daemon inactif,
attendu en dev sans `schedule:work` — cf. GUIDE-DEPLOY §7.3) ;
`datamesh:outbox-publier` exécuté à 21:03:27Z : « Messages publiés : 0 »,
table inchangée (0 ligne). Donc : enregistrée ✓, scheduler inactif sur cet
environnement, exécutée manuellement ✓, publication confirmée : sans objet
(0 message ; chemin `marquerPublie` couvert par les tests d'idempotence).
Valeurs possibles : ACTIF
(planifié + heartbeat frais), INCONNU (planifié mais exécution non
confirmée, ou scheduler illisible), NON_CONFIGURE (0 événement datamesh —
cas actuel). Dernière exécution et dernier succès du publisher : aucune
table → INCONNU prouvé. Aucune planification effectuée (interdite sans
autorisation explicite). Aucun webhook exposé. Point d'extension documenté : les types d'événements
outbox (`type_evenement`) sont le support naturel d'un adaptateur futur
(anomalie qualité, échec outbox répété, fraîcheur dépassée, échec flux,
rapport périodique) — derrière authentification, signature, anti-rejeu,
rate-limit, idempotence et journal expurgé. Le graphe fonctionne sans n8n ;
aucun statut d'automatisation n'est affiché (non mesuré : commande
`datamesh:outbox-publier` non planifiée).
