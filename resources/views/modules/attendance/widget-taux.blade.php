{{-- Widget étudiant (§15) : données fournies par le composer du module. --}}
<section aria-label="Assiduité" class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
    <h2 class="text-base font-black text-slate-900">Assiduité</h2>
    @if(isset($tauxWidget) && $tauxWidget !== null)
        <p class="mt-2 text-3xl font-black tabular-nums text-slate-900">{{ number_format($tauxWidget, 1) }}<span class="text-base text-slate-400"> %</span></p>
        <p class="text-xs text-slate-500">de présence sur {{ $seancesWidget }} séance(s) enregistrée(s).</p>
    @else
        <x-empty-state message="Aucune séance enregistrée pour le moment." />
    @endif
    <a href="{{ route('etudiant.absences.index') }}" class="mt-3 inline-flex items-center gap-1.5 text-xs font-bold text-primary-800 underline underline-offset-2 hover:text-primary-600">
        Mes absences <span aria-hidden="true">→</span>
    </a>
</section>
