# LOOP INDUSTRIALISATION ÉVALUATION — 15 · Observabilité

## Journalisation (4 journaux distincts — jamais fusionnés)

| Journal | Canal | Contenu | Consultation |
|---|---|---|---|
| Technique | `Log::info` (`AuditEvaluation`) | `evaluation.{type}` + entite_id + acteur | logs serveur |
| Historique métier | `evaluation_notes_historique` (append-only) | avant/après, motif, auteur | UI contrôlée (`notes_eval.view`) |
| Décisions officielles | `deliberations` + `evaluation_decisions_deliberation` | décision, moyenne, crédits, `publie`, verrou | UI gouvernance |
| Intégrations/exports | `integration_logs` | opérations, statuts, durées | `ExamHistoriqueAuditOrchestratorService` (filtres serveur) |

## Événements de surveillance recommandés (PHASE R)

| Événement | Source | Alerte |
|---|---|---|
| Tentative après verrouillage | `DomainException` « verrouillée » | log applicatif + taux |
| Refus hors périmètre | Policy 403 | compteur par acteur |
| Rejeu | refus matrice/historique | détection de boucle |
| Échec import confirmé | rapport final | notification opérateur |
| Publication | event `deliberation.publiee` | trace officielle |

Règle : alertes fondées sur des **événements vérifiables** (exceptions métier journalisées, états persistés) — aucun seuil arbitraire.

## Corrélation

- `EvaluationChanged(entiteId)` relie les événements aux objets ; `IntegrationLog.operation` relie les exports/imports ; l'historique relie note↔auteur↔motif↔date.
- **Aucun secret** (mot de passe, token, MFA) n'est journalisé — vérifié sur les 4 canaux.
