<?php

namespace Tests\Unit;

use App\Services\LoopAuto\EvaluateurPorte;
use PHPUnit\Framework\TestCase;

/**
 * LOOP K — Moteur de transition : réussite, échec ×3, blocage, reprise,
 * limites. Scénarios simulés, fonction pure.
 */
class EvaluateurPorteTest extends TestCase
{
    public function test_reussite_passe_au_suivant(): void
    {
        $resultat = EvaluateurPorte::evaluer([
            'verdict' => 'pass', 'reserves_bloquantes' => false, 'preuves' => true,
            'poursuite_autorisee' => true, 'suivant_connu' => true,
            'tentative' => 0, 'loops_consecutifs' => 0,
        ]);

        $this->assertSame('NEXT', $resultat['transition']);
    }

    public function test_echec_trois_fois_puis_failed(): void
    {
        $contexte = [
            'verdict' => 'echec', 'reserves_bloquantes' => false, 'preuves' => true,
            'poursuite_autorisee' => true, 'suivant_connu' => true,
            'tentative' => 0, 'loops_consecutifs' => 0,
        ];

        $this->assertSame('RETRY', EvaluateurPorte::evaluer($contexte)['transition']);
        $contexte['tentative'] = 2;
        $this->assertSame('RETRY', EvaluateurPorte::evaluer($contexte)['transition']);
        $contexte['tentative'] = 3;
        $this->assertSame('FAILED', EvaluateurPorte::evaluer($contexte)['transition']);
    }

    public function test_bloque_sans_preuve_ni_suivant(): void
    {
        $base = [
            'verdict' => 'pass', 'reserves_bloquantes' => false, 'preuves' => true,
            'poursuite_autorisee' => true, 'suivant_connu' => true,
            'tentative' => 0, 'loops_consecutifs' => 0,
        ];

        $this->assertSame('BLOCKED', EvaluateurPorte::evaluer(array_merge($base, ['verdict' => 'blocked']))['transition']);
        $this->assertSame('BLOCKED', EvaluateurPorte::evaluer(array_merge($base, ['preuves' => false]))['transition']);
        $this->assertSame('BLOCKED', EvaluateurPorte::evaluer(array_merge($base, ['reserves_bloquantes' => true]))['transition']);
        $this->assertSame('STOPPED', EvaluateurPorte::evaluer(array_merge($base, ['suivant_connu' => false]))['transition']);
        $this->assertSame('STOPPED', EvaluateurPorte::evaluer(array_merge($base, ['loops_consecutifs' => 10]))['transition']);
    }

    public function test_reprise_idempotente_meme_transition(): void
    {
        $contexte = [
            'verdict' => 'pass', 'reserves_bloquantes' => false, 'preuves' => true,
            'poursuite_autorisee' => true, 'suivant_connu' => true,
            'tentative' => 1, 'loops_consecutifs' => 4,
        ];

        $this->assertSame(
            EvaluateurPorte::evaluer($contexte),
            EvaluateurPorte::evaluer($contexte)
        );
    }
}
