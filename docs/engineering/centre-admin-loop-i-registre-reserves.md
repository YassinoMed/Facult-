# LOOP I — Registre des réserves (12/10/2026)

| ID | Réserve | Preuve | Gravité | Décision/action | Clôture |
|---|---|---|---|---|---|
| R-I-01 | SMTP `NOT_CONFIGURED` (pilote `log`, aucun fournisseur) | `.env.example`, zéro `Mailable`, ORM vide | MAJEURE (si e-mails requis) | Fournisseur + secrets + test réel | Envoi réel prouvé |
| R-I-02 | MFA/invitations `NON IMPLÉMENTÉS` | grep vide | DÉCISION MÉTIER | Approbation + dépendances | Implémenté + testé |
| R-I-03 | 17 permissions candidates non créées | matrice doc + verrou test | DÉCISION MÉTIER | Approbation explicite | Créées + tests |
| R-I-04 | Panne Redis réelle non testée (stub) | test panne (stub) | MODÉRÉE | Test chaos si Redis en recette | Preuve environnement |
| R-I-05 | `updateOrInsert` seeds non testé en réexécution | lecture code | MINEURE | Test idempotence | Test vert |
| R-I-06 | Comptage suites 14/42 vs 15/43 | exécution LOOP I | MINEURE | Aligner le rapport final | Décompte corrigé |
| R-I-07 | Production `NOT_VERIFIED` | checklist non cochée | BLOQUANTE (exploitation) | DEC-06 + preuves ops | Checklist + drill |
| R-I-08 | Pint global 9 échecs hors périmètre | `pint --test` | MODÉRÉE (dette) | Mains concernées | Vert global |
| R-I-09 | Navigateur réel non exécuté (a11y dynamique) | env sans navigateur | MODÉRÉE | Recette navigateur | Vérification manuelle |
