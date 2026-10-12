# Centre de communication (Phase 15)

## Moteur unique

`CommunicationDispatcher` : point de passage des notifications
automatiques (inscriptions, progression, transfert, délibération
publiée — textes contractuels inchangés). Règle
`config/communication.php` (gabarit + catégorie + actif) :
désactiver coupe l'envoi sans toucher au métier. Déduplication :
même type + lien non lu < 60 min ignoré (configurable).

## Audience

`AudienceResolver` : faculte/programme/niveau/groupe/épreuve
(candidats)/listes explicites + exclusions, doublons retirés,
**périmètre de l'expéditeur toujours appliqué**.
`previsualiser()` : effectif + échantillon, sans PII en masse.

## Gabarits et préférences

`notification_templates` (code UNIQUE, version auto-incrémentée à
chaque changement de texte, CRUD `configuration.manage`) : variables
`{nom}` échappées, manquante → vide. `notification_preferences`
(opt-out par catégorie ; `resultats` + `documents_officiels`
jamais désactivables).

## Non fait (documenté)

Canaux email/SMS/push (in-app seul), approbation des diffusions,
quotas d'envoi, messagerie générale (demandes uniquement),
notifications epreuve-déplacée (aucun flow d'édition), `NotifierPlanning`
en boucle (dette), `compensation_ecue` (flag mort), mentions,
import `notes_epreuves`, ML.
