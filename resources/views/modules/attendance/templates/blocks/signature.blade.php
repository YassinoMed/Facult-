@if($config['enabled'] ?? true)
    <div class="bloc-signatures-fin">
        <table class="table-signatures-fin">
            <tr>
                @if($config['show_enseignant'] ?? true)
                    <td>
                        <div class="sig-titre">Visa de l'enseignant responsable</div>
                        <div class="sig-mentions">
                            Nom : {{ $metadonnees['enseignant'] }}<br>
                            Date : {{ $metadonnees['date'] }}<br>
                            Signature et observations :
                        </div>
                    </td>
                @endif
                @if($config['show_administration'] ?? true)
                    <td style="margin-left: 8pt;">
                        <div class="sig-titre">Administration / Scolarité</div>
                        <div class="sig-mentions">
                            Cachet et validation :<br>
                            Présents : ……… / {{ $metadonnees['total'] }}<br>
                            Date : ………………………………
                        </div>
                    </td>
                @endif
                @if(($config['show_surveillants'] ?? false) || ($config['type'] ?? '') === 'exam')
                    <td style="margin-left: 8pt;">
                        <div class="sig-titre">Surveillants d'épreuve</div>
                        <div class="sig-mentions">
                            1. {{ $metadonnees['surveillant_1'] ?? 'Surveillant 1' }}<br>
                            2. {{ $metadonnees['surveillant_2'] ?? 'Surveillant 2' }}<br>
                            Nombre de copies remises : ………
                        </div>
                    </td>
                @endif
            </tr>
        </table>
        @if(! empty($config['mention_legale']))
            <p style="font-size: 6.5pt; color: #94a3b8; margin-top: 4pt; font-style: italic; text-align: center;">
                {{ $config['mention_legale'] }}
            </p>
        @endif
    </div>
@endif
