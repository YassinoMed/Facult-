# Maquettes et versionnement

`Maquette` = programme + année + version + statut
(`draft→review→approved→active→archived`, bascule `active` atomique).
`nouvelleVersion()` = duplication officielle (incrément + snapshot pivot
`maquette_ue`) — il n'existe pas d'autre duplication.
`definirContenu()` rattache UE du programme ; verrouillée si
approved/active/archived. Inscriptions pédagogiques adossées à la maquette
ACTIVE (`InscriptionService::inscrireEcue`).
