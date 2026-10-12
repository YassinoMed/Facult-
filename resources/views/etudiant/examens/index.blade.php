@extends('layouts.app')

@section('title', 'Mes Examens & Convocations — Espace Étudiant')
@section('meta_description', 'Consultation de vos épreuves d\'examen et téléchargement de vos convocations officielles.')

@section('content')
<div class="mx-auto max-w-5xl px-4 sm:px-6 py-8 space-y-6">

    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-black text-slate-900">Mes Examens & Convocations</h1>
            <p class="mt-1 text-sm text-slate-500">Consultez votre calendrier d'épreuves et téléchargez vos convocations officielles.</p>
        </div>
        <a href="{{ route('etudiant.dashboard') }}"
           class="inline-flex items-center gap-1.5 rounded-xl border border-slate-300 px-3.5 py-2 text-xs font-bold text-slate-600 hover:bg-slate-50 transition-smooth">
            ← Tableau de bord
        </a>
    </div>

    @if($candidatures->isEmpty())
        <div class="rounded-2xl border border-slate-200 bg-white p-8 text-center text-sm text-slate-500">
            Aucun examen programmé ou convocation disponible pour le moment.
        </div>
    @else
        <div class="rounded-2xl border border-slate-200/80 bg-white shadow-xs overflow-hidden">
            <div class="border-b border-slate-100 bg-slate-50/80 px-6 py-3 flex items-center justify-between">
                <p class="text-sm font-bold text-slate-900">
                    Calendrier de vos épreuves
                </p>
                <span class="text-xs text-slate-500">{{ $candidatures->count() }} épreuve(s)</span>
            </div>

            <div class="divide-y divide-slate-100">
                @foreach($candidatures as $candidat)
                    @php $epreuve = $candidat->epreuve; @endphp
                    <div class="p-5 flex flex-col md:flex-row md:items-center justify-between gap-4 hover:bg-slate-50/50 transition-colors">
                        <div class="flex items-start gap-4">
                            <div class="text-center min-w-[70px] pt-1">
                                <span class="text-xs font-bold text-indigo-700 bg-indigo-50 px-2.5 py-1 rounded-md tabular-nums block">
                                    {{ $epreuve->date->format('d/m/Y') }}
                                </span>
                                <span class="text-[11px] text-slate-500 tabular-nums block mt-1">
                                    {{ substr($epreuve->heure_debut, 0, 5) }} → {{ substr($epreuve->heure_fin, 0, 5) }}
                                </span>
                            </div>

                            <div>
                                <h3 class="font-black text-slate-900 text-sm">
                                    {{ $epreuve->matiere->code }} — {{ $epreuve->matiere->nom }}
                                </h3>
                                <div class="mt-1 flex flex-wrap items-center gap-3 text-xs text-slate-500">
                                    <span>Session : <strong class="text-slate-700">{{ $epreuve->session?->nom ?? '—' }}</strong></span>
                                    <span>·</span>
                                    <span>Salle : <strong class="text-slate-900">{{ $candidat->salleEffective()?->code ?? $epreuve->salle?->code ?? 'À confirmer' }}</strong></span>
                                    @if($candidat->place)
                                        <span>·</span>
                                        <span>Place : <strong class="text-indigo-600 font-bold">{{ $candidat->place }}</strong></span>
                                    @endif
                                    <span>·</span>
                                    <span>Statut : <span class="capitalize font-medium">{{ $candidat->statut }}</span></span>
                                </div>
                            </div>
                        </div>

                        <div>
                            <a href="{{ route('etudiant.examens.convocation', $candidat) }}"
                               class="inline-flex items-center gap-1.5 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white px-3.5 py-2 text-xs font-bold shadow-xs transition-smooth">
                                <span>📄 Télécharger convocation</span>
                            </a>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

</div>
@endsection
