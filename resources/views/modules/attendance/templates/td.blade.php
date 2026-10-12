@extends('modules.attendance.templates.layout')

@section('cartouche')
    <div class="cartouche-seance" style="background-color: #f0fdf4; border-color: #bbf7d0;">
        <table class="grille-meta">
            <tr>
                <td style="width: 25%;">
                    <span class="meta-label">Formation :</span>
                    <span class="meta-valeur">{{ $metadonnees['formation'] }}</span>
                </td>
                <td style="width: 25%;">
                    <span class="meta-label">Niveau / Semestre :</span>
                    <span class="meta-valeur">{{ $metadonnees['niveau'] }}</span>
                </td>
                <td style="width: 25%;">
                    <span class="meta-label">Groupe TD :</span>
                    <span class="meta-valeur" style="color: #166534;">{{ $metadonnees['groupe'] }}</span>
                </td>
                <td style="width: 25%; text-align: right;">
                    <span class="meta-label">Année académique :</span>
                    <span class="meta-valeur">{{ $metadonnees['annee_universitaire'] }}</span>
                </td>
            </tr>
            <tr>
                <td>
                    <span class="meta-label">Matière / ECUE :</span>
                    <span class="meta-valeur">{{ $metadonnees['matiere'] }}</span>
                </td>
                <td>
                    <span class="meta-label">Enseignant chargé de TD :</span>
                    <span class="meta-valeur">{{ $metadonnees['enseignant'] }}</span>
                </td>
                <td>
                    <span class="meta-label">Date & Horaires :</span>
                    <span class="meta-valeur">{{ $metadonnees['date'] }} ({{ $metadonnees['horaire'] }})</span>
                </td>
                <td style="text-align: right;">
                    <span class="meta-label">Salle TD :</span>
                    <span class="meta-valeur">{{ $metadonnees['salle'] }}</span>
                </td>
            </tr>
        </table>
    </div>
@endsection

@section('tableau')
    <table class="table-presence">
        <thead>
            <tr>
                <th style="width: 5%;" class="cell-centre">N°</th>
                <th style="width: 20%;">Nom</th>
                <th style="width: 20%;">Prénom</th>
                <th style="width: 15%;">Matricule</th>
                <th style="width: 12%;" class="cell-centre">Présence</th>
                <th style="width: 14%;" class="cell-centre">Signature</th>
                <th style="width: 14%;">Observation</th>
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
                    <td style="color: #64748b; font-size: 7.5pt;">{{ $ligne['observation'] }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="7" class="cell-centre" style="padding: 16pt; color: #64748b;">
                        Aucun étudiant inscrit dans ce groupe de TD pour les critères sélectionnés.
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>
@endsection
