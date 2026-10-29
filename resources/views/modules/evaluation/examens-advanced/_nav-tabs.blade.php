{{--
    Barre de navigation transversale des 12 menus fonctionnels d'Examens Advanced.
    Offre une expérience fluide avec onglets actifs, icônes et accessibilité ARIA.

    Lecture : la barre est un index, pas un parcours à étapes. L'en-tête situe
    le menu courant (groupe + rang sur 12) sans suggérer une progression, et les
    quatre groupes sont séparés visuellement dans la bande défilante.
--}}
@php
$menusExamens = [
    [
        'id' => 'tableau-de-bord',
        'route' => 'admin.evaluation.examens.tableau-de-bord',
        'motif' => 'admin.evaluation.examens.tableau-de-bord*',
        'label' => 'Tableau de bord',
        'numero' => '1',
        'groupe' => 'Vue d’ensemble',
        'icone' => 'M3.75 6A2.25 2.25 0 0 1 6 3.75h2.25A2.25 2.25 0 0 1 10.5 6v2.25a2.25 2.25 0 0 1-2.25 2.25H6a2.25 2.25 0 0 1-2.25-2.25V6ZM3.75 15.75A2.25 2.25 0 0 1 6 13.5h2.25a2.25 2.25 0 0 1 2.25 2.25V18a2.25 2.25 0 0 1-2.25 2.25H6A2.25 2.25 0 0 1 3.75 18v-2.25ZM13.5 6a2.25 2.25 0 0 1 2.25-2.25H18A2.25 2.25 0 0 1 20.25 6v2.25A2.25 2.25 0 0 1 18 10.5h-2.25a2.25 2.25 0 0 1-2.25-2.25V6ZM13.5 15.75a2.25 2.25 0 0 1 2.25-2.25H18a2.25 2.25 0 0 1 2.25 2.25V18A2.25 2.25 0 0 1 18 20.25h-2.25A2.25 2.25 0 0 1 13.5 18v-2.25Z',
    ],
    [
        'id' => 'sessions',
        'route' => 'admin.evaluation.examens.sessions',
        'motif' => 'admin.evaluation.examens.sessions*',
        'label' => 'Sessions',
        'numero' => '2',
        'groupe' => 'Vue d’ensemble',
        'icone' => 'M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 0 1 2.25-2.25h13.5A2.25 2.25 0 0 1 21 7.5v11.25m-18 0A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75m-18 0v-7.5A2.25 2.25 0 0 1 5.25 9h13.5A2.25 2.25 0 0 1 21 11.25v7.5',
    ],
    [
        'id' => 'configurations',
        'route' => 'admin.evaluation.examens.configurations',
        'motif' => 'admin.evaluation.examens.configurations*',
        'label' => 'Configurations',
        'numero' => '3',
        'groupe' => 'Paramétrage',
        'icone' => 'M10.5 6h9.75M10.5 6a1.5 1.5 0 1 1-3 0m3 0a1.5 1.5 0 1 0-3 0M3.75 6H7.5m3 12h9.75m-9.75 0a1.5 1.5 0 0 1-3 0m3 0a1.5 1.5 0 0 0-3 0m-3.75 0H7.5m9-6h3.75m-3.75 0a1.5 1.5 0 0 1-3 0m3 0a1.5 1.5 0 0 0-3 0m-9 0h6.375',
    ],
    [
        'id' => 'eligibilite',
        'route' => 'admin.evaluation.examens.eligibilite',
        'motif' => 'admin.evaluation.examens.eligibilite*',
        'label' => 'Éligibilité',
        'numero' => '4',
        'groupe' => 'Paramétrage',
        'icone' => 'M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z',
    ],
    [
        'id' => 'groupes',
        'route' => 'admin.evaluation.examens.groupes',
        'motif' => 'admin.evaluation.examens.groupes*',
        'label' => 'Groupes d\'examen',
        'numero' => '5',
        'groupe' => 'Paramétrage',
        'icone' => 'M18 18.72a9.094 9.094 0 0 0 3.741-.479 3 3 0 0 0-4.682-2.72m.94 3.198.001.031c0 .225-.012.447-.037.666A11.944 11.944 0 0 1 12 21c-2.17 0-4.207-.576-5.963-1.584A6.062 6.062 0 0 1 6 18.719m12 0a5.971 5.971 0 0 0-.941-3.197m0 0A5.995 5.995 0 0 0 12 12.75a5.995 5.995 0 0 0-5.058 2.772m0 0a3 3 0 0 0-4.681 2.72 8.986 8.986 0 0 0 3.74.477m.94-3.197a5.971 5.971 0 0 0-.94 3.197M15 6.75a3 3 0 1 1-6 0 3 3 0 0 1 6 0Zm6 3a2.25 2.25 0 1 1-4.5 0 2.25 2.25 0 0 1 4.5 0Zm-13.5 0a2.25 2.25 0 1 1-4.5 0 2.25 2.25 0 0 1 4.5 0Z',
    ],
    [
        'id' => 'salles',
        'route' => 'admin.evaluation.examens.salles',
        'motif' => 'admin.evaluation.examens.salles*',
        'label' => 'Salles & Capacités',
        'numero' => '6',
        'groupe' => 'Paramétrage',
        'icone' => 'M2.25 21h19.5m-18-18v18m10.5-18v18m6-13.5V21M6.75 6.75h.75m-.75 3h.75m-.75 3h.75m3-6h.75m-.75 3h.75m-.75 3h.75M6.75 21v-3.75a1.5 1.5 0 0 1 1.5-1.5h1.5a1.5 1.5 0 0 1 1.5 1.5V21',
    ],
    [
        'id' => 'planification',
        'route' => 'admin.evaluation.examens.planification',
        'motif' => 'admin.evaluation.examens.planification*',
        'label' => 'Planification',
        'numero' => '7',
        'groupe' => 'Déroulement',
        'icone' => 'M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z',
    ],
    [
        'id' => 'surveillance',
        'route' => 'admin.evaluation.examens.surveillance',
        'motif' => 'admin.evaluation.examens.surveillance*',
        'label' => 'Surveillance',
        'numero' => '8',
        'groupe' => 'Déroulement',
        'icone' => 'M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178Z',
    ],
    [
        'id' => 'simulation',
        'route' => 'admin.evaluation.examens.simulation',
        'motif' => 'admin.evaluation.examens.simulation*',
        'label' => 'Simulation & Qualité',
        'numero' => '9',
        'groupe' => 'Déroulement',
        'icone' => 'M9.75 3.104v5.714a2.25 2.25 0 0 1-.659 1.591L5 14.5M9.75 3.104c-.251.023-.501.05-.75.082m.75-.082a24.301 24.301 0 0 1 4.5 0m0 0v5.714c0 .597.237 1.17.659 1.591L19.8 15.3M14.25 3.104c.251.023.501.05.75.082M19.8 15.3l-1.57.942A4.5 4.5 0 0 1 15.9 16.5H8.1a4.5 4.5 0 0 1-2.33-.658L4.2 14.9m15.6.4v4.35a2.25 2.25 0 0 1-2.25 2.25H6.45a2.25 2.25 0 0 1-2.25-2.25V14.9',
    ],
    [
        'id' => 'publication',
        'route' => 'admin.evaluation.examens.publication',
        'motif' => 'admin.evaluation.examens.publication*',
        'label' => 'Publication',
        'numero' => '10',
        'groupe' => 'Diffusion & suivi',
        'icone' => 'M9 12.75 11.25 15 15 9.75m-3-7.036A11.959 11.959 0 0 1 3.598 6 11.99 11.99 0 0 0 3 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285Z',
    ],
    [
        'id' => 'convocations',
        'route' => 'admin.evaluation.examens.convocations',
        'motif' => 'admin.evaluation.examens.convocations*',
        'label' => 'Convocations',
        'numero' => '11',
        'groupe' => 'Diffusion & suivi',
        'icone' => 'M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z',
    ],
    [
        'id' => 'historique',
        'route' => 'admin.evaluation.examens.historique',
        'motif' => 'admin.evaluation.examens.historique*',
        'label' => 'Historique & Audit',
        'numero' => '12',
        'groupe' => 'Diffusion & suivi',
        'icone' => 'M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z',
    ],
];

