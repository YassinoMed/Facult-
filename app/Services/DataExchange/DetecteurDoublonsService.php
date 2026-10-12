<?php

namespace App\Services\DataExchange;

use App\Services\DataExchange\Contracts\ImportDefinitionInterface;
use App\Services\DataExchange\Contracts\ImportSimilitude;
use App\Services\Io\NormaliseurEnTetes;

/**
 * Détection graduée des doublons (PHASE 5) : exacte (fichier/clé, déjà
 * tranchée par le moteur), mise à jour, conflit, potentiel, non résolu.
 * Zéro requête ajoutée quand l'existant est déjà résolu (l'objet trouvé
 * par `trouverExistant()` est réutilisé) ; au plus UNE requête bornée
 * pour la similarité, seulement sans identifiant fort. Ne fusionne
 * JAMAIS : les cas ambigus partent en `a_revoir` (revue PHASE 4).
 */
class DetecteurDoublonsService
{
    public const VERSION = '1.0';

    public const NOUVEAU = 'nouveau';

    public const MISE_A_JOUR = 'mise_a_jour';

    public const CONFLIT = 'conflit';

    public const POTENTIEL = 'potentiel';

    public const NON_RESOLU = 'non_resolu';

    /**
     * @param  array<string, mixed>  $ligne  (clés canoniques + `ligne`)
     * @return array{statut: string, candidat: ?array{id: int, nom: string}, divergences: array<int, string>}
     */
    public function classifier(array $ligne, ImportDefinitionInterface $definition, ?object $existant): array
    {
        if ($existant !== null) {
            $divergences = $this->divergencesIdentite($ligne, $existant);

            if ($divergences === []) {
                return ['statut' => self::MISE_A_JOUR, 'candidat' => null, 'divergences' => []];
            }

            return [
                'statut' => self::CONFLIT,
                'candidat' => ['id' => (int) ($existant->id ?? 0), 'nom' => (string) ($existant->nom ?? '?')],
                'divergences' => $divergences,
            ];
        }

        if (! $this->aIdentifiantsForts($ligne)) {
            // Entités non nominatives (salles, groupes…) : aucun sens à
            // exiger un identifiant de personne → comportement historique.
            if (! $definition instanceof ImportSimilitude) {
                return ['statut' => self::NOUVEAU, 'candidat' => null, 'divergences' => []];
            }

            $candidat = $this->meilleurCandidat($ligne, $definition->candidatsSimilaires($ligne));

            if ($candidat !== null) {
                return ['statut' => self::POTENTIEL, 'candidat' => $candidat, 'divergences' => []];
            }

            return ['statut' => self::NON_RESOLU, 'candidat' => null, 'divergences' => []];
        }

        return ['statut' => self::NOUVEAU, 'candidat' => null, 'divergences' => []];
    }

    /**
     * Identifiants forts : au moins un de matricule/CIN/e-mail renseigné.
     */
    private function aIdentifiantsForts(array $ligne): bool
    {
        foreach (['matricule', 'cin', 'email'] as $champ) {
            if (trim((string) ($ligne[$champ] ?? '')) !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * Divergence d'identité : nom/prénom/naissance normalisés (insensible
     * casse/accents/espaces) entre la ligne et la fiche existante.
     * Champs absents d'un côté : jamais une divergence (pas de devinette).
     *
     * @return array<int, string>
     */
    private function divergencesIdentite(array $ligne, object $existant): array
    {
        $divergences = [];

        foreach (['nom' => 'nom', 'prenom' => 'prénom', 'date_naissance' => 'naissance'] as $champ => $libelle) {
            $attendu = trim((string) ($ligne[$champ] ?? ''));
            $actuel = $existant->{$champ} ?? null;
            $actuel = $actuel instanceof \DateTimeInterface ? $actuel->format('Y-m-d') : (string) $actuel;
            $actuel = trim($actuel);

            if ($attendu === '' || $actuel === '') {
                continue;
            }

            if (NormaliseurEnTetes::cle($attendu) !== NormaliseurEnTetes::cle($actuel)) {
                $divergences[] = $libelle;
            }
        }

        return $divergences;
    }

    /**
     * @param  array<int, array{id: int, nom: string, prenom: string, naissance: ?string}>  $candidats
     */
    private function meilleurCandidat(array $ligne, array $candidats): ?array
    {
        $nom = NormaliseurEnTetes::cle(trim((string) ($ligne['nom'] ?? '')));
        $prenom = NormaliseurEnTetes::cle(trim((string) ($ligne['prenom'] ?? '')));
        $naissance = trim((string) ($ligne['date_naissance'] ?? ''));

        if ($nom === '' || $prenom === '') {
            return null;
        }

        foreach ($candidats as $candidat) {
            $nomCandidat = NormaliseurEnTetes::cle($candidat['nom']);
            $prenomCandidat = NormaliseurEnTetes::cle($candidat['prenom']);
            $naissanceCandidat = (string) ($candidat['naissance'] ?? '');

            $nomEgal = $nomCandidat === $nom;
            $nomProche = ! $nomEgal && levenshtein($nomCandidat, $nom) <= 2;
            $prenomEgal = $prenomCandidat === $prenom;
            $naissanceEgale = $naissance !== '' && $naissanceCandidat === $naissance;

            // Règle explicite et conservatrice : jamais sur le seul nom.
            if (($nomEgal && ($prenomEgal || $naissanceEgale))
                || ($nomProche && $prenomEgal && $naissanceEgale)) {
                return ['id' => $candidat['id'], 'nom' => $candidat['nom']];
            }
        }

        return null;
    }
}
