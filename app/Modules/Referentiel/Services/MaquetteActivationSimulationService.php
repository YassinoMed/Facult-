<?php

namespace App\Modules\Referentiel\Services;

use App\Models\InscriptionAcademique;
use App\Models\Matiere;
use App\Modules\Evaluation\Models\Examen;
use App\Modules\Referentiel\Models\Maquette;
use App\Modules\Referentiel\Models\MaquetteUe;
use App\Modules\Referentiel\Models\MaquetteUeEcue;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\Referentiel\Models\UniteEnseignement;
use Illuminate\Support\Facades\DB;

/**
 * Simulation d'activation des maquettes (MASTER LOOP §1.3, ENTERPRISE
 * LOOP 02) : prévalidation LECTURE SEULE — aucune écriture en base (ni
 * maquette, ni UE, ni ECUE, ni inscriptions). Pour chaque programme :
 * validations (INFO), blocages (BLOCKER), avertissements (WARNING),
 * corrections nécessaires, consommateurs affectés et recommandation
 * (ACTIVE_DEJA / ACTIVABLE / ATTENTE).
 *
 * Chaque résultat porte un identifiant de règle stable (PROG-*, NIV-*,
 * DIP-*, SEM-*, UE-*, EC-*, PRE-*, MAQ-*), une gravité, un code, un
 * détail et — pour les blocages/avertissements — une correction.
 * La gravité découle des règles réellement définies : un programme
 * sans maquette active n'est pas une erreur de base mais un blocage
 * opérationnel (les flux plan-actif refusent explicitement).
 *
 * Une maquette n'est ACTIVABLE que si son contenu est complet ET que la
 * décision pédagogique est explicite hors de ce service (jamais
 * d'activation automatique ici — règle absolue n°5).
 */
