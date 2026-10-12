@if($config['enabled'] ?? true)
    <div style="background-color: #f1f5f9; border: 1pt dashed #94a3b8; border-radius: 4pt; padding: 6pt 10pt; margin: 8pt 0; text-align: center;">
        <table style="width: 100%; border-collapse: collapse;">
            <tr>
                <td style="width: 70px; text-align: center; vertical-align: middle;">
                    @php
                        $qrSize = (int) ($config['size'] ?? 60);
                    @endphp
                    <div style="display: inline-block; width: {{ $qrSize }}px; height: {{ $qrSize }}px; background: white; padding: 2px; border-radius: 4px;">
                        {!! \App\Support\QrCodeSvg::generer('QR-SEANCE-' . ($metadonnees['reference'] ?? 'DEMO'), $qrSize) !!}
                    </div>
                </td>
                <td style="text-align: left; vertical-align: middle; padding-left: 10pt;">
                    <div style="font-weight: bold; color: #1e3a5f; font-size: 8.5pt;">Pointage & Contrôle Numérique par QR Code</div>
                    <div style="font-size: 7.5pt; color: #475569; margin-top: 1pt;">
                        Scannez ce QR Code institutionnel avec l'application mobile ou l'émulateur pour valider le pointage de la séance en direct.
                    </div>
                    <div style="font-size: 7pt; font-family: monospace; color: #64748b; margin-top: 1pt;">
                        Réf session : {{ $metadonnees['reference'] }} · Validité : {{ $metadonnees['date'] }}
                    </div>
                </td>
            </tr>
        </table>
    </div>
@endif
