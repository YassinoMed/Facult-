# LOOP 43-BIS — Plan de déploiement progressif

## Objectif
Déployer la composante LOOP 43-BIS par étapes successives, avec des gardes à chaque étape, pour minimiser les risques et permettre un retour en arrière si nécessaire.

## 1. Aperçu du déploiement

Le déploiement se déroule par **couches successives** :

| Couche | Composants | Risk | Rollback |
|---|---|---|---|
| **Couche 0** | Base existante (RBAC, Policies, Gates, MFA) | None (déjà en production) | Aucune action |
| **Couche 1** | Nouvelles migrations + modèles IAM | Faible (additive uniquement) | `migrate:rollback` sur la migration IAM |
| **Couche 2** | MoteurAutorisation + services de base | Faible (fonctionne en parallèle) | Désactivation du moteur via config |
| **Couche 3** | Interface admin checkbox + contrôleurs | Moyenne (nouvelle UI) | Passage en mode maintenance, retour à l'ancienne vue |
| **Couche 4** | Workflow demandes + approbations | Moyenne (nouveau flux) | Désactivation des nouvelles routes, utilisation des anciennes |
| **Couche 5** | Délégations, urgence, recertification | Élevée (fonctionnalités sensibles) | Passage en mode "urgences seules" avec anciens mécanismes |

## 2. Étapes détaillées

### Étape 1 : Migration de base (Couche 1)
**Commande :**
```bash
php artisan migrate --path=database/migrations/2026_10_31_120000_creer_tables_gouvernance_iam.php
```

**Ce qui change :**
- Création des tables additives (`iam_profils`, `iam_demandes`, `iam_approbations`, `iam_affectations`, `iam_habilitations`, `iam_delegations`, `iam_evenements`, `iam_urgences`, `iam_recertifications`, `iam_versions_autorisation`)
- Aucun changement sur les tables existantes
- Modèles Eloquent prêts à l'emploi

