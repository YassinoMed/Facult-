<?php

namespace App\Services;

/**
 * Moteur de rendu des schémas du studio e-mail (STUDIO-03 §5) : composants
 * contrôlés uniquement — tableaux + styles inline (compatibilité clients
 * mail), version texte brut miroir. Interpolation stricte `{{variable}}`
 * (registre, échappée, vide si absente). Aucun JavaScript, formulaire,
 * CSS arbitraire ou ressource distante hors logo autorisé.
 */
class MoteurRenduCourriel
{
    /**
     * @param  array<string, mixed>  $schemaNormalise
     * @param  array<string, string>  $contexte
     * @return array{sujet: string, html: string, texte: string}
     */
    public function rendre(array $schemaNormalise, array $contexte = []): array
    {
        $theme = $schemaNormalise['theme'];
        $meta = $schemaNormalise['meta'];
        $sujet = $this->interpoler($meta['objet'], $contexte);

        $cellules = [];
        foreach ($schemaNormalise['blocs'] as $bloc) {
            $cellules[] = $this->rendreBloc($bloc, $theme, $contexte);
        }

        $lignesTexte = [];
        foreach ($schemaNormalise['blocs'] as $bloc) {
            $rendu = $this->rendreTexte($bloc, $contexte, $theme);
            if ($rendu !== '') {
                $lignesTexte[] = $rendu;
            }
        }

        $largeur = (int) $theme['largeur'];
        $police = $this->pilePolice((string) ($theme['police'] ?? 'arial'));
        $rayonBouton = (($theme['style_bouton'] ?? 'arrondi') === 'angle') ? '2px' : '8px';
        $logo = '';
        if (is_string($theme['logo_url'] ?? null) && $theme['logo_url'] !== '') {
            $logo = '<img src="'.$this->e($theme['logo_url']).'" alt="" width="120" style="display:block;max-width:120px;height:auto;border:0;" />'
                .'<div style="height:12px;line-height:12px;">&nbsp;</div>';
        }

        $preheader = $meta['preheader'] !== ''
            ? '<div style="display:none;max-height:0;overflow:hidden;opacity:0;">'.$this->e($this->interpoler($meta['preheader'], $contexte)).'</div>'
            : '';

        $signature = trim((string) ($theme['signature'] ?? ''));
        $mentions = trim((string) ($theme['mentions'] ?? ''));
        $pied = '';
        if ($signature !== '' || $mentions !== '') {
            $pied = '<tr><td style="padding:16px 28px;border-top:1px solid #e2e8f0;color:#64748b;font-size:12px;line-height:1.6;">'
                .($signature !== '' ? '<p style="margin:0 0 8px 0;">'.$this->e($signature).'</p>' : '')
                .($mentions !== '' ? '<p style="margin:0;">'.$this->e($mentions).'</p>' : '')
                .'</td></tr>';
        }

        $html = '<!DOCTYPE html><html lang="fr"><head><meta charset="UTF-8">'
            .'<meta name="viewport" content="width=device-width, initial-scale=1.0">'
            .'<title>'.$this->e($sujet).'</title></head>'
            .'<body style="margin:0;padding:0;background-color:'.$theme['fond'].';font-family:'.$police.';">'
            .$preheader
            .'<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:'.$theme['fond'].';padding:24px 12px;"><tr><td align="center">'
            .'<table role="presentation" width="'.$largeur.'" cellpadding="0" cellspacing="0" style="max-width:'.$largeur.'px;width:100%;background-color:'.$theme['fond_contenu'].';border:1px solid #e2e8f0;border-radius:12px;overflow:hidden;">'
            .'<tr><td style="background-color:'.$theme['couleur_principale'].';padding:20px 28px;">'
            .$logo
            .'<p style="margin:0;color:#ffffff;font-size:16px;font-weight:bold;">'.$this->e($this->interpoler((string) ($theme['nom_etablissement'] ?? ''), $contexte)).'</p>'
            .'</td></tr>'
            .'<tr><td style="padding:28px;color:#0f172a;font-size:14px;line-height:1.6;">'
            .implode('', $cellules)
            .'</td></tr>'
            .$pied
            .'</table></td></tr></table></body></html>';

        $texte = $sujet."\n".str_repeat('=', 60)."\n".implode("\n\n", $lignesTexte);
        if ($signature !== '') {
            $texte .= "\n\n--\n".$signature;
        }
        if ($mentions !== '') {
            $texte .= "\n".$mentions;
        }

        return ['sujet' => $sujet, 'html' => $html, 'texte' => $texte];
    }

