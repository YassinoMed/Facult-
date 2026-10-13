# LOOP M — SMTP & MFA, état réel (13/10/2026)

## SMTP : NOT_CONFIGURED (honnête)

Stockage chiffré (`encrypted`+`$hidden`, brut jamais en clair — test),
validation port/email/chiffrement, activation unique, CRUD + test connexion
(4/4, refus 127.0.0.1:1 honnête), diagnostic sans mot de passe, zéro secret
en paramètres/versions/logs. Pilote effectif : `log` ; table vide (aucune
configuration créée hors tests) ; **aucun e-mail réel envoyé**. Connexion =
`start/stop` transport (pas de livraison), timeouts 10 s, erreurs expurgées.
Prérequis envoi : fournisseur + secrets coffre + test vers destinataire approuvé.

## MFA : implémentée, adoption nulle

TOTP pur PHP prouvé par 6 vecteurs RFC 6238 (8/8) ; enrôlement QR-confirmé ;
défi throttlé post-login (session sans droits) ; codes fenêtrés ±1 ;
désactivation par mot de passe ; reset super-admin coupe la MFA (audité) ;
secret chiffré + masqué + hors fillable (4/4 flux). Dev : **0 compte actif**.
Réutilisation interdite non prévue (fenêtre ±1 acceptée, documentée).
Tension : DH-18 listait la 2FA comme décision — implémentée sous approbation
session LOOP J du 13/10 ; mettre à jour le registre des décisions.
