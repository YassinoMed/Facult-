<div class="ds-card p-4">
    <p class="text-[11px] font-bold uppercase tracking-wider text-slate-500">Annonces</p>
    <p class="mt-1 text-2xl font-black tabular-nums text-slate-900">{{ $annoncesPubliees }}</p>
    <p class="text-xs text-slate-500">annonce(s) visible(s)</p>
    <a href="{{ route('admin.annonces.index') }}" class="mt-2 inline-block text-xs font-bold text-primary-800 underline underline-offset-2">Gérer</a>
</div>
