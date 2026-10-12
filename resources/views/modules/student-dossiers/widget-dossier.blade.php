{{-- Widget étudiant (§15) : données fournies par le composer du module. --}}
<section aria-label="Dossier administratif" class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
    <h2 class="text-base font-black text-slate-900">Dossier administratif</h2>
    @if(isset($dossierWidget))
        <p class="mt-1 font-mono text-xs text-slate-500">{{ $dossierWidget->reference }}</p>
        <div class="mt-3 grid grid-cols-3 gap-2 text-center">
            <div class="rounded-xl bg-emerald-50 border border-emerald-200 px-2 py-2.5">
                <p class="text-xl font-black tabular-nums text-emerald-800">{{ $dossierWidget->valides }}</p>
                <p class="text-[10px] font-bold uppercase text-emerald-700">Validés</p>
            </div>
            <div class="rounded-xl bg-amber-50 border border-amber-200 px-2 py-2.5">
                <p class="text-xl font-black tabular-nums text-amber-800">{{ $dossierWidget->en_attente }}</p>
                <p class="text-[10px] font-bold uppercase text-amber-700">En attente</p>
            </div>
            <div class="rounded-xl bg-slate-50 border border-slate-200 px-2 py-2.5">
                <p class="text-xl font-black tabular-nums text-slate-800">{{ $dossierWidget->documents_count }}</p>
                <p class="text-[10px] font-bold uppercase text-slate-500">Total</p>
            </div>
        </div>
    @else
        <x-empty-state message="Dossier en cours de constitution par la scolarité." />
    @endif
    <a href="{{ route('etudiant.dossier.show') }}" class="mt-3 inline-flex items-center gap-1.5 text-xs font-bold text-primary-800 underline underline-offset-2 hover:text-primary-600">
        Mon dossier <span aria-hidden="true">→</span>
    </a>
</section>
