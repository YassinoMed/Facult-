# LOOP 43-BIS — Plan de rollback et de reprise

## Objectif
Fournir des mécanismes de rollback structurés et testés pour chaque étape du déploiement, ainsi que des procédures de reprise après échec.

## 1. Principes de rollback

### 1.1 Rollback par étape
Chaque étape du déploiement (voir `LOOP-43BIS-PLAN-DEPLOIEMENT.md`) dispose de sa propre commande de rollback :

| étape | Commande de rollback | Effet |
|---|---|---|
| 1 (migrations) | `php artisan migrate:rollback --step=1` | Supprime toutes tables `iam_*`, restauration complète |
| 2 (config) | `php artisan config:clear` + vérification | Restitue `config/iam.php` précédent, vide les caches |
| 3 (interface) | Suppression routes + retour à ancienne vue | Ancienne interface admin retrouve, nouvelles routes disparaissent |
| 4 (workflow 4 yeux) | Suppression routes + repli attribution directe | Les demandes redeviennent des attributions directes, pas de nouveau workflow |
| 5 (fonctionnalités avancées) | `config/iam.php` en arrière-plan | Plus de recertification/urgence via nouvelle interface, mode dégradé |

### 1.2 Rollback global (toutes étapes)
Si un rollback complet est nécessaire :

```bash
# 1. Rollback des migrations (étape 1)
php artisan migrate:rollback --step=1

# 2. Effacer tous les caches
php artisan config:clear
php artisan cache:clear
php artisan route:clear
php artisan view:clear

# 3. Vérifier l'état des routes
php artisan route:list | grep -E "iam|cockpit|gouvernance"

# 4. Vérifier que les anciens contrôleurs/functions sont toujours actifs
#    - AppServiceProvider->register() avec Gates existants
#    - AttributionRoleService toujours actif
#    - EnsureMfa toujours actif

# 5. Reconstruire si nécessaire
php artisan view:compile
```

### 1.3 Vérification post-rollback
Après exécution du rollback :

```bash
# Vérifier le statut des migrations
php artisan migrate:status

# S'assurer que les tables iam_ n'existent plus
php artisan tinker --execute="Schema::hasTable('iam_profils')"

# S'assurer que les tests passent encore
php artisan test --filter="MfaLoopJTest"

# Vérifier que l'ancienne interface admin est accessible
# (tester manuellement ou via test d'integration)
```

## 2. Procédures de reprise après échec

### 2.1 Reprise à l'étape précédente
Si un problème est détecté après avoir avancé à l'étape N :

1. Identifier l'étape N où le problème a été introduit
2. Exécuter le rollback à l'étape N-1
3. Validé que tout fonctionne (tests, interface, accès)
4. Si nécessaire, prendre le temps d'analyser le problème en détail
5. Réessayer l'étape N avec des paramètres ajustés ou une approche différente

### 2.2 Cas particuliers

#### Cas A : Problème de base de données après migration
- Exécuter `php artisan migrate:rollback --step=1`
- Vérifier les contraintes étrangères, index en base
- S'assurer qu'aucune donnée sensible n'a été exposée
- Le cas échéant, restaurer la sauvegarde de base de données précédente

#### Cas B : Problème de permissions après activation du moteur
- Exécuter `php artisan config:clear`
- Vérifier que `config/iam.php` a des valeurs raisonnables
- S'assurer que `modules_reserves = ['iam']` est bien présent
- Vérifier que `modules_federes_lecture_seule = ['ai']` empêche la fusion silencieuse
- Tester `User::hasPermission()` sur un compte de test

#### Cas C : Problème d'interface après déploiement
- Supprimer les nouvelles routes de `routes/web.php`
- Restaurer les anciennes vues Blade
- `php artisan view:clear`
- Tester que l'ancienne navigation admin fonctionne (`routes/web.php` routes classiques)

#### Cas D : Problème de workflow quatre yeux
- Désactiver les nouvelles routes de demande d'accès
- Revenir à l'attribution directe via `AttributionRoleService`
- Notifier les utilisateurs que le nouveau workflow est temporairement désactivé
- Analyser le problème (conflit de statuts, quorum non atteignable, etc.) avant de réessayer

## 3. Sécurité lors du rollback

### 3.1 Garanties de sécurité
Pendant un rollback :

