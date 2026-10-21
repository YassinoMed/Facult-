<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\HistoriqueAcademique;
use App\Models\InscriptionAcademique;
use App\Models\SituationEtudiant;
use App\Models\User;
use App\Modules\Referentiel\Models\Niveau;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\Schedule\Models\Group;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\ActAsScolarite;
use Tests\TestCase;

/**
 * Modification administrative d'un étudiant : fiche + inscription courante,
 * traçabilité (historique + situation), périmètre de faculté, unicité,
 * propagation LMD et exclusion du statut / groupe / mot de passe.
 */
class AdminModificationEtudiantTest extends TestCase
{
    use ActAsScolarite;
    use RefreshDatabase;

    private Faculte $faculte;

    private Programme $programme;

    private Niveau $niveau;

    private Group $groupe;

    private AnneeUniversitaire $annee;

    private Etudiant $etudiant;

    private InscriptionAcademique $inscription;

    protected function setUp(): void
    {
        parent::setUp();

        config(['modules.finances.active' => true]);

        $this->faculte = Faculte::create([
            'nom' => 'Faculté des Sciences',
            'code' => 'FS',
            'active' => true,
        ]);

        $this->programme = Programme::create([
            'faculte_id' => $this->faculte->id,
            'code' => 'GI',
            'nom' => 'Génie Informatique',
            'statut' => Programme::STATUT_ACTIF,
        ]);

        $this->niveau = Niveau::create([
            'programme_id' => $this->programme->id,
            'code' => 'L1',
            'nom' => 'Licence 1',
            'ordre' => 1,
            'actif' => true,
        ]);

        $this->annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027',
            'date_debut' => '2026-09-01',
            'date_fin' => '2027-08-31',
            'active' => true,
        ]);

        $this->groupe = Group::create([
            'code' => 'GI-L1-A',
            'nom' => 'GI L1 A',
            'faculte_id' => $this->faculte->id,
            'niveau' => 'L1',
            'filiere' => 'GI',
            'programme_id' => $this->programme->id,
            'niveau_id' => $this->niveau->id,
            'annee_universitaire_id' => $this->annee->id,
            'capacite' => 30,
            'actif' => true,
        ]);

        $this->etudiant = $this->creerEtudiant();
        $this->inscription = $this->creerInscription();

        $this->scolarite('super_admin');
    }

    private function creerEtudiant(array $surcharges = []): Etudiant
    {
        return Etudiant::create(array_merge([
            'cin' => 'AB123456',
            'identifiant_national' => 'MENS-001',
            'nom' => 'El Amrani',
            'prenom' => 'Yassine',
            'email' => 'yassine.elamrani@example.test',
            'telephone' => '+212 600 000 000',
            'date_naissance' => '2004-03-15',
            'sexe' => 'M',
            'faculte_id' => $this->faculte->id,
            'groupe_id' => $this->groupe->id,
            'programme_id' => $this->programme->id,
            'niveau_id' => $this->niveau->id,
            'niveau' => 'L1',
            'annee_academique' => '2026-2027',
            'adresse' => '12 rue des Écoles',
            'ville' => 'Casablanca',
            'code_postal' => '20000',
            'annee_bac' => '2024',
            'mention_bac' => 'Bien',
            'password' => Hash::make('Motdepasse@123'),
        ], $surcharges));
    }

    private function creerInscription(?Etudiant $etudiant = null): InscriptionAcademique
    {
        $etudiant = $etudiant ?? $this->etudiant;

        return InscriptionAcademique::create([
            'etudiant_id' => $etudiant->id,
            'annee_universitaire_id' => $this->annee->id,
            'programme_id' => $this->programme->id,
            'niveau_id' => $this->niveau->id,
            'niveau' => 'L1',
            'filiere' => 'Génie Informatique',
            'groupe' => $this->groupe->code,
            'statut' => 'valide',
            'type' => 'premiere',
            'regime' => 'initiale',
            'paye' => false,
        ]);
    }

    /**
     * @param  array<string, mixed>  $surcharges
     * @return array<string, mixed>
     */
    private function donnees(array $surcharges = []): array
    {
        return array_merge([
            'nom' => 'El Amrani',
            'prenom' => 'Yassine',
            'cin' => 'AB123456',
            'identifiant_national' => 'MENS-001',
            'email' => 'yassine.elamrani@example.test',
            'telephone' => '+212 600 000 000',
            'date_naissance' => '2004-03-15',
            'sexe' => 'M',
            'faculte_id' => $this->faculte->id,
            'programme_id' => $this->programme->id,
            'niveau_id' => $this->niveau->id,
            'type_inscription' => 'premiere',
            'regime' => 'initiale',
            'adresse' => '12 rue des Écoles',
            'ville' => 'Casablanca',
            'code_postal' => '20000',
            'annee_bac' => '2024',
            'mention_bac' => 'Bien',
            'motif_modification' => 'Faute de frappe sur le CIN relevée au guichet.',
            'paye' => '0',
        ], $surcharges);
    }

    private function nombreTraces(): int
    {
        return HistoriqueAcademique::where('etudiant_id', $this->etudiant->id)->count()
            + SituationEtudiant::where('etudiant_id', $this->etudiant->id)->count();
    }

    /**
     * Contenu d'un `<select id="...">` du HTML rendu.
     */
    private function optionsSelect(string $html, string $id): string
    {
        $motif = '/<select\b[^>]*\bid="'.preg_quote($id, '/').'"[^>]*>(.*?)<\/select>/s';

        preg_match($motif, $html, $captures);

        return $captures[1] ?? '';
    }

    /**
     * Balise `<select id="...">` complète (attributs compris).
     */
    private function baliseSelect(string $html, string $id): string
    {
        $motif = '/<select\b[^>]*\bid="'.preg_quote($id, '/').'"[^>]*>/s';

        preg_match($motif, $html, $captures);

        return $captures[0] ?? '';
    }

    public function test_la_page_de_modification_est_accessible_aux_agents_habilites(): void
    {
        $this->get(route('admin.etudiants.modifier', $this->etudiant))
            ->assertOk()
            ->assertSee('Modifier l’étudiant')
            ->assertSee('Identité de l’étudiant')
            ->assertSee('Rattachement académique')
            ->assertSee('Inscription courante')
            ->assertSee('Motif de la modification')
            ->assertSee('name="motif_modification"', false)
            ->assertSee('value="AB123456"', false)
            ->assertSee('value="yassine.elamrani@example.test"', false)
            ->assertSee('value="12 rue des Écoles"', false)
            ->assertSee('Enregistrer les modifications');
    }

    public function test_le_select_groupe_est_en_lecture_seule_et_presente_le_groupe_courant(): void
    {
        $html = $this->get(route('admin.etudiants.modifier', $this->etudiant))
            ->assertOk()
            ->getContent();

        $balise = $this->baliseSelect($html, 'groupe_id');

        // Le select groupe reste en lecture seule : le workflow
        // d'affectation est le seul propriétaire du groupe.
        $this->assertStringContainsString('disabled', $balise);
        $this->assertStringContainsString('data-lecture-seule="1"', $balise);

        // La cascade JS ne doit JAMAIS reconstruire ce select : sinon elle
        // le réactiverait (le groupe deviendrait éditable alors que le
        // service ignore `groupe_id` → choix silencieusement perdu) et le
        // groupe courant disparaîtrait de l'écran.
        $this->assertSame(0, preg_match('/remplir\(\s*groupe\b/', $html));

        // Le groupe courant reste affiché et PRÉ-SÉLECTIONNÉ.
        $options = $this->optionsSelect($html, 'groupe_id');

        $this->assertStringContainsString('value="'.$this->groupe->id.'"', $options);
        $this->assertMatchesRegularExpression(
            '/<option value="'.preg_quote((string) $this->groupe->id, '/').'"[^>]*\bselected\b/',
            $options
        );
    }

    public function test_un_agent_sans_permission_obtient_un_403(): void
    {
        $consultation = User::creerCompteBackOffice([
            'name' => 'Consultation Test',
            'email' => 'consultation@example.test',
            'password' => Hash::make('Motdepasse@123'),
        ], 'consultation');

        $this->actingAs($consultation);

        $this->get(route('admin.etudiants.modifier', $this->etudiant))->assertForbidden();
        $this->patch(route('admin.etudiants.update', $this->etudiant), $this->donnees([
            'nom' => 'Nom Interdit',
        ]))->assertForbidden();

        $this->assertSame('El Amrani', $this->etudiant->fresh()->nom);
        $this->assertSame(0, $this->nombreTraces());
    }

    public function test_un_agent_d_une_autre_faculte_obtient_un_403(): void
    {
        $autreFaculte = Faculte::create([
            'nom' => 'Faculté de Médecine',
            'code' => 'FM',
            'active' => true,
        ]);

        $adminFaculte = User::creerCompteBackOffice([
            'name' => 'Admin Faculté',
            'email' => 'admin.faculte@example.test',
            'password' => Hash::make('Motdepasse@123'),
            'faculte_id' => $autreFaculte->id,
        ], 'admin_faculte');

        $this->actingAs($adminFaculte);

        $this->get(route('admin.etudiants.modifier', $this->etudiant))->assertForbidden();
        $this->patch(route('admin.etudiants.update', $this->etudiant), $this->donnees([
            'nom' => 'Nom Interdit',
        ]))->assertForbidden();

        $this->assertSame('El Amrani', $this->etudiant->fresh()->nom);
        $this->assertSame(0, $this->nombreTraces());
    }

    public function test_le_bouton_modifier_est_visible_sur_la_fiche(): void
    {
        $this->get(route('admin.etudiants.show', $this->etudiant))
            ->assertOk()
            ->assertSee(route('admin.etudiants.modifier', $this->etudiant), false)
            ->assertSee('Modifier');
    }

    public function test_le_bouton_modifier_est_visible_dans_le_registre(): void
    {
        $this->get(route('admin.etudiants.index'))
            ->assertOk()
            ->assertSee(route('admin.etudiants.modifier', $this->etudiant), false);
    }

    public function test_une_modification_identite_est_enregistree_et_tracee(): void
    {
        $reponse = $this->patch(route('admin.etudiants.update', $this->etudiant), $this->donnees([
            'nom' => 'El Amrani Alaoui',
            'cin' => 'CD654321',
            'telephone' => '+212 611 111 111',
            'date_naissance' => '2004-04-20',
            'identifiant_national' => 'MENS-002',
        ]));

        $reponse->assertRedirect(route('admin.etudiants.show', $this->etudiant));
        $reponse->assertSessionHas('success');

        $etudiant = $this->etudiant->fresh();

        $this->assertSame('El Amrani Alaoui', $etudiant->nom);
        $this->assertSame('CD654321', $etudiant->cin);
        $this->assertSame('+212 611 111 111', $etudiant->telephone);
        $this->assertSame('2004-04-20', $etudiant->date_naissance?->format('Y-m-d'));
        $this->assertSame('MENS-002', $etudiant->identifiant_national);

        $this->assertDatabaseHas('historique_academique', [
            'etudiant_id' => $this->etudiant->id,
            'action' => 'modification_dossier',
            'entite' => 'etudiant',
            'entite_id' => $this->etudiant->id,
        ]);

        $this->assertDatabaseHas('situations_etudiant', [
            'etudiant_id' => $this->etudiant->id,
            'type' => 'note',
            'ancienne_valeur' => 'dossier',
            'nouvelle_valeur' => 'dossier',
            'motif' => 'Faute de frappe sur le CIN relevée au guichet.',
        ]);

        // Aucune PII dans le journal : uniquement les NOMS des champs
        // modifiés et des valeurs non nominatives. Les valeurs contrôlées
        // ici sont bien celles RÉELLEMENT modifiées par ce test.
        $historique = HistoriqueAcademique::where('etudiant_id', $this->etudiant->id)
            ->where('action', 'modification_dossier')
            ->latest('id')
            ->firstOrFail();

        $journal = (string) $historique->nouvelle_valeur;

        $this->assertStringContainsString('cin', $journal);
        $this->assertStringContainsString('nom', $journal);
        $this->assertStringContainsString('telephone', $journal);
        $this->assertStringContainsString('date_naissance', $journal);
        $this->assertStringContainsString('identifiant_national', $journal);
        $this->assertStringNotContainsString('CD654321', $journal);
        $this->assertStringNotContainsString('El Amrani Alaoui', $journal);
        $this->assertStringNotContainsString('yassine.elamrani@example.test', $journal);
        $this->assertStringNotContainsString('+212 611 111 111', $journal);
        $this->assertStringNotContainsString('2004-04-20', $journal);
        $this->assertStringNotContainsString('MENS-002', $journal);
    }

    public function test_le_motif_de_modification_est_obligatoire(): void
    {
        $this->patch(route('admin.etudiants.update', $this->etudiant), $this->donnees([
            'motif_modification' => '',
            'nom' => 'Nom Jamais Enregistre',
        ]))->assertSessionHasErrors('motif_modification');

        $this->assertSame('El Amrani', $this->etudiant->fresh()->nom);
        $this->assertSame(0, $this->nombreTraces());
    }

    public function test_un_cin_deja_utilise_par_un_autre_etudiant_est_refuse(): void
    {
        $this->creerEtudiant([
            'cin' => 'XY987654',
            'email' => 'autre.cin@example.test',
        ]);

        $this->patch(route('admin.etudiants.update', $this->etudiant), $this->donnees([
            'cin' => 'XY987654',
            'nom' => 'Nom Jamais Enregistre',
        ]))->assertSessionHasErrors('cin');

        $this->assertSame('El Amrani', $this->etudiant->fresh()->nom);
        $this->assertSame('AB123456', $this->etudiant->fresh()->cin);
        $this->assertSame(0, $this->nombreTraces());
    }

    public function test_un_email_deja_utilise_par_un_autre_etudiant_est_refuse(): void
    {
        $this->creerEtudiant([
            'cin' => 'XY987654',
            'email' => 'autre.email@example.test',
        ]);

        $this->patch(route('admin.etudiants.update', $this->etudiant), $this->donnees([
            'email' => 'autre.email@example.test',
            'nom' => 'Nom Jamais Enregistre',
        ]))->assertSessionHasErrors('email');

        $this->assertSame('El Amrani', $this->etudiant->fresh()->nom);
        $this->assertSame('yassine.elamrani@example.test', $this->etudiant->fresh()->email);
        $this->assertSame(0, $this->nombreTraces());
    }

    public function test_la_valeur_actuelle_du_cin_est_acceptee(): void
    {
        $this->patch(route('admin.etudiants.update', $this->etudiant), $this->donnees([
            'cin' => 'AB123456',
            'nom' => 'El Amrani Alaoui',
        ]))->assertRedirect(route('admin.etudiants.show', $this->etudiant));

        $this->assertSame('El Amrani Alaoui', $this->etudiant->fresh()->nom);
        $this->assertSame('AB123456', $this->etudiant->fresh()->cin);
    }

    public function test_le_rattachement_lmd_est_propage_a_l_inscription_courante(): void
    {
        // Étudiant sans groupe : le rattachement LMD peut être corrigé
        // sans passer par le workflow d'affectation (hors périmètre ici).
        $this->etudiant->forceFill(['groupe_id' => null])->save();

        $autreProgramme = Programme::create([
            'faculte_id' => $this->faculte->id,
            'code' => 'GC',
            'nom' => 'Génie Civil',
            'statut' => Programme::STATUT_ACTIF,
        ]);

        $autreNiveau = Niveau::create([
            'programme_id' => $autreProgramme->id,
            'code' => 'L1',
            'nom' => 'Licence 1',
            'ordre' => 1,
            'actif' => true,
        ]);

        $this->patch(route('admin.etudiants.update', $this->etudiant), $this->donnees([
            'programme_id' => $autreProgramme->id,
            'niveau_id' => $autreNiveau->id,
        ]))->assertRedirect(route('admin.etudiants.show', $this->etudiant));

        $etudiant = $this->etudiant->fresh();
        $inscription = $this->inscription->fresh();

        $this->assertSame($autreProgramme->id, (int) $etudiant->programme_id);
        $this->assertSame($autreNiveau->id, (int) $etudiant->niveau_id);
        $this->assertSame('L1', $etudiant->niveau);

        $this->assertSame($autreProgramme->id, (int) $inscription->programme_id);
        $this->assertSame($autreNiveau->id, (int) $inscription->niveau_id);
        $this->assertSame('L1', $inscription->niveau);
        $this->assertSame('Génie Civil', $inscription->filiere);

        $this->assertDatabaseHas('situations_etudiant', [
            'etudiant_id' => $this->etudiant->id,
            'type' => 'programme',
            'ancienne_valeur' => 'GI',
            'nouvelle_valeur' => 'GC',
        ]);
    }

    public function test_un_parcours_d_une_autre_faculte_est_refuse(): void
    {
        $autreFaculte = Faculte::create([
            'nom' => 'Faculté de Médecine',
            'code' => 'FM',
            'active' => true,
        ]);

        $programmeMedecine = Programme::create([
            'faculte_id' => $autreFaculte->id,
            'code' => 'MED',
            'nom' => 'Médecine',
            'statut' => Programme::STATUT_ACTIF,
        ]);

        $niveauMedecine = Niveau::create([
            'programme_id' => $programmeMedecine->id,
            'code' => 'L1',
            'nom' => 'Licence 1',
            'ordre' => 1,
            'actif' => true,
        ]);

        $this->patch(route('admin.etudiants.update', $this->etudiant), $this->donnees([
            'programme_id' => $programmeMedecine->id,
            'niveau_id' => $niveauMedecine->id,
        ]))->assertSessionHasErrors('programme_id');

        $this->assertSame($this->programme->id, (int) $this->etudiant->fresh()->programme_id);
        $this->assertSame(0, $this->nombreTraces());
    }

    public function test_un_niveau_d_une_autre_parcours_est_refuse(): void
    {
        $autreProgramme = Programme::create([
            'faculte_id' => $this->faculte->id,
            'code' => 'GC',
            'nom' => 'Génie Civil',
            'statut' => Programme::STATUT_ACTIF,
        ]);

        $niveauAutreParcours = Niveau::create([
            'programme_id' => $autreProgramme->id,
            'code' => 'L1',
            'nom' => 'Licence 1',
            'ordre' => 1,
            'actif' => true,
        ]);

        $this->patch(route('admin.etudiants.update', $this->etudiant), $this->donnees([
            'niveau_id' => $niveauAutreParcours->id,
        ]))->assertSessionHasErrors('niveau_id');

        $this->assertSame($this->niveau->id, (int) $this->etudiant->fresh()->niveau_id);
        $this->assertSame(0, $this->nombreTraces());
    }

    public function test_une_modification_de_groupe_incompatible_est_refusee(): void
    {
        $autreProgramme = Programme::create([
            'faculte_id' => $this->faculte->id,
            'code' => 'GC',
            'nom' => 'Génie Civil',
            'statut' => Programme::STATUT_ACTIF,
        ]);

        $niveauAutreParcours = Niveau::create([
            'programme_id' => $autreProgramme->id,
            'code' => 'L1',
            'nom' => 'Licence 1',
            'ordre' => 1,
            'actif' => true,
        ]);

        // Le rattachement change VRAIMENT (GI → GC) alors que le groupe
        // courant (GI-L1-A) ne peut pas appartenir au nouveau triplet :
        // le refus doit être opposé.
        $reponse = $this->patch(route('admin.etudiants.update', $this->etudiant), $this->donnees([
            'programme_id' => $autreProgramme->id,
            'niveau_id' => $niveauAutreParcours->id,
        ]));

        $reponse->assertSessionHasErrors('modification');
        $this->assertStringContainsString(
            'n’appartient pas au nouveau rattachement',
            (string) session('errors')->first('modification')
        );

        $this->assertSame($this->programme->id, (int) $this->etudiant->fresh()->programme_id);
        $this->assertSame($this->groupe->id, (int) $this->etudiant->fresh()->groupe_id);
        $this->assertSame(0, $this->nombreTraces());
    }

    public function test_une_incompatibilite_de_groupe_preexistante_ne_bloque_pas_une_correction_hors_lmd(): void
    {
        $autreProgramme = Programme::create([
            'faculte_id' => $this->faculte->id,
            'code' => 'GC',
            'nom' => 'Génie Civil',
            'statut' => Programme::STATUT_ACTIF,
        ]);

        $niveauAutreParcours = Niveau::create([
            'programme_id' => $autreProgramme->id,
            'code' => 'L1',
            'nom' => 'Licence 1',
            'ordre' => 1,
            'actif' => true,
        ]);

        // Dérive de données : le groupe courant (GC) n'appartient pas au
        // LMD de la fiche (GI / L1). Le formulaire ne doit PAS bloquer une
        // simple correction de contact.
        $groupeDerive = Group::create([
            'code' => 'GC-L1-A',
            'nom' => 'GC L1 A',
            'faculte_id' => $this->faculte->id,
            'niveau' => 'L1',
            'filiere' => 'GC',
            'programme_id' => $autreProgramme->id,
            'niveau_id' => $niveauAutreParcours->id,
            'annee_universitaire_id' => $this->annee->id,
            'capacite' => 30,
            'actif' => true,
        ]);

        $this->etudiant->forceFill(['groupe_id' => $groupeDerive->id])->save();

        $this->patch(route('admin.etudiants.update', $this->etudiant), $this->donnees([
            'email' => 'adresse.corrigee@example.test',
        ]))->assertRedirect(route('admin.etudiants.show', $this->etudiant));

        $this->assertSame('adresse.corrigee@example.test', $this->etudiant->fresh()->email);
        $this->assertSame($groupeDerive->id, (int) $this->etudiant->fresh()->groupe_id);
        $this->assertSame($this->programme->id, (int) $this->etudiant->fresh()->programme_id);

        // Un groupe d'une AUTRE faculté n'empêche pas non plus une
        // correction hors LMD.
        $autreFaculte = Faculte::create([
            'nom' => 'Faculté de Médecine',
            'code' => 'FM',
            'active' => true,
        ]);

        $groupeEtranger = Group::create([
            'code' => 'MED-L1-A',
            'nom' => 'MED L1 A',
            'faculte_id' => $autreFaculte->id,
            'niveau' => 'L1',
            'filiere' => 'MED',
            'programme_id' => null,
            'niveau_id' => null,
            'annee_universitaire_id' => $this->annee->id,
            'capacite' => 30,
            'actif' => true,
        ]);

        $this->etudiant->forceFill(['groupe_id' => $groupeEtranger->id])->save();

        $this->patch(route('admin.etudiants.update', $this->etudiant), $this->donnees([
            'email' => 'adresse.corrigee2@example.test',
        ]))->assertRedirect(route('admin.etudiants.show', $this->etudiant));

        $this->assertSame('adresse.corrigee2@example.test', $this->etudiant->fresh()->email);
    }

    public function test_un_post_partiel_ne_reecrit_pas_le_type_le_regime_et_le_paiement_courants(): void
    {
        // Inscription réelle : transfert / continue / payée.
        $this->inscription->update([
            'type' => 'transfert',
            'regime' => 'continue',
            'paye' => true,
        ]);

        $this->inscription->refresh();

        // POST partiel : ni type_inscription, ni regime, ni paye.
        $donnees = $this->donnees(['email' => 'partiel@example.test']);
        unset($donnees['type_inscription'], $donnees['regime'], $donnees['paye']);

        $this->patch(route('admin.etudiants.update', $this->etudiant), $donnees)
            ->assertRedirect(route('admin.etudiants.show', $this->etudiant));

        $inscription = $this->inscription->fresh();

        $this->assertSame('transfert', $inscription->type);
        $this->assertSame('continue', $inscription->regime);
        $this->assertTrue($inscription->paye);
        $this->assertSame('partiel@example.test', $this->etudiant->fresh()->email);
    }

    public function test_un_post_partiel_conserve_les_champs_optionnels_absents(): void
    {
        // POST partiel : `identifiant_national`, `telephone` et
        // `mention_bac` ne sont PAS transmis (§2.5.b). Une absence de
        // champ vaut « inchangé », jamais « remit à zéro » : les trois
        // colonnes doivent conserver leur valeur courante.
        $donnees = $this->donnees(['email' => 'partiel.optionnels@example.test']);

        unset($donnees['identifiant_national'], $donnees['telephone'], $donnees['mention_bac']);

        $this->patch(route('admin.etudiants.update', $this->etudiant), $donnees)
            ->assertRedirect(route('admin.etudiants.show', $this->etudiant));

        $etudiant = $this->etudiant->fresh();

        $this->assertSame('+212 600 000 000', $etudiant->telephone);
        $this->assertSame('MENS-001', $etudiant->identifiant_national);
        $this->assertSame('Bien', $etudiant->mention_bac);
    }

    public function test_un_champ_optionnel_transmis_vide_est_un_effacement_volontaire(): void
    {
        // Le VIDE TRANSMIS est un effacement volontaire : il doit produire
        // `null` (et non « inchangé »). C'est la contrepartie exacte du
        // test précédent : absent ≠ vide.
        $this->patch(route('admin.etudiants.update', $this->etudiant), $this->donnees([
            'email' => 'volontaire.vide@example.test',
            'identifiant_national' => '',
            'telephone' => '',
            'mention_bac' => '',
        ]))->assertRedirect(route('admin.etudiants.show', $this->etudiant));

        $etudiant = $this->etudiant->fresh();

        $this->assertNull($etudiant->telephone);
        $this->assertNull($etudiant->identifiant_national);
        $this->assertNull($etudiant->mention_bac);
    }

    public function test_une_correction_hors_lmd_preserve_les_colonnes_texte_de_l_inscription(): void
    {
        // `niveau` et `filiere` sont des colonnes en texte libre, éditées
        // par l'écran archives : une correction hors LMD ne doit pas y
        // réécrire les valeurs canoniques.
        //
        // La valeur de `niveau` est VOLONTAIREMENT distincte du code
        // canonique du référentiel ('L1') : une assertion sur 'L1' ne
        // prouverait rien (l'implémentation qui écrirait la colonne
        // canonique passerait le test).
        $this->inscription->update([
            'filiere' => 'Parcours saisi à la main',
            'niveau' => 'L1 (reprise)',
        ]);

        $this->patch(route('admin.etudiants.update', $this->etudiant), $this->donnees([
            'email' => 'archives@example.test',
        ]))->assertRedirect(route('admin.etudiants.show', $this->etudiant));

        $inscription = $this->inscription->fresh();

        $this->assertSame('Parcours saisi à la main', $inscription->filiere);
        $this->assertSame('L1 (reprise)', $inscription->niveau);
    }

    public function test_une_derive_du_texte_niveau_ne_declenche_pas_la_propagation_lmd(): void
    {
        // Dérive de données : le texte `niveau` de la fiche ne correspond
        // plus au code canonique du référentiel. Or `niveau` est DÉRIVÉ de
        // `niveau_id` : sa dérive ne doit PAS déclencher la propagation
        // vers l'inscription, sinon les colonnes en texte libre de l'écran
        // `admin.archives.show` seraient écrasées à tort (§2.4).
        $this->etudiant->forceFill(['niveau' => 'L1 (reprise)'])->save();

        $this->inscription->update([
            'niveau' => 'L1 (reprise)',
            'filiere' => 'Parcours saisi à la main',
        ]);

        $this->patch(route('admin.etudiants.update', $this->etudiant), $this->donnees([
            'email' => 'derive.niveau@example.test',
        ]))->assertRedirect(route('admin.etudiants.show', $this->etudiant));

        // Le texte dérive est réécrit dans la FICHE (source canonique)…
        $this->assertSame('L1', $this->etudiant->fresh()->niveau);

        // … mais l'inscription n'est PAS touchée.
        $inscription = $this->inscription->fresh();

        $this->assertSame('L1 (reprise)', $inscription->niveau);
        $this->assertSame('Parcours saisi à la main', $inscription->filiere);
        $this->assertSame($this->programme->id, (int) $inscription->programme_id);
        $this->assertSame($this->niveau->id, (int) $inscription->niveau_id);
    }

    public function test_une_correction_hors_lmd_preserve_les_identifiants_lmd_de_l_inscription(): void
    {
        // Invariant §2.4 : si le LMD de la FICHE ne bouge pas, l'inscription
        // courante n'est pas touchée — y compris ses identifiants
        // (`programme_id`, `niveau_id`), pas seulement ses colonnes texte.
        //
        // L'inscription est ici VOLONTAIREMENT désynchronisée de la fiche :
        // une écriture inconditionnelle y serait visible.
        $autreProgramme = Programme::create([
            'faculte_id' => $this->faculte->id,
            'code' => 'GC',
            'nom' => 'Génie Civil',
            'statut' => Programme::STATUT_ACTIF,
        ]);

        $autreNiveau = Niveau::create([
            'programme_id' => $autreProgramme->id,
            'code' => 'L1',
            'nom' => 'Licence 1',
            'ordre' => 1,
            'actif' => true,
        ]);

        $this->inscription->update([
            'programme_id' => $autreProgramme->id,
            'niveau_id' => $autreNiveau->id,
            'niveau' => 'L1 (reprise)',
            'filiere' => 'Parcours saisi à la main',
        ]);

        // Correction HORS LMD : uniquement l'email change.
        $this->patch(route('admin.etudiants.update', $this->etudiant), $this->donnees([
            'email' => 'desynchronisation@example.test',
        ]))->assertRedirect(route('admin.etudiants.show', $this->etudiant));

        $inscription = $this->inscription->fresh();

        $this->assertSame($autreProgramme->id, (int) $inscription->programme_id);
        $this->assertSame($autreNiveau->id, (int) $inscription->niveau_id);
        $this->assertSame('L1 (reprise)', $inscription->niveau);
        $this->assertSame('Parcours saisi à la main', $inscription->filiere);
    }

    public function test_un_etudiant_sans_inscription_peut_modifier_sa_fiche(): void
    {
        $sansInscription = $this->creerEtudiant([
            'cin' => 'SF111111',
            'email' => 'sans.inscription@example.test',
            'groupe_id' => null,
            'programme_id' => null,
            'niveau_id' => null,
        ]);

        $donnees = $this->donnees([
            'cin' => 'SF111111',
            'email' => 'sans.inscription@example.test',
            'programme_id' => $this->programme->id,
            'niveau_id' => $this->niveau->id,
        ]);
        unset($donnees['type_inscription'], $donnees['regime'], $donnees['paye']);

        $this->patch(route('admin.etudiants.update', $sansInscription), $donnees)
            ->assertRedirect(route('admin.etudiants.show', $sansInscription));

        $this->assertSame($this->programme->id, (int) $sansInscription->fresh()->programme_id);
        $this->assertSame(0, InscriptionAcademique::where('etudiant_id', $sansInscription->id)->count());
    }

    public function test_le_statut_du_dossier_est_inchange_par_la_modification(): void
    {
        $statutAvant = $this->etudiant->statut;

        $this->patch(route('admin.etudiants.update', $this->etudiant), $this->donnees([
            'nom' => 'El Amrani Alaoui',
        ]))->assertRedirect(route('admin.etudiants.show', $this->etudiant));

        $this->assertSame($statutAvant, $this->etudiant->fresh()->statut);
    }

    public function test_le_mot_de_passe_est_inchange(): void
    {
        $hashAvant = $this->etudiant->password;

        $this->patch(route('admin.etudiants.update', $this->etudiant), $this->donnees([
            'email' => 'nouvelle.adresse@example.test',
        ]))->assertRedirect(route('admin.etudiants.show', $this->etudiant));

        $this->assertSame($hashAvant, $this->etudiant->fresh()->password);
    }

    public function test_les_champs_hors_formulaire_sont_ignores_meme_si_transmis(): void
    {
        // Garde-fou : un client non-navigateur peut tenter d'écrire des
        // champs qui ne font PAS partie du formulaire. Ni le FormRequest
        // (règles) ni le service (liste blanche d'écriture) ne doivent les
        // laisser passer.
        $groupeAlterne = Group::create([
            'code' => 'GI-L1-B',
            'nom' => 'GI L1 B',
            'faculte_id' => $this->faculte->id,
            'niveau' => 'L1',
            'filiere' => 'GI',
            'programme_id' => $this->programme->id,
            'niveau_id' => $this->niveau->id,
            'annee_universitaire_id' => $this->annee->id,
            'capacite' => 30,
            'actif' => true,
        ]);

        $statutAvant = $this->etudiant->statut;
        $groupeAvant = (int) $this->etudiant->groupe_id;
        $numInscriptionAvant = $this->etudiant->num_inscription;
        $hashAvant = $this->etudiant->password;

        $this->patch(route('admin.etudiants.update', $this->etudiant), $this->donnees([
            'email' => 'garde.fou@example.test',
            'statut' => 'rejete',
            'groupe_id' => $groupeAlterne->id,
            'num_inscription' => 'INS-0000-99999',
            'password' => 'MotdepassePirate@123',
        ]))->assertRedirect(route('admin.etudiants.show', $this->etudiant));

        $etudiant = $this->etudiant->fresh();

        $this->assertSame($statutAvant, $etudiant->statut);
        $this->assertSame($groupeAvant, (int) $etudiant->groupe_id);
        $this->assertSame($numInscriptionAvant, $etudiant->num_inscription);
        $this->assertSame($hashAvant, $etudiant->password);
        $this->assertTrue(Hash::check('Motdepasse@123', $etudiant->password));
    }

    public function test_une_modification_inchangee_est_refusee(): void
    {
        $reponse = $this->patch(route('admin.etudiants.update', $this->etudiant), $this->donnees());

        $reponse->assertSessionHasErrors('modification');
        $this->assertSame(
            'Aucune modification à enregistrer.',
            (string) session('errors')->first('modification')
        );

        $this->assertSame(0, $this->nombreTraces());
    }

    public function test_le_type_le_regime_et_le_paiement_sont_modifiables(): void
    {
        $this->patch(route('admin.etudiants.update', $this->etudiant), $this->donnees([
            'type_inscription' => 'reinscription',
            'regime' => 'continue',
            'paye' => '1',
        ]))->assertRedirect(route('admin.etudiants.show', $this->etudiant));

        $inscription = $this->inscription->fresh();

        $this->assertSame('reinscription', $inscription->type);
        $this->assertSame('continue', $inscription->regime);
        $this->assertTrue($inscription->paye);

        // Le statut de l'inscription n'est pas un champ de ce formulaire.
        $this->assertSame('valide', $inscription->statut);
    }

    public function test_le_statut_de_paiement_est_independant_du_module_finances(): void
    {
        config(['modules.finances.active' => false]);

        $this->patch(route('admin.etudiants.update', $this->etudiant), $this->donnees([
            'paye' => '1',
        ]))->assertRedirect(route('admin.etudiants.show', $this->etudiant));

        $this->assertTrue($this->inscription->fresh()->paye);
        $this->assertDatabaseCount('factures', 0);
        $this->assertDatabaseCount('paiements', 0);
    }
}
