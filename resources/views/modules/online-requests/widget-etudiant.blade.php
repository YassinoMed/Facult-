{{-- Widget étudiant : suivi des demandes personnelles. --}}
<section aria-label="Mes demandes" class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
    <h2 class="text-base font-black text-slate-900">Mes demandes</h2>
    <div class="mt-2 grid grid-cols-2 gap-2 text-center">
        <div class="rounded-xl bg-amber-50 border border-amber-200 px-2 py-2.5">
            <p class="text-xl font-black tabular-nums text-amber-800">{{ $mesEnCours ?? 0 }}</p>
            <p class="text-[10px] font-bold uppercase text-amber-700">En cours</p>
        </div>
        <div class="rounded-xl bg-emerald-50 border border-emerald-200 px-2 py-2.5">
            <p class="text-xl font-black tabular-nums text-emerald-800">{{ $mesDisponibles ?? 0 }}</p>
            <p class="text-[10px] font-bold uppercase text-emerald-700">Documents prêts</p>
        </div>
    </div>
    <a href="{{ route('etudiant.demandes.index') }}" class="mt-3 inline-flex items-center gap-1.5 text-xs font-bold text-primary-800 underline underline-offset-2 hover:text-primary-600">
        Mes demandes <span aria-hidden="true">→</span>
    </a>
</section>
