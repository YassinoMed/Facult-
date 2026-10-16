<?php

namespace App\Services;

use InvalidArgumentException;

/**
 * Validateur du schéma JSON des modèles e-mail (STUDIO-03 §5) : exécuté
 * CÔTÉ SERVEUR à chaque sauvegarde/aperçu/publication, indépendamment de
 * toute validation navigateur. Rejette : types inconnus, HTML/CSS/JS
 * arbitraires, URL non autorisées, variables hors registre, dimensions
 * hors bornes, documents trop volumineux.
 */
class ValidateurSchemaCourriel
{
    public const VERSION_SCHEMA = 1;

    public const TYPES_BLOCS = ['titre', 'texte', 'bouton', 'image', 'separateur', 'liste', 'espaceur', 'colonnes', 'carte', 'tableau', 'signature'];

    /** Blocs autorisés comme sous-blocs de `colonnes` (profondeur 1, pas d'imbrication). */
    public const SOUS_BLOCS_COLONNES = ['titre', 'texte', 'bouton', 'image', 'separateur', 'liste', 'espaceur'];

    public const POLICES = ['arial', 'georgia', 'systeme'];

    public const STYLES_BOUTON = ['arrondi', 'angle'];

    public const EXTENSIONS_IMAGE = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

    public const TAILLE_MAX_OCTETS = 204800;

    public const BLOCS_MAX = 30;

    /**
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed> schéma normalisé
     *
     * @throws InvalidArgumentException
     */
    public function valider(array $schema): array
    {
        if (($schema['schema_version'] ?? null) !== self::VERSION_SCHEMA) {
            throw new InvalidArgumentException('Version de schéma non prise en charge.');
        }

        $meta = $schema['meta'] ?? null;
        if (! is_array($meta)) {
            throw new InvalidArgumentException('Métadonnées manquantes.');
        }

        $this->validerTexteCourt((string) ($meta['objet'] ?? ''), 'objet', 150, true);
        $this->validerTexteCourt((string) ($meta['preheader'] ?? ''), 'preheader', 150, false);

        if (isset($meta['categorie']) && ! is_string($meta['categorie'])) {
            throw new InvalidArgumentException('Catégorie invalide.');
        }

        $theme = $schema['theme'] ?? null;
        if (! is_array($theme)) {
            throw new InvalidArgumentException('Thème manquant.');
        }
        $themeNormalise = $this->validerTheme($theme);

        $blocs = $schema['blocs'] ?? null;
        if (! is_array($blocs) || $blocs === [] || count($blocs) > self::BLOCS_MAX) {
            throw new InvalidArgumentException('Le modèle doit contenir entre 1 et '.self::BLOCS_MAX.' blocs.');
        }

        $blocsNormalises = [];
        $identifiants = [];
        foreach (array_values($blocs) as $index => $bloc) {
            if (! is_array($bloc)) {
                throw new InvalidArgumentException('Bloc n°'.($index + 1).' invalide.');
            }
            $normalise = $this->validerBloc($bloc, $index);
            foreach ($this->identifiantsBloc($normalise) as $identifiant) {
                if (in_array($identifiant, $identifiants, true)) {
                    throw new InvalidArgumentException('Identifiant de bloc dupliqué : '.$identifiant.'.');
                }
                $identifiants[] = $identifiant;
            }
            $blocsNormalises[] = $normalise;
        }

        if (mb_strlen((string) json_encode($schema, JSON_UNESCAPED_UNICODE)) > self::TAILLE_MAX_OCTETS) {
            throw new InvalidArgumentException('Document trop volumineux.');
        }

        return [
            'schema_version' => self::VERSION_SCHEMA,
            'meta' => [
                'objet' => trim((string) $meta['objet']),
                'preheader' => trim((string) ($meta['preheader'] ?? '')),
                'categorie' => isset($meta['categorie']) ? mb_substr((string) $meta['categorie'], 0, 40) : null,
            ],
            'theme' => $themeNormalise,
            'blocs' => $blocsNormalises,
        ];
    }