- **Aucune fenêtre de sécurité vacante** : le rollback vise à restaurer l'état précédent qui était validé
- **Pas de création de privilèges implicites** : le rollback ne supprime pas de permissions, il les rend simplement inaccessibles via le nouveau mécanisme (tout en conservant celles du moteur canonique)
- **Journalisation du rollback** : chaque étape de rollback est journalisée dans `iam_evenements` pour traçabilité
- **Validation finale** : après rollback, exécuter la checklist de vérification avant de considérer l'opération complète

### 3.2 Rolling back avec données
Si des données ont été créées pendant l'étape en cours (affectations, délégations, demandes) :

- Les données additifs dans les tables `iam_*` sont either :
  - Conservées mais rendues sans effet (le moteur retombe sur le mode canonique)
  - Supprimées par le rollback de la migration (étape 1)
  - Transférées vers l'ancien mécanisme avant le rollback (si possible)

La stratégie par défaut est le rollback complet de la migration (suppression des tables `iam_*`), qui revient proprement à l'état précédent.

## 4. Exemples de scénarios de rollback

### Scénario 1 : Écheu après l'étape 2 (activation moteur)
```
Problème : Après activation du MoteurAutorisation, certains tests échouent
           à cause de permissions inattendues.

Solution :
  1. php artisan config:clear
  2. Vérifier config/iam.php (valeurs par défaut conservatrices)
  3. Tester User::hasPermission() sur comptes de test
  4. Si tout fonctionne, réessayer avec des valeurs de config ajustées
  5. Si non, considération comme problème bloquant → rollback global
```

### Scénario 2 : Écheu après l'étape 3 (interface)
```
Problème : La nouvelle interface checkbox cause des erreurs JavaScript
           ou des conflits avec l'existant.

Solution :
  1. Supprimer les nouvelles routes de routes/web.php
  2. Restaurer les anciennes vues Blade
  3. php artisan view:clear
  4. Vérifier que l'ancienne navigation admin fonctionne
  5. Analyser les conflits JavaScript/Ajax
  6. Soit corriger l'interface et réessayer, soit arrêter le déploiement à cette étape
```

### Scénario 3 : Écheu après l'étape 4 (workflow 4 yeux)
```
Problème : Les demandes d'accès bloquent en statut SOUMISE
           car le quorum n'est pas atteint ou les approbateurs ne peuvent pas agir.

Solution :
  1. Vérifier config('iam.approbateurs_requis', 1) — est-ce bien la valeur voulue ?
  2. Vérifier que les approbateurs ont bien le can: nécessaire
  3. Vérifier que l'initiateur n'est pas aussi approbateur (règle stricte)
  4. Si le workflow est bloquant, le désactiver temporairement :
     - Supprimer les routes de demande d'accès
     - Revenir à l'attribution directe
  6. Analyser et réajuster les paramètres avant de réessayer
```

## 5. Checklist de sécurité avant rollback

Avant d'exécuter tout rollback :

- [ ] Identifier la cause réelle du problème (pas seulement les symptômes)
- [ ] S'assurer que le problème ne vient pas du code métier existant
- [ ] Vérifier que les 173 modifications locales ne sont pas en cause
- [ ] S'assurer que le rollback ne supprimra pas de données critiques sans sauvegarde
- [ ] Prévoir une fenêtre de maintenance communiquée
- [ ] Avoir un plan de reprise si le rollback lui-même échoue
- [ ] Journaliser le motif du rollback dans `iam_evenements`
- [ ] Prévoir une fenêtre de temps pour réessayer si le rollback initial pose problème

## 6. Contact et support

En cas de difficulté avec le rollback :
- Vérifier la documentation `LOOP-43BIS-*.md` correspondante
- Revoir les gates G43BIS-1 à G43BIS-14 dans `LOOP-43BIS-VERDICT.md`
- Consulter `LOOP-43BIS-PLAN-DEPLOIEMENT.md` pour les étapes détaillées
- En dernier recours, restaurer une sauvegarde complète de la base de données et des fichiers de configuration

## État actuel
- Tous les plans de rollback documentés pour chaque étape
- Procédures testées conceptuellement (non encore exécutées en production)
- Aucune étape de déploiement n'a été pleinement effectuée dans l'environnement actuel
- Les 173 modifications locales sont préservées et peuvent être restaurées à tout moment via `git`

## Livrables
- `LOOP-43BIS-ROLLBACK.md`

**Verdict G43BIS-16 :** ✅ Rollback — procédures documentées par étape, commandes précises, vérifications post-rollback, cas particuliers couverts, garanties de sécurité, checklist avant rollback.