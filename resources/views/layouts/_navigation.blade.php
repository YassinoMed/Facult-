{{--
    Navigation par rôle (§8/§17/§31) : sections, état actif, modules
    filtrés (actif + permission). Utilisée par la sidebar desktop et
    le drawer mobile — source unique, zéro duplication.
--}}
@php
$classeLien = 'flex items-center gap-3 px-3 py-2.5 rounded-xl text-sm font-semibold transition focus:outline-none';
$classeActif = 'bg-primary-800 text-white ';
$classeInactif = 'text-slate-600 hover:bg-slate-100 hover:text-slate-900';
@endphp

@if(auth()->check())
    <div class="admin-nav-section" data-ouvert="true"
         x-data="{ ouvert: (localStorage.getItem('admin-nav-pilotage') ?? '1') === '1' }"
         :data-ouvert="ouvert" @keydown.escape.window="$el.querySelector('button')?.blur()">
        <button type="button" @click="ouvert = !ouvert; localStorage.setItem('admin-nav-pilotage', ouvert ? '1' : '0')"
                :aria-expanded="ouvert.toString()" aria-controls="nav-pilotage">
            <span class="px-3 text-[13px] font-semibold text-slate-500">Pilotage</span>
            <svg class="chevron h-3.5 w-3.5 text-slate-400" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" /></svg>
        </button>
        <div id="nav-pilotage" x-show="ouvert" x-cloak class="admin-nav-liens space-y-1">
    <a href="{{ route('admin.dashboard') }}"
       class="{{ $classeLien }} {{ request()->routeIs('admin.dashboard') ? $classeActif.' admin-nav-lien-actif' : $classeInactif }}"
       @if(request()->routeIs('admin.dashboard')) aria-current="page" @endif>
        <svg class="h-5 w-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6A2.25 2.25 0 0 1 6 3.75h2.25A2.25 2.25 0 0 1 10.5 6v2.25a2.25 2.25 0 0 1-2.25 2.25H6a2.25 2.25 0 0 1-2.25-2.25V6ZM3.75 15.75A2.25 2.25 0 0 1 6 13.5h2.25a2.25 2.25 0 0 1 2.25 2.25V18a2.25 2.25 0 0 1-2.25 2.25H6A2.25 2.25 0 0 1 3.75 18v-2.25ZM13.5 6a2.25 2.25 0 0 1 2.25-2.25H18A2.25 2.25 0 0 1 20.25 6v2.25A2.25 2.25 0 0 1 18 10.5h-2.25a2.25 2.25 0 0 1-2.25-2.25V6ZM13.5 15.75a2.25 2.25 0 0 1 2.25-2.25H18a2.25 2.25 0 0 1 2.25 2.25V18A2.25 2.25 0 0 1 18 20.25h-2.25A2.25 2.25 0 0 1 13.5 18v-2.25Z" /></svg>
        Tableau de bord
    </a>
    <a href="{{ route('admin.population.stats') }}"
       class="{{ $classeLien }} {{ request()->routeIs('admin.population.*') ? $classeActif.' admin-nav-lien-actif' : $classeInactif }}"
       @if(request()->routeIs('admin.population.*')) aria-current="page" @endif>
        <svg class="h-5 w-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 0 1 3 19.875v-6.75ZM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V8.625ZM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V4.125Z" /></svg>
        Population
    </a>
    @can('cockpit.view')
    <a href="{{ route('admin.cockpit.index') }}"
       class="{{ $classeLien }} {{ request()->routeIs('admin.cockpit.*') ? $classeActif.' admin-nav-lien-actif' : $classeInactif }}"
       @if(request()->routeIs('admin.cockpit.*')) aria-current="page" @endif>
        <svg class="h-5 w-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 13.5l10.5-11.25L12 10.5h8.25L9.75 21.75 12 13.5H3.75z" /></svg>
        Cockpit
    </a>
    @endcan
    @if(auth()->user()->can('tasks.view') || auth()->user()->can('archives.view'))
    <a href="{{ route('admin.centre-travail.index') }}"
       class="{{ $classeLien }} {{ request()->routeIs('admin.centre-travail.*') ? $classeActif.' admin-nav-lien-actif' : $classeInactif }}"
       @if(request()->routeIs('admin.centre-travail.*')) aria-current="page" @endif>
        <svg class="h-5 w-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12h3.75M9 15h3.75M9 18h3.75m3 .75H18a2.25 2.25 0 0 0 2.25-2.25V6.108c0-1.135-.845-2.098-1.976-2.192a48.424 48.424 0 0 0-1.123-.08m-5.801 0c-.065.21-.1.433-.1.664 0 .414.336.75.75.75h4.5a.75.75 0 0 0 .75-.75 2.25 2.25 0 0 0-.1-.664m-5.8 0A2.251 2.251 0 0 1 13.5 2.25H15c1.012 0 1.867.668 2.15 1.586m-5.8 0c-.376.023-.75.05-1.124.08C9.095 4.01 8.25 4.973 8.25 6.108V8.25m0 0H4.875c-.621 0-1.125.504-1.125 1.125v11.25c0 .621.504 1.125 1.125 1.125h9.75c.621 0 1.125-.504 1.125-1.125V9.375c0-.621-.504-1.125-1.125-1.125H8.25ZM6.75 12h.008v.008H6.75V12Zm0 3h.008v.008H6.75V15Zm0 3h.008v.008H6.75V18Z" /></svg>
        Centre de travail
    </a>
    @endif
    @can('statistiques.view')
    <a href="{{ route('admin.bi.index') }}"
       class="{{ $classeLien }} {{ request()->routeIs('admin.bi.*') ? $classeActif.' admin-nav-lien-actif' : $classeInactif }}"
       @if(request()->routeIs('admin.bi.*')) aria-current="page" @endif>
        <svg class="h-5 w-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 0 1 3 19.875v-6.75ZM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V8.625ZM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V4.125Z" /></svg>
        BI
    </a>
    <a href="{{ route('admin.datamesh.index') }}"
       class="{{ $classeLien }} {{ request()->routeIs('admin.datamesh.*') ? $classeActif.' admin-nav-lien-actif' : $classeInactif }}"
       @if(request()->routeIs('admin.datamesh.*')) aria-current="page" @endif>
        <svg class="h-5 w-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M7.5 3.75h9m-9 0a3 3 0 0 0-3 3v9a3 3 0 0 0 3 3h9a3 3 0 0 0 3-3v-9a3 3 0 0 0-3-3m-9 0v15m9-15v15m-9-7.5h9" /></svg>
        Data Mesh
    </a>
    @if(config('modules.simulateur-scenarios.active'))
    <a href="{{ route('admin.simulateur-scenarios.index') }}"
       class="{{ $classeLien }} {{ request()->routeIs('admin.simulateur-scenarios.*') ? $classeActif.' admin-nav-lien-actif' : $classeInactif }}"
       @if(request()->routeIs('admin.simulateur-scenarios.*')) aria-current="page" @endif>
        <svg class="h-5 w-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75c0 .621-.504 1.125-1.125 1.125h-2.25A1.125 1.125 0 0 1 3 19.875v-6.75Zm6.75-4.5c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V8.625Zm6.75-4.5c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V4.125Z" /></svg>
        Simulateur
    </a>
    @endif
    @endcan
        </div>
    </div>

    <div class="admin-nav-section" data-ouvert="true"
         x-data="{ ouvert: (localStorage.getItem('admin-nav-scolarite') ?? '1') === '1' }"
         :data-ouvert="ouvert">
        <button type="button" @click="ouvert = !ouvert; localStorage.setItem('admin-nav-scolarite', ouvert ? '1' : '0')"
                :aria-expanded="ouvert.toString()" aria-controls="nav-scolarite">
            <span class="px-3 text-[13px] font-semibold text-slate-500">Scolarité</span>
            <svg class="chevron h-3.5 w-3.5 text-slate-400" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" /></svg>
        </button>
        <div id="nav-scolarite" x-show="ouvert" x-cloak class="admin-nav-liens space-y-1">
    @foreach([
        ['admin.inscriptions.file-traitement', 'admin.inscriptions.file-traitement*', 'File des inscriptions', 'M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z'],
        ['admin.etudiants.index', 'admin.etudiants.*', 'Registre des étudiants', 'M15 19.128a9.38 9.38 0 0 0 2.625.372 9.337 9.337 0 0 0 4.121-.952 4.125 4.125 0 0 0-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 0 1 8.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0 1 11.964-3.07M12 6.375a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0Zm8.25 2.25a2.625 2.625 0 1 1-5.25 0 2.625 2.625 0 0 1 5.25 0Z'],
        ['admin.archives.index', 'admin.archives.*', 'Archives', 'M12 21v-8.25M15.75 21v-8.25M8.25 21v-8.25M3 9l9-6 9 6m-1.5 12V10.332A48.36 48.36 0 0 0 12 9.75c-2.551 0-5.056.2-7.5.582V21M3 21h18M12 6.75h.008v.008H12V6.75Z'],
        ['admin.groupes.index', 'admin.groupes.*', 'Groupes', 'M3.75 6A2.25 2.25 0 0 1 6 3.75h2.25A2.25 2.25 0 0 1 10.5 6v2.25a2.25 2.25 0 0 1-2.25 2.25H6a2.25 2.25 0 0 1-2.25-2.25V6ZM3.75 15.75A2.25 2.25 0 0 1 6 13.5h2.25a2.25 2.25 0 0 1 2.25 2.25V18a2.25 2.25 0 0 1-2.25 2.25H6A2.25 2.25 0 0 1 3.75 18v-2.25ZM13.5 6a2.25 2.25 0 0 1 2.25-2.25H18A2.25 2.25 0 0 1 20.25 6v2.25A2.25 2.25 0 0 1 18 10.5h-2.25a2.25 2.25 0 0 1-2.25-2.25V6ZM13.5 15.75a2.25 2.25 0 0 1 2.25-2.25H18a2.25 2.25 0 0 1 2.25 2.25V18A2.25 2.25 0 0 1 18 20.25h-2.25A2.25 2.25 0 0 1 13.5 18v-2.25Z'],
        ['admin.facultes.index', 'admin.facultes.*', 'Facultés', 'M12 21v-8.25M15.75 21v-8.25M8.25 21v-8.25M3 9l9-6 9 6m-1.5 12V10.332A48.36 48.36 0 0 0 12 9.75c-2.551 0-5.056.2-7.5.582V21M3 21h18M12 6.75h.008v.008H12V6.75Z'],
        ['admin.periodes.index', 'admin.periodes.*', 'Périodes d’inscription', 'M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z'],
        ['admin.admissions.index', 'admin.admissions.*', 'Admissions', 'M16.5 18.75h-9m9 0a3 3 0 0 1 5.66 1.5 3 3 0 0 1-5.66 0Zm-9 0a3 3 0 0 0-5.66 1.5 3 3 0 0 0 5.66 0Zm.75-13.5L10.5 3m-3 2.25h6M6.75 21h9.75'],
    ] as [$route, $motif, $libelle, $icone])
        <a href="{{ route($route) }}"
           class="{{ $classeLien }} {{ request()->routeIs($motif) ? $classeActif.' admin-nav-lien-actif' : $classeInactif }}"
           @if(request()->routeIs($motif)) aria-current="page" @endif>
            <svg class="h-5 w-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="{{ $icone }}" /></svg>
            {{ $libelle }}
        </a>
    @endforeach
        </div>
    </div>
    <div class="admin-nav-section" data-ouvert="true"
         x-data="{ ouvert: (localStorage.getItem('admin-nav-formation') ?? '1') === '1' }"
         :data-ouvert="ouvert">
        <button type="button" @click="ouvert = !ouvert; localStorage.setItem('admin-nav-formation', ouvert ? '1' : '0')"
                :aria-expanded="ouvert.toString()" aria-controls="nav-formation">
            <span class="px-3 text-[13px] font-semibold text-slate-500">Formation</span>
            <svg class="chevron h-3.5 w-3.5 text-slate-400" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" /></svg>
        </button>
        <div id="nav-formation" x-show="ouvert" x-cloak class="admin-nav-liens space-y-1">
    @foreach([
        ['admin.enseignants.index', 'admin.enseignants.*', 'Enseignants'],
        ['admin.salles.index', 'admin.salles.*', 'Salles'],
    ] as [$route, $motif, $libelle])
        <a href="{{ route($route) }}"
           class="{{ $classeLien }} {{ request()->routeIs($motif) ? $classeActif.' admin-nav-lien-actif' : $classeInactif }}"
           @if(request()->routeIs($motif)) aria-current="page" @endif>
            <svg class="h-5 w-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6.042A8.967 8.967 0 0 0 6 3.75c-1.052 0-2.062.18-3 .512v14.25A8.987 8.987 0 0 1 6 18c2.305 0 4.408.867 6 2.292m0-14.25a8.966 8.966 0 0 1 6-2.292c1.052 0 2.062.18 3 .512v14.25A8.987 8.987 0 0 0 18 18a8.967 8.967 0 0 0-6 2.292m0-14.25v14.25" /></svg>
            {{ $libelle }}
        </a>
    @endforeach
        </div>
    </div>
    <div class="admin-nav-section" data-ouvert="true"
         x-data="{ ouvert: (localStorage.getItem('admin-nav-modules') ?? '1') === '1' }"
         :data-ouvert="ouvert">
        <button type="button" @click="ouvert = !ouvert; localStorage.setItem('admin-nav-modules', ouvert ? '1' : '0')"
                :aria-expanded="ouvert.toString()" aria-controls="nav-modules">
            <span class="px-3 text-[13px] font-semibold text-slate-500">Planification &amp; services</span>
            <svg class="chevron h-3.5 w-3.5 text-slate-400" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" /></svg>
        </button>
        <div id="nav-modules" x-show="ouvert" x-cloak class="admin-nav-liens space-y-1">
    @foreach(app(\App\Support\Modules\ModuleRegistry::class)->elementsNavigation('admin') as $item)
        @if($item['permission'] === null || auth()->user()->can($item['permission']))
            @php
                $motif = match($item['route']) {
                    'admin.evaluations.index' => 'admin.evaluations.index',
                    'admin.evaluations.examens.index' => 'admin.evaluations.examens.*',
                    'admin.evaluations.epreuves.index' => ['admin.evaluations.epreuves.*', 'admin.evaluations.notes.*', 'admin.notes.*'],
                    'admin.evaluations.affectations', 'admin.evaluations.affectations.index' => 'admin.evaluations.affectations*',
                    'admin.evaluations.surveillances.index' => ['admin.evaluations.surveillances.index', 'admin.evaluations.surveillances.cockpit', 'admin.evaluations.surveillances.mes-gardes', 'admin.evaluations.surveillances.export', 'admin.evaluations.surveillants.*'],
                    'admin.evaluations.surveillances.emploi' => 'admin.evaluations.surveillances.emploi*',
                    'admin.evaluations.surveillances.contraintes.index' => 'admin.evaluations.surveillances.contraintes*',
                    default => [$item['route'], str_replace(['.index', '.tableau-bord'], '.*', $item['route'])],
                };
                $estActif = request()->routeIs($motif);
            @endphp
            <a href="{{ route($item['route']) }}"
               class="{{ $classeLien }} {{ $estActif ? $classeActif.' admin-nav-lien-actif' : $classeInactif }}"
               @if($estActif) aria-current="page" @endif>
                <svg class="h-5 w-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" aria-hidden="true">
                    @if(isset($item['icone']))
                        <path stroke-linecap="round" stroke-linejoin="round" d="{{ $item['icone'] }}" />
                    @elseif(str_contains($item['route'], 'epreuve') || str_contains($item['route'], 'note'))
                        <path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931Zm0 0L19.5 7.125M18 14v4.75A2.25 2.25 0 0 1 15.75 21H5.25A2.25 2.25 0 0 1 3 18.75V8.25A2.25 2.25 0 0 1 5.25 6H10" />
                    @elseif(str_contains($item['route'], 'evaluation'))
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h3.75M9 15h3.75M9 18h3.75m3 .75H18a2.25 2.25 0 0 0 2.25-2.25V6.108c0-1.135-.845-2.098-1.976-2.192a48.424 48.424 0 0 0-1.123-.08m-5.801 0c-.065.21-.1.433-.1.664 0 .414.336.75.75.75h4.5a.75.75 0 0 0 .75-.75 2.25 2.25 0 0 0-.1-.664m-5.8 0A2.251 2.251 0 0 1 13.5 2.25H15c1.012 0 1.867.668 2.15 1.586m-5.8 0c-.376.023-.75.05-1.124.08C9.095 4.01 8.25 4.973 8.25 6.108V8.25m0 0H4.875c-.621 0-1.125.504-1.125 1.125v11.25c0 .621.504 1.125 1.125 1.125h9.75c.621 0 1.125-.504 1.125-1.125V9.375c0-.621-.504-1.125-1.125-1.125H8.25ZM6.75 12h.008v.008H6.75V12Zm0 3h.008v.008H6.75V15Zm0 3h.008v.008H6.75V18Z" />
                    @else
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                    @endif
                </svg>
                {{ $item['label'] }}
            </a>
        @endif
    @endforeach
        </div>
    </div>

    @if(config('modules.evaluation.active', true) && (auth()->user()->estSuperAdmin() || auth()->user()->can('evaluations.view') || in_array(auth()->user()->role, ['admin', 'admin_scolarite', 'admin_faculte'], true)))
    <div class="admin-nav-section" data-ouvert="true"
         x-data="{ ouvert: (localStorage.getItem('admin-nav-examens-advanced') ?? '1') === '1' }"
         :data-ouvert="ouvert">
        <button type="button" @click="ouvert = !ouvert; localStorage.setItem('admin-nav-examens-advanced', ouvert ? '1' : '0')"
                :aria-expanded="ouvert.toString()" aria-controls="nav-examens-advanced">
            <span class="px-3 text-[13px] font-semibold text-slate-500">Examens</span>
            <svg class="chevron h-3.5 w-3.5 text-slate-400" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" /></svg>
        </button>
        <div id="nav-examens-advanced" x-show="ouvert" x-cloak class="admin-nav-liens space-y-1">
            @foreach([
                ['admin.evaluation.examens.tableau-de-bord', 'admin.evaluation.examens.tableau-de-bord*', 'Tableau de bord', 'M3.75 6A2.25 2.25 0 0 1 6 3.75h2.25A2.25 2.25 0 0 1 10.5 6v2.25a2.25 2.25 0 0 1-2.25 2.25H6a2.25 2.25 0 0 1-2.25-2.25V6ZM3.75 15.75A2.25 2.25 0 0 1 6 13.5h2.25a2.25 2.25 0 0 1 2.25 2.25V18a2.25 2.25 0 0 1-2.25 2.25H6A2.25 2.25 0 0 1 3.75 18v-2.25ZM13.5 6a2.25 2.25 0 0 1 2.25-2.25H18A2.25 2.25 0 0 1 20.25 6v2.25A2.25 2.25 0 0 1 18 10.5h-2.25a2.25 2.25 0 0 1-2.25-2.25V6ZM13.5 15.75a2.25 2.25 0 0 1 2.25-2.25H18a2.25 2.25 0 0 1 2.25 2.25V18A2.25 2.25 0 0 1 18 20.25h-2.25A2.25 2.25 0 0 1 13.5 18v-2.25Z'],
                ['admin.evaluation.examens.sessions', 'admin.evaluation.examens.sessions*', 'Sessions d’examen', 'M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 0 1 2.25-2.25h13.5A2.25 2.25 0 0 1 21 7.5v11.25m-18 0A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75m-18 0v-7.5A2.25 2.25 0 0 1 5.25 9h13.5A2.25 2.25 0 0 1 21 11.25v7.5'],
                ['admin.evaluation.examens.configurations', 'admin.evaluation.examens.configurations*', 'Configurations & Modules', 'M10.5 6h9.75M10.5 6a1.5 1.5 0 1 1-3 0m3 0a1.5 1.5 0 1 0-3 0M3.75 6H7.5m3 12h9.75m-9.75 0a1.5 1.5 0 0 1-3 0m3 0a1.5 1.5 0 0 0-3 0m-3.75 0H7.5m9-6h3.75m-3.75 0a1.5 1.5 0 0 1-3 0m3 0a1.5 1.5 0 0 0-3 0m-9 0h6.375'],
                ['admin.evaluation.examens.eligibilite', 'admin.evaluation.examens.eligibilite*', 'Éligibilité candidats', 'M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z'],
                ['admin.evaluation.examens.groupes', 'admin.evaluation.examens.groupes*', 'Groupes d’examen', 'M18 18.72a9.094 9.094 0 0 0 3.741-.479 3 3 0 0 0-4.682-2.72m.94 3.198.001.031c0 .225-.012.447-.037.666A11.944 11.944 0 0 1 12 21c-2.17 0-4.207-.576-5.963-1.584A6.062 6.062 0 0 1 6 18.719m12 0a5.971 5.971 0 0 0-.941-3.197m0 0A5.995 5.995 0 0 0 12 12.75a5.995 5.995 0 0 0-5.058 2.772m0 0a3 3 0 0 0-4.681 2.72 8.986 8.986 0 0 0 3.74.477m.94-3.197a5.971 5.971 0 0 0-.94 3.197M15 6.75a3 3 0 1 1-6 0 3 3 0 0 1 6 0Zm6 3a2.25 2.25 0 1 1-4.5 0 2.25 2.25 0 0 1 4.5 0Zm-13.5 0a2.25 2.25 0 1 1-4.5 0 2.25 2.25 0 0 1 4.5 0Z'],
                ['admin.evaluation.examens.salles', 'admin.evaluation.examens.salles*', 'Salles & Capacités', 'M2.25 21h19.5m-18-18v18m10.5-18v18m6-13.5V21M6.75 6.75h.75m-.75 3h.75m-.75 3h.75m3-6h.75m-.75 3h.75m-.75 3h.75M6.75 21v-3.75a1.5 1.5 0 0 1 1.5-1.5h1.5a1.5 1.5 0 0 1 1.5 1.5V21'],
                ['admin.evaluation.examens.planification', 'admin.evaluation.examens.planification*', 'Planification auto', 'M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z'],
                ['admin.evaluation.examens.surveillance', 'admin.evaluation.examens.surveillance*', 'Surveillance', 'M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178Z'],
                ['admin.evaluation.examens.simulation', 'admin.evaluation.examens.simulation*', 'Simulation & Qualité', 'M9.75 3.104v5.714a2.25 2.25 0 0 1-.659 1.591L5 14.5M9.75 3.104c-.251.023-.501.05-.75.082m.75-.082a24.301 24.301 0 0 1 4.5 0m0 0v5.714c0 .597.237 1.17.659 1.591L19.8 15.3M14.25 3.104c.251.023.501.05.75.082M19.8 15.3l-1.57.942A4.5 4.5 0 0 1 15.9 16.5H8.1a4.5 4.5 0 0 1-2.33-.658L4.2 14.9m15.6.4v4.35a2.25 2.25 0 0 1-2.25 2.25H6.45a2.25 2.25 0 0 1-2.25-2.25V14.9'],
                ['admin.evaluation.examens.publication', 'admin.evaluation.examens.publication*', 'Validation & Publication', 'M9 12.75 11.25 15 15 9.75m-3-7.036A11.959 11.959 0 0 1 3.598 6 11.99 11.99 0 0 0 3 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285Z'],
                ['admin.evaluation.examens.convocations', 'admin.evaluation.examens.convocations*', 'Convocations', 'M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z'],
                ['admin.evaluation.examens.historique', 'admin.evaluation.examens.historique*', 'Historique & Audit', 'M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z'],
            ] as [$route, $motif, $libelle, $icone])
                <a href="{{ route($route) }}"
                   class="{{ $classeLien }} {{ request()->routeIs($motif) ? $classeActif.' admin-nav-lien-actif' : $classeInactif }}"
                   @if(request()->routeIs($motif)) aria-current="page" @endif>
                    <svg class="h-5 w-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="{{ $icone }}" /></svg>
                    {{ $libelle }}
                </a>
            @endforeach
        </div>
    </div>
    @endif

    <div class="admin-nav-section" data-ouvert="true"
         x-data="{ ouvert: (localStorage.getItem('admin-nav-donnees') ?? '1') === '1' }"
         :data-ouvert="ouvert">
        <button type="button" @click="ouvert = !ouvert; localStorage.setItem('admin-nav-donnees', ouvert ? '1' : '0')"
                :aria-expanded="ouvert.toString()" aria-controls="nav-donnees">
            <span class="px-3 text-[13px] font-semibold text-slate-500">Données</span>
            <svg class="chevron h-3.5 w-3.5 text-slate-400" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" /></svg>
        </button>
        <div id="nav-donnees" x-show="ouvert" x-cloak class="admin-nav-liens space-y-1">
    @can('data.import')
        <a href="{{ route('admin.donnees.hub') }}"
           class="{{ $classeLien }} {{ request()->routeIs('admin.donnees.*') ? $classeActif.' admin-nav-lien-actif' : $classeInactif }}">
            <svg class="h-5 w-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 16.5V9.75m0 0 3 3m-3-3-3 3M6.75 19.5a4.5 4.5 0 0 1-1.41-8.775 6 6 0 0 1 11.32-2.27 4.5 4.5 0 0 1 1.34 8.797" /></svg>
            Import / Export
        </a>
        <a href="{{ route('admin.donnees.import.form', 'notes') }}"
           class="{{ $classeLien }} {{ request()->routeIs('admin.donnees.import.form') && request()->route('entite') === 'notes' ? $classeActif.' admin-nav-lien-actif' : $classeInactif }}"
           @if(request()->routeIs('admin.donnees.import.form') && request()->route('entite') === 'notes') aria-current="page" @endif>
            <svg class="h-5 w-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" /></svg>
            Importer les notes
        </a>
    @endcan
    @can('document.view')
        <a href="{{ route('admin.documents-center.index') }}"
           class="{{ $classeLien }} {{ request()->routeIs('admin.documents-center.*') ? $classeActif.' admin-nav-lien-actif' : $classeInactif }}"
           @if(request()->routeIs('admin.documents-center.*')) aria-current="page" @endif>
            <svg class="h-5 w-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z" /></svg>
            Documents académiques
        </a>
    @endcan
    @can('document.generate')
        <a href="{{ route('admin.attestations.index') }}"
           class="{{ $classeLien }} {{ request()->routeIs('admin.attestations.*') ? $classeActif.' admin-nav-lien-actif' : $classeInactif }}"
           @if(request()->routeIs('admin.attestations.*')) aria-current="page" @endif>
            <svg class="h-5 w-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M4.26 10.147a60.436 60.436 0 0 0-.491 6.347A48.627 48.627 0 0 1 12 20.904a48.627 48.627 0 0 1 8.232-4.41 60.46 60.46 0 0 0-.491-6.347m-15.482 0a50.57 50.57 0 0 0-2.658-.813A59.905 59.905 0 0 1 12 3.493a59.902 59.902 0 0 1 10.399 5.84c-.896.248-1.783.52-2.658.814m-15.482 0A50.594 50.594 0 0 1 12 13.489a50.594 50.594 0 0 1 7.74-3.342M6.75 15a.75.75 0 1 0 0-1.5.75.75 0 0 0 0 1.5Zm0 0v-3.675A55.378 55.378 0 0 1 12 8.443m-7.007 11.55A5.981 5.981 0 0 0 6.75 15.75v-1.5" /></svg>
            Attestations
        </a>
    @endcan
    @can('document_template.view')
        <a href="{{ route('admin.maquettes.index') }}"
           class="{{ $classeLien }} {{ request()->routeIs('admin.maquettes.*') ? $classeActif.' admin-nav-lien-actif' : $classeInactif }}"
           @if(request()->routeIs('admin.maquettes.*')) aria-current="page" @endif>
            <svg class="h-5 w-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 17.25v3.375c0 .621-.504 1.125-1.125 1.125h-9.75a1.125 1.125 0 0 1-1.125-1.125V7.875c0-.621.504-1.125 1.125-1.125H6.75a9.06 9.06 0 0 1 1.5.124m7.5 10.376h3.375c.621 0 1.125-.504 1.125-1.125V11.25c0-4.46-3.243-8.161-7.5-8.876a9.06 9.06 0 0 0-1.5-.124H9.375c-.621 0-1.125.504-1.125 1.125v3.5m7.5 10.375H9.375a1.125 1.125 0 0 1-1.125-1.125v-9.25" /></svg>
            Maquettes
        </a>
    @endcan
    @can('data.import')
        <a href="{{ route('admin.mens.index') }}"
           class="{{ $classeLien }} {{ request()->routeIs('admin.mens.*') ? $classeActif.' admin-nav-lien-actif' : $classeInactif }}"
           @if(request()->routeIs('admin.mens.*')) aria-current="page" @endif>
            <svg class="h-5 w-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M3.375 19.5h17.25m-17.25 0a1.125 1.125 0 0 1-1.125-1.125M3.375 19.5h1.5C5.496 19.5 6 18.996 6 18.375m-3.75 0V5.625m0 12.75v-1.5c0-.621.504-1.125 1.125-1.125m18.375 2.625V5.625m0 12.75c0 .621-.504 1.125-1.125 1.125m1.125-2.625v-1.5c0-.621-.504-1.125-1.125-1.125m0 3.75h-1.5A1.125 1.125 0 0 1 18 18.375M20.625 4.5H3.375m17.25 15v1.5m0-1.5c0-.621.504-1.125 1.125-1.125M3.375 4.5h1.5C5.496 4.5 6 5.004 6 5.625m-3.75 0v1.5c0 .621.504 1.125 1.125 1.125m0 0h1.5m-1.5 0c-.621 0-1.125.504-1.125 1.125v1.5m22.5-4.5h-1.5c-.621 0-1.125.504-1.125 1.125m1.125-1.125c.621 0 1.125.504 1.125 1.125v1.5m-22.5 0h1.5m-1.5 0c-.621 0-1.125.504-1.125 1.125v1.5m22.5 0h-1.5m-1.5 0c-.621 0-1.125.504-1.125 1.125v1.5" /></svg>
            Référentiel MENS
        </a>
    @endcan
    <a href="{{ route('admin.recherche') }}"
       class="{{ $classeLien }} {{ request()->routeIs('admin.recherche') ? $classeActif.' admin-nav-lien-actif' : $classeInactif }}">
        <svg class="h-5 w-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z" /></svg>
        Recherche globale
    </a>
    @can('archives.view')
    <a href="{{ route('admin.listes.index') }}"
       class="{{ $classeLien }} {{ request()->routeIs('admin.listes.*') ? $classeActif.' admin-nav-lien-actif' : $classeInactif }}">
        <svg class="h-5 w-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 6.75h12M8.25 12h12m-12 5.25h12M3.75 6.75h.007v.008H3.75V6.75Zm.375 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0ZM3.75 12h.007v.008H3.75V12Zm.375 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm-.375 5.25h.007v.008H3.75v-.008Zm.375 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Z" /></svg>
        Listes et exports
    </a>
    @endcan
    @can('settings.view')
        <a href="{{ route('admin.parametres.index') }}"
           class="{{ $classeLien }} {{ request()->routeIs('admin.parametres.*') ? $classeActif.' admin-nav-lien-actif' : $classeInactif }}"
           @if(request()->routeIs('admin.parametres.*')) aria-current="page" @endif>
            <svg class="h-5 w-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M9.594 3.94c.09-.542.56-.94 1.11-.94h2.593c.55 0 1.02.398 1.11.94l.213 1.281c.063.374.313.686.645.87.074.04.147.083.22.127.325.196.72.257 1.075.124l1.217-.456a1.125 1.125 0 0 1 1.37.49l1.296 2.247a1.125 1.125 0 0 1-.216 1.411l-1.001 1.001a1.125 1.125 0 0 0-.29 1.176c.04.077.078.155.113.234l.212 1.281c.09.542-.56.94-1.11.94h-2.593c-.55 0-1.02-.398-1.11-.94l-.213-1.281c-.063-.374-.313-.686-.645-.87a2.107 2.107 0 0 0-.22-.127c-.325-.196-.72-.257-1.075-.124l-1.217.456a1.125 1.125 0 0 1-1.37-.49l-1.296-2.247a1.125 1.125 0 0 1 .216-1.411l1.001-1.001a1.125 1.125 0 0 0 .29-1.176c-.04-.077-.078-.155-.113-.234l-.212-1.281Z" /><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" /></svg>
            Paramètres
        </a>
    @endcan
    @can('qualite.view')
    <a href="{{ route('admin.qualite.index') }}"
       class="{{ $classeLien }} {{ request()->routeIs('admin.qualite.*') ? $classeActif.' admin-nav-lien-actif' : $classeInactif }}">
        <svg class="h-5 w-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" /></svg>
        Qualité des données
    </a>
    @endcan
    <a href="{{ route('admin.notifications.index') }}"
       class="{{ $classeLien }} {{ request()->routeIs('admin.notifications.*') ? $classeActif.' admin-nav-lien-actif' : $classeInactif }}">
        <svg class="h-5 w-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M14.857 17.082a23.848 23.848 0 0 0 5.454-1.31A8.967 8.967 0 0 1 18 9.75V9A6 6 0 0 0 6 9v.75a8.967 8.967 0 0 1-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 0 1-5.714 0m5.714 0a3 3 0 1 1-5.714 0" /></svg>
        Notifications
    </a>
        </div>
    </div>

    @if(auth()->user()->role === 'admin_faculte' || auth()->user()->estSuperAdmin())
        <p class="px-3 pt-4 text-[13px] font-semibold text-slate-500">Administration</p>
        @if(auth()->user()->role === 'admin_faculte')
            <a href="{{ route('admin.ma-faculte') }}"
               class="{{ $classeLien }} {{ request()->routeIs('admin.ma-faculte') ? $classeActif : $classeInactif }}">
                <svg class="h-5 w-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 21v-8.25M15.75 21v-8.25M8.25 21v-8.25M3 9l9-6 9 6m-1.5 12V10.332A48.36 48.36 0 0 0 12 9.75c-2.551 0-5.056.2-7.5.582V21M3 21h18M12 6.75h.008v.008H12V6.75Z" /></svg>
                Ma Faculté
            </a>
        @endif
        @if(auth()->user()->estSuperAdmin())
            <a href="{{ route('admin.administrateurs.index') }}"
               class="{{ $classeLien }} {{ request()->routeIs('admin.administrateurs.*') ? $classeActif : $classeInactif }}">
                <svg class="h-5 w-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 0 0 2.625.372 9.337 9.337 0 0 0 4.121-.952 4.125 4.125 0 0 0-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 0 1 8.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0 1 11.964-3.07M12 6.375a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0Zm8.25 2.25a2.625 2.625 0 1 1-5.25 0 2.625 2.625 0 0 1 5.25 0Z" /></svg>
                Administrateurs
            </a>
            <a href="{{ route('admin.maintenance.index') }}"
               class="{{ $classeLien }} {{ request()->routeIs('admin.maintenance.*') ? $classeActif : $classeInactif }}">
                <svg class="h-5 w-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M11.42 15.17 17.25 21A2.652 2.652 0 0 0 21 17.25l-5.877-5.877M11.42 15.17l2.496-3.03c.317-.384.74-.626 1.208-.766M11.42 15.17l-4.655 5.653a2.548 2.548 0 1 1-3.586-3.586l6.837-5.63m5.108-.233c.55-.164 1.163-.188 1.743-.14a4.5 4.5 0 0 0 4.486-6.336l-3.276 3.277a3.004 3.004 0 0 1-2.25-2.25l3.276-3.276a4.5 4.5 0 0 0-6.336 4.486c.091 1.076-.071 2.264-.904 2.95l-.102.085" /></svg>
                Maintenance
            </a>
            <a href="{{ route('admin.personnel.index') }}"
               class="{{ $classeLien }} {{ request()->routeIs('admin.personnel.*') ? $classeActif : $classeInactif }}">
                <svg class="h-5 w-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M18 18.72a9.094 9.094 0 0 0 3.741-.479 3 3 0 0 0-4.682-2.72m.94 3.198.001.031c0 .225-.012.447-.037.666A11.944 11.944 0 0 1 12 21c-2.17 0-4.207-.576-5.963-1.584A6.062 6.062 0 0 1 6 18.719m12 0a5.971 5.971 0 0 0-.941-3.197m0 0A5.995 5.995 0 0 0 12 12.75a5.995 5.995 0 0 0-5.058 2.772m0 0a3 3 0 0 0-4.681 2.72 8.986 8.986 0 0 0 3.74.477m.94-3.197a5.971 5.971 0 0 0-.94 3.197M15 6.75a3 3 0 1 1-6 0 3 3 0 0 1 6 0Zm6 3a2.25 2.25 0 1 1-4.5 0 2.25 2.25 0 0 1 4.5 0Zm-13.5 0a2.25 2.25 0 1 1-4.5 0 2.25 2.25 0 0 1 4.5 0Z" /></svg>
                Personnel
            </a>
            <a href="{{ route('admin.roles.index') }}"
               class="{{ $classeLien }} {{ request()->routeIs('admin.roles.*') ? $classeActif : $classeInactif }}">
                <svg class="h-5 w-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75m-3-7.036A48.424 48.424 0 0 1 12 2.25c2.623 0 5.146.184 7.5.519 2.355.335 4.5.87 4.5 1.397v11.718c0 .849-.7 1.54-1.562 1.54H4.562A1.562 1.562 0 0 1 3 15.876V4.158c0-.527 2.145-1.062 4.5-1.397A48.424 48.424 0 0 1 12 2.25Zm0 0V21m7.5-5.25v-9" /></svg>
                Rôles
            </a>
            <a href="{{ route('admin.smtp.index') }}"
               class="{{ $classeLien }} {{ request()->routeIs('admin.smtp.*') ? $classeActif : $classeInactif }}">
                <svg class="h-5 w-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M21.75 6.75v10.5a2.25 2.25 0 0 1-2.25 2.25h-15a2.25 2.25 0 0 1-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25m19.5 0v.243a2.25 2.25 0 0 1-1.07 1.916l-7.5 4.615a2.25 2.25 0 0 1-2.36 0L3.32 8.91a2.25 2.25 0 0 1-1.07-1.916V6.75" /></svg>
                SMTP
            </a>
            @can('mail.logs.view')
            <a href="{{ route('admin.courriels.index') }}"
               class="{{ $classeLien }} {{ request()->routeIs('admin.courriels.*') ? $classeActif : $classeInactif }}">
                <svg class="h-5 w-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75m-3-7.036A11.959 11.959 0 0 1 3.598 6 11.99 11.99 0 0 0 3 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285Z" /></svg>
                Courriels
            </a>
            @endcan
            @can('campagnes.view')
            <a href="{{ route('admin.campagnes-courriel.index') }}"
               class="{{ $classeLien }} {{ request()->routeIs('admin.campagnes-courriel.*') ? $classeActif : $classeInactif }}">
                <svg class="h-5 w-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6 12 3.269 3.125A59.769 59.769 0 0 1 21.485 12 59.768 59.768 0 0 1 3.27 20.875L5.999 12Zm0 0h7.5" /></svg>
                Campagnes e-mail
            </a>
            @endcan
            @can('mail.modeles.view')
            <a href="{{ route('admin.studio.index') }}"
               class="{{ $classeLien }} {{ request()->routeIs('admin.studio.*') ? $classeActif : $classeInactif }}">
                <svg class="h-5 w-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931Zm0 0L19.5 7.125M18 14v4.75A2.25 2.25 0 0 1 15.75 21H5.25A2.25 2.25 0 0 1 3 18.75V8.25A2.25 2.25 0 0 1 5.25 6H10" /></svg>
                Studio e-mail
            </a>
            @endcan
        @endif
    @endif
    @can('history.view')
        <p class="px-3 pt-4 text-[13px] font-semibold text-slate-500">Contrôle</p>
        <a href="{{ route('admin.audit.index') }}"
           class="{{ $classeLien }} {{ request()->routeIs('admin.audit.*') ? $classeActif : $classeInactif }}">
            <svg class="h-5 w-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" /></svg>
            Journal d'audit
        </a>
    @endcan
