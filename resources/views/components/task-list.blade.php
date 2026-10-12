{{--
    Liste de tâches actionnable (§9.22) : case à cocher pour le bulk,
    priorité textuelle (jamais la couleur seule), lien métier direct.
--}}
@props(['taches'])

<div class="ds-card overflow-hidden">
    @if($taches->isEmpty())
        <x-empty-state :message="'Aucune tâche — Tout est à jour. La génération quotidienne alimente ce centre.'" />
    @else
        <ul class="divide-y divide-slate-100">
            @foreach($taches as $tache)
                <li class="flex items-start gap-3 px-4 py-3">
                    <input type="checkbox" name="ids[]" value="{{ $tache->id }}" aria-label="Sélectionner {{ $tache->titre }}" class="mt-1" />
                    <div class="min-w-0 flex-1">
                        <p class="text-sm font-bold text-slate-900">
                            @if($tache->priorite === 'critical')
                                <span class="mr-1 rounded bg-rose-100 px-1.5 py-0.5 text-[10px] font-black uppercase text-rose-700">Urgent</span>
                            @elseif($tache->priorite === 'high')
                                <span class="mr-1 rounded bg-amber-100 px-1.5 py-0.5 text-[10px] font-black uppercase text-amber-700">À traiter</span>
                            @endif
                            {{ $tache->titre }}
                        </p>
                        @if($tache->description)
                            <p class="mt-0.5 truncate text-xs text-slate-500">{{ $tache->description }}</p>
                        @endif
                        <p class="mt-1 text-[11px] text-slate-400">
                            {{ $tache->source }} · {{ $tache->statut }}
                            @if($tache->echeance_a) · échéance {{ $tache->echeance_a->format('d/m/Y') }} @endif
                            @if($tache->assigne) · {{ $tache->assigne->name }} @endif
                        </p>
                    </div>
                    <div class="flex shrink-0 gap-1.5">
                        @if($tache->lien)
                            <a href="{{ $tache->lien }}" class="text-xs font-bold text-primary-800 underline underline-offset-2">Ouvrir</a>
                        @endif
                        <a href="{{ route('admin.taches.show', $tache) }}" class="text-xs font-bold text-slate-500 underline underline-offset-2">Détail</a>
                    </div>
                </li>
            @endforeach
        </ul>
        @if(method_exists($taches, 'links'))
            <div class="px-4 py-3">{{ $taches->links() }}</div>
        @endif
    @endif
</div>