**Rollback :**
```bash
php artisan migrate:rollback --step=1
```
(Drop de toutes les tables `iam_*`, restauration de l'état précédent)

**Vérification :**
- `php artisan migrate:status` — nouvelle migration en `Ran`
- Tests existants toujours passables (`MfaLoopJTest` etc.)

### Étape 2 : Activation du moteur d'autorisation (Couche 2)
**Modification :** `config/iam.php`
- Vérifier que `approbateurs_requis`, `duree_max_affectation_jours`, `duree_max_delegation_jours`, `duree_max_urgence_heures` sont correctement paramétrés selon la politique institutionnelle
- S'assurer que `modules_reserves = ['iam']` et `modules_federes_lecture_seule = ['ai']`

**Ce qui change :**
- Le `MoteurAutorisation` est désormais consulté par `User::hasPermission()` (fallback)
- Toutes les nouvelles permissions prennent effet (profil, affectation, etc.)
- Le moteur s'ajoute aux Gates existants, ne les remplace pas

**Rollback :**
- Restitué `config/iam.php` à l'état précédent
- `php artisan migrate:rollback --step=1` pour retirer les tables
- Vider le cache config : `php artisan config:clear`

**Vérification :**
- Tests existants toujours passables
- `php artisan test --filter="MfaLoopJTest"` → toujours 10 tests, 75 assertions

### Étape 3 : Interface administrative (Couche 3)
**Modification :** Routes et contrôleurs nouvellement ajoutés
- Route `admin.gouvernance-iam` → nouvelle interface de gestion
- Matrices de checkboxes Alpine.js sur l'écran de permissions
- Récapitulatif avant enregistrement avec validation serveur

**Ce qui change :**
- Nouvelle interface pour gérer les profils, affectations, délégations
- Cases à cocher hiérarchiques (Module → Ressource → Action)
- Sélection multiple avec compteur et état partiel
- Récapitulatif avant confirmation

**Rollback :**
- Suppression des nouvelles routes de `routes/web.php`
- Suppression ou déplacement des nouveaux contrôleurs
- Retour à l'ancienne interface d'administration des rôles
- `php artisan view:clear` pour rafraîchir les vues

**Vérification :**
- L'ancienne interface fonctionne toujours
- Les nouvelles routes retournent 404 ou sont redirigées

### Étape 4 : Workflow quatre yeux (Couche 4)
**Modification :** Nouvelles routes pour les demandes d'accès
- Routes `admin/iam/demandes` → création, validation, approbation
- Workflow avec statuts `BROUILLON` → `SOUMISE` → `APPROUVEE` → `EXECUTEE`
- Approbateur ≠ initiateur, quorum lu en config

**Ce qui change :**
- Toutes les mutations sensibles passent par une demande explicite
- Quatre eyes principle appliqué (initiateur ≠ approbateur)
- Journalisation de chaque étape

**Rollback :**
- Suppression des nouvelles routes
- Repli sur l'ancien mécanisme d'attribution directe (AttributionRoleService)
- Notification aux utilisateurs que le nouveau workflow est désactivé

**Vérification :**
- Les demandes peuvent être créées mais aboutissent à une attribution directe (si le rollback est complet)
- Ou les demandes restent en `SOUMISE` indéfiniment (si les routes sont simplement supprimées)

### Étape 5 : Fonctionnalités avancées (Couche 5)
**Ajout :**
- Campagnes de recertification périodiques
- Accès d'urgence (break-glass) avec revue postérieure
- Alertes de sécurité dans le cockpit
- Rapports d'expiration et d'invalidation

**Ce qui change :**
- Fonctionnalités sensibles ajoutées en option
- Peut être activé/désactivé via `config/iam.php`

**Rollback :**
- Désactivation des nouvelles fonctionnalités dans `config/iam.php`
- Passage en mode dégradé (plus de recertification automatique, plus d'urgences via l'interface nouvelle)

## 3. Checklist de déploiement

### Avant déploiement
- [ ] Toutes les 14 gates validées (voir `LOOP-43BIS-VERDICT.md`)
- [ ] Migrations testées sur SQLite `:memory:`
- [ ] Tests existants toujours passables (`php artisan test --filter="MfaLoopJTest"`)
- [ ] `config/iam.php` paramétré selon la politique institutionnelle
- [ ] MFA active pour les rôles à privilèges (`EnsureMfa` fonctionne)
- [ ] Base de données de production prête (postgreSQL ou MySQL selon configuration)
- [ ] 173 modifications locales préservées (vérifier `git status`)
- [ ] Aucun commit destructive sur la branche en cours

### Après chaque étape
- [ ] Vérifier que les tests passent
- [ ] Vérifier que l'ancienne interface/functions fonctionne toujours
- [ ] Journaliser les événements dans `iam_evenements`
- [ ] Vérifier le cache d'autorisation (`MoteurAutorisation::invalider()` a été appelé)
- [ ] Confirmer avec les parties prenantes

### En cas de problème
- `php artisan migrate:rollback --step=N` pour revenir en arrière
- `php artisan config:clear` pour réinitialiser la configuration
- Vérifier les logs `iam_evenements` pour comprendre l'incident
- Consulter `LOOP-43BIS-RESULTATS-TESTS.md` pour les résultats des tests

## 4. Plan de rollback global

Si un problème critique est détecté après le déploiement complet :

1. **Arrêter** le déploiement à l'étape courante
2. **Exécuter** `php artisan migrate:rollback --step=5` (ou selon l'étape atteinte)
3. **Effacer** le cache : `php artisan config:clear` && `php artisan route:clear` && `php artisan view:clear`
4. **Vérifier** que toutes les routes/controllers anciennes sont restaurées
5. **Notifier** les utilisateurs du retour à l'état précédent
6. **Documenter** l'incident et les leçons tirées

## État actuel
- Couche 1 (migrations) : ✅ Effectuée (migrations s'exécutent avec SQLite)
- Couches 2-5 : Non encore déployées (en cours d'implémentation)
- Toutes les gates G43BIS-1 à G43BIS-14 validées en documentation

## Livrables
- `LOOP-43BIS-PLAN-DEPLOIEMENT.md`

**Verdict G43BIS-15 :** ✅ Plan de déploiement — 5 étapes progressives, rollback à chaque étape, checklist avant/après déploiement, conditions de retour en arrière définies.