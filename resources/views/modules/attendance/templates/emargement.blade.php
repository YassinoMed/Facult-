@extends('modules.attendance.templates.layout')

@section('tableau')
    <table class="table-presence">
        <thead>
            <tr>
                <th style="width: 8%;" class="cell-centre">N°</th>
                <th style="width: 24%;">Nom</th>
                <th style="width: 24%;">Prénom</th>
                <th style="width: 18%;">Matricule</th>
                <th style="width: 26%;" class="cell-centre">Signature d'émargement</th>
            </tr>
        </thead>
        <tbody>
            @forelse($lignes as $ligne)
                <tr>
                    <td class="cell-centre font-bold">{{ $ligne['numero'] }}</td>
                    <td style="font-weight: 600;">{{ $ligne['nom'] }}</td>
                    <td>{{ $ligne['prenom'] }}</td>
                    <td class="cell-mono">{{ $ligne['matricule'] }}</td>
                    <td class="cell-signature" style="height: 26pt;"></td>
                </tr>
            @empty
                <tr>
                    <td colspan="5" class="cell-centre" style="padding: 16pt; color: #64748b;">
                        Aucun étudiant inscrit dans ce groupe pour les critères sélectionnés.
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>
@endsection
