# LOOP EX-LIFECYCLE — 07 · Expérience utilisateur

## G1 · Fiche d'examen (structure recommandée, conventions du module respectées)

Les vues existantes (`modules/evaluation/examens-index`, menus avancés) sont **inchangées** — aucune surcharge de formulaire. Sections attendues pour toute évolution UI future : informations générales · périmètre pédagogique · état & progression · sessions · épreuves · planification · conformité · gouvernance · historique.

## G2 · Visualisation du cycle

La chronologie doit s'appuyer sur le journal réel `evaluation_examen_evenements` (examen_id + created_at + statut_avant/après + acteur + motif) — **plus aucune reconstruction approximative** :
- chaque étape porte acteur, avant/après, date, motif éventuel ;
- la création y figure (`action = creation`) ;
- le circuit de gouvernance conserve son propre journal (`PlanningEvenement`, versions + empreintes) : ne pas fusionner les deux — les afficher l'un sous l'autre.

## G3 · Actions disponibles

Source d'unique vérité : `GET admin/evaluations/examens/{examen}/transitions` → `disponibles[]` (`cible`, `acceptable`, `bloquages`). Règles :
- n'afficher comme cliquable que les cibles `acceptable` ;
- toujours laisser le serveur trancher à l'application ;
- masquer un bouton ne remplace jamais le `can:` (middleware) — l'UI est une projection, pas une autorité.

## G4 · Explication des blocages

Chaque refus renvoie une erreur de session `examen` avec un message métier précis (voir `04-regles-de-transition.md` §Refus). Messages cartographiés : session/config manquante, validation gouvernance en attente, autorisation insuffisante (403 standard), examen verrouillé (« Examen verrouillé. »), transition inexistante, contraintes dures. Aucune donnée sensible n'est exposée (aucun payload du journal ne sort vers un acteur sans `evaluations.view` + périmètre).

## G5 · Confirmation des actions sensibles

- Transitions vers états critiques (`published` — via circuit, `cancelled`, `locked`, `archived`) : confirmer examen + état courant + état cible + justification (champ `motif` déjà accepté par la route, facultatif — D-02).
- Transitions simples de préparation (`draft→planned→scheduled`) : pas de confirmation superflue.
- Anti double-soumission : la répétition est refusée par le moteur (EX-LC-011) et n'écrit rien — l'UI doit de plus désactiver le bouton pendant la requête.

## G6 · Accessibilité

Point de contrôle requis pour toute évolution UI (non modifiée dans ce LOOP, donc non régressée) : navigation clavier, libellés explicites, contrastes AA, focus visible, responsive, messages d'erreur rattachés au champ/bloc concerné, indicateurs de chargement sur les transitions POST, état de confirmation cohérent avec l'état serveur réel.
