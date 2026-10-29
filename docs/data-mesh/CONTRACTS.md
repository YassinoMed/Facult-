# Spécification Technique des Contrats de Données — Data Mesh

Ce document décrit formellement les spécifications d'interfaces, les formats d'échange DTO, les enveloppes d'erreurs et les protocoles d'événements outbox régissant le Data Mesh.

---

## 1. Interface Commune : `ProduitDonnees`

Tout produit de données exposé au sein du catalogue implémente l'interface PHP suivante :

```php
namespace App\DataMesh\Contrats;

use App\Models\User;

interface ProduitDonnees
{
    /** Code unique stable du produit (ex. `inscriptions_validees`). */
    public function code(): string;

    /** Domaine propriétaire responsable (ex. `scolarite`). */
    public function domaine(): string;

    /** Version sémantique du contrat (ex. `1.0`). */
    public function version(): string;

    /** Descripteur complet du produit de données. */
    public function decrire(): array;

    /** Schéma formel des attributs exposés. */
    public function schema(): array;

    /**
     * Lecture des données du produit.
     * 
     * @param array{utilisateur: User, faculte_id?: int, ...} $contexte
     * @return array<string, mixed>
     * @throws \Illuminate\Auth\Access\AuthorizationException
     */
    public function donnees(array $contexte = []): array;

    /** Date ISO-8601 de dernière mise à jour de la source. */
    public function fraicheur(): ?string;
}
```

---

## 2. Enveloppe Standard de Réponse et Erreurs

Lorsqu'un produit ou une API Data Mesh est sollicité, les données sont servies soit sous forme de DTO natif en code PHP, soit encapsulées dans une enveloppe JSON standardisée :

### Format de Succès
```json
{
  "ok": true,
  "produit": "inscriptions_validees",
  "version": "1.0",
  "horodatage": "2026-10-16T18:00:00+01:00",
  "donnees": {
    "total": 150,
    "inscriptions": [ ... ]
  }
}
```

### Format d'Erreur Standardisé (Fail-Safe, Sans PII)
```json
{
  "ok": false,
  "code": "ACCES_REFUSE",
  "message": "Produit de données : faculté hors périmètre.",
  "horodatage": "2026-10-16T18:00:00+01:00"
}
```

---

## 3. Contrat du Transactional Outbox

Le composant Outbox permet aux domaines d'émettre des événements d'état post-commit de façon fiable.

### Schéma de la Table `data_mesh_outbox`
| Colonne | Type SQL | Rôle |
|---|---|---|
| `id` | `bigint unsigned auto_increment` | Identifiant technique séquentiel |
| `type_evenement` | `varchar(100)` | Nom de l'événement (ex. `scolarite.inscription_validee`) |
| `type_agregat` | `varchar(100)` | Type d'entité source (ex. `inscription_academique`) |
| `agregat_id` | `bigint unsigned` | Clé primaire de l'entité source |
| `cle_deduplication`| `varchar(120) unique` | Clé unique d'idempotence |
| `charge_utile` | `json` | Données de l'événement (DTO sans PII brute) |
| `statut` | `enum('pending', 'published', 'failed')` | État du message dans le cycle d'expédition |
| `tentatives` | `tinyint unsigned` | Nombre d'essais d'expédition |
| `derniere_erreur` | `text nullable` | Message d'erreur assaini en cas d'échec |
| `publie_le` | `timestamp nullable` | Date effective d'expédition |
| `created_at` | `timestamp` | Date de mise en file d'attente (transactionnelle) |

### Exemple de Message Outbox
```json
{
  "type_evenement": "scolarite.inscription_validee",
  "type_agregat": "inscription_academique",
  "agregat_id": 1420,
  "cle_deduplication": "inscr_validee_1420_2026_10_16",
  "charge_utile": {
    "inscription_id": 1420,
    "etudiant_id": 850,
    "reference": "2026-FST-042",
    "faculte_id": 1,
    "annee_id": 3,
    "niveau": "L1",
    "programme_id": 12
  }
}
```

---

## 4. Spécification des Résultats du Contrôle Qualité

Le service `ControlesDonnees` produit des rapports standardisés exploitables par les tableaux de bord et par l'agrégateur d'anomalies :

```php
array {
    'date_controle' => string, // Horodatage ISO-8601
    'resultats' => array<int, array{
        'regle' => string,     // Identifiant technique de la règle
        'perimetre' => string, // Chaîne descriptive du filtre
        'anomalies' => int,    // Nombre total d'anomalies constatées
        'gravite' => string,   // 'info', 'avertissement', 'bloquant'
        'exemples' => array,   // Échantillon de codes/références stables
    }>
}
```

## 5. Pagination v1.1 `inscriptions_validees` (MINEUR, compatible lot 02)
Clés v1.0 (`total`, `inscriptions`) inchangées ; ajout `page` (défaut 1, ≥ 1),
`par_page` (défaut 100, 1 à 1000), `pages`. Filtres `faculte_id`/`niveau`/`annee`/`programme_id`
validés et bornés serveur ; tri whitelisté (`id`|`created_at`, repli `id`),
sens (`asc`|`desc`, repli `asc`). Tout paramètre invalide retombe sur les défauts
documentés (jamais d'erreur 500, jamais de full-scan au-delà de la page).

## 6. `analytique_faculte` v1.1 (MINEUR, compatible LOOP-03)
Clés v1.0 (`version_agregats`, `indicateurs`) inchangées ; ajout
`snapshot_kpi` (objet) : snapshot unifié des KPI scolarité produit par
`CockpitKpiService::calculerSnapshot()` et exposé via `versDataMesh()`.
Aucun retrait ni renommage ; les consommateurs v1.0 restent valides.
Le schéma est gelé par `DataMeshContractsTest::test_instantane_des_schemas_empeche_les_changements_silencieux`
(instantané + version à mettre à jour conjointement).

