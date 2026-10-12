# Affectation des étudiants aux groupes — moteur contrôlé

## Modèle

`etudiant → (année, inscription) → programme/niveau → groupe (+ sous-groupe
optionnel)`. Table `affectations_groupe` historisée (active/cloturee, motif,
auteur, dates) ; `etudiants.groupe_id` = pointeur courant. Jamais de
suppression : retrait = clôture.

## Règles (codes §29, `AffectationService::controler()`)

| Code | Gravité | Blocage | Sens |
|---|---|---|---|
| ETUDIANT_SUSPENDU | erreur | toujours | suspension active |
| REGISTRATION_NOT_FOUND | erreur | toujours | sans inscription sur l'année |
| REGISTRATION_INVALID | erreur | toujours | inscription ni en_cours ni valide |
| PROGRAM_MISMATCH | erreur | toujours | programmes renseignés et différents |
| LEVEL_MISMATCH | erreur | sauf force | niveaux renseignés et différents |
| GROUP_INACTIVE | erreur | toujours | groupe désactivé |
| GROUP_FULL | erreur | sauf force | effectif (actives) ≥ capacité |
| GROUP_ASSIGNMENT_EXISTS | info | non | idempotence : aucune opération |
| MULTIPLE_PRIMARY_GROUPS | info | non | transfert : ancienne clôturée |
| INVALID_SUBGROUP | erreur | toujours | sous-groupe hors groupe |

`force` (exception capacité/niveau) : permission `groupes.manage` + motif
obligatoire + préfixe `[FORCÉ]` + audit. Inscription/programme/inactif/
suspension : jamais forçables.

## Workflows

Individuel : fiche → *Prévisualiser* (contraintes + impacts planning,
épreuves à venir, notes conservées) → confirmer (motif) → transaction →
audit (`SituationEtudiant`) → notification étudiant.
Masse : liste d'IDs → *Simuler* (proposées/bloquées avec motifs/warnings) →
*Appliquer* (transaction, confirmation explicite) → notification par étudiant.
Import : centre DataExchange, entité `affectations-groupes`
(matricule/CIN + code groupe + année), preview obligatoire, périmètre
faculté de l'auteur contrôlé ligne par ligne.
Retrait : clôture + pointeur réinitialisé + audit, notes/examens intacts.

## Concurrence

`lockForUpdate` sur le groupe + recomptage dans la transaction :
29/30 + 2 admins → le second est refusé (sauf exception autorisée).

## Permissions (réutilisées, §35)

`archives.update` (assigner/changer/retirer), `groupes.manage` (forcer),
`data.import/export` (import), `archives.view` (prévisualiser/simuler).
Isolation faculté : étudiant ET groupe, 403/404, IDOR testés.

## Prérequis / redoublants / rattrapage / mobilité

Prérequis ECUE : non bloquants pour l'affectation groupe (règle
institutionnelle : warning futur si besoin). Redoublants : jamais exclus
automatiquement (inscription `reinscription` valide requise). Rattrapage :
les candidatures (`evaluation_candidats`) sont liées à l'étudiant, pas au
groupe — inchangées par transfert. Mobilité : transfert + historique.
