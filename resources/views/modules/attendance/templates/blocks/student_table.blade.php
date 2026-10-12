@if($config['enabled'] ?? true)
    @php
        $fontSize = $config['font_size'] ?? '8.5pt';
        $padding = match($config['row_height'] ?? 'normal') {
            'compact' => '2pt 3pt',
            'large' => '6pt 5pt',
            default => '3.5pt 4pt',
        };
    @endphp
    <table class="table-presence" style="font-size: {{ $fontSize }};">
        <thead>
            <tr>
                @foreach($colonnes as $colonne)
                    <th style="{{ isset($colonne['width']) ? 'width: '.$colonne['width'].';' : '' }} padding: {{ $padding }};"
                        class="{{ in_array($colonne['key'], ['numero', 'presence', 'signature', 'signature_enseignant', 'qr_code', 'piece_identite', 'retard']) ? 'cell-centre' : '' }}">
                        {{ $colonne['label'] ?? \App\Modules\Attendance\Models\AttendanceTemplate::COLONNES_DISPONIBLES[$colonne['key']] ?? ucfirst($colonne['key']) }}
                    </th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @forelse($lignes as $ligne)
                <tr>
                    @foreach($colonnes as $colonne)
                        @php
                            $cle = $colonne['key'];
                        @endphp
                        @if($cle === 'numero')
                            <td class="cell-centre font-bold" style="padding: {{ $padding }};">{{ $ligne['numero'] }}</td>
                        @elseif($cle === 'matricule')
                            <td class="cell-mono" style="padding: {{ $padding }};">{{ $ligne['matricule'] }}</td>
                        @elseif($cle === 'nom')
                            <td style="font-weight: 600; padding: {{ $padding }};">{{ $ligne['nom'] }}</td>
                        @elseif($cle === 'prenom')
                            <td style="padding: {{ $padding }};">{{ $ligne['prenom'] }}</td>
                        @elseif($cle === 'cin')
                            <td class="cell-mono" style="padding: {{ $padding }};">{{ $ligne['cin'] ?? '—' }}</td>
                        @elseif($cle === 'presence')
                            <td class="cell-checkbox" style="padding: {{ $padding }};">{{ $ligne['presence'] }}</td>
                        @elseif($cle === 'signature' || $cle === 'signature_enseignant')
                            <td class="cell-signature" style="padding: {{ $padding }};">
                                @if(!empty($ligne['signature']))
                                    <span style="font-family: cursive; color: #1e3a5f;">{{ $ligne['signature'] }}</span>
                                @endif
                            </td>
                        @elseif($cle === 'qr_code')
                            <td class="cell-centre" style="padding: 2pt;">
                                @if(! empty($ligne['qr_code_svg']))
                                    <div style="display: inline-block; width: 36px; height: 36px; margin: 0 auto;">
                                        {!! $ligne['qr_code_svg'] !!}
                                    </div>
                                @else
                                    <span style="color: #94a3b8; font-size: 7pt;">[QR]</span>
                                @endif
                            </td>
                        @elseif($cle === 'poste')
                            <td class="cell-centre font-semibold" style="padding: {{ $padding }};">{{ $ligne['poste'] }}</td>
                        @elseif($cle === 'piece_identite')
                            <td class="cell-centre" style="padding: {{ $padding }};">{{ $ligne['piece_identite'] ?? '☐' }}</td>
                        @elseif($cle === 'retard')
                            <td class="cell-centre" style="padding: {{ $padding }};">{{ $ligne['retard'] }}</td>
                        @elseif($cle === 'heure_arrivee')
                            <td class="cell-centre" style="padding: {{ $padding }};">{{ $ligne['heure_arrivee'] !== '—' ? $ligne['heure_arrivee'] : '……:……' }}</td>
                        @elseif($cle === 'heure_depart')
                            <td class="cell-centre" style="padding: {{ $padding }};">{{ $ligne['heure_depart'] !== '—' ? $ligne['heure_depart'] : '……:……' }}</td>
                        @elseif($cle === 'observation')
                            <td style="color: #64748b; font-size: 7.5pt; padding: {{ $padding }};">{{ $ligne['observation'] }}</td>
                        @else
                            <td style="padding: {{ $padding }};">{{ $ligne[$cle] ?? '' }}</td>
                        @endif
                    @endforeach
                </tr>
            @empty
                <tr>
                    <td colspan="{{ count($colonnes) }}" class="cell-centre" style="padding: 16pt; color: #64748b;">
                        Aucun étudiant inscrit dans ce groupe pour les critères sélectionnés.
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>
@endif
