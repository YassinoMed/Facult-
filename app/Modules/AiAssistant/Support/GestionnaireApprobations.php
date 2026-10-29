<?php

namespace App\Modules\AiAssistant\Support;

use App\Modules\AiAssistant\Data\ContexteExecution;
use App\Modules\AiAssistant\Exceptions\ActionRefuseeException;
use App\Modules\AiAssistant\Exceptions\AiAssistantException;
use App\Modules\AiAssistant\Models\AiApprobation;
use Illuminate\Support\Str;

/**
 * Gestionnaire d'approbations humaines (Lots 7/9) :
 *   - SOUMISSION : empreinte sha256 canonique + anti-rejeu (un même
 *     lot de paramètres ne peut pas être en attente deux fois) ;
 *   - DÉCISION : quatre yeux OBLIGATOIRES (le demandeur ne peut pas
 *     décider sa propre demande), expiration automatique, journal
 *     horodaté sans PII ;
 *   - EXÉCUTION : liste blanche STRICTE. `proposer_patch` et
 *     `appliquer_patch` sont REFUSÉS PAR CONSTRUCTION — le module ne
 *     modifie JAMAIS le code ; les autres actions n'ont pas encore
 *     d'exécuteur enregistré (refus explicite, jamais silencieux).
 */
final class GestionnaireApprobations
{
    /** Actions symboliquement interdites : jamais exécutables. */
    public const REFUSES_TOUJOURS = ['proposer_patch', 'appliquer_patch'];

    /** Actions aujourd'hui exécutables : aucune (refus par défaut). */
    private const EXECUTEURS = [];

    public function soumettre(
        string $action,
        string $description,
        array $cibles,
        array $parametres,
        string $niveauRisque,
        ContexteExecution $contexte,
    ): AiApprobation {
        $this->verifierActionConnue($action);

        $heures = max(1, (int) config('ai-assistant.approbations.heures_validite', 24));
        $empreinte = $this->empreinte($action, $cibles, $parametres);

        $enCours = AiApprobation::query()
            ->where('empreinte', $empreinte)
            ->whereIn('etat', [
                AiApprobation::ETAT_PENDING,
                AiApprobation::ETAT_APPROVED,
                AiApprobation::ETAT_EXECUTING,
            ])
            ->exists();

        if ($enCours) {
            throw new ActionRefuseeException($action, 'demande identique déjà en cours (anti-rejeu)');
        }

        return AiApprobation::create([
            'identifiant' => Str::uuid()->toString(),
            'demandeur_id' => $contexte->utilisateurId,
            'conversation_id' => $contexte->conversationId,
            'action' => $action,
            'description' => mb_substr($description, 0, 255),
            'cibles' => $cibles,
            'parametres' => $parametres,
            'impacts_resume' => mb_substr(
                (string) config('ai-assistant.approbations.impacts_resume_defaut', 'Proposition IA — JAMAIS exécutée automatiquement.'),
                0, 2000),
            'niveau_risque' => in_array($niveauRisque, ['faible', 'modere', 'eleve'], true) ? $niveauRisque : 'modere',
            'expire_le' => now()->addHours($heures),
            'etat' => AiApprobation::ETAT_PENDING,
            'empreinte' => $empreinte,
            'journal' => [[
                'evenement' => 'soumise',
                'par' => 'agent ia',
                'a' => now()->toIso8601String(),
            ]],
        ]);
    }

