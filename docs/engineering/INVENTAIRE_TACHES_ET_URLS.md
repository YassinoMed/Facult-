# Inventaire des tâches et URLs — UniRegistre

Inventaire généré à partir de `php artisan route:list` (lecture seule, aucune modification).
Nombre total de routes recensées : 952

## Convention
- **Tâche** : libellé fonctionnel déduit de l’URI et de la méthode HTTP.
- **URL** : chemin à appeler (préfixer par l’URL racine de l’application).
- **Méthode** : verbe(s) HTTP autorisé(s). HEAD est omis quand GET est présent.
- **Nom de route** : identifiant Laravel utilisé pour générer l’URL (`route('nom')`).
- **Action** : contrôleur et méthode appelés (nom complet, non tronqué).

## Sommaire par domaine

- [1. Back-office Admin](#1.-back-office-admin) — 711 routes
- [2. Espace Enseignant](#2.-espace-enseignant) — 67 routes
- [3. Espace Étudiant](#3.-espace-Étudiant) — 80 routes
- [4. API REST (api/v1)](#4.-api-rest-(api/v1)) — 53 routes
- [5. Inscriptions Publiques](#5.-inscriptions-publiques) — 25 routes
- [6. Livewire (ressources assets)](#6.-livewire-(ressources-assets)) — 1 routes
- [7. Sanctum (CSRF)](#7.-sanctum-(csrf)) — 1 routes
- [8. Santé applicative](#8.-santé-applicative) — 1 routes
- [10. Vérification de documents](#10.-vérification-de-documents) — 1 routes
- [11. Hors-ligne](#11.-hors-ligne) — 1 routes
- [12. Accueil / Racine](#12.-accueil-/-racine) — 1 routes
- [Livewire-ed63f1ff](#livewire-ed63f1ff) — 9 routes
- [Verifier](#verifier) — 1 routes

---

## 1. Back-office Admin

| Méthode | URL | Tâche | Nom de route | Contrôleur / Action |
|---------|-----|-------|----------------|-------------------|
| GET | `admin` |  | `admin.dashboard` | `App\Http\Controllers\Admin\TableauDeBordController@index` |
| GET | `admin/administrateurs` | Administrateurs | `admin.administrateurs.index` | `App\Http\Controllers\Admin\AdministrateurController@index` |
| POST | `admin/administrateurs` | Créer / Administrateurs | `admin.administrateurs.store` | `App\Http\Controllers\Admin\AdministrateurController@store` |
| GET | `admin/administrateurs/create` | Create | `admin.administrateurs.create` | `App\Http\Controllers\Admin\AdministrateurController@create` |
| GET | `admin/administrateurs/recherche` | Recherche | `admin.administrateurs.recherche` | `App\Http\Controllers\Admin\AdministrateurController@rechercher` |
| PUT, PATCH | `admin/administrateurs/{administrateur}` | Modifier / {administrateur} | `admin.administrateurs.update` | `App\Http\Controllers\Admin\AdministrateurController@update` |
| DELETE | `admin/administrateurs/{administrateur}` | Supprimer / {administrateur} | `admin.administrateurs.destroy` | `App\Http\Controllers\Admin\AdministrateurController@destroy` |
| GET | `admin/administrateurs/{administrateur}/modifier` | Modifier | `admin.administrateurs.edit` | `App\Http\Controllers\Admin\AdministrateurController@edit` |
| POST | `admin/administrateurs/{administrateur}/reinitialiser` | Créer / Reinitialiser | `admin.administrateurs.reinitialiser` | `App\Http\Controllers\Admin\AdministrateurController@reinitialiser` |
| PATCH | `admin/administrateurs/{administrateur}/role` | Modifier / Role | `admin.administrateurs.role.attribuer` | `App\Http\Controllers\Admin\AdministrateurController@attribuerRole` |
| POST | `admin/administrateurs/{administrateur}/sessions/purger` | Créer / Purger | `admin.administrateurs.sessions.purger` | `App\Http\Controllers\Admin\AdministrateurController@purgerSessions` |
| PATCH | `admin/administrateurs/{administrateur}/statut` | Modifier / Statut | `admin.administrateurs.statut` | `App\Http\Controllers\Admin\AdministrateurController@toggleStatut` |
| GET | `admin/admissions` | Admissions | `admin.admissions.index` | `App\Http\Controllers\Admin\AdmissionController@index` |
| POST | `admin/admissions` | Créer / Admissions | `admin.admissions.store` | `App\Http\Controllers\Admin\AdmissionController@store` |
| GET | `admin/admissions/creer` | Creer | `admin.admissions.create` | `App\Http\Controllers\Admin\AdmissionController@create` |
| DELETE | `admin/admissions/{admission}` | Supprimer / {admission} | `admin.admissions.destroy` | `App\Http\Controllers\Admin\AdmissionController@destroy` |
| DELETE | `admin/affectations-enseignement/{affectation}` | Supprimer / {affectation} | `admin.affectations-enseignement.destroy` | `App\Modules\Enseignement\Controllers\EnseignementController@desaffecter` |
| POST | `admin/affectations/appliquer` | Créer / Appliquer | `admin.affectations.appliquer` | `App\Http\Controllers\Admin\AffectationController@appliquer` |
| GET | `admin/affectations/auto` | Auto | `admin.affectations.auto` | `App\Http\Controllers\Admin\AffectationController@auto` |
| POST | `admin/affectations/auto/appliquer` | Créer / Appliquer | `admin.affectations.auto.appliquer` | `App\Http\Controllers\Admin\AffectationController@appliquerAuto` |
| POST | `admin/affectations/auto/simuler` | Créer / Simuler | `admin.affectations.auto.simuler` | `App\Http\Controllers\Admin\AffectationController@simulerAuto` |
| GET | `admin/affectations/auto/simuler` | Simuler | `admin.affectations.auto.simuler.rediriger` | `App\Http\Controllers\Admin\AffectationController@simulerRediriger` |
| GET | `admin/affectations/automatique` | Automatique | `admin.affectations.automatique.parametres` | `App\Http\Controllers\Admin\AutomaticAssignmentController@parametres` |
| PATCH | `admin/affectations/automatique/items/{item}` | Modifier / {item} | `admin.affectations.automatique.modifier` | `App\Http\Controllers\Admin\AutomaticAssignmentController@modifier` |
| POST | `admin/affectations/automatique/simuler` | Créer / Simuler | `admin.affectations.automatique.simuler` | `App\Http\Controllers\Admin\AutomaticAssignmentController@simuler` |
| GET | `admin/affectations/automatique/{simulation}` | {simulation} | `admin.affectations.automatique.show` | `App\Http\Controllers\Admin\AutomaticAssignmentController@show` |
| POST | `admin/affectations/automatique/{simulation}/annuler` | Créer / Annuler | `admin.affectations.automatique.annuler` | `App\Http\Controllers\Admin\AutomaticAssignmentController@annuler` |
| GET | `admin/affectations/automatique/{simulation}/exporter` | Exporter | `admin.affectations.automatique.exporter` | `App\Http\Controllers\Admin\AutomaticAssignmentController@exporter` |
| POST | `admin/affectations/automatique/{simulation}/groupes/{groupe}/confirmer` | Créer / Confirmer | `admin.affectations.automatique.confirmer` | `App\Http\Controllers\Admin\AutomaticAssignmentController@confirmerGroupe` |
| POST | `admin/affectations/automatique/{simulation}/regenerer` | Créer / Regenerer | `admin.affectations.automatique.regenerer` | `App\Http\Controllers\Admin\AutomaticAssignmentController@regenerer` |
| GET | `admin/affectations/masse` | Masse | `admin.affectations.masse` | `App\Http\Controllers\Admin\AffectationController@masse` |
| POST | `admin/affectations/masse/analyser` | Créer / Analyser | `admin.affectations.masse.analyser` | `App\Http\Controllers\Admin\AffectationController@analyserMasse` |
| GET | `admin/affectations/masse/analyser` | Analyser | `admin.affectations.masse.analyser.rediriger` | `App\Http\Controllers\Admin\AffectationController@simulerRediriger` |
| POST | `admin/affectations/masse/confirmer` | Créer / Confirmer | `admin.affectations.masse.confirmer` | `App\Http\Controllers\Admin\AffectationController@confirmerMasse` |
| POST | `admin/affectations/masse/exporter` | Créer / Exporter | `admin.affectations.masse.exporter` | `App\Http\Controllers\Admin\AffectationController@exporterMasse` |
| GET | `admin/affectations/masse/lots/{lot}` | {lot} | `admin.affectations.masse.lots.show` | `App\Http\Controllers\Admin\AffectationController@showLot` |
| POST | `admin/affectations/masse/lots/{lot}/annuler` | Créer / Annuler | `admin.affectations.masse.lots.annuler` | `App\Http\Controllers\Admin\AffectationController@annulerLot` |
| POST | `admin/affectations/masse/proposer` | Créer / Proposer | `admin.affectations.masse.proposer` | `App\Http\Controllers\Admin\AffectationController@proposerMasse` |
| GET | `admin/affectations/masse/recherche` | Recherche | `admin.affectations.masse.recherche` | `App\Http\Controllers\Admin\AffectationController@rechercherCandidats` |
| POST | `admin/affectations/masse/regulariser-inscriptions` | Créer / Regulariser inscriptions | `admin.affectations.masse.regulariser-inscriptions` | `App\Http\Controllers\Admin\AffectationController@regulariserInscriptionsMasse` |
| GET | `admin/affectations/propositions` | Propositions | `admin.affectations.propositions.index` | `App\Http\Controllers\Admin\AffectationProposalController@index` |
| POST | `admin/affectations/propositions/bulk-accepter` | Créer / Bulk accepter | `admin.affectations.propositions.bulk-accepter` | `App\Http\Controllers\Admin\AffectationProposalController@bulkAccepter` |
| POST | `admin/affectations/propositions/{proposal}/accepter` | Créer / Accepter | `admin.affectations.propositions.accepter` | `App\Http\Controllers\Admin\AffectationProposalController@accepter` |
| POST | `admin/affectations/propositions/{proposal}/modifier` | Créer / Modifier | `admin.affectations.propositions.modifier` | `App\Http\Controllers\Admin\AffectationProposalController@modifier` |
| POST | `admin/affectations/propositions/{proposal}/rejeter` | Créer / Rejeter | `admin.affectations.propositions.rejeter` | `App\Http\Controllers\Admin\AffectationProposalController@rejeter` |
| POST | `admin/affectations/simuler` | Créer / Simuler | `admin.affectations.simuler` | `App\Http\Controllers\Admin\AffectationController@simuler` |
| GET | `admin/affectations/simuler` | Simuler | `admin.affectations.simuler.rediriger` | `App\Http\Controllers\Admin\AffectationController@simulerRediriger` |
| GET | `admin/ai` | Ai | `admin.ai.index` | `App\Modules\AiAssistant\Controllers\AdminAiAssistantController@index` |
| GET | `admin/ai/conversations` | Conversations | `admin.ai.conversations.index` | `App\Modules\AiAssistant\Controllers\AdminAiAssistantController@conversations` |
| POST | `admin/ai/conversations` | Créer / Conversations | `admin.ai.conversations.store` | `App\Modules\AiAssistant\Controllers\AdminAiAssistantController@creerConversation` |
| GET | `admin/ai/conversations/{conversation}` | {conversation} | `admin.ai.conversations.messages` | `App\Modules\AiAssistant\Controllers\AdminAiAssistantController@messages` |
| DELETE | `admin/ai/conversations/{conversation}` | Supprimer / {conversation} | `admin.ai.conversations.destroy` | `App\Modules\AiAssistant\Controllers\AdminAiAssistantController@detruire` |
| POST | `admin/ai/conversations/{conversation}/messages` | Créer / Messages | `admin.ai.conversations.messages.store` | `App\Modules\AiAssistant\Controllers\AdminAiAssistantController@envoyerMessage` |
| GET | `admin/annonces` | Annonces | `admin.annonces.index` | `App\Modules\Announcements\Controllers\AnnonceController@index` |
| POST | `admin/annonces` | Créer / Annonces | `admin.annonces.store` | `App\Modules\Announcements\Controllers\AnnonceController@store` |
| GET | `admin/annonces/creer` | Creer | `admin.annonces.create` | `App\Modules\Announcements\Controllers\AnnonceController@create` |
| DELETE | `admin/annonces/{annonce}` | Supprimer / {annonce} | `admin.annonces.destroy` | `App\Modules\Announcements\Controllers\AnnonceController@destroy` |
| POST | `admin/annonces/{annonce}/expirer` | Créer / Expirer | `admin.annonces.expirer` | `App\Modules\Announcements\Controllers\AnnonceController@expirer` |
| POST | `admin/annonces/{annonce}/publier` | Créer / Publier | `admin.annonces.publier` | `App\Modules\Announcements\Controllers\AnnonceController@publier` |
| GET | `admin/appels` | Appels | `admin.appels.index` | `App\Http\Controllers\Admin\AppelsController@index` |
| GET | `admin/appels/{appel}` | {appel} | `admin.appels.show` | `App\Http\Controllers\Admin\AppelsController@show` |
| GET | `admin/archives` | Archives | `admin.archives.index` | `App\Http\Controllers\Admin\ArchiveController@index` |
| POST | `admin/archives` | Créer / Archives | `admin.archives.store` | `App\Http\Controllers\Admin\ArchiveController@store` |
| POST | `admin/archives/annees-universitaires` | Créer / Annees universitaires | `admin.archives.annees.store` | `App\Http\Controllers\Admin\ConfigurationAcademiqueController@storeAnnee` |
| PATCH | `admin/archives/annees-universitaires/{anneeUniversitaire}` | Modifier / {anneeUniversitaire} | `admin.archives.annees.update` | `App\Http\Controllers\Admin\ConfigurationAcademiqueController@updateAnnee` |
| DELETE | `admin/archives/annees-universitaires/{anneeUniversitaire}` | Supprimer / {anneeUniversitaire} | `admin.archives.annees.destroy` | `App\Http\Controllers\Admin\ConfigurationAcademiqueController@destroyAnnee` |
| GET | `admin/archives/configuration` | Configuration | `admin.archives.configuration.index` | `App\Http\Controllers\Admin\ConfigurationAcademiqueController@index` |
| GET | `admin/archives/create` | Create | `admin.archives.create` | `App\Http\Controllers\Admin\ArchiveController@create` |
| DELETE | `admin/archives/documents/{document}` | Supprimer / {document} | `admin.archives.documents.destroy` | `App\Http\Controllers\Admin\DocumentAcademiqueController@destroy` |
| GET | `admin/archives/documents/{document}/download` | Download | `admin.archives.documents.download` | `App\Http\Controllers\Admin\DocumentAcademiqueController@download` |
| GET | `admin/archives/export-registre` | Export registre | `admin.archives.export-registre` | `App\Http\Controllers\Admin\ArchiveController@exportRegistre` |
| PATCH | `admin/archives/inscriptions/{inscription}` | Modifier / {inscription} | `admin.archives.inscriptions.update` | `App\Http\Controllers\Admin\InscriptionAcademiqueController@update` |
| DELETE | `admin/archives/inscriptions/{inscription}` | Supprimer / {inscription} | `admin.archives.inscriptions.destroy` | `App\Http\Controllers\Admin\InscriptionAcademiqueController@destroy` |
| POST | `admin/archives/inscriptions/{inscription}/notes` | Créer / Notes | `admin.archives.notes.store` | `App\Http\Controllers\Admin\NoteController@store` |
| POST | `admin/archives/matieres` | Créer / Matieres | `admin.archives.matieres.store` | `App\Http\Controllers\Admin\ConfigurationAcademiqueController@storeMatiere` |
| PATCH | `admin/archives/matieres/{matiere}` | Modifier / {matiere} | `admin.archives.matieres.update` | `App\Http\Controllers\Admin\ConfigurationAcademiqueController@updateMatiere` |
| DELETE | `admin/archives/matieres/{matiere}` | Supprimer / {matiere} | `admin.archives.matieres.destroy` | `App\Http\Controllers\Admin\ConfigurationAcademiqueController@destroyMatiere` |
| PATCH | `admin/archives/notes/{note}` | Modifier / {note} | `admin.archives.notes.update` | `App\Http\Controllers\Admin\NoteController@update` |
| DELETE | `admin/archives/notes/{note}` | Supprimer / {note} | `admin.archives.notes.destroy` | `App\Http\Controllers\Admin\NoteController@destroy` |
| PATCH | `admin/archives/notes/{note}/verrouiller` | Modifier / Verrouiller | `admin.archives.notes.lock` | `App\Http\Controllers\Admin\NoteController@toggleLock` |
| GET | `admin/archives/{dossier}` | {dossier} | `admin.archives.show` | `App\Http\Controllers\Admin\ArchiveController@show` |
| PATCH | `admin/archives/{dossier}` | Modifier / {dossier} | `admin.archives.update` | `App\Http\Controllers\Admin\ArchiveController@update` |
| DELETE | `admin/archives/{dossier}` | Supprimer / {dossier} | `admin.archives.destroy` | `App\Http\Controllers\Admin\ArchiveController@destroy` |
| POST | `admin/archives/{dossier}/documents` | Créer / Documents | `admin.archives.documents.store` | `App\Http\Controllers\Admin\DocumentAcademiqueController@store` |
| GET | `admin/archives/{dossier}/edit` | Edit | `admin.archives.edit` | `App\Http\Controllers\Admin\ArchiveController@edit` |
| GET | `admin/archives/{dossier}/export` | Export | `admin.archives.export` | `App\Http\Controllers\Admin\ArchiveController@export` |
| POST | `admin/archives/{dossier}/inscriptions` | Créer / Inscriptions | `admin.archives.inscriptions.store` | `App\Http\Controllers\Admin\InscriptionAcademiqueController@store` |
| PATCH | `admin/archives/{dossier}/statut` | Modifier / Statut | `admin.archives.statut` | `App\Http\Controllers\Admin\ArchiveController@toggleStatut` |
| GET | `admin/assistant-ia` | Assistant ia | `admin.assistant-ia.index` | `App\Modules\AssistantIa\Controllers\AssistantIaController@index` |
| POST | `admin/assistant-ia/actions/{id}/approuver` | Créer / Approuver | `admin.assistant-ia.actions.approuver` | `App\Modules\AssistantIa\Controllers\AssistantIaController@approuverAction` |
| POST | `admin/assistant-ia/actions/{id}/rejeter` | Créer / Rejeter | `admin.assistant-ia.actions.rejeter` | `App\Modules\AssistantIa\Controllers\AssistantIaController@rejeterAction` |
| POST | `admin/assistant-ia/consentement` | Créer / Consentement | `admin.assistant-ia.consentement.generer` | `App\Modules\AssistantIa\Controllers\AssistantIaController@genererConsentement` |
| GET | `admin/assistant-ia/conversations` | Conversations | `admin.assistant-ia.conversations.index` | `App\Modules\AssistantIa\Controllers\AssistantIaController@index` |
| POST | `admin/assistant-ia/conversations` | Créer / Conversations | `admin.assistant-ia.conversations.creer` | `App\Modules\AssistantIa\Controllers\AssistantIaController@creerConversation` |
| GET | `admin/assistant-ia/conversations/{uuid}` | {uuid} | `admin.assistant-ia.conversations.show` | `App\Modules\AssistantIa\Controllers\AssistantIaController@afficherConversation` |
| DELETE | `admin/assistant-ia/conversations/{uuid}` | Supprimer / {uuid} | `admin.assistant-ia.conversations.supprimer` | `App\Modules\AssistantIa\Controllers\AssistantIaController@supprimerConversation` |
| POST | `admin/assistant-ia/conversations/{uuid}/messages` | Créer / Messages | `admin.assistant-ia.messages.envoyer` | `App\Modules\AssistantIa\Controllers\AssistantIaController@envoyerMessage` |
| GET | `admin/assistant-ia/sources` | Sources | `admin.assistant-ia.sources.index` | `App\Modules\AssistantIa\Controllers\AssistantIaController@sources` |
| POST | `admin/assistant-ia/sources/{id}/basculer` | Créer / Basculer | `admin.assistant-ia.sources.basculer` | `App\Modules\AssistantIa\Controllers\AssistantIaController@basculerStatutSource` |
| POST | `admin/assistant-ia/sources/{id}/synchroniser` | Créer / Synchroniser | `admin.assistant-ia.sources.synchroniser` | `App\Modules\AssistantIa\Controllers\AssistantIaController@synchroniserSource` |
| GET | `admin/assistant-ia/statut-fournisseurs` | Statut fournisseurs | `admin.assistant-ia.fournisseurs.statut` | `App\Modules\AssistantIa\Controllers\AssistantIaController@statutFournisseurs` |
| GET | `admin/attestations` | Attestations | `admin.attestations.index` | `App\Http\Controllers\Admin\AttestationController@index` |
| POST | `admin/attestations/generer` | Créer / Generer | `admin.attestations.generer` | `App\Http\Controllers\Admin\AttestationController@generer` |
| GET | `admin/audit` | Audit | `admin.audit.index` | `App\Http\Controllers\Admin\AuditController@index` |
| GET | `admin/audit/export` | Export | `admin.audit.export` | `App\Http\Controllers\Admin\AuditController@exporter` |
| GET | `admin/bi` | Bi | `admin.bi.index` | `App\Http\Controllers\Admin\BiController@index` |
| GET | `admin/bi/export` | Export | `admin.bi.export` | `App\Http\Controllers\Admin\BiController@exporter` |
| GET | `admin/calendrier` | Calendrier | `admin.calendrier.index` | `App\Modules\Calendar\Controllers\CalendrierController@index` |
| POST | `admin/calendrier` | Créer / Calendrier | `admin.calendrier.store` | `App\Modules\Calendar\Controllers\CalendrierController@store` |
| DELETE | `admin/calendrier/{evenement}` | Supprimer / {evenement} | `admin.calendrier.destroy` | `App\Modules\Calendar\Controllers\CalendrierController@destroy` |
| GET | `admin/campagnes` | Campagnes | `admin.campagnes.index` | `App\Modules\Announcements\Controllers\CampagneController@index` |
| POST | `admin/campagnes` | Créer / Campagnes | `admin.campagnes.store` | `App\Modules\Announcements\Controllers\CampagneController@store` |
| POST | `admin/campagnes/{campagne}/envoyer` | Créer / Envoyer | `admin.campagnes.envoyer` | `App\Modules\Announcements\Controllers\CampagneController@envoyer` |
| POST | `admin/campagnes/{campagne}/planifier` | Créer / Planifier | `admin.campagnes.planifier` | `App\Modules\Announcements\Controllers\CampagneController@planifier` |
| GET | `admin/centre-travail` | Centre travail | `admin.centre-travail.index` | `App\Http\Controllers\Admin\CentreTravailController@index` |
| POST | `admin/centre-travail/actions-groupees` | Créer / Actions groupees | `admin.centre-travail.actions-groupees` | `App\Http\Controllers\Admin\CentreTravailController@actionsGroupees` |
| GET | `admin/centre-travail/{inscription}` | {inscription} | `admin.centre-travail.show` | `App\Http\Controllers\Admin\CentreTravailController@show` |
| POST | `admin/centre-travail/{inscription}/corrections/{correction}/decider` | Créer / Decider | `admin.centre-travail.corrections.decider` | `App\Http\Controllers\Admin\CentreTravailController@deciderCorrection` |
| POST | `admin/centre-travail/{inscription}/rejeter` | Créer / Rejeter | `admin.centre-travail.rejeter` | `App\Http\Controllers\Admin\CentreTravailController@rejeterInscription` |
| POST | `admin/centre-travail/{inscription}/valider` | Créer / Valider | `admin.centre-travail.valider` | `App\Http\Controllers\Admin\CentreTravailController@validerInscription` |
| GET | `admin/cockpit` | Cockpit | `admin.cockpit.index` | `App\Http\Controllers\Admin\CockpitController@index` |
| GET | `admin/connexion` | Connexion | `admin.connexion.show` | `App\Http\Controllers\Admin\AuthController@show` |
| POST | `admin/connexion` | Créer / Connexion | `admin.connexion.login` | `App\Http\Controllers\Admin\AuthController@login` |
| GET | `admin/connexion/defi` | Defi | `admin.connexion.defi` | `App\Http\Controllers\Admin\AuthController@defi` |
| POST | `admin/connexion/defi` | Créer / Defi | `admin.connexion.defi.verifier` | `App\Http\Controllers\Admin\AuthController@verifierDefi` |
| GET | `admin/courriels` | Courriels | `admin.courriels.index` | `App\Http\Controllers\Admin\CourrielController@index` |
| POST | `admin/courriels` | Créer / Courriels | `admin.courriels.envoyer` | `App\Http\Controllers\Admin\CourrielController@envoyer` |
| GET | `admin/courriels/campagnes` | Campagnes | `admin.campagnes-courriel.index` | `App\Livewire\Admin\Campagnes\TableauDeBordCampagnes` |
| GET | `admin/courriels/campagnes/composer/{campagne?}` | {campagne?} | `admin.campagnes-courriel.composer` | `App\Livewire\Admin\Campagnes\AssistantCompositionCampagne` |
| GET | `admin/courriels/campagnes/groupes` | Groupes | `admin.campagnes-courriel.groupes` | `App\Livewire\Admin\Campagnes\GestionGroupesDestinataires` |
| GET | `admin/courriels/campagnes/{campagne}` | {campagne} | `admin.campagnes-courriel.details` | `App\Livewire\Admin\Campagnes\SuiviCampagneCourriel` |
| GET | `admin/courriels/creer` | Creer | `admin.courriels.creer` | `App\Http\Controllers\Admin\CourrielController@creer` |
| GET | `admin/courriels/modeles` | Modeles | `admin.courriels.catalogue` | `App\Http\Controllers\Admin\CourrielController@catalogue` |
| GET | `admin/courriels/{courriel}` | {courriel} | `admin.courriels.voir` | `App\Http\Controllers\Admin\CourrielController@voir` |
| POST | `admin/courriels/{courriel}/reessayer` | Créer / Reessayer | `admin.courriels.reessayer` | `App\Http\Controllers\Admin\CourrielController@reessayer` |
| GET | `admin/data-mesh` | Data mesh | `admin.datamesh.index` | `App\Http\Controllers\Admin\DataMeshController@index` |
| GET | `admin/data-mesh/apercu` | Apercu | `admin.datamesh.apercu` | `App\Http\Controllers\Admin\DataMeshController@apercu` |
| GET | `admin/data-mesh/flux` | Flux | `admin.datamesh.flux` | `App\Http\Controllers\Admin\DataMeshController@flux` |
| GET | `admin/data-mesh/graphe` | Graphe | `admin.datamesh.graphe` | `App\Http\Controllers\Admin\DataMeshController@graphe` |
| GET | `admin/data-mesh/noeud/{id}` | {id} | `admin.datamesh.noeud` | `App\Http\Controllers\Admin\DataMeshController@noeud` |
| GET | `admin/data-mesh/outbox` | Outbox | `admin.datamesh.outbox` | `App\Http\Controllers\Admin\DataMeshController@outbox` |
| GET | `admin/data-mesh/qualite` | Qualite | `admin.datamesh.qualite` | `App\Http\Controllers\Admin\DataMeshController@qualite` |
| POST | `admin/deconnexion` | Créer / Deconnexion | `admin.connexion.logout` | `App\Http\Controllers\Admin\AuthController@logout` |
| GET | `admin/demandes` | Demandes | `admin.demandes.index` | `App\Modules\OnlineRequests\Controllers\OnlineRequestController@index` |
| GET | `admin/demandes-types` | Demandes types | `admin.demandes.types.index` | `App\Modules\OnlineRequests\Controllers\RequestTypeController@index` |
| POST | `admin/demandes-types` | Créer / Demandes types | `admin.demandes.types.store` | `App\Modules\OnlineRequests\Controllers\RequestTypeController@store` |
| PUT, PATCH | `admin/demandes-types/{type}` | Modifier / {type} | `admin.demandes.types.update` | `App\Modules\OnlineRequests\Controllers\RequestTypeController@update` |
| DELETE | `admin/demandes-types/{type}` | Supprimer / {type} | `admin.demandes.types.destroy` | `App\Modules\OnlineRequests\Controllers\RequestTypeController@destroy` |
| GET | `admin/demandes/{demande}` | {demande} | `admin.demandes.show` | `App\Modules\OnlineRequests\Controllers\OnlineRequestController@show` |
| PATCH | `admin/demandes/{demande}/approuver` | Modifier / Approuver | `admin.demandes.approuver` | `App\Modules\OnlineRequests\Controllers\OnlineRequestController@approuver` |
| PATCH | `admin/demandes/{demande}/assigner` | Modifier / Assigner | `admin.demandes.assigner` | `App\Modules\OnlineRequests\Controllers\OnlineRequestController@assigner` |
| PATCH | `admin/demandes/{demande}/demander-piece` | Modifier / Demander piece | `admin.demandes.demander-piece` | `App\Modules\OnlineRequests\Controllers\OnlineRequestController@demanderPiece` |
| GET | `admin/demandes/{demande}/document/download` | Download | `admin.demandes.document.download` | `App\Modules\OnlineRequests\Controllers\OnlineRequestController@downloadDocument` |
| PATCH | `admin/demandes/{demande}/examiner` | Modifier / Examiner | `admin.demandes.examiner` | `App\Modules\OnlineRequests\Controllers\OnlineRequestController@examiner` |
| POST | `admin/demandes/{demande}/messages` | Créer / Messages | `admin.demandes.messages.store` | `App\Modules\OnlineRequests\Controllers\OnlineRequestController@commenter` |
| GET | `admin/demandes/{demande}/pieces/{piece}/download` | Download | `admin.demandes.pieces.download` | `App\Modules\OnlineRequests\Controllers\OnlineRequestController@downloadPiece` |
| PATCH | `admin/demandes/{demande}/recevoir` | Modifier / Recevoir | `admin.demandes.recevoir` | `App\Modules\OnlineRequests\Controllers\OnlineRequestController@recevoir` |
| PATCH | `admin/demandes/{demande}/rejeter` | Modifier / Rejeter | `admin.demandes.rejeter` | `App\Modules\OnlineRequests\Controllers\OnlineRequestController@rejeter` |
| PATCH | `admin/demandes/{demande}/terminer` | Modifier / Terminer | `admin.demandes.terminer` | `App\Modules\OnlineRequests\Controllers\OnlineRequestController@terminer` |
| GET | `admin/documents` | Documents | `admin.ged.index` | `App\Http\Controllers\Admin\GedController@index` |
| GET | `admin/documents-center` | Documents center | `admin.documents-center.index` | `App\Http\Controllers\Admin\DocumentCenterController@index` |
| POST | `admin/documents-center/lots` | Créer / Lots | `admin.documents-center.lots.lancer` | `App\Http\Controllers\Admin\DocumentCenterController@lancerLot` |
| POST | `admin/documents-center/lots/{lot}/annuler` | Créer / Annuler | `admin.documents-center.lots.annuler` | `App\Http\Controllers\Admin\DocumentCenterController@annulerLot` |
| GET | `admin/documents-center/{document}` | {document} | `admin.documents-center.show` | `App\Http\Controllers\Admin\DocumentCenterController@show` |
| POST | `admin/documents-center/{document}/approuver` | Créer / Approuver | `admin.documents-center.approuver` | `App\Http\Controllers\Admin\DocumentCenterController@approuver` |
| POST | `admin/documents-center/{document}/emettre` | Créer / Emettre | `admin.documents-center.emettre` | `App\Http\Controllers\Admin\DocumentCenterController@emettre` |
| POST | `admin/documents-center/{document}/envoyer` | Créer / Envoyer | `admin.documents-center.envoyer` | `App\Http\Controllers\Admin\DocumentCenterController@envoyer` |
| GET | `admin/documents-center/{document}/imprimer` | Imprimer | `admin.documents-center.imprimer` | `App\Http\Controllers\Admin\DocumentCenterController@imprimer` |
| POST | `admin/documents-center/{document}/revoquer` | Créer / Revoquer | `admin.documents-center.revoquer` | `App\Http\Controllers\Admin\DocumentCenterController@revoquer` |
| GET | `admin/documents-center/{document}/telecharger` | Telecharger | `admin.documents-center.telecharger` | `App\Http\Controllers\Admin\DocumentCenterController@telecharger` |
| GET | `admin/documents-officiels/{document}/download` | Download | `admin.documents-officiels.download` | `App\Modules\StudentDossiers\Controllers\OfficialDocumentController@download` |
| POST | `admin/documents-officiels/{document}/revoquer` | Créer / Revoquer | `admin.documents-officiels.revoquer` | `App\Modules\StudentDossiers\Controllers\OfficialDocumentController@revoquer` |
| GET | `admin/donnees` | Donnees | `admin.donnees.hub` | `App\Http\Controllers\Admin\DataExchangeController@hub` |
| GET | `admin/donnees/historique` | Historique | `admin.donnees.historique` | `App\Http\Controllers\Admin\DataExchangeController@historique` |
| GET | `admin/donnees/rapports/{rapport}/erreurs/{format}` | {format} | `admin.donnees.rapport.erreurs` | `App\Http\Controllers\Admin\DataExchangeController@erreurs` |
| GET | `admin/donnees/{entite}/donnees` | Donnees | `admin.donnees.voir` | `App\Http\Controllers\Admin\DataExchangeController@voir` |
| GET | `admin/donnees/{entite}/export/{format}` | {format} | `admin.donnees.export` | `App\Http\Controllers\Admin\DataExchangeController@exporter` |
| GET | `admin/donnees/{entite}/import` | Import | `admin.donnees.import.form` | `App\Http\Controllers\Admin\DataExchangeController@formulaire` |
| POST | `admin/donnees/{entite}/import/annuler` | Créer / Annuler | `admin.donnees.import.annuler` | `App\Http\Controllers\Admin\DataExchangeController@annuler` |
| POST | `admin/donnees/{entite}/import/confirmer` | Créer / Confirmer | `admin.donnees.import.confirmer` | `App\Http\Controllers\Admin\DataExchangeController@confirmer` |
| POST | `admin/donnees/{entite}/import/preview` | Créer / Preview | `admin.donnees.import.preview` | `App\Http\Controllers\Admin\DataExchangeController@preview` |
| POST | `admin/donnees/{entite}/import/revoir` | Créer / Revoir | `admin.donnees.import.revoir` | `App\Http\Controllers\Admin\DataExchangeController@revoir` |
| GET | `admin/donnees/{entite}/modele/{format}` | {format} | `admin.donnees.modele` | `App\Http\Controllers\Admin\DataExchangeController@modele` |
| GET | `admin/double-facteur` | Double facteur | `admin.mfa.configurer` | `App\Http\Controllers\Admin\MfaController@configurer` |
| POST | `admin/double-facteur/codes-secours` | Créer / Codes secours | `admin.mfa.codes-secours` | `App\Http\Controllers\Admin\MfaController@nouveauxCodesSecours` |
| POST | `admin/double-facteur/confirmer` | Créer / Confirmer | `admin.mfa.confirmer` | `App\Http\Controllers\Admin\MfaController@confirmer` |
| POST | `admin/double-facteur/desactiver` | Créer / Desactiver | `admin.mfa.desactiver` | `App\Http\Controllers\Admin\MfaController@desactiver` |
| POST | `admin/double-facteur/nouveau-secret` | Créer / Nouveau secret | `admin.mfa.nouveau-secret` | `App\Http\Controllers\Admin\MfaController@nouveauSecret` |
| GET | `admin/emplois-du-temps` | Emplois du temps | `admin.emplois-du-temps.index` | `App\Modules\Schedule\Controllers\ScheduleController@index` |
| POST | `admin/emplois-du-temps` | Créer / Emplois du temps | `admin.emplois-du-temps.store` | `App\Modules\Schedule\Controllers\ScheduleController@store` |
| GET | `admin/emplois-du-temps/charge` | Charge | `admin.emplois-du-temps.charge` | `App\Modules\Schedule\Controllers\ScheduleExplorerController@charge` |
| GET | `admin/emplois-du-temps/conflits` | Conflits | `admin.emplois-du-temps.conflits` | `App\Modules\Schedule\Controllers\ScheduleExplorerController@conflits` |
| GET | `admin/emplois-du-temps/creer` | Creer | `admin.emplois-du-temps.create` | `App\Modules\Schedule\Controllers\ScheduleController@create` |
| PATCH | `admin/emplois-du-temps/creneaux/{slot}` | Modifier / {slot} | `admin.emplois-du-temps.slots.update` | `App\Modules\Schedule\Controllers\ScheduleController@updateSlot` |
| DELETE | `admin/emplois-du-temps/creneaux/{slot}` | Supprimer / {slot} | `admin.emplois-du-temps.slots.destroy` | `App\Modules\Schedule\Controllers\ScheduleController@destroySlot` |
| POST | `admin/emplois-du-temps/creneaux/{slot}/annuler` | Créer / Annuler | `admin.emplois-du-temps.slots.cancel` | `App\Modules\Schedule\Controllers\ScheduleController@cancelSlot` |
| PATCH | `admin/emplois-du-temps/creneaux/{slot}/deplacer` | Modifier / Deplacer | `admin.emplois-du-temps.slots.deplacer` | `App\Modules\Schedule\Controllers\ScheduleController@deplacer` |
| POST | `admin/emplois-du-temps/creneaux/{slot}/exceptions` | Créer / Exceptions | `admin.emplois-du-temps.slots.exceptions.store` | `App\Modules\Schedule\Controllers\ScheduleController@storeException` |
| GET | `admin/emplois-du-temps/creneaux/{slot}/modifier` | Modifier | `admin.emplois-du-temps.slots.edit` | `App\Modules\Schedule\Controllers\ScheduleController@editSlot` |
| POST | `admin/emplois-du-temps/creneaux/{slot}/restaurer` | Créer / Restaurer | `admin.emplois-du-temps.slots.restore` | `App\Modules\Schedule\Controllers\ScheduleController@restoreSlot` |
| POST | `admin/emplois-du-temps/creneaux/{slot}/verifier` | Créer / Verifier | `admin.emplois-du-temps.slots.verifier` | `App\Modules\Schedule\Controllers\ScheduleController@verifierDeplacement` |
| GET | `admin/emplois-du-temps/disponibilites` | Disponibilites | `admin.emplois-du-temps.disponibilites` | `App\Modules\Schedule\Controllers\ScheduleExplorerController@disponibilites` |
| GET | `admin/emplois-du-temps/explorer` | Explorer | `admin.emplois-du-temps.explorer` | `App\Modules\Schedule\Controllers\ScheduleExplorerController@explorer` |
| GET | `admin/emplois-du-temps/import` | Import | `admin.emplois-du-temps.import.form` | `App\Modules\Schedule\Controllers\ScheduleImportController@form` |
| POST | `admin/emplois-du-temps/import/annuler` | Créer / Annuler | `admin.emplois-du-temps.import.annuler` | `App\Modules\Schedule\Controllers\ScheduleImportController@annuler` |
| POST | `admin/emplois-du-temps/import/confirmer` | Créer / Confirmer | `admin.emplois-du-temps.import.confirmer` | `App\Modules\Schedule\Controllers\ScheduleImportController@confirmer` |
| POST | `admin/emplois-du-temps/import/preview` | Créer / Preview | `admin.emplois-du-temps.import.preview` | `App\Modules\Schedule\Controllers\ScheduleImportController@preview` |
| GET | `admin/emplois-du-temps/imports/{rapport}` | {rapport} | `admin.emplois-du-temps.imports.show` | `App\Modules\Schedule\Controllers\ScheduleImportController@rapport` |
| GET | `admin/emplois-du-temps/indisponibilites` | Indisponibilites | `admin.emplois-du-temps.indisponibilites.index` | `App\Modules\Schedule\Controllers\ScheduleAvailabilityController@index` |
| POST | `admin/emplois-du-temps/indisponibilites` | Créer / Indisponibilites | `admin.emplois-du-temps.indisponibilites.store` | `App\Modules\Schedule\Controllers\ScheduleAvailabilityController@store` |
| GET | `admin/emplois-du-temps/indisponibilites/creer` | Creer | `admin.emplois-du-temps.indisponibilites.create` | `App\Modules\Schedule\Controllers\ScheduleAvailabilityController@create` |
| PATCH | `admin/emplois-du-temps/indisponibilites/{plage}` | Modifier / {plage} | `admin.emplois-du-temps.indisponibilites.update` | `App\Modules\Schedule\Controllers\ScheduleAvailabilityController@update` |
| DELETE | `admin/emplois-du-temps/indisponibilites/{plage}` | Supprimer / {plage} | `admin.emplois-du-temps.indisponibilites.destroy` | `App\Modules\Schedule\Controllers\ScheduleAvailabilityController@destroy` |
| GET | `admin/emplois-du-temps/indisponibilites/{plage}/modifier` | Modifier | `admin.emplois-du-temps.indisponibilites.edit` | `App\Modules\Schedule\Controllers\ScheduleAvailabilityController@edit` |
| GET | `admin/emplois-du-temps/occupation` | Occupation | `admin.emplois-du-temps.occupation` | `App\Modules\Schedule\Controllers\ScheduleExplorerController@occupation` |
| GET | `admin/emplois-du-temps/trouver-creneau` | Trouver creneau | `admin.emplois-du-temps.trouver-creneau` | `App\Modules\Schedule\Controllers\ScheduleExplorerController@trouverCreneau` |
| GET | `admin/emplois-du-temps/{planning}` | {planning} | `admin.emplois-du-temps.show` | `App\Modules\Schedule\Controllers\ScheduleController@show` |
| POST | `admin/emplois-du-temps/{planning}/creneaux` | Créer / Creneaux | `admin.emplois-du-temps.slots.store` | `App\Modules\Schedule\Controllers\ScheduleController@storeSlot` |
| POST | `admin/emplois-du-temps/{planning}/dupliquer` | Créer / Dupliquer | `admin.emplois-du-temps.dupliquer` | `App\Modules\Schedule\Controllers\ScheduleController@dupliquer` |
| GET | `admin/emplois-du-temps/{planning}/generation` | Generation | `admin.emplois-du-temps.generation.index` | `App\Modules\Schedule\Controllers\ScheduleGenerationController@index` |
| POST | `admin/emplois-du-temps/{planning}/generation` | Créer / Generation | `admin.emplois-du-temps.generation.generer` | `App\Modules\Schedule\Controllers\ScheduleGenerationController@generer` |
| POST | `admin/emplois-du-temps/{planning}/generation/simuler` | Créer / Simuler | `admin.emplois-du-temps.generation.simuler` | `App\Modules\Schedule\Controllers\ScheduleGenerationController@simuler` |
| GET | `admin/emplois-du-temps/{planning}/tableau` | Tableau | `admin.emplois-du-temps.tableau` | `App\Modules\Schedule\Controllers\ScheduleController@tableau` |
| POST | `admin/emplois-du-temps/{planning}/transiter/{cible}` | Créer / {cible} | `admin.emplois-du-temps.transiter` | `App\Modules\Schedule\Controllers\ScheduleController@transiter` |
| GET | `admin/enseignants` | Enseignants | `admin.enseignants.index` | `App\Http\Controllers\Admin\EnseignantController@index` |
| POST | `admin/enseignants` | Créer / Enseignants | `admin.enseignants.store` | `App\Http\Controllers\Admin\EnseignantController@store` |
| GET | `admin/enseignants/creer` | Creer | `admin.enseignants.create` | `App\Http\Controllers\Admin\EnseignantController@create` |
| PUT, PATCH | `admin/enseignants/{enseignant}` | Modifier / {enseignant} | `admin.enseignants.update` | `App\Http\Controllers\Admin\EnseignantController@update` |
| DELETE | `admin/enseignants/{enseignant}` | Supprimer / {enseignant} | `admin.enseignants.destroy` | `App\Http\Controllers\Admin\EnseignantController@destroy` |
| GET | `admin/enseignants/{enseignant}` | {enseignant} | `admin.enseignants.show` | `App\Http\Controllers\Admin\EnseignantController@show` |
| GET | `admin/enseignants/{enseignant}/modifier` | Modifier | `admin.enseignants.edit` | `App\Http\Controllers\Admin\EnseignantController@edit` |
| DELETE | `admin/enseignement-groupes/{lien}` | Supprimer / {lien} | `admin.enseignement-groupes.destroy` | `App\Modules\Enseignement\Controllers\EnseignementController@detacherGroupe` |
| GET | `admin/enseignements` | Enseignements | `admin.enseignements.index` | `App\Modules\Enseignement\Controllers\EnseignementController@index` |
| POST | `admin/enseignements` | Créer / Enseignements | `admin.enseignements.store` | `App\Modules\Enseignement\Controllers\EnseignementController@store` |
| GET | `admin/enseignements/creer` | Creer | `admin.enseignements.create` | `App\Modules\Enseignement\Controllers\EnseignementController@create` |
| DELETE | `admin/enseignements/evaluation/composantes/{composante}` | Supprimer / {composante} | `admin.enseignements.evaluation.composantes.destroy` | `App\Modules\Enseignement\Controllers\EvaluationController@detruireComposante` |
| GET | `admin/enseignements/evaluation/{matiere}` | {matiere} | `admin.enseignements.evaluation.edit` | `App\Modules\Enseignement\Controllers\EvaluationController@edit` |
| PUT | `admin/enseignements/evaluation/{matiere}/composantes` | Modifier / Composantes | `admin.enseignements.evaluation.composantes` | `App\Modules\Enseignement\Controllers\EvaluationController@composantes` |
| PATCH | `admin/enseignements/evaluation/{matiere}/responsable` | Modifier / Responsable | `admin.enseignements.evaluation.responsable` | `App\Modules\Enseignement\Controllers\EvaluationController@responsable` |
| GET | `admin/enseignements/qualite` | Qualite | `admin.enseignements.qualite` | `App\Modules\Enseignement\Controllers\ServiceController@qualite` |
| GET | `admin/enseignements/services` | Services | `admin.enseignements.services` | `App\Modules\Enseignement\Controllers\ServiceController@services` |
| POST | `admin/enseignements/seuils` | Créer / Seuils | `admin.enseignements.seuils.store` | `App\Modules\Enseignement\Controllers\ServiceController@seuilStore` |
| DELETE | `admin/enseignements/seuils/{seuil}` | Supprimer / {seuil} | `admin.enseignements.seuils.destroy` | `App\Modules\Enseignement\Controllers\ServiceController@seuilDestroy` |
| GET | `admin/enseignements/tableau-bord` | Tableau bord | `admin.enseignements.tableau-bord` | `App\Modules\Enseignement\Controllers\EnseignementController@tableauBord` |
| GET | `admin/enseignements/types` | Types | `admin.enseignements.types` | `App\Modules\Enseignement\Controllers\ServiceController@types` |
| POST | `admin/enseignements/types` | Créer / Types | `admin.enseignements.types.store` | `App\Modules\Enseignement\Controllers\ServiceController@typeStore` |
| PUT, PATCH | `admin/enseignements/types/{type}` | Modifier / {type} | `admin.enseignements.types.update` | `App\Modules\Enseignement\Controllers\ServiceController@typeUpdate` |
| DELETE | `admin/enseignements/types/{type}` | Supprimer / {type} | `admin.enseignements.types.destroy` | `App\Modules\Enseignement\Controllers\ServiceController@typeDestroy` |
| GET | `admin/enseignements/{enseignement}` | {enseignement} | `admin.enseignements.show` | `App\Modules\Enseignement\Controllers\EnseignementController@show` |
| PUT, PATCH | `admin/enseignements/{enseignement}` | Modifier / {enseignement} | `admin.enseignements.update` | `App\Modules\Enseignement\Controllers\EnseignementController@update` |
| DELETE | `admin/enseignements/{enseignement}` | Supprimer / {enseignement} | `admin.enseignements.destroy` | `App\Modules\Enseignement\Controllers\EnseignementController@destroy` |
| POST | `admin/enseignements/{enseignement}/affectations` | Créer / Affectations | `admin.enseignements.affectations.store` | `App\Modules\Enseignement\Controllers\EnseignementController@affecter` |
| POST | `admin/enseignements/{enseignement}/groupes` | Créer / Groupes | `admin.enseignements.groupes.store` | `App\Modules\Enseignement\Controllers\EnseignementController@rattacherGroupe` |
| GET | `admin/enseignements/{enseignement}/modifier` | Modifier | `admin.enseignements.edit` | `App\Modules\Enseignement\Controllers\EnseignementController@edit` |
| PATCH | `admin/enseignements/{enseignement}/transition` | Modifier / Transition | `admin.enseignements.transition` | `App\Modules\Enseignement\Controllers\EnseignementController@transition` |
| GET | `admin/etudiants` | Etudiants | `admin.etudiants.index` | `App\Http\Controllers\Admin\EtudiantController@index` |
| POST | `admin/etudiants` | Créer / Etudiants | `admin.etudiants.store` | `App\Http\Controllers\Admin\EtudiantController@store` |
| POST | `admin/etudiants/bulk-statut` | Créer / Bulk statut | `admin.etudiants.bulkStatut` | `App\Http\Controllers\Admin\EtudiantController@bulkStatut` |
| GET | `admin/etudiants/creer` | Creer | `admin.etudiants.creer` | `App\Http\Controllers\Admin\EtudiantController@create` |
| GET | `admin/etudiants/sans-groupe` | Sans groupe | `admin.etudiants.sans-groupe` | `App\Http\Controllers\Admin\EtudiantController@sansGroupe` |
| POST | `admin/etudiants/verification/bulk` | Créer / Bulk | `admin.verification.bulk` | `App\Http\Controllers\Admin\VerificationController@bulk` |
| PATCH | `admin/etudiants/{etudiant}` | Modifier / {etudiant} | `admin.etudiants.update` | `App\Http\Controllers\Admin\EtudiantController@update` |
| GET | `admin/etudiants/{etudiant}` | {etudiant} | `admin.etudiants.show` | `App\Http\Controllers\Admin\EtudiantController@show` |
| DELETE | `admin/etudiants/{etudiant}` | Supprimer / {etudiant} | `admin.etudiants.destroy` | `App\Http\Controllers\Admin\EtudiantController@destroy` |
| POST | `admin/etudiants/{etudiant}/affectations` | Créer / Affectations | `admin.etudiants.affectations.store` | `App\Http\Controllers\Admin\AffectationController@store` |
| POST | `admin/etudiants/{etudiant}/affectations/changer` | Créer / Changer | `admin.etudiants.affectations.changer` | `App\Http\Controllers\Admin\AffectationController@changer` |
| GET | `admin/etudiants/{etudiant}/affectations/previsualiser` | Previsualiser | `admin.etudiants.affectations.previsualiser` | `App\Http\Controllers\Admin\AffectationController@previsualiser` |
| POST | `admin/etudiants/{etudiant}/affectations/retirer` | Créer / Retirer | `admin.etudiants.affectations.retirer` | `App\Http\Controllers\Admin\AffectationController@retirer` |
| GET | `admin/etudiants/{etudiant}/archive` | Archive | `admin.etudiants.archive` | `App\Modules\StudentArchive\Controllers\StudentArchiveController@show` |
| POST | `admin/etudiants/{etudiant}/archive/sensible` | Créer / Sensible | `admin.etudiants.archive.sensible.store` | `App\Modules\StudentArchive\Controllers\SensitiveRecordController@store` |
| PATCH | `admin/etudiants/{etudiant}/archive/sensible/{dossier}` | Modifier / {dossier} | `admin.etudiants.archive.sensible.update` | `App\Modules\StudentArchive\Controllers\SensitiveRecordController@update` |
| GET | `admin/etudiants/{etudiant}/archive/sensible/{dossier}/download` | Download | `admin.etudiants.archive.sensible.download` | `App\Modules\StudentArchive\Controllers\SensitiveRecordController@download` |
| POST | `admin/etudiants/{etudiant}/demande-complement` | Créer / Demande complement | `admin.etudiants.demande-complement` | `App\Http\Controllers\Admin\VerificationController@demanderComplement` |
| POST | `admin/etudiants/{etudiant}/documents-center` | Créer / Documents center | `admin.documents-center.generer` | `App\Http\Controllers\Admin\DocumentCenterController@generer` |
| POST | `admin/etudiants/{etudiant}/documents-officiels` | Créer / Documents officiels | `admin.documents-officiels.generer` | `App\Modules\StudentDossiers\Controllers\OfficialDocumentController@generer` |
| GET | `admin/etudiants/{etudiant}/export-dossier` | Export dossier | `admin.etudiants.exportDossier` | `App\Http\Controllers\Admin\EtudiantController@exportDossier` |
| GET | `admin/etudiants/{etudiant}/modifier` | Modifier | `admin.etudiants.modifier` | `App\Http\Controllers\Admin\EtudiantController@edit` |
| GET | `admin/etudiants/{etudiant}/parcours` | Parcours | `admin.etudiants.parcours` | `App\Http\Controllers\Admin\ParcoursEtudiantController@show` |
| PATCH | `admin/etudiants/{etudiant}/rattachement` | Modifier / Rattachement | `admin.etudiants.rattachement` | `App\Http\Controllers\Admin\SituationController@rattacher` |
| POST | `admin/etudiants/{etudiant}/reinitialiser-mot-de-passe` | Créer / Reinitialiser mot de passe | `admin.etudiants.reinitialiserMotDePasse` | `App\Http\Controllers\Admin\EtudiantController@reinitialiserMotDePasse` |
| POST | `admin/etudiants/{etudiant}/reinscriptions` | Créer / Reinscriptions | `admin.etudiants.reinscriptions.store` | `App\Http\Controllers\Admin\ReinscriptionController@store` |
| GET | `admin/etudiants/{etudiant}/reinscriptions/creer` | Creer | `admin.etudiants.reinscriptions.creer` | `App\Http\Controllers\Admin\ReinscriptionController@creer` |
| PATCH | `admin/etudiants/{etudiant}/statut` | Modifier / Statut | `admin.etudiants.updateStatut` | `App\Http\Controllers\Admin\EtudiantController@updateStatut` |
| POST | `admin/etudiants/{etudiant}/suspendre` | Créer / Suspendre | `admin.etudiants.suspendre` | `App\Http\Controllers\Admin\SituationController@suspendre` |
| POST | `admin/etudiants/{etudiant}/transferer` | Créer / Transferer | `admin.etudiants.transferer` | `App\Http\Controllers\Admin\SituationController@transferer` |
| GET | `admin/etudiants/{etudiant}/verification` | Verification | `admin.verification.show` | `App\Http\Controllers\Admin\VerificationController@show` |
| POST | `admin/etudiants/{etudiant}/verification` | Créer / Verification | `admin.verification.verifier` | `App\Http\Controllers\Admin\VerificationController@verifier` |
| POST | `admin/etudiants/{etudiant}/verification/corriger` | Créer / Corriger | `admin.verification.corriger` | `App\Http\Controllers\Admin\VerificationController@corriger` |
| POST | `admin/etudiants/{etudiant}/verification/demande-complement` | Créer / Demande complement | `admin.verification.demande-complement` | `App\Http\Controllers\Admin\VerificationController@demanderComplement` |
| POST | `admin/etudiants/{etudiant}/verification/proposer` | Créer / Proposer | `admin.verification.proposer` | `App\Http\Controllers\Admin\VerificationController@proposer` |
| GET | `admin/evaluations` | Evaluations | `admin.evaluations.index` | `App\Modules\Evaluation\Controllers\TableauBordEvaluationController@index` |
| GET | `admin/evaluations/affectations` | Affectations | `admin.evaluations.affectations.index` | `App\Modules\Evaluation\Controllers\AffectationEnseignantController@index` |
| GET | `admin/evaluations/affectations-hub` | Affectations hub | `admin.evaluations.affectations` | `App\Modules\Evaluation\Controllers\AffectationEnseignantController@index` |
| POST | `admin/evaluations/affectations/auto` | Créer / Auto | `admin.evaluations.affectations.auto` | `App\Modules\Evaluation\Controllers\AffectationEnseignantController@autoAssigner` |
| GET | `admin/evaluations/affectations/lancer-compte` | Lancer compte | `admin.evaluations.affectations.compte` | `App\Modules\Evaluation\Controllers\AffectationEnseignantController@compteLancement` |
| POST | `admin/evaluations/affectations/optimiser` | Créer / Optimiser | `admin.evaluations.affectations.optimiser` | `App\Modules\Evaluation\Controllers\AffectationEnseignantController@optimiser` |
| GET | `admin/evaluations/affectations/runs` | Runs | `admin.evaluations.affectations.runs` | `App\Modules\Evaluation\Controllers\AffectationEnseignantController@runs` |
| GET | `admin/evaluations/affectations/runs/actif` | Actif | `admin.evaluations.affectations.runs.actif` | `App\Modules\Evaluation\Controllers\AffectationEnseignantController@runActif` |
| POST | `admin/evaluations/affectations/runs/{run}/annuler` | Créer / Annuler | `admin.evaluations.affectations.runs.annuler` | `App\Modules\Evaluation\Controllers\AffectationEnseignantController@annulerRun` |
| GET | `admin/evaluations/affectations/runs/{run}/status` | Status | `admin.evaluations.affectations.runs.status` | `App\Modules\Evaluation\Controllers\AffectationEnseignantController@runStatus` |
| POST | `admin/evaluations/affectations/{epreuve}/assigner` | Créer / Assigner | `admin.evaluations.affectations.assigner` | `App\Modules\Evaluation\Controllers\AffectationEnseignantController@assigner` |
| POST | `admin/evaluations/affectations/{surveillance}/retirer` | Créer / Retirer | `admin.evaluations.affectations.retirer` | `App\Modules\Evaluation\Controllers\AffectationEnseignantController@retirer` |
| GET | `admin/evaluations/anomalies` | Anomalies | `admin.evaluations.anomalies.index` | `App\Modules\Evaluation\Controllers\AnomalieController@index` |
| GET | `admin/evaluations/anomalies/export` | Export | `admin.evaluations.anomalies.export` | `App\Modules\Evaluation\Controllers\AnomalieController@exporter` |
| POST | `admin/evaluations/anomalies/resoudre-groupee` | Créer / Resoudre groupee | `admin.evaluations.anomalies.resoudre-groupee` | `App\Modules\Evaluation\Controllers\AnomalieController@resoudreGroupee` |
| GET | `admin/evaluations/candidats/{candidat}/convocation` | Convocation | `admin.evaluations.candidats.convocation` | `App\Modules\Evaluation\Controllers\EpreuveController@convocationCandidat` |
| POST | `admin/evaluations/decisions/{decision}` | Créer / {decision} | `admin.evaluations.decisions.decider` | `App\Modules\Evaluation\Controllers\DeliberationController@decider` |
| GET | `admin/evaluations/deliberations` | Deliberations | `admin.evaluations.deliberations.index` | `App\Modules\Evaluation\Controllers\DeliberationController@index` |
| POST | `admin/evaluations/deliberations` | Créer / Deliberations | `admin.evaluations.deliberations.store` | `App\Modules\Evaluation\Controllers\DeliberationController@store` |
| GET | `admin/evaluations/deliberations/{deliberation}` | {deliberation} | `admin.evaluations.deliberations.show` | `App\Modules\Evaluation\Controllers\DeliberationController@show` |
| POST | `admin/evaluations/deliberations/{deliberation}/membres` | Créer / Membres | `admin.evaluations.deliberations.membres.store` | `App\Modules\Evaluation\Controllers\DeliberationController@ajouterMembre` |
| POST | `admin/evaluations/deliberations/{deliberation}/preparer` | Créer / Preparer | `admin.evaluations.deliberations.preparer` | `App\Modules\Evaluation\Controllers\DeliberationController@preparer` |
| POST | `admin/evaluations/deliberations/{deliberation}/publier` | Créer / Publier | `admin.evaluations.deliberations.publier` | `App\Modules\Evaluation\Controllers\DeliberationController@publier` |
| GET | `admin/evaluations/deliberations/{deliberation}/pv` | Pv | `admin.evaluations.deliberations.pv` | `App\Modules\Evaluation\Controllers\DeliberationController@pv` |
| POST | `admin/evaluations/deliberations/{deliberation}/releves` | Créer / Releves | `admin.evaluations.deliberations.releves` | `App\Modules\Evaluation\Controllers\DeliberationController@genererReleves` |
| GET | `admin/evaluations/deliberations/{deliberation}/simuler` | Simuler | `admin.evaluations.deliberations.simuler` | `App\Modules\Evaluation\Controllers\DeliberationController@simuler` |
| POST | `admin/evaluations/deliberations/{deliberation}/valider` | Créer / Valider | `admin.evaluations.deliberations.valider` | `App\Modules\Evaluation\Controllers\DeliberationController@valider` |
| GET | `admin/evaluations/documents-batch` | Documents batch | `admin.evaluations.documents-batch.index` | `App\Modules\Evaluation\Controllers\DocumentBatchController@index` |
| POST | `admin/evaluations/documents-batch/generer` | Créer / Generer | `admin.evaluations.documents-batch.generer` | `App\Modules\Evaluation\Controllers\DocumentBatchController@generer` |
| POST | `admin/evaluations/documents-batch/previsualiser` | Créer / Previsualiser | `admin.evaluations.documents-batch.previsualiser` | `App\Modules\Evaluation\Controllers\DocumentBatchController@previsualiser` |
| GET | `admin/evaluations/documents-batch/{lotId}/telecharger` | Telecharger | `admin.evaluations.documents-batch.telecharger` | `App\Modules\Evaluation\Controllers\DocumentBatchController@telecharger` |
| GET | `admin/evaluations/epreuves` | Epreuves | `admin.evaluations.epreuves.index` | `App\Modules\Evaluation\Controllers\EpreuveController@index` |
| POST | `admin/evaluations/epreuves/verifier` | Créer / Verifier | `admin.evaluations.epreuves.verifier` | `App\Modules\Evaluation\Controllers\EpreuveController@verifier` |
| PUT, PATCH | `admin/evaluations/epreuves/{epreuve}` | Modifier / {epreuve} | `admin.evaluations.epreuves.update` | `App\Modules\Evaluation\Controllers\EpreuveController@update` |
| GET | `admin/evaluations/epreuves/{epreuve}` | {epreuve} | `admin.evaluations.epreuves.show` | `App\Modules\Evaluation\Controllers\EpreuveController@show` |
| POST | `admin/evaluations/epreuves/{epreuve}/compostages/generer` | Créer / Generer | `admin.evaluations.epreuves.compostages.generer` | `App\Modules\Evaluation\Controllers\NoteEpreuveController@generer` |
| GET | `admin/evaluations/epreuves/{epreuve}/convocation` | Convocation | `admin.evaluations.epreuves.convocation` | `App\Modules\Evaluation\Controllers\EpreuveController@convocation` |
| GET | `admin/evaluations/epreuves/{epreuve}/correspondance` | Correspondance | `admin.evaluations.epreuves.correspondance` | `App\Modules\Evaluation\Controllers\NoteEpreuveController@correspondance` |
| GET | `admin/evaluations/epreuves/{epreuve}/emargement` | Emargement | `admin.evaluations.epreuves.emargement` | `App\Modules\Evaluation\Controllers\EpreuveController@emargement` |
| POST | `admin/evaluations/epreuves/{epreuve}/emargement` | Créer / Emargement | `admin.evaluations.epreuves.emargement.store` | `App\Modules\Evaluation\Controllers\EpreuveController@enregistrerEmargement` |
| GET | `admin/evaluations/epreuves/{epreuve}/historique` | Historique | `admin.evaluations.epreuves.historique` | `App\Modules\Evaluation\Controllers\HistoriqueNotesController@epreuve` |
| GET | `admin/evaluations/epreuves/{epreuve}/incidents` | Incidents | `admin.evaluations.epreuves.incidents.index` | `App\Modules\Evaluation\Controllers\IncidentController@index` |
| POST | `admin/evaluations/epreuves/{epreuve}/incidents` | Créer / Incidents | `admin.evaluations.epreuves.incidents.store` | `App\Modules\Evaluation\Controllers\IncidentController@store` |
| GET | `admin/evaluations/epreuves/{epreuve}/notes` | Notes | `admin.evaluations.notes.saisie` | `App\Modules\Evaluation\Controllers\NoteEpreuveController@saisie` |
| POST | `admin/evaluations/epreuves/{epreuve}/notes` | Créer / Notes | `admin.evaluations.notes.store` | `App\Modules\Evaluation\Controllers\NoteEpreuveController@storeLot` |
| POST | `admin/evaluations/epreuves/{epreuve}/notes/anonymes` | Créer / Anonymes | `admin.evaluations.notes.store-anonyme` | `App\Modules\Evaluation\Controllers\NoteEpreuveController@storeAnonyme` |
| GET | `admin/evaluations/epreuves/{epreuve}/notes/export-anonyme` | Export anonyme | `admin.evaluations.notes.export-anonyme` | `App\Modules\Evaluation\Controllers\NoteEpreuveController@exportAnonyme` |
| POST | `admin/evaluations/epreuves/{epreuve}/notes/import-anonyme` | Créer / Import anonyme | `admin.evaluations.notes.import-anonyme` | `App\Modules\Evaluation\Controllers\NoteEpreuveController@importAnonyme` |
| POST | `admin/evaluations/epreuves/{epreuve}/notes/import-multi` | Créer / Import multi | `admin.evaluations.notes.import-multi` | `App\Modules\Evaluation\Controllers\NoteEpreuveController@importMulti` |
| POST | `admin/evaluations/epreuves/{epreuve}/notes/import-multi/annuler` | Créer / Annuler | `admin.evaluations.notes.import-multi-annuler` | `App\Modules\Evaluation\Controllers\NoteEpreuveController@importMultiAnnuler` |
| POST | `admin/evaluations/epreuves/{epreuve}/notes/import-multi/confirmer` | Créer / Confirmer | `admin.evaluations.notes.import-multi-confirmer` | `App\Modules\Evaluation\Controllers\NoteEpreuveController@importMultiConfirmer` |
| POST | `admin/evaluations/epreuves/{epreuve}/notes/soumettre` | Créer / Soumettre | `admin.evaluations.epreuves.notes.soumettre` | `App\Modules\Evaluation\Controllers\NoteEpreuveController@soumettre` |
| POST | `admin/evaluations/epreuves/{epreuve}/notes/valider-tout` | Créer / Valider tout | `admin.evaluations.epreuves.notes.valider-tout` | `App\Modules\Evaluation\Controllers\NoteEpreuveController@validerTout` |
| POST | `admin/evaluations/epreuves/{epreuve}/repartir-salles` | Créer / Repartir salles | `admin.evaluations.epreuves.repartir-salles` | `App\Modules\Evaluation\Controllers\EpreuveController@repartirSalles` |
| POST | `admin/evaluations/epreuves/{epreuve}/surveillants` | Créer / Surveillants | `admin.evaluations.surveillants.store` | `App\Modules\Evaluation\Controllers\SurveillanceController@store` |
| POST | `admin/evaluations/epreuves/{epreuve}/surveillants/simuler` | Créer / Simuler | `admin.evaluations.surveillants.simuler` | `App\Modules\Evaluation\Controllers\SurveillanceController@simuler` |
| POST | `admin/evaluations/epreuves/{epreuve}/transiter/{cible}` | Créer / {cible} | `admin.evaluations.epreuves.transiter` | `App\Modules\Evaluation\Controllers\EpreuveController@transiter` |
| POST | `admin/evaluations/epreuves/{epreuve}/verrouiller` | Créer / Verrouiller | `admin.evaluations.epreuves.verrouiller` | `App\Modules\Evaluation\Controllers\EpreuveController@verrouiller` |
| GET | `admin/evaluations/examens` | Examens | `admin.evaluations.examens.index` | `App\Modules\Evaluation\Controllers\ExamenController@index` |
| POST | `admin/evaluations/examens` | Créer / Examens | `admin.evaluations.examens.store` | `App\Modules\Evaluation\Controllers\ExamenController@store` |
| POST | `admin/evaluations/examens/{examen}/epreuves` | Créer / Epreuves | `admin.evaluations.epreuves.store` | `App\Modules\Evaluation\Controllers\EpreuveController@store` |
| POST | `admin/evaluations/examens/{examen}/epreuves/{epreuve}/candidats` | Créer / Candidats | `admin.evaluations.epreuves.candidats` | `App\Modules\Evaluation\Controllers\EpreuveController@genererCandidats` |
| POST | `admin/evaluations/examens/{examen}/transiter/{cible}` | Créer / {cible} | `admin.evaluations.examens.transiter` | `App\Modules\Evaluation\Controllers\ExamenController@transiter` |
| GET | `admin/evaluations/historique-notes` | Historique notes | `admin.evaluations.historique-notes.index` | `App\Modules\Evaluation\Controllers\HistoriqueNotesController@index` |
| POST | `admin/evaluations/incidents/{incident}/transiter/{cible}` | Créer / {cible} | `admin.evaluations.incidents.transiter` | `App\Modules\Evaluation\Controllers\IncidentController@transiter` |
| POST | `admin/evaluations/membres/{membre}/pointer` | Créer / Pointer | `admin.evaluations.membres.pointer` | `App\Modules\Evaluation\Controllers\DeliberationController@pointer` |
| POST | `admin/evaluations/notes/{note}/comparer` | Créer / Comparer | `admin.evaluations.notes.comparer` | `App\Modules\Evaluation\Controllers\HistoriqueNotesController@comparer` |
| POST | `admin/evaluations/notes/{note}/correction-autoriser` | Créer / Correction autoriser | `admin.evaluations.notes.correction-autoriser` | `App\Modules\Evaluation\Controllers\NoteEpreuveController@autoriserCorrection` |
| POST | `admin/evaluations/notes/{note}/correction-demander` | Créer / Correction demander | `admin.evaluations.notes.correction-demander` | `App\Modules\Evaluation\Controllers\NoteEpreuveController@demanderCorrection` |
| GET | `admin/evaluations/notes/{note}/historique` | Historique | `admin.evaluations.notes.historique` | `App\Modules\Evaluation\Controllers\HistoriqueNotesController@detail` |
| POST | `admin/evaluations/notes/{note}/valider` | Créer / Valider | `admin.evaluations.notes.valider` | `App\Modules\Evaluation\Controllers\NoteEpreuveController@valider` |
| POST | `admin/evaluations/notes/{note}/verrouiller` | Créer / Verrouiller | `admin.evaluations.notes.verrouiller` | `App\Modules\Evaluation\Controllers\NoteEpreuveController@verrouiller` |
| GET | `admin/evaluations/planning` | Planning | `admin.evaluations.planning` | `App\Modules\Evaluation\Controllers\EpreuveController@planning` |
| GET | `admin/evaluations/regles` | Regles | `admin.evaluations.regles.index` | `App\Modules\Evaluation\Controllers\RegleEvaluationController@index` |
| POST | `admin/evaluations/regles` | Créer / Regles | `admin.evaluations.regles.store` | `App\Modules\Evaluation\Controllers\RegleEvaluationController@store` |
| DELETE | `admin/evaluations/regles/{regle}` | Supprimer / {regle} | `admin.evaluations.regles.destroy` | `App\Modules\Evaluation\Controllers\RegleEvaluationController@destroy` |
| PATCH | `admin/evaluations/regles/{regle}/bascule` | Modifier / Bascule | `admin.evaluations.regles.bascule` | `App\Modules\Evaluation\Controllers\RegleEvaluationController@basculer` |
| GET | `admin/evaluations/sessions` | Sessions | `admin.evaluations.sessions.index` | `App\Modules\Evaluation\Controllers\SessionExamenController@index` |
| POST | `admin/evaluations/sessions` | Créer / Sessions | `admin.evaluations.sessions.store` | `App\Modules\Evaluation\Controllers\SessionExamenController@store` |
| GET | `admin/evaluations/sessions/creer` | Creer | `admin.evaluations.sessions.create` | `App\Modules\Evaluation\Controllers\SessionExamenController@create` |
| GET | `admin/evaluations/sessions/{session}` | {session} | `admin.evaluations.sessions.show` | `App\Modules\Evaluation\Controllers\SessionExamenController@show` |
| POST | `admin/evaluations/sessions/{session}/equilibrage/appliquer` | Créer / Appliquer | `admin.evaluations.sessions.equilibrage.appliquer` | `App\Modules\Evaluation\Controllers\SessionExamenController@appliquerEquilibrage` |
| POST | `admin/evaluations/sessions/{session}/equilibrage/simuler` | Créer / Simuler | `admin.evaluations.sessions.equilibrage.simuler` | `App\Modules\Evaluation\Controllers\SessionExamenController@simulerEquilibrage` |
| POST | `admin/evaluations/sessions/{session}/generer` | Créer / Generer | `admin.evaluations.sessions.generer` | `App\Modules\Evaluation\Controllers\SessionExamenController@generer` |
| POST | `admin/evaluations/sessions/{session}/surveillants-auto` | Créer / Surveillants auto | `admin.evaluations.sessions.surveillants-auto` | `App\Modules\Evaluation\Controllers\SessionExamenController@autoSurveillants` |
| POST | `admin/evaluations/sessions/{session}/transiter/{cible}` | Créer / {cible} | `admin.evaluations.sessions.transiter` | `App\Modules\Evaluation\Controllers\SessionExamenController@transiter` |
| POST | `admin/evaluations/sessions/{session}/verrouiller` | Créer / Verrouiller | `admin.evaluations.sessions.verrouiller` | `App\Modules\Evaluation\Controllers\SessionExamenController@verrouiller` |
| GET | `admin/evaluations/surveillances` | Surveillances | `admin.evaluations.surveillances.index` | `App\Modules\Evaluation\Controllers\SurveillanceController@index` |
| GET | `admin/evaluations/surveillances/cockpit` | Cockpit | `admin.evaluations.surveillances.cockpit` | `App\Modules\Evaluation\Controllers\SurveillanceController@cockpit` |
| GET | `admin/evaluations/surveillances/contraintes` | Contraintes | `admin.evaluations.surveillances.contraintes.index` | `App\Modules\Evaluation\Controllers\ContrainteSurveillanceController@index` |
| POST | `admin/evaluations/surveillances/contraintes` | Créer / Contraintes | `admin.evaluations.surveillances.contraintes.store` | `App\Modules\Evaluation\Controllers\ContrainteSurveillanceController@store` |
| GET | `admin/evaluations/surveillances/contraintes-alias` | Contraintes alias | `admin.evaluations.surveillances.contraintes` | `App\Modules\Evaluation\Controllers\ContrainteSurveillanceController@index` |
| POST | `admin/evaluations/surveillances/contraintes/reset` | Créer / Reset | `admin.evaluations.surveillances.contraintes.reset` | `App\Modules\Evaluation\Controllers\ContrainteSurveillanceController@reset` |
| POST | `admin/evaluations/surveillances/contraintes/simuler` | Créer / Simuler | `admin.evaluations.surveillances.contraintes.simuler` | `App\Modules\Evaluation\Controllers\ContrainteSurveillanceController@simuler` |
| PUT, PATCH | `admin/evaluations/surveillances/contraintes/{config}` | Modifier / {config} | `admin.evaluations.surveillances.contraintes.update` | `App\Modules\Evaluation\Controllers\ContrainteSurveillanceController@update` |
| POST | `admin/evaluations/surveillances/contraintes/{config}/reset` | Créer / Reset | `admin.evaluations.surveillances.contraintes.reset.item` | `App\Modules\Evaluation\Controllers\ContrainteSurveillanceController@reset` |
| GET | `admin/evaluations/surveillances/emploi` | Emploi | `admin.evaluations.surveillances.emploi` | `App\Modules\Evaluation\Controllers\EmploiSurveillanceController@index` |
| GET | `admin/evaluations/surveillances/emploi/csv` | Csv | `admin.evaluations.surveillances.emploi.csv` | `App\Modules\Evaluation\Controllers\EmploiSurveillanceController@exporterCsv` |
| GET | `admin/evaluations/surveillances/emploi/pdf` | Pdf | `admin.evaluations.surveillances.emploi.pdf` | `App\Modules\Evaluation\Controllers\EmploiSurveillanceController@exporterPdf` |
| GET | `admin/evaluations/surveillances/export` | Export | `admin.evaluations.surveillances.export` | `App\Modules\Evaluation\Controllers\SurveillanceController@exporter` |
| GET | `admin/evaluations/surveillances/mes-gardes` | Mes gardes | `admin.evaluations.surveillances.mes-gardes` | `App\Modules\Evaluation\Controllers\SurveillanceController@mesGardes` |
| POST | `admin/evaluations/surveillants/{surveillance}/confirmer` | Créer / Confirmer | `admin.evaluations.surveillants.confirmer` | `App\Modules\Evaluation\Controllers\SurveillanceController@confirmer` |
| POST | `admin/evaluations/surveillants/{surveillance}/desaffecter` | Créer / Desaffecter | `admin.evaluations.surveillants.desaffecter` | `App\Modules\Evaluation\Controllers\SurveillanceController@desaffecter` |
| POST | `admin/evaluations/surveillants/{surveillance}/emargement` | Créer / Emargement | `admin.evaluations.surveillants.emargement` | `App\Modules\Evaluation\Controllers\SurveillanceController@emargement` |
| POST | `admin/evaluations/surveillants/{surveillance}/remplacer` | Créer / Remplacer | `admin.evaluations.surveillants.remplacer` | `App\Modules\Evaluation\Controllers\SurveillanceController@remplacer` |
| GET | `admin/evaluations/types` | Types | `admin.evaluations.types.index` | `App\Modules\Evaluation\Controllers\TypeEvaluationController@index` |
| POST | `admin/evaluations/types` | Créer / Types | `admin.evaluations.types.store` | `App\Modules\Evaluation\Controllers\TypeEvaluationController@store` |
| PUT, PATCH | `admin/evaluations/types/{type}` | Modifier / {type} | `admin.evaluations.types.update` | `App\Modules\Evaluation\Controllers\TypeEvaluationController@update` |
| DELETE | `admin/evaluations/types/{type}` | Supprimer / {type} | `admin.evaluations.types.destroy` | `App\Modules\Evaluation\Controllers\TypeEvaluationController@destroy` |
| GET | `admin/examens/preparation` | Preparation | `admin.examens.preparation.index` | `App\Modules\Evaluation\Controllers\PreparationExamenController@index` |
| GET | `admin/examens/preparation/epreuves/{epreuve}/emargement` | Emargement | `admin.examens.preparation.epreuves.emargement` | `App\Modules\Evaluation\Controllers\PreparationExamenController@emargement` |
| POST | `admin/examens/preparation/epreuves/{epreuve}/population` | Créer / Population | `admin.examens.preparation.epreuves.population` | `App\Modules\Evaluation\Controllers\PreparationExamenController@validerPopulation` |
| GET | `admin/examens/preparation/{session}` | {session} | `admin.examens.preparation.session` | `App\Modules\Evaluation\Controllers\PreparationExamenController@session` |
| GET | `admin/exports` | Exports | `admin.exports.index` | `App\Http\Controllers\Admin\ExportController@index` |
| POST | `admin/exports/generate` | Créer / Generate | `admin.exports.generate` | `App\Http\Controllers\Admin\ExportController@generate` |
| GET | `admin/exports/options` | Options | `admin.exports.options` | `App\Http\Controllers\Admin\ExportController@options` |
| POST | `admin/exports/preview` | Créer / Preview | `admin.exports.preview` | `App\Http\Controllers\Admin\ExportController@preview` |
| GET | `admin/exports/rapports/{rapport}/download` | Download | `admin.exports.download` | `App\Http\Controllers\Admin\ExportController@download` |
| POST | `admin/exports/templates` | Créer / Templates | `admin.exports.templates.store` | `App\Http\Controllers\Admin\ExportController@storeTemplate` |
| DELETE | `admin/exports/templates/{template}` | Supprimer / {template} | `admin.exports.templates.destroy` | `App\Http\Controllers\Admin\ExportController@destroyTemplate` |
| GET | `admin/facultes` | Facultes | `admin.facultes.index` | `App\Http\Controllers\Admin\FaculteController@index` |
| POST | `admin/facultes` | Créer / Facultes | `admin.facultes.store` | `App\Http\Controllers\Admin\FaculteController@store` |
| GET | `admin/facultes/create` | Create | `admin.facultes.create` | `App\Http\Controllers\Admin\FaculteController@create` |
| PUT, PATCH | `admin/facultes/{faculte}` | Modifier / {faculte} | `admin.facultes.update` | `App\Http\Controllers\Admin\FaculteController@update` |
| DELETE | `admin/facultes/{faculte}` | Supprimer / {faculte} | `admin.facultes.destroy` | `App\Http\Controllers\Admin\FaculteController@destroy` |
| GET | `admin/facultes/{faculte}/edit` | Edit | `admin.facultes.edit` | `App\Http\Controllers\Admin\FaculteController@edit` |
| POST | `admin/favoris` | Créer / Favoris | `admin.favoris.basculer` | `App\Http\Controllers\Admin\FavoriController@basculer` |
| DELETE | `admin/favoris/{favori}` | Supprimer / {favori} | `admin.favoris.supprimer` | `App\Http\Controllers\Admin\FavoriController@supprimer` |
| GET | `admin/finances` | Finances | `admin.finances.index` | `App\Modules\Finances\Controllers\FinanceController@dashboard` |
| GET | `admin/finances/factures` | Factures | `admin.finances.factures.index` | `App\Modules\Finances\Controllers\FinanceController@factures` |
| GET | `admin/finances/factures/{facture}` | {facture} | `admin.finances.factures.show` | `App\Modules\Finances\Controllers\FinanceController@show` |
| POST | `admin/finances/factures/{facture}/payer` | Créer / Payer | `admin.finances.payer` | `App\Modules\Finances\Controllers\FinanceController@payer` |
| GET | `admin/finances/paiements-inscriptions` | Paiements inscriptions | `admin.finances.paiements.index` | `App\Modules\Finances\Controllers\PaiementInscriptionAdminController@index` |
| GET | `admin/finances/paiements-inscriptions/{dossier}` | {dossier} | `admin.finances.paiements.show` | `App\Modules\Finances\Controllers\PaiementInscriptionAdminController@show` |
| POST | `admin/finances/paiements-inscriptions/{dossier}/prendre` | Créer / Prendre | `admin.finances.paiements.prendre` | `App\Modules\Finances\Controllers\PaiementInscriptionAdminController@prendre` |
| GET | `admin/finances/paiements-inscriptions/{dossier}/recus/{recu}/apercu` | Apercu | `admin.finances.paiements.recu.apercu` | `App\Modules\Finances\Controllers\PaiementInscriptionAdminController@apercu` |
| GET | `admin/finances/paiements-inscriptions/{dossier}/recus/{recu}/telecharger` | Telecharger | `admin.finances.paiements.recu.telecharger` | `App\Modules\Finances\Controllers\PaiementInscriptionAdminController@telecharger` |
| POST | `admin/finances/paiements-inscriptions/{dossier}/refuser` | Créer / Refuser | `admin.finances.paiements.refuser` | `App\Modules\Finances\Controllers\PaiementInscriptionAdminController@refuser` |
| POST | `admin/finances/paiements-inscriptions/{dossier}/valider` | Créer / Valider | `admin.finances.paiements.valider` | `App\Modules\Finances\Controllers\PaiementInscriptionAdminController@valider` |
| POST | `admin/finances/paiements/{paiement}/confirmer` | Créer / Confirmer | `admin.finances.confirmer` | `App\Modules\Finances\Controllers\FinanceController@confirmer` |
| GET | `admin/finances/paiements/{paiement}/recu` | Recu | `admin.finances.recu` | `App\Modules\Finances\Controllers\FinanceController@recu` |
| GET | `admin/finances/tarifs` | Tarifs | `admin.finances.tarifs.index` | `App\Modules\Finances\Controllers\FinanceController@tarifs` |
| POST | `admin/finances/tarifs` | Créer / Tarifs | `admin.finances.tarifs.store` | `App\Modules\Finances\Controllers\FinanceController@storeTarif` |
| GET | `admin/groupes` | Groupes | `admin.groupes.index` | `App\Http\Controllers\Admin\GroupeController@index` |
| POST | `admin/groupes` | Créer / Groupes | `admin.groupes.store` | `App\Http\Controllers\Admin\GroupeController@store` |
| GET | `admin/groupes/creer` | Creer | `admin.groupes.create` | `App\Http\Controllers\Admin\GroupeController@create` |
| PUT, PATCH | `admin/groupes/{groupe}` | Modifier / {groupe} | `admin.groupes.update` | `App\Http\Controllers\Admin\GroupeController@update` |
| DELETE | `admin/groupes/{groupe}` | Supprimer / {groupe} | `admin.groupes.destroy` | `App\Http\Controllers\Admin\GroupeController@destroy` |
| GET | `admin/groupes/{groupe}` | {groupe} | `admin.groupes.show` | `App\Http\Controllers\Admin\GroupeController@show` |
| GET | `admin/groupes/{groupe}/etudiants` | Etudiants | `admin.groupes.etudiants` | `App\Http\Controllers\Admin\GroupeController@etudiants` |
| GET | `admin/groupes/{groupe}/modifier` | Modifier | `admin.groupes.edit` | `App\Http\Controllers\Admin\GroupeController@edit` |
| GET | `admin/groupes/{groupe}/sous-groupes` | Sous groupes | `admin.sous-groupes.index` | `App\Modules\Enseignement\Controllers\SousGroupeController@index` |
| POST | `admin/groupes/{groupe}/sous-groupes` | Créer / Sous groupes | `admin.sous-groupes.store` | `App\Modules\Enseignement\Controllers\SousGroupeController@store` |
| GET | `admin/imports/modeles` | Modeles | `admin.imports.templates` | `App\Http\Controllers\Admin\ImportTemplateController@index` |
| GET | `admin/imports/modeles/{type}/download/{format}` | {format} | `admin.imports.templates.download` | `App\Http\Controllers\Admin\ImportTemplateController@download` |
| GET | `admin/imports/rapports/{rapport}/erreurs` | Erreurs | `admin.imports.templates.erreurs` | `App\Http\Controllers\Admin\ImportTemplateController@erreurs` |
| DELETE | `admin/inscriptions-pedagogiques/{pedagogique}` | Supprimer / {pedagogique} | `admin.inscriptions.ecues.destroy` | `App\Http\Controllers\Admin\InscriptionGestionController@retirerEcue` |
| GET | `admin/inscriptions/file-traitement` | File traitement | `admin.inscriptions.file-traitement` | `App\Http\Controllers\Admin\FileTraitementController@index` |
| POST | `admin/inscriptions/file-traitement/{etudiant}/commenter` | Créer / Commenter | `admin.inscriptions.file-traitement.commenter` | `App\Http\Controllers\Admin\FileTraitementController@commenter` |
| GET | `admin/inscriptions/{inscription}/attestation` | Attestation | `admin.inscriptions.attestation` | `App\Http\Controllers\Admin\InscriptionGestionController@attestation` |
| POST | `admin/inscriptions/{inscription}/corriger` | Créer / Corriger | `admin.inscriptions.corriger` | `App\Http\Controllers\Admin\VerificationController@corrigerInscription` |
| POST | `admin/inscriptions/{inscription}/ecues` | Créer / Ecues | `admin.inscriptions.ecues.store` | `App\Http\Controllers\Admin\InscriptionGestionController@inscrireEcue` |
| POST | `admin/inscriptions/{inscription}/ecues/lot` | Créer / Lot | `admin.inscriptions.ecues.lot` | `App\Http\Controllers\Admin\InscriptionGestionController@inscrireEcues` |
| POST | `admin/inscriptions/{inscription}/facturer` | Créer / Facturer | `admin.finances.emettre` | `App\Modules\Finances\Controllers\FinanceController@emettre` |
| GET | `admin/inscriptions/{inscription}/pedagogique` | Pedagogique | `admin.inscriptions.pedagogique` | `App\Http\Controllers\Admin\InscriptionGestionController@pedagogique` |
| POST | `admin/inscriptions/{inscription}/progresser` | Créer / Progresser | `admin.inscriptions.progresser` | `App\Http\Controllers\Admin\InscriptionGestionController@progresser` |
| POST | `admin/inscriptions/{inscription}/reaffecter` | Créer / Reaffecter | `admin.inscriptions.reaffecter` | `App\Http\Controllers\Admin\InscriptionGestionController@reaffecter` |
| POST | `admin/inscriptions/{inscription}/reinscrire` | Créer / Reinscrire | `admin.inscriptions.reinscrire` | `App\Http\Controllers\Admin\InscriptionGestionController@reinscrire` |
| POST | `admin/inscriptions/{inscription}/rejeter` | Créer / Rejeter | `admin.inscriptions.rejeter` | `App\Http\Controllers\Admin\InscriptionGestionController@rejeter` |
| POST | `admin/inscriptions/{inscription}/valider` | Créer / Valider | `admin.inscriptions.valider` | `App\Http\Controllers\Admin\InscriptionGestionController@valider` |
| GET | `admin/integrations` | Integrations | `admin.integrations.index` | `App\Http\Controllers\Admin\IntegrationController@index` |
| POST | `admin/integrations/export` | Créer / Export | `admin.integrations.export` | `App\Http\Controllers\Admin\IntegrationController@storeExport` |
| POST | `admin/integrations/import` | Créer / Import | `admin.integrations.import` | `App\Http\Controllers\Admin\IntegrationController@storeImport` |
| POST | `admin/integrations/rapports/{rapport}/confirm` | Créer / Confirm | `admin.integrations.confirm` | `App\Http\Controllers\Admin\IntegrationController@confirm` |
| GET | `admin/integrations/rapports/{rapport}/download` | Download | `admin.integrations.download` | `App\Http\Controllers\Admin\IntegrationController@download` |
| GET | `admin/integrations/rapports/{rapport}/preview` | Preview | `admin.integrations.preview` | `App\Http\Controllers\Admin\IntegrationController@preview` |
| GET | `admin/justifications` | Justifications | `admin.justifications.index` | `App\Modules\Attendance\Controllers\JustificationController@index` |
| GET | `admin/justifications/{justification}/piece` | Piece | `admin.justifications.piece` | `App\Modules\Attendance\Controllers\JustificationController@telechargerPiece` |
| POST | `admin/justifications/{justification}/valider` | Créer / Valider | `admin.justifications.valider` | `App\Modules\Attendance\Controllers\JustificationController@valider` |
| GET | `admin/listes` | Listes | `admin.listes.index` | `App\Http\Controllers\Admin\ListesController@index` |
| GET | `admin/listes/exporter/{format}` | {format} | `admin.listes.exporter` | `App\Http\Controllers\Admin\ListesController@exporter` |
| GET | `admin/listes/exports` | Exports | `admin.listes.exports` | `App\Http\Controllers\Admin\ListesController@exports` |
| GET | `admin/listes/exports/{rapport}/download` | Download | `admin.listes.exports.download` | `App\Http\Controllers\Admin\ListesController@telechargerExport` |
| GET | `admin/listes/imprimer` | Imprimer | `admin.listes.imprimer` | `App\Http\Controllers\Admin\ListesController@imprimer` |
| GET | `admin/listes/preview` | Preview | `admin.listes.preview` | `App\Http\Controllers\Admin\ListesController@preview` |
| POST | `admin/listes/profils` | Créer / Profils | `admin.listes.profils.store` | `App\Http\Controllers\Admin\ListesController@storeProfil` |
| DELETE | `admin/listes/profils/{profil}` | Supprimer / {profil} | `admin.listes.profils.destroy` | `App\Http\Controllers\Admin\ListesController@destroyProfil` |
| GET | `admin/ma-faculte` | Ma faculte | `admin.ma-faculte` | `App\Http\Controllers\Admin\DashboardFaculteController@index` |
| GET | `admin/maintenance` | Maintenance | `admin.maintenance.index` | `App\Http\Controllers\Admin\MaintenanceController@index` |
| POST | `admin/maintenance/purger-cache` | Créer / Purger cache | `admin.maintenance.purger-cache` | `App\Http\Controllers\Admin\MaintenanceController@purgerCache` |
| GET | `admin/maquettes` | Maquettes | `admin.maquettes.index` | `App\Http\Controllers\Admin\MaquetteController@index` |
| POST | `admin/maquettes` | Créer / Maquettes | `admin.maquettes.store` | `App\Http\Controllers\Admin\MaquetteController@store` |
| GET | `admin/maquettes/creer` | Creer | `admin.maquettes.create` | `App\Http\Controllers\Admin\MaquetteController@create` |
| GET | `admin/maquettes/{template}` | {template} | `admin.maquettes.show` | `App\Http\Controllers\Admin\MaquetteController@show` |
| PATCH | `admin/maquettes/{template}` | Modifier / {template} | `admin.maquettes.update` | `App\Http\Controllers\Admin\MaquetteController@update` |
| GET | `admin/maquettes/{template}/apercu-pdf` | Apercu pdf | `admin.maquettes.apercu-pdf` | `App\Http\Controllers\Admin\MaquetteController@apercuPdf` |
| POST | `admin/maquettes/{template}/archiver` | Créer / Archiver | `admin.maquettes.archiver` | `App\Http\Controllers\Admin\MaquetteController@archiver` |
| POST | `admin/maquettes/{template}/dupliquer` | Créer / Dupliquer | `admin.maquettes.dupliquer` | `App\Http\Controllers\Admin\MaquetteController@dupliquer` |
| GET | `admin/maquettes/{template}/modifier` | Modifier | `admin.maquettes.edit` | `App\Http\Controllers\Admin\MaquetteController@edit` |
| POST | `admin/maquettes/{template}/publier` | Créer / Publier | `admin.maquettes.publier` | `App\Http\Controllers\Admin\MaquetteController@publier` |
| POST | `admin/maquettes/{template}/versions` | Créer / Versions | `admin.maquettes.versionner` | `App\Http\Controllers\Admin\MaquetteController@versionner` |
| GET | `admin/mens` | Mens | `admin.mens.index` | `App\Http\Controllers\Admin\MensReferenceController@index` |
| POST | `admin/mens` | Créer / Mens | `admin.mens.store` | `App\Http\Controllers\Admin\MensReferenceController@store` |
| GET | `admin/mens/assistance` | Assistance | `admin.mens.assistance` | `App\Http\Controllers\Admin\MensReferenceController@assistance` |
| GET | `admin/mens/creer` | Creer | `admin.mens.create` | `App\Http\Controllers\Admin\MensReferenceController@create` |
| GET | `admin/mens/referentiels` | Referentiels | `admin.mens.referentiels` | `App\Http\Controllers\Admin\MensReferenceController@referentiels` |
| GET | `admin/mens/{reference}` | {reference} | `admin.mens.show` | `App\Http\Controllers\Admin\MensReferenceController@show` |
| PUT | `admin/mens/{reference}` | Modifier / {reference} | `admin.mens.update` | `App\Http\Controllers\Admin\MensReferenceController@update` |
| DELETE | `admin/mens/{reference}` | Supprimer / {reference} | `admin.mens.destroy` | `App\Http\Controllers\Admin\MensReferenceController@destroy` |
| POST | `admin/mens/{reference}/creer-dossier` | Créer / Creer dossier | `admin.mens.creer-dossier` | `App\Http\Controllers\Admin\MensReferenceController@creerDossier` |
| GET | `admin/mens/{reference}/modifier` | Modifier | `admin.mens.edit` | `App\Http\Controllers\Admin\MensReferenceController@edit` |
| POST | `admin/mot-de-passe/lien` | Créer / Lien | `admin.mot-de-passe.envoyer` | `App\Http\Controllers\Admin\MotDePasseController@envoyerLien` |
| GET | `admin/mot-de-passe/oublie` | Oublie | `admin.mot-de-passe.oublie` | `App\Http\Controllers\Admin\MotDePasseController@oublie` |
| POST | `admin/mot-de-passe/reinitialiser` | Créer / Reinitialiser | `admin.mot-de-passe.enregistrer` | `App\Http\Controllers\Admin\MotDePasseController@enregistrer` |
| GET | `admin/mot-de-passe/reinitialiser/{token}` | {token} | `admin.mot-de-passe.reinitialiser` | `App\Http\Controllers\Admin\MotDePasseController@reinitialiser` |
| GET | `admin/notes/{epreuve}/saisie` | Saisie | `admin.notes.saisie` | `App\Modules\Evaluation\Controllers\NoteEpreuveController@saisie` |
| GET | `admin/notifications` | Notifications | `admin.notifications.index` | `App\Http\Controllers\NotificationController@indexAdmin` |
| GET | `admin/notifications/gabarits` | Gabarits | `admin.notifications.templates` | `App\Http\Controllers\Admin\NotificationTemplateController@index` |
| PATCH | `admin/notifications/gabarits/{gabarit}` | Modifier / {gabarit} | `admin.notifications.templates.update` | `App\Http\Controllers\Admin\NotificationTemplateController@update` |
| POST | `admin/notifications/lire-tout` | Créer / Lire tout | `admin.notifications.lire-tout` | `App\Http\Controllers\NotificationController@toutLireAdmin` |
| PATCH | `admin/notifications/{notification}/lire` | Modifier / Lire | `admin.notifications.lire` | `App\Http\Controllers\NotificationController@lireAdmin` |
| GET | `admin/parametres` | Parametres | `admin.parametres.index` | `App\Http\Controllers\Admin\ParametreController@index` |
| PATCH | `admin/parametres/{parametre}` | Modifier / {parametre} | `admin.parametres.update` | `App\Http\Controllers\Admin\ParametreController@update` |
| POST | `admin/parametres/{parametre}/restaurer` | Créer / Restaurer | `admin.parametres.restaurer` | `App\Http\Controllers\Admin\ParametreController@restaurer` |
| GET | `admin/parcours` | Parcours | `admin.parcours.index` | `App\Modules\ParcoursAcademique\Controllers\TableauBordParcoursController@index` |
| GET | `admin/parcours-regles` | Parcours regles | `admin.parcours.regles.index` | `App\Modules\ParcoursAcademique\Controllers\RegleParcoursController@index` |
| POST | `admin/parcours-regles` | Créer / Parcours regles | `admin.parcours.regles.store` | `App\Modules\ParcoursAcademique\Controllers\RegleParcoursController@store` |
| GET | `admin/parcours/comparaison` | Comparaison | `admin.parcours.comparer` | `App\Modules\ParcoursAcademique\Controllers\FicheParcoursController@comparer` |
| GET | `admin/parcours/etudiants/{etudiant}` | {etudiant} | `admin.parcours.fiche` | `App\Modules\ParcoursAcademique\Controllers\FicheParcoursController@show` |
| POST | `admin/parcours/etudiants/{etudiant}/risque` | Créer / Risque | `admin.parcours.risque.store` | `App\Modules\ParcoursAcademique\Controllers\AmenagementParcoursController@risque` |
| POST | `admin/parcours/plans` | Créer / Plans | `admin.parcours.plans.store` | `App\Modules\ParcoursAcademique\Controllers\AmenagementParcoursController@planifier` |
| GET | `admin/parcours/prospective` | Prospective | `admin.parcours.prospective` | `App\Modules\ParcoursAcademique\Controllers\TableauBordParcoursController@prospective` |
| GET | `admin/parcours/simulations` | Simulations | `admin.parcours.simulations.index` | `App\Modules\ParcoursAcademique\Controllers\SimulationParcoursController@index` |
| POST | `admin/parcours/simulations` | Créer / Simulations | `admin.parcours.simulations.store` | `App\Modules\ParcoursAcademique\Controllers\SimulationParcoursController@store` |
| GET | `admin/parcours/simulations/{simulation}` | {simulation} | `admin.parcours.simulations.show` | `App\Modules\ParcoursAcademique\Controllers\SimulationParcoursController@show` |
| GET | `admin/parcours/{categorie}` | {categorie} | `admin.parcours.amenagements.index` | `App\Modules\ParcoursAcademique\Controllers\AmenagementParcoursController@index` |
| POST | `admin/parcours/{categorie}` | Créer / {categorie} | `admin.parcours.amenagements.store` | `App\Modules\ParcoursAcademique\Controllers\AmenagementParcoursController@store` |
| POST | `admin/parcours/{categorie}/{id}/decider` | Créer / Decider | `admin.parcours.amenagements.decider` | `App\Modules\ParcoursAcademique\Controllers\AmenagementParcoursController@decider` |
| GET | `admin/periodes` | Periodes | `admin.periodes.index` | `App\Http\Controllers\Admin\PeriodeInscriptionController@index` |
| POST | `admin/periodes` | Créer / Periodes | `admin.periodes.store` | `App\Http\Controllers\Admin\PeriodeInscriptionController@store` |
| GET | `admin/periodes/etat` | Etat | `admin.periodes.etat` | `App\Http\Controllers\Admin\PeriodeInscriptionController@etat` |
| POST | `admin/periodes/{periode}/prolonger` | Créer / Prolonger | `admin.periodes.prolonger` | `App\Http\Controllers\Admin\PeriodeInscriptionController@prolonger` |
| POST | `admin/periodes/{periode}/revoquer` | Créer / Revoquer | `admin.periodes.revoquer` | `App\Http\Controllers\Admin\PeriodeInscriptionController@revoquer` |
| GET | `admin/personnel` | Personnel | `admin.personnel.index` | `App\Http\Controllers\Admin\PersonnelController@index` |
| POST | `admin/personnel` | Créer / Personnel | `admin.personnel.store` | `App\Http\Controllers\Admin\PersonnelController@store` |
| GET | `admin/personnel/creer` | Creer | `admin.personnel.create` | `App\Http\Controllers\Admin\PersonnelController@create` |
| GET | `admin/personnel/{personnel}` | {personnel} | `admin.personnel.show` | `App\Http\Controllers\Admin\PersonnelController@show` |
| PUT, PATCH | `admin/personnel/{personnel}` | Modifier / {personnel} | `admin.personnel.update` | `App\Http\Controllers\Admin\PersonnelController@update` |
| DELETE | `admin/personnel/{personnel}` | Supprimer / {personnel} | `admin.personnel.destroy` | `App\Http\Controllers\Admin\PersonnelController@destroy` |
| GET | `admin/personnel/{personnel}/modifier` | Modifier | `admin.personnel.edit` | `App\Http\Controllers\Admin\PersonnelController@edit` |
| GET | `admin/population/qualite` | Qualite | `admin.population.qualite` | `App\Http\Controllers\Admin\PopulationController@qualite` |
| GET | `admin/population/stats` | Stats | `admin.population.stats` | `App\Http\Controllers\Admin\PopulationController@stats` |
| GET | `admin/preferences` | Preferences | `admin.preferences` | `App\Http\Controllers\Admin\PreferenceController@afficher` |
| PATCH | `admin/preferences` | Modifier / Preferences | `admin.preferences.update` | `App\Http\Controllers\Admin\PreferenceController@mettreAJour` |
| GET | `admin/presences` | Presences | `admin.presences.index` | `App\Modules\Attendance\Controllers\PresenceController@index` |
| POST | `admin/presences` | Créer / Presences | `admin.presences.store` | `App\Modules\Attendance\Controllers\PresenceController@store` |
| POST | `admin/presences/enseignants/pointage` | Créer / Pointage | `admin.presences.enseignants.pointage` | `App\Modules\Attendance\Controllers\PresenceController@enregistrerPointageEnseignant` |
| POST | `admin/presences/enseignants/reconcilier` | Créer / Reconcilier | `admin.presences.enseignants.reconcilier` | `App\Modules\Attendance\Controllers\PresenceController@reconcilierEnseignants` |
| POST | `admin/presences/enseignants/{presence}/confirmer` | Créer / Confirmer | `admin.presences.enseignants.confirmer` | `App\Modules\Attendance\Controllers\PresenceController@confirmerPresenceEnseignant` |
| POST | `admin/presences/enseignants/{presence}/corriger` | Créer / Corriger | `admin.presences.enseignants.corriger` | `App\Modules\Attendance\Controllers\PresenceController@corrigerPresenceEnseignant` |
| POST | `admin/presences/enseignants/{presence}/dispenser` | Créer / Dispenser | `admin.presences.enseignants.dispenser` | `App\Modules\Attendance\Controllers\PresenceController@dispenserEnseignant` |
| GET | `admin/presences/enseignants/{presence}/historique` | Historique | `admin.presences.enseignants.historique` | `App\Modules\Attendance\Controllers\PresenceController@historiqueEnseignant` |
| POST | `admin/presences/enseignants/{presence}/valider` | Créer / Valider | `admin.presences.enseignants.valider` | `App\Modules\Attendance\Controllers\PresenceController@validerPresenceEnseignant` |
| GET | `admin/presences/feuille` | Feuille | `admin.presences.feuille` | `App\Modules\Attendance\Controllers\PresenceController@feuille` |
| GET | `admin/presences/feuille/apercu` | Apercu | `admin.presences.feuille.apercu` | `App\Modules\Attendance\Controllers\PresenceController@apercuFeuille` |
| GET | `admin/presences/feuille/nouveau` | Nouveau | `admin.presences.feuille.nouveau` | `App\Modules\Attendance\Controllers\PresenceController@nouveauFeuille` |
| GET | `admin/presences/modeles` | Modeles | `admin.presences.modeles.index` | `App\Modules\Attendance\Controllers\AttendanceTemplateController@index` |
| POST | `admin/presences/modeles` | Créer / Modeles | `admin.presences.modeles.store` | `App\Modules\Attendance\Controllers\AttendanceTemplateController@store` |
| GET | `admin/presences/modeles/creer` | Creer | `admin.presences.modeles.create` | `App\Modules\Attendance\Controllers\AttendanceTemplateController@create` |
| PUT | `admin/presences/modeles/{template}` | Modifier / {template} | `admin.presences.modeles.update` | `App\Modules\Attendance\Controllers\AttendanceTemplateController@update` |
| DELETE | `admin/presences/modeles/{template}` | Supprimer / {template} | `admin.presences.modeles.destroy` | `App\Modules\Attendance\Controllers\AttendanceTemplateController@destroy` |
| GET | `admin/presences/modeles/{template}/apercu` | Apercu | `admin.presences.modeles.apercu` | `App\Modules\Attendance\Controllers\AttendanceTemplateController@apercu` |
| GET | `admin/presences/modeles/{template}/apercu-pdf` | Apercu pdf | `admin.presences.modeles.apercu-pdf` | `App\Modules\Attendance\Controllers\AttendanceTemplateController@apercuPdf` |
| POST | `admin/presences/modeles/{template}/dupliquer` | Créer / Dupliquer | `admin.presences.modeles.duplicate` | `App\Modules\Attendance\Controllers\AttendanceTemplateController@duplicate` |
| GET | `admin/presences/modeles/{template}/editer` | Editer | `admin.presences.modeles.edit` | `App\Modules\Attendance\Controllers\AttendanceTemplateController@edit` |
| PATCH | `admin/presences/modeles/{template}/toggle` | Modifier / Toggle | `admin.presences.modeles.toggle` | `App\Modules\Attendance\Controllers\AttendanceTemplateController@toggle` |
| POST | `admin/presences/qr/scanner` | Créer / Scanner | `admin.presences.qr.scanner` | `App\Modules\Attendance\Controllers\PresenceController@scannerQr` |
| GET | `admin/presences/regularisations` | Regularisations | `admin.presences.regularisations.index` | `App\Http\Controllers\Admin\RegularisationPresenceController@index` |
| GET | `admin/presences/regularisations/{reclamation}` | {reclamation} | `admin.presences.regularisations.show` | `App\Http\Controllers\Admin\RegularisationPresenceController@show` |
| GET | `admin/presences/regularisations/{reclamation}/piece-jointe` | Piece jointe | `admin.presences.regularisations.piece-jointe` | `App\Http\Controllers\Admin\RegularisationPresenceController@pieceJointe` |
| POST | `admin/presences/regularisations/{reclamation}/traiter` | Créer / Traiter | `admin.presences.regularisations.traiter` | `App\Http\Controllers\Admin\RegularisationPresenceController@traiter` |
| DELETE | `admin/presences/{presence}` | Supprimer / {presence} | `admin.presences.destroy` | `App\Modules\Attendance\Controllers\PresenceController@destroy` |
| GET | `admin/qualite` | Qualite | `admin.qualite.index` | `App\Http\Controllers\Admin\QualiteController@index` |
| POST | `admin/qualite/{anomalie}/assigner` | Créer / Assigner | `admin.qualite.assigner` | `App\Http\Controllers\Admin\QualiteController@assigner` |
| POST | `admin/qualite/{anomalie}/transiter` | Créer / Transiter | `admin.qualite.transiter` | `App\Http\Controllers\Admin\QualiteController@transiter` |
| GET | `admin/recherche` | Recherche | `admin.recherche` | `App\Http\Controllers\Admin\RechercheController@global` |
| GET | `admin/recherche/contexte` | Contexte | `admin.recherche.contexte` | `App\Http\Controllers\Admin\RechercheController@contexte` |
| GET | `admin/recherche/instantanee` | Instantanee | `admin.recherche.instantanee` | `App\Http\Controllers\Admin\RechercheController@instantanee` |
| GET | `admin/referentiel/arbre` | Arbre | `admin.referentiel.arbre` | `App\Modules\Referentiel\Controllers\ReferentielController@arbre` |
| GET | `admin/referentiel/charges` | Charges | `admin.referentiel.charges` | `App\Modules\Referentiel\Controllers\ReferentielController@charges` |
| GET | `admin/referentiel/ecues` | Ecues | `admin.referentiel.ecues.index` | `App\Modules\Referentiel\Controllers\EcuesController@index` |
| GET | `admin/referentiel/ecues/{ecue}` | {ecue} | `admin.referentiel.ecues.show` | `App\Modules\Referentiel\Controllers\EcuesController@show` |
| PATCH | `admin/referentiel/ecues/{ecue}` | Modifier / {ecue} | `admin.referentiel.ecues.update` | `App\Modules\Referentiel\Controllers\EcuesController@update` |
| GET | `admin/referentiel/ecues/{ecue}/modifier` | Modifier | `admin.referentiel.ecues.edit` | `App\Modules\Referentiel\Controllers\EcuesController@edit` |
| POST | `admin/referentiel/ecues/{ecue}/prerequis` | Créer / Prerequis | `admin.referentiel.ecues.prerequis.store` | `App\Modules\Referentiel\Controllers\EcuesController@attacherPrerequis` |
| DELETE | `admin/referentiel/ecues/{ecue}/prerequis/{prerequis}` | Supprimer / {prerequis} | `admin.referentiel.ecues.prerequis.destroy` | `App\Modules\Referentiel\Controllers\EcuesController@detacherPrerequis` |
| GET | `admin/referentiel/maquettes` | Maquettes | `admin.referentiel.maquettes.index` | `App\Modules\Referentiel\Controllers\MaquetteController@index` |
| POST | `admin/referentiel/maquettes` | Créer / Maquettes | `admin.referentiel.maquettes.store` | `App\Modules\Referentiel\Controllers\MaquetteController@store` |
| GET | `admin/referentiel/maquettes/creer` | Creer | `admin.referentiel.maquettes.create` | `App\Modules\Referentiel\Controllers\MaquetteController@create` |
| GET | `admin/referentiel/maquettes/{maquette}` | {maquette} | `admin.referentiel.maquettes.show` | `App\Modules\Referentiel\Controllers\MaquetteController@show` |
| DELETE | `admin/referentiel/maquettes/{maquette}` | Supprimer / {maquette} | `admin.referentiel.maquettes.destroy` | `App\Modules\Referentiel\Controllers\MaquetteController@destroy` |
| GET | `admin/referentiel/maquettes/{maquette}/comparer` | Comparer | `admin.referentiel.maquettes.comparer` | `App\Modules\Referentiel\Controllers\MaquetteController@comparer` |
| PUT | `admin/referentiel/maquettes/{maquette}/contenu` | Modifier / Contenu | `admin.referentiel.maquettes.contenu` | `App\Modules\Referentiel\Controllers\MaquetteController@contenu` |
| PUT | `admin/referentiel/maquettes/{maquette}/ecues` | Modifier / Ecues | `admin.referentiel.maquettes.ecues` | `App\Modules\Referentiel\Controllers\MaquetteController@ecues` |
| POST | `admin/referentiel/maquettes/{maquette}/nouvelle-version` | Créer / Nouvelle version | `admin.referentiel.maquettes.nouvelle-version` | `App\Modules\Referentiel\Controllers\MaquetteController@nouvelleVersion` |
| PATCH | `admin/referentiel/maquettes/{maquette}/transition` | Modifier / Transition | `admin.referentiel.maquettes.transition` | `App\Modules\Referentiel\Controllers\MaquetteController@transition` |
| GET | `admin/referentiel/pilotage` | Pilotage | `admin.referentiel.pilotage` | `App\Modules\Referentiel\Controllers\ReferentielController@pilotage` |
| GET | `admin/referentiel/prerequis` | Prerequis | `admin.referentiel.prerequis` | `App\Modules\Referentiel\Controllers\ReferentielController@prerequis` |
| GET | `admin/referentiel/prevalidation` | Prevalidation | `admin.referentiel.prevalidation.index` | `App\Modules\Referentiel\Controllers\ReferentielController@prevalidation` |
| GET | `admin/referentiel/prevalidation/{programme}` | {programme} | `admin.referentiel.prevalidation.show` | `App\Modules\Referentiel\Controllers\ReferentielController@prevalidationProgramme` |
| GET | `admin/referentiel/qualite` | Qualite | `admin.referentiel.qualite` | `App\Modules\Referentiel\Controllers\ReferentielController@qualite` |
| POST | `admin/referentiel/reordonner` | Créer / Reordonner | `admin.referentiel.reordonner` | `App\Modules\Referentiel\Controllers\ReordonnancementController@store` |
| GET | `admin/referentiel/standalone` | Standalone | `admin.referentiel.standalone` | `App\Modules\Referentiel\Controllers\ReferentielController@standalone` |
| GET | `admin/referentiel/tableau-bord` | Tableau bord | `admin.referentiel.tableau-bord` | `App\Modules\Referentiel\Controllers\ReferentielController@tableauBord` |
| GET | `admin/referentiel/{entite}` | {entite} | `admin.referentiel.entites.index` | `App\Modules\Referentiel\Controllers\ReferentielController@index` |
| POST | `admin/referentiel/{entite}` | Créer / {entite} | `admin.referentiel.entites.store` | `App\Modules\Referentiel\Controllers\ReferentielController@store` |
| GET | `admin/referentiel/{entite}/creer` | Creer | `admin.referentiel.entites.create` | `App\Modules\Referentiel\Controllers\ReferentielController@create` |
| GET | `admin/referentiel/{entite}/{id}` | {id} | `admin.referentiel.entites.show` | `App\Modules\Referentiel\Controllers\ReferentielController@show` |
| PUT, PATCH | `admin/referentiel/{entite}/{id}` | Modifier / {id} | `admin.referentiel.entites.update` | `App\Modules\Referentiel\Controllers\ReferentielController@update` |
| DELETE | `admin/referentiel/{entite}/{id}` | Supprimer / {id} | `admin.referentiel.entites.destroy` | `App\Modules\Referentiel\Controllers\ReferentielController@destroy` |
| GET | `admin/referentiel/{entite}/{id}/modifier` | Modifier | `admin.referentiel.entites.edit` | `App\Modules\Referentiel\Controllers\ReferentielController@edit` |
| GET | `admin/roles` | Roles | `admin.roles.index` | `App\Http\Controllers\Admin\RoleController@index` |
| POST | `admin/roles/attribuer` | Créer / Attribuer | `admin.roles.attribuer` | `App\Http\Controllers\Admin\RoleController@attribuer` |
| GET | `admin/salles` | Salles | `admin.salles.index` | `App\Http\Controllers\Admin\SalleController@index` |
| POST | `admin/salles` | Créer / Salles | `admin.salles.store` | `App\Http\Controllers\Admin\SalleController@store` |
| GET | `admin/salles/creer` | Creer | `admin.salles.create` | `App\Http\Controllers\Admin\SalleController@create` |
| PUT, PATCH | `admin/salles/{salle}` | Modifier / {salle} | `admin.salles.update` | `App\Http\Controllers\Admin\SalleController@update` |
| DELETE | `admin/salles/{salle}` | Supprimer / {salle} | `admin.salles.destroy` | `App\Http\Controllers\Admin\SalleController@destroy` |
| GET | `admin/salles/{salle}/modifier` | Modifier | `admin.salles.edit` | `App\Http\Controllers\Admin\SalleController@edit` |
| GET | `admin/sentinelle` | Sentinelle | `admin.sentinel.index` | `App\Modules\Sentinel\Controllers\SentinelController@index` |
| POST | `admin/sentinelle/executer` | Créer / Executer | `admin.sentinel.executer` | `App\Modules\Sentinel\Controllers\SentinelController@executer` |
| GET | `admin/sentinelle/incidents/{incident}` | {incident} | `admin.sentinel.incidents.show` | `App\Modules\Sentinel\Controllers\SentinelController@show` |
| POST | `admin/sentinelle/incidents/{incident}/transiter` | Créer / Transiter | `admin.sentinel.incidents.transiter` | `App\Modules\Sentinel\Controllers\SentinelController@transiter` |
| GET | `admin/sentinelle/regles` | Regles | `admin.sentinel.regles` | `App\Modules\Sentinel\Controllers\SentinelController@regles` |
| POST | `admin/sentinelle/regles/{code}/bascule` | Créer / Bascule | `admin.sentinel.regles.basculer` | `App\Modules\Sentinel\Controllers\SentinelController@basculer` |
| GET | `admin/sessions` | Sessions | `admin.sessions.index` | `App\Http\Controllers\Admin\SessionsController@index` |
| DELETE | `admin/sessions/{session}` | Supprimer / {session} | `admin.sessions.destroy` | `App\Http\Controllers\Admin\SessionsController@destroy` |
| GET | `admin/smtp` | Smtp | `admin.smtp.index` | `App\Http\Controllers\Admin\SmtpConfigurationController@index` |
| POST | `admin/smtp` | Créer / Smtp | `admin.smtp.store` | `App\Http\Controllers\Admin\SmtpConfigurationController@store` |
| GET | `admin/smtp/creer` | Creer | `admin.smtp.create` | `App\Http\Controllers\Admin\SmtpConfigurationController@create` |
| PUT, PATCH | `admin/smtp/{smtp}` | Modifier / {smtp} | `admin.smtp.update` | `App\Http\Controllers\Admin\SmtpConfigurationController@update` |
| DELETE | `admin/smtp/{smtp}` | Supprimer / {smtp} | `admin.smtp.destroy` | `App\Http\Controllers\Admin\SmtpConfigurationController@destroy` |
| GET | `admin/smtp/{smtp}/modifier` | Modifier | `admin.smtp.edit` | `App\Http\Controllers\Admin\SmtpConfigurationController@edit` |
| POST | `admin/smtp/{smtp}/tester` | Créer / Tester | `admin.smtp.tester` | `App\Http\Controllers\Admin\SmtpConfigurationController@tester` |
| DELETE | `admin/sous-groupes/{sousGroupe}` | Supprimer / {sousGroupe} | `admin.sous-groupes.destroy` | `App\Modules\Enseignement\Controllers\SousGroupeController@destroy` |
| GET | `admin/statistiques` | Statistiques | `admin.statistiques.index` | `App\Http\Controllers\Admin\StatistiquesController@index` |
| GET | `admin/statistiques/export` | Export | `admin.statistiques.export` | `App\Http\Controllers\Admin\StatistiquesController@exporter` |
| GET | `admin/student-dossiers` | Student dossiers | `admin.student-dossiers.index` | `App\Modules\StudentDossiers\Controllers\StudentDossierController@index` |
| POST | `admin/student-dossiers` | Créer / Student dossiers | `admin.student-dossiers.store` | `App\Modules\StudentDossiers\Controllers\StudentDossierController@store` |
| DELETE | `admin/student-dossiers/documents/{document}` | Supprimer / {document} | `admin.student-dossiers.documents.destroy` | `App\Modules\StudentDossiers\Controllers\StudentDocumentController@destroy` |
| PATCH | `admin/student-dossiers/documents/{document}/reject` | Modifier / Reject | `admin.student-dossiers.documents.reject` | `App\Modules\StudentDossiers\Controllers\StudentDocumentController@reject` |
| POST | `admin/student-dossiers/documents/{document}/replace` | Créer / Replace | `admin.student-dossiers.documents.replace` | `App\Modules\StudentDossiers\Controllers\StudentDocumentController@replace` |
| PATCH | `admin/student-dossiers/documents/{document}/validate` | Modifier / Validate | `admin.student-dossiers.documents.validate` | `App\Modules\StudentDossiers\Controllers\StudentDocumentController@validate` |
| GET | `admin/student-dossiers/{dossier}` | {dossier} | `admin.student-dossiers.show` | `App\Modules\StudentDossiers\Controllers\StudentDossierController@show` |
| DELETE | `admin/student-dossiers/{dossier}` | Supprimer / {dossier} | `admin.student-dossiers.destroy` | `App\Modules\StudentDossiers\Controllers\StudentDossierController@destroy` |
| POST | `admin/student-dossiers/{dossier}/documents` | Créer / Documents | `admin.student-dossiers.documents.store` | `App\Modules\StudentDossiers\Controllers\StudentDocumentController@store` |
| GET | `admin/student-dossiers/{dossier}/documents/{document}/download` | Download | `admin.student-dossiers.documents.download` | `App\Modules\StudentDossiers\Controllers\StudentDocumentController@download` |
| GET | `admin/student-dossiers/{dossier}/documents/{document}/preview` | Preview | `admin.student-dossiers.documents.preview` | `App\Modules\StudentDossiers\Controllers\StudentDocumentController@preview` |
| GET | `admin/student-dossiers/{dossier}/documents/{document}/versions/{version}/download` | Download | `admin.student-dossiers.documents.versions.download` | `App\Modules\StudentDossiers\Controllers\StudentDocumentController@downloadVersion` |
| GET | `admin/student-dossiers/{dossier}/pdf/download` | Download | `admin.student-dossiers.pdf.download` | `App\Modules\StudentDossiers\Controllers\StudentDossierPdfController@download` |
| GET | `admin/student-dossiers/{dossier}/pdf/fichier` | Fichier | `admin.student-dossiers.pdf.fichier` | `App\Modules\StudentDossiers\Controllers\StudentDossierPdfController@fichier` |
| POST | `admin/student-dossiers/{dossier}/pdf/generer` | Créer / Generer | `admin.student-dossiers.pdf.generer` | `App\Modules\StudentDossiers\Controllers\StudentDossierPdfController@generer` |
| GET | `admin/student-dossiers/{dossier}/pdf/preview` | Preview | `admin.student-dossiers.pdf.preview` | `App\Modules\StudentDossiers\Controllers\StudentDossierPdfController@preview` |
| GET | `admin/student-dossiers/{dossier}/pdf/print` | Print | `admin.student-dossiers.pdf.print` | `App\Modules\StudentDossiers\Controllers\StudentDossierPdfController@print` |
| GET | `admin/studio` | Studio | `admin.studio.index` | `App\Http\Controllers\Admin\StudioCourrielController@index` |
| POST | `admin/studio` | Créer / Studio | `admin.studio.store` | `App\Http\Controllers\Admin\StudioCourrielController@store` |
| GET | `admin/studio/designer` | Designer | `admin.studio.designer` | `App\Livewire\Admin\Courriels\DesignerCourriel` |
| GET | `admin/studio/themes` | Themes | `admin.studio.themes` | `App\Http\Controllers\Admin\StudioCourrielController@themes` |
| POST | `admin/studio/themes` | Créer / Themes | `admin.studio.themes.store` | `App\Http\Controllers\Admin\StudioCourrielController@themeStore` |
| PUT, PATCH | `admin/studio/themes/{theme}` | Modifier / {theme} | `admin.studio.themes.update` | `App\Http\Controllers\Admin\StudioCourrielController@themeUpdate` |
| GET | `admin/studio/{modele}` | {modele} | `admin.studio.voir` | `App\Http\Controllers\Admin\StudioCourrielController@voir` |
| DELETE | `admin/studio/{modele}` | Supprimer / {modele} | `admin.studio.supprimer` | `App\Http\Controllers\Admin\StudioCourrielController@supprimer` |
| POST | `admin/studio/{modele}/apercu` | Créer / Apercu | `admin.studio.apercu` | `App\Http\Controllers\Admin\StudioCourrielController@apercu` |
| POST | `admin/studio/{modele}/archiver` | Créer / Archiver | `admin.studio.archiver` | `App\Http\Controllers\Admin\StudioCourrielController@archiver` |
| POST | `admin/studio/{modele}/dupliquer` | Créer / Dupliquer | `admin.studio.dupliquer` | `App\Http\Controllers\Admin\StudioCourrielController@dupliquer` |
| GET | `admin/studio/{modele}/editer` | Editer | `admin.studio.editer` | `App\Http\Controllers\Admin\StudioCourrielController@editer` |
| POST | `admin/studio/{modele}/enregistrer` | Créer / Enregistrer | `admin.studio.enregistrer` | `App\Http\Controllers\Admin\StudioCourrielController@enregistrer` |
| POST | `admin/studio/{modele}/envoi-test` | Créer / Envoi test | `admin.studio.envoi-test` | `App\Http\Controllers\Admin\StudioCourrielController@envoiTest` |
| POST | `admin/studio/{modele}/publier` | Créer / Publier | `admin.studio.publier` | `App\Http\Controllers\Admin\StudioCourrielController@publier` |
| POST | `admin/studio/{modele}/restaurer` | Créer / Restaurer | `admin.studio.restaurer` | `App\Http\Controllers\Admin\StudioCourrielController@restaurer` |
| POST | `admin/studio/{modele}/soumettre` | Créer / Soumettre | `admin.studio.soumettre` | `App\Http\Controllers\Admin\StudioCourrielController@soumettre` |
| GET | `admin/system/backups` | Backups | `admin.system.backups.index` | `App\Http\Controllers\Admin\SauvegardesController@index` |
| POST | `admin/system/backups` | Créer / Backups | `admin.system.backups.store` | `App\Http\Controllers\Admin\SauvegardesController@store` |
| GET | `admin/system/backups/planification` | Planification | `admin.system.backups.planification` | `App\Http\Controllers\Admin\SauvegardesController@planification` |
| POST | `admin/system/backups/planification` | Créer / Planification | `admin.system.backups.planification.update` | `App\Http\Controllers\Admin\SauvegardesController@mettreAJourPlanification` |
| POST | `admin/system/backups/{backup}/restaurer` | Créer / Restaurer | `admin.system.backups.restore` | `App\Http\Controllers\Admin\SauvegardesController@restaurer` |
| POST | `admin/system/backups/{backup}/tester-restauration` | Créer / Tester restauration | `admin.system.backups.tester-restauration` | `App\Http\Controllers\Admin\SauvegardesController@testerRestauration` |
| POST | `admin/system/backups/{backup}/verifier` | Créer / Verifier | `admin.system.backups.verify` | `App\Http\Controllers\Admin\SauvegardesController@verifier` |
| GET | `admin/systeme` | Systeme | `admin.systeme.index` | `App\Http\Controllers\Admin\SystemeController@index` |
| GET | `admin/taches` | Taches | `admin.taches.index` | `App\Modules\Tasks\Controllers\TaskController@index` |
| POST | `admin/taches/actions-groupees` | Créer / Actions groupees | `admin.taches.bulk` | `App\Modules\Tasks\Controllers\TaskController@actionGroupee` |
| GET | `admin/taches/{tache}` | {tache} | `admin.taches.show` | `App\Modules\Tasks\Controllers\TaskController@show` |
| POST | `admin/taches/{tache}/assigner` | Créer / Assigner | `admin.taches.assigner` | `App\Modules\Tasks\Controllers\TaskController@assigner` |
| POST | `admin/taches/{tache}/transiter/{cible}` | Créer / {cible} | `admin.taches.transiter` | `App\Modules\Tasks\Controllers\TaskController@transiter` |
| GET | `admin/verification/bloquees` | Bloquees | `admin.verification.index` | `App\Http\Controllers\Admin\VerificationController@index` |
| POST | `admin/verification/corrections/{correction}/decider` | Créer / Decider | `admin.verification.decider` | `App\Http\Controllers\Admin\VerificationController@decider` |
| GET | `admin/verrouillage/code` | Code | `admin.verrouillage.code` | `App\Http\Controllers\VerrouillageController@code` |
| POST | `admin/verrouillage/code` | Créer / Code | `admin.verrouillage.definir` | `App\Http\Controllers\VerrouillageController@definir` |
| POST | `admin/verrouillage/deconnecter` | Créer / Deconnecter | `admin.verrouillage.deconnecter` | `App\Http\Controllers\VerrouillageController@deconnecter` |
| POST | `admin/verrouillage/prolonger` | Créer / Prolonger | `admin.verrouillage.prolonger` | `App\Http\Controllers\VerrouillageController@prolonger` |
| POST | `admin/verrouillage/verifier` | Créer / Verifier | `admin.verrouillage.verifier` | `App\Http\Controllers\VerrouillageController@verifier` |
| POST | `admin/verrouillage/verrouiller` | Créer / Verrouiller | `admin.verrouillage.verrouiller` | `App\Http\Controllers\VerrouillageController@verrouiller` |
| GET | `admin/versioning` | Versioning | `admin.versioning.index` | `App\Modules\Versioning\Controllers\VersioningController@index` |
| GET | `admin/versioning/audit` | Audit | `admin.versioning.audit` | `App\Modules\Versioning\Controllers\VersioningController@audit` |
| GET | `admin/versioning/comparer` | Comparer | `admin.versioning.releases.comparer` | `App\Modules\Versioning\Controllers\ReleaseController@comparer` |
| GET | `admin/versioning/compatibilite` | Compatibilite | `admin.versioning.compatibilite` | `App\Modules\Versioning\Controllers\VersioningController@compatibilite` |
| GET | `admin/versioning/deploiements` | Deploiements | `admin.versioning.deployments.index` | `App\Modules\Versioning\Controllers\DeploymentController@index` |
| GET | `admin/versioning/deploiements/{deployment}` | {deployment} | `admin.versioning.deployments.show` | `App\Modules\Versioning\Controllers\DeploymentController@show` |
| GET | `admin/versioning/drift` | Drift | `admin.versioning.drift` | `App\Modules\Versioning\Controllers\VersioningController@drift` |
| GET | `admin/versioning/manifest/{version}` | {version} | `admin.versioning.manifest` | `App\Modules\Versioning\Controllers\VersioningController@manifest` |
| GET | `admin/versioning/releases` | Releases | `admin.versioning.releases.index` | `App\Modules\Versioning\Controllers\ReleaseController@index` |
| POST | `admin/versioning/releases` | Créer / Releases | `admin.versioning.releases.store` | `App\Modules\Versioning\Controllers\ReleaseController@store` |
| GET | `admin/versioning/releases/creer` | Creer | `admin.versioning.releases.create` | `App\Modules\Versioning\Controllers\ReleaseController@create` |
| GET | `admin/versioning/releases/{release}` | {release} | `admin.versioning.releases.show` | `App\Modules\Versioning\Controllers\ReleaseController@show` |
| GET | `admin/versioning/releases/{release}/deployer` | Deployer | `admin.versioning.deployments.create` | `App\Modules\Versioning\Controllers\DeploymentController@create` |
| POST | `admin/versioning/releases/{release}/deployer` | Créer / Deployer | `admin.versioning.deployments.store` | `App\Modules\Versioning\Controllers\DeploymentController@store` |
| GET | `admin/versioning/releases/{release}/rollback` | Rollback | `admin.versioning.rollback.plan` | `App\Modules\Versioning\Controllers\DeploymentController@rollbackPlan` |
| POST | `admin/versioning/releases/{release}/rollback` | Créer / Rollback | `admin.versioning.rollback.execute` | `App\Modules\Versioning\Controllers\DeploymentController@rollback` |
| POST | `admin/versioning/releases/{release}/transition` | Créer / Transition | `admin.versioning.releases.transition` | `App\Modules\Versioning\Controllers\ReleaseController@transition` |
| POST | `admin/vues` | Créer / Vues | `admin.vues.store` | `App\Http\Controllers\Admin\VueSauvegardeeController@store` |
| DELETE | `admin/vues/{vue}` | Supprimer / {vue} | `admin.vues.destroy` | `App\Http\Controllers\Admin\VueSauvegardeeController@destroy` |

---

## 2. Espace Enseignant

| Méthode | URL | Tâche | Nom de route | Contrôleur / Action |
|---------|-----|-------|----------------|-------------------|
| GET | `enseignant` |  | `enseignant.` | `Closure` |
| GET | `enseignant/annonces` | Annonces | `enseignant.annonces.index` | `App\Modules\Announcements\Controllers\MesAnnoncesController@indexEnseignant` |
| GET | `enseignant/annonces/{annonce}` | {annonce} | `enseignant.annonces.show` | `App\Modules\Announcements\Controllers\MesAnnoncesController@showEnseignant` |
| GET | `enseignant/appel` | Appel | `enseignant.appel.selection` | `App\Modules\Attendance\Controllers\AppelController@selection` |
| GET | `enseignant/appel/ouvrir/{slot}` | {slot} | `enseignant.appel.ouvrir` | `App\Modules\Attendance\Controllers\AppelController@ouvrir` |
| GET | `enseignant/appel/{appel}` | {appel} | `enseignant.appel.feuille` | `App\Modules\Attendance\Controllers\AppelController@feuille` |
| POST | `enseignant/appel/{appel}/cloturer` | Créer / Cloturer | `enseignant.appel.cloturer` | `App\Modules\Attendance\Controllers\AppelController@cloturer` |
| POST | `enseignant/appel/{appel}/corriger` | Créer / Corriger | `enseignant.appel.corriger` | `App\Modules\Attendance\Controllers\AppelController@corriger` |
| POST | `enseignant/appel/{appel}/enregistrer` | Créer / Enregistrer | `enseignant.appel.enregistrer` | `App\Modules\Attendance\Controllers\AppelController@enregistrer` |
| GET | `enseignant/appel/{appel}/export` | Export | `enseignant.appel.exporter` | `App\Modules\Attendance\Controllers\AppelController@exporter` |
| GET | `enseignant/appel/{appel}/qr/{etudiant}` | {etudiant} | `enseignant.appel.qr` | `App\Modules\Attendance\Controllers\AppelController@qr` |
| GET | `enseignant/calendrier` | Calendrier | `enseignant.calendrier.agenda` | `App\Modules\Calendar\Controllers\CalendrierController@agendaEnseignant` |
| GET | `enseignant/connexion` | Connexion | `enseignant.connexion.show` | `App\Http\Controllers\Enseignant\ConnexionController@show` |
| POST | `enseignant/connexion` | Créer / Connexion | `enseignant.connexion.login` | `App\Http\Controllers\Enseignant\ConnexionController@login` |
| POST | `enseignant/deconnexion` | Créer / Deconnexion | `enseignant.connexion.logout` | `App\Http\Controllers\Enseignant\ConnexionController@logout` |
| GET | `enseignant/demandes` | Demandes | `enseignant.demandes.index` | `App\Http\Controllers\Enseignant\DemandeController@index` |
| POST | `enseignant/demandes` | Créer / Demandes | `enseignant.demandes.store` | `App\Http\Controllers\Enseignant\DemandeController@store` |
| GET | `enseignant/demandes/creer` | Creer | `enseignant.demandes.create` | `App\Http\Controllers\Enseignant\DemandeController@create` |
| GET | `enseignant/demandes/{demande}` | {demande} | `enseignant.demandes.show` | `App\Http\Controllers\Enseignant\DemandeController@show` |
| POST | `enseignant/demandes/{demande}/annuler` | Créer / Annuler | `enseignant.demandes.annuler` | `App\Http\Controllers\Enseignant\DemandeController@annuler` |
| GET | `enseignant/demandes/{demande}/piece-jointe` | Piece jointe | `enseignant.demandes.piece-jointe` | `App\Http\Controllers\Enseignant\DemandeController@pieceJointe` |
| GET | `enseignant/documents` | Documents | `enseignant.documents.index` | `App\Http\Controllers\Enseignant\DocumentsController@index` |
| GET | `enseignant/documents/{document}/telecharger` | Telecharger | `enseignant.documents.telecharger` | `App\Http\Controllers\Enseignant\DocumentController@download` |
| GET | `enseignant/emploi-du-temps` | Emploi du temps | `enseignant.emploi-du-temps.semaine` | `App\Modules\Schedule\Controllers\MonEmploiEnseignantController@semaine` |
| GET | `enseignant/emploi-du-temps.pdf` | Emploi du temps.pdf | `enseignant.emploi-du-temps.pdf` | `App\Modules\Schedule\Controllers\MonEmploiEnseignantController@exporterPdf` |
| GET | `enseignant/emploi-du-temps/creneaux/{slot}` | {slot} | `enseignant.emploi-du-temps.creneau` | `App\Modules\Schedule\Controllers\MonEmploiEnseignantController@show` |
| GET | `enseignant/epreuves/{epreuve}` | {epreuve} | `enseignant.epreuves.show` | `App\Modules\Evaluation\Controllers\MesSurveillancesController@show` |
| GET | `enseignant/epreuves/{epreuve}/emargement` | Emargement | `enseignant.epreuves.emargement` | `App\Modules\Evaluation\Controllers\MesSurveillancesController@emargement` |
| POST | `enseignant/epreuves/{epreuve}/incidents` | Créer / Incidents | `enseignant.epreuves.incidents.signaler` | `App\Modules\Evaluation\Controllers\MesSurveillancesController@signaler` |
| POST | `enseignant/mot-de-passe/lien` | Créer / Lien | `enseignant.mot-de-passe.envoyer` | `App\Http\Controllers\Auth\PortailMotDePasseController@envoyerLien` |
| GET | `enseignant/mot-de-passe/oublie` | Oublie | `enseignant.mot-de-passe.oublie` | `App\Http\Controllers\Auth\PortailMotDePasseController@oublie` |
| POST | `enseignant/mot-de-passe/reinitialiser` | Créer / Reinitialiser | `enseignant.mot-de-passe.enregistrer` | `App\Http\Controllers\Auth\PortailMotDePasseController@enregistrer` |
| GET | `enseignant/mot-de-passe/reinitialiser/{token}` | {token} | `enseignant.mot-de-passe.reinitialiser` | `App\Http\Controllers\Auth\PortailMotDePasseController@reinitialiser` |
| GET | `enseignant/notes` | Notes | `enseignant.notes.index` | `App\Modules\Evaluation\Controllers\MesNotesController@index` |
| GET | `enseignant/notes/{epreuve}/export` | Export | `enseignant.notes.exporter` | `App\Modules\Evaluation\Controllers\MesNotesController@exporter` |
| POST | `enseignant/notes/{epreuve}/import` | Créer / Import | `enseignant.notes.importer` | `App\Modules\Evaluation\Controllers\MesNotesController@importer` |
| GET | `enseignant/notes/{epreuve}/saisie` | Saisie | `enseignant.notes.saisie` | `App\Modules\Evaluation\Controllers\MesNotesController@saisie` |
| POST | `enseignant/notes/{epreuve}/saisie` | Créer / Saisie | `enseignant.notes.store` | `App\Modules\Evaluation\Controllers\MesNotesController@store` |
| GET | `enseignant/notifications` | Notifications | `enseignant.notifications.index` | `App\Http\Controllers\NotificationController@indexEnseignant` |
| POST | `enseignant/notifications/lire-tout` | Créer / Lire tout | `enseignant.notifications.lire-tout` | `App\Http\Controllers\NotificationController@toutLireEnseignant` |
| PATCH | `enseignant/notifications/{notification}/lire` | Modifier / Lire | `enseignant.notifications.lire` | `App\Http\Controllers\NotificationController@lireEnseignant` |
| GET | `enseignant/presences` | Presences | `enseignant.presences.index` | `App\Modules\Attendance\Controllers\MesPresencesController@index` |
| GET | `enseignant/presences/export-csv` | Export csv | `enseignant.presences.export` | `App\Modules\Attendance\Controllers\MesPresencesController@exporter` |
| GET | `enseignant/presences/{presence}` | {presence} | `enseignant.presences.show` | `App\Modules\Attendance\Controllers\MesPresencesController@show` |
| GET | `enseignant/profil` | Profil | `enseignant.profil.show` | `App\Http\Controllers\Enseignant\ProfilController@show` |
| PATCH | `enseignant/profil` | Modifier / Profil | `enseignant.profil.update` | `App\Http\Controllers\Enseignant\ProfilController@update` |
| GET | `enseignant/profil/modifier` | Modifier | `enseignant.profil.edit` | `App\Http\Controllers\Enseignant\ProfilController@edit` |
| PATCH | `enseignant/profil/mot-de-passe` | Modifier / Mot de passe | `enseignant.profil.password` | `App\Http\Controllers\Enseignant\ProfilController@updatePassword` |
| GET | `enseignant/recherche` | Recherche | `enseignant.recherche` | `App\Http\Controllers\RecherchePortailController@enseignant` |
| GET | `enseignant/reclamations` | Reclamations | `enseignant.reclamations.index` | `App\Http\Controllers\Enseignant\ReclamationController@index` |
| POST | `enseignant/reclamations` | Créer / Reclamations | `enseignant.reclamations.store` | `App\Http\Controllers\Enseignant\ReclamationController@store` |
| GET | `enseignant/reclamations/creer` | Creer | `enseignant.reclamations.create` | `App\Http\Controllers\Enseignant\ReclamationController@create` |
| GET | `enseignant/reclamations/{reclamation}` | {reclamation} | `enseignant.reclamations.show` | `App\Http\Controllers\Enseignant\ReclamationController@show` |
| POST | `enseignant/reclamations/{reclamation}/cloturer` | Créer / Cloturer | `enseignant.reclamations.cloturer` | `App\Http\Controllers\Enseignant\ReclamationController@cloturer` |
| GET | `enseignant/reclamations/{reclamation}/piece-jointe` | Piece jointe | `enseignant.reclamations.piece-jointe` | `App\Http\Controllers\Enseignant\ReclamationController@pieceJointe` |
| GET | `enseignant/ressources` | Ressources | `enseignant.ressources.index` | `App\Modules\Enseignement\Controllers\MesRessourcesController@index` |
| POST | `enseignant/ressources` | Créer / Ressources | `enseignant.ressources.store` | `App\Modules\Enseignement\Controllers\MesRessourcesController@store` |
| GET | `enseignant/ressources/creer` | Creer | `enseignant.ressources.create` | `App\Modules\Enseignement\Controllers\MesRessourcesController@create` |
| GET | `enseignant/ressources/{ressource}` | {ressource} | `enseignant.ressources.show` | `App\Modules\Enseignement\Controllers\MesRessourcesController@show` |
| DELETE | `enseignant/ressources/{ressource}` | Supprimer / {ressource} | `enseignant.ressources.destroy` | `App\Modules\Enseignement\Controllers\MesRessourcesController@destroy` |
| POST | `enseignant/ressources/{ressource}/archiver` | Créer / Archiver | `enseignant.ressources.archiver` | `App\Modules\Enseignement\Controllers\MesRessourcesController@archiver` |
| POST | `enseignant/ressources/{ressource}/publier` | Créer / Publier | `enseignant.ressources.publier` | `App\Modules\Enseignement\Controllers\MesRessourcesController@publier` |
| POST | `enseignant/ressources/{ressource}/retirer` | Créer / Retirer | `enseignant.ressources.retirer` | `App\Modules\Enseignement\Controllers\MesRessourcesController@retirer` |
| GET | `enseignant/ressources/{ressource}/telecharger` | Telecharger | `enseignant.ressources.telecharger` | `App\Modules\Enseignement\Controllers\MesRessourcesController@telecharger` |
| GET | `enseignant/surveillances` | Surveillances | `enseignant.surveillances.index` | `App\Modules\Evaluation\Controllers\MesSurveillancesController@index` |
| GET | `enseignant/surveillances/export-csv` | Export csv | `enseignant.surveillances.export` | `App\Modules\Evaluation\Controllers\MesSurveillancesController@exporter` |
| GET | `enseignant/tableau-de-bord` | Tableau de bord | `enseignant.dashboard` | `App\Http\Controllers\Enseignant\TableauDeBordController@index` |

---

## 3. Espace Étudiant

| Méthode | URL | Tâche | Nom de route | Contrôleur / Action |
|---------|-----|-------|----------------|-------------------|
| GET | `etudiant/absences` | Absences | `etudiant.absences.index` | `App\Modules\Attendance\Controllers\PresenceController@mesAbsences` |
| GET | `etudiant/absences/export` | Export | `etudiant.absences.exporter` | `App\Modules\Attendance\Controllers\PresenceController@exporterAbsences` |
| GET | `etudiant/absences/justificatifs/{justification}/piece` | Piece | `etudiant.absences.justificatif` | `App\Modules\Attendance\Controllers\JustificationController@telechargerPieceEtudiant` |
| POST | `etudiant/absences/{presence}/justifier` | Créer / Justifier | `etudiant.absences.justifier` | `App\Modules\Attendance\Controllers\JustificationController@deposer` |
| GET | `etudiant/annonces` | Annonces | `etudiant.annonces.index` | `App\Modules\Announcements\Controllers\MesAnnoncesController@index` |
| GET | `etudiant/annonces/{annonce}` | {annonce} | `etudiant.annonces.show` | `App\Modules\Announcements\Controllers\MesAnnoncesController@show` |
| GET | `etudiant/appel/qr` | Qr | `etudiant.appel.qr` | `App\Modules\Attendance\Controllers\PointageQrController@formulaire` |
| POST | `etudiant/appel/qr` | Créer / Qr | `etudiant.appel.pointer` | `App\Modules\Attendance\Controllers\PointageQrController@pointer` |
| GET | `etudiant/archives` | Archives | `etudiant.archives.show` | `App\Http\Controllers\Etudiant\ArchiveController@show` |
| GET | `etudiant/archives/documents/{document}/download` | Download | `etudiant.archives.documents.download` | `App\Http\Controllers\Etudiant\ArchiveController@download` |
| GET | `etudiant/calendrier` | Calendrier | `etudiant.calendrier.agenda` | `App\Modules\Calendar\Controllers\CalendrierController@agenda` |
| GET | `etudiant/connexion` | Connexion | `etudiant.connexion.show` | `App\Http\Controllers\Etudiant\ConnexionController@show` |
| POST | `etudiant/connexion` | Créer / Connexion | `etudiant.connexion.login` | `App\Http\Controllers\Etudiant\ConnexionController@login` |
| GET | `etudiant/corrections` | Corrections | `etudiant.corrections.index` | `App\Http\Controllers\Etudiant\DossierComplementController@index` |
| POST | `etudiant/corrections` | Créer / Corrections | `etudiant.corrections.store` | `App\Http\Controllers\Etudiant\DossierComplementController@store` |
| POST | `etudiant/deconnexion` | Créer / Deconnexion | `etudiant.connexion.logout` | `App\Http\Controllers\Etudiant\ConnexionController@logout` |
| GET | `etudiant/demandes` | Demandes | `etudiant.demandes.index` | `App\Modules\OnlineRequests\Controllers\MesDemandesController@index` |
| POST | `etudiant/demandes` | Créer / Demandes | `etudiant.demandes.store` | `App\Modules\OnlineRequests\Controllers\MesDemandesController@store` |
| GET | `etudiant/demandes/nouvelle` | Nouvelle | `etudiant.demandes.create` | `App\Modules\OnlineRequests\Controllers\MesDemandesController@create` |
| GET | `etudiant/demandes/{demande}` | {demande} | `etudiant.demandes.show` | `App\Modules\OnlineRequests\Controllers\MesDemandesController@show` |
| POST | `etudiant/demandes/{demande}/annuler` | Créer / Annuler | `etudiant.demandes.cancel` | `App\Modules\OnlineRequests\Controllers\MesDemandesController@cancel` |
| GET | `etudiant/demandes/{demande}/document/download` | Download | `etudiant.demandes.document.download` | `App\Modules\OnlineRequests\Controllers\MesDemandesController@downloadDocument` |
| POST | `etudiant/demandes/{demande}/messages` | Créer / Messages | `etudiant.demandes.messages.repondre` | `App\Modules\OnlineRequests\Controllers\MesDemandesController@repondre` |
| POST | `etudiant/demandes/{demande}/pieces` | Créer / Pieces | `etudiant.demandes.pieces.store` | `App\Modules\OnlineRequests\Controllers\MesDemandesController@uploadPiece` |
| POST | `etudiant/demandes/{demande}/soumettre` | Créer / Soumettre | `etudiant.demandes.submit` | `App\Modules\OnlineRequests\Controllers\MesDemandesController@submit` |
| GET | `etudiant/documents` | Documents | `etudiant.documents.index` | `App\Http\Controllers\Etudiant\ArchiveController@documents` |
| GET | `etudiant/dossier` | Dossier | `etudiant.dossier.show` | `App\Modules\StudentDossiers\Controllers\MonDossierController@show` |
| POST | `etudiant/dossier/documents` | Créer / Documents | `etudiant.dossier.documents.store` | `App\Modules\StudentDossiers\Controllers\MonDossierController@store` |
| GET | `etudiant/dossier/documents/{document}/download` | Download | `etudiant.dossier.documents.download` | `App\Modules\StudentDossiers\Controllers\MonDossierController@download` |
| GET | `etudiant/dossier/documents/{document}/preview` | Preview | `etudiant.dossier.documents.preview` | `App\Modules\StudentDossiers\Controllers\MonDossierController@preview` |
| POST | `etudiant/dossier/documents/{document}/replace` | Créer / Replace | `etudiant.dossier.documents.replace` | `App\Modules\StudentDossiers\Controllers\MonDossierController@replace` |
| GET | `etudiant/dossier/documents/{document}/versions/{version}/download` | Download | `etudiant.dossier.documents.versions.download` | `App\Modules\StudentDossiers\Controllers\MonDossierController@downloadVersion` |
| GET | `etudiant/dossier/officiels` | Officiels | `etudiant.dossier.officiels.index` | `App\Modules\StudentDossiers\Controllers\MonDossierController@officiels` |
| GET | `etudiant/dossier/officiels/{document}/download` | Download | `etudiant.dossier.officiels.download` | `App\Modules\StudentDossiers\Controllers\MonDossierController@telechargerOfficiel` |
| GET | `etudiant/dossier/pdf/download` | Download | `etudiant.dossier.pdf.download` | `App\Modules\StudentDossiers\Controllers\StudentDossierPdfController@telecharger` |
| GET | `etudiant/dossier/pdf/fichier` | Fichier | `etudiant.dossier.pdf.fichier` | `App\Modules\StudentDossiers\Controllers\StudentDossierPdfController@fichierEtu` |
| POST | `etudiant/dossier/pdf/generer` | Créer / Generer | `etudiant.dossier.pdf.generer` | `App\Modules\StudentDossiers\Controllers\StudentDossierPdfController@genererEtu` |
| GET | `etudiant/dossier/pdf/preview` | Preview | `etudiant.dossier.pdf.preview` | `App\Modules\StudentDossiers\Controllers\StudentDossierPdfController@apercu` |
| GET | `etudiant/dossier/pdf/print` | Print | `etudiant.dossier.pdf.print` | `App\Modules\StudentDossiers\Controllers\StudentDossierPdfController@imprimer` |
| GET | `etudiant/emploi-du-temps` | Emploi du temps | `etudiant.emploi-du-temps.semaine` | `App\Modules\Schedule\Controllers\MonEmploiDuTempsController@semaine` |
| GET | `etudiant/emploi-du-temps/actualisations` | Actualisations | `etudiant.emploi-du-temps.actualisations` | `App\Modules\Schedule\Controllers\MonEmploiDuTempsController@actualisations` |
| GET | `etudiant/examens` | Examens | `etudiant.examens.index` | `App\Http\Controllers\Etudiant\ExamenController@index` |
| GET | `etudiant/examens/{candidat}/convocation` | Convocation | `etudiant.examens.convocation` | `App\Http\Controllers\Etudiant\ExamenController@convocation` |
| GET | `etudiant/finances` | Finances | `etudiant.finances.index` | `App\Modules\Finances\Controllers\FinanceController@mesFinances` |
| GET | `etudiant/historique` | Historique | `etudiant.historique.index` | `App\Http\Controllers\Etudiant\ArchiveController@historique` |
| GET | `etudiant/inscriptions` | Inscriptions | `etudiant.inscriptions.index` | `App\Http\Controllers\Etudiant\ArchiveController@inscriptions` |
| POST | `etudiant/mot-de-passe/lien` | Créer / Lien | `etudiant.mot-de-passe.envoyer` | `App\Http\Controllers\Auth\PortailMotDePasseController@envoyerLien` |
| GET | `etudiant/mot-de-passe/oublie` | Oublie | `etudiant.mot-de-passe.oublie` | `App\Http\Controllers\Auth\PortailMotDePasseController@oublie` |
| POST | `etudiant/mot-de-passe/reinitialiser` | Créer / Reinitialiser | `etudiant.mot-de-passe.enregistrer` | `App\Http\Controllers\Auth\PortailMotDePasseController@enregistrer` |
| GET | `etudiant/mot-de-passe/reinitialiser/{token}` | {token} | `etudiant.mot-de-passe.reinitialiser` | `App\Http\Controllers\Auth\PortailMotDePasseController@reinitialiser` |
| GET | `etudiant/notes` | Notes | `etudiant.notes.index` | `App\Http\Controllers\Etudiant\ArchiveController@notes` |
| GET | `etudiant/notifications` | Notifications | `etudiant.notifications.index` | `App\Http\Controllers\NotificationController@indexEtudiant` |
| POST | `etudiant/notifications/lire-tout` | Créer / Lire tout | `etudiant.notifications.lire-tout` | `App\Http\Controllers\NotificationController@toutLireEtudiant` |
| PATCH | `etudiant/notifications/{notification}/lire` | Modifier / Lire | `etudiant.notifications.lire` | `App\Http\Controllers\NotificationController@lireEtudiant` |
| GET | `etudiant/paiements` | Paiements | `etudiant.paiements.index` | `App\Modules\Finances\Controllers\PaiementInscriptionEtudiantController@index` |
| GET | `etudiant/paiements/inscriptions/{inscription}` | {inscription} | `etudiant.paiements.inscription` | `App\Modules\Finances\Controllers\PaiementInscriptionEtudiantController@show` |
| POST | `etudiant/paiements/inscriptions/{inscription}/recus` | Créer / Recus | `etudiant.paiements.recus.store` | `App\Modules\Finances\Controllers\PaiementInscriptionEtudiantController@store` |
| GET | `etudiant/paiements/inscriptions/{inscription}/recus/{recu}/apercu` | Apercu | `etudiant.paiements.recus.apercu` | `App\Modules\Finances\Controllers\PaiementInscriptionEtudiantController@apercu` |
| GET | `etudiant/paiements/inscriptions/{inscription}/recus/{recu}/telecharger` | Telecharger | `etudiant.paiements.recus.telecharger` | `App\Modules\Finances\Controllers\PaiementInscriptionEtudiantController@telecharger` |
| GET | `etudiant/parcours` | Parcours | `etudiant.parcours` | `App\Http\Controllers\Etudiant\ArchiveController@parcours` |
| GET | `etudiant/parcours/pdf` | Pdf | `etudiant.parcours.pdf` | `App\Http\Controllers\Etudiant\ArchiveController@parcoursPdf` |
| GET | `etudiant/parcours/simulations` | Simulations | `etudiant.parcours.simulations.index` | `App\Modules\ParcoursAcademique\Controllers\MaSimulationParcoursController@index` |
| POST | `etudiant/parcours/simulations` | Créer / Simulations | `etudiant.parcours.simulations.store` | `App\Modules\ParcoursAcademique\Controllers\MaSimulationParcoursController@store` |
| GET | `etudiant/parcours/simulations/creer` | Creer | `etudiant.parcours.simulations.create` | `App\Modules\ParcoursAcademique\Controllers\MaSimulationParcoursController@create` |
| GET | `etudiant/parcours/simulations/{simulation}` | {simulation} | `etudiant.parcours.simulations.show` | `App\Modules\ParcoursAcademique\Controllers\MaSimulationParcoursController@show` |
| GET | `etudiant/profil` | Profil | `etudiant.profil.show` | `App\Http\Controllers\Etudiant\ProfilController@show` |
| PATCH | `etudiant/profil` | Modifier / Profil | `etudiant.profil.update` | `App\Http\Controllers\Etudiant\ProfilController@update` |
| GET | `etudiant/profil/modifier` | Modifier | `etudiant.profil.edit` | `App\Http\Controllers\Etudiant\ProfilController@edit` |
| PATCH | `etudiant/profil/mot-de-passe` | Modifier / Mot de passe | `etudiant.profil.password` | `App\Http\Controllers\Etudiant\ProfilController@updatePassword` |
| GET | `etudiant/recepisse` | Recepisse | `etudiant.recepisse` | `App\Http\Controllers\Etudiant\RecepisseController@telecharger` |
| GET | `etudiant/recherche` | Recherche | `etudiant.recherche` | `App\Http\Controllers\RecherchePortailController@etudiant` |
| POST | `etudiant/rendez-vous` | Créer / Rendez vous | `etudiant.rendez-vous.store` | `App\Http\Controllers\Etudiant\RendezVousScolariteController@store` |
| GET | `etudiant/rendez-vous/creneaux` | Creneaux | `etudiant.rendez-vous.creneaux` | `App\Http\Controllers\Etudiant\RendezVousScolariteController@creneaux` |
| GET | `etudiant/rendez-vous/nouveau` | Nouveau | `etudiant.rendez-vous.nouveau` | `App\Http\Controllers\Etudiant\RendezVousScolariteController@nouveau` |
| POST | `etudiant/rendez-vous/{demande}/annuler` | Créer / Annuler | `etudiant.rendez-vous.annuler` | `App\Http\Controllers\Etudiant\RendezVousScolariteController@annuler` |
| POST | `etudiant/rendez-vous/{demande}/deplacer` | Créer / Deplacer | `etudiant.rendez-vous.deplacer` | `App\Http\Controllers\Etudiant\RendezVousScolariteController@deplacer` |
| GET | `etudiant/ressources` | Ressources | `etudiant.ressources.index` | `App\Modules\Enseignement\Controllers\RessourcesEtudiantController@index` |
| GET | `etudiant/ressources/{ressource}/telecharger` | Telecharger | `etudiant.ressources.telecharger` | `App\Modules\Enseignement\Controllers\RessourcesEtudiantController@telecharger` |
| GET | `etudiant/suivi` | Suivi | `etudiant.suivi` | `App\Http\Controllers\Etudiant\SuiviDossierController@index` |
| GET | `etudiant/tableau-de-bord` | Tableau de bord | `etudiant.dashboard` | `App\Http\Controllers\Etudiant\TableauDeBordController@index` |

---

## 4. API REST (api/v1)

| Méthode | URL | Tâche | Nom de route | Contrôleur / Action |
|---------|-----|-------|----------------|-------------------|
| POST | `api/v1/assignments/simulate` | Créer / Simulate | `api.v1.assignments.simulate` | `App\Http\Controllers\Api\V1\AssignmentApiController@simuler` |
| GET | `api/v1/assignments/simulations` | Simulations | `api.v1.assignments.index` | `App\Http\Controllers\Api\V1\AssignmentApiController@index` |
| GET | `api/v1/assignments/simulations/{simulation}` | {simulation} | `api.v1.assignments.show` | `App\Http\Controllers\Api\V1\AssignmentApiController@show` |
| POST | `api/v1/assignments/simulations/{simulation}/groups/{groupe}/confirm` | Créer / Confirm | `api.v1.assignments.confirm` | `App\Http\Controllers\Api\V1\AssignmentApiController@confirmer` |
| GET | `api/v1/assignments/simulations/{simulation}/students/{student}` | {student} | `api.v1.assignments.student` | `App\Http\Controllers\Api\V1\AssignmentApiController@student` |
| GET | `api/v1/data/{entite}/export/{format}` | {format} | `api.v1.data.export` | `App\Http\Controllers\Api\V1\DataExchangeApiController@exporter` |
| POST | `api/v1/data/{entite}/import/confirm` | Créer / Confirm | `api.v1.data.import.confirm` | `App\Http\Controllers\Api\V1\DataExchangeApiController@confirmer` |
| POST | `api/v1/data/{entite}/import/preview` | Créer / Preview | `api.v1.data.import.preview` | `App\Http\Controllers\Api\V1\DataExchangeApiController@preview` |
| POST | `api/v1/exports` | Créer / Exports | `api.v1.exports.store` | `App\Http\Controllers\Api\V1\ExportApiController@store` |
| GET | `api/v1/exports/{report}` | {report} | `api.v1.exports.show` | `App\Http\Controllers\Api\V1\ExportApiController@show` |
| GET | `api/v1/exports/{report}/download` | Download | `api.v1.exports.download` | `App\Http\Controllers\Api\V1\ExportApiController@download` |
| GET | `api/v1/health` | Health | `api.v1.health` | `Closure` |
| GET | `api/v1/import-templates` | Import templates | `api.v1.templates.index` | `App\Http\Controllers\Api\V1\ImportTemplateApiController@index` |
| GET | `api/v1/import-templates/{type}` | {type} | `api.v1.templates.show` | `App\Http\Controllers\Api\V1\ImportTemplateApiController@show` |
| GET | `api/v1/import-templates/{type}/download` | Download | `api.v1.templates.download` | `App\Http\Controllers\Api\V1\ImportTemplateApiController@download` |
| POST | `api/v1/imports` | Créer / Imports | `api.v1.imports.store` | `App\Http\Controllers\Api\V1\ImportApiController@store` |
| GET | `api/v1/imports/{report}` | {report} | `api.v1.imports.show` | `App\Http\Controllers\Api\V1\ImportApiController@show` |
| POST | `api/v1/imports/{report}/confirm` | Créer / Confirm | `api.v1.imports.confirm` | `App\Http\Controllers\Api\V1\ImportApiController@confirm` |
| GET | `api/v1/ready` | Ready | `api.v1.ready` | `App\Http\Controllers\Api\V1\ReadyController` |
| GET | `api/v1/referentiel/maquettes` | Maquettes | `api.v1.referentiel.maquettes` | `App\Http\Controllers\Api\V1\ReferentielApiController@maquettes` |
| GET | `api/v1/referentiel/matieres` | Matieres | `api.v1.referentiel.matieres` | `App\Http\Controllers\Api\V1\ReferentielApiController@matieres` |
| GET | `api/v1/referentiel/niveaux` | Niveaux | `api.v1.referentiel.niveaux` | `App\Http\Controllers\Api\V1\ReferentielApiController@niveaux` |
| GET | `api/v1/referentiel/programmes` | Programmes | `api.v1.referentiel.programmes` | `App\Http\Controllers\Api\V1\ReferentielApiController@programmes` |
| GET | `api/v1/referentiel/semestres` | Semestres | `api.v1.referentiel.semestres` | `App\Http\Controllers\Api\V1\ReferentielApiController@semestres` |
| GET | `api/v1/referentiel/ues` | Ues | `api.v1.referentiel.ues` | `App\Http\Controllers\Api\V1\ReferentielApiController@ues` |
| GET | `api/v1/registrations` | Registrations | `api.v1.registrations.index` | `App\Http\Controllers\Api\V1\RegistrationApiController@index` |
| POST | `api/v1/registrations/reenroll/{student}` | Créer / {student} | `api.v1.registrations.reenroll` | `App\Http\Controllers\Api\V1\RegistrationApiController@reenroll` |
| GET | `api/v1/registrations/{registration}` | {registration} | `api.v1.registrations.show` | `App\Http\Controllers\Api\V1\RegistrationApiController@show` |
| POST | `api/v1/schedules/import/confirm` | Créer / Confirm | `api.v1.schedules.import.confirm` | `App\Http\Controllers\Api\V1\ScheduleImportApiController@confirmer` |
| POST | `api/v1/schedules/import/preview` | Créer / Preview | `api.v1.schedules.import.preview` | `App\Http\Controllers\Api\V1\ScheduleImportApiController@preview` |
| GET | `api/v1/student-dossiers` | Student dossiers | `api.v1.student-dossiers.index` | `App\Modules\StudentDossiers\Controllers\Api\StudentDossierApiController@index` |
| GET | `api/v1/student-dossiers/{dossier}` | {dossier} | `api.v1.student-dossiers.show` | `App\Modules\StudentDossiers\Controllers\Api\StudentDossierApiController@show` |
| GET | `api/v1/student-dossiers/{dossier}/documents` | Documents | `api.v1.student-dossiers.documents` | `App\Modules\StudentDossiers\Controllers\Api\StudentDossierApiController@documents` |
| GET | `api/v1/students` | Students | `api.v1.students.index` | `App\Http\Controllers\Api\V1\StudentApiController@index` |
| GET | `api/v1/students/{student}` | {student} | `api.v1.students.show` | `App\Http\Controllers\Api\V1\StudentApiController@show` |
| GET | `api/v1/students/{student}/academic-progression` | Academic progression | `api.v1.students.academic-progression` | `App\Http\Controllers\Api\V1\RegistrationApiController@progression` |
| GET | `api/v1/students/{student}/academic-record` | Academic record | `api.v1.students.record` | `App\Http\Controllers\Api\V1\StudentApiController@academicRecord` |
| GET | `api/v1/students/{student}/documents` | Documents | `api.v1.students.documents` | `App\Http\Controllers\Api\V1\StudentApiController@documents` |
| GET | `api/v1/students/{student}/grades` | Grades | `api.v1.students.grades` | `App\Http\Controllers\Api\V1\StudentApiController@grades` |
| GET | `api/v1/students/{student}/parcours` | Parcours | `api.v1.students.parcours` | `App\Modules\ParcoursAcademique\Controllers\ParcoursApiController@parcours` |
| GET | `api/v1/students/{student}/parcours-credits` | Parcours credits | `api.v1.students.parcours-credits` | `App\Modules\ParcoursAcademique\Controllers\ParcoursApiController@credits` |
| GET | `api/v1/students/{student}/parcours-risques` | Parcours risques | `api.v1.students.parcours-risques` | `App\Modules\ParcoursAcademique\Controllers\ParcoursApiController@risques` |
| GET | `api/v1/students/{student}/parcours-simulations` | Parcours simulations | `api.v1.students.parcours-simulations` | `App\Modules\ParcoursAcademique\Controllers\ParcoursApiController@simulations` |
| GET | `api/v1/students/{student}/reenrollment-context` | Reenrollment context | `api.v1.students.reenrollment-context` | `App\Http\Controllers\Api\V1\RegistrationApiController@reenrollmentContext` |
| GET | `api/v1/students/{student}/registration-period` | Registration period | `api.v1.students.registration-period` | `App\Http\Controllers\Api\V1\RegistrationApiController@periodeStatut` |
| GET | `api/v1/students/{student}/results` | Results | `api.v1.students.results` | `App\Http\Controllers\Api\V1\StudentApiController@results` |
| GET | `api/v1/students/{student}/timeline` | Timeline | `api.v1.students.timeline` | `App\Http\Controllers\Api\V1\StudentApiController@timeline` |
| GET | `api/v1/students/{student}/verification` | Verification | `api.v1.students.verification` | `App\Http\Controllers\Api\V1\StudentApiController@verification` |
| POST | `api/v1/students/{student}/verification` | Créer / Verification | `api.v1.students.verification.run` | `App\Http\Controllers\Api\V1\StudentApiController@runVerification` |
| GET | `api/v1/students/{student}/verification/anomalies` | Anomalies | `api.v1.students.verification.anomalies` | `App\Http\Controllers\Api\V1\StudentApiController@anomalies` |
| GET | `api/v1/version` | Version | `api.v1.version` | `App\Modules\Versioning\Controllers\Api\VersioningApiController@version` |
| GET | `api/v1/version/compatibility` | Compatibility | `api.v1.version.compatibility` | `App\Modules\Versioning\Controllers\Api\VersioningApiController@compatibility` |
| GET | `api/v1/version/drift` | Drift | `api.v1.version.drift` | `App\Modules\Versioning\Controllers\Api\VersioningApiController@drift` |
| GET | `api/v1/version/manifest` | Manifest | `api.v1.version.manifest` | `App\Modules\Versioning\Controllers\Api\VersioningApiController@manifest` |
| GET | `api/v1/version/status` | Status | `api.v1.version.status` | `App\Modules\Versioning\Controllers\Api\VersioningApiController@status` |
| POST | `api/v1/webhooks/external-system` | Créer / External system | `api.v1.webhooks.receive` | `App\Http\Controllers\Api\V1\WebhookController@receive` |

---

## 5. Inscriptions Publiques

| Méthode | URL | Tâche | Nom de route | Contrôleur / Action |
|---------|-----|-------|----------------|-------------------|
| POST | `inscription` | Créer /  | `inscription.store` | `App\Http\Controllers\InscriptionController@store` |
| GET | `inscription/existant` | Existant | `inscription.existant.accueil` | `App\Http\Controllers\DossierExistantController@accueil` |
| POST | `inscription/existant` | Créer / Existant | `inscription.existant-store` | `App\Http\Controllers\DossierExistantController@enregistrer` |
| POST | `inscription/existant/autonome` | Créer / Autonome | `inscription.existant.autonome` | `App\Http\Controllers\DossierExistantController@demanderAutonome` |
| POST | `inscription/existant/autonome/confirmer` | Créer / Confirmer | `inscription.existant.autonome.confirmer` | `App\Http\Controllers\DossierExistantController@confirmerAutonome` |
| GET | `inscription/existant/confirmation` | Confirmation | `inscription.existant.confirmation` | `App\Http\Controllers\DossierExistantController@confirmation` |
| GET | `inscription/existant/donnees` | Donnees | `inscription.existant.donnees` | `App\Http\Controllers\DossierExistantController@donnees` |
| POST | `inscription/existant/email` | Créer / Email | `inscription.existant.email` | `App\Http\Controllers\DossierExistantController@demanderEmail` |
| POST | `inscription/existant/email/confirmer` | Créer / Confirmer | `inscription.existant.email.confirmer` | `App\Http\Controllers\DossierExistantController@confirmerEmail` |
| POST | `inscription/existant/email/confirmer-nouvelle` | Créer / Confirmer nouvelle | `inscription.existant.email.confirmer-nouvelle` | `App\Http\Controllers\DossierExistantController@confirmerNouvelleAdresse` |
| GET | `inscription/existant/formulaire` | Formulaire | `inscription.existant.formulaire` | `App\Http\Controllers\DossierExistantController@formulaire` |
| POST | `inscription/existant/recherche` | Créer / Recherche | `inscription.existant.recherche` | `App\Http\Controllers\DossierExistantController@rechercher` |
| POST | `inscription/existant/verification` | Créer / Verification | `inscription.existant.verification` | `App\Http\Controllers\DossierExistantController@verifier` |
| GET | `inscription/faq` | Faq | `inscription.faq` | `App\Http\Controllers\InscriptionController@faq` |
| POST | `inscription/faq/assistant` | Créer / Assistant | `inscription.faq.assistant` | `App\Http\Controllers\InscriptionController@interrogerAssistantFaq` |
| POST | `inscription/identifier` | Créer / Identifier | `inscription.identifier` | `App\Http\Controllers\InscriptionController@identifier` |
| POST | `inscription/identity/scan` | Créer / Scan | `inscription.identity.scan` | `App\Http\Controllers\InscriptionController@scanIdentity` |
| GET | `inscription/nouvelle` | Nouvelle | `inscription.create` | `App\Http\Controllers\InscriptionController@create` |
| GET | `inscription/orientation-options` | Orientation options | `inscription.orientation-options` | `App\Http\Controllers\InscriptionController@optionsOrientation` |
| GET | `inscription/periode-etat` | Periode etat | `inscription.periode-etat` | `App\Http\Controllers\InscriptionController@etatPeriode` |
| GET | `inscription/pieces-requises` | Pieces requises | `inscription.pieces-requises` | `App\Http\Controllers\InscriptionController@checklistDocuments` |
| GET | `inscription/success` | Success | `inscription.success` | `App\Http\Controllers\InscriptionController@success` |
| GET | `inscription/suivi` | Suivi | `inscription.suivi` | `App\Http\Controllers\SuiviPublicController@index` |
| POST | `inscription/suivi` | Créer / Suivi | `inscription.suivi.rechercher` | `App\Http\Controllers\SuiviPublicController@rechercher` |
| POST | `inscription/verifier-cin` | Créer / Verifier cin | `inscription.verifierCin` | `App\Http\Controllers\InscriptionController@verifierCin` |

---

## 6. Livewire (ressources assets)

| Méthode | URL | Tâche | Nom de route | Contrôleur / Action |
|---------|-----|-------|----------------|-------------------|
| POST | `livewire/update` | Créer / Update | `livewire.update` | `Livewire\Mechanisms\HandleRequests\HandleRequests@handleUpdate` |

---

## 7. Sanctum (CSRF)

| Méthode | URL | Tâche | Nom de route | Contrôleur / Action |
|---------|-----|-------|----------------|-------------------|
| GET | `sanctum/csrf-cookie` | Csrf cookie | `sanctum.csrf-cookie` | `Laravel\Sanctum\Http\Controllers\CsrfCookieController@show` |

---

## 8. Santé applicative

| Méthode | URL | Tâche | Nom de route | Contrôleur / Action |
|---------|-----|-------|----------------|-------------------|
| GET | `up` |  | `-` | `Closure` |

---

## 10. Vérification de documents

| Méthode | URL | Tâche | Nom de route | Contrôleur / Action |
|---------|-----|-------|----------------|-------------------|
| GET | `verify/{code}` | {code} | `documents.verify` | `App\Http\Controllers\VerifyDocumentController@verifier` |

---

## 11. Hors-ligne

| Méthode | URL | Tâche | Nom de route | Contrôleur / Action |
|---------|-----|-------|----------------|-------------------|
| GET | `hors-ligne` |  | `hors-ligne` | `\Illuminate\Routing\ViewController` |

---

## 12. Accueil / Racine

| Méthode | URL | Tâche | Nom de route | Contrôleur / Action |
|---------|-----|-------|----------------|-------------------|
| GET | `/` | Page d’accueil | `accueil` | `App\Http\Controllers\DossierExistantController@accueil` |

---

## Livewire-ed63f1ff

| Méthode | URL | Tâche | Nom de route | Contrôleur / Action |
|---------|-----|-------|----------------|-------------------|
| GET | `livewire-ed63f1ff/css/{component}.css` | {component}.css | `-` | `Closure` |
| GET | `livewire-ed63f1ff/css/{component}.global.css` | {component}.global.css | `-` | `Closure` |
| GET | `livewire-ed63f1ff/js/{component}.js` | {component}.js | `-` | `Closure` |
| GET | `livewire-ed63f1ff/livewire.csp.min.js.map` | Livewire.csp.min.js.map | `-` | `Livewire\Mechanisms\FrontendAssets\FrontendAssets@cspMaps` |
| GET | `livewire-ed63f1ff/livewire.min.js` | Livewire.min.js | `-` | `Livewire\Mechanisms\FrontendAssets\FrontendAssets@returnJavaScriptAsFile` |
| GET | `livewire-ed63f1ff/livewire.min.js.map` | Livewire.min.js.map | `-` | `Livewire\Mechanisms\FrontendAssets\FrontendAssets@maps` |
| GET | `livewire-ed63f1ff/preview-file/{filename}` | {filename} | `livewire.preview-file` | `Livewire\Features\SupportFileUploads\FilePreviewController@handle` |
| POST | `livewire-ed63f1ff/update` | Créer / Update | `default-livewire.update` | `Livewire\Mechanisms\HandleRequests\HandleRequests@handleUpdate` |
| POST | `livewire-ed63f1ff/upload-file` | Créer / Upload file | `livewire.upload-file` | `Livewire\Features\SupportFileUploads\FileUploadController@handle` |

---

## Verifier

| Méthode | URL | Tâche | Nom de route | Contrôleur / Action |
|---------|-----|-------|----------------|-------------------|
| GET | `verifier/{reference}` | {reference} | `documents.verifier` | `App\Http\Controllers\VerifyDocumentController@verifier` |

---

*Total : 952 routes recensées.*

> Ce document est un inventaire technique généré à partir des routes déclarées. Il ne
> modifie aucun code, aucune base de données et ne constitue pas une modification métier.
