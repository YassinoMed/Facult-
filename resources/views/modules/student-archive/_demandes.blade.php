<section aria-label="Demandes de services" class="mt-4 ds-card p-5">
    <h2 class="text-sm font-black uppercase tracking-wider text-slate-500">Demandes ({{ $demandes['ouvertes'] }} ouverte(s))</h2>
    @if($demandes['demandes']->isNotEmpty())
        <ul class="mt-2 space-y-1.5 text-sm">
            @foreach($demandes['demandes'] as $demande)
                <li class="flex items-center justify-between gap-2 rounded-xl border border-slate-100 px-3.5 py-2.5">
                    <span><strong>{{ $demande->reference }}</strong>
                        <span class="text-slate-500">· {{ $demande->type?->name ?? '—' }} · {{ $demande->objet }}</span></span>
                    <x-status-badge :statut="$demande->status" />
                </li>
            @endforeach
        </ul>
    @else
        <div class="mt-2"><x-empty-state message="Aucune demande." /></div>
    @endif
</section>
