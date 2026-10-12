@if($config['enabled'] ?? true)
    <div class="entete-officiel">
        <table class="entete-table">
            <tr>
                <td style="width: 60%;">
                    @if($config['show_institution'] ?? true)
                        <div class="institution-nom">{{ $metadonnees['institution'] }}</div>
                    @endif
                    @if($config['show_faculte'] ?? true)
                        <div class="faculte-nom">{{ $metadonnees['faculte'] }}</div>
                    @endif
                    @if(($config['show_departement'] ?? true) && ! empty($metadonnees['departement']))
                        <div class="departement-nom">{{ $metadonnees['departement'] }}</div>
                    @endif
                </td>
                <td style="width: 40%; text-align: {{ $config['title_alignment'] ?? 'right' }};">
                    <div class="titre-document">{{ $metadonnees['titre'] }}</div>
                    <div class="badge-type">{{ $template->name }}</div>
                    @if($config['show_reference'] ?? true)
                        <div style="font-size: 7.5pt; color: #64748b; margin-top: 2pt;">
                            Réf : {{ $metadonnees['reference'] }}
                        </div>
                    @endif
                </td>
            </tr>
        </table>
    </div>
@endif
