# LOOP 43-BIS — Délégations et droits temporaires

## Objectif
Permettre des délégations encadrées sans créer de privilèges permanents.

## 1.1 Délégation

### 1.1.1 À préciser dans une délégation
Une délégation doit préciser :
- **Délégant** : compte qui délègue (doit détenir la permission en propre)
- **Bénéficiaire** : compte qui reçoit la permission temporaire
- **Rôle ou permissions déléguées** : code(s) de permission précis
- **Périmètre** : faculté, programme, ou global
- **Début** : date/heure de début d'effet
- **Expiration** : date/heure de fin (obligatoire)
- **Motif** : raison de la délégation
- **Validation** : approbation le cas échéant (selon config `iam.approbateurs_requis`)
- **Restrictions** : interdictions (pas de sous-délégation, pas de chaîne)

### 1.1.2 Exemple de délégation
```
Délégant : admin_faculte de la faculté F
Bénéficiaire : agent_scolarite Y
Permission : `demandes.approve`
Périmètre : faculté F
Du 2026-11-01 au 2026-11-30
Motif : absence du délégant pour formation
Validation : 1 approbateur requis (config par défaut)
Restrictions : pas de sous-délégation, expiration automatique
```

## 1.2 Limites

### 1.2.1 Ce qu'une délégation ne doit jamais faire
Une délégation ne doit jamais :
- Dépasser l'autorité du délégant (vérification côté serveur à chaque évaluation)
- Dépasser son périmètre (faculté du délégant contrôlée via `faculteRestreinteId()`)
- Devenir permanente implicitement (expiration vérifiée à chaque `autorise()` — pas de scheduler requis)
- Être transférable sans autorisation (le délégataire ne peut pas redéleguer)
- Être réutilisée après révocation (toute nouvelle demande nécessaire)
- Permettre une élévation récursive de privilèges (pas de chaîne de délégation)

### 1.2.2 Sous-délégation interdite par défaut
Le délégataire ne peut pas redéleguer la permission à un tiers. Si besoin, le délégant doit déléguer directement.

## 1.3 Expiration

### 1.3.1 Vérification à chaque autorisation
L'expiration doit être vérifiée au moment de chaque évaluation d'autorisation via `MoteurAutorisation::autorise()`. Ne pas compter uniquement sur un scheduler.

### 1.3.2 Rôle du scheduler
Le scheduler sert à :
- Nettoyer les délégations expirées (suppression ou marquage comme expirées)
- Historiser les expirations
- Générer des rapports de délégations arrivant à expiration
- Mais le moteur d'autorisation doit déjà refuser les délégations expirées sans attendre le scheduler

## 1.4 Revue

### 1.4.1 Consultation
Permettre la consultation de :
- Délégations actives (encore dans la fenêtre de validité)
- Prochaines expirations (délégations dont l'expiration approche, ex: dans les 7 jours)
- Délégations expirées (encore présentes en base, marquées comme telles)
- Délégations révoquées
- Bénéficiaires de chaque délégation
- Raisons et validateurs
- Historique des modifications

## État des délégations
- Règles impératives : pas de chaîne, vérification du délégant à chaque évaluation
- Fenêtre bornée, expirée sans scheduler actif (vérification immédiate)
- Limites strictes (pas de dépassement d'autorité, pas de périmètre dépassé)
- Expiration vérifiée au moment de l'accès, pas seulement par scheduler
- Revue possible avec liste complète des délégations et bénéficiaires