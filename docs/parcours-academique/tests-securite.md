# Parcours académique — tests & sécurité

`tests/Feature/ParcoursAcademiqueTest.php` : 22 tests.

- Socle : module off → 404.
- L1→L2, L2→L3 (passage), L3 sans admission (jamais auto + passerelle non_eligible), M1→M2, redoublement même niveau.
- Dette (1 matière < 10), compensation (avant/après), crédits (requis 180, restants, semestres, historique).
- Équivalence (propose→valide + audit), dispense (notes intactes, dette conservée), réorientation (historique intact), passerelle (admise + motif commission).
- Risque (niveau + motifs, statut inscription inchangé = aucune décision auto).
- Simulation vs officiel (notes/décisions/inscriptions inchangés), historique (inscription+délibération+simulation), comparaison (2 étudiants), plan L1 (propositions + règles).
- Sécurité : IDOR autre faculté → 404 (fiche + store), sans `parcours.simuler` → 403, concurrence double décision → 409, routes fiche/simulation OK.

Voisins vérifiés : AcademicProgression/Calculation + Deliberation/Evaluation/Enseignement (51 OK),
Inscriptions/Reinscription/CentreResolution/Verification/Dossier (41 OK),
Notes/Documents/Communication (29 OK).
`tests/Feature/Security/FaculteScopeEcritureTest` : 7 échecs + 1 erreur
pré-existants hors périmètre (annonces/salles/rattachement/composantes —
aucun fichier parcours impliqué).

Performance : fiche = ~6 requêtes scopées + eager (`with`), simulations
sans N+1 (agrégats en mémoire), pagination 15/20, aucune requête en Blade.
Traitements lourds : aucun (pas de job requis à ce stade).
