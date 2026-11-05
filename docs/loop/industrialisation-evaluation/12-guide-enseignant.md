# LOOP INDUSTRIALISATION ÉVALUATION — 12 · Guide enseignant

## Accès
- Portail enseignant (sans compte back-office) : « Mes notes » (`MesNotesController`) — vos épreuves autorisées uniquement.

## Saisie
- Saisie individuelle ou par lot (grille) ; identification par **numéro de compostage** (anonyme) ou interne.
- Règles serveur : valeur numérique, 0..note_max, 2 décimales max, absence ≠ zéro (champ vide = absence explicite), candidat non convoqué refusé, doublons du lot refusés.
- Une seule ligne invalide → **aucune écriture partielle** (rollback intégral) ; rapport d'erreur par ligne.

## Enregistrement et soumission
- Statuts : saisie (`entered`) → soumission → validation (par la scolarité). Vous ne validez pas vos propres notes (séparation des rôles).

## Vérification et historique
- Consultez les notes saisies et leurs statuts ; les corrections passent par la scolarité.
- Votre saisie est tracée (`origine` + horodatage) ; l'historique des valeurs est consultable via l'administration.

## Incidents
- Signalement via l'écran incidents du jour-J ; suivi dans votre périmètre.

## Ce que vous ne pouvez pas faire (par conception serveur)
- Modifier une note verrouillée, valider/corriger (permissions réservées), publier des résultats, accéder aux notes hors de vos épreuves.
