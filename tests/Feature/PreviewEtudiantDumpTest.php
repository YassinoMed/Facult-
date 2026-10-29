<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\User;
use App\Services\PeriodeInscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * OUTIL DE PRÉVISUALISATION (design-taste-frontend) : rend la fiche étudiant
 * en HTML statique avec assets buildés, pour inspection navigateur
 * (lint.mjs + inspect.mjs). Exécuter : php artisan test --filter=dump_fiche
 */
class PreviewEtudiantDumpTest extends TestCase
{
    use RefreshDatabase;

    public function test_dump_fiche_etudiant_statique(): void
    {
        $faculte = Faculte::create(['nom' => 'Faculté des Sciences de Sfax', 'code' => 'FSS', 'description' => 'Sciences.', 'active' => true]);

        $annee = AnneeUniversitaire::create([
            'libelle' => '2025-2026', 'date_debut' => '2025-09-01', 'date_fin' => '2026-06-30', 'active' => true,
        ]);
        app(PeriodeInscriptionService::class)->creerNormale([
            'annee_universitaire_id' => $annee->id,
            'opened_at' => '2020-01-01 00:00', 'closed_at' => '2030-12-31 23:59',
        ], null);

        $etudiant = Etudiant::create([
            'cin' => '09512233', 'nom' => 'Ben Salah', 'prenom' => 'Ines',
            'email' => 'ines.bensalah@etudiant.univ.test', 'telephone' => '+216 98 445 512',
            'date_naissance' => '2003-11-24', 'sexe' => 'F',
            'faculte_id' => $faculte->id, 'niveau' => 'L2', 'annee_academique' => '2025-2026',
            'adresse' => '12 rue Habib Thameur, Médina', 'ville' => 'Sfax', 'code_postal' => '3000',
            'annee_bac' => '2022', 'mention_bac' => 'Bien',
            'password' => 'Motdepasse123',
        ]);
        $etudiant->forceFill(['num_inscription' => 'INS-2025-04172'])->save();
        $etudiant->definirStatut('en_attente');

        $this->actingAs(User::creerCompteBackOffice([
            'name' => 'Scolarité', 'email' => uniqid('dump.', true).'@scolarite.test',
            'password' => 'Motdepasse@123',
        ], 'super_admin'));

        $reponse = $this->get(route('admin.etudiants.show', $etudiant));
        $reponse->assertOk();
        $html = $reponse->getContent();

        // withoutVite rend des tags vides : inline le CSS buildé réel pour
        // que l'inspection navigateur voie la vraie mise en page (le serveur
        // jetable d'inspect.mjs ne résout pas les chemins absolus /build).
        if (! str_contains($html, '/* INLINE-BUILD-CSS */')) {
            $css = (string) file_get_contents(glob(public_path('build/assets/app-*.css'))[0]);
            $html = str_replace('</head>', "<style>/* INLINE-BUILD-CSS */\n".$css.'</style></head>', $html);
        }

        // Aperçu sans JS : Alpine ne boote pas, on lève x-cloak et on neutralise
        // x-show pour que TOUS les panneaux d'onglets soient visibles à l'inspection.
        $html = str_replace(' x-cloak', '', $html);
        $html = str_replace('x-show="', 'data-apercu-xshow="', $html);
        // Les surpositions fixes (modales, palette, drawer) s'afficheraient
        // ouvertes sans Alpine : masquées pour l'inspection du contenu. Les menus
        // (role=menu : thème, notifications, compte, actions rapides) sont eux
        // aussi fermés au repos : l'état jugé est celui de la page au chargement.
        $html = str_replace('</head>', '<style>.fixed.inset-0{display:none!important}[role="menu"]{display:none!important}</style></head>', $html);

        file_put_contents(public_path('preview-etudiant.html'), $html);
        fwrite(STDERR, "\n[PREVIEW] ecrit : public/preview-etudiant.html (".strlen($html)." o)\n");
        $this->assertTrue(true);
    }
}