    /**
     * @param  array<string, mixed>  $bloc
     * @param  array<string, mixed>  $theme
     * @param  array<string, string>  $contexte
     */
    private function rendreBloc(array $bloc, array $theme, array $contexte): string
    {
        $alignement = $this->alignement($bloc['alignement'] ?? 'gauche');
        $police = $this->pilePolice((string) ($theme['police'] ?? 'arial'));
        $rayonBouton = (($theme['style_bouton'] ?? 'arrondi') === 'angle') ? '2px' : '8px';

        return match ($bloc['type']) {
            'titre' => $this->paragraphe(
                $this->interpoler($bloc['texte'], $contexte),
                $alignement,
                'font-size:'.match ($bloc['niveau']) {
                    'h1' => '24px', 'h3' => '16px', default => '20px',
                }.';font-weight:bold;color:'.$theme['couleur_principale'].';'
            ),
            'texte' => implode('', array_map(
                fn ($paragraphe) => $this->paragraphe($this->interpoler($paragraphe, $contexte), $alignement, ''),
                preg_split('/\r?\n\r?\n/', (string) $bloc['contenu'])
            )),
            'bouton' => $this->rendreBouton($bloc, $theme, $contexte, $alignement),
            'image' => '<div style="text-align:'.$alignement.';margin:0 0 12px 0;">'
                .'<img src="'.$this->e($bloc['url']).'" alt="'.$this->e($this->interpoler($bloc['texte_alt'], $contexte)).'" width="'.(int) $bloc['largeur'].'" style="display:inline-block;max-width:100%;height:auto;border:0;" />'
                .'</div>',
            'separateur' => '<div style="margin:16px 0;border-top:'
                .match ($bloc['style']) {
                    'epais' => '3px solid #cbd5e1', 'pointille' => '2px dotted #cbd5e1', default => '1px solid #e2e8f0',
                }
            .';line-height:1px;">&nbsp;</div>',
            'liste' => '<ul style="margin:0 0 12px 0;padding-left:20px;color:#0f172a;font-size:14px;line-height:1.6;">'
                .implode('', array_map(
                    fn ($element) => '<li>'.$this->e($this->interpoler($element, $contexte)).'</li>',
                    $bloc['elements']
                ))
                .'</ul>',
            'espaceur' => '<div style="height:'.(int) $bloc['hauteur'].'px;line-height:'.(int) $bloc['hauteur'].'px;">&nbsp;</div>',
            'colonnes' => $this->rendreColonnes($bloc, $theme, $contexte, $alignement),
            'carte' => $this->rendreCarte($bloc, $theme, $contexte, $alignement),
            'tableau' => $this->rendreTableau($bloc, $contexte, $alignement),
            'signature' => $this->paragraphe(
                $this->interpoler($bloc['texte'] !== '' ? $bloc['texte'] : (string) ($theme['signature'] ?? ''), $contexte),
                $alignement,
                'font-style:italic;color:#475569;'
            ),
            default => '',
        };
    }

