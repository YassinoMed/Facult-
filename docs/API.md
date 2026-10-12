# 🔌 API d'interopérabilité — v1

Base URL : **`/api/v1`** · Authentification : **Sanctum Bearer tokens à scopes** · Format : JSON (sauf fichiers d'import/export)

> Documentation OpenAPI complète : [`docs/openapi.yaml`](openapi.yaml)
> La v1 ne sera jamais cassée : toute évolution incompatible passera en `/api/v2` (§64).

---

## 1. Authentification (§46-48)

Chaque système externe reçoit son **propre token** (jamais de token global) :

```bash
# Créer un token d'intégration (affiché UNE SEULE fois)
php artisan integration:token-create --name="mon-systeme" --role=admin_scolarite \
    --abilities=students:read --abilities=grades:read

# Lister / révoquer
php artisan integration:tokens
php artisan integration:token-revoke {id}
```

**Scopes disponibles** (`app/Support/ApiScopes.php`) : `students:read/write`,
`archives:read`, `grades:read/write`, `documents:read/write`, `imports:create`,
`exports:create`, `data:exchange`, `schedules:import`, `referentiel:read`,
`configuration:manage`, `webhooks:receive` — principe du **moindre privilège** : un token
`students:read` ne peut ni importer, ni exporter, ni lire les notes.

```
Authorization: Bearer {token}
```

- Sans token → **401** · Scope insuffisant → **403** générique (`error: insufficient_scope`, sans divulgation des scopes)
- Token révoqué → **401** immédiat · Rate limits → **429** avec `Retry-After`

---

## 2. Rate limiting (§49)

| Limiteur | Quota par défaut | Config |
|---|---|---|
| `api` (lectures) | 60 req/min par token/IP | `INTEGRATION_THROTTLE_API` |
| `api-imports` | 5/min | `INTEGRATION_THROTTLE_IMPORTS` |
| `api-exports` | 10/min | `INTEGRATION_THROTTLE_EXPORTS` |
| `api-webhooks` | 30/min par IP | `INTEGRATION_THROTTLE_WEBHOOKS` |

---

## 3. Endpoints étudiants (lecture)

### `GET /api/v1/students`
Filtres **en liste blanche** : `search`, `faculty` (code), `status`, `niveau`, `per_page` (≤ 100), `page`.

```json
{
  "data": [
    {
      "id": 1, "cin": "BK712345", "numero_inscription": "INS-2026-00001",
      "nom": "Alami", "prenom": "Mehdi", "email": "mehdi.alami@email.com",
      "sexe": "M", "niveau": "L1", "annee_academique": "2025-2026",
      "ville": "Casablanca", "statut": "valide",
      "faculte": { "id": 1, "code": "FST", "nom": "Faculté des Sciences…" }
    }
  ],
  "meta": { "per_page_max": 100 }, "links": { }
}
```
Scope : `students:read`

### `GET /api/v1/students/{id}`
→ `StudentResource` · 404 si inconnu (ID numérique uniquement). Scope : `students:read`

### `GET /api/v1/students/{id}/academic-record`
Dossier académique complet : matricule, statut, inscriptions → semestres → notes
(matériaux, moyennes, crédits, décisions). Scope : `archives:read`

### `GET /api/v1/students/{id}/grades`
Notes paginées (métadonnées : matière, coefficient, session, verrouillage).
Scope : `grades:read`

### `GET /api/v1/students/{id}/documents`
**Métadonnées uniquement** — le `chemin` interne n'est JAMAIS exposé.
Scope : `documents:read`

---

## 4. Imports — workflow deux phases (§37-40)

### Phase 1 : `POST /api/v1/imports` — analyse + prévisualisation (202)
`multipart/form-data` : `type` (`students`|`grades`), `file` (csv/json/xml/xlsx, ≤ 10 Mo).
**Aucune écriture métier.** Réponse : rapport (`pending`) + `preview`
(total/valides/doublons/erreurs + détail des 50 premières erreurs).

```json
{
  "message": "Fichier analysé. Confirmez l'import pour exécuter.",
  "report": { "id": 1, "reference": "EXCH-20260924-00001", "status": "pending",
              "total": 3, "valides": 2, "doublons": 0, "erreurs": 1 },
  "preview": { "total": 3, "valides": 2, "doublons": 0, "erreurs": 1,
               "detail_erreurs": [{ "ligne": 4, "champ": "email", "message": "…" }] }
}
```

### Phase 2 : `POST /api/v1/imports/{report}/confirm` — exécution
`{"mode": "atomique" | "partiel"}` — le mode **atomique** fait un rollback complet
en cas d'erreur ; le mode **partiel** importe uniquement les lignes valides.
Fichiers volumineux (> `INTEGRATION_QUEUE_THRESHOLD`) → **202** + job en file.
Le fichier est **re-parcé et re-validé** à la confirmation (anti-TOCTOU).

### `GET /api/v1/imports/{report}` — rapport final (§40)
Compteurs, erreurs, durée, statut (`completed`/`failed`/`cancelled`).
**Anti-IDOR** : chaque rapport n'est accessible qu'à son propriétaire.

**Idempotence (§50)** : CIN/email uniques (étudiants) et unicité
(semestre, matière, session) des notes — ré-envoyer le même fichier ne crée
**jamais** de doublons ; les lignes existantes sont rapportées comme doublons.

**Clé d'idempotence (Phase 11 §11.12)** : `POST /api/v1/imports` et
`POST /api/v1/exports` acceptent `Idempotency-Key: [A-Za-z0-9_-]{8,64}`.
Rejouer la même clé (même token) rejoue la réponse originelle
(`X-Idempotent-Replayed: true`) **sans** réexécuter l'opération.
Clé scopée par token, TTL 24h, JSON uniquement (jamais de binaire).

**Formats acceptés** : CSV (séparateur `,` ou `;`, BOM UTF-8 géré), JSON (tableau
d'objets), XML (**anti-XXE : DTD et entités interdites**), XLSX (OpenSpout, flux).
Schémas attendus (§38 — mêmes règles que les formulaires internes) :
- `students` : `cin, nom, prenom, email, sexe, faculte (code), niveau, annee_academique, adresse, ville, code_postal, annee_bac` (+ optionnels `telephone, mention_bac, date_naissance`)
- `grades` : `cin, matiere (code), annee (AAAA-AAAA), niveau, semestre (1|2), note (0-20), session (normale|rattrapage)`

---

## 4-bis. Modèles d'importation (§91-117)

### `GET /api/v1/import-templates` — catalogue des types autorisés pour CE token (scopes)
### `GET /api/v1/import-templates/{type}` — schéma détaillé (colonnes, formats, exemples, références)
### `GET /api/v1/import-templates/{type}/download?format=xlsx|csv|json&vide=1`

> Audit §P2-12 : ces 3 routes exigent le scope `imports:create` (moindre privilège) — un token de lecture seule reçoit **403**.

6 types : `students`, `grades`, `academic_registrations`, `subjects`, `results`, `documents`.

- **XLSX multi-feuilles** : `IMPORT` (en-têtes + exemple fictif) + `INSTRUCTIONS` (documenter chaque colonne) + `REFERENCES` (valeurs autorisées dynamiques : facultés, années, matières, groupes — §95/96/98)
- **Versionnage** : `students_import_template_v1.xlsx` + métadonnées `template_type`/`template_version`/`generated_at` (§99/100)
- **À l'import** : validation stricte des colonnes contre le schéma officiel (§105) + détection du type par structure (§104) — un fichier non conforme est refusé avec un message clair (« colonne manquante », « colonne renommée : Vouliez-vous dire… »)
- **Rapport d'erreurs exportable** : `GET /admin/imports/rapports/{rapport}/erreurs` (XLSX/CSV : numéro_ligne, champ, valeur, erreur, suggestion — §109)
- **Un seul principe** : TEMPLATE = SCHÉMA D'IMPORT = VALIDATION (§117) — la définition centralisée `ImportTemplateDefinition` alimente la génération, la validation et les messages.

---

## 5. Exports (§41-43, §70-90)

### `POST /api/v1/exports`
```json
{
  "type": "students" | "grades" | "subject" | "group" | "averages" | "filiere" | "stats",
  "format": "csv" | "xlsx" | "json" | "xml",
  "profil": "administratif" | "academique" | "statistique",
  "granularite": "matiere" | "semestre" | "annee" | "generale",
  "filters": { "...": "..." },
  "columns": ["matricule", "nom", "note"]
}
```
→ 201 avec `report` + `download_url`. **Filtres en liste blanche stricte** —
toute clé inconnue → 422 ; jamais de `?all=true` incontrôlé (§42).

**Moteur unique multicritère (§90)** — types disponibles :

| Type | Contenu | Sections |
|---|---|---|
| `students` | Roster d'étudiants (3 profils §43) | §71 |
| `grades` | Notes détaillées du périmètre | §72 |
| `subject` | Notes d'une matière : session principale/rattrapage/toutes | §75 |
| `group` | Relevé collectif : matières en colonnes dynamiques | §74 |
| `averages` | Moyennes par matière / semestre / année / générale (`granularite`) | §73 |
| `filiere` | Synthèse : effectif, moyenne, admis/ajournés par niveau × groupe | §76 |
| `stats` | Statistiques par matière (moyenne, taux de validation) | §86 |

**Filtres multicritères (§70)** — tous combinables (AND logique) :
`faculte`, `filiere`, `niveau`, `groupe`, `annee`, `semestre`, `matiere`, `session`,
`statut` (étudiant), `statut_academique`, `decision`, `moyenne_min/max`, `note_min/max`,
`credits_min/max`, `cin`, `nom`, `prenom`, `num_inscription`, `matricule`, `search`.

**Colonnes personnalisables (§78)** : `columns` = intersection avec le catalogue du
type ; sans sélection → toutes les colonnes autorisées par votre rôle.

**Permission `exports.personal` (§85)** : sans elle, CIN/email/téléphone sont
automatiquement retirés des exports et la CIN est masquée dans les listes —
principe du moindre privilège.

**Règle d'or (§90)** — le moteur répond aussi bien à
« les notes d'IA301, groupe G2, 2025-2026, session principale »
qu'à « tous les étudiants de la Faculté des Sciences »
ou « la synthèse de la filière Informatique ».

### `GET /api/v1/exports/{report}` · `GET /api/v1/exports/{report}/download`
Rapport et téléchargement (fichier en disque privé) — **propriétaire uniquement**.

### `GET /api/v1/exports/{report}/download` après expiration → **404**
**§82** : durée de conservation configurable (`INTEGRATION_EXPORT_RETENTION_HOURS`,
défaut 7 jours) ; à l'expiration, le fichier est purgé et l'événement journalisé
(`integration_logs` : `export.expired`). Purge horaire : `php artisan exports:purge`.

---

## 6. Webhooks (§53-54)

`POST /api/v1/webhooks/external-system` — **sans token Sanctum** :
authentification par signature HMAC-SHA256.

```
X-Signature  : base64(HMAC-SHA256(corps brut, INTEGRATION_WEBHOOK_SECRET))
X-Timestamp  : unix (fenêtre ± INTEGRATION_WEBHOOK_TOLERANCE, défaut 300 s)
X-Request-ID : identifiant unique — rejeu refusé pendant 24 h
```

Payload : `{"event": "student.registered" | "student.updated" | "grade.submitted" | "ping", "external_reference": "…"}`

- Signature/timestamp/rejeu invalides → **401** (journalisé)
- Event inconnu → **422** · Valide → **202** (journal d'échange, empreinte SHA-256 du payload — jamais le contenu)

---

## 7. Codes HTTP (§57)

`200` OK · `201` créé · `202` accepté (prévisualisation, job) · `400/422` validation
(messages FR, jamais d'exception PHP brute) · `401` non authentifié · `403` scope
insuffisant · `404` ressource inexistante/interdite (anti-IDOR) · `429` rate limit.

---

## 8. Journal des intégrations (§55)

Chaque appel API est tracé dans `integration_logs` (operation, statut HTTP, durée,
request-id, empreinte SHA-256 du payload d'import/export — **jamais** de contenu
ni de secret). Consultable côté scolarité : `/admin/integrations` (onglet Journal).

---

## 9. Sécurité — rappels

- Import/export repassent par les **mêmes validations métier** que l'UI (§38) ;
- XML : DTD/entités **interdites**, `LIBXML_NONET`, pas de résolution externe (§61) ;
- Pagination bornée à 100 (§58), filtres en liste blanche (§59) ;
- Tokens : création/révocation via artisan, jamais dans Git/Blade/JS/logs (§47) ;
- Secrets via `.env` uniquement (`INTEGRATION_WEBHOOK_SECRET`, rotation recommandée).
