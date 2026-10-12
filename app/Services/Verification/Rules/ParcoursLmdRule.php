<?php

namespace App\Services\Verification\Rules;

use App\Models\Etudiant;
use App\Modules\Referentiel\Models\Niveau;
use App\Services\Verification\Anomalie;
use App\Services\Verification\VerificationRule;

/**
 * R4 — Cohérence du parcours LMD.
 *
 * Vérifie la cohérence entre programme, niveau, inscriptions
 * et la chaîne LMD (Niveau.ordre).
 */
class ParcoursLmdRule implements VerificationRule
{
    public function code(): string
    {
        return 'parcours.lmd';
    }

    public function libelle(): string
    {
        return 'Cohérence parcours LMD';
    }

    public function categorie(): string
    {
        return 'parcours';
    }

    public function verifier(Etudiant $etudiant): array
    {
        $anomalies = [];
        $inscriptions = $etudiant->inscriptionsAcademiques()
            ->with('anneeUniversitaire')
            ->orderBy('annee_universitaire_id')
            ->get();

        // Programme non renseigné mais inscription existante.
        if ($etudiant->programme_id === null && $inscriptions->isNotEmpty()) {
            $anomalies[] = new Anomalie(
                regle: $this->code(),
                categorie: $this->categorie(),
                gravite: 'erreur',
                message: 'Programme non renseigné malgré des inscriptions existantes.',
                justification: "programme_id = null, {$inscriptions->count()} inscription(s) trouvée(s).",
                recommandation: 'Rattacher l\'étudiant au programme LMD approprié.',
                champ: 'programme_id',
            );
        }

        // Niveau incohérent avec le programme (chaîne Niveau.ordre).
        if ($etudiant->niveau_id !== null && $etudiant->programme_id !== null) {
            $niveau = Niveau::find($etudiant->niveau_id);

            if ($niveau !== null && $niveau->programme_id !== $etudiant->programme_id) {
                $anomalies[] = new Anomalie(
                    regle: $this->code(),
                    categorie: $this->categorie(),
                    gravite: 'erreur',
                    message: 'Le niveau ne correspond pas au programme de l\'étudiant.',
                    justification: "Niveau programme_id = {$niveau->programme_id}, étudiant programme_id = {$etudiant->programme_id}.",
                    recommandation: 'Corriger le rattachement niveau/programme.',
                    donnees: ['niveau_programme' => $niveau->programme_id, 'etudiant_programme' => $etudiant->programme_id],
                );
            }
        }

        // Programme étudiant ≠ programme inscription la plus récente.
        $derniere = $inscriptions->last();

        if ($derniere !== null && $etudiant->programme_id !== null && $derniere->programme_id !== null) {
            if ($derniere->programme_id !== $etudiant->programme_id) {
                $anomalies[] = new Anomalie(
                    regle: $this->code(),
                    categorie: $this->categorie(),
                    gravite: 'avertissement',
                    message: 'Le programme de l\'étudiant diffère de sa dernière inscription.',
                    justification: "Programme étudiant = {$etudiant->programme_id}, inscription = {$derniere->programme_id}.",
                    recommandation: 'Vérifier et mettre à jour le programme de rattachement.',
                    donnees: ['programme_etudiant' => $etudiant->programme_id, 'programme_inscription' => $derniere->programme_id],
                );
            }
        }

        // Niveau avancé (ordre > 1) sans inscription ou historique au niveau précédent (§5, §23 CAS 4)
        if ($derniere !== null && $derniere->niveau_id !== null) {
            $niveauActuel = Niveau::find($derniere->niveau_id);
            if ($niveauActuel !== null && $niveauActuel->ordre > 1) {
                $aNiveauPrecedent = $inscriptions->contains(function ($i) use ($niveauActuel) {
                    if ($i->niveau_id === null) {
                        return false;
                    }
                    $n = Niveau::find($i->niveau_id);

                    return $n !== null && $n->programme_id === $niveauActuel->programme_id && $n->ordre === ($niveauActuel->ordre - 1);
                });

                if (! $aNiveauPrecedent) {
                    $anomalies[] = new Anomalie(
                        regle: $this->code(),
                        categorie: $this->categorie(),
                        gravite: 'bloquant',
                        message: "Niveau {$niveauActuel->code} sans historique de niveau L1 préalable validé.",
                        justification: "Inscription en {$niveauActuel->code} (ordre {$niveauActuel->ordre}) sans inscription préalable au niveau inférieur.",
                        recommandation: "Vérifier le parcours académique préalable ou intégrer le dossier d'admission.",
                        champ: 'niveau_id',
                    );
                }
            }
        }

        // Progression LMD : inscription N+1 sans décision favorable en N.
        $precedente = null;

        foreach ($inscriptions as $inscription) {
            if ($precedente !== null && $inscription->niveau_id !== null && $precedente->niveau_id !== null) {
                $niveauPrec = Niveau::find($precedente->niveau_id);
                $niveauCourant = Niveau::find($inscription->niveau_id);

                if ($niveauPrec !== null && $niveauCourant !== null
                    && $niveauCourant->programme_id === $niveauPrec->programme_id
                    && $niveauCourant->ordre > $niveauPrec->ordre
                    && ! in_array($precedente->decision, ['Admis(e)', 'Admis(e) — dettes de crédits'], true)) {
                    $anomalies[] = new Anomalie(
                        regle: $this->code(),
                        categorie: $this->categorie(),
                        gravite: 'avertissement',
                        message: 'Progression de niveau sans décision favorable à l\'année précédente.',
                        justification: "Passage {$niveauPrec->code} → {$niveauCourant->code} sans décision « Admis(e) » (décision précédente : {$precedente->decision}).",
                        recommandation: 'Vérifier la décision de l\'année précédente.',
                        donnees: ['decision_precedente' => $precedente->decision],
                    );
                }
            }
            $precedente = $inscription;
        }

        return $anomalies;
    }
}