    /**
     * Bouton omis si l'URL ne se résout pas en https/mailto valide
     * (jamais de lien vide ou dangereux émis).
     *
     * @param  array<string, mixed>  $bloc
     * @param  array<string, mixed>  $theme
     * @param  array<string, string>  $contexte
     */
    private function rendreBouton(array $bloc, array $theme, array $contexte, string $alignement): string
    {
        $url = $this->interpoler($bloc['url'], $contexte);
        $protocole = mb_strtolower((string) parse_url($url, PHP_URL_SCHEME));

        if ($protocole === 'mailto') {
            $valide = filter_var(mb_substr($url, 7), FILTER_VALIDATE_EMAIL) !== false;
        } else {
            $valide = $protocole === 'https' && filter_var($url, FILTER_VALIDATE_URL) !== false;
        }

        if (! $valide) {
            return '';
        }

        $rayonBouton = (($theme['style_bouton'] ?? 'arrondi') === 'angle') ? '2px' : '8px';

        return '<div style="text-align:'.$alignement.';margin:0 0 12px 0;">'
            .'<a href="'.$this->e($url).'" style="display:inline-block;background-color:'.$theme['couleur_accent'].';color:#ffffff;font-size:14px;font-weight:bold;text-decoration:none;padding:12px 24px;border-radius:'.$rayonBouton.';">'
            .$this->e($this->interpoler($bloc['libelle'], $contexte)).'</a></div>';
    }

    /**
     * Deux colonnes 50/50 (tableau e-mail ; empilement non garanti sur
     * Outlook — avertissement qualité émis à la publication).
     *
     * @param  array<string, mixed>  $bloc
     * @param  array<string, mixed>  $theme
     * @param  array<string, string>  $contexte
     */
    private function rendreColonnes(array $bloc, array $theme, array $contexte, string $alignement): string
    {
        $cellules = '';
        foreach ((array) ($bloc['colonnes'] ?? []) as $colonne) {
            $contenu = '';
            foreach ((array) $colonne as $sousBloc) {
                if (is_array($sousBloc)) {
                    $contenu .= $this->rendreBloc($sousBloc, $theme, $contexte);
                }
            }
            $cellules .= '<td width="50%" valign="top" style="padding:0 6px;">'.$contenu.'</td>';
        }

        return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 12px 0;text-align:'.$alignement.';"><tr>'.$cellules.'</tr></table>';
    }

    /**
     * @param  array<string, mixed>  $bloc
     * @param  array<string, mixed>  $theme
     * @param  array<string, string>  $contexte
     */
    private function rendreCarte(array $bloc, array $theme, array $contexte, string $alignement): string
    {
        $bordure = is_string($bloc['couleur_bordure'] ?? null) && $bloc['couleur_bordure'] !== ''
            ? $bloc['couleur_bordure']
            : $theme['couleur_accent'];

        return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 12px 0;">'
            .'<tr><td style="border:1px solid '.$bordure.';border-left:4px solid '.$bordure.';border-radius:8px;padding:16px;background-color:#ffffff;text-align:'.$alignement.';">'
            .'<p style="margin:0 0 8px 0;font-size:15px;font-weight:bold;color:'.$theme['couleur_principale'].';">'
            .$this->e($this->interpoler($bloc['titre'], $contexte)).'</p>'
            .'<p style="margin:0;font-size:14px;line-height:1.6;color:#0f172a;">'
            .$this->e($this->interpoler($bloc['texte'], $contexte)).'</p>'
            .'</td></tr></table>';
    }

    /**
     * @param  array<string, mixed>  $bloc
     * @param  array<string, string>  $contexte
     */
    private function rendreTableau(array $bloc, array $contexte, string $alignement): string
    {
        $entetes = '';
        foreach ((array) ($bloc['entetes'] ?? []) as $entete) {
            $entetes .= '<th style="padding:8px 10px;background-color:#f1f5f9;font-size:13px;font-weight:bold;color:#0f172a;text-align:left;border:1px solid #e2e8f0;">'
                .$this->e($this->interpoler($entete, $contexte)).'</th>';
        }

        $lignes = '';
        foreach ((array) ($bloc['lignes'] ?? []) as $ligne) {
            $cellules = '';
            foreach ((array) $ligne as $cellule) {
                $cellules .= '<td style="padding:8px 10px;font-size:13px;color:#0f172a;border:1px solid #e2e8f0;">'
                    .$this->e($this->interpoler($cellule, $contexte)).'</td>';
            }
            $lignes .= '<tr>'.$cellules.'</tr>';
        }

        return '<table role="presentation" cellpadding="0" cellspacing="0" style="margin:0 0 12px 0;border-collapse:collapse;text-align:'.$alignement.';">'
            .'<tr>'.$entetes.'</tr>'.$lignes.'</table>';
    }

