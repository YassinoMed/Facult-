<?php

namespace App\Services\Identity;

use App\Models\Etudiant;
use App\Services\StudentLookupService;

/**
 * Préparation des données de pré-remplissage (§10-12, §20, §24).
 *
 * §20 Priorité : une donnée déjà VALIDÉE dans le dossier étudiant ne
 * doit jamais être écrasée silencieusement par une lecture OCR — si un
 * dossier existe, SES données font foi pour le formulaire ; l'OCR ne
 * sert qu'aux champs que la BD ne possède pas.
 *
 * La réponse contient UNIQUEMENT des champs autorisés (whitelist) :
 * jamais password, hash, token, identifiant interne, ni contenu OCR
 * brut non filtré. Les originaux arabes (§9/§12) sont transmis par
 * champ : valeur brute arabe + translittération — pour la
 * vérification humaine comparative, jamais pour la persistance
 * automatique.
 */
final class IdentityPrefillService
{
    public function __construct(private readonly StudentLookupService $lookup) {}

    /**
     * @param  array<int, array{champ: string, extrait: ?string, enregistre: ?string, verdict: string}>  $divergences
     * @param  array<int, string>  $alertes
     * @return array<string, mixed>
     */
    public function construire(OcrResult $resultat, ?Etudiant $existant, array $divergences, array $alertes): array
    {
        $reponse = [
            'document_type' => $resultat->documentType,
            'language' => $resultat->language,
            'data' => $resultat->champsFormulaire(),
            'originals' => $resultat->originauxFormulaire(),
            'confidence' => $resultat->confidenceFormulaire(),
            'seuils' => [
                'eleve' => (float) config('identity.confiance.eleve'),
                'moyen' => (float) config('identity.confiance.moyen'),
            ],
            'existing_student' => $existant !== null,
            'alerts' => $alertes,
        ];

        if ($resultat->documentType === 'passeport' && $resultat->mrz !== null) {
            $reponse['mrz_valide'] = $resultat->mrz === 'valide';
        }

        if ($divergences !== []) {
            $reponse['divergences'] = $divergences;
        }

        if ($existant !== null) {
            // §20 : le dossier validé fait foi — les données OCR ne
            // remplacent jamais silencieusement une donnée validée.
            $reponse['etudiant'] = $this->lookup->donneesPrefill($existant);
            $reponse['source'] = 'dossier_existant';
        }

        return $reponse;
    }
}
