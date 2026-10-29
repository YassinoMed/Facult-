<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Etudiant;
use App\Models\InscriptionAcademique;
use App\Modules\StudentDossiers\Services\Pdf\OfficialDocumentService;
use App\Services\Documents\DocumentEligibiliteService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Guichet attestations : recherche étudiant par CIN ou matricule,
 * vérification automatique des conditions par type, génération
 * (déléguée au service officiel), puis impression depuis la fiche
 * du document généré. Contrôleur fin — métier dans les services.
 */
class AttestationController extends Controller
{
    public function __construct(
        private readonly OfficialDocumentService $officiels,
        private readonly DocumentEligibiliteService $eligibilite,
    ) {}

    public function index(Request $request): View
    {
        $catalogue = OfficialDocumentService::catalogue();
        $recherche = trim((string) $request->input('q', ''));
        $etudiant = null;
        $inscriptions = collect();
        $inscription = null;
        $controles = [];

        if ($recherche !== '') {
            $etudiant = $this->trouverEtudiant($recherche);

            if ($etudiant === null) {
                return view('admin.attestations.index', [
                    'catalogue' => $catalogue,
                    'recherche' => $recherche, 'etudiant' => null, 'inscriptions' => $inscriptions,
                    'inscription' => null, 'controles' => $controles, 'introuvable' => true,
                ]);
            }

            abort_unless($request->user()->peutAccederFaculte((int) $etudiant->faculte_id), 404);

            $etudiant->loadMissing('dossierAcademique');
            $inscriptions = InscriptionAcademique::where('etudiant_id', $etudiant->id)
                ->with('anneeUniversitaire')->orderByDesc('annee_universitaire_id')->get();

            $inscription = $inscriptions->firstWhere('id', (int) $request->input('inscription_id'))
                ?? $inscriptions->first(fn ($i) => in_array($i->statut, ['en_cours', 'valide'], true))
                ?? $inscriptions->first();

            if ($inscription !== null) {
                foreach (array_keys(OfficialDocumentService::catalogue()) as $type) {
                    $controles[$type] = $this->eligibilite->verifier($etudiant, $type, $inscription);
                }
            }
        }

        return view('admin.attestations.index', [
            'catalogue' => $catalogue,
            'recherche' => $recherche, 'etudiant' => $etudiant, 'inscriptions' => $inscriptions,
            'inscription' => $inscription, 'controles' => $controles, 'introuvable' => false,
        ]);
    }

    public function generer(Request $request): RedirectResponse
    {
        $donnees = $request->validate([
            'etudiant_id' => ['required', 'integer', 'exists:etudiants,id'],
            'inscription_id' => ['required', 'integer', 'exists:inscriptions_academiques,id'],
            'type' => ['required', 'string', 'in:'.implode(',', array_keys(OfficialDocumentService::catalogue()))],
            'semestre' => ['nullable', 'integer', 'in:1,2'],
            'langue' => ['nullable', 'string', 'in:ar,fr'],
        ]);

        $etudiant = Etudiant::findOrFail($donnees['etudiant_id']);
        abort_unless($request->user()->peutAccederFaculte((int) $etudiant->faculte_id), 404);

        $definition = OfficialDocumentService::catalogue()[$donnees['type']];
        $semestre = ($definition['semestre_requis'] ?? false) ? ($donnees['semestre'] ?? null) : null;
        $langue = $donnees['langue'] ?? (in_array($donnees['type'], ['attestation_inscription', 'attestation_reussite', 'certificat_reussite'], true) ? 'ar' : 'fr');

        try {
            $document = $this->officiels->generer(
                $etudiant, $donnees['type'], (int) $donnees['inscription_id'],
                (int) $request->user()->id, $semestre,
                ['langue' => $langue]
            );
        } catch (\DomainException $e) {
            return back()->withErrors(['document' => $e->getMessage()])->withInput();
        }

        return redirect()->route('admin.documents-center.show', $document)
            ->with('success', "Document {$document->reference} généré — vérifiez, approuvez puis imprimez.");
    }

    /**
     * CIN exact (normalisé) ou matricule du dossier (MAT-…).
     */
    private function trouverEtudiant(string $recherche): ?Etudiant
    {
        $cin = mb_strtoupper(trim($recherche));

        return Etudiant::where('cin', $cin)->first()
            ?? Etudiant::whereHas('dossierAcademique', fn ($q) => $q->where('matricule', trim($recherche)))->first();
    }
}
