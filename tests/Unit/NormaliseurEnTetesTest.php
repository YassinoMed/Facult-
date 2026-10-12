<?php

namespace Tests\Unit;

use App\Services\Io\NormaliseurEnTetes;
use PHPUnit\Framework\TestCase;

/**
 * PHASE 2 — Normalisation unique des en-têtes : accents translittérés,
 * casse/espaces/séparateurs canoniques, déterminisme total.
 */
class NormaliseurEnTetesTest extends TestCase
{
    public function test_accents_translitteres(): void
    {
        $this->assertSame('prenom', NormaliseurEnTetes::cle('Prénom'));
        $this->assertSame('telephone_portable', NormaliseurEnTetes::cle('Téléphone portable'));
        $this->assertSame('date_de_naissance', NormaliseurEnTetes::cle('Date de naissance'));
        $this->assertSame('ca', NormaliseurEnTetes::cle('Ça'));
        $this->assertSame('angstrom', NormaliseurEnTetes::cle('Ångström'));
    }

    public function test_casse_espaces_separateurs(): void
    {
        $this->assertSame('nom', NormaliseurEnTetes::cle('NOM'));
        $this->assertSame('e_mail', NormaliseurEnTetes::cle('  E-mail '));
        $this->assertSame('code_enseignement', NormaliseurEnTetes::cle('Code.Enseignement'));
    }

    public function test_vide_et_speciaux(): void
    {
        $this->assertSame('', NormaliseurEnTetes::cle('   '));
        $this->assertSame('n0cin', NormaliseurEnTetes::cle('N°CIN'));
    }
}
