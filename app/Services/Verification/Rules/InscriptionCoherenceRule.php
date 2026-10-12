<?php

namespace App\Services\Verification\Rules;

use App\Models\Etudiant;
use App\Services\Verification\Anomalie;
use App\Services\Verification\VerificationRule;

/**
 * R3 — Cohérence des inscriptions académiques.
 *
 * Détecte les incohérences entre le statut de l'étudiant et
 * ses inscriptions (validé sans inscription, doubles inscriptions,
 * etc.).
 */
class InscriptionCoherenceRule implements VerificationRule
{
    public function code(): string
    {
        return 'inscription.coherence';
    }

    public function libelle(): string
    {
        return 'Cohérence des inscriptions';
    }

    public function categorie(): string
    {
        return 'inscription';
    }

    public function verifier(Etudiant $etudiant): array
    {
        $anomalies = [];
        $inscriptions = $etudiant->inscriptionsAcademiques()->with('anneeUniversitaire')->get();

        $inscriptionActive = $inscriptions->whereIn('statut', ['en_cours', 'valide'])->isNotEmpty();

        // Validé sans inscription active (Cas bloquant critique §23 CAS 2).
        // CAS 9 : un historique valide (années antérieures) rend
        // l'inscription « active » aux yeux du moteur (statuts
        // en_cours/valide sans borne d'année) : aucun faux bloquant
        // pour les anciens étudiants.
        if ($etudiant->statut === 'valide' && ! $inscriptionActive) {

            $anomalies[] = new Anomalie(
                regle: $this->code(),
                categorie: $this->categorie(),
                gravite: 'bloquant',
                message: 'Étudiant validé sans aucune inscription académique active.',
                justification: 'Statut = « valide », aucune inscription en_cours ou valide trouvée.',
                recommandation: 'Créer une inscription académique ou revoir le statut.',
                donnees: ['statut' => $etudiant->statut, 'inscriptions_count' => $inscriptions->count()],
                champ: 'statut',
            );
        }

        // En attente avec inscription en cours.
        if ($etudiant->statut === 'en_attente' && $inscriptions->where('statut', 'en_cours')->isNotEmpty()) {
            $anomalies[] = new Anomalie(
                regle: $this->code(),
                categorie: $this->categorie(),
                gravite: 'avertissement',
                message: 'Étudiant en attente mais avec une inscription en cours.',
                justification: 'Statut étudiant = « en_attente » alors qu\'une inscription est « en_cours ».',
                recommandation: 'Valider le statut de l\'étudiant ou suspendre l\'inscription.',
                donnees: ['statut' => $etudiant->statut],
            );
        }

        // Double inscription même année universitaire.
        $parAnnee = $inscriptions->groupBy('annee_universitaire_id');

        foreach ($parAnnee as $anneeId => $inscriptionsAnnee) {
            if ($inscriptionsAnnee->count() > 1) {
                $libelle = $inscriptionsAnnee->first()->anneeUniversitaire?->libelle ?? "ID:{$anneeId}";
                $anomalies[] = new Anomalie(
                    regle: $this->code(),
                    categorie: $this->categorie(),
                    gravite: 'bloquant',
                    message: "Double inscription pour l'année {$libelle}.",
                    justification: "{$inscriptionsAnnee->count()} inscriptions trouvées pour la même année universitaire.",
                    recommandation: 'Supprimer l\'inscription en double ou vérifier le dossier.',
                    donnees: ['annee' => $libelle, 'nb_inscriptions' => $inscriptionsAnnee->count()],
                );
            }
        }

        // Inscription valide mais moyenne < seuil.
        foreach ($inscriptions->where('statut', 'valide') as $inscription) {
            if ($inscription->moyenne_annuelle !== null && (float) $inscription->moyenne_annuelle < 10.0) {
                $anomalies[] = new Anomalie(
                    regle: $this->code(),
                    categorie: $this->categorie(),
                    gravite: 'avertissement',
                    message: 'Inscription validée avec moyenne inférieure à 10.',
                    justification: "Moyenne annuelle = {$inscription->moyenne_annuelle} pour une inscription au statut « valide ».",
                    recommandation: 'Vérifier la décision de validation.',
                    donnees: ['moyenne' => $inscription->moyenne_annuelle],
                );
            }
        }

        return $anomalies;
    }
}