$indexActif = null;

foreach ($menusExamens as $index => $menu) {
    if (request()->routeIs($menu['motif'])) {
        $indexActif = $index;
        break;
    }
}

$menuActif = $indexActif !== null ? $menusExamens[$indexActif] : null;
$totalMenus = count($menusExamens);
@endphp

<nav aria-label="Menus des examens avancés"
     class="mb-6 rounded-2xl border border-slate-200/80 bg-white p-1.5 shadow-xs">
    <div class="flex items-center justify-between gap-3 px-2 pb-2 pt-1.5">
        <span class="flex min-w-0 items-center gap-2">
            <span class="inline-flex h-5 w-5 shrink-0 items-center justify-center rounded-md bg-primary-50 text-primary-700 ring-1 ring-primary-100" aria-hidden="true">
                <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5M3.75 17.25h10.5" />
                </svg>
            </span>
            <span class="whitespace-nowrap text-[10.5px] font-semibold uppercase tracking-[0.14em] text-slate-600">
                Examens Advanced — 12 Menus fonctionnels
            </span>
        </span>

        @if($menuActif)
            <span class="flex shrink-0 items-center gap-2">
                <span class="hidden text-[10px] font-semibold uppercase tracking-[0.12em] text-slate-600 sm:inline">
                    {{ $menuActif['groupe'] }}
                </span>
                <span class="hidden h-3 w-px shrink-0 bg-slate-200 sm:inline-block" aria-hidden="true"></span>
                <span class="whitespace-nowrap text-[11px] font-medium text-slate-600">
                    Menu <span class="font-bold tabular-nums text-primary-800">{{ $indexActif + 1 }}</span>
                    <span class="text-slate-600">sur {{ $totalMenus }}</span>
                </span>
            </span>
        @else
            <span class="hidden shrink-0 text-[11px] font-medium text-slate-600 sm:inline">Navigation transversale unifiée</span>
        @endif
    </div>

    {{-- Navigation sur 2 niveaux (2 lignes) sans défilement horizontal --}}
    <div data-motion-tabs
         class="examens-tabs flex flex-col gap-1.5 px-1 py-1">
        {{-- Niveau 1 : Menus 1 à 6 (Vue d’ensemble & Paramétrage) --}}
        <div class="flex flex-wrap items-center gap-1">
            @foreach(array_slice($menusExamens, 0, 6) as $relIndex => $menu)
                @php($index = $relIndex)
                @if($index > 0 && $menu['groupe'] !== $menusExamens[$index - 1]['groupe'])
                    <span class="examens-tabs-separateur" aria-hidden="true"></span>
                @endif

                @php($actif = $index === $indexActif)

                <a href="{{ route($menu['route']) }}"
                   class="examens-tab group inline-flex shrink-0 items-center gap-1.5 whitespace-nowrap rounded-lg border px-2.5 py-1.5 text-xs transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-500/50 focus-visible:ring-offset-1 focus-visible:ring-offset-white {{ $actif ? 'border-primary-900/15 bg-primary-800 font-semibold text-white shadow-xs' : 'border-transparent font-medium text-slate-600 hover:border-slate-200 hover:bg-slate-50 hover:text-slate-900' }}"
                   @if($actif) aria-current="page" @endif>
                    <span class="examens-tab-numero inline-flex h-4 w-4 items-center justify-center rounded-[5px] text-[10px] font-bold tabular-nums {{ $actif ? 'bg-white text-primary-800' : 'bg-slate-100 text-slate-600 group-hover:bg-white group-hover:text-primary-700 group-hover:ring-1 group-hover:ring-slate-200' }}"
                          aria-hidden="true">{{ $menu['numero'] }}</span>
                    <span>{{ $menu['label'] }}</span>
                </a>
            @endforeach
        </div>

        {{-- Séparateur entre Niveau 1 et Niveau 2 (groupe 2 Paramétrage -> groupe 3 Déroulement) --}}
        <span class="examens-tabs-separateur hidden" aria-hidden="true"></span>

        {{-- Niveau 2 : Menus 7 à 12 (Déroulement & Diffusion/suivi) --}}
        <div class="flex flex-wrap items-center gap-1 border-t border-slate-100 dark:border-slate-800/80 pt-1.5">
            @foreach(array_slice($menusExamens, 6, 6) as $relIndex => $menu)
                @php($index = $relIndex + 6)
                @if($relIndex > 0 && $menu['groupe'] !== $menusExamens[$index - 1]['groupe'])
                    <span class="examens-tabs-separateur" aria-hidden="true"></span>
                @endif

                @php($actif = $index === $indexActif)

                <a href="{{ route($menu['route']) }}"
                   class="examens-tab group inline-flex shrink-0 items-center gap-1.5 whitespace-nowrap rounded-lg border px-2.5 py-1.5 text-xs transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-500/50 focus-visible:ring-offset-1 focus-visible:ring-offset-white {{ $actif ? 'border-primary-900/15 bg-primary-800 font-semibold text-white shadow-xs' : 'border-transparent font-medium text-slate-600 hover:border-slate-200 hover:bg-slate-50 hover:text-slate-900' }}"
                   @if($actif) aria-current="page" @endif>
                    <span class="examens-tab-numero inline-flex h-4 w-4 items-center justify-center rounded-[5px] text-[10px] font-bold tabular-nums {{ $actif ? 'bg-white text-primary-800' : 'bg-slate-100 text-slate-600 group-hover:bg-white group-hover:text-primary-700 group-hover:ring-1 group-hover:ring-slate-200' }}"
                          aria-hidden="true">{{ $menu['numero'] }}</span>
                    <span>{{ $menu['label'] }}</span>
                </a>
            @endforeach
        </div>
    </div>
</nav>