    /**
     * Décision humaine : quatre yeux + envelope d'intégrité + expiration.
     */
    public function decider(AiApprobation $approbation, int $decideurId, bool $approuve): AiApprobation
    {
        if ($approbation->etat !== AiApprobation::ETAT_PENDING) {
            throw new ActionRefuseeException($approbation->action, "état {$approbation->etat} non décidable");
        }

        if ((int) $approbation->demandeur_id === $decideurId) {
            throw new ActionRefuseeException($approbation->action, 'quatre yeux : le demandeur ne décide pas');
        }

        if ($this->estExpiree($approbation)) {
            $this->marquerExpiree($approbation);
            throw new ActionRefuseeException($approbation->action, 'demande expirée');
        }

        if ($this->empreinte($approbation->action, (array) $approbation->cibles, (array) $approbation->parametres)
            !== $approbation->empreinte) {
            throw new AiAssistantException('empreinte_approbation_invalide');
        }

        $approbation->forceFill([
            'etat' => $approuve ? AiApprobation::ETAT_APPROVED : AiApprobation::ETAT_REJECTED,
            'approbateur_id' => $decideurId,
            'decide_le' => now(),
            'journal' => $this->journaliser($approbation, [
                'evenement' => $approuve ? 'approuvee' : 'rejetee',
                'par' => 'decideur #'.$decideurId,
            ]),
        ])->save();

        return $approbation->refresh();
    }

    /**
     * Exécution : liste blanche, empreinte re-vérifiée, journalisation.
     */
    public function executer(AiApprobation $approbation, ContexteExecution $contexte): array
    {
        if (! in_array($approbation->action, self::REFUSES_TOUJOURS, true)) {
            $this->verifierActionConnue($approbation->action);
        }

        if (in_array($approbation->action, self::REFUSES_TOUJOURS, true)) {
            throw new ActionRefuseeException(
                $approbation->action,
                'exécution interdite par construction : les correctifs de code ne sont jamais appliqués par le module'
            );
        }

        if (! $this->estExecutable($approbation->action)) {
            throw new ActionRefuseeException($approbation->action, 'aucun exécuteur enregistré pour cette action');
        }

        if ($approbation->etat !== AiApprobation::ETAT_APPROVED) {
            throw new ActionRefuseeException($approbation->action, "état {$approbation->etat} non exécutable");
        }

        if ($this->estExpiree($approbation)) {
            $this->marquerExpiree($approbation);
            throw new ActionRefuseeException($approbation->action, 'approbation expirée');
        }

        if ($this->empreinte($approbation->action, (array) $approbation->cibles, (array) $approbation->parametres)
            !== $approbation->empreinte) {
            throw new AiAssistantException('empreinte_approbation_invalide');
        }

        throw new ActionRefuseeException($approbation->action, 'aucun exécuteur enregistré pour cette action');
    }

    /* ── Mécanique privée ────────────────────────────────────────── */

    private function checkerExiste(string $action): bool
    {
        return in_array($action, self::REFUSES_TOUJOURS, true) || $this->estExecutable($action);
    }

    private function verifierActionConnue(string $action): void
    {
        if (! $this->checkerExiste($action)) {
            throw new ActionRefuseeException($action, 'action inconnue du registre');
        }

        if (in_array($action, self::REFUSES_TOUJOURS, true)) {
            return; // interdite mais CONNUE : engrange l'approbation papier.
        }

        if (! $this->estExecutable($action)) {
            throw new ActionRefuseeException($action, 'aucun exécuteur enregistré : action non soumissible');
        }
    }

    private function estExecutable(string $action): bool
    {
        return array_key_exists($action, self::EXECUTEURS);
    }

    private function estExpiree(AiApprobation $approbation): bool
    {
        return $approbation->expire_le !== null && $approbation->expire_le->isPast();
    }

    private function marquerExpiree(AiApprobation $approbation): void
    {
        $approbation->forceFill([
            'etat' => AiApprobation::ETAT_EXPIRED,
            'journal' => $this->journaliser($approbation, ['evenement' => 'expiree', 'par' => 'système']),
        ])->save();
    }

    /**
     * Empreinte canonique, stable et non falsifiable sans réécriture
     * de la totalité des champs signés.
     */
    private function empreinte(string $action, array $cibles, array $parametres): string
    {
        $canonique = json_encode([
            'action' => $action,
            'cibles' => array_values($cibles),
            'parametres' => $parametres,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return hash('sha256', (string) $canonique);
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function journaliser(AiApprobation $approbation, array $evenement): array
    {
        $journal = $approbation->journal ?? [];
        $evenement['a'] = now()->toIso8601String();
        $journal[] = $evenement;

        return $journal;
    }
}
