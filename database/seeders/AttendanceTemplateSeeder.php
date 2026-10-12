<?php

namespace Database\Seeders;

use App\Modules\Attendance\Models\AttendanceTemplate;
use Illuminate\Database\Seeder;

/**
 * Seeder idempotent des modèles de fiches de présence système.
 * Initialise les 8 gabarits officiels : classique, émargement, détaillée,
 * TD, TP, examen, double signature, et QR Code.
 */
class AttendanceTemplateSeeder extends Seeder
{
    public function run(): void
    {
        $templates = [
            [
                'code' => 'classic',
                'name' => 'Fiche classique',
                'description' => 'Modèle standard polyvalent adapté aux cours magistraux, TD et TP avec émargement étudiant.',
                'type' => AttendanceTemplate::TYPE_CLASSIC,
                'is_system' => true,
                'is_active' => true,
                'configuration' => [
                    'orientation' => 'portrait',
                    'paper' => 'A4',
                    'columns' => [
                        ['key' => 'numero', 'label' => 'N°', 'width' => '6%', 'visible' => true],
                        ['key' => 'nom', 'label' => 'Nom', 'width' => '20%', 'visible' => true],
                        ['key' => 'prenom', 'label' => 'Prénom', 'width' => '20%', 'visible' => true],
                        ['key' => 'matricule', 'label' => 'Matricule', 'width' => '16%', 'visible' => true],
                        ['key' => 'presence', 'label' => 'Présence', 'width' => '12%', 'visible' => true],
                        ['key' => 'signature', 'label' => 'Signature', 'width' => '14%', 'visible' => true],
                        ['key' => 'observation', 'label' => 'Observation', 'width' => '12%', 'visible' => true],
                    ],
                ],
            ],
            [
                'code' => 'emargement',
                'name' => 'Feuille d\'émargement',
                'description' => 'Gabarit d\'émargement officiel avec grand espace de signature et cartouche institutionnel complet.',
                'type' => AttendanceTemplate::TYPE_EMARGEMENT,
                'is_system' => true,
                'is_active' => true,
                'configuration' => [
                    'orientation' => 'portrait',
                    'paper' => 'A4',
                    'columns' => [
                        ['key' => 'numero', 'label' => 'N°', 'width' => '8%', 'visible' => true],
                        ['key' => 'nom', 'label' => 'Nom', 'width' => '24%', 'visible' => true],
                        ['key' => 'prenom', 'label' => 'Prénom', 'width' => '24%', 'visible' => true],
                        ['key' => 'matricule', 'label' => 'Matricule', 'width' => '18%', 'visible' => true],
                        ['key' => 'signature', 'label' => 'Signature', 'width' => '26%', 'visible' => true],
                    ],
                ],
            ],
            [
                'code' => 'detailed',
                'name' => 'Fiche détaillée',
                'description' => 'Suivi fin des présences avec heure d\'arrivée, retard, heure de départ et motif éventuel.',
                'type' => AttendanceTemplate::TYPE_DETAILED,
                'is_system' => true,
                'is_active' => true,
                'configuration' => [
                    'orientation' => 'landscape',
                    'paper' => 'A4',
                    'columns' => [
                        ['key' => 'numero', 'label' => 'N°', 'width' => '5%', 'visible' => true],
                        ['key' => 'nom', 'label' => 'Nom', 'width' => '16%', 'visible' => true],
                        ['key' => 'prenom', 'label' => 'Prénom', 'width' => '16%', 'visible' => true],
                        ['key' => 'matricule', 'label' => 'Matricule', 'width' => '12%', 'visible' => true],
                        ['key' => 'heure_arrivee', 'label' => 'Arrivée', 'width' => '9%', 'visible' => true],
                        ['key' => 'presence', 'label' => 'Présence', 'width' => '9%', 'visible' => true],
                        ['key' => 'retard', 'label' => 'Retard', 'width' => '8%', 'visible' => true],
                        ['key' => 'heure_depart', 'label' => 'Départ', 'width' => '8%', 'visible' => true],
                        ['key' => 'signature', 'label' => 'Signature', 'width' => '9%', 'visible' => true],
                        ['key' => 'observation', 'label' => 'Observation', 'width' => '8%', 'visible' => true],
                    ],
                ],
            ],
            [
                'code' => 'td',
                'name' => 'Fiche Travaux Dirigés (TD)',
                'description' => 'Fiche optimisée pour les séances de TD intégrant le niveau, la formation et les horaires spécifiques.',
                'type' => AttendanceTemplate::TYPE_TD,
                'is_system' => true,
                'is_active' => true,
                'configuration' => [
                    'orientation' => 'portrait',
                    'paper' => 'A4',
                    'columns' => [
                        ['key' => 'numero', 'label' => 'N°', 'width' => '6%', 'visible' => true],
                        ['key' => 'nom', 'label' => 'Nom', 'width' => '20%', 'visible' => true],
                        ['key' => 'prenom', 'label' => 'Prénom', 'width' => '20%', 'visible' => true],
                        ['key' => 'matricule', 'label' => 'Matricule', 'width' => '16%', 'visible' => true],
                        ['key' => 'presence', 'label' => 'Présence', 'width' => '12%', 'visible' => true],
                        ['key' => 'signature', 'label' => 'Signature', 'width' => '14%', 'visible' => true],
                        ['key' => 'observation', 'label' => 'Observation', 'width' => '12%', 'visible' => true],
                    ],
                ],
            ],
            [
                'code' => 'tp',
                'name' => 'Fiche Travaux Pratiques (TP)',
                'description' => 'Gabarit spécialisé pour les laboratoires et salles machines avec attribution du poste ou groupe TP.',
                'type' => AttendanceTemplate::TYPE_TP,
                'is_system' => true,
                'is_active' => true,
                'configuration' => [
                    'orientation' => 'landscape',
                    'paper' => 'A4',
                    'columns' => [
                        ['key' => 'numero', 'label' => 'N°', 'width' => '6%', 'visible' => true],
                        ['key' => 'nom', 'label' => 'Nom', 'width' => '18%', 'visible' => true],
                        ['key' => 'prenom', 'label' => 'Prénom', 'width' => '18%', 'visible' => true],
                        ['key' => 'matricule', 'label' => 'Matricule', 'width' => '14%', 'visible' => true],
                        ['key' => 'poste', 'label' => 'Poste / Machine', 'width' => '12%', 'visible' => true],
                        ['key' => 'presence', 'label' => 'Présence', 'width' => '10%', 'visible' => true],
                        ['key' => 'signature', 'label' => 'Signature', 'width' => '12%', 'visible' => true],
                        ['key' => 'observation', 'label' => 'Observation', 'width' => '10%', 'visible' => true],
                    ],
                ],
            ],
            [
                'code' => 'exam',
                'name' => 'Feuille d\'émargement d\'examen',
                'description' => 'Feuille officielle pour épreuves et examens avec vérification d\'identité et double visa des surveillants.',
                'type' => AttendanceTemplate::TYPE_EXAM,
                'is_system' => true,
                'is_active' => true,
                'configuration' => [
                    'orientation' => 'landscape',
                    'paper' => 'A4',
                    'options' => [
                        'double_surveillant' => true,
                    ],
                    'columns' => [
                        ['key' => 'numero', 'label' => 'N°', 'width' => '5%', 'visible' => true],
                        ['key' => 'matricule', 'label' => 'Matricule', 'width' => '15%', 'visible' => true],
                        ['key' => 'nom', 'label' => 'Nom', 'width' => '18%', 'visible' => true],
                        ['key' => 'prenom', 'label' => 'Prénom', 'width' => '18%', 'visible' => true],
                        ['key' => 'piece_identite', 'label' => 'Pièce vérifiée', 'width' => '11%', 'visible' => true],
                        ['key' => 'presence', 'label' => 'Présence', 'width' => '10%', 'visible' => true],
                        ['key' => 'signature', 'label' => 'Signature', 'width' => '12%', 'visible' => true],
                        ['key' => 'observation', 'label' => 'Observation', 'width' => '11%', 'visible' => true],
                    ],
                ],
            ],
            [
                'code' => 'double_signature',
                'name' => 'Fiche double signature',
                'description' => 'Émargement à double contrôle requérant la signature de l\'étudiant et le visa de l\'enseignant.',
                'type' => AttendanceTemplate::TYPE_DOUBLE_SIGNATURE,
                'is_system' => true,
                'is_active' => true,
                'configuration' => [
                    'orientation' => 'landscape',
                    'paper' => 'A4',
                    'columns' => [
                        ['key' => 'numero', 'label' => 'N°', 'width' => '5%', 'visible' => true],
                        ['key' => 'nom', 'label' => 'Nom', 'width' => '18%', 'visible' => true],
                        ['key' => 'prenom', 'label' => 'Prénom', 'width' => '18%', 'visible' => true],
                        ['key' => 'matricule', 'label' => 'Matricule', 'width' => '14%', 'visible' => true],
                        ['key' => 'presence', 'label' => 'Présence', 'width' => '9%', 'visible' => true],
                        ['key' => 'signature', 'label' => 'Signature étudiant', 'width' => '13%', 'visible' => true],
                        ['key' => 'signature_enseignant', 'label' => 'Visa enseignant', 'width' => '13%', 'visible' => true],
                        ['key' => 'observation', 'label' => 'Observation', 'width' => '10%', 'visible' => true],
                    ],
                ],
            ],
            [
                'code' => 'qr',
                'name' => 'Fiche présence avec QR Code',
                'description' => 'Gabarit intégrant un token QR Code sécurisé et signé pour chaque étudiant pour pointage rapide sans PII.',
                'type' => AttendanceTemplate::TYPE_QR,
                'is_system' => true,
                'is_active' => true,
                'configuration' => [
                    'orientation' => 'landscape',
                    'paper' => 'A4',
                    'columns' => [
                        ['key' => 'numero', 'label' => 'N°', 'width' => '5%', 'visible' => true],
                        ['key' => 'matricule', 'label' => 'Matricule', 'width' => '14%', 'visible' => true],
                        ['key' => 'nom', 'label' => 'Nom', 'width' => '17%', 'visible' => true],
                        ['key' => 'prenom', 'label' => 'Prénom', 'width' => '17%', 'visible' => true],
                        ['key' => 'qr_code', 'label' => 'Code séance', 'width' => '13%', 'visible' => true],
                        ['key' => 'presence', 'label' => 'Présence', 'width' => '10%', 'visible' => true],
                        ['key' => 'signature', 'label' => 'Signature', 'width' => '12%', 'visible' => true],
                        ['key' => 'observation', 'label' => 'Observation', 'width' => '12%', 'visible' => true],
                    ],
                ],
            ],
        ];

        foreach ($templates as $data) {
            AttendanceTemplate::updateOrCreate(
                ['code' => $data['code']],
                $data
            );
        }
    }
}
