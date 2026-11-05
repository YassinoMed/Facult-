# LOOP 44 — VERSIONNEMENT IMMUABLE ET EMPREINTES CRYPTOGRAPHIQUES
## UniRegistre — Traçabilité append-only, protection contre les altérations et stratégie de correction

**Date :** 31 octobre 2026  
**Auteur :** Antigravity Engine — UniRegistre Core Governance Team  
**Statut :** AUDITÉ ET CONFORME (PHASE 4 - LOOP 44)  
**Référence contractuelle :** LOOP 44 — Phase 4 & 10  

---

## 1. Principes d'Immuabilité Absolue

Dans UniRegistre, **aucune règle académique publiée ou approuvée ne peut être modifiée sur place (in-place update)**. Toute tentative de mise à jour d'un enregistrement existant est interceptée et bloquée par une garde de modèle Eloquent au niveau du noyau applicatif.

### Garde de Modèle Implémentée (`booted()`) :
```php
static::updating(function (self $regle): void {
    $statutOrigine = $regle->getOriginal('statut');

    if (! in_array($statutOrigine, [
        self::STATUT_APPROUVEE,
        self::STATUT_CONFIRMEE,
        self::STATUT_PUBLIEE,
        self::STATUT_SUSPENDUE,
        self::STATUT_REMPLACEE,
        self::STATUT_EXPIREE,
        self::STATUT_RETIREE,
    ], true)) {
        return;
    }

    foreach (self::CHAMPS_FIGES as $champ) {
        if ($regle->isDirty($champ)) {
            throw new DomainException(
                "Le champ « {$champ} » d'une règle {$statutOrigine} est immuable : créez une nouvelle version."
            );
        }
    }
});
```

---

## 2. Empreinte Cryptographique Déterministe (SHA-256)

Pour se prémunir contre les attaques directes sur la base de données (ex. modification directe d'un seuil via console SQL ou injection), chaque version est scellée par un **checksum SHA-256 canonique**.

### Algorithme de Calcul de l'Empreinte :
1. Tri récursif de toutes les clés du tableau `contenu` via `ksort()` récursif.
2. Sérialisation JSON normalisée avec `JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES`.
3. Hash SHA-256 de la structure canonique `['code' => $code, 'version' => $version, 'contenu' => $contenu]`.

```php
public function checksum(string $code, int $version, array $contenu): string
{
    $canonique = $this->canonicaliser([
        'code' => $code,
        'version' => $version,
        'contenu' => $contenu,
    ]);

    return hash('sha256', json_encode($canonique, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}
```

### Détection d'Altération en Temps Réel :
La méthode [`verifierCoherence()`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/app/Services/GouvernanceReglesService.php) recalcule dynamiquement le checksum de chaque règle en base. Si un champ JSON a été falsifié en base sans recalcul d'empreinte (ce qui nécessite la clé applicative et l'historique), l'anomalie `CHECKSUM_INVALIDE` est immédiatement signalée et la règle est écartée des calculs officiels.

---

## 3. Stratégie de Correction Sans Suppression

Lorsqu'une règle publiée comporte une erreur matérielle ou doit évoluer à la suite d'un nouvel arrêté ministériel :

1. **Interdiction de Suppression** : `static::deleting` interdit la suppression de tout enregistrement dont le statut est différent de `BROUILLON`.
2. **Création d'une Nouvelle Version `v+1`** :
   - Le numéro de version est incrémenté de manière atomique (`max('version') + 1` sous verrouillage transactionnel `lockForUpdate()`).
   - L'auteur documente le motif de la correction dans le contenu.
   - La nouvelle version franchit le circuit complet des 4 yeux (soumission -> revue -> approbation).
3. **Transition Automatique par Remplacement (`remplacer()`)** :
   - La méthode atomique `remplacer($ancienne, $nouvelle, $publicateur)` clôture la période de l'ancienne version (`date_fin = date_effet_nouvelle - 1 jour`).
   - L'ancienne version passe au statut `REMPLACEE`.
   - La nouvelle version passe au statut `PUBLIEE`.
4. **Intégrité Rétrospective Assurée** :
   Les délibérations et jurys passés qui avaient délibéré sous l'empire de la version `v1` continuent d'être relus avec la règle `v1` lors des audits a posteriori.

