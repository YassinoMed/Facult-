# Décisions humaines requises (12/10/2026)

| ID | Décision | Contexte | Blocage |
|---|---|---|---|
| DEC-01 | ARB-001…009 (référentiel LMD, sources S2-S6, activation maquette) | `docs/pedagogie/registre-arbitrages-lmd.md` | `REF` PARTIAL ; toute migration auto interdite |
| DEC-02 | Protection `main` + checks CI requis | Audit CI/CD | Sans elle, gate = avis (`BLOCKED` potentiel) |
| DEC-03 | Demandes enseignant : OnlineRequests vs tables parallèles | `PORT` | Arbitrage d'architecture requis |
| DEC-04 | Chantier CSP `@alpinejs/csp` (éliminer `unsafe-eval`) | `SEC` | Nouvelle dépendance + refonte, approbation requise |
| DEC-05 | NO-GO hors-ligne étudiant / assistant IA | `PORT` | Nouvelle approbation pour rouvrir |
| DEC-06 | Mise en production (secrets, TLS, drill, checklist) | `INFRA` | Autorisation explicite, preuves ops |
