<?php

namespace App\Modules\Attendance\Services;

use App\Contracts\EnregistrementPresenceInterface;
use App\Models\Etudiant;
use App\Models\IntegrationLog;
use App\Modules\Attendance\Models\Presence;
use App\Modules\Schedule\Models\ScheduleSlot;
use Carbon\Carbon;
use Illuminate\Support\Str;

/**
 * Service de sécurité QR Code pour les présences (§8 & §22).
 *
 * Règles absolues :
 * - Aucune donnée personnelle sensible (CIN, adresse, téléphone) n'est encodée dans le token ;
 * - Signature HMAC SHA-256 avec la clé secrète de l'application (anti-falsification) ;
 * - Horodatage d'expiration contrôlé côté serveur ;
 * - Validation de l'inscription effective de l'étudiant à la séance / groupe ;
 * - Idempotence : évite la double présence si le QR est scanné deux fois ;
 * - Audit systématique de chaque utilisation (ATTENDANCE_QR_USED).
 */
class AttendanceQrSecurityService
{
    public function __construct(
        private readonly EnregistrementPresenceInterface $presences,
    ) {}

    /**
     * Génère un token cryptographiquement signé pour un étudiant et une séance.
     */
    public function genererToken(
        int $etudiantId,
        int|string $seanceRef,
        string|Carbon $date,
        int $dureeValiditeMinutes = 240
    ): string {
        $dateStr = is_string($date) ? Carbon::parse($date)->format('Y-m-d') : $date->format('Y-m-d');
        $expiresAt = now()->addMinutes($dureeValiditeMinutes)->timestamp;

        $payload = [
            'sid' => $etudiantId,
            'ses' => (string) $seanceRef,
            'd' => $dateStr,
            'exp' => $expiresAt,
            'nonce' => Str::random(8),
        ];

        $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $payloadB64 = rtrim(strtr(base64_encode($json), '+/', '-_'), '=');

        $cle = (string) config('app.key');
        $signature = hash_hmac('sha256', $payloadB64, $cle);

        return $payloadB64.'.'.$signature;
    }

    /**
     * Valide et décode le token signé.
     *
     * @return array{valide: bool, code_erreur: ?string, payload: ?array<string, mixed>}
     */
    public function validerToken(string $token): array
    {
        $parties = explode('.', $token);
        if (count($parties) !== 2) {
            return ['valide' => false, 'code_erreur' => 'TOKEN_FORMAT_INVALIDE', 'payload' => null];
        }

        [$payloadB64, $signature] = $parties;

        $cle = (string) config('app.key');
        $signatureAttendue = hash_hmac('sha256', $payloadB64, $cle);

        if (! hash_equals($signatureAttendue, $signature)) {
            return ['valide' => false, 'code_erreur' => 'SIGNATURE_FALSIFIEE', 'payload' => null];
        }

        $json = base64_decode(strtr($payloadB64, '-_', '+/'));
        $payload = json_decode($json, true);

        if (! is_array($payload) || ! isset($payload['sid'], $payload['ses'], $payload['d'], $payload['exp'])) {
            return ['valide' => false, 'code_erreur' => 'PAYLOAD_MALFORME', 'payload' => null];
        }

        if (now()->timestamp > (int) $payload['exp']) {
            return ['valide' => false, 'code_erreur' => 'TOKEN_EXPIRE', 'payload' => $payload];
        }

        return ['valide' => true, 'code_erreur' => null, 'payload' => $payload];
    }

    /**
     * Valide le scan du QR Code et enregistre la présence si toutes les conditions sont remplies.
     *
     * @return array{succes: bool, code: string, message: string, presence: ?Presence, etudiant: ?Etudiant}
     */
    public function validerEtEnregistrer(string $token, ?int $enregistrePar = null): array
    {
        $validation = $this->validerToken($token);

        if (! $validation['valide']) {
            return [
                'succes' => false,
                'code' => $validation['code_erreur'] ?? 'ERREUR_TOKEN',
                'message' => match ($validation['code_erreur']) {
                    'TOKEN_EXPIRE' => 'Ce QR Code a expiré pour cette séance.',
                    'SIGNATURE_FALSIFIEE' => 'Signature du QR Code invalide ou falsifiée.',
                    default => 'QR Code non reconnu ou altéré.',
                },
                'presence' => null,
                'etudiant' => null,
            ];
        }

        $payload = $validation['payload'];
        $etudiantId = (int) $payload['sid'];
        $seanceRef = (string) $payload['ses'];
        $date = (string) $payload['d'];

        /** @var Etudiant|null $etudiant */
        $etudiant = Etudiant::find($etudiantId);
        if ($etudiant === null) {
            return [
                'succes' => false,
                'code' => 'ETUDIANT_INCONNU',
                'message' => 'Étudiant associé au QR Code introuvable.',
                'presence' => null,
                'etudiant' => null,
            ];
        }

        // Vérification de cohérence de groupe si la séance correspond à un créneau ScheduleSlot
        if (is_numeric($seanceRef)) {
            $slot = ScheduleSlot::with('schedule.groupe')->find((int) $seanceRef);
            if ($slot !== null && $slot->schedule?->groupe_id !== null) {
                $groupeId = $slot->schedule->groupe_id;
                if ($etudiant->groupe_id !== null && (int) $etudiant->groupe_id !== (int) $groupeId) {
                    return [
                        'succes' => false,
                        'code' => 'ETUDIANT_NON_INSCRIT_GROUPE',
                        'message' => 'L\'étudiant n\'est pas inscrit au groupe assigné à cette séance.',
                        'presence' => null,
                        'etudiant' => $etudiant,
                    ];
                }
            }
        }

        // Vérifier si la présence existe déjà pour respecter l'idempotence
        $presenceExistante = Presence::where('etudiant_id', $etudiant->id)
            ->whereDate('date', $date)
            ->where('seance', $seanceRef)
            ->first();

        if ($presenceExistante !== null && $presenceExistante->statut === 'present') {
            return [
                'succes' => true,
                'code' => 'DEJA_PRESENT',
                'message' => 'Présence déjà enregistrée pour cette séance (idempotent).',
                'presence' => $presenceExistante,
                'etudiant' => $etudiant,
            ];
        }

        // Enregistrement officiel via le seul moteur central AttendanceService
        $presence = $this->presences->enregistrer(
            $etudiant,
            $date,
            'present',
            $seanceRef,
            'Pointage automatique via scan QR Code sécurisé',
            $enregistrePar
        );

        // Journalisation d'audit §24
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
            'QR-ETU-'.$etudiant->id.'-SES-'.$seanceRef
        );

        return [
            'succes' => true,
            'code' => 'PRESENCE_ENREGISTREE',
            'message' => 'Présence de l\'étudiant '.$etudiant->nom_complet.' confirmée avec succès.',
            'presence' => $presence,
            'etudiant' => $etudiant,
        ];
    }
}
