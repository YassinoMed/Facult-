@foreach($parcours as $bloc)
    <section aria-label="Inscription {{ $bloc['inscription']->anneeUniversitaire?->libelle }}" class="mt-4 ds-card p-5">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h2 class="font-black text-slate-900">{{ $bloc['inscription']->anneeUniversitaire?->libelle ?? '—' }}
                <span class="font-normal text-slate-500">· {{ $bloc['inscription']->niveau ?? '—' }}</span></h2>
            <x-status-badge :statut="$bloc['inscription']->statut" />
        </div>
        <dl class="mt-3 grid grid-cols-1 sm:grid-cols-3 gap-x-6 gap-y-2 text-sm">
            <div><dt class="text-[11px] font-bold uppercase text-slate-400">Formation / Parcours</dt><dd class="font-semibold">{{ $bloc['inscription']->programmeRef?->nom ?? $bloc['inscription']->filiere ?? '—' }}</dd></div>
            <div><dt class="text-[11px] font-bold uppercase text-slate-400">Type / Régime</dt><dd class="font-semibold">{{ $bloc['inscription']->type ?? '—' }} {{ $bloc['inscription']->regime ? '· ' . $bloc['inscription']->regime : '' }}</dd></div>
            <div><dt class="text-[11px] font-bold uppercase text-slate-400">Date / Décision</dt><dd class="font-semibold">{{ $bloc['inscription']->created_at?->format('d/m/Y') ?? '—' }} {{ $bloc['inscription']->decision ? '· ' . $bloc['inscription']->decision : '' }}</dd></div>
            <div><dt class="text-[11px] font-bold uppercase text-slate-400">Groupe</dt><dd class="font-semibold font-mono">{{ $bloc['affectation']?->groupe?->code ?? '—' }}</dd></div>
            <div><dt class="text-[11px] font-bold uppercase text-slate-400">Sous-groupe</dt><dd class="font-semibold font-mono">{{ $bloc['affectation']?->sousGroupe?->code ?? '—' }}</dd></div>
            <div><dt class="text-[11px] font-bold uppercase text-slate-400">Moyenne / Crédits</dt><dd class="font-semibold tabular-nums">{{ $bloc['inscription']->moyenne_annuelle ?? '—' }} · {{ $bloc['inscription']->credits_obtenus ?? 0 }}/{{ $bloc['inscription']->credits_total ?? 0 }}</dd></div>
        </dl>
        @if($bloc['situations']->isNotEmpty())
            <h3 class="mt-3 text-xs font-black uppercase tracking-wider text-slate-500">Changements de parcours ({{ $bloc['situations']->count() }})</h3>
            <ul class="mt-1.5 space-y-1.5 text-sm">
                @foreach($bloc['situations'] as $situation)
                    <li class="rounded-xl border border-slate-100 px-3.5 py-2.5">
                        <strong>{{ ucfirst($situation->type) }}</strong>
                        <span class="text-slate-500">{{ $situation->ancienne_valeur ?? '—' }} → {{ $situation->nouvelle_valeur ?? '—' }}</span>
                        <span class="block text-xs text-slate-400">{{ $situation->date_effet?->format('d/m/Y') }}@if($situation->motif) · {{ $situation->motif }}@endif</span>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>
@endforeach

@if($parcours->isEmpty())
    <div class="mt-4"><x-empty-state message="Aucune inscription archivée." /></div>
@endif