    /**
     * @param  array<string, mixed>  $theme
     * @return array<string, mixed>
     *
     * @throws InvalidArgumentException
     */
    public function validerTheme(array $theme): array
    {
        foreach (['couleur_principale', 'couleur_accent', 'fond', 'fond_contenu'] as $cle) {
            $valeur = (string) ($theme[$cle] ?? '');
            if (! preg_match('/\A#[0-9a-fA-F]{6}\z/', $valeur)) {
                throw new InvalidArgumentException('Couleur invalide : '.$cle.'.');
            }
        }

        $largeur = (int) ($theme['largeur'] ?? 0);
        if ($largeur < 480 || $largeur > 800) {
            throw new InvalidArgumentException('Largeur hors bornes (480 à 800).');
        }

        $police = (string) ($theme['police'] ?? 'arial');
        if (! in_array($police, self::POLICES, true)) {
            throw new InvalidArgumentException('Police non autorisée.');
        }

        $styleBouton = (string) ($theme['style_bouton'] ?? 'arrondi');
        if (! in_array($styleBouton, self::STYLES_BOUTON, true)) {
            throw new InvalidArgumentException('Style de bouton non autorisé.');
        }

        $logo = trim((string) ($theme['logo_url'] ?? ''));
        if ($logo !== '') {
            $this->validerUrlImage($logo);
        }

        return [
            'couleur_principale' => $theme['couleur_principale'],
            'couleur_accent' => $theme['couleur_accent'],
            'fond' => $theme['fond'],
            'fond_contenu' => $theme['fond_contenu'],
            'largeur' => $largeur,
            'police' => $police,
            'style_bouton' => $styleBouton,
            'logo_url' => $logo !== '' ? $logo : null,
            'nom_etablissement' => mb_substr(trim((string) ($theme['nom_etablissement'] ?? '')), 0, 120),
            'signature' => mb_substr(trim((string) ($theme['signature'] ?? '')), 0, 500),
            'mentions' => mb_substr(trim((string) ($theme['mentions'] ?? '')), 0, 500),
        ];
    }

    /**
     * Tous les identifiants d'un bloc normalisé (niveau + sous-blocs).
     *
     * @param  array<string, mixed>  $bloc
     * @return list<string>
     */
    private function identifiantsBloc(array $bloc): array
    {
        $identifiants = [];
        if (is_string($bloc['id'] ?? null) && $bloc['id'] !== '') {
            $identifiants[] = $bloc['id'];
        }

        if (($bloc['type'] ?? null) === 'colonnes') {
            foreach ((array) ($bloc['colonnes'] ?? []) as $colonne) {
                foreach ((array) $colonne as $sousBloc) {
                    if (is_array($sousBloc) && is_string($sousBloc['id'] ?? null) && $sousBloc['id'] !== '') {
                        $identifiants[] = $sousBloc['id'];
                    }
                }
            }
        }

        return $identifiants;
    }

