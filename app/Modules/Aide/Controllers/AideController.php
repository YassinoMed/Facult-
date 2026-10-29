<?php

namespace App\Modules\Aide\Controllers;

use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Centre d'aide utilisateur (module Aide).
 *
 * Rendu public de la base de connaissances utilisateur « docs/utilisateur/ » :
 * 6 pages statiques, en lecture seule, sans écriture ni donnée métier.
 * Slug liste blanche → fichier : aucune entrée utilisateur par ailleurs.
 * Cache invalidé automatiquement par la date de modification du fichier
 * (clé = md5(fichier) + filemtime).
 */
class AideController
{
    /**
     * Pages servies : slug URL (liste blanche) → fichier de la KB + titre.
     *
     * @var array<string, array{fichier: string, titre: string}>
     */
    private const PAGES = [
        'par-role' => [
            'fichier' => '01-qui-etes-vous-roles.md',
            'titre' => 'Par rôle : qui êtes-vous, que pouvez-vous faire ?',
        ],
        'guides-pas-a-pas' => [
            'fichier' => '02-guides-pas-a-pas.md',
            'titre' => 'Guides pas-à-pas (par tâche)',
        ],
        'a-la-page' => [
            'fichier' => '03-a-la-page.md',
            'titre' => 'À la page : que puis-je faire sur l\'écran ?',
        ],
        'faq' => [
            'fichier' => '04-faq.md',
            'titre' => 'FAQ (questions fréquentes)',
        ],
        'depannage' => [
            'fichier' => '05-depannage.md',
            'titre' => 'Dépannage : symptôme, cause, quoi faire',
        ],
        'vocabulaire' => [
            'fichier' => '06-vocabulaire.md',
            'titre' => 'Vocabulaire (langage utilisateur)',
        ],
    ];

    public function index(): View
    {
        return view('modules.aide.index', [
            'pages' => self::PAGES,
        ]);
    }

    public function montrer(string $slug): View
    {
        if (! isset(self::PAGES[$slug])) {
            abort(Response::HTTP_NOT_FOUND);
        }

        $page = self::PAGES[$slug];

        $contenu = Cache::rememberForever(
            $this->cleCache($page['fichier']),
            fn (): string => $this->rendreMarkdown(
                base_path('docs/utilisateur/'.$page['fichier'])
            )
        );

        return view('modules.aide.page', [
            'titre' => $page['titre'],
            'contenu' => $contenu,
        ]);
    }

    /**
     * Convertit un fichier markdown de la KB en HTML sûr :
     * HTML brut retiré (« html_input: strip »), liens non sûrs refusés.
     */
    private function rendreMarkdown(string $chemin): string
    {
        $texte = file_get_contents($chemin);

        if ($texte === false) {
            abort(Response::HTTP_NOT_FOUND);
        }

        return (string) Str::markdown($texte, [
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
        ]);
    }

    private function cleCache(string $fichier): string
    {
        $mtime = filemtime(base_path('docs/utilisateur/'.$fichier));

        return 'aide.page.'.md5($fichier).'.'.($mtime !== false ? $mtime : 0);
    }
}
