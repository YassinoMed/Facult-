@if($config['enabled'] ?? true)
    <div class="cartouche-seance">
        <table class="grille-meta">
            @if(($config['layout'] ?? 'grid_4') === 'grid_2')
                <tr>
                    @if($config['show_groupe'] ?? true)
                        <td style="width: 50%;">
                            <span class="meta-label">Groupe :</span>
                            <span class="meta-valeur">{{ $metadonnees['groupe'] }}</span>
                        </td>
                    @endif
                    @if($config['show_matiere'] ?? true)
                        <td style="width: 50%;">
                            <span class="meta-label">Matière / ECUE :</span>
                            <span class="meta-valeur">{{ $metadonnees['matiere'] }}</span>
                        </td>
                    @endif
                </tr>
                <tr>
                    @if($config['show_enseignant'] ?? true)
                        <td>
                            <span class="meta-label">Enseignant :</span>
                            <span class="meta-valeur">{{ $metadonnees['enseignant'] }}</span>
                        </td>
                    @endif
                    @if($config['show_salle'] ?? true)
                        <td>
                            <span class="meta-label">Salle :</span>
                            <span class="meta-valeur">{{ $metadonnees['salle'] }}</span>
                        </td>
                    @endif
                </tr>
                <tr>
                    @if($config['show_date'] ?? true)
                        <td>
                            <span class="meta-label">Date :</span>
                            <span class="meta-valeur">{{ $metadonnees['date'] }}</span>
                        </td>
                    @endif
                    @if($config['show_horaire'] ?? true)
                        <td>
                            <span class="meta-label">Horaire / Séance :</span>
                            <span class="meta-valeur">{{ $metadonnees['horaire'] }}</span>
                        </td>
                    @endif
                </tr>
                <tr>
                    @if($config['show_annee'] ?? true)
                        <td>
                            <span class="meta-label">Année :</span>
                            <span class="meta-valeur">{{ $metadonnees['annee_universitaire'] }}</span>
                        </td>
                    @endif
                    @if($config['show_effectif'] ?? true)
                        <td>
                            <span class="meta-label">Effectif :</span>
                            <span class="meta-valeur">{{ $metadonnees['total'] }} étudiant(s)</span>
                        </td>
                    @endif
                </tr>
            @else
                <tr>
                    @if($config['show_groupe'] ?? true)
                        <td style="width: 25%;">
                            <span class="meta-label">Groupe :</span>
                            <span class="meta-valeur">{{ $metadonnees['groupe'] }}</span>
                        </td>
                    @endif
                    @if($config['show_matiere'] ?? true)
                        <td style="width: 30%;">
                            <span class="meta-label">Matière / ECUE :</span>
                            <span class="meta-valeur">{{ $metadonnees['matiere'] }}</span>
                        </td>
                    @endif
                    @if($config['show_enseignant'] ?? true)
                        <td style="width: 25%;">
                            <span class="meta-label">Enseignant :</span>
                            <span class="meta-valeur">{{ $metadonnees['enseignant'] }}</span>
                        </td>
                    @endif
                    @if($config['show_annee'] ?? true)
                        <td style="width: 20%; text-align: right;">
                            <span class="meta-label">Année :</span>
                            <span class="meta-valeur">{{ $metadonnees['annee_universitaire'] }}</span>
                        </td>
                    @endif
                </tr>
                <tr>
                    @if($config['show_date'] ?? true)
                        <td>
                            <span class="meta-label">Date :</span>
                            <span class="meta-valeur">{{ $metadonnees['date'] }}</span>
                        </td>
                    @endif
                    @if($config['show_horaire'] ?? true)
                        <td>
                            <span class="meta-label">Horaire / Séance :</span>
                            <span class="meta-valeur">{{ $metadonnees['horaire'] }}</span>
                        </td>
                    @endif
                    @if($config['show_salle'] ?? true)
                        <td>
                            <span class="meta-label">Salle :</span>
                            <span class="meta-valeur">{{ $metadonnees['salle'] }}</span>
                        </td>
                    @endif
                    @if($config['show_effectif'] ?? true)
                        <td style="text-align: right;">
                            <span class="meta-label">Effectif :</span>
                            <span class="meta-valeur">{{ $metadonnees['total'] }} étudiant(s)</span>
                        </td>
                    @endif
                </tr>
            @endif
        </table>
    </div>
@endif