    /**
     * @param  array<string, mixed>  $bloc
     * @return array<string, mixed>
     *
     * @throws InvalidArgumentException
     */
    private function validerBloc(array $bloc, int $index): array
    {
        $etiquette = 'Bloc n°'.($index + 1);
        $type = $bloc['type'] ?? null;

        if (! in_array($type, self::TYPES_BLOCS, true)) {
            throw new InvalidArgumentException($etiquette.' : type inconnu.');
        }

        $alignement = $bloc['alignement'] ?? 'gauche';
        if (! in_array($alignement, ['gauche', 'centre', 'droite'], true)) {
            throw new InvalidArgumentException($etiquette.' : alignement invalide.');
        }

        $identifiant = $bloc['id'] ?? null;
        if ($identifiant !== null && ! preg_match('/\A[a-z0-9-]{1,32}\z/', (string) $identifiant)) {
            throw new InvalidArgumentException($etiquette.' : identifiant invalide.');
        }

        $normalise = match ($type) {
            'titre' => [
                'type' => 'titre',
                'texte' => $this->validerTexteCourt((string) ($bloc['texte'] ?? ''), $etiquette.' (titre)', 200, true),
                'niveau' => in_array($bloc['niveau'] ?? null, ['h1', 'h2', 'h3'], true) ? $bloc['niveau'] : 'h2',
                'alignement' => $alignement,
            ],
            'texte' => [
                'type' => 'texte',
                'contenu' => $this->validerTexteLong((string) ($bloc['contenu'] ?? ''), $etiquette),
                'alignement' => $alignement,
            ],
            'bouton' => [
                'type' => 'bouton',
                'libelle' => $this->validerTexteCourt((string) ($bloc['libelle'] ?? ''), $etiquette.' (libellé)', 80, true),
                'url' => $this->validerUrlAction((string) ($bloc['url'] ?? '')),
                'alignement' => $alignement,
            ],
            'image' => [
                'type' => 'image',
                'url' => $this->validerUrlImage((string) ($bloc['url'] ?? '')),
                'texte_alt' => $this->validerTexteCourt((string) ($bloc['texte_alt'] ?? ''), $etiquette.' (alt)', 200, true),
                'largeur' => $this->validerEntierBorne($bloc['largeur'] ?? null, 100, 600, $etiquette.' (largeur)'),
                'alignement' => $alignement,
            ],
            'separateur' => [
                'type' => 'separateur',
                'style' => in_array($bloc['style'] ?? null, ['fin', 'epais', 'pointille'], true) ? $bloc['style'] : 'fin',
                'alignement' => $alignement,
            ],
            'liste' => [
                'type' => 'liste',
                'elements' => $this->validerListe($bloc['elements'] ?? null, $etiquette),
                'alignement' => $alignement,
            ],
            'espaceur' => [
                'type' => 'espaceur',
                'hauteur' => $this->validerEntierBorne($bloc['hauteur'] ?? null, 8, 80, $etiquette.' (hauteur)'),
                'alignement' => $alignement,
            ],
            'colonnes' => [
                'type' => 'colonnes',
                'colonnes' => $this->validerColonnes($bloc['colonnes'] ?? null, $etiquette),
                'alignement' => $alignement,
            ],
            'carte' => [
                'type' => 'carte',
                'titre' => $this->validerTexteCourt((string) ($bloc['titre'] ?? ''), $etiquette.' (titre)', 120, true),
                'texte' => $this->validerTexteLong((string) ($bloc['texte'] ?? ''), $etiquette.' (texte)'),
                'couleur_bordure' => $this->validerCouleurOptionnelle($bloc['couleur_bordure'] ?? null, $etiquette),
                'alignement' => $alignement,
            ],
            'tableau' => [
                'type' => 'tableau',
                'entetes' => $this->validerEntetesTableau($bloc['entetes'] ?? null, $etiquette),
                'lignes' => $this->validerLignesTableau($bloc['lignes'] ?? null, $bloc['entetes'] ?? null, $etiquette),
                'alignement' => $alignement,
            ],
            'signature' => [
                'type' => 'signature',
                'texte' => $this->validerTexteCourt((string) ($bloc['texte'] ?? ''), $etiquette.' (signature)', 500, false),
                'alignement' => $alignement,
            ],
        };

        $normalise['id'] = $identifiant !== null ? (string) $identifiant : null;
        $normalise['verrouille'] = (bool) ($bloc['verrouille'] ?? false);

        return $normalise;
    }

    /**
     * Colonnes : exactement 2 colonnes de 0 à 6 sous-blocs simples
     * (imbrication interdite, verrou interdit en sous-bloc).
     *
     * @return array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>}
     *
     * @throws InvalidArgumentException
     */
    private function validerColonnes(mixed $colonnes, string $champ): array
    {
        if (! is_array($colonnes) || count(array_values($colonnes)) !== 2) {
            throw new InvalidArgumentException($champ.' : exactement 2 colonnes.');
        }

        $normalisees = [];
        foreach (array_values($colonnes) as $position => $colonne) {
            if (! is_array($colonne) || count($colonne) > 6) {
                throw new InvalidArgumentException($champ.' : colonne '.($position + 1).' de 0 à 6 sous-blocs.');
            }

            $sousBlocs = [];
            foreach (array_values($colonne) as $index => $sousBloc) {
                if (! is_array($sousBloc)) {
                    throw new InvalidArgumentException($champ.' : sous-bloc invalide.');
                }

                $type = $sousBloc['type'] ?? null;
                if (! in_array($type, self::SOUS_BLOCS_COLONNES, true)) {
                    throw new InvalidArgumentException($champ.' : sous-bloc non autorisé (imbrication interdite).');
                }

                if (! empty($sousBloc['verrouille'])) {
                    throw new InvalidArgumentException($champ.' : verrou interdit en sous-bloc.');
                }

                $sousBlocs[] = $this->validerBloc(
                    array_merge($sousBloc, ['verrouille' => false]),
                    $index
                );
            }
            $normalisees[] = $sousBlocs;
        }

        return $normalisees;
    }

