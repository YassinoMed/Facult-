# Dette technique (2026-09-30)

Constatée, non bloquante :

1. `StatistiquesController` vs `BIService` : chevauchement partiel assumé
   (fonctions différentes : pilotage vs analyse gouvernée).
2. Vues `show` Blade longues (fiche étudiant, planning) : extraction différée.
3. `TODO` code : 1 seul (placeholder `INS-...-XXXXX` vue succès — affichage, inoffensif).
4. `GUIDE-DEPLOY` : annexe deploy.sh absente (promesse doc à tenir ou retirer).
5. Cache BI en `array` acceptable (volumes actuels) ; passer en `database`/
   `redis` si latence mesurée > seuil.
6. `Teacher` sans ancrage faculté direct (voir M5).
7. Anciens importers `Io/` coexistent avec DataExchange (compat API v1 conservée
   volontairement — ne pas supprimer sans versionner).
