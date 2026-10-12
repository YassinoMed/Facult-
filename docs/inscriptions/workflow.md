# Inscriptions — workflow (Phase 3)

```text
Création (ArchiveService::creerInscription, Request validée)
↓
en_cours
↓
Validation administrative (InscriptionService::transiter → valide, verrouillée)
ou Ajournement motivé (→ ajourne) / Abandon (→ abandon)
↓
Réinscription année N+1 (reinscrire : nouvelle ligne + S1/S2, historique intact)
Progression LMD (AcademicProgressionService, décision jury publiée requise)
```

Règles : transitions `TRANSITIONS` (`InscriptionService`) — terminaux
verrouillés, `DomainException` sinon ; tout est transactionnel
(scope → authorization → validation → règles → transaction → audit →
notification) ; `lockForUpdate` sur la ligne (transiter) et sur l'étudiant
(reinscrire) + contrainte unique = double clic/retry sans doublon (422
lisible, jamais 500).

Pas de `draft/pending/active` : workflow existant conservé (décision
audit §10). Paiement hors workflow ici (module Finances).

---

## Parcours public — 6 étapes (B4, 05/10/2026)

1. **Identification** (CIN + date de naissance, `POST /inscription/identifier`
   throttlé, aucune PII) ; 2. **Identité** (profil pré-rempli MENS, verrouillé
   CIN/naissance ; modification = re-vérification) ; 3. **Coordonnées** ;
   4. **Formation** ; 5. **Documents** ; 6. **Confirmation** (récap + mot
   de passe). Transition 1→2 pilotée par événement serveur
   (`identification-verifiee`) — jamais par contournement client. Période
   fermée : repli étape 4 (consultation), champs désactivés. POST `/`
   contrôle toujours serveur (unicité exacte en filet final).

## Identification fail-closed (correctif critique, 05/10/2026)

`POST /inscription` **recalcule** `IdentificationIntelligenteService::identifier()`
sur les valeurs soumises et exige un enregistrement MENS (`MENS_SEUL` ou
`MENS_ET_LOCAL`, sans divergence `BLOQUANT`) — sinon 422 générique, rien
créé. `UNKNOWN` (CIN/DOB absents de `mens_references`) bloque le wizard
(frontend : état erreur) comme la soumission directe, les valeurs
modifiées et les sessions réutilisées (aucune confiance au JS/session :
la vérification porte sur les données finales). Doublon exact → refus
`cin` historique ; double soumission → contrainte unique.

## Corrections d'inscription à quatre yeux (B8, 05/10/2026)

Une cible de changement (`programme_id`, `niveau_id`, `type`) n'est
plus écrite directement : `POST admin/inscriptions/{inscription}/corriger`
la **propose** dans `dossier_corrections` (à quatre yeux, idempotente).
Un second agent approuve/rejette (`admin.verification.decider`) puis
applique — `CorrectionService::appliquer` délègue à
`InscriptionCorrectionService` (drift-check JSON avant application).
Trace d'audit directe conservée pour un motif SEUL (aucune cible) —
comportement historique inchangé.
