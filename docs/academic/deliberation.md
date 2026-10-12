# Délibération (Phase 6)

Workflow `draft → prepared → (in_review|convened|in_progress) → validated → published → locked → archived` (`Deliberation::TRANSITIONS`, serveur uniquement). `preparer` : population centrale + `syntheseEtudiant` + snapshot (`regles_appliquees`, `anomalies`) + `delete+insert` décisions (idempotent, `lockForUpdate`). `decider` : whitelist + optimistic `updated_at`. `valider` : quorum (président + 2 présents) + zéro anomalie bloquante + zéro `a_examiner`, pose `verrouillee_at`. `publier` : décisions `publie=true` + notif masse `deliberation.publiee`. Immuabilité : verrouillée = `preparer`/`decider` refusés ; `published → locked → archived` seuls.

Anomalies bloquantes : `note_non_validee`, `sans_note`. Snapshot = règles résolues (année+programme, version) : reproductibilité (§7) — recalcul identique à données et version égales.

Séparation §5 : RÉSULTAT (synthèse) ≠ VALIDATION (seuils/crédits) ≠ DÉCISION (jury, 8 valeurs) ≠ PROGRESSION (service dédié).
