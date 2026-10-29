@extends('layouts.app')

@section('title', 'Tableau de bord — Scolarité')
@section('meta_description', 'Pilotage des inscriptions : effectifs, statuts, derniers dossiers et activité récente.')

@section('content')
    <div class="w-full px-4 sm:px-6 py-8 sm:py-10">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <p class="text-[11px] font-bold uppercase tracking-wider text-slate-400">Back-office scolarité</p>
                <h1 class="mt-1 text-2xl sm:text-3xl font-black tracking-tight text-slate-900" data-motion-block>Tableau de bord</h1>
            </div>
            <span class="inline-flex items-center rounded-full border border-slate-200 bg-white px-3 py-1.5 text-[11px] font-bold text-slate-600">
                Périmètre : {{ $faculte?->nom ?? 'toutes les facultés' }}
            </span>
        </div>

        {{-- Statistiques (§15) : UNE requête agrégée côté contrôleur ; pastille + libellé --}}
        <div class="mt-6 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-3" aria-label="Statistiques des inscriptions">
            <x-stat-card libelle="Total étudiants" :valeur="$stats['total']" detail="Tous statuts" couleur="slate" />
            <a href="{{ route('admin.inscriptions.file-traitement') }}" class="block transition hover:scale-[1.01]"><x-stat-card libelle="En attente" :valeur="$stats['en_attente']" detail="File de traitement scolarité" couleur="amber" /></a>
            <x-stat-card libelle="Validées" :valeur="$stats['valide']" detail="Dossiers confirmés" couleur="emerald" />
            <x-stat-card libelle="Rejetées" :valeur="$stats['rejete']" detail="Dossiers refusés" couleur="rose" />
            <x-stat-card libelle="Documents" :valeur="$documentsTotal" detail="Pièces archivées" couleur="primary" />
        </div>

        {{-- Centre de résolution : derniers runs de vérification (scopés) --}}
        <div class="mt-3 grid grid-cols-1 sm:grid-cols-2 gap-3" aria-label="Vérification des dossiers">
            <a href="{{ route('admin.verification.index', ['verdict' => 'BLOQUANT']) }}"><x-stat-card libelle="Dossiers bloquants" :valeur="$verifications['bloquants']" detail="À corriger puis re-vérifier" couleur="rose" /></a>
            <a href="{{ route('admin.verification.index') }}"><x-stat-card libelle="À vérifier" :valeur="$verifications['a_verifier']" detail="File de traitement" couleur="amber" /></a>
        </div>

        {{-- Actions rapides (§7) : que des routes réelles --}}
        <div x-data="ordreWidgets">
        <div class="flex items-center justify-end">
            <button type="button" @click="perso = !perso" :aria-expanded="perso.toString()"
                    class="text-[11px] font-bold text-slate-500 underline underline-offset-2 hover:text-slate-800 min-h-[32px]">
                <span x-show="!perso">⚙ Personnaliser l'ordre</span>
                <span x-show="perso">✓ Terminer</span>
            </button>
        </div>
        <section aria-label="Actions rapides" data-widget="actions" class="mt-2">
            <div class="flex items-center justify-between gap-2">
                <h2 class="text-sm font-black uppercase tracking-wider text-slate-500">Actions rapides</h2>
                <span x-show="perso" class="flex gap-1">
                    <button type="button" @click="deplacer('actions', -1)" aria-label="Monter Actions rapides" class="rounded-lg border px-2 py-1 text-xs font-bold min-h-[32px] min-w-[32px]">↑</button>
                    <button type="button" @click="deplacer('actions', 1)" aria-label="Descendre Actions rapides" class="rounded-lg border px-2 py-1 text-xs font-bold min-h-[32px] min-w-[32px]">↓</button>
                </span>
            </div>
            <div class="mt-2 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
                <a href="{{ route('admin.etudiants.index') }}"
                   class="ds-card flex items-center gap-3 p-4 hover:border-primary-300 hover:shadow-md transition">
                    <span class="flex h-10 w-10 flex-shrink-0 items-center justify-center rounded-xl bg-primary-800 text-sm font-black text-white" aria-hidden="true">👥</span>
                    <span><span class="block text-sm font-black text-slate-900">Registre étudiants</span>
                    <span class="block text-[11px] text-slate-500">Rechercher, filtrer, consulter</span></span>
                </a>
                <a href="{{ route('admin.archives.index') }}"
                   class="ds-card flex items-center gap-3 p-4 hover:border-primary-300 hover:shadow-md transition">
                    <span class="flex h-10 w-10 flex-shrink-0 items-center justify-center rounded-xl bg-primary-800 text-sm font-black text-white" aria-hidden="true">🗂</span>
                    <span><span class="block text-sm font-black text-slate-900">Archives</span>
                    <span class="block text-[11px] text-slate-500">Dossiers, notes, documents</span></span>
                </a>
                @can('data.import')
                    <a href="{{ route('admin.donnees.hub') }}"
                       class="ds-card flex items-center gap-3 p-4 hover:border-primary-300 hover:shadow-md transition">
                        <span class="flex h-10 w-10 flex-shrink-0 items-center justify-center rounded-xl bg-primary-800 text-sm font-black text-white" aria-hidden="true">🔄</span>
                        <span><span class="block text-sm font-black text-slate-900">Importer des données</span>
                        <span class="block text-[11px] text-slate-500">Référentiels enseignants, salles…</span></span>
                    </a>
                @endcan
                @if(app(\App\Support\Modules\ModuleRegistry::class)->estActif('schedule'))
                    <a href="{{ route('admin.emplois-du-temps.import.form') }}"
                       class="ds-card flex items-center gap-3 p-4 hover:border-primary-300 hover:shadow-md transition">
                        <span class="flex h-10 w-10 flex-shrink-0 items-center justify-center rounded-xl bg-primary-800 text-sm font-black text-white" aria-hidden="true">📥</span>
                        <span><span class="block text-sm font-black text-slate-900">Importer un planning</span>
                        <span class="block text-[11px] text-slate-500">CSV, JSON, XML ou XLSX</span></span>
                    </a>
                @endif
                @if(app(\App\Support\Modules\ModuleRegistry::class)->estActif('online-requests'))
                    <a href="{{ route('admin.demandes.index') }}"
                       class="ds-card flex items-center gap-3 p-4 hover:border-primary-300 hover:shadow-md transition">
                        <span class="flex h-10 w-10 flex-shrink-0 items-center justify-center rounded-xl bg-primary-800 text-sm font-black text-white" aria-hidden="true">📝</span>
                        <span><span class="block text-sm font-black text-slate-900">Demandes</span>
                        <span class="block text-[11px] text-slate-500">Traiter les dossiers en attente</span></span>
                    </a>
                @endif
            </div>
        </section>

        {{-- À traiter : orienté action (inscriptions, demandes, imports) --}}
        @if(($stats['en_attente'] ?? 0) > 0 || ($demandesAttente ?? 0) > 0)
            <section aria-label="À traiter" data-widget="atraiter" class="mt-6 rounded-2xl border border-amber-300 bg-amber-50 p-4 sm:p-5">
                <div class="flex items-center justify-between gap-2">
                    <h2 class="text-sm font-black uppercase tracking-wider text-amber-900">À traiter</h2>
                    <span x-show="perso" class="flex gap-1">
                        <button type="button" @click="deplacer('atraiter', -1)" aria-label="Monter À traiter" class="rounded-lg border border-amber-300 px-2 py-1 text-xs font-bold min-h-[32px] min-w-[32px]">↑</button>
                        <button type="button" @click="deplacer('atraiter', 1)" aria-label="Descendre À traiter" class="rounded-lg border border-amber-300 px-2 py-1 text-xs font-bold min-h-[32px] min-w-[32px]">↓</button>
                    </span>
                </div>
                <ul class="mt-2 space-y-1.5 text-sm font-semibold text-amber-900">
                    @if(($stats['en_attente'] ?? 0) > 0)
                        <li>⏳ <a href="{{ route('admin.etudiants.index', ['statut' => 'en_attente']) }}" class="underline underline-offset-2">{{ $stats['en_attente'] }} inscription(s) en attente de validation</a></li>
                    @endif
                    @if(($demandesAttente ?? 0) > 0 && app(\App\Support\Modules\ModuleRegistry::class)->estActif('online-requests'))
                        <li>📝 <a href="{{ route('admin.demandes.index') }}" class="underline underline-offset-2">{{ $demandesAttente }} demande(s) en attente</a></li>
                    @endif
                </ul>
            </section>
        @endif

        <div class="mt-6 grid grid-cols-1 lg:grid-cols-3 gap-6" data-widget="principal">
            <div x-show="perso" class="lg:col-span-3 flex justify-end gap-1 -mb-3">
                <button type="button" @click="deplacer('principal', -1)" aria-label="Monter le bloc inscriptions et activité" class="rounded-lg border border-slate-200 bg-white px-2 py-1 text-xs font-bold min-h-[32px] min-w-[32px]">↑</button>
                <button type="button" @click="deplacer('principal', 1)" aria-label="Descendre le bloc inscriptions et activité" class="rounded-lg border border-slate-200 bg-white px-2 py-1 text-xs font-bold min-h-[32px] min-w-[32px]">↓</button>
            </div>
            {{-- Derniers inscrits --}}
            <section aria-labelledby="titre-derniers" class="lg:col-span-2 rounded-2xl border border-slate-200 bg-white shadow-sm overflow-hidden">
                <div class="flex items-center justify-between px-5 py-4 border-b border-slate-100">
                    <h2 id="titre-derniers" class="text-base font-black text-slate-900">Dernières inscriptions</h2>
                    <a href="{{ route('admin.etudiants.index') }}" class="text-xs font-bold text-primary-800 underline underline-offset-2 hover:text-primary-600">Registre complet →</a>
                </div>
                @if($derniers->isNotEmpty())
                    <div class="overflow-x-auto">
                        <table class="w-full min-w-[560px] text-left text-xs sm:text-sm">
                            <thead>
                                <tr class="border-b border-slate-100 text-[11px] uppercase tracking-wider text-slate-700 font-bold">
                                    <th scope="col" class="px-5 py-3 font-bold">CIN</th>
                                    <th scope="col" class="px-5 py-3 font-bold">Nom</th>
                                    <th scope="col" class="px-5 py-3 font-bold">Faculté</th>
                                    <th scope="col" class="px-5 py-3 font-bold">Statut</th>
                                    <th scope="col" class="px-5 py-3 font-bold">Date</th>
                                    <th scope="col" class="px-5 py-3 font-bold"><span class="sr-only">Actions</span></th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                @foreach($derniers as $etudiant)
                                    <tr class="hover:bg-slate-50/60">
                                        <td class="px-5 py-3 font-mono font-bold text-slate-800">{{ $etudiant->cin }}</td>
                                        <td class="px-5 py-3 font-bold text-slate-900">{{ $etudiant->nom_complet }}</td>
                                        <td class="px-5 py-3 text-slate-600">{{ $etudiant->faculte?->code ?? '—' }}</td>
                                        <td class="px-5 py-3">
                                            <x-status-badge :statut="$etudiant->statut" />
                                        </td>
                                        <td class="px-5 py-3 text-slate-500 tabular-nums">{{ $etudiant->created_at->format('d/m/Y') }}</td>
                                        <td class="px-5 py-3 text-right">
                                            <a href="{{ route('admin.etudiants.show', $etudiant) }}" class="font-bold text-primary-800 underline underline-offset-2 hover:text-primary-600">Voir</a>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @else
                    <p class="px-5 py-8 text-center text-sm text-slate-500">Aucune inscription dans ce périmètre pour le moment.</p>
                @endif
            </section>

            {{-- Activité récente : timeline --}}
            <section aria-labelledby="titre-activite" class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <h2 id="titre-activite" class="text-base font-black text-slate-900">Activité récente</h2>
                @if($activite->isNotEmpty())
                    <ol class="mt-3 space-y-0">
                        @foreach($activite as $entree)
                            <li class="relative flex gap-3 pb-4 last:pb-0">
                                <span class="flex flex-col items-center" aria-hidden="true">
                                    <span class="h-2.5 w-2.5 rounded-full bg-primary-700 ring-4 ring-primary-50"></span>
                                    @if(!$loop->last)<span class="w-px flex-1 bg-slate-200"></span>@endif
                                </span>
                                <div class="min-w-0 flex-1 text-xs">
                                    <p class="font-bold text-slate-800">{{ $entree->action }}</p>
                                    <p class="mt-0.5 text-slate-500">
                                        {{ $entree->etudiant?->nom_complet ?? '—' }}
                                        @if($entree->utilisateur) · par {{ $entree->utilisateur->name }} @endif
                                    </p>
                                    <p class="tabular-nums text-[11px] text-slate-400">{{ $entree->created_at->format('d/m/Y H:i') }}</p>
                                </div>
                            </li>
                        @endforeach
                    </ol>
                @else
                    <div class="mt-3">
                        <x-empty-state message="Aucune activité journalisée pour le moment." />
                    </div>
                @endif
            </section>
        </div>

        {{-- Widgets des modules actifs (§15) --}}
        @php $widgetsAdmin = app(\App\Support\Modules\ModuleRegistry::class)->widgets('admin'); @endphp
        @if(count($widgetsAdmin) > 0)
            <div class="mt-6 grid grid-cols-1 lg:grid-cols-3 gap-6">
                @foreach($widgetsAdmin as $widget)
                    @include($widget)
                @endforeach
            </div>
        @endif

        {{-- Pilotage transverse : référentiels, demandes, imports, notifications --}}
        <div class="mt-6 grid grid-cols-1 lg:grid-cols-3 gap-6" data-widget="pilotage">
            <div x-show="perso" class="lg:col-span-3 flex justify-end gap-1">
                <button type="button" @click="deplacer('pilotage', -1)" aria-label="Monter le bloc pilotage" class="rounded-lg border border-slate-200 bg-white px-2 py-1 text-xs font-bold min-h-[32px] min-w-[32px]">↑</button>
                <button type="button" @click="deplacer('pilotage', 1)" aria-label="Descendre le bloc pilotage" class="rounded-lg border border-slate-200 bg-white px-2 py-1 text-xs font-bold min-h-[32px] min-w-[32px]">↓</button>
            </div>
            <section aria-label="Référentiels" class="ds-card p-5">
                <h2 class="text-base font-black text-slate-900">Référentiels</h2>
                <ul class="mt-3 space-y-2 text-sm">
                    <li class="flex items-center justify-between gap-2">
                        <span class="text-slate-600">Enseignants</span>
                        <span class="flex items-center gap-2">
                            <strong class="tabular-nums text-slate-900">{{ $referentiels['enseignants'] }}</strong>
                            <a href="{{ route('admin.enseignants.index') }}" class="text-[11px] font-bold text-primary-800 underline underline-offset-2">Gérer</a>
                        </span>
                    </li>
                    <li class="flex items-center justify-between gap-2">
                        <span class="text-slate-600">Groupes</span>
                        <span class="flex items-center gap-2">
                            <strong class="tabular-nums text-slate-900">{{ $referentiels['groupes'] }}</strong>
                            <a href="{{ route('admin.groupes.index') }}" class="text-[11px] font-bold text-primary-800 underline underline-offset-2">Gérer</a>
                        </span>
                    </li>
                    <li class="flex items-center justify-between gap-2">
                        <span class="text-slate-600">Salles</span>
                        <span class="flex items-center gap-2">
                            <strong class="tabular-nums text-slate-900">{{ $referentiels['salles'] }}</strong>
                            <a href="{{ route('admin.salles.index') }}" class="text-[11px] font-bold text-primary-800 underline underline-offset-2">Gérer</a>
                        </span>
                    </li>
                    <li class="flex items-center justify-between gap-2">
                        <span class="text-slate-600">Demandes en attente</span>
                        <strong class="tabular-nums text-slate-900">{{ $demandesAttente }}</strong>
                    </li>
                    <li class="flex items-center justify-between gap-2">
                        <span class="text-slate-600">Notifications non lues</span>
                        <span class="flex items-center gap-2">
                            <strong class="tabular-nums text-slate-900">{{ $notificationsNT }}</strong>
                            <a href="{{ route('admin.notifications.index') }}" class="text-[11px] font-bold text-primary-800 underline underline-offset-2">Voir</a>
                        </span>
                    </li>
                </ul>
                @if($estSuperAdmin)
                    <a href="{{ route('admin.maintenance.index') }}" class="mt-3 inline-flex items-center gap-1.5 text-xs font-bold text-primary-800 underline underline-offset-2 hover:text-primary-600">Maintenance système →</a>
                @endif
            </section>

            <section aria-label="Imports récents" class="lg:col-span-2 ds-card p-5">
                <div class="flex items-center justify-between gap-2">
                    <h2 class="text-base font-black text-slate-900">Imports récents</h2>
                    <a href="{{ route('admin.emplois-du-temps.index') }}" class="text-xs font-bold text-primary-800 underline underline-offset-2 hover:text-primary-600">Emplois du temps →</a>
                </div>
                @if($importsRecents->isNotEmpty())
                    <ul class="mt-3 space-y-2">
                        @foreach($importsRecents as $import)
                            <li class="flex items-center justify-between gap-3 rounded-xl border border-slate-100 bg-slate-50/60 px-3.5 py-2.5 text-xs">
                                <span class="min-w-0">
                                    <span class="block truncate font-bold text-slate-800">{{ $import->filename }} <span class="font-mono text-[10px] text-slate-400">({{ $import->format }})</span></span>
                                    <span class="block text-slate-500 tabular-nums">{{ $import->successful_rows }} lignes · {{ $import->created_at->format('d/m/Y H:i') }}</span>
                                </span>
                                <span class="flex-shrink-0 rounded-full border px-2 py-0.5 text-[10px] font-bold {{ $import->status === 'completed' ? 'border-emerald-300 bg-emerald-50 text-emerald-800' : 'border-rose-300 bg-rose-50 text-rose-800' }}">{{ $import->status }}</span>
                            </li>
                        @endforeach
                    </ul>
                @else
                    <div class="mt-3"><x-empty-state message="Aucun import enregistré." /></div>
                @endif
            </section>
        </div>
        </div>
    </div>
@endsection
