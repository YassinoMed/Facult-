<?php

namespace App\Modules\Evaluation\Controllers;

use App\Modules\Evaluation\Services\ExamDocumentsProfessionnelsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Contrôleur des documents professionnels d'examen (LOOP MASTER - LOTS 1 à 5).
 *
 * Expose les endpoints :
 * - Catalogue dynamique par option active
 * - Aperçu avant impression (HTML immersif)
 * - Téléchargement PDF A4 certifié
 * - Rendu autonome prêt pour impression papier navigateur
 */
class ExamDocumentsProfessionnelsController
{
    public function __construct(
        private readonly ExamDocumentsProfessionnelsService $documentService
    ) {}

    /**
     * Retourne le catalogue des documents autorisés pour une option.
     */
    public function catalogue(Request $request): JsonResponse
    {
        $option = $request->input('option', 'groupes');
        $documents = $this->documentService->obtenirDocumentsPourOption($option, $request->user());

        return response()->json([
            'succes' => true,
            'option' => $option,
            'documents' => $documents,
            'total' => count($documents),
        ]);
    }

    /**
     * Aperçu avant impression d'un document professionnel.
     */
    public function apercu(Request $request): Response|JsonResponse
    {
        $typeDocument = $request->input('type_document');

        if (! $typeDocument) {
            abort(400, 'Le paramètre type_document est obligatoire.');
        }

        $filtres = $request->only(['annee_id', 'session_id', 'epreuve_id', 'examen_id', 'salle_id', 'groupe_id', 'date']);
        $donnees = $this->documentService->preparerDonneesDocument($typeDocument, $filtres, $request->user());
        $donnees['estPdf'] = false;
        $donnees['autoPrint'] = false;
        $html = view('modules.evaluation.pdf.document-officiel', $donnees)->render();

        if ($request->wantsJson() || $request->ajax()) {
            return response()->json([
                'succes' => true,
                'titre' => $donnees['titreDocument'],
                'reference' => $donnees['reference'],
                'statutDoc' => $donnees['statutDoc'],
                'filigrane' => $donnees['filigrane'],
                'totalLignes' => count($donnees['lignes'] ?? []),
                'html' => $html,
            ]);
        }

        return response($html, 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
        ]);
    }

    /**
     * Téléchargement binaire du document au format PDF A4.
     */
    public function telecharger(Request $request): Response
    {
        $typeDocument = $request->input('type_document');

        if (! $typeDocument) {
            abort(400, 'Le paramètre type_document est obligatoire.');
        }

        $filtres = $request->only(['annee_id', 'session_id', 'epreuve_id', 'examen_id', 'salle_id', 'groupe_id', 'date']);
        $resultat = $this->documentService->genererPdf($typeDocument, $filtres, $request->user());

        return new Response($resultat['contenu'], 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$resultat['nomFichier'].'"',
            'X-Document-Reference' => $resultat['reference'],
            'Cache-Control' => 'no-cache, private',
        ]);
    }

    /**
     * Page d'impression navigateur avec styles print A4 et déclenchement optionnel.
     */
    public function imprimer(Request $request): Response
    {
        $typeDocument = $request->input('type_document');

        if (! $typeDocument) {
            abort(400, 'Le paramètre type_document est obligatoire.');
        }

        $filtres = $request->only(['annee_id', 'session_id', 'epreuve_id', 'examen_id', 'salle_id', 'groupe_id', 'date']);
        $donnees = $this->documentService->preparerDonneesDocument($typeDocument, $filtres, $request->user());
        $donnees['estPdf'] = false;
        $donnees['autoPrint'] = $request->boolean('auto', true);

        $html = view('modules.evaluation.pdf.document-officiel', $donnees)->render();

        return response($html, 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
        ]);
    }
}
