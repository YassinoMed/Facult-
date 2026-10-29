@extends('layouts.app')

@section('title', 'Examens — Historique & Audit')

@section('content')
<div class="space-y-6">
    <x-page-header
        titre="Historique et audit des examens"
        description="Traçabilité exhaustive et sécurisée des générations, exports, publications et convocations (sans PII)."
        :fil="['Évaluation' => route('admin.evaluations.index'), 'Examens' => route('admin.evaluation.examens.tableau-de-bord'), 'Historique' => '']"
    />

    @include('modules.evaluation.examens-advanced._nav-tabs')

    {{-- Bannière Réglementaire §55 & RGPD --}}
    <div class="relative overflow-hidden rounded-2xl border border-indigo-200/80 bg-gradient-to-r from-indigo-50/80 via-white to-slate-50/60 p-5 shadow-xs">
        <div class="flex flex-col md:flex-row items-start md:items-center justify-between gap-4">
            <div class="flex items-start gap-3.5">
                <span class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-indigo-700 text-white shadow-xs">
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75m-3-7.036A11.959 11.959 0 0 1 3.598 6 11.99 11.99 0 0 0 3 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285Z" />
                    </svg>
                </span>
                <div>
                    <h3 class="text-sm font-bold text-slate-900">Règle §55 & RGPD — Traçabilité exhaustive sans fuite de données personnelles</h3>
                    <p class="mt-1 text-xs text-slate-600 leading-relaxed max-w-4xl">
                        Toutes les actions sensibles (publications, exports, convocations, émargements) sont journalisées de façon immuable. Seules les empreintes cryptographiques SHA-256 et les métadonnées techniques contextuelles sont archivées : aucun CIN, identifiant personnel ou payload sensible n'est consigné en clair.
                    </p>
                </div>
            </div>
            <div class="flex shrink-0 items-center gap-2">
                <span class="inline-flex items-center gap-1.5 rounded-full bg-indigo-100/80 px-3 py-1 text-xs font-semibold text-indigo-900">
                    <svg class="h-3.5 w-3.5 text-indigo-700" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 1 0-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 0 0 2.25-2.25v-6.75a2.25 2.25 0 0 0-2.25-2.25H6.75a2.25 2.25 0 0 0-2.25 2.25v6.75a2.25 2.25 0 0 0 2.25 2.25Z" /></svg>
                    Immuable (§55)
                </span>
            </div>
        </div>
    </div>

    {{-- Cartes KPI Audit & Observabilité --}}
    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        {{-- KPI 1 : Total traces d'intégration --}}
        <div class="examens-card rounded-2xl border border-slate-200/80 bg-gradient-to-br from-slate-50/70 via-white to-white p-5 shadow-xs transition hover:shadow-sm">
            <div class="flex items-center justify-between">
                <span class="text-[11px] font-bold uppercase tracking-wider text-slate-600">Traces d'intégration</span>
                <span class="inline-flex h-8 w-8 items-center justify-center rounded-xl bg-slate-100 text-slate-700 ring-4 ring-slate-50">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 12h16.5m-16.5 3.75h16.5M3.75 19.5h16.5M5.625 4.5h12.75a1.875 1.875 0 0 1 0 3.75H5.625a1.875 1.875 0 0 1 0-3.75Z" />
                    </svg>
                </span>
            </div>
            <div class="mt-3 flex items-baseline gap-2">
                <span class="text-3xl font-black tracking-tight text-slate-900">{{ $statsHistorique['total_traces'] ?? $traces->total() }}</span>
                <span class="text-xs font-semibold text-slate-500">événements</span>
            </div>
            <p class="mt-1 text-xs text-slate-500">Exports, API et documents certifiés</p>
        </div>

        {{-- KPI 2 : Documents officiels générés --}}
        <div class="examens-card rounded-2xl border border-emerald-200/80 bg-gradient-to-br from-emerald-50/70 via-white to-white p-5 shadow-xs transition hover:shadow-sm">
            <div class="flex items-center justify-between">
                <span class="text-[11px] font-bold uppercase tracking-wider text-emerald-800">Documents générés</span>
                <span class="inline-flex h-8 w-8 items-center justify-center rounded-xl bg-emerald-100 text-emerald-800 ring-4 ring-emerald-50">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m2.25 0H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z" />
                    </svg>
                </span>
            </div>
            <div class="mt-3 flex items-baseline gap-2">
                <span class="text-3xl font-black tracking-tight text-emerald-950">{{ $statsHistorique['documents_generes'] ?? 0 }}</span>
                <span class="text-xs font-semibold text-emerald-700">fichiers scellés</span>
            </div>
            <p class="mt-1 text-xs text-emerald-700">Émargements et convocations officielles</p>
        </div>

        {{-- KPI 3 : Taux de succès technique --}}
        <div class="examens-card rounded-2xl border border-blue-200/80 bg-gradient-to-br from-blue-50/70 via-white to-white p-5 shadow-xs transition hover:shadow-sm">
            <div class="flex items-center justify-between">
                <span class="text-[11px] font-bold uppercase tracking-wider text-blue-800">Taux de succès</span>
                <span class="inline-flex h-8 w-8 items-center justify-center rounded-xl bg-blue-100 text-blue-800 ring-4 ring-blue-50">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                    </svg>
                </span>
            </div>
            <div class="mt-3 flex items-baseline gap-2">
                <span class="text-3xl font-black tracking-tight text-slate-900">{{ $statsHistorique['taux_succes'] ?? 100 }}%</span>
                <span class="text-xs font-semibold text-blue-700">fiabilité</span>
            </div>
            <p class="mt-1 text-xs text-slate-500">{{ $statsHistorique['operations_echouees'] ?? 0 }} anomalie(s) répertoriée(s)</p>
        </div>

        {{-- KPI 4 : Performance & Latence moyenne --}}
        <div class="examens-card rounded-2xl border border-amber-200/80 bg-gradient-to-br from-amber-50/70 via-white to-white p-5 shadow-xs transition hover:shadow-sm">
            <div class="flex items-center justify-between">
                <span class="text-[11px] font-bold uppercase tracking-wider text-amber-800">Durée moyenne</span>
                <span class="inline-flex h-8 w-8 items-center justify-center rounded-xl bg-amber-100 text-amber-800 ring-4 ring-amber-50">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                    </svg>
                </span>
            </div>
            <div class="mt-3 flex items-baseline gap-2">
                <span class="text-3xl font-black tracking-tight text-slate-900">{{ $statsHistorique['duree_moyenne_ms'] ?? 0 }}</span>
                <span class="text-xs font-semibold text-amber-800">ms / trace</span>
            </div>
            <p class="mt-1 text-xs text-slate-500">Temps de réponse d'intégration</p>
        </div>
    </div>

    {{-- Filtres serveur (Statut / Direction / Date / Recherche) --}}
    <div class="examens-card rounded-2xl border border-slate-200/80 bg-white p-4 shadow-xs">
        <form method="GET" action="{{ route('admin.evaluation.examens.historique') }}" class="flex flex-wrap items-end gap-3">
            {{-- Filtre Statut --}}
            <div class="w-36">
                <label for="filtre-statut" class="block text-xs font-semibold text-slate-600">Statut</label>
                <select id="filtre-statut" name="statut" onchange="this.form.submit()"
                        class="mt-1 w-full rounded-xl border border-slate-200 bg-white px-3 py-1.5 text-xs text-slate-800 focus:border-primary-500 focus:outline-none focus:ring-1 focus:ring-primary-500">
                    <option value="">Tous statuts</option>
                    <option value="completed" @selected(($statutFiltre ?? '') === 'completed')>Complété</option>
                    <option value="failed" @selected(($statutFiltre ?? '') === 'failed')>Échoué</option>
                    <option value="pending" @selected(($statutFiltre ?? '') === 'pending')>En attente</option>
                </select>
            </div>

            {{-- Filtre Direction / Canal --}}
            <div class="w-40">
                <label for="filtre-direction" class="block text-xs font-semibold text-slate-600">Canal / Direction</label>
                <select id="filtre-direction" name="direction" onchange="this.form.submit()"
                        class="mt-1 w-full rounded-xl border border-slate-200 bg-white px-3 py-1.5 text-xs text-slate-800 focus:border-primary-500 focus:outline-none focus:ring-1 focus:ring-primary-500">
                    <option value="">Toutes directions</option>
                    <option value="pdf_generation" @selected(($directionFiltre ?? '') === 'pdf_generation')>Génération PDF</option>
                    <option value="export" @selected(($directionFiltre ?? '') === 'export')>Export</option>
                    <option value="inbound" @selected(($directionFiltre ?? '') === 'inbound')>Inbound</option>
                    <option value="outbound" @selected(($directionFiltre ?? '') === 'outbound')>Outbound</option>
                </select>
            </div>

            {{-- Période début / fin --}}
            <div class="w-36">
                <label for="filtre-date-debut" class="block text-xs font-semibold text-slate-600">Date début</label>
                <input id="filtre-date-debut" type="date" name="date_debut" value="{{ $dateDebut ?? '' }}" onchange="this.form.submit()"
                       class="mt-1 w-full rounded-xl border border-slate-200 bg-white px-3 py-1.5 text-xs text-slate-800 focus:border-primary-500 focus:outline-none focus:ring-1 focus:ring-primary-500" />
            </div>
            <div class="w-36">
                <label for="filtre-date-fin" class="block text-xs font-semibold text-slate-600">Date fin</label>
                <input id="filtre-date-fin" type="date" name="date_fin" value="{{ $dateFin ?? '' }}" onchange="this.form.submit()"
                       class="mt-1 w-full rounded-xl border border-slate-200 bg-white px-3 py-1.5 text-xs text-slate-800 focus:border-primary-500 focus:outline-none focus:ring-1 focus:ring-primary-500" />
            </div>

            {{-- Recherche --}}
            <div class="min-w-[12rem] grow">
                <label for="filtre-recherche" class="block text-xs font-semibold text-slate-600">Recherche (opération, référence…)</label>
                <input id="filtre-recherche" type="search" name="recherche" value="{{ $recherche ?? '' }}"
                       placeholder="Ex : export, convocation, #EPR…"
                       class="mt-1 w-full rounded-xl border border-slate-200 bg-white px-3 py-1.5 text-xs text-slate-800 focus:border-primary-500 focus:outline-none focus:ring-1 focus:ring-primary-500" />
            </div>

            <button type="submit" class="rounded-xl bg-primary-800 px-4 py-2 text-xs font-semibold text-white shadow-xs transition hover:bg-primary-900 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-500/50">
                Filtrer
            </button>

            @if(!empty($statutFiltre) || !empty($directionFiltre) || !empty($recherche) || !empty($dateDebut) || !empty($dateFin))
                <a href="{{ route('admin.evaluation.examens.historique') }}" class="text-xs font-semibold text-slate-500 underline-offset-2 hover:text-slate-700 hover:underline">
                    Réinitialiser
                </a>
            @endif
        </form>
    </div>

    {{-- Journal d'audit — colonnes réelles de `integration_logs` (§55) --}}
    <div class="examens-card rounded-2xl border border-slate-200/80 bg-white shadow-xs overflow-hidden">
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 px-6 py-4 bg-slate-50/50">
            <div>
                <h3 class="font-bold text-base text-slate-900">Traces d'intégration et journal d'événements</h3>
                <p class="text-xs text-slate-500 mt-0.5">Exports, PDF générés (émargement) et opérations d'évaluation — empreintes uniquement, jamais de contenu nominatif.</p>
            </div>
            <span class="inline-flex items-center gap-1.5 rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-700">
                <span class="font-bold tabular-nums">{{ $traces->total() }}</span> trace(s)
            </span>
        </div>

        <div class="overflow-x-auto px-6 py-2">
            <table class="examens-tableau w-full text-left text-sm">
                <thead>
                    <tr class="border-b border-slate-100 text-[11px] font-bold uppercase tracking-wider text-slate-500">
                        <th scope="col" class="py-3 pr-4">Horodatage</th>
                        <th scope="col" class="py-3 pr-4">Opération</th>
                        <th scope="col" class="py-3 pr-4">Canal</th>
                        <th scope="col" class="py-3 pr-4">Statut</th>
                        <th scope="col" class="py-3 pr-4">HTTP</th>
                        <th scope="col" class="py-3 pr-4">Référence externe & Hash</th>
                        <th scope="col" class="py-3 text-right">Durée</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse($traces as $trace)
                        <tr class="hover:bg-slate-50/60 transition">
                            <td class="py-3.5 pr-4 text-xs tabular-nums text-slate-600">
                                <span class="font-semibold text-slate-800 block">{{ $trace->created_at?->format('d/m/Y') }}</span>
                                <span class="font-mono text-[11px] text-slate-400">{{ $trace->created_at?->format('H:i:s') }}</span>
                            </td>
                            <td class="py-3.5 pr-4 font-mono text-xs font-medium text-slate-800">
                                <span class="block text-slate-900 font-bold">{{ $trace->operation }}</span>
                                @if($trace->request_id)
                                    <span class="block text-[10px] text-slate-400">REQ: {{ substr($trace->request_id, 0, 12) }}…</span>
                                @endif
                            </td>
                            <td class="py-3.5 pr-4">
                                <span class="inline-flex items-center gap-1 rounded-md bg-slate-100 px-2 py-0.5 text-[11px] font-semibold text-slate-700 font-mono">
                                    {{ $trace->integration }}
                                </span>
                            </td>
                            <td class="py-3.5 pr-4">
                                @if($trace->status === 'completed')
                                    <span class="inline-flex items-center gap-1.5 rounded-full bg-emerald-100 px-2.5 py-0.5 text-xs font-semibold text-emerald-800">
                                        <span class="h-1.5 w-1.5 rounded-full bg-emerald-600"></span>
                                        completed
                                    </span>
                                @elseif($trace->status === 'failed')
                                    <span class="inline-flex items-center gap-1.5 rounded-full bg-rose-100 px-2.5 py-0.5 text-xs font-semibold text-rose-800">
                                        <span class="h-1.5 w-1.5 rounded-full bg-rose-600"></span>
                                        failed
                                    </span>
                                @elseif(in_array($trace->status, ['pending', 'processing']))
                                    <span class="inline-flex items-center gap-1.5 rounded-full bg-amber-100 px-2.5 py-0.5 text-xs font-semibold text-amber-800">
                                        <span class="h-1.5 w-1.5 rounded-full bg-amber-600"></span>
                                        {{ $trace->status }}
                                    </span>
                                @else
                                    <span class="inline-flex rounded-full bg-slate-100 px-2.5 py-0.5 text-xs font-semibold text-slate-700">
                                        {{ $trace->status }}
                                    </span>
                                @endif
                            </td>
                            <td class="py-3.5 pr-4 font-mono text-xs tabular-nums">
                                @if($trace->http_status)
                                    <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[11px] font-semibold {{ $trace->http_status < 400 ? 'bg-emerald-50 text-emerald-700 border border-emerald-200' : 'bg-rose-50 text-rose-700 border border-rose-200' }}">
                                        {{ $trace->http_status }}
                                    </span>
                                @else
                                    <span class="text-slate-400">—</span>
                                @endif
                            </td>
                            <td class="py-3.5 pr-4 font-mono text-xs text-slate-600">
                                <span class="block text-slate-800">{{ $trace->external_reference ?? '—' }}</span>
                                @if($trace->payload_hash)
                                    <span class="block text-[10px] text-slate-400" title="Empreinte SHA-256 du payload (Zéro PII)">
                                        SHA: {{ substr($trace->payload_hash, 0, 10) }}…
                                    </span>
                                @endif
                            </td>
                            <td class="py-3.5 text-xs tabular-nums text-slate-600 text-right">
                                @if($trace->duration_ms !== null)
                                    <span class="font-semibold text-slate-800">{{ $trace->duration_ms }} ms</span>
                                @else
                                    <span class="text-slate-400">—</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="py-12 text-center text-sm text-slate-500">
                                Aucune trace d'audit trouvée pour ce périmètre.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="border-t border-slate-100 px-6 py-4 bg-slate-50/30">
            {{ $traces->withQueryString()->links() }}
        </div>
    </div>

    {{-- Section secondaire : Derniers documents certifiés générés --}}
    @if(isset($derniersDocuments) && $derniersDocuments->isNotEmpty())
        <div class="examens-card rounded-2xl border border-slate-200/80 bg-white shadow-xs p-6">
            <div class="flex items-center justify-between pb-4 border-b border-slate-100 mb-4">
                <div>
                    <h3 class="font-bold text-sm text-slate-900">Derniers documents certifiés scellés</h3>
                    <p class="text-xs text-slate-500 mt-0.5">Historique des bordereaux d'émargement et convocations générées par les gestionnaires académiques.</p>
                </div>
                <span class="inline-flex items-center gap-1 rounded-full bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-700">
                    {{ $derniersDocuments->count() }} document(s) récent(s)
                </span>
            </div>

            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                @foreach($derniersDocuments as $doc)
                    <div class="rounded-xl border border-slate-100 bg-slate-50/50 p-3.5 space-y-1.5 transition hover:border-slate-200">
                        <div class="flex items-center justify-between">
                            <span class="inline-flex rounded px-1.5 py-0.5 text-[10px] font-bold uppercase tracking-wider {{ $doc->type === 'emargement' ? 'bg-primary-100 text-primary-800' : 'bg-emerald-100 text-emerald-800' }}">
                                {{ $doc->type }}
                            </span>
                            <span class="text-[10px] tabular-nums text-slate-400">{{ $doc->created_at?->format('d/m/Y H:i') }}</span>
                        </div>
                        <p class="font-semibold text-xs text-slate-800 truncate" title="{{ $doc->nom_fichier }}">{{ $doc->nom_fichier }}</p>
                        <div class="flex items-center justify-between text-[11px] text-slate-500 pt-1 border-t border-slate-100">
                            <span>{{ $doc->candidats_count }} candidat{{ $doc->candidats_count > 1 ? 's' : '' }}</span>
                            <span class="font-mono text-[10px]">#{{ $doc->salle?->nom ?? 'Salle #'.$doc->salle_id }}</span>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    {{-- Panneau Enterprise — Gouvernance des Journaux d'Audit & Sécurité --}}
    <div class="rounded-2xl border border-slate-200/80 bg-white p-6 shadow-xs">
        <h3 class="font-bold text-sm text-slate-900 mb-2">Gouvernance des Journaux d'Audit & Sécurité Académique</h3>
        <p class="text-xs text-slate-600 mb-4 leading-relaxed">
            UniRegistre garantit une conformité légale et réglementaire totale pour toutes les opérations d'évaluation :
        </p>
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <div class="rounded-xl border border-slate-100 bg-slate-50/50 p-4">
                <span class="text-xs font-bold text-slate-900 block">1. Zéro PII en Clair (§55)</span>
                <p class="text-[11px] text-slate-500 mt-1">Conformité RGPD absolue : aucune identité nominative ni donnée personnelle n'est stockée dans les tables d'audit.</p>
            </div>
            <div class="rounded-xl border border-slate-100 bg-slate-50/50 p-4">
                <span class="text-xs font-bold text-slate-900 block">2. Empreintes Cryptographiques SHA-256</span>
                <p class="text-[11px] text-slate-500 mt-1">Chaque échange ou export génère une signature SHA-256 vérifiable pour prouver l'intégrité sans compromettre le secret.</p>
            </div>
            <div class="rounded-xl border border-slate-100 bg-slate-50/50 p-4">
                <span class="text-xs font-bold text-slate-900 block">3. Immuabilité des Écritures</span>
                <p class="text-[11px] text-slate-500 mt-1">Tables sans colonne d'édition (<code class="font-mono text-[10px]">UPDATED_AT = null</code>) interdisant toute modification ou suppression ultérieure.</p>
            </div>
            <div class="rounded-xl border border-slate-100 bg-slate-50/50 p-4">
                <span class="text-xs font-bold text-slate-900 block">4. Audit 4-Yeux des Transitions</span>
                <p class="text-[11px] text-slate-500 mt-1">Identification et traçabilité de chaque opérateur pour les scellements d'examen et dérogations de surveillance.</p>
            </div>
        </div>
    </div>

    {{-- Zone dédiée aux documents professionnels (LOT 2) --}}
    @include('modules.evaluation.examens-advanced._zone-documents-professionnels', ['optionCourante' => 'historique'])
</div>
@endsection
