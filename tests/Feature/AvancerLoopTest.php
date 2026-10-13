<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * LOOP K — Commande `loop:avancer` : persistance d'état et journal
 * idempotent, sur chemins injectés (jamais les docs réels).
 */
class AvancerLoopTest extends TestCase
{
    use RefreshDatabase;

    public function test_transition_persiste_et_journal_sans_doublon(): void
    {
        $etat = tempnam(sys_get_temp_dir(), 'etat').'.json';
        $journal = tempnam(sys_get_temp_dir(), 'journal').'.md';

        $this->artisan('loop:avancer', [
            'loop' => 'LOOP-K-DEMO',
            '--preuves' => true,
            '--poursuite-autorisee' => true,
            '--suivant' => 'LOOP-SUIVANT',
            '--etat' => $etat,
            '--journal' => $journal,
        ])->assertSuccessful();

        $this->artisan('loop:avancer', [
            'loop' => 'LOOP-K-DEMO',
            '--preuves' => true,
            '--poursuite-autorisee' => true,
            '--suivant' => 'LOOP-SUIVANT',
            '--etat' => $etat,
            '--journal' => $journal,
        ])->assertSuccessful();

        $donnees = json_decode((string) file_get_contents($etat), true);
        $this->assertSame('NEXT', $donnees['transition']);
        $this->assertSame('LOOP-SUIVANT', $donnees['suivant']);
        $this->assertCount(1, array_filter(explode("\n", (string) file_get_contents($journal))));

        $this->artisan('loop:avancer', [
            'loop' => 'LOOP-K-BLOQUE',
            '--verdict' => 'blocked',
            '--etat' => $etat,
            '--journal' => $journal,
        ])->assertSuccessful();
        $donnees = json_decode((string) file_get_contents($etat), true);
        $this->assertSame('BLOCKED', $donnees['transition']);

        @unlink($etat);
        @unlink($journal);
    }
}
