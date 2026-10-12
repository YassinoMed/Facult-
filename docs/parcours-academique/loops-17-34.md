# Parcours académique — PROMPTs 17 à 34 (LOOPS)

Exécutés un par un le 04/10/2026, sans doublon, sans régression.

- P17 tableau de bord : KPI scopés (étudiants, passages/passerelles
  simulés, équivalences/dispenses validées, réorientations,
  passerelles admises) + alertes + simulations. Test indicateurs.
- P18 prospective : `ProspectiveParcoursService::projeter` (dry-run
  `AcademicProgressionService::evaluer` par inscription, projetés N+1
  = redoublements + passages N-1, places vs capacité groupes,
  places manquantes). Route `admin.parcours.prospective`. Test
  sans écriture + déterminisme.
- P19 assistant : `AssistantParcoursService::expliquer` (résumé, points,
  sources citées, avertissement non-décision). Test sans écriture.
- P20 anomalies : `AnomalieParcoursService::detecter` (5 codes classés,
  complète `VerificationEngine`, correction au centre existant).
- P21 documentaire : réemploi GED/Centre (snapshot+hash+version) —
  fiche renvoie au centre, aucun document depuis une simulation.
  Test attestation admise/refusée.
- P22 relevés : réemploi `OfficialDocumentService` (décision requise,
  anti-régénération, code vérification). Test.
- P23-25 imports : réemploi `NoteImportDefinition` + pipeline
  DataExchange (preview→confirm, `file_hash`, chaîne
  CIN→matricule→CIN+DoB, verrou refusé). Non-régression 16 OK.
- P26 centre résolution : réemploi (workflow + 4-eyes + motif).
  Non-régression 17 OK.
- P27 notifications : listener `NotifierParcoursDecision`
  (best-effort via `CommunicationDispatcher`, event
  `parcours.decision`, gabarit à créer — sans gabarit = no-op).
- P28 API : 4 GET lecture (`parcours`, `parcours-credits`,
  `parcours-risques`, `parcours-simulations`, scope `archives:read`,
  throttle, BOLA 404, sans PII) + openapi resynchronisé (dérive OK).
- P29 recherche : aucun index parcours (non-fuite testée, saut
  direct conservé et scopé).
- P30 audit : simulation/décisions/risques tracés (`historique_academique`, sans PII) via `AuditService` + listener.
- P31 sécurité : scope→404 avant gate→403 partout, 403 sans
  `parcours.simuler`, BOLA API 404, concurrence 409, lecture
  conservée pour consultation.
- P32 versionnement : `parcours_regles` code@version (résolution
  spécifique>programme>globale>socle) + `RegleEvaluation` + snapshots
  délibération existants ; simulations figent `regle_reference`.
- P33 institutionnelle : prospective déterministe (P18) ; moteur de
  variantes de règles = dette documentée (pas de second moteur).
- P34 global : 50 tests module + 108 voisins OK, pint vert.

Dettes : gabarit `parcours.decision` à créer (notifications),
variantes de règles institutionnelles, export prospectif, jobs
gros volumes.
