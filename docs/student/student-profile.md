# Profil étudiant (Phase 8)

Identité canonique : `id` interne, `num_inscription` fonctionnel
(UNIQUE), `cin` national nullable (étrangers), `email` login (UNIQUE).
Minimisation : nom/prénom, naissance, sexe, contacts (email/tél/
adresse) — arabe/lieu/pays/nationalité/urgence NON persistés (OCR non
conservé). Complétude 6 items (`FicheEtudiantService::completude`),
doublons email/tél/nom+prénom+naissance (jamais fusion auto).
