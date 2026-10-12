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
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-slate-100 text-left text-xs uppercase tracking-wider text-slate-400">
                            <th class="px-6 py-2.5">Matière</th>
                            <th class="px-4 py-2.5">Note</th>
                            <th class="px-4 py-2.5">Session</th>
                            <th class="px-4 py-2.5 text-right">Crédits</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($semestre->notes as $note)
                            <tr class="border-b border-slate-50 last:border-0">
                                <td class="px-6 py-2.5 font-semibold text-slate-800">
                                    {{ $note->matiere?->nom ?? '—' }}
                                    <span class="ml-1 font-mono text-xs text-slate-400">{{ $note->matiere?->code }}</span>
                                </td>
                                <td class="px-4 py-2.5 font-black {{ ($note->note_finale ?? 0) >= 10 ? 'text-emerald-600' : 'text-rose-600' }}">
                                    {{ $note->note_finale ?? '—' }}/20
                                </td>
                                <td class="px-4 py-2.5 text-slate-500 text-xs">{{ $note->session ?? '—' }}</td>
                                <td class="px-4 py-2.5 text-right text-slate-600">{{ $note->matiere?->credits ?? '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endforeach
    @endforeach
</div>
@endsection
