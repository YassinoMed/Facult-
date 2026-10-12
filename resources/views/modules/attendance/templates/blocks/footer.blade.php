@if($config['enabled'] ?? true)
    <div class="pied-document">
        <table class="pied-table">
            <tr>
                <td style="text-align: left;">
                    @if($config['show_legal_notice'] ?? true)
                        {{ !empty($config['custom_notice']) ? $config['custom_notice'] : ($metadonnees['institution'] . ' · Document officiel de scolarité · Réf : ' . $metadonnees['reference']) }}
                    @endif
                </td>
                <td style="text-align: right;">
                    @if($config['show_generation_date'] ?? true)
                        Généré le {{ $metadonnees['date_generation'] }}
                    @endif
                    @if($config['show_page_number'] ?? true)
                        · Page <span class="pagenum"></span> sur <span class="pagecount"></span>
                    @endif
                </td>
            </tr>
        </table>
    </div>
@endif
