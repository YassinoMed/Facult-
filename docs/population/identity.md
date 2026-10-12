# Identité étudiante (Phase 2)

Canonique : `id` interne. Fonctionnel : `num_inscription` (INS-YYYY-00001,
UNIQUE, non mass-assignable). National : `cin` (UNIQUE, **nullable** —
étrangers ou sans pièce ; parcours public l'exige, back-office/import
acceptent NULL). Contact : `email` (UNIQUE, login). Pas d'UUID étudiant.

Règles : CIN ou email pour dédupliquer (jamais `where cin vide`) ;
`masquerCin`/`cin_masquee` rendent `''`/`—` si absent ; complétude identité
= nom + prénom + (CIN ou email). Champs arabe/lieu/pays/nationalité/
contact-urgence : NON créés (minimisation, extraction OCR non persistée).

Doublons (`FicheEtudiantService`) : même email OU téléphone OU
(nom+prénom+naissance), limite 5, jamais de fusion auto.