@elseif(session('etudiant_auth_id'))
    <p class="px-3 pt-1 text-[13px] font-semibold text-slate-500">Accueil</p>
    <a href="{{ route('etudiant.dashboard') }}"
       class="{{ $classeLien }} {{ request()->routeIs('etudiant.dashboard') ? $classeActif : $classeInactif }}"
       @if(request()->routeIs('etudiant.dashboard')) aria-current="page" @endif>
        <svg class="h-5 w-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6A2.25 2.25 0 0 1 6 3.75h2.25A2.25 2.25 0 0 1 10.5 6v2.25a2.25 2.25 0 0 1-2.25 2.25H6a2.25 2.25 0 0 1-2.25-2.25V6ZM3.75 15.75A2.25 2.25 0 0 1 6 13.5h2.25a2.25 2.25 0 0 1 2.25 2.25V18a2.25 2.25 0 0 1-2.25 2.25H6A2.25 2.25 0 0 1 3.75 18v-2.25ZM13.5 6a2.25 2.25 0 0 1 2.25-2.25H18A2.25 2.25 0 0 1 20.25 6v2.25A2.25 2.25 0 0 1 18 10.5h-2.25a2.25 2.25 0 0 1-2.25-2.25V6ZM13.5 15.75a2.25 2.25 0 0 1 2.25-2.25H18a2.25 2.25 0 0 1 2.25 2.25V18A2.25 2.25 0 0 1 18 20.25h-2.25A2.25 2.25 0 0 1 13.5 18v-2.25Z" /></svg>
        Tableau de bord
    </a>

    <p class="px-3 pt-4 text-[13px] font-semibold text-slate-500">Mon espace</p>
    @foreach([
        ['etudiant.archives.show', 'etudiant.archives.*', 'Mon dossier'],
        ['etudiant.inscriptions.index', 'etudiant.inscriptions.*', 'Mes inscriptions'],
        ['etudiant.parcours', 'etudiant.parcours', 'Mon parcours LMD'],
        ['etudiant.notes.index', 'etudiant.notes.*', 'Mes notes'],
        ['etudiant.documents.index', 'etudiant.documents.*', 'Mes documents'],
        ['etudiant.historique.index', 'etudiant.historique.*', 'Mon historique'],
        ['etudiant.notifications.index', 'etudiant.notifications.*', 'Notifications'],
        ['etudiant.profil.show', 'etudiant.profil.*', 'Mon profil'],
    ] as [$route, $motif, $libelle])
        <a href="{{ route($route) }}"
           class="{{ $classeLien }} {{ request()->routeIs($motif) ? $classeActif : $classeInactif }}"
           @if(request()->routeIs($motif)) aria-current="page" @endif>
            <svg class="h-5 w-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" /></svg>
            {{ $libelle }}
        </a>
    @endforeach
    @foreach(app(\App\Support\Modules\ModuleRegistry::class)->elementsNavigation('etudiant') as $item)
        <a href="{{ route($item['route']) }}"
           class="{{ $classeLien }} {{ request()->routeIs($item['route']) ? $classeActif : $classeInactif }}"
           @if(request()->routeIs($item['route'])) aria-current="page" @endif>
            <svg class="h-5 w-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" /></svg>
            {{ $item['label'] }}
        </a>
    @endforeach
