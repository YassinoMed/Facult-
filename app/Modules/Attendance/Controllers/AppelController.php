<?php

namespace App\Modules\Attendance\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Etudiant;
use App\Modules\Attendance\Models\Presence;
use App\Modules\Attendance\Models\SeanceAppel;
use App\Modules\Attendance\Services\AppelPresenceService;
use App\Modules\Attendance\Services\AttendanceQrSecurityService;
use App\Modules\Schedule\Models\ScheduleSlot;
use App\Modules\Schedule\Models\Teacher;
use App\Services\Io\CsvSerializer;
use App\Support\QrCodeSvg;
use App\Support\Recherche;
use Carbon\Carbon;
use DomainException;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Espace Enseignant — appel de présence des étudiants (séance × date).
 * Résolution exclusive depuis la session : slot possédé (404 sinon),
 * étudiants du groupe de la séance (rejetés exclus), statuts validés
 * serveur. Sans ligne = « non renseigné », jamais absent.
 */
class AppelController extends Controller
{
    public function __construct(
        private readonly AppelPresenceService $appels,
    ) {}

    /**
     * Sélection de la séance : mes créneaux du jour demandé (défaut :
     * aujourd'hui, jamais de futur) + état d'appel + effectif.
     */
    public function selection(Request $request): View
    {
        $enseignant = $this->enseignantConnecte($request);
        $date = $this->dateSeance($request);

        return view('modules.attendance.appel-selection', [
            'enseignant' => $enseignant,
            'date' => $date,
            'seances' => $this->appels->seancesAppelables($enseignant, $date),
        ]);
    }

    /**
     * Feuille d'appel : liste alphabétique + relevés existants + compteurs.
     */
    public function feuille(Request $request, SeanceAppel $appel): View
    {
        $enseignant = $this->enseignantConnecte($request);
        $this->verifierPropriete($enseignant, $appel);

        $appel->loadMissing(['slot.matiere', 'slot.room', 'slot.schedule.groupe']);
        ['etudiants' => $etudiants, 'releves' => $releves] = $this->appels->feuille($appel);

        $recherche = trim((string) $request->input('search', ''));
        $filtreStatut = $request->input('statut');

        if ($recherche !== '') {
            $motif = Recherche::contient($recherche);
            $etudiants = $etudiants->filter(fn (Etudiant $e) => mb_stripos($e->nom.' '.$e->prenom.' '.$e->num_inscription, trim($motif, '%')) !== false)->values();
        }

        if (in_array($filtreStatut, [...Presence::STATUTS_APPEL, 'non_renseigne'], true)) {
            $etudiants = $etudiants->filter(
                fn (Etudiant $e) => ($releves->get($e->id)?->statut ?? 'non_renseigne') === $filtreStatut
            )->values();
        }

        return view('modules.attendance.appel-feuille', [
            'enseignant' => $enseignant,
            'appel' => $appel,
            'etudiants' => $etudiants,
            'releves' => $releves,
            'compteurs' => $this->appels->compteurs($appel),
            'recherche' => $recherche,
            'filtreStatut' => $filtreStatut,
        ]);
    }

    /**
     * Ouvre (ou reprend) l'appel d'une séance.
     */
    public function ouvrir(Request $request, ScheduleSlot $slot)
    {
        $enseignant = $this->enseignantConnecte($request);
        abort_if((int) $slot->teacher_id !== (int) $enseignant->id, 404);

        $date = $this->dateSeance($request);

        try {
            $appel = $this->appels->ouvrir($slot, $date, $enseignant);
        } catch (DomainException $e) {
            return back()->withErrors(['appel' => $e->getMessage()]);
        }

        return redirect()->route('enseignant.appel.feuille', $appel);
    }

