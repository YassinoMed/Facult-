@extends('layouts.app')

@section('title', $enseignant->nomComplet() . ' — Enseignant')

@section('content')
    <div class="mx-auto max-w-4xl px-4 sm:px-6 py-8">
        <x-page-header :titre="$enseignant->nomComplet()" :description="$enseignant->grade?->nom ?? 'Enseignant'"
            :fil="['Enseignants' => route('admin.enseignants.index'), $enseignant->nomComplet() => '#']">
            <x-slot:actions>
                <x-btn variant="secondary" href="{{ route('admin.listes.preview', ['enseignant_id' => $enseignant->id]) }}">Étudiants de ses enseignements</x-btn>
                @can('enseignants.manage')
                    <x-btn variant="secondary" href="{{ route('admin.enseignants.edit', $enseignant) }}">Modifier</x-btn>
                @endcan
            </x-slot:actions>
        </x-page-header>

        <div class="mt-4 grid grid-cols-2 sm:grid-cols-4 gap-3">
            <div class="ds-card p-4"><p class="text-2xl font-black tabular-nums">{{ $heures }}h</p><p class="text-[11px] font-bold uppercase text-slate-500">Charge</p></div>
            <div class="ds-card p-4"><p class="text-2xl font-black tabular-nums">{{ $nbCours }}</p><p class="text-[11px] font-bold uppercase text-slate-500">Créneaux</p></div>
            <div class="ds-card p-4"><p class="text-lg font-black">{{ $enseignant->matricule ?? '—' }}</p><p class="text-[11px] font-bold uppercase text-slate-500">Matricule</p></div>
            <div class="ds-card p-4"><p class="text-lg font-black">{{ $enseignant->statut ?? 'actif' }}</p><p class="text-[11px] font-bold uppercase text-slate-500">Statut</p></div>
        </div>

        <div class="mt-4 ds-card p-6">
            <dl class="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-3 text-sm">
                <div><dt class="text-[11px] font-bold uppercase text-slate-400">Email</dt><dd class="font-semibold">{{ $enseignant->email ?? '—' }}</dd></div>
                <div><dt class="text-[11px] font-bold uppercase text-slate-400">Bureau</dt><dd class="font-semibold">{{ $enseignant->bureau ?? '—' }}</dd></div>
                <div><dt class="text-[11px] font-bold uppercase text-slate-400">Département</dt><dd class="font-semibold">{{ $enseignant->departement?->nom ?? '—' }}</dd></div>
                <div><dt class="text-[11px] font-bold uppercase text-slate-400">Spécialité professionnelle</dt><dd class="font-semibold">{{ $enseignant->specialite?->nom ?? '—' }}</dd></div>
                <div><dt class="text-[11px] font-bold uppercase text-slate-400">Embauche</dt><dd class="font-semibold">{{ $enseignant->date_embauche?->format('d/m/Y') ?? '—' }}</dd></div>
            </dl>
        </div>

        <section aria-label="Services (créneaux)" class="mt-4 ds-card p-5">
            <h2 class="text-base font-black text-slate-900">Services — créneaux à venir</h2>
            @if($enseignant->slots->isNotEmpty())
                <ul class="mt-3 space-y-1.5 text-sm">
                    @foreach($enseignant->slots->take(10) as $slot)
                        <li class="rounded-xl border border-slate-100 px-3.5 py-2.5">
                            <strong>{{ $slot->matiere->nom }}</strong>
                            <span class="text-slate-500 tabular-nums">· {{ $slot->schedule->groupe->code }} · {{ $slot->day_of_week }} {{ $slot->start_time }} → {{ $slot->end_time }}</span>
                        </li>
                    @endforeach
                </ul>
            @else
                <div class="mt-3"><x-empty-state message="Aucun créneau affecté." /></div>
            @endif
        </section>
        <section aria-label="Enseignements de l'enseignant" class="mt-4 ds-card p-5">
            <h2 class="text-base font-black text-slate-900">Enseignements ({{ $enseignant->affectationsEnseignement->where('statut', 'active')->count() }})</h2>
            @if($enseignements->isNotEmpty())
                <ul class="mt-3 space-y-1.5 text-sm">
                    @foreach($enseignements as $ens)
                        <li class="flex items-center justify-between gap-2 rounded-xl border border-slate-100 px-3.5 py-2.5">
                            <span><strong class="font-mono">{{ $ens->matiere->code }}</strong> — {{ $ens->matiere->nom }}
                                <span class="text-slate-500">· {{ $ens->type?->code }} · {{ $ens->volumeTotal() }}h</span></span>
                            <a href="{{ route('admin.enseignements.show', $ens) }}" class="text-xs font-bold text-primary-800 underline underline-offset-2">Fiche →</a>
                        </li>
                    @endforeach
                </ul>
            @else
                <div class="mt-3"><x-empty-state message="Aucun enseignement affecté." /></div>
            @endif
        </section>
    </div>
@endsection