@elseif(session('enseignant_auth_id'))
    <p class="px-3 pt-1 text-[13px] font-semibold text-slate-500">Accueil</p>
    <a href="{{ route('enseignant.dashboard') }}"
       class="{{ $classeLien }} {{ request()->routeIs('enseignant.dashboard') ? $classeActif : $classeInactif }}"
       @if(request()->routeIs('enseignant.dashboard')) aria-current="page" @endif>
        <svg class="h-5 w-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6A2.25 2.25 0 0 1 6 3.75h2.25A2.25 2.25 0 0 1 10.5 6v2.25a2.25 2.25 0 0 1-2.25 2.25H6a2.25 2.25 0 0 1-2.25-2.25V6ZM3.75 15.75A2.25 2.25 0 0 1 6 13.5h2.25a2.25 2.25 0 0 1 2.25 2.25V18a2.25 2.25 0 0 1-2.25 2.25H6A2.25 2.25 0 0 1 3.75 18v-2.25ZM13.5 6a2.25 2.25 0 0 1 2.25-2.25H18A2.25 2.25 0 0 1 20.25 6v2.25A2.25 2.25 0 0 1 18 10.5h-2.25a2.25 2.25 0 0 1-2.25-2.25V6ZM13.5 15.75a2.25 2.25 0 0 1 2.25-2.25H18a2.25 2.25 0 0 1 2.25 2.25V18A2.25 2.25 0 0 1 18 20.25h-2.25A2.25 2.25 0 0 1 13.5 18v-2.25Z" /></svg>
        Tableau de bord
    </a>

    <p class="px-3 pt-4 text-[13px] font-semibold text-slate-500">Mon espace</p>
    {{--
        Entrées propres au cœur du portail. Les pages fournies par les
        modules (emploi du temps, présences, surveillances, annonces) sont
        rendues par `ModuleRegistry::elementsNavigation('enseignant')`
        ci-dessous — JAMAIS en double ici (la boucle module filtre aussi
        l'activation : un lien vers un module inactif mène à un 404).
        `Route::has()` : une entrée sans route enregistrée est masquée
        (jamais de lien cassé / 500 de rendu).
    --}}
    @foreach([
        ['enseignant.demandes.index', 'enseignant.demandes.*', 'Mes demandes', 'M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z'],
        ['enseignant.reclamations.index', 'enseignant.reclamations.*', 'Mes réclamations', 'M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008Z'],
        ['enseignant.documents.index', 'enseignant.documents.*', 'Mes documents', 'M2.25 12.75V12A2.25 2.25 0 0 1 4.5 9.75h15A2.25 2.25 0 0 1 21.75 12v.75m-8.69-6.44-2.12-2.12a1.5 1.5 0 0 0-1.061-.44H4.5A2.25 2.25 0 0 0 2.25 6v12a2.25 2.25 0 0 0 2.25 2.25h15A2.25 2.25 0 0 0 21.75 18V9a2.25 2.25 0 0 0-2.25-2.25h-5.379a1.5 1.5 0 0 1-1.06-.44Z'],
        ['enseignant.profil.show', 'enseignant.profil.*', 'Mon profil', 'M15.75 6a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0ZM4.501 20.118a7.5 7.5 0 0 1 14.998 0A17.933 17.933 0 0 1 12 21.75c-2.676 0-5.216-.584-7.499-1.632Z'],
        ['enseignant.notifications.index', 'enseignant.notifications.*', 'Notifications', 'M14.857 17.082a23.848 23.848 0 0 0 5.454-1.31A8.967 8.967 0 0 1 18 9.75v-.7V9A6 6 0 0 0 6 9v.75a8.967 8.967 0 0 1-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 0 1-5.714 0m5.714 0a3 3 0 1 1-5.714 0'],
    ] as [$route, $motif, $libelle, $icone])
        @continue(! Route::has($route))
        <a href="{{ route($route) }}"
           class="{{ $classeLien }} {{ request()->routeIs($motif) ? $classeActif : $classeInactif }}"
           @if(request()->routeIs($motif)) aria-current="page" @endif>
            <svg class="h-5 w-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="{{ $icone }}" /></svg>
            {{ $libelle }}
        </a>
    @endforeach
    @foreach(app(\App\Support\Modules\ModuleRegistry::class)->elementsNavigation('enseignant') as $item)
        <a href="{{ route($item['route']) }}"
           class="{{ $classeLien }} {{ request()->routeIs($item['route']) ? $classeActif : $classeInactif }}"
           @if(request()->routeIs($item['route'])) aria-current="page" @endif>
            <svg class="h-5 w-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" /></svg>
            {{ $item['label'] }}
        </a>
    @endforeach
@else
    <p class="px-3 pt-1 text-[13px] font-semibold text-slate-500">Portail</p>
    @foreach([
        ['inscription.create', 'Accueil'],
        ['inscription.create#inscription', 'Inscription'],
        ['etudiant.connexion.show', 'Suivi de dossier'],
        ['inscription.create#aide', 'Aide'],
        ['etudiant.connexion.show', 'Connexion'],
    ] as [$cible, $libelle])
        <a href="{{ str_contains($cible, '#') ? route(explode('#', $cible)[0]).'#'.explode('#', $cible)[1] : route($cible) }}"
           class="{{ $classeLien }} {{ $classeInactif }}">
            <svg class="h-5 w-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5 21 12m0 0-7.5 7.5M21 12H3" /></svg>
            {{ $libelle }}
        </a>
    @endforeach
    <a href="{{ route('admin.connexion.show') }}" class="{{ $classeLien }} {{ $classeInactif }}">
        <svg class="h-5 w-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 1 0-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 0 0 2.25-2.25v-6.75a2.25 2.25 0 0 0-2.25-2.25H6.75a2.25 2.25 0 0 0-2.25 2.25v6.75a2.25 2.25 0 0 0 2.25 2.25Z" /></svg>
        Espace scolarité
    </a>
@endif
