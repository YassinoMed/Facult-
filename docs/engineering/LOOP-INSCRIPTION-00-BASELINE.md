# LOOP-INSCRIPTION-00-BASELINE — Parcours d'inscription (accueil + existant)

Date : 2026-10-14. Environnement : dev local (`http://localhost:8000`, MySQL dev, tests SQLite `:memory:`).

## Découverte structurante
`/#inscription` n'est PAS une page séparée : `/` (route `inscription.create` → `InscriptionController@create`) rend `inscription/create.blade.php`, dont la section formulaire porte `id="inscription"`. La nav `/#inscription` y ancre. `/inscription/existant` est le parcours dossier-existant (OTP, 11 routes, triple preuve + autonome).

## Comparaison réelle (inspectée, non inventée)

| Critère | Accueil `#inscription` | `/inscription/existant` |
|---|---|---|
| Champs | CIN+DOB (étape 1) puis formulaire 6 étapes si `inconnu` | CIN+DOB (+OTP) ; prérempli post-preuve ; autonome : +ancienne adresse +password +nouvelle |
| Étapes | Identification → formulaire complet → dépôt | Recherche → OTP → formulaire/autonome → confirmation |
| Validations | `VerifierCinRequest` + `InscriptionRequest` + JS/Alpine | FormRequests dédiées ×7, serveur seul fait foi |
| Identité | `identifier` (MENS/LOCAL : INCONNU/MENS_SEUL/LOCAL_SEUL/…) | OTP 6 chiffres vers adresse enregistrée (10 min, 5 essais) |
| Existant détecté | Statut `EXISTING_STUDENT` + modale « Dossier existant » + lien canonique + email masqué ; formulaire complet masqué (`inconnu` seul) | Cœur du parcours (jeton 15 min, anti-IDOR) |
| Création/reprise | `store` : anti-doublons CIN/email + idempotency + race→message | Jamais de création (mise à jour seule, whitelist) |
| Notifications | Dépôt reçu (fail-soft), OTP existant | OTP ×3 canaux, alertes, confirmations (file `notifications`) |
| Anti-abus | Throttles 10/1 + 5/1, CSRF, idempotency_token | Throttles nommés/fonction + identité + destinataire, CSRF |

## Lacune à traiter
**Aucun point d'entrée anticipé** : le retourné ne découvre `/inscription/existant` qu'APRÈS identification (1 aller-retour `identifier` + modale) ou via footer/nav. Pas de duplication à corriger : la modale réutilise déjà la route canonique. Test couvrant ce routage : **aucun** (trou prouvé par grep).
**À ne pas faire :** extraire le formulaire 6 étapes en composant partagé (aucun consommateur commun — les deux formulaires n'ont ni mêmes champs ni mêmes règles), ni iframe, ni second backend.

## Baseline tests
Périmètre e-mail : 89/89 (888 assertions). Non-régression : 65/65. E2E Chrome : 23/23 (accueil). À ajouter : routage accueil→existant (JSON + modale + liens), store anti-doublon explicite, E2E clic.
