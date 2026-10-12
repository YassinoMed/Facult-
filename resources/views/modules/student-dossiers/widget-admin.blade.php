{{-- Widget scolarité (§15) : données fournies par le composer du module. --}}
<section aria-label="Documents en attente" class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
    <h2 class="text-base font-black text-slate-900">Documents en attente</h2>
    <p class="mt-2 text-3xl font-black tabular-nums text-slate-900">{{ $documentsEnAttente ?? 0 }}</p>
    <p class="text-[11px] text-slate-500">document(s) à valider dans votre périmètre.</p>
    <a href="{{ route('admin.student-dossiers.index') }}" class="mt-3 inline-flex items-center gap-1.5 text-xs font-bold text-primary-800 underline underline-offset-2 hover:text-primary-600">
        Dossiers étudiants <span aria-hidden="true">→</span>
    </a>
</section>
