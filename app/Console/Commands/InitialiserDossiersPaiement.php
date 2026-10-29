<?php

namespace App\Console\Commands;

use App\Models\InscriptionAcademique;
use App\Modules\Finances\Services\DossierPaiementService;
use Illuminate\Console\Command;

/**
 * LOOP-UNIREGISTRE-INSCRIPTIONS-PAIEMENTS-01 : rattrapage des dossiers
 * de paiement manquants.
 *
 * Par défaut, seules les inscriptions `en_cours` sont traitées : les
 * inscriptions historiques ne sont pas modifiées sans autorisation
 * explicite. `--tout` est réservé à un rattrapage validé par la scolarité.
 *
 * La commande est idempotente : elle ne crée que les dossiers absents,
 * ne touche à aucun statut, à aucun montant et à aucun reçu existant.
 */
class InitialiserDossiersPaiement extends Command
{
    protected $signature = 'paiements:initialiser-dossiers
        {--etudiant= : Limiter à un identifiant étudiant}
        {--annee= : Limiter à un identifiant d’année universitaire}
        {--tout : Inclure les inscriptions historiques (hors en_cours)}
        {--dry-run : Compter sans écrire}';

    protected $description = 'Crée les dossiers de paiement manquants pour les inscriptions annuelles.';

    public function handle(DossierPaiementService $dossiers): int
    {
        if (! config('modules.finances.active', false)) {
            $this->warn('Le module Finances est désactivé (modules.finances.active = false). Aucune écriture.');

            return self::SUCCESS;
        }

        if (! config('inscriptions-paiements.actif', true)) {
            $this->warn('Le parcours de paiement est désactivé (inscriptions-paiements.actif = false). Aucune écriture.');

            return self::SUCCESS;
        }

        $etudiantId = $this->option('etudiant');
        $anneeId = $this->option('annee');

        if ($etudiantId !== null && ! ctype_digit((string) $etudiantId)) {
            $this->error('L’option --etudiant doit être un identifiant numérique.');

            return self::FAILURE;
        }

        if ($anneeId !== null && ! ctype_digit((string) $anneeId)) {
            $this->error('L’option --annee doit être un identifiant numérique.');

            return self::FAILURE;
        }

        $query = InscriptionAcademique::query()
            ->whereDoesntHave('dossierPaiement')
            ->when(! $this->option('tout'), fn ($q) => $q->where('statut', 'en_cours'))
            ->when($etudiantId !== null, fn ($q) => $q->where('etudiant_id', (int) $etudiantId))
            ->when($anneeId !== null, fn ($q) => $q->where('annee_universitaire_id', (int) $anneeId));

        $total = (clone $query)->count();

        if ($total === 0) {
            $this->info('Aucun dossier de paiement manquant pour le périmètre demandé.');

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->info("Mode dry-run : {$total} dossier(s) seraient créés. Aucune écriture effectuée.");

            return self::SUCCESS;
        }

        $traites = 0;

        $query->orderBy('id')->chunkById(200, function ($inscriptions) use ($dossiers, &$traites): void {
            foreach ($inscriptions as $inscription) {
                $dossiers->creerPour($inscription);
                $traites++;
            }
        });

        $this->info("{$traites} dossier(s) de paiement créé(s) ou déjà existant(s).");
        $this->line('Aucun statut, montant, reçu ou indicateur de paiement historique n’a été modifié.');

        return self::SUCCESS;
    }
}
