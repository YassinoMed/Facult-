@extends('modules.attendance.templates.layout')

@section('tableau')
    <table class="table-presence">
        <thead>
            <tr>
                @foreach($colonnes as $colonne)
                    <th style="{{ isset($colonne['width']) ? 'width: '.$colonne['width'].';' : '' }}"
                        class="{{ in_array($colonne['key'], ['numero', 'presence', 'signature', 'signature_enseignant', 'qr_code', 'piece_identite', 'retard']) ? 'cell-centre' : '' }}">
                        {{ $colonne['label'] ?? AttendanceTemplate::COLONNES_DISPONIBLES[$colonne['key']] ?? ucfirst($colonne['key']) }}
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
                            <td class="cell-centre font-bold">{{ $ligne['numero'] }}</td>
                        @elseif($cle === 'matricule')
                            <td class="cell-mono">{{ $ligne['matricule'] }}</td>
                        @elseif($cle === 'nom')
                            <td style="font-weight: 600;">{{ $ligne['nom'] }}</td>
                        @elseif($cle === 'prenom')
                            <td>{{ $ligne['prenom'] }}</td>
                        @elseif($cle === 'presence')
                            <td class="cell-checkbox">{{ $ligne['presence'] }}</td>
                        @elseif($cle === 'signature' || $cle === 'signature_enseignant')
                            <td class="cell-signature"></td>
                        @elseif($cle === 'qr_code')
                            <td class="cell-centre" style="padding: 2pt;">
                                @if(! empty($ligne['qr_code_svg']))
                                    <div style="display: inline-block; width: 40px; height: 40px; margin: 0 auto;">
                                        {!! $ligne['qr_code_svg'] !!}
                                    </div>
                                @else
                                    <span style="color: #94a3b8; font-size: 7pt;">[QR]</span>
                                @endif
                            </td>
                        @elseif($cle === 'poste')
                            <td class="cell-centre font-semibold">{{ $ligne['poste'] }}</td>
                        @elseif($cle === 'piece_identite')
                            <td class="cell-centre">☐ Conforme</td>
                        @elseif($cle === 'retard')
                            <td class="cell-centre">{{ $ligne['retard'] }}</td>
                        @elseif($cle === 'heure_arrivee')
                            <td class="cell-centre">{{ $ligne['heure_arrivee'] !== '—' ? $ligne['heure_arrivee'] : '……:……' }}</td>
                        @elseif($cle === 'heure_depart')
                            <td class="cell-centre">{{ $ligne['heure_depart'] !== '—' ? $ligne['heure_depart'] : '……:……' }}</td>
                        @elseif($cle === 'observation')
                            <td style="color: #64748b; font-size: 7.5pt;">{{ $ligne['observation'] }}</td>
                        @else
                            <td>{{ $ligne[$cle] ?? '' }}</td>
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
@endsection
