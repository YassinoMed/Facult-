# ANALYSE DES RISQUES DE SÉCURITÉ ET CONTRÔLES APPLIQUÉS — LOOP 146

**Référence :** `04-risques-securite.md`  
**Application :** UniRegistre  
**Date :** 2026-10-29  

---

## 1. Matrice des Risques et Remédiations

| Domaine de Sécurité | Risque Identifié | Contrôle Implémenté | Preuve de Validation | Verdict |
| :--- | :--- | :--- | :--- | :---: |
| **BOLA / IDOR** | Accès ou modification d'une notification ou dérogation d'un tiers | Vérification stricte du destinataire et du modèle sous-jacent | Contrôle côté serveur dans les services métier | `PASS` |
| **Fail-Closed** | Statuer sans compétence ou clôturer sans droit | Rejet par `DomainException` en cas d'autorité inconnue ou fondement absent | `NotificationPreuveRemiseTest`, `EcheanceDerogationServiceTest` | `PASS` |
| **Zero-PII** | Fuite de données personnelles ou identités d'étudiants dans les journaux | Anonymisation des logs, empreintes de documents sans données brutes | `AuditService` et `IntegrationLog` | `PASS` |
| **Corruption relationnelle** | Modification sauvage des inscriptions ou examens suite à un recours | Garantie formelle `mutations_automatiques === false` (`BUS-004`) | `test_decision_recours_identifie_dossiers_sans_mutation_automatique` | `PASS` |
| **Intégrité historique** | Écrasement des dates initiales ou effacement de refus antérieurs | Enregistrement cumulatif append-only (`HIST-003`, `HIST-007`) | `test_constater_depassement_echeance_preserve_date_initiale_et_retard` | `PASS` |

---

## 2. Synthèse des Contrôles par Catégorie OWASP
- **Authentification & Session :** Conforme aux politiques Sanctum et session web.
- **Autorisation (RBAC) :** Politiques d'accès vérifiées côté serveur.
- **Injection SQL & XSS :** Requêtes paramétrées Eloquent et échappement automatique Blade.
- **Contrôle CSRF :** Tokens CSRF systématiques sur tous les formulaires d'action.