    /**
     * @throws InvalidArgumentException
     */
    private function validerCouleurOptionnelle(mixed $valeur, string $champ): ?string
    {
        if ($valeur === null || trim((string) $valeur) === '') {
            return null;
        }

        if (! preg_match('/\A#[0-9a-fA-F]{6}\z/', trim((string) $valeur))) {
            throw new InvalidArgumentException($champ.' : couleur invalide.');
        }

        return trim((string) $valeur);
    }

    /**
     * @return list<string>
     *
     * @throws InvalidArgumentException
     */
    private function validerEntetesTableau(mixed $entetes, string $champ): array
    {
        if (! is_array($entetes) || $entetes === [] || count($entetes) > 6) {
            throw new InvalidArgumentException($champ.' : tableau de 1 à 6 colonnes.');
        }

        $normalises = [];
        foreach (array_values($entetes) as $entete) {
            $normalises[] = $this->validerTexteCourt((string) $entete, $champ.' (en-tête)', 60, true);
        }

        return $normalises;
    }

    /**
     * @return list<list<string>>
     *
     * @throws InvalidArgumentException
     */
    private function validerLignesTableau(mixed $lignes, mixed $entetes, string $champ): array
    {
        $colonnes = is_array($entetes) ? count(array_values($entetes)) : 0;

        if (! is_array($lignes) || count($lignes) > 20) {
            throw new InvalidArgumentException($champ.' : tableau de 0 à 20 lignes.');
        }

        $normalisees = [];
        foreach (array_values($lignes) as $ligne) {
            if (! is_array($ligne) || count(array_values($ligne)) !== $colonnes) {
                throw new InvalidArgumentException($champ.' : chaque ligne doit avoir '.$colonnes.' cellules.');
            }

            $cellules = [];
            foreach (array_values($ligne) as $cellule) {
                $texte = $this->validerTexteCourt((string) $cellule, $champ.' (cellule)', 100, false);
                $cellules[] = $texte;
            }
            $normalisees[] = $cellules;
        }

        return $normalisees;
    }

    /**
     * @throws InvalidArgumentException
     */
    private function validerTexteCourt(string $texte, string $champ, int $plafond, bool $obligatoire): string
    {
        $texte = trim($texte);

        if ($obligatoire && $texte === '') {
            throw new InvalidArgumentException($champ.' : vide.');
        }

        if (mb_strlen($texte) > $plafond) {
            throw new InvalidArgumentException($champ.' : trop long.');
        }

        $this->rejeterContenuActif($texte, $champ);
        $this->validerVariables($texte, $champ);

        return $texte;
    }

    /**
     * @throws InvalidArgumentException
     */
    private function validerTexteLong(string $texte, string $champ): string
    {
        $texte = trim($texte);

        if ($texte === '' || mb_strlen($texte) > 5000) {
            throw new InvalidArgumentException($champ.' : vide ou trop long.');
        }

        $this->rejeterContenuActif($texte, $champ);
        $this->validerVariables($texte, $champ);

        return $texte;
    }

    /**
     * Rejette tout contenu actif ou dangereux (XSS, HTML arbitraire).
     *
     * @throws InvalidArgumentException
     */
    private function rejeterContenuActif(string $texte, string $champ): void
    {
        if (
            preg_match('/<\s*(script|iframe|object|embed|form|style|link|meta|base|video|audio|input|button|select|textarea)\b/i', $texte)
            || preg_match('/\bon\w+\s*=/i', $texte)
            || preg_match('/javascript\s*:/i', $texte)
            || preg_match('/data\s*:\s*text\/html/i', $texte)
            || preg_match('/vbscript\s*:/i', $texte)
            || preg_match('/expression\s*\(/i', $texte)
        ) {
            throw new InvalidArgumentException($champ.' : contenu actif interdit.');
        }

        // Aucune balise HTML n'est autorisée dans les contenus administrables.
        if ($texte !== strip_tags($texte)) {
            throw new InvalidArgumentException($champ.' : HTML interdit (texte brut uniquement).');
        }
    }

