# Audit population (Phase 2 — sans code)

## Modèle et identité

`Etudiant` (fillable) : nom/prénom, date_naissance, sexe, cin, email,
téléphone, adresse/ville/code_postal, faculte/groupe/programme/niveau,
annee_academique, annee_bac/mention_bac. Canonique : `id` interne ;
fonctionnel : `num_inscription` (INS-YYYY-00001) ; national : `cin` ;
technique : aucun UUID (pas de donnée technique érigée en identité ✓).

## Écarts prouvés

1. `cin` NOT NULL → étrangers sans CIN impossibles. À CORRIGER (migration
   nullable + validation conditionnelle + requêtes `whereNotNull`).
2. Champs arabe/lieu/pays/nationalité/contact-urgence : absents partout
   sauf extraction OCR non persistée. À NE PAS CRÉER (minimisation, aucun
   besoin métier prouvé).
3. `matricule` : UNIQUE sur `archives_etudiants`, pas sur `etudiants`
   (num_inscription l'est). Pas de doublon, nommage à documenter.
4. Situations : TYPES d'événements (7), pas statuts individuels — sémantique
   à documenter, rien à créer.
5. Permissions `students.*` : inexistantes, `archives.*` couvrent la même
   finalité → NE PAS CRÉER.
6. Import : doublons CIN/email + race guardés ; `matricule` N/A (pas de
   colonne) ; chunking générique (DataImportService 500). Suffisant.
7. API : GET seuls (5 endpoints) ; POST/PATCH/DELETE absents — À NE PAS
   CRÉER (surface d'attaque, besoin non prouvé).
8. Notifs `inscription.*` : seul `inscription.progression` existe.
   MANQUANT : créée/validée/refusée/réinscrite → À AJOUTER (générique, sans PII).
9. Pyramides sexe/nationalité/âge : sans colonnes sources → NE PAS FAIRE.

## À réutiliser tel quel

Scope, recherche (registre+listes+globale), dashboard
(`PopulationStatsService` 12 métriques), doublons (jamais fusion auto),
audit (3 codes), exports, StudentDossier/Archive/GED.
