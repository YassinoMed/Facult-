<?php

namespace App\Modules\Attendance\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Etudiant;
use App\Models\IntegrationLog;
use App\Modules\Attendance\Models\SeanceAppel;
use App\Modules\Attendance\Services\AppelPresenceService;
use App\Modules\Attendance\Services\AttendanceQrSecurityService;
use DomainException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Espace Étudiant — pointage via QR temporaire d'appel (LOOP 21).
 *
 * Le jeton est validé côté serveur (format, signature HMAC, expiration),
 * rattaché à l'étudiant connecté (impossible de pointer pour un autre),
 * à un appel existant EN COURS de son groupe. Idempotent (double scan =
 * même présence). Le QR ne vaut pas preuve absolue de présence physique :
 * l'enseignant conserve la correction manuelle.
 */
class PointageQrController extends Controller
{
    public function __construct(
        private readonly AppelPresenceService $appels,
    ) {}

    public function formulaire(): View
    {
        return view('modules.attendance.pointage-qr');
    }

    public function pointer(Request $request, AttendanceQrSecurityService $qr)
    {
        $etudiant = Etudiant::findOrFail($request->session()->get('etudiant_auth_id'));

        $valides = $request->validate(['jeton' => ['required', 'string', 'max:500']]);

        $validation = $qr->validerToken($valides['jeton']);

        if (! $validation['valide']) {
            return back()->withErrors(['jeton' => match ($validation['code_erreur']) {
                'TOKEN_EXPIRE' => 'Ce QR a expiré : demandez à votre enseignant d’en générer un nouveau.',
                'SIGNATURE_FALSIFIEE' => 'QR invalide ou falsifié.',
                default => 'QR non reconnu ou altéré.',
            }])->withInput();
        }

        $donnees = $validation['payload'];

        // Le jeton est nominatif : impossible de pointer pour un autre.
        if ((int) ($donnees['sid'] ?? 0) !== (int) $etudiant->id) {
            return back()->withErrors(['jeton' => 'Ce QR appartient à un autre étudiant.'])->withInput();
        }

        $reference = (string) ($donnees['ses'] ?? '');

        if (! str_starts_with($reference, 'APPEL-')) {
            return back()->withErrors(['jeton' => 'QR hors appel : utilisez le QR affiché par votre enseignant.'])->withInput();
        }

        $appel = SeanceAppel::find((int) mb_substr($reference, 6));

        if ($appel === null) {
            return back()->withErrors(['jeton' => 'Séance d’appel introuvable.'])->withInput();
        }

        try {
            $presence = $this->appels->pointerQr($appel, $etudiant);
        } catch (DomainException $e) {
            return back()->withErrors(['jeton' => $e->getMessage()])->withInput();
        }

        IntegrationLog::tracer(
            'attendance',
            'qr_scan',
            'ATTENDANCE_QR_USED',
            'completed',
            null,
            200,
            null,
            null,
            (string) Str::uuid(),
            'QR-ETU-'.$etudiant->id.'-APPEL-'.$appel->id
        );

        return back()->with('success', 'Présence pointée à '.$presence->updated_at->format('H:i').' — vérifiable par votre enseignant sur la feuille d’appel.');
    }
}
