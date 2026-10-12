@extends('modules.student-dossiers.pdf.layouts.document')

@section('contenu')
    @php
        $afficher = fn (string $cle) => empty($champsEntete) || in_array($cle, $champsEntete, true);
        $hasBlocTitre = ($afficher('etablissement') && ! empty($metadonnees['etablissement']))
            || ($afficher('faculte') && ! empty($metadonnees['faculte']))
            || ($afficher('departement') && ! empty($metadonnees['departement']))
            || ($afficher('annee') && ! empty($metadonnees['annee']))
            || ($afficher('groupe') && ! empty($metadonnees['groupe']))
            || ($afficher('programme') && ! empty($metadonnees['programme']))
            || ($afficher('parcours') && ! empty($metadonnees['parcours']))
            || ($afficher('niveau') && ! empty($metadonnees['niveau']))
            || ($afficher('semestre') && ! empty($metadonnees['semestre']));
    @endphp

    @if(! empty($metadonnees))
        @if($hasBlocTitre || $afficher('filtres') || $afficher('utilisateur') || $afficher('effectif'))
            <div class="contexte">
                @if($hasBlocTitre)
                    @if($afficher('etablissement') && ! empty($metadonnees['etablissement']))<div class="bloc-titre">{{ $metadonnees['etablissement'] }}</div>@endif
                    @if($afficher('faculte') && ! empty($metadonnees['faculte']))<div>Faculté : {{ $metadonnees['faculte'] }}</div>@endif
                    @if($afficher('departement') && ! empty($metadonnees['departement']))<div>Département : {{ $metadonnees['departement'] }}</div>@endif
                    @if($afficher('annee') && ! empty($metadonnees['annee']))<div>Année universitaire : {{ $metadonnees['annee'] }}</div>@endif
                    @if($afficher('groupe') && ! empty($metadonnees['groupe']))<div>Groupe : {{ $metadonnees['groupe'] }}</div>@endif
                    @if($afficher('programme') && ! empty($metadonnees['programme']))<div>Programme : {{ $metadonnees['programme'] }}</div>@endif
                    @if($afficher('parcours') && ! empty($metadonnees['parcours']))<div>Parcours : {{ $metadonnees['parcours'] }}</div>@endif
                    @if($afficher('niveau') && ! empty($metadonnees['niveau']))<div>Niveau : {{ $metadonnees['niveau'] }}</div>@endif
                    @if($afficher('semestre') && ! empty($metadonnees['semestre']))<div>Semestre : {{ $metadonnees['semestre'] }}</div>@endif
                @endif
                @if($afficher('filtres') && ! empty($contexte))
                    <div class="filtres">Filtres appliqués : {{ $contexte }}</div>
                @endif
                @if($afficher('utilisateur') || $afficher('effectif') || ! empty($metadonnees['regroupement']))
                    <div class="filtres">
                        @if($afficher('utilisateur'))Généré par {{ $metadonnees['utilisateur'] ?? '—' }}@endif
                        @if($afficher('utilisateur') && $afficher('effectif')) · @endif
                        @if($afficher('effectif')){{ $metadonnees['total'] ?? count($lignes ?? []) }} résultat(s)@endif
                        @if(! empty($metadonnees['regroupement'])) · Regroupé par {{ $metadonnees['regroupement'] }} @endif
                    </div>
                @endif
            </div>
        @endif
    @else
        <div class="meta">{{ $contexte }}</div>
    @endif

    @if(! empty($sections))
        @foreach($sections as $section)
            <div class="section-bloc">
                <div class="section-titre">{{ $section['titre'] }}</div>
                <p class="section-effectif">Effectif : {{ $section['effectif'] }} étudiant(s)</p>
                <table>
                    <thead>
                        <tr>
                            @foreach($entetes as $entete)
                                <th>{{ $entete }}</th>
                            @endforeach
                            @if($signature ?? false)
                                <th>Signature</th>
                            @endif
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($section['lignes'] as $ligne)
                            <tr>
                                @foreach($entetes as $entete)
                                    <td>{{ $ligne[$entete] ?? '' }}</td>
                                @endforeach
                                @if($signature ?? false)
                                    <td style="min-width: 90pt;">&nbsp;</td>
                                @endif
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endforeach
        <p class="meta">Total : {{ $metadonnees['total'] ?? 0 }} étudiant(s) · {{ count($sections) }} section(s).</p>
    @else
        <table>
            <thead>
                <tr>
                    @foreach($entetes as $entete)
                        <th>{{ $entete }}</th>
                    @endforeach
                    @if($signature ?? false)
                        <th>Signature</th>
                    @endif
                </tr>
            </thead>
            <tbody>
                @forelse($lignes as $ligne)
                    <tr>
                        @foreach($entetes as $entete)
                            <td>{{ $ligne[$entete] ?? '' }}</td>
                        @endforeach
                        @if($signature ?? false)
                            <td style="min-width: 90pt;">&nbsp;</td>
                        @endif
                    </tr>
                @empty
                    <tr><td colspan="{{ count($entetes) + ($signature ?? false ? 1 : 0) }}">Aucun résultat pour les critères sélectionnés.</td></tr>
                @endforelse
            </tbody>
        </table>

        <p class="meta">{{ count($lignes) }} ligne(s).</p>
    @endif

    @if($zoneSignature ?? false)
        <div class="signature">
            Responsable pédagogique — Nom : ……………………………… Signature : ……………………………… Date : ………………
            <br />Cachet : (signature manuscrite — ne constitue pas une signature électronique qualifiée)
        </div>
    @endif
@endsection
