<?php

namespace Database\Seeders;

use App\Models\AnneeUniversitaire;
use App\Models\DocumentAcademique;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\HistoriqueAcademique;
use App\Models\InscriptionAcademique;
use App\Models\Matiere;
use App\Models\Note;
use App\Models\User;
use App\Modules\Evaluation\Models\DecisionDeliberation;
use App\Modules\Evaluation\Models\Deliberation;
use App\Modules\ParcoursAcademique\Models\ParcoursDispense;
use App\Modules\ParcoursAcademique\Models\ParcoursEquivalence;
use App\Modules\ParcoursAcademique\Models\ParcoursPlan;
use App\Modules\Referentiel\Models\Niveau;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\Referentiel\Models\SemestreLmd;
use App\Modules\Referentiel\Models\TypeDiplome;
use App\Modules\Referentiel\Models\UniteEnseignement;
use App\Services\Acquis\DocumentPedagogiqueOfficielService;
use App\Services\Acquis\PlanRedoublementService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Seeder de données de test et de démonstration du Moteur de Décision Académique,
 * de la gestion des acquis, du redoublement et des workflows (LOOP 16 à 25).
 * Conforme au Décret n° 2022-631 et à l'Arrêté du 7 juillet 2023.
 */
class DecisionAcademiqueRedoublementSeeder extends Seeder
{
    public function run(): void
    {
        $this->command?->info('Initialisation des données de test UniRegistre (Partie VI - Décision & Redoublement)...');

        // 1. Récupération ou création des entités structurelles
        $faculte = Faculte::where('code', 'FST')->first() ?? Faculte::first();
        if (! $faculte) {
            $faculte = Faculte::create([
                'nom' => 'Faculté des Sciences de Tunis',
                'code' => 'FST',
                'description' => 'Campus Universitaire El Manar',
                'active' => true,
            ]);
        }

        $typeLicence = TypeDiplome::firstOrCreate(
            ['code' => 'LIC'],
            ['nom' => 'Licence Nationale LMD', 'cycle' => 'premier_cycle', 'actif' => true]
        );

        $annee2025 = AnneeUniversitaire::where('libelle', '2025-2026')->first();
        if (! $annee2025) {
            $annee2025 = AnneeUniversitaire::create([
                'libelle' => '2025-2026',
                'date_debut' => '2025-09-01',
                'date_fin' => '2026-06-30',
                'active' => false,
            ]);
        }

        $annee2026 = AnneeUniversitaire::where('libelle', '2026-2027')->first();
        if (! $annee2026) {
            $annee2026 = AnneeUniversitaire::create([
                'libelle' => '2026-2027',
                'date_debut' => '2026-09-01',
                'date_fin' => '2027-06-30',
                'active' => true,
            ]);
        }

        $adminUser = User::where('email', 'admin@universite.ac.ma')->first()
            ?? User::where('role', 'super_admin')->first()
            ?? User::first();

        $agentScolarite = User::where('email', 'scolarite@universite.ac.ma')->first()
            ?? User::where('role', 'admin_scolarite')->first()
            ?? $adminUser;

        // 2. Programme LMD de référence pour les tests
        $programme = Programme::firstOrCreate(
            ['code' => 'LIC-INFO-REG'],
            [
                'faculte_id' => $faculte->id,
                'type_diplome_id' => $typeLicence->id,
                'nom' => 'Licence en Informatique (Régime LMD - Acquis & Redoublement)',
                'duree_annees' => 3,
                'semestres_nombre' => 6,
                'credits_requis' => 60,
                'actif' => true,
            ]
        );

        $niveauL1 = Niveau::firstOrCreate(
            ['programme_id' => $programme->id, 'code' => 'L1'],
            ['nom' => 'Licence 1ère Année', 'ordre' => 1, 'actif' => true]
        );

        $semestre1 = SemestreLmd::firstOrCreate(
            ['niveau_id' => $niveauL1->id, 'numero' => 1],
            ['code' => 'S1-INFO', 'nom' => 'Semestre 1', 'ordre' => 1, 'actif' => true]
        );

        $semestre2 = SemestreLmd::firstOrCreate(
            ['niveau_id' => $niveauL1->id, 'numero' => 2],
            ['code' => 'S2-INFO', 'nom' => 'Semestre 2', 'ordre' => 2, 'actif' => true]
        );

        // 3. Maquette pédagogique : Unités d'Enseignement & ECUEs
        // UE 1 : Algorithmique et Programmation (6 ECTS)
        $ueAlgo = UniteEnseignement::firstOrCreate(
            ['programme_id' => $programme->id, 'code' => 'UE-ALGO-PROG'],
            ['semestre_id' => $semestre1->id, 'nom' => 'Algorithmique et Programmation', 'credits' => 6, 'coefficient' => 3.0, 'ordre' => 1, 'actif' => true]
        );
        $ecueAlgo = Matiere::firstOrCreate(
            ['code' => 'ALGO-101'],
            ['nom' => 'Algorithmique et Structures de Données', 'coefficient' => 1.5, 'credits' => 3, 'ue_id' => $ueAlgo->id, 'niveau' => 'L1', 'semestre' => 1, 'actif' => true]
        );
        $ecueProg = Matiere::firstOrCreate(
            ['code' => 'PROG-102'],
            ['nom' => 'Programmation C Avancée', 'coefficient' => 1.5, 'credits' => 3, 'ue_id' => $ueAlgo->id, 'niveau' => 'L1', 'semestre' => 1, 'actif' => true]
        );

        // UE 2 : Mathématiques pour l'Informatique (6 ECTS)
        $ueMath = UniteEnseignement::firstOrCreate(
            ['programme_id' => $programme->id, 'code' => 'UE-MATH-INFO'],
            ['semestre_id' => $semestre1->id, 'nom' => 'Mathématiques pour l\'Informatique', 'credits' => 6, 'coefficient' => 3.0, 'ordre' => 2, 'actif' => true]
        );
        $ecueAlgebre = Matiere::firstOrCreate(
            ['code' => 'MATH-101'],
            ['nom' => 'Algèbre Linéaire et Matrices', 'coefficient' => 1.5, 'credits' => 3, 'ue_id' => $ueMath->id, 'niveau' => 'L1', 'semestre' => 1, 'actif' => true]
        );
        $ecueAnalyse = Matiere::firstOrCreate(
            ['code' => 'MATH-102'],
            ['nom' => 'Analyse et Séries Numériques', 'coefficient' => 1.5, 'credits' => 3, 'ue_id' => $ueMath->id, 'niveau' => 'L1', 'semestre' => 1, 'actif' => true]
        );

        // UE 3 : Systèmes et Architecture (6 ECTS)
        $ueSys = UniteEnseignement::firstOrCreate(
            ['programme_id' => $programme->id, 'code' => 'UE-SYS-ARCH'],
            ['semestre_id' => $semestre2->id, 'nom' => 'Systèmes et Architecture Informatique', 'credits' => 6, 'coefficient' => 3.0, 'ordre' => 3, 'actif' => true]
        );
        $ecueArch = Matiere::firstOrCreate(
            ['code' => 'SYS-201'],
            ['nom' => 'Architecture des Ordinateurs', 'coefficient' => 1.5, 'credits' => 3, 'ue_id' => $ueSys->id, 'niveau' => 'L1', 'semestre' => 2, 'actif' => true]
        );
        $ecueUnix = Matiere::firstOrCreate(
            ['code' => 'SYS-202'],
            ['nom' => 'Systèmes d\'Exploitation Unix', 'coefficient' => 1.5, 'credits' => 3, 'ue_id' => $ueSys->id, 'niveau' => 'L1', 'semestre' => 2, 'actif' => true]
        );

        // UE 4 : Développement Web & Données (6 ECTS)
        $ueWeb = UniteEnseignement::firstOrCreate(
            ['programme_id' => $programme->id, 'code' => 'UE-DEV-WEB'],
            ['semestre_id' => $semestre2->id, 'nom' => 'Bases de Données & Technologies Web', 'credits' => 6, 'coefficient' => 3.0, 'ordre' => 4, 'actif' => true]
        );
        $ecueBd = Matiere::firstOrCreate(
            ['code' => 'BD-201'],
            ['nom' => 'Bases de Données Relationnelles', 'coefficient' => 1.5, 'credits' => 3, 'ue_id' => $ueWeb->id, 'niveau' => 'L1', 'semestre' => 2, 'actif' => true]
        );
        $ecueWeb = Matiere::firstOrCreate(
            ['code' => 'WEB-202'],
            ['nom' => 'Développement Web & API', 'coefficient' => 1.5, 'credits' => 3, 'ue_id' => $ueWeb->id, 'niveau' => 'L1', 'semestre' => 2, 'actif' => true]
        );

        // 4. Dépendances et Prérequis pédagogiques (LOOP 20)
        // SYS-202 requiert SYS-201
        if (! $ecueUnix->prerequis()->where('prerequis_id', $ecueArch->id)->exists()) {
            $ecueUnix->prerequis()->attach($ecueArch->id);
        }
        // WEB-202 requiert BD-201
        if (! $ecueWeb->prerequis()->where('prerequis_id', $ecueBd->id)->exists()) {
            $ecueWeb->prerequis()->attach($ecueBd->id);
        }
        // PROG-102 requiert ALGO-101
        if (! $ecueProg->prerequis()->where('prerequis_id', $ecueAlgo->id)->exists()) {
            $ecueProg->prerequis()->attach($ecueAlgo->id);
        }

        // =========================================================================
        // SCÉNARIO 1 : ÉTUDIANT TYPE CAS A — CAPITALISATION PARTIELLE ET REDOUBLEMENT
        // =========================================================================
        $etudiant1 = Etudiant::firstOrCreate(
            ['cin' => '08800001'],
            [
                'nom' => 'Mansour',
                'prenom' => 'Yassine',
                'email' => 'yassine.mansour@etudiant.test',
                'date_naissance' => '2004-04-10',
                'sexe' => 'M',
                'faculte_id' => $faculte->id,
                'programme_id' => $programme->id,
                'niveau_id' => $niveauL1->id,
                'niveau' => 'L1',
                'annee_academique' => '2025-2026',
                'adresse' => 'Rue Habib Bourguiba, Tunis',
                'ville' => 'Tunis',
                'code_postal' => '1000',
                'annee_bac' => '2023',
                'statut' => 'valide',
                'password' => Hash::make('Secret@123'),
            ]
        );

        $insc1 = InscriptionAcademique::firstOrCreate(
            ['etudiant_id' => $etudiant1->id, 'annee_universitaire_id' => $annee2025->id],
            [
                'programme_id' => $programme->id,
                'niveau_id' => $niveauL1->id,
                'filiere' => 'Informatique',
                'niveau' => 'L1',
                'statut' => 'ajourne',
                'type' => 'premiere',
                'regime' => 'initiale',
                'moyenne_annuelle' => 9.25,
                'credits_obtenus' => 9,
                'credits_total' => 60,
                'decision' => 'autorise_redoublement',
            ]
        );
        $insc1->semestres()->firstOrCreate(['numero' => 1]);
        $insc1->semestres()->firstOrCreate(['numero' => 2]);
        $s1_1 = $insc1->semestres()->where('numero', 1)->value('id');
        $s2_1 = $insc1->semestres()->where('numero', 2)->value('id');

        // Notes Etudiant 1 :
        // UE Algo validée : ALGO-101 = 14.0, PROG-102 = 13.0 -> Moyenne UE = 13.5 (6 crédits acquis à vie)
        Note::updateOrCreate(
            ['semestre_id' => $s1_1, 'matiere_id' => $ecueAlgo->id, 'session' => 'normale'],
            ['etudiant_id' => $etudiant1->id, 'inscription_academique_id' => $insc1->id, 'note' => 14.0, 'coefficient' => 1.5, 'credits' => 3, 'locked_at' => now()]
        );
        Note::updateOrCreate(
            ['semestre_id' => $s1_1, 'matiere_id' => $ecueProg->id, 'session' => 'normale'],
            ['etudiant_id' => $etudiant1->id, 'inscription_academique_id' => $insc1->id, 'note' => 13.0, 'coefficient' => 1.5, 'credits' => 3, 'locked_at' => now()]
        );

        // UE Math non acquise : MATH-101 = 12.0 (3 crédits ECUE), MATH-102 = 5.0 (à reprendre)
        Note::updateOrCreate(
            ['semestre_id' => $s1_1, 'matiere_id' => $ecueAlgebre->id, 'session' => 'normale'],
            ['etudiant_id' => $etudiant1->id, 'inscription_academique_id' => $insc1->id, 'note' => 12.0, 'coefficient' => 1.5, 'credits' => 3, 'locked_at' => now()]
        );
        Note::updateOrCreate(
            ['semestre_id' => $s1_1, 'matiere_id' => $ecueAnalyse->id, 'session' => 'normale'],
            ['etudiant_id' => $etudiant1->id, 'inscription_academique_id' => $insc1->id, 'note' => 5.0, 'coefficient' => 1.5, 'credits' => 3, 'locked_at' => now()]
        );

        // UE Sys non acquise : SYS-201 = 6.5, SYS-202 = 5.5
        Note::updateOrCreate(
            ['semestre_id' => $s2_1, 'matiere_id' => $ecueArch->id, 'session' => 'normale'],
            ['etudiant_id' => $etudiant1->id, 'inscription_academique_id' => $insc1->id, 'note' => 6.5, 'coefficient' => 1.5, 'credits' => 3, 'locked_at' => now()]
        );
        Note::updateOrCreate(
            ['semestre_id' => $s2_1, 'matiere_id' => $ecueUnix->id, 'session' => 'normale'],
            ['etudiant_id' => $etudiant1->id, 'inscription_academique_id' => $insc1->id, 'note' => 5.5, 'coefficient' => 1.5, 'credits' => 3, 'locked_at' => now()]
        );

        // Délibération officielle publiée pour Etudiant 1
        $delib1 = Deliberation::firstOrCreate(
            [
                'code' => 'DELIB-L1-2025-2026',
                'annee_universitaire_id' => $annee2025->id,
            ],
            [
                'programme_id' => $programme->id,
                'niveau_id' => $niveauL1->id,
                'semestre_lmd_id' => $semestre1->id,
                'statut' => 'validee',
                'published_at' => now(),
            ]
        );

        DecisionDeliberation::updateOrCreate(
            ['etudiant_id' => $etudiant1->id, 'inscription_academique_id' => $insc1->id],
            [
                'deliberation_id' => $delib1->id,
                'moyenne' => 9.25,
                'credits_obtenus' => 9,
                'credits_total' => 60,
                'ues_validees' => 1,
                'ues_total' => 4,
                'decision' => 'autorise_redoublement',
                'publie' => true,
                'commentaire' => 'Délibération officielle de fin d\'année. Autorisé à redoubler avec conservation définitive des acquis.',
            ]
        );

        // Plan de redoublement validé pour Etudiant 1
        $planService = app(PlanRedoublementService::class);
        $simulationPlan1 = $planService->simulerPlan($etudiant1, $insc1);

        $plan1 = ParcoursPlan::updateOrCreate(
            ['etudiant_id' => $etudiant1->id],
            [
                'inscription_source_id' => $insc1->id,
                'objectif' => 'Plan individuel de redoublement L1 — Licence en Informatique',
                'contenu' => $simulationPlan1,
                'statut' => 'valide',
                'version' => 1,
                'auteur_id' => $adminUser?->id,
            ]
        );

        // Document officiel scellé pour Etudiant 1
        $docService = app(DocumentPedagogiqueOfficielService::class);
        $donneesDoc1 = $docService->genererDonneesDocument($etudiant1, DocumentPedagogiqueOfficielService::TYPE_RELEVE_REDOUBLEMENT, $plan1);

        DocumentAcademique::updateOrCreate(
            ['etudiant_id' => $etudiant1->id, 'type_document' => 'releve_redoublement'],
            [
                'annee_universitaire_id' => $annee2025->id,
                'nom_original' => "{$donneesDoc1['reference']}.json",
                'chemin' => "documents/academiques/{$donneesDoc1['reference']}.json",
                'mime_type' => 'application/json',
                'taille' => strlen(json_encode($donneesDoc1)),
                'hash_fichier' => $donneesDoc1['empreinte_sha256'],
                'uploaded_by' => $adminUser?->id,
            ]
        );

        // =========================================================================
        // SCÉNARIO 2 : ÉTUDIANT TYPE CAS C — COMPENSATION DE JURY (ART. 9)
        // =========================================================================
        $etudiant2 = Etudiant::firstOrCreate(
            ['cin' => '08800002'],
            [
                'nom' => 'Ben Amor',
                'prenom' => 'Mariem',
                'email' => 'mariem.benamor@etudiant.test',
                'date_naissance' => '2004-09-18',
                'sexe' => 'F',
                'faculte_id' => $faculte->id,
                'programme_id' => $programme->id,
                'niveau_id' => $niveauL1->id,
                'niveau' => 'L1',
                'annee_academique' => '2025-2026',
                'adresse' => 'Avenue Habib Thameur, Manouba',
                'ville' => 'Manouba',
                'code_postal' => '2010',
                'annee_bac' => '2023',
                'statut' => 'valide',
                'password' => Hash::make('Secret@123'),
            ]
        );

        $insc2 = InscriptionAcademique::firstOrCreate(
            ['etudiant_id' => $etudiant2->id, 'annee_universitaire_id' => $annee2025->id],
            [
                'programme_id' => $programme->id,
                'niveau_id' => $niveauL1->id,
                'filiere' => 'Informatique',
                'niveau' => 'L1',
                'statut' => 'valide',
                'type' => 'premiere',
                'regime' => 'initiale',
                'moyenne_annuelle' => 10.45,
                'credits_obtenus' => 60,
                'credits_total' => 60,
                'decision' => 'admis',
            ]
        );

        $s1_2 = $insc2->semestres()->firstOrCreate(['numero' => 1])->id;
        $s2_2 = $insc2->semestres()->firstOrCreate(['numero' => 2])->id;

        // Notes Etudiant 2 :
        // Algo = 12.0 / 11.0 -> UE Algo validée (11.5)
        Note::updateOrCreate(
            ['semestre_id' => $s1_2, 'matiere_id' => $ecueAlgo->id, 'session' => 'normale'],
            ['etudiant_id' => $etudiant2->id, 'inscription_academique_id' => $insc2->id, 'note' => 12.0, 'coefficient' => 1.5, 'credits' => 3, 'locked_at' => now()]
        );
        Note::updateOrCreate(
            ['semestre_id' => $s1_2, 'matiere_id' => $ecueProg->id, 'session' => 'normale'],
            ['etudiant_id' => $etudiant2->id, 'inscription_academique_id' => $insc2->id, 'note' => 11.0, 'coefficient' => 1.5, 'credits' => 3, 'locked_at' => now()]
        );
        // Math = 9.8 (Algebre 11.0, Analyse 8.6) -> Pas de note éliminatoire (< 6.0) -> Validée par compensation
        Note::updateOrCreate(
            ['semestre_id' => $s1_2, 'matiere_id' => $ecueAlgebre->id, 'session' => 'normale'],
            ['etudiant_id' => $etudiant2->id, 'inscription_academique_id' => $insc2->id, 'note' => 11.0, 'coefficient' => 1.5, 'credits' => 3, 'locked_at' => now()]
        );
        Note::updateOrCreate(
            ['semestre_id' => $s1_2, 'matiere_id' => $ecueAnalyse->id, 'session' => 'normale'],
            ['etudiant_id' => $etudiant2->id, 'inscription_academique_id' => $insc2->id, 'note' => 8.6, 'coefficient' => 1.5, 'credits' => 3, 'locked_at' => now()]
        );

        DecisionDeliberation::updateOrCreate(
            ['etudiant_id' => $etudiant2->id, 'inscription_academique_id' => $insc2->id],
            [
                'deliberation_id' => $delib1->id,
                'moyenne' => 10.45,
                'credits_obtenus' => 60,
                'credits_total' => 60,
                'ues_validees' => 4,
                'ues_total' => 4,
                'decision' => 'admis',
                'publie' => true,
                'commentaire' => 'Admis par compensation semestrielle conformément à l\'Article 9 de l\'Arrêté du 7 juillet 2023.',
            ]
        );

        // =========================================================================
        // SCÉNARIO 3 : ÉTUDIANT TYPE CAS B & G — PRÉREQUIS BLOQUANT ET SEUIL ÉLIMINATOIRE
        // =========================================================================
        $etudiant3 = Etudiant::firstOrCreate(
            ['cin' => '08800003'],
            [
                'nom' => 'Ayari',
                'prenom' => 'Mohamed',
                'email' => 'mohamed.ayari@etudiant.test',
                'date_naissance' => '2003-12-05',
                'sexe' => 'M',
                'faculte_id' => $faculte->id,
                'programme_id' => $programme->id,
                'niveau_id' => $niveauL1->id,
                'niveau' => 'L1',
                'annee_academique' => '2025-2026',
                'adresse' => 'Cité El Khadra, Tunis',
                'ville' => 'Tunis',
                'code_postal' => '1003',
                'annee_bac' => '2022',
                'statut' => 'valide',
                'password' => Hash::make('Secret@123'),
            ]
        );

        $insc3 = InscriptionAcademique::firstOrCreate(
            ['etudiant_id' => $etudiant3->id, 'annee_universitaire_id' => $annee2025->id],
            [
                'programme_id' => $programme->id,
                'niveau_id' => $niveauL1->id,
                'filiere' => 'Informatique',
                'niveau' => 'L1',
                'statut' => 'ajourne',
                'type' => 'premiere',
                'regime' => 'initiale',
                'moyenne_annuelle' => 7.80,
                'credits_obtenus' => 0,
                'credits_total' => 60,
                'decision' => 'autorise_redoublement',
            ]
        );

        $s1_3 = $insc3->semestres()->firstOrCreate(['numero' => 1])->id;
        $s2_3 = $insc3->semestres()->firstOrCreate(['numero' => 2])->id;

        // Note éliminatoire (< 6.0) sur SYS-201 = 4.0/20 -> bloque compensation et bloque SYS-202 (prérequis)
        Note::updateOrCreate(
            ['semestre_id' => $s2_3, 'matiere_id' => $ecueArch->id, 'session' => 'normale'],
            ['etudiant_id' => $etudiant3->id, 'inscription_academique_id' => $insc3->id, 'note' => 4.0, 'coefficient' => 1.5, 'credits' => 3, 'locked_at' => now()]
        );
        Note::updateOrCreate(
            ['semestre_id' => $s1_3, 'matiere_id' => $ecueAlgo->id, 'session' => 'normale'],
            ['etudiant_id' => $etudiant3->id, 'inscription_academique_id' => $insc3->id, 'note' => 5.5, 'coefficient' => 1.5, 'credits' => 3, 'locked_at' => now()]
        );

        DecisionDeliberation::updateOrCreate(
            ['etudiant_id' => $etudiant3->id, 'inscription_academique_id' => $insc3->id],
            [
                'deliberation_id' => $delib1->id,
                'moyenne' => 7.80,
                'credits_obtenus' => 0,
                'credits_total' => 60,
                'ues_validees' => 0,
                'ues_total' => 4,
                'decision' => 'autorise_redoublement',
                'publie' => true,
                'commentaire' => 'Présence de note éliminatoire bloquant la compensation. Prérequis obligatoires à satisfaire.',
            ]
        );

        ParcoursPlan::updateOrCreate(
            ['etudiant_id' => $etudiant3->id],
            [
                'inscription_source_id' => $insc3->id,
                'objectif' => 'Plan de redoublement bloqué par prérequis et seuil éliminatoire',
                'contenu' => [
                    'anomalies' => ['Note éliminatoire de 4.00/20 sur SYS-201 bloquant la compensation (Art. 13)'],
                    'prerequis_bloquants' => [['ecue_id' => $ecueUnix->id, 'prerequis_id' => $ecueArch->id]],
                ],
                'statut' => 'bloque',
                'version' => 1,
                'auteur_id' => $adminUser?->id,
            ]
        );

        // =========================================================================
        // SCÉNARIO 4 : ÉTUDIANT TYPE CAS D & E — DISPENSE ET ÉQUIVALENCE 4 YEUX
        // =========================================================================
        $etudiant4 = Etudiant::firstOrCreate(
            ['cin' => '08800004'],
            [
                'nom' => 'Trabelsi',
                'prenom' => 'Sonia',
                'email' => 'sonia.trabelsi@etudiant.test',
                'date_naissance' => '2004-01-22',
                'sexe' => 'F',
                'faculte_id' => $faculte->id,
                'programme_id' => $programme->id,
                'niveau_id' => $niveauL1->id,
                'niveau' => 'L1',
                'annee_academique' => '2025-2026',
                'adresse' => 'Avenue de la Liberté, Ariana',
                'ville' => 'Ariana',
                'code_postal' => '2080',
                'annee_bac' => '2023',
                'statut' => 'valide',
                'password' => Hash::make('Secret@123'),
            ]
        );

        $insc4 = InscriptionAcademique::firstOrCreate(
            ['etudiant_id' => $etudiant4->id, 'annee_universitaire_id' => $annee2025->id],
            [
                'programme_id' => $programme->id,
                'niveau_id' => $niveauL1->id,
                'filiere' => 'Informatique',
                'niveau' => 'L1',
                'statut' => 'ajourne',
                'type' => 'transfert',
                'regime' => 'initiale',
                'moyenne_annuelle' => 9.00,
                'credits_obtenus' => 12,
                'credits_total' => 60,
                'decision' => 'autorise_redoublement',
            ]
        );

        // Dispense officielle accordée sur l'UE Web (6 ECTS)
        ParcoursDispense::updateOrCreate(
            ['etudiant_id' => $etudiant4->id, 'ue_id' => $ueWeb->id],
            [
                'cible_libelle' => 'Bases de Données & Technologies Web',
                'motif' => 'Validation des acquis de l\'expérience (VAE) certifiée',
                'decision' => 'validee',
                'statut' => 'validee',
                'responsable' => 'Commission Pédagogique Universitaire',
                'auteur_id' => $adminUser?->id,
            ]
        );

        // Équivalence sur Algèbre Linéaire
        ParcoursEquivalence::updateOrCreate(
            ['etudiant_id' => $etudiant4->id, 'cible_matiere_id' => $ecueAlgebre->id],
            [
                'origine_libelle' => 'Algèbre I (Université Paris-Saclay)',
                'origine_credits' => 3,
                'cible_libelle' => 'Algèbre Linéaire et Matrices',
                'etablissement' => 'Université Paris-Saclay',
                'decision' => 'validee',
                'justification' => 'Concordance stricte à 95% des objectifs d\'apprentissage',
                'decide_par' => $adminUser?->id,
                'decide_le' => now(),
                'statut' => 'validee',
                'auteur_id' => $adminUser?->id,
            ]
        );

        DecisionDeliberation::updateOrCreate(
            ['etudiant_id' => $etudiant4->id, 'inscription_academique_id' => $insc4->id],
            [
                'deliberation_id' => $delib1->id,
                'moyenne' => 9.00,
                'credits_obtenus' => 12,
                'credits_total' => 60,
                'ues_validees' => 2,
                'ues_total' => 4,
                'decision' => 'autorise_redoublement',
                'publie' => true,
            ]
        );

        ParcoursPlan::updateOrCreate(
            ['etudiant_id' => $etudiant4->id],
            [
                'inscription_source_id' => $insc4->id,
                'objectif' => 'Plan de redoublement avec dispense et équivalence (4 yeux requis)',
                'contenu' => [
                    'exige_double_approbation' => true,
                    'motifs' => ['Dispense officielle UE-DEV-WEB', 'Équivalence Paris-Saclay sur MATH-101'],
                    'approbations' => [
                        [
                            'acteur_id' => $adminUser?->id,
                            'role' => 'approbateur_principal',
                            'date' => now()->toIso8601String(),
                            'motif' => 'Vérification et transmission en commission scolarité',
                        ],
                    ],
                ],
                'statut' => 'en_validation',
                'version' => 1,
                'auteur_id' => $agentScolarite?->id,
            ]
        );

        // =========================================================================
        // SCÉNARIO 5 : ÉTUDIANT TYPE CAS HISTORIQUE — RÈGLE MEILLEURE NOTE (ART. 14)
        // =========================================================================
        $etudiant5 = Etudiant::firstOrCreate(
            ['cin' => '08800005'],
            [
                'nom' => 'Dridi',
                'prenom' => 'Farouk',
                'email' => 'farouk.dridi@etudiant.test',
                'date_naissance' => '2004-07-14',
                'sexe' => 'M',
                'faculte_id' => $faculte->id,
                'programme_id' => $programme->id,
                'niveau_id' => $niveauL1->id,
                'niveau' => 'L1',
                'annee_academique' => '2025-2026',
                'adresse' => 'Menzah 6, Tunis',
                'ville' => 'Tunis',
                'code_postal' => '2091',
                'annee_bac' => '2023',
                'statut' => 'valide',
                'password' => Hash::make('Secret@123'),
            ]
        );

        $insc5 = InscriptionAcademique::firstOrCreate(
            ['etudiant_id' => $etudiant5->id, 'annee_universitaire_id' => $annee2025->id],
            [
                'programme_id' => $programme->id,
                'niveau_id' => $niveauL1->id,
                'filiere' => 'Informatique',
                'niveau' => 'L1',
                'statut' => 'ajourne',
                'type' => 'premiere',
                'regime' => 'initiale',
                'moyenne_annuelle' => 9.40,
                'credits_obtenus' => 6,
                'credits_total' => 60,
                'decision' => 'autorise_redoublement',
            ]
        );

        $s1_5 = $insc5->semestres()->firstOrCreate(['numero' => 1])->id;

        // Tentative 1 : Session normale = 7.50
        Note::updateOrCreate(
            ['semestre_id' => $s1_5, 'matiere_id' => $ecueAlgo->id, 'session' => 'normale'],
            ['etudiant_id' => $etudiant5->id, 'inscription_academique_id' => $insc5->id, 'note' => 7.50, 'coefficient' => 1.5, 'credits' => 3, 'locked_at' => now()]
        );
        // Tentative 2 : Session rattrapage = 13.50 (Non écrasement, conservé en base)
        Note::updateOrCreate(
            ['semestre_id' => $s1_5, 'matiere_id' => $ecueAlgo->id, 'session' => 'rattrapage'],
            ['etudiant_id' => $etudiant5->id, 'inscription_academique_id' => $insc5->id, 'note' => 13.50, 'coefficient' => 1.5, 'credits' => 3, 'locked_at' => now()]
        );

        DecisionDeliberation::updateOrCreate(
            ['etudiant_id' => $etudiant5->id, 'inscription_academique_id' => $insc5->id],
            [
                'deliberation_id' => $delib1->id,
                'moyenne' => 9.40,
                'credits_obtenus' => 6,
                'credits_total' => 60,
                'ues_validees' => 1,
                'ues_total' => 4,
                'decision' => 'autorise_redoublement',
                'publie' => true,
                'commentaire' => 'Application de la règle de la meilleure note (Arrêté du 7 juillet 2023, Art. 14) : note de 13.50/20 retenue.',
            ]
        );

        // Historisation immuable dans HistoriqueAcademique
        HistoriqueAcademique::create([
            'etudiant_id' => $etudiant5->id,
            'utilisateur_id' => $adminUser?->id,
            'action' => 'seeder_donnees_test_partie_vi',
            'entite' => 'etudiants',
            'entite_id' => $etudiant5->id,
            'ancienne_valeur' => null,
            'nouvelle_valeur' => json_encode([
                'description' => 'Génération des données de test pour la Partie VI (Décision académique & Redoublement)',
                'programme' => $programme->nom,
                'etudiants_generes' => ['08800001', '08800002', '08800003', '08800004', '08800005'],
            ], JSON_UNESCAPED_UNICODE),
            'source_systeme' => 'seeder',
        ]);

        $this->command?->info('Données de test de la Partie VI insérées avec succès dans la base de données !');
        $this->command?->info('Étudiants disponibles pour démonstration et recette :');
        $this->command?->info(' - 08800001 : Yassine Mansour (Cas A — Capitalisation UE partielle & Redoublement)');
        $this->command?->info(' - 08800002 : Mariem Ben Amor (Cas C — Validation par compensation Art. 9)');
        $this->command?->info(' - 08800003 : Mohamed Ayari (Cas B & G — Note éliminatoire et prérequis bloquant)');
        $this->command?->info(' - 08800004 : Sonia Trabelsi (Cas D & E — Dispense et équivalence sous 4 yeux)');
        $this->command?->info(' - 08800005 : Farouk Dridi (Cas Tentatives multiples — Règle meilleure note Art. 14)');
    }
}
