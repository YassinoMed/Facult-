# Angle 5 — Dépannage : symptôme → cause → quoi faire

> Tableaux : **symptôme** (ce que vous voyez) → **cause la plus probable** → **action**.
> Tous ces cas correspondent au comportement réel de la plateforme ; aucun cas n'est inventé.

## 1. Codes de statut et verdicts à décoder d'abord

| Symptôme affiché | Signification | Quoi faire |
|---|---|---|
| Statut dossier « en_attente » | Dossier déposé, pas encore traité | Attendre la file ; si urgent : rendez-vous scolarité |
| Statut « valide » | Dossier validé par l'administration | Aucune action ; vos écrans s'animent (inscriptions, notes après publication) |
| Statut « rejete » | Dossier rejeté (motif tracé) | Revenir par les canaux officiels ; une nouvelle inscription reste impossible tant que le doublon existe — demandez à la scolarité |
| Verdict vérificateur : **CONFORME** / **À_VÉRIFIER** / **BLOQUANT** | Score /100 ; **bloquant plafonne la note à 49/100** (garde §12) | Lire les anomalies ligne par ligne (règle, preuve, recommandation) ; corriger ou commander une correction assistée |
| Sévérités du vérificateur : Bloquant −35 · Erreur −15 · Avertissement −5 · Info −1 | Pondération du score | Prioriser les Bloquants/Erreurs ; un seul suffit à bloquer la validation |
| « Note verrouillée » | Écriture interdite (verrou officiel) | Chemin 2 étapes ; `notes.unlock` réservé |
| « Période fermée » (REGISTRATION_PERIOD_CLOSED) | Période d'inscription clôturée | Attendre une exception individuelle ou une réouverture (scolarité) |
| « Module indisponible » (404) | Module désactivé (drapeau env `MODULE_*`) | Aucune faute de votre part ; vérifier l'activation |

## 2. Erreurs HTTP et pages d'erreur (Design System)

| Code | Cause typique | Action |
|---|---|---|
| **403** | Action non permise (policy, périmètre faculté) | Revenir au dashboard ; si vous pensez être en droit : contacter le super admin |
| **404** | Ressource hors périmètre (anti-BOLA : 404 sans exister), ou module désactivé | Ne pas scanner d'autres IDs à la main ; la page peut dépendre d'un module désactivé |
| **419** | Jeton CSRF expiré (formulaire resté ouvert) | Recharger puis renvoyer ; le form-guard anti-double-soumission demande une correction |
| **429** | Trop de requêtes (throttle) | Attendre la fenêtre ; les quotas vont de 5/min (connexions admin) à 30/min selon la route |
| **500** | Erreur technique (jamais un cas métier) | Capture d'écran + horodatage + audit, signaler un ticket ; un refus métier s'affiche toujours comme message compréhensible |
| **503** | Maintenance / santé | Attendre ; le centre maintenance l'explique |

## 3. Symptômes inattendus (métier)

| Symptôme | Cause probable | Action |
|---|---|---|
| La saisie refuse : doublon CIN / inscription annuelle | Contrainte d'unicité (anti-doublon) | Chercher le dossier existant (`/inscription/verifier-cin` ou recherche admin) au lieu de recréer |
| La validation refuse : « déjà inscrit cette année » | L'unicité (étudiant, année) protège les inscriptions | Utiliser les régularisations, pas une nouvelle création |
| Une carte KPI ne mène pas à la liste attendue | Les cartes KPI mènent aux listes exactes (cas corrigé CT-01) | Si le symptôme revient, c'est une régression à signaler |
| Rien après publier | Vérifier `DecisionDeliberation.publie=true`; 2. déduplication fenêtrée des notifications (60 min) | Voir C4 (angle 2) ; l'audit garde le run hashé |
| « Quorum » bloque la validation du jury | Président + ≥2 présents exigés | Ajouter/compléter les membres dans `…/deliberations/{id}/membres` |
| Re-préparer obligatoire après correction sur délib verrouillée | `resultats_invalides_le` | Rejouer « Preparer » puis valider de nouveau avant publication |
| Import de notes : « aperçu » s'affiche mais j'ai fermé sans confirmer | 2 étapes anti-TOCTOU ; l'import n'a **pas** été exécuté | Relancer l'aperçu, puis confirmer ; l'annulation est disponible |
| Le lien de réinitialisation ne fonctionne qu'une fois | Tokens de réinitialisation à usage unique | Repartir de « mot de passe oublié » |
| Un message d'erreur parle en « français métier » (DomainException) | C'est un refus **métier normal** (raison lisible) | Lire la raison, corriger la donnée ; 500 doit rester pour un bug, pas pour ce cas |
| Compte admin actif=false refuse la connexion même identifiants corrects | Compte désactivé + rôle lu en base à chaque requête | Faire réactiver par le super admin |
| MFA / défi de connexion s'affiche de manière inattendue | Défi de second facteur selon routes et rôles | Suivre l'écran ; le verrouillage secondaire complémentaire reste possible |

## 4. Quand tout semble perdu

1. Tout ce qui est écrit est **tracé** : audit immuable (ancienne/nouvelle valeur + motif).
2. Un administrateur `consultation` peut prouver l'état sans rien modifier.
3. Toute correction passe par la voie contrôlée (motif + transaction + audit +
   re-vérification) — jamais par une intervention en base — c'est ce qui garantit qu'après
   correction, le score se recalcule immédiatement après correction.
