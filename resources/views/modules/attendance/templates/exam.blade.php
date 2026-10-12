@extends('modules.attendance.templates.layout')

@section('cartouche')
    <div class="cartouche-seance" style="background-color: #fef2f2; border-color: #fecaca;">
        <table class="grille-meta">
            <tr>
                <td style="width: 25%;">
                    <span class="meta-label">Type d'épreuve :</span>
                    <span class="meta-valeur" style="color: #991b1b;">{{ $metadonnees['type_examen'] }}</span>
                </td>
                <td style="width: 25%;">
                    <span class="meta-label">Session :</span>
                    <span class="meta-valeur">{{ $metadonnees['session'] }}</span>
                </td>
                <td style="width: 25%;">
                    <span class="meta-label">Matière / Épreuve :</span>
                    <span class="meta-valeur">{{ $metadonnees['matiere'] }}</span>
                </td>
                <td style="width: 25%; text-align: right;">
                    <span class="meta-label">Année universitaire :</span>
                    <span class="meta-valeur">{{ $metadonnees['annee_universitaire'] }}</span>
                </td>
            </tr>
            <tr>
                <td>
                    <span class="meta-label">Date :</span>
                    <span class="meta-valeur">{{ $metadonnees['date'] }}</span>
                </td>
                <td>
                    <span class="meta-label">Horaires d'épreuve :</span>
                    <span class="meta-valeur">{{ $metadonnees['horaire'] }}</span>
                </td>
                <td>
                    <span class="meta-label">Salle d'examen :</span>
                    <span class="meta-valeur">{{ $metadonnees['salle'] }}</span>
                </td>
                <td style="text-align: right;">
                    <span class="meta-label">Responsable d'épreuve :</span>
                    <span class="meta-valeur">{{ $metadonnees['enseignant'] }}</span>
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
                <th style="width: 15%;">Matricule</th>
                <th style="width: 18%;">Nom</th>
                <th style="width: 18%;">Prénom</th>
                <th style="width: 11%;" class="cell-centre">Pièce vérifiée</th>
                <th style="width: 10%;" class="cell-centre">Présence</th>
                <th style="width: 12%;" class="cell-centre">Émargement</th>
                <th style="width: 11%;">Observation</th>
            </tr>
        </thead>
        <tbody>
            @forelse($lignes as $ligne)
                <tr>
                    <td class="cell-centre font-bold">{{ $ligne['numero'] }}</td>
                    <td class="cell-mono font-semibold">{{ $ligne['matricule'] }}</td>
                    <td style="font-weight: 600;">{{ $ligne['nom'] }}</td>
                    <td>{{ $ligne['prenom'] }}</td>
                    <td class="cell-centre" style="font-size: 10pt; color: #475569;">☐ CIN / Carte</td>
                    <td class="cell-checkbox">{{ $ligne['presence'] }}</td>
                    <td class="cell-signature" style="height: 24pt;"></td>
                    <td style="color: #64748b; font-size: 7pt;">{{ $ligne['observation'] }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="8" class="cell-centre" style="padding: 16pt; color: #64748b;">
                        Aucun candidat convoqué pour cette épreuve d'examen.
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>
@endsection

@section('signatures_finales')
    <div class="bloc-signatures-fin">
        <table class="table-signatures-fin">
            <tr>
                <td>
                    <div class="sig-titre" style="color: #991b1b;">Surveillant 1 (Titulaire)</div>
                    <div class="sig-mentions">
                        Nom et Prénom : {{ $metadonnees['surveillant_1'] }}<br>
                        Heure de remise des copies : …………:…………<br>
                        Nombre de copies remises : ……………<br>
                        Signature :
                    </div>
                </td>
                <td style="margin-left: 8pt;">
                    <div class="sig-titre" style="color: #991b1b;">Surveillant 2 (Adjoint)</div>
                    <div class="sig-mentions">
                        Nom et Prénom : {{ $metadonnees['surveillant_2'] }}<br>
                        Nombre de présents : ……… / Absents : ………<br>
                        Incidents constatés : Aucun / Voir PV<br>
                        Signature :
                    </div>
                </td>
            </tr>
        </table>
    </div>
@endsection
