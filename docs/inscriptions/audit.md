# Audit inscriptions (Phase 3 — sans code)

## Structure

`inscriptions_academiques` : étudiant + année (UQ) + programme/niveau
(FK Phase 2) + niveau/filiere/groupe legacy + statut
(`en_cours→valide/ajourne/abandon`, `InscriptionService::TRANSITIONS`) +
type (`premiere/reinscription/transfert/…`) + regime + moyenne/crédits/
décision dénormalisés. Faculté dérivée (`etudiant.faculte_id`) — pas de
`faculte_id` propre (cohérent, policies via étudiant).

## Écarts prouvés

1. Transfert : `programme_id` changé (même autre faculté) MAIS
   `etudiant.faculte_id` JAMAIS modifié → incohérence périmètre.
   À CORRIGER (aligner faculté + situation, historique conservé).
2. Première inscription (`ArchiveService::creerInscription`) : pas de
   contrôle programme/niveau actif, pas de type/regime/FK dans la Request
   (divergence fillable). À RENFORCER (gardes métier, harmoniser Request).
3. Colonnes `type/regime/programme_id/niveau_id` : présentes via migration
   additive `210249` (vérifié). Reste : harmoniser la Request
   (`StoreInscriptionAcademiqueRequest` ne les valide pas).
4. `reinscrire()` : garde anti-doublon au contrôleur, pas interne ; pas de
   `lockForUpdate` (course → SQL brute). À DURCIR (garde interne + 422 propre).
5. API `/api/v1/registrations` : inexistante. À CRÉER en GET seul
   (index/show, `archives:read`, resources existantes).
6. Attestation/récépissé d'inscription : inexistants (seule
   `ATTESTATION_SCOLARITE` via demandes). À ÉVALUER (même moteur PDF).
7. Dashboard : métriques inscription manquantes (aujourd'hui/attente/
   formation). À COMPLÉTER (léger, depuis le réel).
8. Recherche : pas d'index inscriptions ; filtres programme/statut
   inscription manquants. À COMPLÉTER (léger).
9. Notifs : `validee/ajournee/reinscrite/transferee` manquantes (cf. Phase 2).
10. Statuts `draft/pending/active` : À NE PAS CRÉER (workflow existant conservé).

## Séparation acad/péda

`inscrireEcue` : maquette ACTIVE exigée, `firstOrCreate` idempotent — OK
pour Phase 4. Manque : contrôle statut inscription (à ajouter : `en_cours`
seule pour nouvelle ECUE ? — à valider métier, défaut : afficher mais
documenter).
