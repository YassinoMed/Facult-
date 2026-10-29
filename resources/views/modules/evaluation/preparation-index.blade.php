@extends('layouts.app')

@section('title', 'Préparation des examens')

@section('content')
    <div class="mx-auto max-w-6xl px-4 sm:px-6 py-8">
        <x-page-header titre="Préparation des examens"
            description="Assistant administratif — session, épreuves, salles, candidats, listes d'émargement."
            :fil="['Évaluations' => route('admin.evaluations.index'), 'Préparation' => '#']" />

        {{-- ── Statistiques de préparation ── --}}
        <div class="mt-4 grid grid-cols-2 md:grid-cols-4 gap-4">
            <div class="ds-card p-4">
                <p class="text-xs font-black uppercase tracking-wider text-slate-500">Épreuves planifiées</p>
                <p class="mt-1 text-2xl font-black tabular-nums">{{ $tableau['total_epreuves'] }}</p>
            </div>
            <div class="ds-card p-4">
                <p class="text-xs font-black uppercase tracking-wider text-slate-500">Sans salle</p>
                <p class="mt-1 text-2xl font-black tabular-nums {{ $tableau['sans_salle'] > 0 ? 'text-rose-600' : 'text-emerald-600' }}">{{ $tableau['sans_salle'] }}</p>
            </div>
            <div class="ds-card p-4">
                <p class="text-xs font-black uppercase tracking-wider text-slate-500">Candidats convoqués</p>
                <p class="mt-1 text-2xl font-black tabular-nums">{{ $tableau['candidats'] }}</p>
            </div>
            <div class="ds-card p-4">
                <p class="text-xs font-black uppercase tracking-wider text-slate-500">Documents générés</p>
                <p class="mt-1 text-2xl font-black tabular-nums">{{ $tableau['documents'] }}</p>
            </div>
        </div>

        {{-- ── Répartition par statut ── --}}
        @if($tableau['par_statut'] !== [])
            <div class="mt-4 ds-card p-4 flex flex-wrap items-center gap-2">
                <p class="text-xs font-black uppercase tracking-wider text-slate-500 mr-2">Épreuves par statut :</p>
                @foreach($tableau['par_statut'] as $statut => $nombre)
                    <x-status-badge :statut="$statut" />
                    <span class="text-sm font-bold tabular-nums">{{ $nombre }}</span>
                @endforeach
            </div>
        @endif

        {{-- ── Sessions récentes ── --}}
        <div class="mt-4 ds-card overflow-hidden">
            @if($tableau['sessions']->isEmpty())
                <x-empty-state :message="'Aucune session — Créez d\'abord une session d\'examen.'" />
            @else
                <table class="w-full text-sm">
                    <thead class="bg-slate-50 text-left text-[11px] uppercase tracking-wider text-slate-500">
                        <tr>
                            <th class="px-4 py-2">Code</th>
                            <th class="px-4 py-2">Session</th>
                            <th class="px-4 py-2">Type</th>
                            <th class="px-4 py-2">Période</th>
                            <th class="px-4 py-2">Statut</th>
                            <th class="px-4 py-2 text-right">Préparation</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach($tableau['sessions'] as $session)
                            <tr>
                                <td class="px-4 py-2 font-mono font-bold">{{ $session->code ?? '—' }}</td>
                                <td class="px-4 py-2">{{ $session->nom }}</td>
                                <td class="px-4 py-2"><x-status-badge :statut="$session->type" /></td>
                                <td class="px-4 py-2 tabular-nums">{{ $session->date_debut->format('d/m/Y') }} → {{ $session->date_fin->format('d/m/Y') }}</td>
                                <td class="px-4 py-2"><x-status-badge :statut="$session->statut" /></td>
                                <td class="px-4 py-2 text-right">
                                    <x-btn size="sm" href="{{ route('admin.examens.preparation.session', $session) }}">Ouvrir l'assistant</x-btn>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>

        <p class="mt-3 text-xs text-slate-500">
            Les épreuves, salles et surveillances se gèrent depuis les écrans existants :
            <a class="font-bold text-primary-700 underline underline-offset-2" href="{{ route('admin.evaluations.sessions.index') }}">sessions</a> ·
            <a class="font-bold text-primary-700 underline underline-offset-2" href="{{ route('admin.evaluations.epreuves.index') }}">épreuves</a> ·
            <a class="font-bold text-primary-700 underline underline-offset-2" href="{{ route('admin.evaluations.index') }}">tableau de bord évaluations</a>.
        </p>
    </div>
@endsection
