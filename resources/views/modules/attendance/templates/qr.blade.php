@extends('modules.attendance.templates.layout')

@section('tableau')
    <table class="table-presence">
        <thead>
            <tr>
                <th style="width: 5%;" class="cell-centre">N°</th>
                <th style="width: 14%;">Matricule</th>
                <th style="width: 17%;">Nom</th>
                <th style="width: 17%;">Prénom</th>
                <th style="width: 13%;" class="cell-centre">QR Code séance</th>
                <th style="width: 10%;" class="cell-centre">Présence</th>
                <th style="width: 12%;" class="cell-centre">Signature</th>
                <th style="width: 12%;">Observation</th>
            </tr>
        </thead>
        <tbody>
            @forelse($lignes as $ligne)
                <tr>
                    <td class="cell-centre font-bold">{{ $ligne['numero'] }}</td>
                    <td class="cell-mono font-semibold">{{ $ligne['matricule'] }}</td>
                    <td style="font-weight: 600;">{{ $ligne['nom'] }}</td>
                    <td>{{ $ligne['prenom'] }}</td>
                    <td class="cell-centre" style="padding: 2pt;">
                        @if(! empty($ligne['qr_code_svg']))
                            <div style="display: inline-block; width: 44px; height: 44px; margin: 0 auto;">
                                {!! $ligne['qr_code_svg'] !!}
                            </div>
                        @else
                            <span style="color: #94a3b8; font-size: 7pt;">[QR N/A]</span>
                        @endif
                    </td>
                    <td class="cell-checkbox">{{ $ligne['presence'] }}</td>
                    <td class="cell-signature"></td>
                    <td style="color: #64748b; font-size: 7pt;">{{ $ligne['observation'] }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="8" class="cell-centre" style="padding: 16pt; color: #64748b;">
                        Aucun étudiant inscrit dans ce groupe pour les critères sélectionnés.
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>
@endsection
