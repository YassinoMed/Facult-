{{--
    Sélecteur de thème du portail étudiant.
    Utilisé dans la barre latérale (format compact) et dans la fiche
    étudiante (format complet). Les liens restent fonctionnels sans
    JavaScript : ils rechargent la page avec `?theme=v1|v2`.
--}}
@props(['theme' => 'v2', 'compact' => false])

@php
    $themeCourant = $theme === 'v1' ? 'v1' : 'v2';
    $estCompact = filter_var($compact, FILTER_VALIDATE_BOOLEAN);
@endphp

<div @class([
        'etudiant-theme-switch',
        'etudiant-theme-switch--compact' => $estCompact,
    ])
     data-etudiant-theme-switch
     role="group"
     aria-label="Thème du portail étudiant">
    @unless($estCompact)
        <span class="etudiant-theme-switch-label">Thème</span>
    @endunless

    <a href="{{ request()->fullUrlWithQuery(['theme' => 'v1']) }}"
       class="etudiant-theme-option"
       data-etudiant-theme-option="v1"
       @if($themeCourant === 'v1') aria-current="true" @endif
       aria-label="Thème V1, classique">
        V1
        <span class="etudiant-theme-option-label">Classique</span>
    </a>

    <a href="{{ request()->fullUrlWithQuery(['theme' => 'v2']) }}"
       class="etudiant-theme-option"
       data-etudiant-theme-option="v2"
       @if($themeCourant === 'v2') aria-current="true" @endif
       aria-label="Thème V2, bêta">
        V2
        <span class="etudiant-theme-beta">Beta</span>
    </a>
</div>
