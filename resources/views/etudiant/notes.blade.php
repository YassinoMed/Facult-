@extends('layouts.app')

@section('title', 'Mes Notes — Espace Étudiant')
@section('meta_description', 'Consultation de vos notes par année, semestre et matière.')

@section('content')
<div class="mx-auto max-w-5xl px-4 sm:px-6 py-8 space-y-6">

    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-black text-slate-900">Mes Notes</h1>
            <p class="mt-1 text-sm text-slate-500">Vos notes en lecture seule — toute correction est gérée par la scolarité.</p>
        </div>
        <a href="{{ route('etudiant.archives.show') }}"
           class="inline-flex items-center gap-1.5 rounded-xl border border-slate-300 px-3.5 py-2 text-xs font-bold text-slate-600 hover:bg-slate-50 transition-smooth">
            ← Dossier complet
        </a>
    </div>

    @if($inscriptions->isEmpty())
        <div class="rounded-2xl border border-slate-200 bg-white p-8 text-center text-sm text-slate-500">
            Aucune note enregistrée pour le moment.
        </div>
    @endif

    @foreach($inscriptions as $inscription)
        @foreach($inscription->semestres as $semestre)
            @if($semestre->notes->isEmpty()) @continue @endif
            <div class="rounded-2xl border border-slate-200/80 bg-white shadow-sm overflow-hidden">
                <div class="border-b border-slate-100 bg-slate-50/80 px-6 py-3 flex items-center justify-between">
                    <p class="text-sm font-bold text-slate-900">
                        {{ $inscription->anneeUniversitaire?->libelle }} — {{ $semestre->libelle ?? 'Semestre' }}
                    </p>
                    <span class="text-xs text-slate-500">{{ $semestre->notes->count() }} matière(s)</span>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm min-w-[540px]">
                        <thead>
                            <tr class="border-b border-slate-100 text-left text-xs uppercase tracking-wider text-slate-700 font-bold">
                                <th scope="col" class="px-6 py-2.5">Matière</th>
                                <th scope="col" class="px-4 py-2.5">Note</th>
                                <th scope="col" class="px-4 py-2.5">Coeff.</th>
                                <th scope="col" class="px-4 py-2.5">Session</th>
                                <th scope="col" class="px-4 py-2.5 text-right">Crédits</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($semestre->notes as $note)
                                @php
                                    $valeurNote = $note->note !== null ? (float) $note->note : null;
                                @endphp
                                <tr class="border-b border-slate-50 last:border-0 hover:bg-slate-50/50 transition-colors">
                                    <td class="px-6 py-2.5 font-semibold text-slate-800">
                                        {{ $note->matiere?->nom ?? '—' }}
                                        <span class="ml-1 font-mono text-xs text-slate-500 font-medium">{{ $note->matiere?->code }}</span>
                                    </td>
                                    <td class="px-4 py-2.5 font-black {{ $valeurNote !== null ? ($valeurNote >= 10 ? 'text-emerald-600' : 'text-rose-600') : 'text-slate-500' }}">
                                        {{ $valeurNote !== null ? number_format($valeurNote, 2) : '—' }}/20
                                    </td>
                                    <td class="px-4 py-2.5 text-xs text-slate-500 font-mono">
                                        {{ $note->coefficient !== null ? number_format((float) $note->coefficient, 1) : ($note->matiere?->coefficient ?? '—') }}
                                    </td>
                                    <td class="px-4 py-2.5 text-slate-500 text-xs">
                                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium {{ $note->session === 'rattrapage' ? 'bg-amber-100 text-amber-800' : 'bg-slate-100 text-slate-700' }}">
                                            {{ ucfirst($note->session ?? 'Normale') }}
                                        </span>
                                    </td>
                                    <td class="px-4 py-2.5 text-right text-slate-600 font-medium">
                                        {{ $note->credits ?? $note->matiere?->credits ?? '—' }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endforeach
    @endforeach
</div>
@endsection
