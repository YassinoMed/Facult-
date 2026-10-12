# Vérification publique (Phase 7 §50-52, §72)

Route `GET /verifier/{reference}` (sans auth, `throttle:30,1`,
référence opaque non séquentielle) : affiche uniquement valide/invalide,
référence, type, date, statut, établissement. Jamais de CIN, notes,
adresse, téléphone, médical ou données internes. Référence inconnue =
404 (aucune confirmation d'existence).

Intégrité : `sha256` stocké par document (détection de modification) ;
`statut` (`valide|remplace|revoque`) affiché — un document remplacé ou
révoqué ne doit jamais être accepté comme valide.
