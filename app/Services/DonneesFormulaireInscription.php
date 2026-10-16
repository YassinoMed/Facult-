<?php

namespace App\Services;

use App\Models\Faculte;
use App\Modules\Referentiel\Models\Programme;
use Illuminate\Support\Str;

/**
 * Données du formulaire officiel d'inscription (6 étapes) : source UNIQUE
 * partagée par la page canonique (`InscriptionController@create`) et le
 * parcours embarqué (modal d'accueil via le partial `inscription._wizard`).
 * Mêmes facultés, niveaux, années, mentions, état des périodes, checklists
 * et jeton d'idempotence — aucune divergence possible entre les deux
 * points d'entrée, aucune duplication de requêtes métier.
 */
class DonneesFormulaireInscription
{
    public function __construct(
        private readonly PeriodeInscriptionService $periodes,
        private readonly DocumentsRequisService $documentsRequis,
    ) {}

    /**
     * @return array{facultes: mixed, niveaux: list<string>, anneesAcademiques: list<string>, anneesBac: list<string>, mentionsBac: list<string>, etatInscription: array<string, mixed>, checklistsDocuments: array<string, mixed>, idempotencyToken: string}
     */
    public function pourCreation(): array
    {
        $facultes = Faculte::where('active', true)
            ->with(['programmes' => fn ($q) => $q->where('statut', Programme::STATUT_ACTIF)->with('niveaux')])
            ->orderBy('nom')
            ->get();
        $niveaux = ['L1', 'L2', 'L3', 'M1', 'M2', 'D1', 'D2', 'D3'];

        $anneeActuelle = (int) date('Y');

        // Années académiques (ex: 2024-2025, 2025-2026, 2026-2027)
        $anneesAcademiques = [];
        for ($i = 0; $i <= 2; $i++) {
            $debut = $anneeActuelle - 1 + $i;
            $fin = $debut + 1;
            $anneesAcademiques[] = "$debut-$fin";
        }

        // Années d'obtention du baccalauréat (15 dernières années)
        $anneesBac = [];
        for ($year = $anneeActuelle; $year >= $anneeActuelle - 15; $year--) {
            $anneesBac[] = (string) $year;
        }

        $mentionsBac = ['Passable', 'Assez Bien', 'Bien', 'Très Bien', 'Excellent'];

        // LOOP périodes : état global initial (sans contexte) — affiné
        // par faculté/année via /inscription/periode-etat.
        $etatInscription = $this->periodes->etatGlobal();

        // LOOP 04 : checklist documentaire dynamique par type d'inscription
        $checklistsDocuments = [
            'premiere' => $this->documentsRequis->checklistPourType('premiere'),
            'reinscription' => $this->documentsRequis->checklistPourType('reinscription'),
            'transfert' => $this->documentsRequis->checklistPourType('transfert'),
        ];

        // LOOP 09 : Jeton d'idempotence pour prévenir le double dépôt
        $idempotencyToken = (string) Str::uuid();

        return compact('facultes', 'niveaux', 'anneesAcademiques', 'anneesBac', 'mentionsBac', 'etatInscription', 'checklistsDocuments', 'idempotencyToken');
    }
}
