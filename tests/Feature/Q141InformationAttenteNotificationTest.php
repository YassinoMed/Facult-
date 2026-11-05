<?php

namespace Tests\Feature;

use App\Models\AppNotification;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\HistoriqueAcademique;
use App\Services\EcheanceDerogationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Q141 — information factuelle de l'attente prolongée.
 *
 * La réponse confirmée exige une information factuelle, sans délai promis ni
 * décision préjugée. Le contrat vérifié ici est celui du système central de
 * notifications : bon destinataire, contenu fidèle, preuve d'envoi et
 * anti-doublon, sans aucune mutation de statut.
 */
class Q141InformationAttenteNotificationTest extends TestCase
{
    use RefreshDatabase;

    private EcheanceDerogationService $service;

    private Etudiant $etudiant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(EcheanceDerogationService::class);

        $faculte = Faculte::create([
            'code' => 'FST',
            'nom' => 'Faculté des Sciences et Techniques',
        ]);

        $this->etudiant = Etudiant::create([
            'cin' => '71460022',
            'nom' => 'Idrissi',
            'prenom' => 'Salma',
            'email' => 'salma.idrissi@universite.ac.ma',
            'date_naissance' => '2003-02-11',
            'sexe' => 'F',
            'faculte_id' => $faculte->id,
            'niveau' => 'L3',
            'annee_academique' => '2026-2027',
            'adresse' => 'Rabat Agdal',
            'ville' => 'Rabat',
            'code_postal' => '10090',
            'annee_bac' => '2022',
            'password' => 'Etudiant@123',
        ]);
        $this->etudiant->definirStatut('valide');
    }

    public function test_q141_notification_envoyee_au_bon_destinataire_sans_delai_ni_prejuge(): void
    {
        $demande = $this->service->deposerDemandeDerogation(
            $this->etudiant,
            'Demande de dérogation',
            'Motif circonstancié et documenté.'
        );

        $resultat = $this->service->informerAttenteProlongee(
            $this->etudiant,
            $demande['demande_id'],
            demarchesEntreprises: ['Relance du 2026-10-25'],
            reponseRecue: false
        );

        $this->assertSame('enregistree', $resultat['preuve_envoi']);
        $this->assertSame('q141-v1', $resultat['template_version']);
        $this->assertNotNull($resultat['notification_id']);

        $notification = AppNotification::findOrFail($resultat['notification_id']);
        $this->assertSame($this->etudiant->id, $notification->etudiant_id);
        $this->assertSame('derogation_attente_prolongee', $notification->type);
        $this->assertStringContainsString('clarification de compétence est en attente', $notification->message);
        $this->assertStringContainsString('Aucune réponse n’a été reçue à ce jour', $notification->message);
        $this->assertStringNotContainsString('jours', $notification->message);
        $this->assertStringNotContainsString('semaines', $notification->message);
        $this->assertNull($notification->data['delai_promis']);
        $this->assertFalse($notification->data['decision_prejugee']);
        $this->assertSame($demande['demande_id'], $notification->data['demande_id']);
        $this->assertSame('q141-v1', $notification->data['template_version']);
    }

    public function test_q141_meme_information_rejouee_ne_duplique_pas_la_notification(): void
    {
        $demande = $this->service->deposerDemandeDerogation(
            $this->etudiant,
            'Demande de dérogation',
            'Motif circonstancié et documenté.'
        );

        $premier = $this->service->informerAttenteProlongee(
            $this->etudiant,
            $demande['demande_id'],
            demarchesEntreprises: ['Relance du 2026-10-25']
        );
        $second = $this->service->informerAttenteProlongee(
            $this->etudiant,
            $demande['demande_id'],
            demarchesEntreprises: ['Relance du 2026-10-25']
        );

        $this->assertSame($premier['notification_id'], $second['notification_id']);
        $this->assertSame(1, AppNotification::where('etudiant_id', $this->etudiant->id)
            ->where('type', 'derogation_attente_prolongee')
            ->count());
    }

    public function test_q141_nouvelle_demarche_produit_une_information_distincte(): void
    {
        $demande = $this->service->deposerDemandeDerogation(
            $this->etudiant,
            'Demande de dérogation',
            'Motif circonstancié et documenté.'
        );

        $premier = $this->service->informerAttenteProlongee(
            $this->etudiant,
            $demande['demande_id'],
            demarchesEntreprises: ['Relance du 2026-10-25']
        );
        $second = $this->service->informerAttenteProlongee(
            $this->etudiant,
            $demande['demande_id'],
            demarchesEntreprises: ['Relance du 2026-10-25', 'Saisine du doyen du 2026-10-28']
        );

        $this->assertNotSame($premier['notification_id'], $second['notification_id']);
        $this->assertSame(2, AppNotification::where('etudiant_id', $this->etudiant->id)
            ->where('type', 'derogation_attente_prolongee')
            ->count());
    }

    public function test_q141_aucune_mutation_de_statut_et_information_journalisee(): void
    {
        $demande = $this->service->deposerDemandeDerogation(
            $this->etudiant,
            'Demande de dérogation',
            'Motif circonstancié et documenté.'
        );

        $resultat = $this->service->informerAttenteProlongee(
            $this->etudiant,
            $demande['demande_id'],
            statutInstructionActuel: 'en_attente_etablissement_competence'
        );

        $this->assertFalse($resultat['statut_modifie']);
        $this->assertSame('en_attente_etablissement_competence', $resultat['statut_instruction']);
        $this->assertNull($resultat['delai_promis']);
        $this->assertFalse($resultat['decision_prejugee']);

        $audit = HistoriqueAcademique::where('action', 'information_attente_prolongee')->firstOrFail();
        $donnees = json_decode((string) $audit->nouvelle_valeur, true);
        $this->assertFalse($donnees['statut_modifie']);
        $this->assertNull($donnees['delai_promis']);
        $this->assertFalse($donnees['decision_prejugee']);
        $this->assertSame('q141-v1', $donnees['template_version']);
    }

    public function test_q141_information_ne_part_pas_vers_un_autre_etudiant(): void
    {
        $autreFaculte = Faculte::create([
            'code' => 'FLSH',
            'nom' => 'Faculté des Lettres et Sciences Humaines',
        ]);
        $autre = Etudiant::create([
            'cin' => '71460023',
            'nom' => 'Alaoui',
            'prenom' => 'Nadia',
            'email' => 'nadia.alaoui@universite.ac.ma',
            'date_naissance' => '2002-09-03',
            'sexe' => 'F',
            'faculte_id' => $autreFaculte->id,
            'niveau' => 'L1',
            'annee_academique' => '2026-2027',
            'adresse' => 'Fès Centre',
            'ville' => 'Fès',
            'code_postal' => '30000',
            'annee_bac' => '2021',
            'password' => 'Etudiant@123',
        ]);
        $autre->definirStatut('valide');

        $demande = $this->service->deposerDemandeDerogation(
            $this->etudiant,
            'Demande de dérogation',
            'Motif circonstancié et documenté.'
        );

        $resultat = $this->service->informerAttenteProlongee($this->etudiant, $demande['demande_id']);

        $this->assertSame(0, AppNotification::where('etudiant_id', $autre->id)->count());
        $this->assertSame(1, AppNotification::where('etudiant_id', $this->etudiant->id)
            ->where('id', $resultat['notification_id'])
            ->count());
    }
}
