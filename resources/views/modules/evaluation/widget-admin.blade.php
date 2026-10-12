<div class="ds-card p-4">
    <p class="text-[11px] font-bold uppercase tracking-wider text-slate-500">Évaluation</p>
    <p class="mt-1 text-2xl font-black tabular-nums text-slate-900">{{ $epreuvesSansSalle + $notesAValider + $deliberationsEnAttente }}</p>
    <p class="text-xs text-slate-500">{{ $epreuvesSansSalle }} sans salle · {{ $notesAValider }} à valider · {{ $deliberationsEnAttente }} délibérations</p>
    <a href="{{ route('admin.evaluations.index') }}" class="mt-2 inline-block text-xs font-bold text-primary-800 underline underline-offset-2">Ouvrir l’évaluation</a>
</div>
