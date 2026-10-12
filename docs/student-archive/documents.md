# Documents projetés (Phase D)

AUCUN stockage créé : GED cœur + dossiers + demandes, liens de
téléchargement vers les routes existantes (policies existantes).

## Sources et normalisation

`StudentArchiveService::documents()` retourne
`source, titre, type, categorie, reference, annee, date, statut, officiel,
telechargement` (null si pas le droit download — pas de contournement).

| Source | Modèles | Gate liste | Gate download |
|---|---|---|---|
| `ged` | `DocumentAcademique` (6 types) | `documents.view` (onglet) | `documents.download` |
| `dossier` | `StudentDocument` (module on) | `student_documents.view` | `student_documents.download` |
| `demande` | `OnlineRequestDocument` (module on) | `demandes.view` | `demandes.documents.download` |

Catégories d'affichage (mapping lecture seule, §21) : Diplômes, Relevés,
Certificats, Stages, Autres (+ catégorie dossier d'origine).

## Diplômes (§24) et certificats (§23, §25)

- `diplomas()` : décisions `admis/admis_compensation/admis_conditionnel`
  (lecture `evaluation_decisions`) + pièces `type=diplome`. Aucune table.
- `certificates()` : pièces `attestation/certificat` toutes sources.
- Officiel = `diplome/releve_notes` (cœur) ou attestation générée
  (demande) ; statut/versioning sources respectés, jamais modifiés ici.

## Filtres et recherche

Server-side : année, type (6 types cœur), search (titre). Permissions
respectées à chaque ligne. Médical : RIEN n'existe côté cœur (§52 à venir) ;
les catégories `medical` des dossiers ne sont listées que si
`student_documents.view` (politique du module).

## Sécurité (§30)

Chaîne : auth → `student_archive.view` → `documents.view` (onglet) →
`DocumentPolicy` source → scope faculté → download policy → audit source.
UUID connu hors périmètre → 403 (policies GED). Tests IDOR dédiés.
