# RÉFÉRENTIEL — MATRICE DE CORRESPONDANCE ET DÉCISIONS

> **Statut du document** : hypothèses techniques établies par audit du code et de la base.
> **Aucune correspondance métier n'est validée à ce jour.**
> **Règle de gel** : tant que la section 5 n'est pas signée, aucun renommage, aucune migration destructive et aucun raccordement automatique ne sont autorisés.

---

## 1. Avertissement

Les termes « filière », « spécialité » et « parcours » ne désignent pas les mêmes niveaux d'un établissement à l'autre :

| Contexte | Vocabulaire usuel |
|---|---|
| Université — réforme LMD | Domaine → Mention → Parcours |
| ISET | Filière (licence appliquée) |
| Cycle d'ingénieur | Spécialité |
| Usage administratif courant | « filière » employé pour le programme, la mention ou le domaine |

Le code actuel emploie ces termes de façon **non homogène**. Ce document ne tranche pas à la place de l'établissement ; il rend les choix explicites et mesurables.

---

## 2. Ce que le code fait aujourd'hui (constat, pas décision)

| Terme | Entité existante | Où il est stocké | Où il est utilisé |
|---|---|---|---|
| Département | `departements` | `programmes.departement_id` (nullable) | Attribut du programme, filtres de campagnes, enseignants |
| Domaine | `domaines` | `mentions.domaine_id` | Référentiel uniquement |
| Mention | `mentions` | `specialites.mention_id` | Référentiel uniquement |
| Spécialité | `specialites` | `programmes.specialite_id` (nullable), `teachers.specialite_id` | Programme et enseignant — **jamais l'étudiant** |
| Programme | `programmes` | `inscriptions.programme_id`, `groups.programme_id`, `etudiants.programme_id` | Chaîne LMD |
| Parcours | **aucune entité propre** | confondu avec `programmes` | Libellé UI « Programmes / Parcours » (`EntitesReferentiel.php:190`) |
| Filière | **aucune entité** | `inscriptions.filiere`, `groups.filiere`, `matieres.filiere` (texte libre) | Exports, PDF, planning, affectation |

### 2.1 Trois significations de « parcours » dans le code

1. **Formation** : alias de `programme` dans les imports (`ExamenDefinition.php:59`, `DeliberationDefinition.php:59`, `EtudiantDefinition.php:72`).
2. **Progression de l'étudiant** : permissions `parcours.view`, `parcours.simuler`, `parcours.gerer` (`app/Support/Permissions.php:286`).
3. **Tunnel public d'inscription** : « Parcours CIN » (`docs/PROFILS-ET-SCOPING.md`).

Toute décision de renommage doit d'abord lever cette collision.

---

## 3. Matrice de correspondance (hypothèses)

| # | Terme établissement (à confirmer) | Entité technique | Table.colonne | Niveau hiérarchique | Usage actuel | Statut |
|---|---|---|---|---|---|---|
| 1 | Département | `Departement` | `departements` | Administratif, rattaché à la faculté | Attribut optionnel du programme | **Confirmé par le code** |
| 2 | Domaine | `Domaine` | `domaines` | Sous la faculté | Référentiel seulement | **Confirmé par le code** |
| 3 | Mention | `Mention` | `mentions` | Sous le domaine | Référentiel seulement | **Confirmé par le code** |
| 4 | Spécialité | `Specialite` | `specialites` | Sous la mention | Attribut du programme et de l'enseignant | **Confirmé par le code** ; statut métier **à valider** |
| 5 | Programme | `Programme` | `programmes` | Sous la spécialité (facultatif) | Chaîne LMD | **Confirmé par le code** |
| 6 | Parcours | `Programme` ? | — | — | Libellé UI | **À VALIDER** |
| 7 | Filière | `Programme` ? `Mention` ? `Domaine` ? | — | — | Texte libre, 96,8 % vide | **À VALIDER — bloquant** |

---

## 4. Questions à soumettre au service scolarité

Chaque réponse doit être écrite, datée et nominative.

### Q1 — Quelle hiérarchie figure dans les documents d'habilitation ?

> Réponse attendue sous la forme : `Niveau 1 → Niveau 2 → Niveau 3 → Niveau 4`.

