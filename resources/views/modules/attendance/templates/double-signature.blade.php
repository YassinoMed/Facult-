@extends('modules.attendance.templates.layout')

@section('tableau')
    <table class="table-presence">
        <thead>
            <tr>
                <th style="width: 5%;" class="cell-centre">N°</th>
                <th style="width: 18%;">Nom</th>
                <th style="width: 18%;">Prénom</th>
                <th style="width: 14%;">Matricule</th>
                <th style="width: 9%;" class="cell-centre">Présence</th>
                <th style="width: 13%;" class="cell-centre">Signature étudiant</th>
                <th style="width: 13%;" class="cell-centre">Visa enseignant</th>
                <th style="width: 10%;">Observation</th>
            </tr>
        </thead>
        <tbody>
            @forelse($lignes as $ligne)
                <tr>
                    <td class="cell-centre font-bold">{{ $ligne['numero'] }}</td>
                    <td style="font-weight: 600;">{{ $ligne['nom'] }}</td>
                    <td>{{ $ligne['prenom'] }}</td>
                    <td class="cell-mono">{{ $ligne['matricule'] }}</td>
                    <td class="cell-checkbox">{{ $ligne['presence'] }}</td>
                    <td class="cell-signature"></td>
                    <td class="cell-signature"></td>
                    <td style="color: #64748b; font-size: 7.5pt;">{{ $ligne['observation'] }}</td>
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