    /**
     * @throws InvalidArgumentException
     */
    private function validerVariables(string $texte, string $champ): void
    {
        $sansValides = (string) preg_replace('/\{\{\s*[a-z_][a-z0-9_]*\s*\}\}/', '', $texte);
        if (str_contains($sansValides, '{{') || str_contains($sansValides, '}}')) {
            throw new InvalidArgumentException($champ.' : syntaxe de variable invalide.');
        }

        if (! preg_match_all('/\{\{\s*([a-z_][a-z0-9_]*)\s*\}\}/', $texte, $correspondances)) {
            return;
        }

        foreach ($correspondances[1] as $variable) {
            if (! $this->variableConnue($variable)) {
                throw new InvalidArgumentException($champ.' : variable inconnue « '.$variable.' ».');
            }
        }
    }

    private function variableConnue(string $variable): bool
    {
        return is_array(config('courriels.variables_autorisees.'.$variable));
    }

    /**
     * @throws InvalidArgumentException
     */
    private function validerUrlAction(string $url): string
    {
        $url = trim($url);

        if ($url === '' || mb_strlen($url) > 500) {
            throw new InvalidArgumentException('URL d’action vide ou trop longue.');
        }

        $parties = parse_url($url);
        $protocole = mb_strtolower((string) ($parties['scheme'] ?? ''));

        if ($protocole === '') {
            // Variable URL exacte et unique (ex. `{{url_action}}`) : seule
            // forme dynamique autorisée ; résolue puis revalidée au rendu
            // (bouton omis si non résolue — jamais de lien vide émis).
            if (preg_match('/\A\{\{\s*([a-z_][a-z0-9_]*)\s*\}\}\z/', $url, $correspondance)) {
                $definition = config('courriels.variables_autorisees.'.$correspondance[1]);
                if (is_array($definition) && ($definition['type'] ?? null) === 'url') {
                    return $url;
                }
            }

            throw new InvalidArgumentException('Protocole d’URL interdit (https ou mailto uniquement).');
        }

        if (! in_array($protocole, ['https', 'mailto'], true)) {
            throw new InvalidArgumentException('Protocole d’URL interdit (https ou mailto uniquement).');
        }

        if ($protocole === 'mailto') {
            $adresse = mb_substr($url, 7);
            if (filter_var($adresse, FILTER_VALIDATE_EMAIL) === false) {
                throw new InvalidArgumentException('Adresse mailto invalide.');
            }

            return $url;
        }

        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            throw new InvalidArgumentException('URL invalide.');
        }

        return $url;
    }

    /**
     * @throws InvalidArgumentException
     */
    private function validerUrlImage(string $url): string
    {
        $url = $this->validerUrlAction($url);

        if (! str_starts_with(mb_strtolower((string) parse_url($url, PHP_URL_SCHEME)), 'https')) {
            throw new InvalidArgumentException('Image : protocole https uniquement.');
        }

        $extension = mb_strtolower((string) pathinfo((string) parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION));

        if (! in_array($extension, self::EXTENSIONS_IMAGE, true)) {
            throw new InvalidArgumentException('Image : extension non autorisée.');
        }

        return $url;
    }

    /**
     * @return list<string>
     *
     * @throws InvalidArgumentException
     */
    private function validerListe(mixed $elements, string $champ): array
    {
        if (! is_array($elements) || $elements === [] || count($elements) > 20) {
            throw new InvalidArgumentException($champ.' : liste de 1 à 20 éléments.');
        }

        $normalises = [];
        foreach (array_values($elements) as $element) {
            $normalises[] = $this->validerTexteCourt((string) $element, $champ.' (élément)', 200, true);
        }

        return $normalises;
    }

    /**
     * @throws InvalidArgumentException
     */
    private function validerEntierBorne(mixed $valeur, int $min, int $max, string $champ): int
    {
        if (! is_numeric($valeur) || (int) $valeur < $min || (int) $valeur > $max) {
            throw new InvalidArgumentException($champ.' : hors bornes.');
        }

        return (int) $valeur;
    }
}
