<?php

namespace Tests\Feature;

use Illuminate\Pagination\LengthAwarePaginator;
use Tests\TestCase;

/**
 * Les vues de pagination Laravel utilisent `__('pagination.previous')`,
 * `__('pagination.next')` et les clés JSON du résumé. Sans fichiers de
 * traduction, les clés brutes apparaissent dans l'interface.
 */
class PaginationTraductionTest extends TestCase
{
    private function paginateur(): LengthAwarePaginator
    {
        return new LengthAwarePaginator(
            range(1, 20),
            20,
            5,
            2,
            ['path' => '/absences'],
        );
    }

    public function test_les_libelles_precedent_et_suivant_sont_traduits_en_francais(): void
    {
        app()->setLocale('fr');

        $this->assertSame('Précédent', __('pagination.previous'));
        $this->assertSame('Suivant', __('pagination.next'));

        $html = (string) $this->paginateur()->links();

        $this->assertStringContainsString('Précédent', $html);
        $this->assertStringContainsString('Suivant', $html);
        $this->assertStringNotContainsString('pagination.previous', $html);
        $this->assertStringNotContainsString('pagination.next', $html);
    }

    public function test_le_resume_et_le_libelle_de_navigation_sont_traduits_en_francais(): void
    {
        app()->setLocale('fr');

        $html = (string) $this->paginateur()->links();

        $this->assertStringContainsString('Navigation de pagination', $html);
        $this->assertStringContainsString('Affichage de', $html);
        $this->assertStringContainsString('sur', $html);
        $this->assertStringContainsString('résultats', $html);
        $this->assertStringNotContainsString('Pagination Navigation', $html);
        $this->assertStringNotContainsString('Showing', $html);
    }
}
