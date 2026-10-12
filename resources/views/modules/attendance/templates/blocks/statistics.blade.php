@if($config['enabled'] ?? true)
    @php
        $total = count($lignes);
        $presents = count(array_filter($lignes, fn($l) => in_array($l['presence'], ['Présent', 'Oui', '1', 1], true)));
        $retards = count(array_filter($lignes, fn($l) => ($l['retard'] ?? '') === 'Oui' || in_array($l['presence'], ['Retard'], true)));
        $absents = count(array_filter($lignes, fn($l) => in_array($l['presence'], ['Absent', 'Non', '0', 0], true)));
        $taux = $total > 0 ? round((($presents + $retards) / $total) * 100, 1) : 100;
    @endphp
    <div style="background-color: #f8fafc; border: 0.75pt solid #cbd5e1; border-radius: 4pt; padding: 6pt 10pt; margin: 8pt 0;">
        <table style="width: 100%; border-collapse: collapse; font-size: 8pt;">
            <tr>
                <td style="font-weight: bold; color: #1e3a5f; text-transform: uppercase; font-size: 7.5pt; width: 25%;">
                    Synthèse de la séance :
                </td>
                @if($config['show_total'] ?? true)
                    <td style="text-align: center;">
                        <span style="color: #64748b;">Inscrits :</span> <strong>{{ $total }}</strong>
                    </td>
                @endif
                @if($config['show_presents'] ?? true)
                    <td style="text-align: center;">
                        <span style="color: #059669;">Présents :</span> <strong>{{ $presents }}</strong>
                    </td>
                @endif
                @if($config['show_retards'] ?? true)
                    <td style="text-align: center;">
                        <span style="color: #d97706;">Retards :</span> <strong>{{ $retards }}</strong>
                    </td>
                @endif
                @if($config['show_absents'] ?? true)
                    <td style="text-align: center;">
                        <span style="color: #dc2626;">Absents :</span> <strong>{{ $absents }}</strong>
                    </td>
                @endif
                @if($config['show_taux'] ?? true)
                    <td style="text-align: right;">
                        <span style="color: #1e3a5f; font-weight: 600;">Taux d'assiduité :</span>
                        <strong style="color: #0369a1;">{{ $taux }}%</strong>
                    </td>
                @endif
            </tr>
        </table>
    </div>
@endif
