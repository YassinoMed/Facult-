<?php

namespace Tests\Unit;

use App\Support\Recherche;
use PHPUnit\Framework\TestCase;

/**
 * Audit §P3-22 : les jokers LIKE saisis par l'utilisateur sont neutralisés —
 * une recherche reste littérale (aucune injection de motif).
 */
class RechercheTest extends TestCase
{
    public function test_echappe_pourcent_underscore_et_antislash(): void
    {
        $this->assertSame('100\\% sûr\\_oui\\\\', Recherche::echapperLike('100% sûr_oui\\'));
    }

    public function test_contient_enrobe_le_motif_echappe(): void
    {
        $this->assertSame('%BK\\%123%', Recherche::contient('BK%123'));
    }

    public function test_texte_simple_inchange_hormis_enrobage(): void
    {
        $this->assertSame('%DUPONT%', Recherche::contient('DUPONT'));
    }
}
