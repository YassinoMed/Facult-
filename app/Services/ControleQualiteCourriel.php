<?php

namespace App\Services;

/**
 * Contrôle qualité pré-publication (STUDIO-04 §8) : erreurs BLOQUANTES
 * (refusent `publier`) et AVERTISSEMENTS (affichés avec correction).
 * Chaque signalement porte un code stable, un message et un moyen de
 * corriger. Ne remplace jamais la validation stricte du schéma.
 */
class ControleQualiteCourriel
{
    /**
     * @param  array<string, mixed>  $schemaNormalise
     * @return array{erreurs: list<array{code: string, message: string, correction: string}>, avertissements: list<array{code: string, message: string, correction: string}>}
     */
    public function analyser(array $schemaNormalise): array
    {
        $erreurs = [];
        $avertissements = [];
        $theme = $schemaNormalise['theme'];
        $blocs = (array) ($schemaNormalise['blocs'] ?? []);

        if (trim((string) ($schemaNormalise['meta']['objet'] ?? '')) === '') {
            $erreurs[] = $this->signal('E_OBJET_VIDE', 'Objet manquant.', 'Renseignez un objet explicite (150 caractères max).');
        }

        if (trim((string) ($schemaNormalise['meta']['preheader'] ?? '')) === '') {
            $avertissements[] = $this->signal('A_PREHEADER_VIDE', 'Pré-en-tête absent.', 'Ajoutez un pré-en-tête : il améliore l’ouverture dans les boîtes de réception.');
        }

        $textePrincipal = $theme['couleur_principale'];
        $accent = $theme['couleur_accent'];
        $fondContenu = $theme['fond_contenu'];

        foreach ([
            ['titre', $textePrincipal, $fondContenu],
            ['bouton', '#ffffff', $accent],
            ['corps', '#0f172a', $fondContenu],
        ] as [$zone, $avant, $arriere]) {
            $ratio = $this->ratioContraste($avant, $arriere);
            if ($ratio < 3.0) {
                $erreurs[] = $this->signal(
                    'E_CONTRASTE_CRITIQUE',
                    'Contraste inutilisable ('.$zone.' : '.$ratio.':1).',
                    'Choisissez des couleurs au contraste ≥ 3:1 (recommandé ≥ 4.5:1).'
                );
            } elseif ($ratio < 4.5) {
                $avertissements[] = $this->signal(
                    'A_CONTRASTE_FAIBLE',
                    'Contraste faible ('.$zone.' : '.$ratio.':1).',
                    'Visez un contraste ≥ 4.5:1 pour une lisibilité optimale.'
                );
            }
        }

        $largeur = (int) $theme['largeur'];
        $contientColonnes = false;
        $colonnesEntetes = 0;
        foreach ($blocs as $bloc) {
            if (($bloc['type'] ?? null) === 'image' && (int) ($bloc['largeur'] ?? 0) > $largeur) {
                $erreurs[] = $this->signal('E_IMAGE_DEBORDEMENT', 'Image plus large que le contenu.', 'Réduisez la largeur de l’image à '.$largeur.' px maximum.');
            }
            if (($bloc['type'] ?? null) === 'colonnes') {
                $contientColonnes = true;
            }
            if (($bloc['type'] ?? null) === 'tableau') {
                $colonnesEntetes = max($colonnesEntetes, count((array) ($bloc['entetes'] ?? [])));
            }
        }

        if ($contientColonnes) {
            $avertissements[] = $this->signal(
                'A_COLONNES_OUTLOOK',
                'Colonnes côte à côte : empilement non garanti sur Outlook.',
                'Vérifiez le rendu sur Outlook ou préférez des blocs pleine largeur.'
            );
        }

        foreach ($blocs as $bloc) {
            if (($bloc['type'] ?? null) === 'bouton' && preg_match('/\{\{/', (string) ($bloc['url'] ?? ''))) {
                $avertissements[] = $this->signal(
                    'A_URL_VARIABLE',
                    'Bouton à URL variable : omis si non résolue à l’envoi.',
                    'Vérifiez que la variable est fournie dans tous les contextes d’envoi.'
                );
                break;
            }
        }

        if ($colonnesEntetes > 4) {
            $avertissements[] = $this->signal(
                'A_TABLEAU_MOBILE',
                'Tableau de plus de 4 colonnes : lecture difficile sur mobile.',
                'Réduisez le nombre de colonnes ou simplifiez le tableau.'
            );
        }

        return ['erreurs' => $erreurs, 'avertissements' => $avertissements];
    }

    /**
     * @return array{code: string, message: string, correction: string}
     */
    private function signal(string $code, string $message, string $correction): array
    {
        return ['code' => $code, 'message' => $message, 'correction' => $correction];
    }

    private function ratioContraste(string $avant, string $arriere): float
    {
        $luminance = function (string $hex): float {
            $hex = ltrim($hex, '#');
            $canaux = [];
            for ($i = 0; $i < 3; $i++) {
                $composante = hexdec(substr($hex, $i * 2, 2)) / 255;
                $canaux[] = $composante <= 0.03928 ? $composante / 12.92 : (($composante + 0.055) / 1.055) ** 2.4;
            }

            return 0.2126 * $canaux[0] + 0.7152 * $canaux[1] + 0.0722 * $canaux[2];
        };

        $claire = max($luminance($avant), $luminance($arriere));
        $sombre = min($luminance($avant), $luminance($arriere));

        return round(($claire + 0.05) / ($sombre + 0.05), 1);
    }
}