Réponse : ..............................................................................

Document de référence (maquette, arrêté, guide) : ....................................

### Q2 — Quelle entité correspond à la « filière » officielle ?

| Option | Conséquence technique | Cocher |
|---|---|---|
| A. La filière est le **programme** (`programmes.code`) | Le champ texte devient un simple alias de `programme.code` ; pas de changement de schéma | ☐ |
| B. La filière est la **mention** | `filiere` est un niveau plus grossier que le programme ; les exports doivent changer de dimension | ☐ |
| C. La filière est le **domaine** | Idem, niveau encore plus grossier | ☐ |
| D. La filière est un **concept local sans équivalent LMD** | Table de correspondance dédiée, hors cœur | ☐ |

Réponse : ............................  Document : ............................

### Q3 — La spécialité constitue-t-elle un niveau distinct ?

| Option | Conséquence |
|---|---|
| Oui, niveau distinct entre mention et programme | `specialites` reste tel quel ; `programmes.specialite_id` devient obligatoire à terme |
| Non, synonyme local de mention ou de parcours | Fusion conceptuelle ; ne pas créer de niveau supplémentaire |

Réponse : ............................  Document : ............................

### Q4 — Le parcours figure-t-il sur le diplôme ou le relevé de notes ?

| Option | Conséquence |
|---|---|
| Oui | Le parcours doit être une donnée opposable, donc identifier de façon stable (code, pas libellé) |
| Non, seulement interne | Le parcours reste un attribut de gestion, sans impact sur les documents officiels |

Réponse : ............................  Document : ............................

---

## 5. Décisions à acter

| ID | Décision | Impact | Responsable | Date | Statut |
|---|---|---|---|---|---|
| D-01 | Signification officielle de « filière » | Schéma, exports, imports, libellés | Service scolarité | — | **EN ATTENTE** |
| D-02 | La spécialité est-elle un niveau distinct ? | Référentiel, contrainte `programmes.specialite_id` | Ingénierie pédagogique | — | **EN ATTENTE** |
| D-03 | Le parcours figure-t-il sur les documents officiels ? | Documents, exports | Scolarité | — | **EN ATTENTE** |
| D-04 | Le champ `matieres.filiere` est-il supprimable ? | Schéma (colonne morte) | Scolarité + DSI | — | **EN ATTENTE** |
| D-05 | Le référentiel doit-il couvrir les 10 facultés ? | Volume de données, gouvernance | Direction | — | **EN ATTENTE** |

---

## 6. Règle de gel (applicable immédiatement)

1. **Aucun renommage** de libellé, de colonne ou d'entité avant signature de D-01 à D-03.
2. **Aucune conversion** de `filiere` vers `programme_id` sur la base d'une ressemblance textuelle.
3. **Aucune suppression** de `inscriptions.filiere`, `groups.filiere` ou `matieres.filiere`.
4. **Aucune contrainte** `NOT NULL` ajoutée sur `programme_id`.
5. Les nouveaux enregistrements peuvent utiliser `programme_id` ; le champ texte reste alimenté en parallèle jusqu'à la bascule.

---

## 7. Ce qui est déjà décidable sans la scolarité

| Constat | Décision technique | Dépend d'une réponse métier ? |
|---|---|---|
| 10 inscriptions ont un `niveau_id` d'un autre programme | Corriger la résolution : borner `niveau_id` par `programme_id` | **Non** |
| 4 résolveurs différents pour un même code | Centraliser dans un service unique | **Non** (le service peut rester neutre) |
| `$etudiant->filiere` est toujours nul | Supprimer la branche morte du scoring d'affectation | **Non** |
| `matieres.filiere` est vide à 100 % | Geler l'écriture, ne pas supprimer la colonne | **Oui** pour la suppression |
| 9 facultés sans référentiel | Ne pas lancer de migration de masse | **Oui** pour le périmètre |

---

## 8. Journal des décisions

| Date | Décision | Acteur | Preuve |
|---|---|---|---|
| — | Aucune décision métier enregistrée | — | — |

---

*Document à faire viser par le service scolarité avant toute modification du référentiel.*
