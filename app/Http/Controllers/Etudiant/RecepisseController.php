<?php

namespace App\Http\Controllers\Etudiant;

use App\Http\Controllers\Controller;
use App\Models\Etudiant;
use App\Modules\StudentDossiers\Contracts\PdfGeneratorInterface;
use App\Modules\StudentDossiers\Models\StudentDossier;
use App\Services\PeriodeInscriptionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Récépissé de dépôt d'inscription (LOOP 13) : preuve de SOUMISSION,
 * jamais une décision d'acceptation. Généré à la demande via le moteur
 * PDF officiel (aucun stockage, aucune copie), résolu exclusivement
 * depuis la session — jamais d'identifiant client. Sans CIN.
 *
 * Échec de génération (LOOP 13 §cahier) : intercepté et communiqué —
 * jamais de 500 brute ; le dépôt (déjà en base) n'est jamais perdu et
 * la régénération reste possible.
 */
class RecepisseController extends Controller
{
    public function telecharger(Request $request, PdfGeneratorInterface $pdf): Response
    {
        $etudiantId = $request->session()->get('etudiant_auth_id');
        if (! $etudiantId) {
            return redirect()->route('etudiant.connexion.show');
        }

        $etudiant = Etudiant::with(['faculte', 'programmeRef', 'niveauRef'])
            ->findOrFail($etudiantId);

        // Récupération des pièces justificatives transmises si disponibles
        $documents = [];
        if (class_exists(StudentDossier::class)) {
            $dossier = StudentDossier::where('etudiant_id', $etudiant->id)
                ->with('documents')
                ->first();

            if ($dossier && $dossier->documents) {
                $documents = $dossier->documents;
            }
        }

        // Empreinte de sécurité déterministe (sans PII) pour contrôle d'intégrité
        $empreinte = strtoupper(substr(hash('sha256', $etudiant->num_inscription.'|'.$etudiant->created_at?->timestamp.'|'.$etudiant->id), 0, 16));

        try {
            $html = view('etudiant.recepisse', [
                'etudiant' => $etudiant,
                'dateDepot' => $etudiant->created_at->setTimezone(PeriodeInscriptionService::fuseau()),
                'documents' => $documents,
                'empreinte' => $empreinte,
            ])->render();

            $contenu = $pdf->generer($html, ['paper' => 'A4', 'orientation' => 'portrait']);
        } catch (\Throwable $e) {
            // Journal sans PII : référence seule, jamais les données du dossier.
            Log::error('recepisse.generation_echec', [
                'etudiant_id' => $etudiant->id,
                'reference' => $etudiant->num_inscription,
                'exception' => $e::class,
            ]);

            return redirect()
                ->route('etudiant.profil.show')
                ->withErrors(['recepisse' => 'Impossible de générer le récépissé pour le moment. Votre dépôt reste enregistré — réessayez plus tard.']);
        }

        return response($contenu, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="recepisse-inscription-'.$etudiant->num_inscription.'.pdf"',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-cache, no-store, must-revalidate',
        ]);
    }
}