final class MaquetteActivationSimulationService
{
    /**
     * @return array{programmes: array<int, array<string, mixed>>, resume: array<string, mixed>}
     */
    public function simuler(?int $faculteId = null, ?string $programmeCode = null): array
    {
        $query = Programme::query()->orderBy('code');

        if ($faculteId !== null) {
            $query->where('faculte_id', $faculteId);
        }

        if ($programmeCode !== null && trim($programmeCode) !== '') {
            $query->where('code', trim($programmeCode));
        }

        $resultats = [];

        foreach ($query->get() as $programme) {
            $resultats[] = $this->simulerProgramme($programme);
        }

        $blocages = array_sum(array_map(fn (array $r) => count($r['blocages']), $resultats));
        $avertissements = array_sum(array_map(fn (array $r) => count($r['avertissements']), $resultats));

        return [
            'programmes' => $resultats,
            'resume' => [
                'programmes' => count($resultats),
                'activables' => count(array_filter($resultats, fn (array $r) => $r['recommandation'] === 'ACTIVABLE')),
                'actives_deja' => count(array_filter($resultats, fn (array $r) => $r['recommandation'] === 'ACTIVE_DEJA')),
                'en_attente' => count(array_filter($resultats, fn (array $r) => $r['recommandation'] === 'ATTENTE')),
                'blocages' => $blocages,
                'avertissements' => $avertissements,
                // Rappel legacy global (LOOP 3) : distingué des rattachées,
                // jamais migré ici.
                'matieres_standalone' => Matiere::whereNull('ue_id')->count(),
                'matieres_rattachees' => Matiere::whereNotNull('ue_id')->count(),
                'inscriptions_sans_programme' => InscriptionAcademique::whereNull('programme_id')->count(),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function simulerProgramme(Programme $programme): array
    {
        $programme->loadMissing([
            'typeDiplome',
            'niveaux.semestres.unitesEnseignement.ecues',
            'maquettes.anneeUniversitaire',
        ]);

        $validations = [];
        $blocages = [];
        $avertissements = [];
        $corrections = [];

        // 1. Statut du programme.
        if ($programme->statut === Programme::STATUT_ACTIF) {
            $validations[] = $this->info('PROG-01', 'PROGRAMME_ACTIF', 'Programme actif (statut=actif).');
        } else {
            $blocages[] = $this->bloqueur('PROG-01', 'PROGRAMME_NON_ACTIF',
                "Statut « {$programme->statut} » : seul un programme actif est proposable.");
            $corrections[] = "Passer le programme {$programme->code} au statut actif (workflow programme, pas ici).";
        }

        // 2. Niveaux L1/L2/L3.
        $codesNiveaux = $programme->niveaux->pluck('code')->all();
        if (count(array_intersect(['L1', 'L2', 'L3'], $codesNiveaux)) === 3) {
            $validations[] = $this->info('NIV-01', 'NIVEAUX_COMPLETS',
                'Niveaux L1, L2 et L3 présents ('.$programme->niveaux->count().' niveaux).');
        } else {
            $blocages[] = $this->bloqueur('NIV-01', 'NIVEAUX_INCOMPLETS',
                'Niveaux présents : '.($codesNiveaux === [] ? 'aucun' : implode(', ', $codesNiveaux)).' (attendus : L1, L2, L3).');
            $corrections[] = "Créer les niveaux manquants du programme {$programme->code} (L1, L2, L3).";
        }

        // 3. Cohérence durée du diplôme.
        $duree = $programme->typeDiplome?->duree_annees;
        if ($duree === null || $programme->niveaux->count() <= $duree) {
            $validations[] = $this->info('DIP-01', 'DIPLOME_COHERENT',
                'Durée du diplôme cohérente ('
                .($programme->typeDiplome?->code ?? 'sans type').', durée '.($duree ?? 'non définie')
                .' pour '.$programme->niveaux->count().' niveaux).');
        } else {
            $avertissements[] = $this->alerte('DIP-01', 'DIPLOME_INCOHERENT',
                "{$programme->niveaux->count()} niveaux pour une durée de {$duree} an(s).");
        }

        // 4. Semestres S1..S6.
        $semestres = $programme->niveaux->flatMap->semestres;
        $numeros = $semestres->pluck('numero')->unique()->sort()->values()->all();
        if ($numeros === [1, 2, 3, 4, 5, 6]) {
            $validations[] = $this->info('SEM-01', 'SEMESTRES_COMPLETS', 'Semestres S1 à S6 présents (6 semestres).');
        } else {
            $blocages[] = $this->bloqueur('SEM-01', 'SEMESTRES_MANQUANTS',
                'Numéros présents : '.($numeros === [] ? 'aucun' : implode(', ', $numeros)).' (attendus : 1 à 6).');
            $corrections[] = "Créer les semestres manquants du programme {$programme->code}.";
        }

        // 5. UE : rattachement programme + crédits + volumes + couverture.
        $ues = $programme->niveaux->flatMap(fn ($n) => $n->semestres->flatMap->unitesEnseignement);
        // Repli : UE liées par programme_id explicite (double chemin §8).
        $uesParProgramme = UniteEnseignement::where('programme_id', $programme->id)->get();
        $ues = $ues->merge($uesParProgramme)->unique('id')->values();

        $horsProgramme = [];
        $creditsIncoherents = [];
        $sansVolume = [];

        foreach ($ues as $ue) {
            $chaineProgrammeId = $ue->semestre?->niveau?->programme_id;
            if ($chaineProgrammeId !== null && (int) $chaineProgrammeId !== (int) $programme->id) {
                $horsProgramme[] = $ue->code;
            }

            $somme = (int) $ue->ecues->sum('credits');
            if ($ue->ecues->count() > 0 && $somme !== (int) $ue->credits) {
                $creditsIncoherents[] = "{$ue->code} (UE {$ue->credits} ≠ somme ECUE {$somme})";
            }

            foreach ($ue->ecues as $ecue) {
                if ($ecue->volumeTotal() <= 0) {
                    $sansVolume[] = $ecue->code;
                }
            }
        }

        if ($horsProgramme === []) {
            $validations[] = $this->info('UE-01', 'UE_PROGRAMME_OK',
                "UE rattachées au bon programme ({$ues->count()} UE, chaîne semestre vérifiée).");
        } else {
            $blocages[] = $this->bloqueur('UE-01', 'UE_HORS_PROGRAMME',
                'UE hors chaîne : '.implode(', ', $horsProgramme).'.');
            $corrections[] = 'Recréer ces UE dans le bon programme (transfert inter-programmes interdit, INV-1).';
        }

        if ($creditsIncoherents === []) {
            $validations[] = $this->info('UE-02', 'CREDITS_COHERENTS', 'Crédits UE cohérents (somme ECUE alignée).');
        } else {
            $blocages[] = $this->bloqueur('UE-02', 'CREDITS_INCOHERENTS',
                implode(' ; ', $creditsIncoherents).'.');
            $corrections[] = 'Aligner les crédits UE sur la somme de leurs ECUE (ou inversement, décision pédagogique).';
        }

        if ($sansVolume === []) {
            $validations[] = $this->info('EC-01', 'VOLUMES_OK', 'Volumes horaires ECUE renseignés.');
        } else {
            $avertissements[] = $this->alerte('EC-01', 'ECUE_SANS_VOLUME',
                'Sans volume CM/TD/TP : '.implode(', ', array_unique($sansVolume)).'.');
            $corrections[] = 'Saisir les volumes CM/TD/TP des ECUE listées (fiches matières).';
        }

        // 6. Couverture des semestres (un semestre sans UE = plan incomplet).
        $semestresSansUe = $semestres->filter(fn ($s) => $s->unitesEnseignement->isEmpty())->pluck('code')->all();
        if ($semestresSansUe === []) {
            $validations[] = $this->info('SEM-02', 'SEMESTRES_COUVERTS',
                'Tous les semestres sont couverts par au moins une UE.');
        } else {
            $blocages[] = $this->bloqueur('SEM-02', 'SEMESTRES_NON_COUVERTS',
                'Semestres sans UE : '.implode(', ', $semestresSansUe).'.');
            $corrections[] = 'Créer les UE manquantes des semestres '.implode(', ', $semestresSansUe).' (contenu pédagogique : responsable pédagogique, pas ici).';
        }

        // 7. Prérequis : graphe vérifié, cycle interdit (BFS).
        $cycle = $this->detecterCyclePrerequis($ues);
        if ($cycle === null) {
            $validations[] = $this->info('PRE-01', 'PREREQUIS_SANS_CYCLE', 'Prérequis sans cycle (graphe vérifié).');
        } else {
            $blocages[] = $this->bloqueur('PRE-01', 'PREREQUIS_CYCLE', 'Cycle détecté : '.$cycle.'.');
            $corrections[] = 'Retirer une arête du cycle de prérequis listé.';
        }

        // 8. Maquette : état + contenu + chemin de transition.
        $maquettes = $programme->maquettes->sortByDesc('version')->values();
        $maquetteInfo = $maquettes->map(fn (Maquette $m) => [
            'id' => $m->id,
            'version' => $m->version,
            'statut' => $m->statut,
            'annee' => $m->anneeUniversitaire?->libelle,
            'annee_active' => (bool) $m->anneeUniversitaire?->active,
            'mise_a_jour' => $m->updated_at?->format('d/m/Y H:i'),
            'validee_par' => $m->validee_par,
            'date_validation' => $m->date_validation?->format('d/m/Y H:i'),
        ])->all();

        $active = $maquettes->firstWhere('statut', 'active');
        $approuvee = $maquettes->where('statut', 'approved')->first();

        if ($active !== null) {
            $contenu = $this->contenuMaquette($active);
            if ($contenu['ues'] > 0 && $contenu['ecues'] > 0) {
                $validations[] = $this->info('MAQ-01', 'MAQUETTE_ACTIVE_OK',
                    "Maquette active v{$active->version} avec contenu ({$contenu['ues']} UE, {$contenu['ecues']} ECUE).");
            } else {
                $blocages[] = $this->bloqueur('MAQ-01', 'MAQUETTE_ACTIVE_VIDE',
                    "Maquette active v{$active->version} sans contenu exploitable.");
                $corrections[] = 'Créer une nouvelle version avec contenu (maquette active vide = anomalie structurelle).';
            }
        } elseif ($approuvee !== null) {
            $avertissements[] = $this->alerte('MAQ-02', 'MAQUETTE_APPROUVEE_NON_ACTIVE',
                "Maquette v{$approuvee->version} approuvée, en attente d'activation (approved → active, permission maquettes.approve).");
            $corrections[] = "Activer la maquette v{$approuvee->version} (bascule atomique, historique conservé) après décision pédagogique explicite.";
        } elseif ($maquettes->isNotEmpty()) {
            $courante = $maquettes->first();
            $blocages[] = $this->bloqueur('MAQ-03', 'MAQUETTE_NON_APPROUVEE',
                "Maquette v{$courante->version} au statut « {$courante->statut} » : chemin requis "
                ."{$courante->statut} → … → approved → active (transition directe vers active interdite).");
            $corrections[] = "Faire progresser la maquette v{$courante->version} dans le workflow (draft → review → approved → active).";
        } else {
            $blocages[] = $this->bloqueur('MAQ-04', 'MAQUETTE_ABSENTE', 'Aucune maquette pour ce programme.');
            $corrections[] = "Créer la maquette v1 (brouillon) du programme {$programme->code} pour l'année active.";
        }

        // 9. Régimes du plan (occurrences à régime vide = valeur par défaut
        // appliquée, signalée sans bloquer).
        if ($active !== null || $approuvee !== null) {
            $maquette = $active ?? $approuvee;
            $sansRegime = MaquetteUeEcue::whereHas('maquetteUe', fn ($q) => $q->where('maquette_id', $maquette->id))
                ->where(fn ($q) => $q->whereNull('regime')->orWhere('regime', ''))
                ->count();
            if ($sansRegime > 0) {
                $avertissements[] = $this->alerte('MAQ-06', 'REGIME_VIDE',
                    "{$sansRegime} occurrence(s) ECUE sans régime (repli : obligatoire).");
            }
        }

        // 10. Consommateurs (lecture seule : inscriptions + examens).
        $inscriptions = InscriptionAcademique::where('programme_id', $programme->id)->count();
        $examens = Examen::where('programme_id', $programme->id)->count();
        $consommateurs = [
            "inscriptions_academiques programme : {$inscriptions}",
            "examens programme : {$examens}",
            $active !== null
                ? 'inscrireEcue / genererEpreuves : débloqués (maquette active).'
                : 'inscrireEcue / genererEpreuves : BLOQUÉS sans maquette active (DomainException explicite, jamais silencieuse).',
        ];

        $recommandation = 'ATTENTE';
        if ($active !== null && $blocages === []) {
            $recommandation = 'ACTIVE_DEJA';
        } elseif ($active === null && $approuvee !== null && $blocages === []) {
            $recommandation = 'ACTIVABLE';
        }

        return [
            'programme' => $programme->code,
            'programme_id' => $programme->id,
            'programme_nom' => $programme->nom,
            'recommandation' => $recommandation,
            'validations' => $validations,
            'blocages' => $blocages,
            'avertissements' => $avertissements,
            'corrections' => array_values(array_unique($corrections)),
            'consommateurs' => $consommateurs,
            'maquettes' => $maquetteInfo,
        ];
    }

    /**
     * @return array{regle: string, code: string, gravite: string, detail: string}
     */
    private function info(string $regle, string $code, string $detail): array
    {
        return ['regle' => $regle, 'code' => $code, 'gravite' => 'INFO', 'detail' => $detail];
    }

    /**
     * @return array{regle: string, code: string, gravite: string, detail: string}
     */
    private function bloqueur(string $regle, string $code, string $detail): array
    {
        return ['regle' => $regle, 'code' => $code, 'gravite' => 'BLOCKER', 'detail' => $detail];
    }

    /**
     * @return array{regle: string, code: string, gravite: string, detail: string}
     */
    private function alerte(string $regle, string $code, string $detail): array
    {
        return ['regle' => $regle, 'code' => $code, 'gravite' => 'WARNING', 'detail' => $detail];
    }

    /**
     * Détecte un cycle dans le graphe des prérequis des ECUE données
     * (BFS depuis chaque nœud, lecture seule sur `ecue_prerequis`).
     */
    private function detecterCyclePrerequis(iterable $ues): ?string
    {
        $ids = collect($ues)->flatMap(fn ($ue) => $ue->ecues->pluck('id'))->unique()->values()->all();

        if ($ids === []) {
            return null;
        }

        $adjacence = [];
        foreach (DB::table('ecue_prerequis')->whereIn('matiere_id', $ids)->get() as $ligne) {
            $adjacence[(int) $ligne->matiere_id][] = (int) $ligne->prerequis_id;
        }

        foreach ($ids as $depart) {
            $visites = [];
            $file = [$depart];

            while ($file !== []) {
                $courant = array_shift($file);

                foreach ($adjacence[$courant] ?? [] as $suivant) {
                    if ($suivant === $depart) {
                        $matiere = Matiere::find($depart);

                        return 'cycle incluant '.($matiere?->code ?? "#{$depart}");
                    }

                    if (! isset($visites[$suivant])) {
                        $visites[$suivant] = true;
                        $file[] = $suivant;
                    }
                }
            }
        }

        return null;
    }

    /**
     * @return array{ues: int, ecues: int}
     */
    private function contenuMaquette(Maquette $maquette): array
    {
        $lignes = MaquetteUe::where('maquette_id', $maquette->id)->pluck('id');

        return [
            'ues' => $lignes->count(),
            'ecues' => $lignes->isEmpty()
                ? 0
                : MaquetteUeEcue::whereIn('maquette_ue_id', $lignes->all())->count(),
        ];
    }
}
