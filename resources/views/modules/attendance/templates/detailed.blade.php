@extends('modules.attendance.templates.layout')

@section('tableau')
    <table class="table-presence">
        <thead>
            <tr>
                <th style="width: 5%;" class="cell-centre">N°</th>
                <th style="width: 16%;">Nom</th>
                <th style="width: 16%;">Prénom</th>
                <th style="width: 12%;">Matricule</th>
                <th style="width: 9%;" class="cell-centre">Arrivée</th>
                <th style="width: 9%;" class="cell-centre">Présence</th>
                <th style="width: 8%;" class="cell-centre">Retard</th>
                <th style="width: 8%;" class="cell-centre">Départ</th>
                <th style="width: 9%;" class="cell-centre">Signature</th>
                <th style="width: 8%;">Obs.</th>
            </tr>
        </thead>
        <tbody>
            @forelse($lignes as $ligne)
                <tr>
                    <td class="cell-centre font-bold">{{ $ligne['numero'] }}</td>
                    <td style="font-weight: 600;">{{ $ligne['nom'] }}</td>
                    <td>{{ $ligne['prenom'] }}</td>
                    <td class="cell-mono">{{ $ligne['matricule'] }}</td>
                    <td class="cell-centre">{{ $ligne['heure_arrivee'] !== '—' ? $ligne['heure_arrivee'] : '……:……' }}</td>
                    <td class="cell-checkbox">{{ $ligne['presence'] }}</td>
                    <td class="cell-centre">{{ $ligne['retard'] === 'Oui' ? 'OUI' : 'NON' }}</td>
                    <td class="cell-centre">{{ $ligne['heure_depart'] !== '—' ? $ligne['heure_depart'] : '……:……' }}</td>
                    <td class="cell-signature"></td>
                    <td style="color: #64748b; font-size: 7pt;">{{ $ligne['observation'] }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="10" class="cell-centre" style="padding: 16pt; color: #64748b;">
                        Aucun étudiant inscrit dans ce groupe pour les critères sélectionnés.
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>
@endsection
