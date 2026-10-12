# Proposition — Attribution des dossiers aux agents (LOOP 8, Étape 7)

Statut : **proposition non officielle, non implémentée, en attente de l'accord du responsable de scolarité.**

## Contexte
Aucune attribution n'existe (constat : aucune colonne ni table). La file est traitée en FIFO anonyme avec traçabilité par action.

## Critères d'attribution possibles
1. Volontariat (prise en charge explicite par l'agent, verrou anti-double-prise).
2. Tourniquet équitable au sein de la faculté (compteur de dossiers pris).
3. Affinité (programme/niveau déjà traités par l'agent).
4. Ancienneté d'abord (FIFO conservé comme ordre réglementaire par défaut).

## Règles de priorité envisageables
- Dossiers BLOQUANT > souffrance > 7 j > souffrance 48 h > FIFO.
- Les priorités **ne contournent jamais** l'ordre réglementaire : elles ordonnent l'affichage, jamais le droit de traiter hors tour sans motif tracé.

## Responsabilités des agents
- Un dossier pris = un responsable (nom + horodatage, visibles).
- Délai de traitement indicatif par catégorie ; au-delà, le dossier redevient disponible (pas de sanction automatique).
- Toute décision (valider/rejeter) reste soumise aux permissions et transitions existantes.

## Contraintes de réattribution
- Réattribution par le responsable de faculté ou la scolarité centrale, motif obligatoire, tracée (qui, quand, pourquoi).
- Interdite pendant une instruction verrouillée (transition en cours).
- Historique des attributions conservé (pas d'écrasement).

## Indicateurs de charge
- Dossiers pris par agent, délai médian de traitement, dossiers rendus, taux de reprise.
- Agrégés par faculté, sans classement individuel opposable (même principe que les KPIs actuels : mesure, pas notation).

## Décisions requises du responsable de scolarité
1. Mode d'attribution retenu (1–4, combinaisons autorisées ?).
2. Seuils de reprise (délais par catégorie).
3. Qui peut réattribuer (rôle exact).
4. Faut-il une migration dédiée (`dossier_attributions` : dossier, agent, prise_le, rendu_le, motif) ou une colonne simple ?
5. Notifications aux agents lors d'une attribution (canal existant `notifications` ?) — oui/non.
