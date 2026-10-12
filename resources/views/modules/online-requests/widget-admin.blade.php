{{-- Widget scolarité : demandes à traiter dans le périmètre. --}}
<section aria-label="Demandes en ligne" class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
    <h2 class="text-base font-black text-slate-900">Demandes en ligne</h2>
    <div class="mt-2 grid grid-cols-3 gap-2 text-center">
        <div class="rounded-xl bg-slate-50 border border-slate-200 px-2 py-2.5">
            <p class="text-xl font-black tabular-nums text-slate-800">{{ $demandesNouvelles ?? 0 }}</p>
            <p class="text-[10px] font-bold uppercase text-slate-500">Nouvelles</p>
        </div>
        <div class="rounded-xl bg-amber-50 border border-amber-200 px-2 py-2.5">
            <p class="text-xl font-black tabular-nums text-amber-800">{{ $demandesEnCours ?? 0 }}</p>
            <p class="text-[10px] font-bold uppercase text-amber-700">En cours</p>
        </div>
        <div class="rounded-xl bg-rose-50 border border-rose-200 px-2 py-2.5">
            <p class="text-xl font-black tabular-nums text-rose-800">{{ $demandesRetard ?? 0 }}</p>
            <p class="text-[10px] font-bold uppercase text-rose-700">En retard</p>
        </div>
    </div>
    <a href="{{ route('admin.demandes.index') }}" class="mt-3 inline-flex items-center gap-1.5 text-xs font-bold text-primary-800 underline underline-offset-2 hover:text-primary-600">
        Registre des demandes <span aria-hidden="true">→</span>
    </a>
</section>
