# Architecture — progression LMD

`AcademicProgressionService` (`app/Services`) : UNIQUE moteur (audit :
aucun existant — `reinscrire()` manuelle aveugle, `niveauSuivant()` privé
chaîne `ordre`, décisions sans effet inscription).

```text
DecisionDeliberation publiée (inscription source)
        ↓ evaluer() dry-run
Niveau réf. (ordre) + Année suivante (dates) / active
        ↓ appliquer() transactionnel idempotent
InscriptionService::reinscrire (cibles explicites)
        ↓
Audit creation_inscription + Situation reinscription + PopulationChanged
        ↓
Notification inscription.progression (1er usage, sans PII)
```

Zéro table : historique = `inscriptions_academiques` (unicité bornée) +
`evaluation_decisions` + `situations_etudiant` + archive (timeline existante
reconstruit `L1 — Admis → L2…`, §11).

`InscriptionService::niveauApres()` : extraction publique de la règle
`ordre` (niveauSuivant = wrapper, comportement inchangé).
UI : panneau fiche étudiant (évaluation dernière inscription) + POST
`admin.inscriptions.progresser` (confirmation explicite, throttle),
mêmes Gates que `reinscrire` (`archives.create/update` + scope).
API/exports/archive : sources inchangées, rien de nouveau.
