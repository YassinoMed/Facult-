<?php

namespace App\Services;

use App\Models\Etudiant;
use App\Models\HistoriqueAcademique;
use Illuminate\Http\Request;

/**
 * Journal d'audit académique.
 *
 * Règles absolues :
 * - ne JAMAIS y écrire de secrets (mots de passe, tokens, cookies) ;
 * - le journal est en écriture seule pour l'application (aucune API de modification) ;
 * - chaque entrée capture l'acteur (utilisateur scolarité ou null), l'action
 *   normalisée, l'entité concernée, l'ancienne et la nouvelle valeur (JSON),
 *   l'adresse IP et le user-agent.
 */
class AuditService
{
    /**
     * Trace une action sur le dossier d'un étudiant.
     */
    public function tracer(
        Etudiant $etudiant,
        string $action,
        ?string $entite = null,
        ?int $entiteId = null,
        ?array $ancienne = null,
        ?array $nouvelle = null,
        string $sourceSysteme = 'web',
    ): HistoriqueAcademique {
        /** @var Request|null $request */
        $request = request();
        $utilisateur = auth()->user();

        return HistoriqueAcademique::create([
            'etudiant_id' => $etudiant->id,
            'utilisateur_id' => $utilisateur?->id,
            'action' => $action,
            'entite' => $entite ?? 'dossier',
            'entite_id' => $entiteId,
            'ancienne_valeur' => $ancienne !== null ? json_encode($ancienne, JSON_UNESCAPED_UNICODE) : null,
            'nouvelle_valeur' => $nouvelle !== null ? json_encode($nouvelle, JSON_UNESCAPED_UNICODE) : null,
            'adresse_ip' => $request?->ip(),
            'user_agent' => $request ? mb_substr((string) $request->userAgent(), 0, 500) : null,
            'source_systeme' => in_array($sourceSysteme, ['web', 'import', 'api', 'systeme'], true) ? $sourceSysteme : 'web',
            'created_at' => now(),
        ]);
    }

    /**
     * Raccourci : trace une consultation de dossier.
     */
    public function consultation(Etudiant $etudiant, int $dossierId): HistoriqueAcademique
    {
        return $this->tracer(
            $etudiant,
            HistoriqueAcademique::ACTION_CONSULTATION,
            'dossier',
            $dossierId,
        );
    }

    /**
     * Trace un événement référentiel hors dossier étudiant (ex.
     * enseignants) dans le journal central — réutilisation de la table
     * `historique_academique` (aucun 2e journal). `etudiant_id` reste NULL ;
     * $ancienne/$nouvelle ne doivent contenir AUCUNE donnée personnelle
     * (identifiants techniques et statuts uniquement).
     */
    public function tracerReferentiel(
        string $action,
        string $entite,
        ?int $entiteId,
        ?array $ancienne = null,
        ?array $nouvelle = null,
        string $sourceSysteme = 'web',
    ): HistoriqueAcademique {
        /** @var Request|null $request */
        $request = request();
        $utilisateur = auth()->user();

        return HistoriqueAcademique::create([
            'etudiant_id' => null,
            'utilisateur_id' => $utilisateur?->id,
            'action' => $action,
            'entite' => $entite,
            'entite_id' => $entiteId,
            'ancienne_valeur' => $ancienne !== null ? json_encode($ancienne, JSON_UNESCAPED_UNICODE) : null,
            'nouvelle_valeur' => $nouvelle !== null ? json_encode($nouvelle, JSON_UNESCAPED_UNICODE) : null,
            'adresse_ip' => $request?->ip(),
            'user_agent' => $request ? mb_substr((string) $request->userAgent(), 0, 500) : null,
            'source_systeme' => in_array($sourceSysteme, ['web', 'import', 'api', 'systeme'], true) ? $sourceSysteme : 'web',
            'created_at' => now(),
        ]);
    }
}
