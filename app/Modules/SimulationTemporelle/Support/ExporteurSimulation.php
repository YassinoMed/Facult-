<?php

namespace App\Modules\SimulationTemporelle\Support;

use App\Modules\SimulationTemporelle\Models\ExecutionTemporelle;
use Dompdf\Dompdf;
use Dompdf\Options;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Service d'Export Sécurisé CSV & PDF pour la Simulation Temporelle (Lot 8).
 *
 * RÈGLES DU MANDAT :
 * - Aucune donnée personnelle (zéro PII) ;
 * - Protection anti-injection CSV sur cellules débutant par =, +, -, @ ;
 * - UTF-8 avec BOM et séparateurs standardisés ;
 * - Rendu PDF avec Dompdf, préservant les graphismes et caractères accentués.
 */
final class ExporteurSimulation
{
    /**
     * Génère une réponse HTTP Streamed pour l'export CSV protégé.
     */
    public function exporterCsv(ExecutionTemporelle $execution): StreamedResponse
    {
        $nomFichier = 'simulation_'.preg_replace('/[^a-zA-Z0-9_\-]/', '_', $execution->scenario->code)."_exec_{$execution->id}.csv";

        return new StreamedResponse(function () use ($execution) {
            $handle = fopen('php://output', 'w');

            // BOM UTF-8 pour Excel
            fwrite($handle, "\xEF\xBB\xBF");

            // En-tête métadonnées
            $this->ecrireLigneCsv($handle, ['UNIREGISTRE — SIMULATEUR TEMPOREL LMD', 'RAPPORT OFFICIEL']);
            $this->ecrireLigneCsv($handle, ['Scénario Code', $execution->scenario->code]);
            $this->ecrireLigneCsv($handle, ['Scénario Libellé', $execution->scenario->libelle]);
            $this->ecrireLigneCsv($handle, ['Version Scénario', $execution->scenario->version]);
            $this->ecrireLigneCsv($handle, ['Version Moteur', $execution->version_moteur]);
            $this->ecrireLigneCsv($handle, ['Graine Déterministe', $execution->graine]);
            $this->ecrireLigneCsv($handle, ['Empreinte Entrées (SHA256)', $execution->hash_entrees]);
            $this->ecrireLigneCsv($handle, ['Empreinte Résultat (SHA256)', $execution->hash_resultat]);
            $this->ecrireLigneCsv($handle, ['Date Exécution', $execution->created_at?->toIso8601String()]);
            $this->ecrireLigneCsv($handle, ['Avertissement', 'Simulation d\'aide à la décision — Aucune décision réelle n\'est appliquée.']);
            $this->ecrireLigneCsv($handle, []);

            // Données comparatives
            $resultat = $execution->resultat ?? [];
            $comparaison = $resultat['comparaison']['lignes'] ?? [];

            $this->ecrireLigneCsv($handle, [
                'Pas', 'Année Universitaire', 'Effectif Référence', 'Effectif Projeté',
                'Écart Absolu', 'Écart (%)', 'Cible Métier', 'Écart Cible', 'Statut Contraintes',
            ]);

            foreach ($comparaison as $ligne) {
                $this->ecrireLigneCsv($handle, [
                    $ligne['pas'],
                    $ligne['annee'],
                    $ligne['effectif_reference'],
                    $ligne['effectif_projete'],
                    $ligne['ecart_absolu'],
                    $ligne['ecart_pourcentage'].'%',
                    $ligne['cible_metier'] ?? 'N/A',
                    $ligne['ecart_cible'] ?? 'N/A',
                    $ligne['conforme_contraintes'] ? 'Conforme' : 'Violation : '.implode(', ', $ligne['violations'] ?? []),
                ]);
            }

            $this->ecrireLigneCsv($handle, []);
            $this->ecrireLigneCsv($handle, ['CLASSEMENT DES VARIANTES']);
            $this->ecrireLigneCsv($handle, ['Rang', 'Index Variante', 'Delta Admissions', 'Score Global', 'Total Final']);

            $classement = $resultat['classement'] ?? [];
            foreach ($classement as $rang => $var) {
                $this->ecrireLigneCsv($handle, [
                    $rang + 1,
                    $var['index'],
                    round($var['delta'] * 100, 2).'%',
                    $var['score'],
                    $var['total_final'],
                ]);
            }

            fclose($handle);
        }, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$nomFichier}\"",
            'Cache-Control' => 'no-store, no-cache',
        ]);
    }

    /**
     * Génère le flux PDF imprimable complet de l'exécution.
     */
    public function genererPdf(ExecutionTemporelle $execution): string
    {
        $options = new Options;
        $options->set('isHtml5ParserEnabled', true);
        $options->set('isRemoteEnabled', false);
        $options->set('defaultFont', 'DejaVu Sans');

        $dompdf = new Dompdf($options);

        $html = view('modules.simulation-temporel.pdf', [
            'execution' => $execution,
            'scenario' => $execution->scenario,
            'resultat' => $execution->resultat ?? [],
        ])->render();

        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        return $dompdf->output();
    }

    /**
     * Écrit une ligne CSV protégée contre l'injection de formules (=, +, -, @).
     *
     * Les nombres purs (ex. -12.5) ne sont PAS neutralisés : ils ne peuvent
     * pas être interprétés comme des formules par Excel/LibreOffice, et les
     * préfixer corromprait les écarts négatifs du rapport.
     *
     * @param  resource  $handle
     * @param  array<int, mixed>  $champs
     */
    private function ecrireLigneCsv($handle, array $champs): void
    {
        $securises = array_map(function ($valeur) {
            $chaine = (string) $valeur;

            if ($chaine !== '' && in_array($chaine[0], ['=', '+', '-', '@'], true)
                && ! preg_match('/^-?\d+(?:[.,]\d+)?%?$/', $chaine)) {
                return "'".$chaine; // Neutralisation formule
            }

            return $chaine;
        }, $champs);

        fputcsv($handle, $securises, ';');
    }
}
