<?php

namespace Database\Seeders;

use App\Models\AnneeUniversitaire;
use App\Models\DossierAcademique;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\Matiere;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Jeu de démonstration pour tester l'import des notes de bout en bout :
 * 12 étudiants (CIN + matricules connus), 3 matières, inscriptions
 * 2026-2027 + semestres. Idempotent (rejouable sans doublon), local
 * et testing uniquement. Fichier d'exemple : docs/notes/demo-notes.csv.
 */
class NotesDemoSeeder extends Seeder
{
    public const CIN_PREFIXE = '120001';

    public const MATRICULE_PREFIXE = 'MAT-DEMO-';

    public function run(): void
    {
        if (! app()->environment('local', 'testing')) {
            $this->command?->warn('NotesDemoSeeder ignoré (environnement non local).');

            return;
        }

        $faculte = Faculte::where('code', 'FST')->first()
            ?? Faculte::create(['nom' => 'FST', 'code' => 'FST', 'description' => 'Démo', 'active' => true]);

        $annee = AnneeUniversitaire::where('libelle', '2026-2027')->first()
            ?? AnneeUniversitaire::create([
                'libelle' => '2026-2027', 'date_debut' => '2026-09-01', 'date_fin' => '2027-06-30', 'active' => true,
            ]);

        foreach ($this->matieres() as $donnees) {
            Matiere::where('code', $donnees['code'])->firstOrCreate($donnees);
        }

        $noms = [
            ['Benali', 'Sara', 'F'], ['Alami', 'Mehdi', 'M'], ['Trabelsi', 'Yassine', 'M'],
            ['Haddad', 'Manel', 'F'], ['Gharbi', 'Oussama', 'M'], ['Belhaj', 'Rim', 'F'],
            ['Mansouri', 'Karim', 'M'], ['Ayari', 'Sana', 'F'], ['Jaziri', 'Nour', 'F'],
            ['Khelifi', 'Adam', 'M'], ['Zouari', 'Lina', 'F'], ['Dridi', 'Anas', 'M'],
        ];

        foreach ($noms as $index => [$nom, $prenom, $sexe]) {
            $numero = str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT);

            $etudiant = Etudiant::where('cin', self::CIN_PREFIXE.$numero)->first();

            if ($etudiant === null) {
                $etudiant = Etudiant::create([
                    'cin' => self::CIN_PREFIXE.$numero, 'nom' => $nom, 'prenom' => $prenom,
                    'email' => strtolower(self::CIN_PREFIXE.$numero).'@demo.test',
                    'date_naissance' => '2004-03-15', 'sexe' => $sexe, 'faculte_id' => $faculte->id,
                    'niveau' => 'L1', 'annee_academique' => '2026-2027', 'adresse' => 'Rue Démo',
                    'ville' => 'Tunis', 'code_postal' => '1000', 'annee_bac' => '2024',
                    'password' => Hash::make('Etudiant@123'),
                ]);
            }

            DossierAcademique::firstOrCreate(
                ['etudiant_id' => $etudiant->id],
                ['matricule' => self::MATRICULE_PREFIXE.str_pad((string) ($index + 1), 3, '0', STR_PAD_LEFT), 'date_archivage' => now()->toDateString(), 'statut' => 'actif'],
            );

            $inscription = $etudiant->inscriptionsAcademiques()
                ->where('annee_universitaire_id', $annee->id)
                ->first();

            if ($inscription === null) {
                $inscription = $etudiant->inscriptionsAcademiques()->create([
                    'annee_universitaire_id' => $annee->id, 'niveau' => 'L1', 'statut' => 'en_cours',
                ]);
                $inscription->semestres()->create(['numero' => 1]);
                $inscription->semestres()->create(['numero' => 2]);
            }
        }

        $this->command?->info('NotesDemoSeeder : 12 étudiants, 3 matières, inscriptions 2026-2027.');
    }

    /** @return array<int, array<string, mixed>> */
    private function matieres(): array
    {
        return [
            ['code' => 'DEMO101', 'nom' => 'Algorithmique (démo)', 'niveau' => 'L1', 'semestre' => 1],
            ['code' => 'DEMO102', 'nom' => 'Bases de données (démo)', 'niveau' => 'L1', 'semestre' => 1],
            ['code' => 'DEMO201', 'nom' => 'Programmation web (démo)', 'niveau' => 'L1', 'semestre' => 2],
        ];
    }
}
