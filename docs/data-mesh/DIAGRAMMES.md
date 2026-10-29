# Data Mesh — Diagrammes d'architecture

Rendu : Markdown + Mermaid (GitHub, GitLab, VS Code). Source de vérité : `app/DataMesh/`.

## 1. Architecture globale

```mermaid
flowchart TB
    subgraph DOMAINES["Domaines propriétaires (sources de vérité)"]
        REF[Référentiel<br/>facultes, programmes, niveaux]
        SCO[Scolarité<br/>inscriptions + etudiants]
        AFF[Affectations<br/>affectations_groupe + groups]
        ENS[Enseignement<br/>enseignements]
        EVA[Évaluation<br/>decisions publiées]
    end

    subgraph MESH["Couche Data Mesh — app/DataMesh/ (lecture seule)"]
        CAT[Catalogue<br/>codes, versions, schémas]
        P1[catalogue_academique v1.0]
        P2[inscriptions_validees v1.1<br/>pagination]
        P3[affectations_groupes v1.0]
        P4[affectations_enseignement v1.0]
        P5[resultats_examens v1.0<br/>publie=true]
        P6[analytique_faculte v1.0<br/>consommateur BIService]
        Q[ControlesDonnees<br/>8 règles]
    end

    subgraph GOUVERNANCE["Gouvernance fédérée"]
        RBAC[Rôle + périmètre facultaire<br/>refus sans oracle]
        PII[PII masquée<br/>cin/email masqués]
        AUDIT[AuditService<br/>historique_academique]
    end

    REF --> P1
    SCO --> P2
    AFF --> P3
    ENS --> P4
    EVA --> P5
    P1 & P2 & P3 --> P6
    P1 & P2 & P3 & P4 & P5 & P6 --> CAT
    Q -. contrôle .-> SCO & AFF & REF
    RBAC -. autorise .-> P1 & P2 & P3 & P4 & P5 & P6
    PII -. minimise .-> P2 & P5
    P6 & Q -. tracent .-> AUDIT
```

## 2. Contrat produit (`ProduitDonnees`)

```mermaid
classDiagram
    class ProduitDonnees {
        <<interface>>
        +code() string
        +domaine() string
        +version() MAJEUR.MINEUR
        +decrire() array
        +schema() array
        +donnees(contexte) array
        +fraicheur() string
    }
    class ProduitSocle {
        +consommateur() User
        +perimetreFaculte() int|null
        +roleBackOfficeRequis() void
    }
    class Catalogue {
        +codes() array
        +produit(code) ProduitDonnees
        +liste() array
        +parDomaine() array
    }
    ProduitDonnees <|.. ProduitSocle
    ProduitSocle <|-- CatalogueAcademique
    ProduitSocle <|-- InscriptionsValidees
    ProduitSocle <|-- AffectationsGroupes
    ProduitSocle <|-- AffectationsEnseignement
    ProduitSocle <|-- ResultatsExamens
    ProduitSocle <|-- AnalytiqueFaculte
    Catalogue ..> ProduitDonnees : découvre
```

Règle de compatibilité : ajout de champ = MINEUR (ex. v1.0 → v1.1 pagination) ; retrait/renommage = MAJEUR.

## 3. Flux vertical (`FluxVerticalDataMesh::executer`)

```mermaid
sequenceDiagram
    actor U as Consommateur (User + périmètre)
    participant F as FluxVerticalDataMesh
    participant R as catalogue_academique
    participant S as inscriptions_validees
    participant A as affectations_groupes
    participant P as analytique_faculte
    participant AU as AuditService

    U->>F: executer(utilisateur, faculte, annee)
    F->>R: donnees() → facultés, programmes, niveaux
    R-->>F: etape_1 (etat)
    F->>S: donnees() paginées, PII masquée
    S-->>F: etape_2 (etat)
    F->>A: donnees() état + occupation
    A-->>F: etape_3 (etat)
    alt 0 affectation active
        F->>F: diagnostic explicite (sans correction auto)
    end
    F->>P: donnees() agrégats versionnés
    P-->>F: etape_4 (etat)
    F->>AU: tracer(data_mesh.flux_vertical_execute)
    F-->>U: SUCCES + 5 bilans (statut, versions, diagnostic)
```

Chaque étape expose `etat` : `terminee` | `terminee_sans_resultat` (jamais de succès masqué).

## 4. Outbox transactionnelle (cycle de vie)

```mermaid
stateDiagram-v2
    [*] --> pending : enregistrer() dans la transaction métier
    pending --> published : publierEnAttente() post-commit
    pending --> failed : marquerEchec() (+tentatives)
    failed --> published : retenterEchecs() sous le seuil
    failed --> failed : seuil dépassé (conservé, diagnostic)
    published --> published : republication = no-op idempotent
    published --> [*] : purgerPublies(published > 30j)
    note right of pending
        Déduplication atomique :
        UNIQUE(cle_deduplication) +
        rattrapage course → existant
    end note
```

Commande planifiée : `datamesh:outbox-publier [--limite=50] [--retenter] [--purge-jours=30]`.
Garantie : **au-moins-une-fois + consommation idempotente** (jamais d'exactement-une-fois revendiqué).

## 5. Qualité des données (`ControlesDonnees::controler`)

```mermaid
flowchart LR
    subgraph ENTREES["Périmètre"]
        U[User + rôle]
        F[faculte_id?]
        A[annee_id?]
    end
    C[controler : 8 règles<br/>lecture seule] --> R[résultat standard<br/>règle, périmètre, n, gravité, exemples, date]
    R --> SYNC[synchroniserVersAnomalies<br/>→ anomalies_qualite]
    R --> DASH[Tableaux de bord]
```

| Règle | Gravité |
|---|---|
| doublons_affectations, groupes_surcharges, affectations_incompatibles, inscriptions_sans_niveau, inscriptions_sans_programme, references_lmd_incoherentes | bloquant |
| capacites_inconnues, incoherences_effectifs_agregats | avertissement |
| fraicheur_produits_depassee | info |

Principe : signalement uniquement, **aucune correction automatique** des données ambiguës.

## 6. Capacités des groupes (triptyque)

```mermaid
flowchart TB
    G[groups.capacite] -->|renseignée| DEF[DEFINIE<br/>opposable, GROUP_FULL au-delà]
    G -->|NULL + flag| ILL[ILLIMITEE<br/>décision métier explicite]
    G -->|NULL sans flag| INC[INCONNUE<br/>avertissement qualité, jamais inventée]
    INC -.->|bascule refus auto| BLOCK[BLOCKED<br/>décision métier requise]
```

`Group::modeCapacite()` expose l'état ; le moteur conserve son comportement historique tant que la bascule n'est pas validée.
