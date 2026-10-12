@extends('layouts.app')

@section('title', 'Mon parcours LMD — Espace Étudiant')
@section('meta_description', 'Votre parcours LMD en lecture seule : programme, niveau, semestres et ECUE.')

@section('content')
<div class="mx-auto max-w-5xl px-4 sm:px-6 py-8 space-y-6">

    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-black text-slate-900">Mon parcours LMD</h1>
            <p class="mt-1 text-sm text-slate-500">Programme et niveau réellement associés à votre inscription — lecture seule.</p>
        </div>
        <a href="{{ route('etudiant.archives.show') }}"
           class="inline-flex items-center gap-1.5 rounded-xl border border-slate-300 px-3.5 py-2 text-xs font-bold text-slate-600 hover:bg-slate-50 transition-smooth">
            ← Dossier complet
        </a>
        <a href="{{ route('etudiant.parcours.pdf') }}" target="_blank" rel="noopener"
           class="inline-flex items-center gap-1.5 rounded-xl border border-slate-300 px-3.5 py-2 text-xs font-bold text-slate-600 hover:bg-slate-50 transition-smooth">
            Version PDF (informatif)
        </a>
    </div>

    <div class="rounded-2xl border border-slate-200/80 bg-white shadow-sm p-6">
        <dl class="grid grid-cols-2 sm:grid-cols-4 gap-3 text-sm">
            <div><dt class="text-[11px] font-bold uppercase tracking-wider text-slate-500">Année</dt><dd class="font-black">{{ $parcours['annee'] ?? '—' }}</dd></div>
            <div><dt class="text-[11px] font-bold uppercase tracking-wider text-slate-500">Programme</dt><dd class="font-black font-mono">{{ $parcours['programme'] ?? '—' }}</dd></div>
            <div><dt class="text-[11px] font-bold uppercase tracking-wider text-slate-500">Niveau</dt><dd class="font-black">{{ $parcours['niveau'] ?? '—' }}</dd></div>
            <div><dt class="text-[11px] font-bold uppercase tracking-wider text-slate-500">Plan</dt><dd class="font-black">{{ $parcours['source_plan'] ? 'Maquette v'.$parcours['maquette']['version'] : 'Référentiel' }}</dd></div>
        </dl>
        <p class="mt-2 text-sm text-slate-600">{{ $parcours['programme_nom'] ?? '' }}</p>
        @if($parcours['etat'] !== 'OK')
            <p class="mt-2 text-sm font-bold text-amber-700">{{ $parcours['message'] }}</p>
        @endif
    </div>

    @foreach($parcours['semestres'] as $semestre)
        <div class="rounded-2xl border border-slate-200/80 bg-white shadow-sm overflow-hidden">
            <div class="border-b border-slate-100 bg-slate-50/80 px-6 py-3">
                <h2 class="text-sm font-black text-slate-900">{{ $semestre['code'] }} ({{ count($semestre['ecues']) }} ECUE)</h2>
            </div>
            <ul class="divide-y divide-slate-100">
                @foreach($semestre['ecues'] as $ecue)
                    <li class="px-6 py-2.5 text-sm flex flex-wrap justify-between gap-2">
                        <span><strong class="font-mono">{{ $ecue['code'] }}</strong> — {{ $ecue['nom'] }}</span>
                        <span class="text-slate-500 tabular-nums">{{ $ecue['credits'] }} crédits</span>
                    </li>
                @endforeach
            </ul>
        </div>
    @endforeach
</div>
@endsection
