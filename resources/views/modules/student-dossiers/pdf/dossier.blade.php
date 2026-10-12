@extends('modules.student-dossiers.pdf.layouts.document', [
    'titreDocument' => 'Dossier étudiant',
    'institution' => $institution,
    'faculteNom' => $academique['Faculté'] ?? '',
    'reference' => $reference,
    'dateGeneration' => $dateGeneration,
    'piedComplement' => config('student-dossiers.pdf.footer'),
])

@section('contenu')
    <h2>Identité</h2>
    @foreach($identite as $libelle => $valeur)
        <p class="kv"><span class="cle">{{ $libelle }}</span> <span class="valeur">{{ $valeur }}</span></p>
    @endforeach

    <h2>Informations académiques</h2>
    @foreach($academique as $libelle => $valeur)
        <p class="kv"><span class="cle">{{ $libelle }}</span> <span class="valeur">{{ $valeur }}</span></p>
    @endforeach

    <h2>Documents ({{ count($documents) }})</h2>
    @if(count($documents) > 0)
        <table>
            <thead>
                <tr>
                    <th>N°</th>
                    <th>Catégorie</th>
                    <th>Type</th>
                    <th>Document</th>
                    <th>Statut</th>
                    <th>Version</th>
                    <th>Date</th>
                    <th>Expiration</th>
                </tr>
            </thead>
            <tbody>
                @foreach($documents as $index => $ligne)
                    <tr>
                        <td>{{ $index + 1 }}</td>
                        <td>{{ $ligne['categorie'] }}</td>
                        <td>{{ $ligne['type'] }}</td>
                        <td>{{ $ligne['titre'] }}</td>
                        <td>{{ $ligne['statut'] }}</td>
                        <td>{{ $ligne['version'] }}</td>
                        <td>{{ $ligne['date'] }}</td>
                        <td>{{ $ligne['expiration'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @else
        <p>Aucun document dans ce dossier.</p>
    @endif

    <h2>Historique</h2>
    @if(count($historique) > 0)
        <table>
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                @foreach($historique as $entree)
                    <tr>
                        <td>{{ $entree['date'] }}</td>
                        <td>{{ $entree['action'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @else
        <p>Aucun événement enregistré.</p>
    @endif

    <div class="signature">
        <p><strong>Document généré par :</strong> la scolarité &nbsp;·&nbsp; <strong>Date :</strong> {{ $dateGeneration }}</p>
        <p><strong>Signature / cachet :</strong></p>
        <p style="height: 40pt;">&nbsp;</p>
    </div>
@endsection
