{{-- Widget scolarité : volume de créneaux actifs du périmètre. --}}
<section aria-label="Emplois du temps" class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
    <h2 class="text-base font-black text-slate-900">Emplois du temps</h2>
    <p class="mt-2 text-3xl font-black tabular-nums text-slate-900">{{ $creneauxActifs ?? 0 }}</p>
    <p class="text-[11px] text-slate-500">créneau(x) planifié(s) dans votre périmètre.</p>
    <a href="{{ route('admin.emplois-du-temps.index') }}" class="mt-3 inline-flex items-center gap-1.5 text-xs font-bold text-primary-800 underline underline-offset-2 hover:text-primary-600">
        Gérer les plannings <span aria-hidden="true">→</span>
    </a>
</section>
