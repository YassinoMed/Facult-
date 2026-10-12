# Reprise après incident (DR)

Objectifs **à valider par l'établissement** (jamais inventés comme garantis) :

| Service | RPO cible | RTO cible |
|---|---|---|
| Base académique | 1 h (planning horaire par défaut, configurable) | 4 h |
| Documents privés | 1 h | 4 h |
| Notifications/file | reconstruction (rejeu) | 8 h |

## Sauvegardes

- Planifiées (défaut horaire) : `sauvegarde:evaluer` toutes les 15 min →
  `ExecuterSauvegardeJob` si échu (planning `sauvegarde.plan.*` : actif,
  fréquence 1/2/6/12/24 h ou personnalisé jours+heure, rétention jours
  et nombre, destination, chiffrement, alertes). Lots `base-*.sql` +
  `documents-*.zip` + `manifest-*.json` signé sha256.
- **Chiffrement au repos (05/10/2026)** : définir `SAUVEGARDE_CLE`
  (32 octets base64 : `php -r "echo base64_encode(random_bytes(32));"`)
  puis planifier `sauvegarde:creer --conserver=14 --chiffrer` — les
  artefacts deviennent `.enc` (sodium secretbox), le manifeste les
  référence chiffrés (vérification inchangée). Restauration d'un lot
  chiffré : `sauvegarde:dechiffrer <fichier.enc> --sortie=<fichier>`
  AVANT `mysql`/`unzip`. Sans clé : `--chiffrer` échoue explicitement.
  La clé vit dans le coffre à secrets (§ Secrets) : PERDUE = lots
  définitivement irrécupérables — conserver une copie hors site.
- Vérification : `sauvegarde:creer --verifier [--dossier=...]` (à planifier
  après chaque sauvegarde critique, et avant toute restauration).
- Exigences serveur : `mysqldump` dans le PATH (MySQL), `zip` PHP,
  espace disque ≥ 2× la taille de `storage/app/private`.
- Externaliser les lots (copie hors serveur) : sans copie externe,
  RPO/RTO ci-dessus ne tiennent pas — à organiser avec l'hébergeur.

## Restauration

```bash
# 1. Vérifier le lot
php artisan sauvegarde:creer --verifier --dossier=/chemin/lot

# 2. Base (MySQL)
mysql -h HOST -u USER -p BASE < base-20260930-010000.sql

# 3. Documents
unzip -o documents-20260930-010000.zip -d storage/app/private/

# 4. Remise en service
php artisan migrate --force
php artisan optimize:clear
php artisan queue:restart
php artisan up   # si maintenance activée pendant l'opération
```

## Scénarios

- **Panne DB** : restaurer le lot vérifié le plus récent, rejouer le
  journal binaire MySQL si disponible (sinon perte ≤ 24 h).
- **Corruption / suppression accidentelle** : ne JAMAIS restaurer sur la
  base de production sans snapshot préalable ; restaurer d'abord en
  staging et contrôler les effectifs (`admin/bi`).
- **Compromission** : rotation `APP_KEY` + Sanctum (`integration:token-revoke`),
  sessions (`sessions` purgées), audit `admin/audit`, puis restauration
  d'un lot **antérieur** à la compromission.
- **Panne stockage** : restaurer `documents-*.zip`, vérifier les sha256
  des pièces critiques, relancer `documents:marquer-expires`.
- **Maintenance** : `php artisan down --secret=...` / `php artisan up`.
  Ne jamais bloquer sans stratégie de sortie (secret conservé hors serveur).

## Secrets

`.env` (APP_KEY, DB, mail, Sanctum, webhooks) : hors sauvegarde applicative,
coffre dédié + copie chiffrée. Sans APP_KEY, les sessions/chiffrements
sont irrécupérables.

## Exercice de restauration (drill trimestriel)

> Principe (mission audit 04/10/2026 §36) : **un backup non testé n'est pas
> une stratégie de récupération fiable.** Le drill ci-dessous est exécuté sur
> l'environnement de **staging**, jamais en production, et consigné dans le
> journal d'exploitation (date, opérateur, lot utilisé, durée mesurée,
> écarts, actions correctives).

1. **Sélectionner le lot** le plus récent externe (`storage/app/sauvegardes/`
   ou copie hors serveur) puis `php artisan sauvegarde:creer --verifier --dossier=...`.
2. **Restaurer sur staging** (base SQL + `documents-*.zip`) en suivant la
   procédure « Restauration » ci-dessus.
3. **Valider la cohérence** :
   - effectifs par faculté/niveau (`admin/bi`) identiques au manifeste ;
   - sha256 de 3 pièces critiques retrouvés (`documents` GED) ;
   - connexion back-office + espace étudiant OK ; journal d'audit lisible.
4. **Mesurer le RTO réel** (début → validation) et le confronter à la cible ;
   tout écart devient une action datée.
5. **Clôturer** : consigner le rapport de drill ; si échec, corriger la
   procédure AVANT le prochain cycle et re-tester.

Cadence : trimestrielle + après tout changement majeur de schéma, de stockage
ou de la commande `sauvegarde:creer`.
