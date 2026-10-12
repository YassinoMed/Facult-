<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\DossierAcademique;
use App\Models\Etudiant;
use App\Models\InscriptionAcademique;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Dashboard de l'administrateur de faculté (§14) — données
 * automatiquement limitées à SA faculté (middleware faculty.admin +
 * périmètre relu en base à chaque requête, jamais côté client).
 */
class DashboardFaculteController extends Controller
{
    public function index(Request $request): View
    {
        $faculteId = $request->user()->faculteRestreinteId();

        abort_if($faculteId === null, 403, 'Aucune faculté de rattachement.');

        // §15 : les 3 compteurs d'étudiants partagent UNE requête agrégée.
        $ligne = Etudiant::where('faculte_id', $faculteId)
            ->selectRaw('COUNT(*) as total')
            ->selectRaw("SUM(statut = 'en_attente') as en_attente")
            ->selectRaw("SUM(statut = 'valide') as valide")
            ->first();
        $stats = [
            'total' => (int) $ligne->total,
            'en_attente' => (int) $ligne->en_attente,
            'valide' => (int) $ligne->valide,
            'dossiers' => DossierAcademique::whereHas('etudiant', fn ($q) => $q->where('faculte_id', $faculteId))->count(),
            'inscriptions' => InscriptionAcademique::whereHas('etudiant', fn ($q) => $q->where('faculte_id', $faculteId))->count(),
        ];

        // Derniers inscrits de SA faculté uniquement
        $derniers = Etudiant::with('faculte')
            ->where('faculte_id', $faculteId)
            ->latest()
            ->limit(8)
            ->get();

        return view('admin.ma-faculte', [
            'faculte' => $request->user()->faculte,
            'stats' => $stats,
            'derniers' => $derniers,
        ]);
    }
}
