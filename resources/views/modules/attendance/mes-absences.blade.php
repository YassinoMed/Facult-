@extends('layouts.app')

@section('title', 'Mes absences — Espace Étudiant')
@section('meta_description', 'Vos relevés de présence et votre taux d\'assiduité.')

@section('content')
    <div class="mx-auto max-w-5xl px-4 sm:px-6 py-8 sm:py-12">
        <p class="text-[11px] font-bold uppercase tracking-wider text-slate-400">Espace étudiant</p>
        <div class="flex flex-wrap items-start justify-between gap-3">
            <h1 class="mt-1 text-2xl sm:text-3xl font-black tracking-tight text-slate-900">Mes absences</h1>
            <a href="{{ route('etudiant.absences.exporter') }}" class="font-bold text-sm text-indigo-700 underline underline-offset-2">Export CSV</a>
        </div>

        <div class="mt-6 grid grid-cols-1 sm:grid-cols-2 gap-3">
            <x-stat-card libelle="Taux de présence" valeur="{{ $taux !== null ? number_format($taux, 1).' %' : '—' }}" detail="Présent + retard comptent présent" couleur="emerald" />
            <x-stat-card libelle="Séances enregistrées" valeur="{{ $seances }}" detail="Toutes périodes" couleur="slate" />
        </div>

        <section aria-label="Historique des relevés" class="mt-6 rounded-2xl border border-slate-200 bg-white shadow-sm overflow-hidden">
            @if($presences->isNotEmpty())
                <ul class="divide-y divide-slate-100">
                    @foreach($presences as $presence)
                        <li class="flex items-center justify-between gap-3 px-5 py-3 text-sm">
                            <span class="text-slate-600">
                                <strong class="tabular-nums text-slate-900">{{ $presence->date->format('d/m/Y') }}</strong>
                                @if($presence->seance) · {{ $presence->seance }} @endif
                            </span>
                            <span class="flex items-center gap-3">
                                @if($presence->derniereJustification?->statut === 'approved' && $presence->derniereJustification->piece_chemin)
                                    <a href="{{ route('etudiant.absences.justificatif', $presence->derniereJustification) }}"
                                       class="text-xs font-bold text-indigo-700 underline underline-offset-2"
                                       aria-label="Télécharger le justificatif d'absence">Justificatif</a>
                                @endif
                                <x-status-badge statut="{{ $presence->statut }}" />
                            </span>
                        </li>
                    @endforeach
                </ul>
                <div class="px-5 py-3 border-t border-slate-100">{{ $presences->links() }}</div>
            @else
                <div class="p-5">
                    <x-empty-state message="Aucune présence enregistrée pour le moment." />
                </div>
            @endif
        </section>
    </div>
@endsection
