<?php

namespace Database\Seeders;

use App\Models\AnneeUniversitaire;
use App\Models\DocumentAcademique;
use App\Models\DossierAcademique;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\HistoriqueAcademique;
use App\Models\InscriptionAcademique;
use App\Models\Matiere;
use App\Models\Note;
use App\Models\User;
use App\Services\AcademicCalculationService;
use App\Support\DemoData;
use Illuminate\Database\Seeder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Seeder du module Archives Académiques — données 100 % fictives :
 * comptes scolarité (RBAC), années universitaires, catalogue de matières,
 * dossiers + parcours complet (3 à 5 années), notes déterministes,
 * documents de test sans aucune donnée personnelle réelle.
 */
class ArchiveSeeder extends Seeder
{
    public function run(): void
    {
        /*
         | Verrou de sécurité (audit §P0-1, LOOP-SECURITY-REMEDIATION-01) :
         | ce seeder crée des comptes avec des mots de passe de démonstration
         | CONNUS (Admin@2026, etc.). Il est strictement réservé aux
         | environnements de développement et de test, ou à un staging de
         | démonstration sur opt-in explicite SEED_DEMO_DATA=true.
         */
        if (! DemoData::autorisee()) {
            DemoData::verrou(
                self::class,
                'Créez les comptes de production via une procédure dédiée (mots de passe forts et uniques).'
            );
        }

        if (! app()->environment('local', 'testing')) {
            $this->command?->warn('SEED_DEMO_DATA=true : comptes de démonstration à mots de passe connus créés — jamais pour une production réelle.');
        }

        /* ── 1. Comptes du personnel de scolarité (RBAC) ── */
        $personnel = [
            ['name' => 'Directeur Scolarité', 'email' => 'admin@universite.ac.ma', 'role' => 'super_admin', 'mot_de_passe' => 'Admin@2026'],
            ['name' => 'Responsable Scolarité', 'email' => 'scolarite@universite.ac.ma', 'role' => 'admin_scolarite', 'mot_de_passe' => 'Scolarite@2026'],
            ['name' => 'Agent Scolarité', 'email' => 'agent@universite.ac.ma', 'role' => 'agent_scolarite', 'mot_de_passe' => 'Agent@2026'],
            ['name' => 'Consultation Archives', 'email' => 'consultation@universite.ac.ma', 'role' => 'consultation', 'mot_de_passe' => 'Consult@2026'],
        ];

        /*
         | Profils « Administrateur de faculté » (§4) : rattachés chacun à
         | UNE faculté — leur périmètre de données est strictement limité
         | à celle-ci (policies + requêtes scopées).
         */
        $adminsFaculte = [
            ['name' => 'Admin Faculté Sciences', 'email' => 'admin.fst@universite.ac.ma', 'faculte_code' => 'FST', 'mot_de_passe' => 'Faculte@2026'],
            ['name' => 'Admin Faculté Médecine', 'email' => 'admin.fmp@universite.ac.ma', 'faculte_code' => 'FMP', 'mot_de_passe' => 'Faculte@2026'],
        ];

        foreach ($adminsFaculte as $compte) {
            $faculte = Faculte::where('code', $compte['faculte_code'])->first();

            if ($faculte !== null) {
                // `role` n'est plus mass-assignable (audit §P3-17).
                $existant = User::where('email', $compte['email'])->first();

                if ($existant === null) {
                    $nouveau = User::create([
                        'name' => $compte['name'],
                        'email' => $compte['email'],
                        'password' => Hash::make($compte['mot_de_passe']),
                        'faculte_id' => $faculte->id,
                        'actif' => true,
                        'email_verified_at' => now(),
                    ]);
                    $nouveau->definirRole('admin_faculte');
                }
            }
        }

        foreach ($personnel as $compte) {
            // `role` n'est plus mass-assignable (audit §P3-17).
            if (User::where('email', $compte['email'])->doesntExist()) {
                $nouveau = User::create([
                    'name' => $compte['name'],
                    'email' => $compte['email'],
                    'password' => Hash::make($compte['mot_de_passe']),
                    'email_verified_at' => now(),
                ]);
                $nouveau->definirRole($compte['role']);
            }
        }

        /* ── 2. Années universitaires (2020-2021 → 2026-2027) ── */
        $anneesData = [];
        for ($debut = 2020; $debut <= 2026; $debut++) {
            $anneesData[] = [
                'libelle' => "{$debut}-".($debut + 1),
                'date_debut' => "{$debut}-09-01",
                'date_fin' => ($debut + 1).'-07-15',
                'active' => $debut === 2026,
            ];
        }
        foreach ($anneesData as $data) {
            AnneeUniversitaire::firstOrCreate(['libelle' => $data['libelle']], $data);
        }
        $annees = AnneeUniversitaire::orderBy('libelle')->get()->values();
        // $annees[0] = 2020-2021 … $annees[5] = 2025-2026, $annees[6] = 2026-2027 (active)

        /* ── 3. Catalogue des matières (fictives, tronc commun) ── */
        $matieresData = [
            // L1
            ['MATH101', 'Analyse I', 3, 5, 'L1', 1], ['ALGB101', 'Algèbre I', 2, 4, 'L1', 1],
            ['INFO101', 'Algorithmique I', 2, 4, 'L1', 1], ['FRAN101', 'Communication FR', 1, 2, 'L1', 1],
            ['MATH102', 'Analyse II', 3, 5, 'L1', 2], ['ALGB102', 'Algèbre II', 2, 4, 'L1', 2],
            ['INFO102', 'Programmation Web', 2, 4, 'L1', 2], ['ANGL101', 'Anglais technique', 1, 2, 'L1', 2],
            // L2
            ['MATH201', 'Analyse III', 3, 4, 'L2', 1], ['INFO201', 'Bases de données', 3, 5, 'L2', 1],
            ['INFO202', 'POO Java', 2, 4, 'L2', 1], ['STAT201', 'Statistiques I', 2, 3, 'L2', 1],
            ['MATH202', 'Équations différentielles', 2, 3, 'L2', 2], ['INFO203', 'Réseaux I', 2, 4, 'L2', 2],
            ['INFO204', 'Systèmes d\'exploitation', 2, 4, 'L2', 2], ['STAT202', 'Probabilités', 2, 3, 'L2', 2],
            // L3
            ['INFO301', 'Génie logiciel', 3, 5, 'L3', 1], ['INFO302', 'Sécurité informatique', 2, 4, 'L3', 1],
            ['MATH301', 'Recherche opérationnelle', 2, 3, 'L3', 1], ['ANGL301', 'Anglais professionnel', 1, 2, 'L3', 1],
            ['INFO303', 'Stage & PFE', 3, 6, 'L3', 2], ['INFO304', 'IA & Data Mining', 2, 4, 'L3', 2],
            ['MATH302', 'Analyse numérique', 2, 3, 'L3', 2], ['GEST301', 'Gestion de projet', 1, 2, 'L3', 2],
            // M1
            ['MATH501', 'Analyse avancée', 3, 4, 'M1', 1], ['INFO501', 'Architecture logicielle', 3, 5, 'M1', 1],
            ['INFO502', 'Big Data', 2, 4, 'M1', 1],
            ['MATH502', 'Optimisation', 2, 4, 'M1', 2], ['INFO503', 'Cloud & DevOps', 2, 4, 'M1', 2],
            ['INFO504', 'Sécurité avancée', 2, 3, 'M1', 2],
            // M2
            ['INFO601', 'Recherche & Innovation', 3, 5, 'M2', 1], ['INFO602', 'Méthodologie de thèse', 2, 4, 'M2', 1],
            ['INFO603', 'Systèmes distribués', 2, 4, 'M2', 1],
            ['INFO604', 'Projet de fin d\'études', 4, 10, 'M2', 2], ['INFO605', 'Séminaires', 1, 2, 'M2', 2],
            ['INFO606', 'Éthique & droit du numérique', 1, 2, 'M2', 2],
        ];

        foreach ($matieresData as [$code, $nom, $coef, $credits, $niveau, $semestre]) {
            Matiere::firstOrCreate(
                ['code' => $code],
                ['nom' => $nom, 'coefficient' => $coef, 'credits' => $credits, 'niveau' => $niveau, 'filiere' => null, 'semestre' => $semestre],
            );
        }

        /* ── 4. Dossiers + parcours des 25 étudiants existants ── */
        $calculs = app(AcademicCalculationService::class);

        $chainesNiveau = [
            'L1' => ['L1'],
            'L2' => ['L1', 'L2'],
            'L3' => ['L1', 'L2', 'L3'],
            'M1' => ['L1', 'L2', 'L3', 'M1'],
            'M2' => ['L1', 'L2', 'L3', 'M1', 'M2'],
            'D1' => ['L1', 'L2', 'L3', 'M1', 'M2'],
        ];

        $agent = User::where('email', 'agent@universite.ac.ma')->first();

        foreach (Etudiant::all() as $index => $etudiant) {
            // Dossier académique (matricule déterministe et unique)
            $dossier = DossierAcademique::firstOrCreate(
                ['etudiant_id' => $etudiant->id],
                [
                    'matricule' => sprintf('MAT-%05d', $etudiant->id),
                    'date_archivage' => now()->toDateString(),
                    'statut' => 'actif',
                    'observation' => $index % 7 === 0 ? 'Dossier suivi par le service de la scolarité.' : null,
                ],
            );

            HistoriqueAcademique::firstOrCreate(
                ['etudiant_id' => $etudiant->id, 'action' => 'creation_dossier'],
                [
                    'utilisateur_id' => $agent?->id,
                    'entite' => 'dossier',
                    'entite_id' => $dossier->id,
                    'nouvelle_valeur' => json_encode(['matricule' => $dossier->matricule]),
                    'adresse_ip' => '10.0.0.1',
                    'user_agent' => 'Seeder (données de démonstration)',
                    'created_at' => now()->subDays(rand(10, 90)),
                ],
            );

            // Parcours : chaîne de niveaux alignée sur le niveau actuel de l'étudiant
            $chaines = $chainesNiveau[$etudiant->niveau] ?? ['L1'];
            $nbAnnees = count($chaines);

            foreach ($chaines as $i => $niveau) {
                // Dernière année du parcours = 2025-2026 (année de leur inscription actuelle)
                $annee = $annees[6 - $nbAnnees + $i];

                $inscription = InscriptionAcademique::firstOrCreate(
                    ['etudiant_id' => $etudiant->id, 'annee_universitaire_id' => $annee->id],
                    [
                        'niveau' => $niveau,
                        'filiere' => 'Sciences & Technologies',
                        'groupe' => 'G'.((int) $etudiant->id % 3 + 1),
                        'statut' => 'en_cours',
                    ],
                );

                foreach ([1, 2] as $numero) {
                    $semestre = $inscription->semestres()->firstOrCreate(
                        ['numero' => $numero],
                        ['inscription_academique_id' => $inscription->id, 'numero' => $numero],
                    );

                    // Notes déterministes : basées sur un hash stable (aucun random flottant)
                    $matieres = Matiere::where('niveau', $niveau)->where('semestre', $numero)->get();

                    foreach ($matieres as $matiere) {
                        $graine = crc32($etudiant->id.$matiere->id.$semestre->id);
                        $noteBrute = 6 + ($graine % 13) + fmod($graine, 7) / 7; // 6.00 → 18.71
                        $note = min(19.5, round($noteBrute, 2));

                        $donneesNote = [
                            'etudiant_id' => $etudiant->id,
                            'inscription_academique_id' => $inscription->id,
                            'semestre_id' => $semestre->id,
                            'matiere_id' => $matiere->id,
                            'note' => $note,
                            'coefficient' => $matiere->coefficient,
                            'credits' => $matiere->credits,
                            'session' => 'normale',
                        ];

                        try {
                            Note::firstOrCreate(
                                ['semestre_id' => $semestre->id, 'matiere_id' => $matiere->id, 'session' => 'normale'],
                                $donneesNote,
                            );

                            // Étudiants en difficulté : une session de rattrapage (meilleure note retenue)
                            if ($note < 10 && $graine % 3 === 0) {
                                $noteRattrapage = min(19.5, $note + 4 + ($graine % 3));
                                Note::firstOrCreate(
                                    ['semestre_id' => $semestre->id, 'matiere_id' => $matiere->id, 'session' => 'rattrapage'],
                                    array_merge($donneesNote, ['note' => $noteRattrapage, 'session' => 'rattrapage']),
                                );
                            }
                        } catch (UniqueConstraintViolationException) {
                            continue; // concurrence de seed idempotent
                        }
                    }
                }

                // Recalcul officiel : moyennes, crédits, décisions (deterministic service)
                $calculs->recalculerInscription($inscription);

                // Statut de l'inscription selon la décision calculée
                $inscription->refresh();
                $estDerniereAnnee = $i === $nbAnnees - 1;
                if (! $estDerniereAnnee) {
                    $inscription->update(['statut' => $inscription->decision !== null && str_starts_with($inscription->decision, 'Admis') ? 'valide' : 'ajourne']);
                } elseif ($inscription->moyenne_annuelle !== null) {
                    $inscription->update(['statut' => str_starts_with((string) $inscription->decision, 'Admis') ? 'valide' : 'ajourne']);
                }
            }

            /* ── 5. Document de test (contenu fictif, sans données personnelles) ── */
            $premiereAnnee = $annees[max(0, 7 - $nbAnnees)];
            $contenuTest = "ATTESTATION DE SCOLARITÉ (document de démonstration)\n\n"
                ."Ce fichier est un document de test généré automatiquement.\n"
                ."Aucune donnée personnelle réelle n'y est contenue.\n";

            $chemin = sprintf('archives/etudiant_%d/%d/documents/%s.txt',
                $etudiant->id,
                $premiereAnnee->id,
                Str::uuid(),
            );
            Storage::disk('local')->put($chemin, $contenuTest);

            DocumentAcademique::firstOrCreate(
                ['etudiant_id' => $etudiant->id, 'type_document' => 'attestation'],
                [
                    'annee_universitaire_id' => $premiereAnnee->id,
                    'nom_original' => 'attestation_scolarite_test.txt',
                    'chemin' => $chemin,
                    'mime_type' => 'text/plain',
                    'taille' => strlen($contenuTest),
                    'hash_fichier' => hash('sha256', $contenuTest),
                    'uploaded_by' => $agent?->id,
                ],
            );
        }
    }
}