    /**
     * Enregistre le relevé complet (tout-ou-rien, idempotent).
     */
    public function enregistrer(Request $request, SeanceAppel $appel)
    {
        $enseignant = $this->enseignantConnecte($request);
        $this->verifierPropriete($enseignant, $appel);

        $valides = $request->validate([
            'releves' => ['required', 'array', 'min:1'],
            'releves.*.etudiant_id' => ['required', 'integer'],
            'releves.*.statut' => ['required', 'string'],
        ]);

        try {
            $bilan = $this->appels->enregistrer($appel, $valides['releves'], $enseignant);
        } catch (DomainException $e) {
            if ($request->wantsJson()) {
                return response()->json(['success' => false, 'error' => $e->getMessage()], 422);
            }

            return back()->withErrors(['appel' => $e->getMessage()])->withInput();
        }

        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'message' => "Appel enregistré : {$bilan['enregistres']} relevé(s).",
                'compteurs' => $this->appels->compteurs($appel),
            ]);
        }

        return redirect()->route('enseignant.appel.feuille', $appel)
            ->with('success', "Appel enregistré : {$bilan['enregistres']} relevé(s).");
    }

    /**
     * Clôture explicite (incomplet refusé sauf confirmation expresse ;
     * les non-renseignés restent non-renseignés, jamais absents).
     */
    public function cloturer(Request $request, SeanceAppel $appel)
    {
        $enseignant = $this->enseignantConnecte($request);
        $this->verifierPropriete($enseignant, $appel);

        try {
            $totaux = $this->appels->cloturer($appel, $request->boolean('confirmer_incomplet'));
        } catch (DomainException $e) {
            if ($request->wantsJson()) {
                return response()->json(['success' => false, 'error' => $e->getMessage()], 422);
            }

            return back()->withErrors(['appel' => $e->getMessage()])->withInput();
        }

        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'message' => "Appel clôturé : {$totaux['presents']} présent(s), {$totaux['absents']} absent(s), {$totaux['retards']} retard(s), {$totaux['non_renseignes']} non renseigné(s).",
                'compteurs' => $totaux,
                'statut' => 'cloture',
            ]);
        }

        return redirect()->route('enseignant.appel.feuille', $appel)
            ->with('success', "Appel clôturé : {$totaux['presents']} présent(s), {$totaux['absents']} absent(s), {$totaux['retards']} retard(s), {$totaux['non_renseignes']} non renseigné(s).");
    }

    /**
     * Correction motivée post-clôture (motif obligatoire, tracée).
     */
    public function corriger(Request $request, SeanceAppel $appel)
    {
        $enseignant = $this->enseignantConnecte($request);
        $this->verifierPropriete($enseignant, $appel);

        $valides = $request->validate([
            'etudiant_id' => ['required', 'integer'],
            'statut' => ['required', 'string'],
            'motif' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        $etudiant = Etudiant::findOrFail($valides['etudiant_id']);

        try {
            $this->appels->corriger($appel, $etudiant, $valides['statut'], $valides['motif'], $enseignant);
        } catch (DomainException $e) {
            if ($request->wantsJson()) {
                return response()->json(['success' => false, 'error' => $e->getMessage()], 422);
            }

            return back()->withErrors(['appel' => $e->getMessage()])->withInput();
        }

        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'message' => 'Correction enregistrée avec motif et historique.',
                'compteurs' => $this->appels->compteurs($appel),
            ]);
        }

        return redirect()->route('enseignant.appel.feuille', $appel)
            ->with('success', 'Correction enregistrée avec motif et historique.');
    }

    /**
     * Export CSV de la feuille d'appel (LOOP 19) : relevés du groupe de
     * la séance uniquement, formules tableur neutralisées.
     */
    public function exporter(Request $request, SeanceAppel $appel): StreamedResponse
    {
        $enseignant = $this->enseignantConnecte($request);
        $this->verifierPropriete($enseignant, $appel);

        $appel->loadMissing(['slot.matiere', 'slot.schedule.groupe']);
        ['etudiants' => $etudiants, 'releves' => $releves] = $this->appels->feuille($appel);

        return response()->streamDownload(function () use ($etudiants, $releves) {
            $sortie = fopen('php://output', 'w');
            fwrite($sortie, "\xEF\xBB\xBF");
            fputcsv($sortie, ['Nom', 'Prénom', 'N° inscription', 'Statut'], ';');

            foreach ($etudiants as $etudiant) {
                fputcsv($sortie, [
                    app(CsvSerializer::class)->neutraliserFormule($etudiant->nom),
                    app(CsvSerializer::class)->neutraliserFormule($etudiant->prenom),
                    $etudiant->num_inscription,
                    $releves->get($etudiant->id)?->statut ?? 'non_renseigne',
                ], ';');
            }

            fclose($sortie);
        }, 'feuille-appel-'.$appel->id.'-'.now()->format('Y-m-d-His').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * QR temporaire de pointage pour UN étudiant (LOOP 21) : jeton signé
     * 15 minutes lié à l'occurrence d'appel (`APPEL-{id}`), sans donnée
     * personnelle encodée. L'enseignant affiche ce QR à l'étudiant qui le
     * scanne depuis son espace ; appel clôturé = refus.
     */
    public function qr(Request $request, SeanceAppel $appel, Etudiant $etudiant, AttendanceQrSecurityService $qr): View
    {
        $enseignant = $this->enseignantConnecte($request);
        $this->verifierPropriete($enseignant, $appel);

        abort_if($appel->estCloture(), 422, 'Appel clôturé : aucun QR ne peut être généré.');
        abort_unless(
            Etudiant::whereKey($etudiant->id)->where('groupe_id', $appel->slot->schedule->groupe_id)->exists(),
            404
        );

        $jeton = $qr->genererToken($etudiant->id, 'APPEL-'.$appel->id, $appel->date->toDateString(), 15);

        return view('modules.attendance.appel-qr', [
            'enseignant' => $enseignant,
            'appel' => $appel,
            'etudiant' => $etudiant,
            'jeton' => $jeton,
            'qrSvg' => QrCodeSvg::generer($jeton, 220),
        ]);
    }

    /**
     * Appel possédé par l'enseignant connecté (anti-IDOR/BOLA, 404).
     */
    private function verifierPropriete(Teacher $enseignant, SeanceAppel $appel): void
    {
        abort_if((int) $appel->teacher_id !== (int) $enseignant->id, 404);
        $appel->loadMissing('slot');
        abort_if($appel->slot === null || (int) $appel->slot->teacher_id !== (int) $enseignant->id, 404);
    }

    private function enseignantConnecte(Request $request): Teacher
    {
        return Teacher::findOrFail($request->session()->get('enseignant_auth_id'));
    }

    /**
     * Date de séance : Y-m-d, aujourd'hui ou passée (jamais future).
     */
    private function dateSeance(Request $request): string
    {
        $date = $request->input('date', Carbon::today()->toDateString());

        if (! is_string($date) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            abort(422, 'Date de séance invalide.');
        }

        abort_if($date > Carbon::today()->toDateString(), 422, 'Appel impossible pour une séance future.');

        return $date;
    }
}
