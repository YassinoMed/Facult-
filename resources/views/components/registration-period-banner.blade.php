{{--
    Bannière d'état période d'inscription (LOOP UI §6/§22) : le backend
    fournit `etat` (OPEN/CLOSED/EXCEPTION_OPEN/NOT_CONFIGURED), le
    frontend ne fait que le représenter. Icône + texte (jamais la
    couleur seule) pour l'accessibilité.
--}}
@props(['etat'])

@php
$styles = [
    'OPEN' => ['type' => 'success', 'icone' => '✓'],
    'EXCEPTION_OPEN' => ['type' => 'info', 'icone' => '⚡'],
    'CLOSED' => ['type' => 'error', 'icone' => '🔒'],
    'NOT_CONFIGURED' => ['type' => 'info', 'icone' => 'ℹ️'],
];
$style = $styles[$etat['statut']] ?? $styles['NOT_CONFIGURED'];
@endphp

<div role="status" aria-label="État des inscriptions : {{ $etat['titre'] }}">
    <x-alert :type="$style['type']" :titre="$style['icone'].' '.$etat['titre']">
        {{ $etat['message'] }}
        @if(! empty($etat['debut']) && ! empty($etat['fin']) && $etat['statut'] !== 'NOT_CONFIGURED')
            <br><span class="text-xs">Période : {{ $etat['debut'] }} → {{ $etat['fin'] }}.</span>
        @endif
    </x-alert>
</div>