    /**
     * @param  array<string, mixed>  $bloc
     * @param  array<string, string>  $contexte
     */
    private function rendreTexte(array $bloc, array $contexte, array $theme = []): string
    {
        return match ($bloc['type']) {
            'titre' => $this->interpoler($bloc['texte'], $contexte)."\n".str_repeat('-', 40),
            'texte' => $this->interpoler($bloc['contenu'], $contexte),
            'bouton' => $this->texteBouton($bloc, $contexte),
            'image' => '[Image : '.$this->interpoler($bloc['texte_alt'], $contexte).'] '.$bloc['url'],
            'liste' => implode("\n", array_map(
                fn ($element) => '- '.$this->interpoler($element, $contexte),
                $bloc['elements']
            )),
            'colonnes' => implode("\n\n", array_map(
                fn ($colonne) => implode("\n", array_map(
                    fn ($sousBloc) => is_array($sousBloc) ? $this->rendreTexte($sousBloc, $contexte) : '',
                    (array) $colonne
                )),
                (array) ($bloc['colonnes'] ?? [])
            )),
            'carte' => $this->interpoler($bloc['titre'], $contexte)."\n".$this->interpoler($bloc['texte'], $contexte),
            'tableau' => implode("\n", array_merge(
                [implode(' | ', array_map(fn ($e) => $this->interpoler($e, $contexte), (array) ($bloc['entetes'] ?? [])))],
                array_map(
                    fn ($ligne) => implode(' | ', array_map(fn ($c) => $this->interpoler($c, $contexte), (array) $ligne)),
                    (array) ($bloc['lignes'] ?? [])
                )
            )),
            'signature' => '-- '.($this->interpoler($bloc['texte'] ?? '', $contexte) !== ''
                ? $this->interpoler($bloc['texte'] ?? '', $contexte)
                : (string) ($theme['signature'] ?? '')),
            default => '',
        };
    }

    private function pilePolice(string $police): string
    {
        return match ($police) {
            'georgia' => "Georgia,'Times New Roman',serif",
            'systeme' => "system-ui,-apple-system,'Segoe UI',Roboto,sans-serif",
            default => 'Arial,Helvetica,sans-serif',
        };
    }

    /**
     * @param  array<string, mixed>  $bloc
     * @param  array<string, string>  $contexte
     */
    private function texteBouton(array $bloc, array $contexte): string
    {
        $url = $this->interpoler($bloc['url'] ?? '', $contexte);

        if ($url === '') {
            return $this->interpoler($bloc['libelle'] ?? '', $contexte);
        }

        return $this->interpoler($bloc['libelle'] ?? '', $contexte).' : '.$url;
    }

    /**
     * Contexte fictif pour les aperçus (jamais de dossier réel).
     *
     * @return array<string, string>
     */
    public function contexteFictif(): array
    {
        $contexte = [];
        foreach ((array) config('courriels.variables_autorisees', []) as $nom => $definition) {
            $contexte[$nom] = (string) ($definition['exemple'] ?? '');
        }

        return $contexte;
    }

    /**
     * @param  array<string, string>  $contexte
     */
    private function interpoler(string $texte, array $contexte): string
    {
        return (string) preg_replace_callback(
            '/\{\{\s*([a-z_][a-z0-9_]*)\s*\}\}/',
            static function ($correspondance) use ($contexte): string {
                $valeur = $contexte[$correspondance[1]] ?? '';

                return is_scalar($valeur) ? (string) $valeur : '';
            },
            $texte
        );
    }

    private function alignement(string $alignement): string
    {
        return match ($alignement) {
            'centre' => 'center', 'droite' => 'right', default => 'left',
        };
    }

    private function paragraphe(string $texte, string $alignement, string $style): string
    {
        return '<p style="margin:0 0 12px 0;text-align:'.$alignement.';'.$style.'">'
            .$this->e($texte).'</p>';
    }

    private function e(string $texte): string
    {
        return htmlspecialchars($texte, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
