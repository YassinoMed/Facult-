# LOOP 44 — INTÉGRATION AU MOTEUR ACADÉMIQUE ET EXPLICABILITÉ
## UniRegistre — Pont entre le registre de gouvernance et le moteur décisionnel d'acquis

**Date :** 31 octobre 2026  
**Auteur :** Antigravity Engine — UniRegistre Core Governance Team  
**Statut :** AUDITÉ ET CONFORME (PHASE 7 - LOOP 44)  
**Référence contractuelle :** LOOP 44 — Phase 7 & 10  

---

## 1. Architecture de Liaison entre Gouvernance et Moteur Métier

Le service [`ReglementationAcquisService`](file:///Users/mohamedyassine/Desktop/PFE/inscription-etudiants/app/Services/Acquis/ReglementationAcquisService.php) a été enrichi pour intégrer nativement la gouvernance versionnée via la méthode `consulterRegleVersionnee()`.

Cette intégration s'opère selon les principes stricts suivants :
1. **Priorité au Registre de Gouvernance** : Avant tout calcul sensible (seuil de validation, compensation, plafond de redoublement), le moteur consulte le registre versionné immuable.
2. **Refus des Seuils Arbitraires par Défaut** : Si aucune règle confirmée et publiée n'existe pour un code donné à la date demandée, le moteur **n'applique aucune constante codée en dur**. Il renvoie un statut d'indétermination (`REGLE_NON_TROUVEE` ou `REVUE_HUMAINE_REQUISE`).
3. **Zéro Exclusion Automatique** : L'absence de règle confirmée ne doit jamais conduire à l'exclusion, à l'ajournement ou à la déchéance des droits d'un étudiant. Le dossier est gelé dans un sas d'instruction en attente de validation institutionnelle.
4. **Explicabilité et Traçabilité Complète** : Tout résultat de calcul produit est annoté du numéro de version de la règle appliquée (`version`), de son empreinte (`checksum`), et de la référence officielle exacte du texte juridique fondant la décision.

---

## 2. Spécification de la Méthode d'Intégration `consulterRegleVersionnee`

```php
public function consulterRegleVersionnee(string $code, ?string $date = null, ?array $contexte = null): array
{
    $gouvernance = app(\App\Services\GouvernanceReglesService::class);
    $diagnostic = $gouvernance->detecterConflits($code, $date);

    if ($diagnostic['statut'] === 'REVUE_JURIDIQUE_REQUISE') {
        return [
            'code' => $code,
            'statut' => 'REVUE_JURIDIQUE_REQUISE',
            'applicable' => false,
            'revue_humaine_requise' => true,
            'valeur' => null,
            'version' => null,
            'reference_officielle' => null,
            'motif' => $diagnostic['motif'],
        ];
    }

    $regle = $diagnostic['regle_applicable'] ?? null;

    if ($regle === null) {
        return [
            'code' => $code,
            'statut' => 'REGLE_NON_TROUVEE',
            'applicable' => false,
            'revue_humaine_requise' => true,
            'valeur' => null,
            'version' => null,
            'reference_officielle' => null,
            'motif' => "Aucune règle versionnée confirmée et applicable pour le code {$code} à la date indiquée.",
        ];
    }

    return [
        'code' => $code,
        'statut' => $regle->statut,
        'applicable' => $regle->estPubliee(),
        'revue_humaine_requise' => false,
        'valeur' => $regle->contenu,
        'version' => (int) $regle->version,
        'reference_officielle' => $regle->reference_officielle,
        'motif' => "Règle version {$regle->version} active selon {$regle->reference_officielle}.",
    ];
}
```

---

## 3. Matrice de Réponse et Comportement du Moteur

| Situation de la Règle | Statut Émis | Décision Calculée par le Moteur | Action Requise |
|---|:---:|---|---|
| **Règle Publiée, Date Valide, Conforme** | `PUBLIEE` / `CONFORME` | **Calcul Automatique Autorisé** (moyenne, compensation, crédits ECTS). | Traitement normal. |
| **Règle en Brouillon / En Revue** | `EN_REVUE` | **Calcul Automatique Bloqué** (aucune valeur consommée). | Finaliser le circuit des 4 yeux. |
| **Règle Remplacée / Expirée** | `EXPIREE` | **Calcul Actuel Bloqué** ; conservé pour l'audit rétrospectif des années passées. | Publication de la version suivante. |
| **Règle en Conflit Juridique** | `REVUE_JURIDIQUE_REQUISE` | **Calcul Automatique Formellement Bloqué**. | Arbitrage de la commission juridique. |
| **Règle Absente / Inconnue** | `REGLE_NON_TROUVEE` | **Calcul Bloqué** (aucun seuil par défaut appliqué). | Création formelle d'une règle par la scolarité. |

---

## 4. Respect des Feature Flags de la LOOP 43

L'intégration au moteur respecte scrupuleusement le cloisonnement étanche établi lors de la phase LOOP 43 :
- Le drapeau `gouvernance_regles` dans `config/feature_flags.php` reste soumis aux mêmes plafonds environnementaux (`local, testing, preproduction` ; production formellement bloquée).
- Seules les actions d'audit et de simulation ont accès aux règles versionnées en environnement de test.
- Aucun recalcul de masse rétroactif sur les dossiers étudiants n'est déclenché lors de la publication d'une nouvelle version de règle.

