@extends('modules.attendance.templates.layout')

@section('cartouche')
    <div class="cartouche-seance" style="background-color: #eff6ff; border-color: #bfdbfe;">
        <table class="grille-meta">
            <tr>
                <td style="width: 25%;">
                    <span class="meta-label">Groupe TP / Section :</span>
                    <span class="meta-valeur" style="color: #1d4ed8;">{{ $metadonnees['groupe'] }}</span>
                </td>
                <td style="width: 30%;">
                    <span class="meta-label">Matière / Laboratoire :</span>
                    <span class="meta-valeur">{{ $metadonnees['matiere'] }}</span>
                </td>
                <td style="width: 25%;">
                    <span class="meta-label">Enseignant / Tuteur TP :</span>
                    <span class="meta-valeur">{{ $metadonnees['enseignant'] }}</span>
                </td>
                <td style="width: 20%; text-align: right;">
                    <span class="meta-label">Année :</span>
                    <span class="meta-valeur">{{ $metadonnees['annee_universitaire'] }}</span>
                </td>
            </tr>
            <tr>
                <td>
                    <span class="meta-label">Date :</span>
                    <span class="meta-valeur">{{ $metadonnees['date'] }}</span>
                </td>
                <td>
                    <span class="meta-label">Créneau TP :</span>
                    <span class="meta-valeur">{{ $metadonnees['horaire'] }}</span>
                </td>
                <td>
                    <span class="meta-label">Laboratoire / Salle Machine :</span>
                    <span class="meta-valeur">{{ $metadonnees['salle'] }}</span>
                </td>
                <td style="text-align: right;">
                    <span class="meta-label">Effectif TP :</span>
                    <span class="meta-valeur">{{ $metadonnees['total'] }} étudiant(s)</span>
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
                <th style="width: 18%;">Nom</th>
                <th style="width: 18%;">Prénom</th>
                <th style="width: 14%;">Matricule</th>
                <th style="width: 12%;" class="cell-centre">Poste / Machine</th>
                <th style="width: 10%;" class="cell-centre">Présence</th>
                <th style="width: 12%;" class="cell-centre">Signature</th>
                <th style="width: 11%;">Observation</th>
            </tr>
        </thead>
        <tbody>
            @forelse($lignes as $ligne)
                <tr>
                    <td class="cell-centre font-bold">{{ $ligne['numero'] }}</td>
                    <td style="font-weight: 600;">{{ $ligne['nom'] }}</td>
                    <td>{{ $ligne['prenom'] }}</td>
                    <td class="cell-mono">{{ $ligne['matricule'] }}</td>
                    <td class="cell-centre font-semibold" style="color: #2563eb;">{{ $ligne['poste'] }}</td>
                    <td class="cell-checkbox">{{ $ligne['presence'] }}</td>
                    <td class="cell-signature"></td>
                    <td style="color: #64748b; font-size: 7.5pt;">{{ $ligne['observation'] }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="8" class="cell-centre" style="padding: 16pt; color: #64748b;">
                        Aucun étudiant inscrit dans ce groupe de TP pour les critères sélectionnés.
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>
@endsection
