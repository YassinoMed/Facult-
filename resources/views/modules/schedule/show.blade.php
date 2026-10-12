@extends('layouts.app')

@section('title', $planning->nom . ' — Scolarité')

@php
$joursFr = ['monday' => 'Lundi', 'tuesday' => 'Mardi', 'wednesday' => 'Mercredi', 'thursday' => 'Jeudi', 'friday' => 'Vendredi', 'saturday' => 'Samedi', 'sunday' => 'Dimanche'];
$typesFr = ['course' => 'Cours', 'td' => 'TD', 'tp' => 'TP', 'exam' => 'Examen', 'meeting' => 'Réunion', 'workshop' => 'Atelier', 'other' => 'Autre'];
$creneauxParJour = $planning->slots->groupBy('day_of_week');
@endphp

@section('content')
    <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 py-8 sm:py-10">
        <a href="{{ route('admin.emplois-du-temps.index') }}" class="text-xs font-bold text-primary-800 underline underline-offset-2">← Emplois du temps</a>

        @if(session('success'))
            <div class="mt-3 rounded-xl border border-emerald-300 bg-emerald-50 px-4 py-3 text-xs font-bold text-emerald-900" role="status">✓ {{ session('success') }}</div>
        @endif
        @if($errors->any())
            <div class="mt-3 rounded-xl border border-rose-300 bg-rose-50 px-4 py-3 text-xs text-rose-800" role="alert">
                <ul class="list-disc list-inside">
                    @foreach($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <div class="mt-3 flex flex-wrap items-start justify-between gap-3">
            <div>
            <h1 class="text-2xl sm:text-3xl font-black text-slate-900">{{ $planning->nom }}</h1>
            <p class="mt-1 text-sm text-slate-500">
                Groupe <strong class="font-mono">{{ $planning->groupe->code }}</strong>
                · {{ $planning->anneeUniversitaire->libelle }} · Semestre {{ $planning->semestre }}
                @if($planning->starts_at || $planning->ends_at)
                    · Du {{ $planning->starts_at?->format('d/m/Y') ?? '—' }} au {{ $planning->ends_at?->format('d/m/Y') ?? '—' }}
                @endif
            </p>
            </div>
            @can('schedules.manage')
                <x-btn href="{{ route('admin.emplois-du-temps.tableau', $planning) }}" variant="secondary">Tableau déplaçable</x-btn>
            @endcan
        </div>

        <section aria-label="Publication" class="mt-4 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 class="text-base font-black text-slate-900">Publication — statut : {{ $planning->statut ?? 'brouillon' }}</h2>
                    <p class="mt-1 text-xs text-slate-500">
                        {{ $controle['creneaux'] }} créneau(x) · {{ count($controle['conflits']) }} conflit(s) · {{ $controle['sans_enseignant'] }} sans enseignant
                    </p>
                    @if($controle['conflits'] !== [])
                        <ul class="mt-2 list-disc list-inside text-xs text-rose-700">
                            @foreach(array_slice($controle['conflits'], 0, 5) as $conflit)
                                <li>{{ $conflit }}</li>
                            @endforeach
                        </ul>
                    @endif
                </div>
                @can('schedules.manage')
                    <div class="flex flex-wrap gap-2">
                        @foreach(array_diff(\App\Modules\Schedule\Models\Schedule::TRANSITIONS[$planning->statut ?? 'brouillon'] ?? [], ['publie', 'verrouille']) as $cible)
                            <form method="post" action="{{ route('admin.emplois-du-temps.transiter', [$planning, $cible]) }}">
                                @csrf
                                <x-btn size="sm" variant="secondary" type="submit">→ {{ $cible }}</x-btn>
                            </form>
                        @endforeach
                        @if(($planning->statut ?? 'brouillon') !== 'publie' && in_array('publie', \App\Modules\Schedule\Models\Schedule::TRANSITIONS[$planning->statut ?? 'brouillon'] ?? []))
                            <form method="post" action="{{ route('admin.emplois-du-temps.transiter', [$planning, 'publie']) }}">
                                @csrf
                                <x-btn size="sm" type="submit">Publier (contrôle requis)</x-btn>
                            </form>
                        @elseif(in_array('verrouille', \App\Modules\Schedule\Models\Schedule::TRANSITIONS[$planning->statut ?? 'brouillon'] ?? []))
                            <form method="post" action="{{ route('admin.emplois-du-temps.transiter', [$planning, 'verrouille']) }}">
                                @csrf
                                <x-btn size="sm" variant="secondary" type="submit">Verrouiller</x-btn>
                            </form>
                        @endif
                    </div>
                @endcan
            </div>
            @can('schedules.manage')
                <form method="post" action="{{ route('admin.emplois-du-temps.dupliquer', $planning) }}" class="mt-3 flex flex-wrap items-end gap-2 border-t border-slate-100 pt-3">
                    @csrf
                    <div>
                        <label class="text-[11px] font-bold uppercase tracking-wider text-slate-500" for="dup-groupe">Groupe cible</label>
                        <select id="dup-groupe" name="groupe_id" class="mt-1 rounded-xl border border-slate-200 px-3 py-2 text-sm">
                            @foreach($groupesDuplication as $groupe)
                                <option value="{{ $groupe->id }}">{{ $groupe->code }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="text-[11px] font-bold uppercase tracking-wider text-slate-500" for="dup-annee">Année</label>
                        <select id="dup-annee" name="annee_universitaire_id" class="mt-1 rounded-xl border border-slate-200 px-3 py-2 text-sm">
                            @foreach($anneesDuplication as $annee)
                                <option value="{{ $annee->id }}">{{ $annee->libelle }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="text-[11px] font-bold uppercase tracking-wider text-slate-500" for="dup-sem">Semestre</label>
                        <select id="dup-sem" name="semestre" class="mt-1 rounded-xl border border-slate-200 px-3 py-2 text-sm">
                            <option value="1">1</option>
                            <option value="2">2</option>
                        </select>
                    </div>
                    <label class="inline-flex items-center gap-1 text-xs"><input type="checkbox" name="confirmer" value="1" /> Confirmer (salles réaffectées à vide)</label>
                    <x-btn size="sm" variant="secondary" type="submit">Dupliquer</x-btn>
                </form>
                @if(session('apercu_duplication') !== null)
                    <p class="mt-2 text-xs text-slate-500" role="status">{{ session('apercu_duplication') }} créneau(x) seront copiés — cochez Confirmer pour appliquer.</p>
                @endif
            @endcan
        </section>

        {{-- Grille hebdomadaire --}}
        <section aria-label="Grille hebdomadaire" class="mt-6 overflow-x-auto rounded-2xl border border-slate-200 bg-white shadow-sm"
                 x-data="{ suppressionUrl: '', suppressionNom: '' }">
            <table class="w-full min-w-[820px] text-left text-xs">
                <thead>
                    <tr class="border-b border-slate-100 text-[11px] uppercase tracking-wider text-slate-400">
                        <th scope="col" class="px-4 py-3 font-bold">Jour</th>
                        <th scope="col" class="px-4 py-3 font-bold">Horaire</th>
                        <th scope="col" class="px-4 py-3 font-bold">Matière</th>
                        <th scope="col" class="px-4 py-3 font-bold">Enseignant</th>
                        <th scope="col" class="px-4 py-3 font-bold">Salle</th>
                        <th scope="col" class="px-4 py-3 font-bold">Type</th>
                        <th scope="col" class="px-4 py-3 font-bold">Statut</th>
                        <th scope="col" class="px-4 py-3 font-bold"><span class="sr-only">Actions</span></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse($planning->slots as $slot)
                        <tr class="hover:bg-slate-50/60 {{ $slot->status === 'cancelled' ? 'opacity-60' : '' }}">
                            <td class="px-4 py-3 font-bold text-slate-800">{{ $joursFr[$slot->day_of_week] }}</td>
                            <td class="px-4 py-3 tabular-nums text-slate-700">{{ substr($slot->start_time, 0, 5) }}–{{ substr($slot->end_time, 0, 5) }}</td>
                            <td class="px-4 py-3 font-bold text-slate-900">{{ $slot->matiere->nom }}</td>
                            <td class="px-4 py-3 text-slate-600">{{ $slot->teacher?->nomComplet() ?? '—' }}</td>
                            <td class="px-4 py-3 font-mono text-slate-700">{{ $slot->room?->code ?? '—' }}</td>
                            <td class="px-4 py-3 text-slate-600">{{ $typesFr[$slot->type] }}</td>
                            <td class="px-4 py-3">
                                <span class="inline-flex items-center gap-1.5 rounded-full border border-slate-200 px-2.5 py-0.5 text-[11px] font-bold">
                                    @if($slot->status === 'cancelled') ❌ Annulé
                                    @elseif($slot->status === 'modified') ⚠ Modifié
                                    @elseif($slot->status === 'completed') ✓ Terminé
                                    @else ● Planifié
                                    @endif
                                </span>
                                @if($slot->exceptions->isNotEmpty())
                                    <span class="block text-[10px] text-amber-700 font-bold mt-0.5">{{ $slot->exceptions->count() }} exception(s) à venir</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-right">
                                <x-dropdown :etiquette="'Actions pour ' . $slot->matiere->nom">
                                    <x-slot:liens>
                                        <a href="{{ route('admin.emplois-du-temps.slots.edit', $slot) }}" role="menuitem"
                                           class="block px-4 py-2.5 text-xs font-bold text-slate-700 hover:bg-slate-50 min-h-[44px]">Modifier</a>
                                        @if($slot->status === 'cancelled')
                                            <form method="POST" action="{{ route('admin.emplois-du-temps.slots.restore', $slot) }}">
                                                @csrf
                                                <button type="submit" role="menuitem"
                                                        class="block w-full px-4 py-2.5 text-left text-xs font-bold text-emerald-700 hover:bg-slate-50 min-h-[44px]">Restaurer</button>
                                            </form>
                                        @else
                                            <form method="POST" action="{{ route('admin.emplois-du-temps.slots.cancel', $slot) }}">
                                                @csrf
                                                <button type="submit" role="menuitem"
                                                        class="block w-full px-4 py-2.5 text-left text-xs font-bold text-amber-700 hover:bg-slate-50 min-h-[44px]">Annuler</button>
                                            </form>
                                        @endif
                                        <button type="button" role="menuitem"
                                                @click="suppressionUrl = '{{ route('admin.emplois-du-temps.slots.destroy', $slot) }}'; suppressionNom = @js($slot->matiere->nom)"
                                                class="block w-full px-4 py-2.5 text-left text-xs font-bold text-rose-700 hover:bg-slate-50 min-h-[44px]">Supprimer</button>
                                    </x-slot:liens>
                                </x-dropdown>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="p-5"><x-empty-state message="Aucun créneau dans ce planning." /></td></tr>
                    @endforelse
                </tbody>
            </table>

            <x-modal titre="Supprimer ce créneau ?" nom="suppressionUrl">
                <p>Le créneau <strong x-text="suppressionNom"></strong> sera supprimé de l'emploi du temps. Cette action est journalisée.</p>
                <x-slot:actions>
                    <form method="POST" :action="suppressionUrl" class="flex flex-col-reverse sm:flex-row sm:justify-end gap-2">
                        @csrf @method('DELETE')
                        <x-btn variant="secondary" @click.prevent="suppressionUrl = ''">Annuler</x-btn>
                        <x-btn variant="danger" type="submit">Supprimer</x-btn>
                    </form>
                </x-slot:actions>
            </x-modal>
        </section>

        <div class="mt-6 grid grid-cols-1 lg:grid-cols-2 gap-6">
            {{-- Ajout de créneau --}}
            <section aria-label="Ajouter un créneau" class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <h2 class="text-base font-black text-slate-900">Ajouter un créneau récurrent <span class="text-xs font-bold text-slate-400">(tout le semestre)</span></h2>
                <form method="POST" action="{{ route('admin.emplois-du-temps.slots.store', $planning) }}" class="mt-3 grid grid-cols-1 sm:grid-cols-2 gap-3">
                    @csrf
                    <div class="sm:col-span-2">
                        <label for="matiere" class="mb-1 block text-[11px] font-bold uppercase text-slate-500">Matière *</label>
                        <select id="matiere" name="matiere_id" required class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
                            @foreach($matieres as $matiere)
                                <option value="{{ $matiere->id }}">{{ $matiere->code }} — {{ $matiere->nom }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label for="jour" class="mb-1 block text-[11px] font-bold uppercase text-slate-500">Jour *</label>
                        <select id="jour" name="day_of_week" required class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
                            @foreach($joursFr as $code => $libelle)
                                <option value="{{ $code }}">{{ $libelle }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label for="type" class="mb-1 block text-[11px] font-bold uppercase text-slate-500">Type *</label>
                        <select id="type" name="type" required class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
                            @foreach($typesFr as $code => $libelle)
                                <option value="{{ $code }}">{{ $libelle }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label for="debut" class="mb-1 block text-[11px] font-bold uppercase text-slate-500">Début *</label>
                        <input type="time" id="debut" name="start_time" required value="08:00" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
                    </div>
                    <div>
                        <label for="fin" class="mb-1 block text-[11px] font-bold uppercase text-slate-500">Fin *</label>
                        <input type="time" id="fin" name="end_time" required value="09:30" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
                    </div>
                    <div>
                        <label for="enseignant" class="mb-1 block text-[11px] font-bold uppercase text-slate-500">Enseignant</label>
                        <select id="enseignant" name="teacher_id" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
                            <option value="">— Aucun —</option>
                            @foreach($enseignants as $enseignant)
                                <option value="{{ $enseignant->id }}">{{ $enseignant->nomComplet() }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label for="salle" class="mb-1 block text-[11px] font-bold uppercase text-slate-500">Salle</label>
                        <select id="salle" name="room_id" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
                            <option value="">— Aucune —</option>
                            @foreach($salles as $salle)
                                <option value="{{ $salle->id }}">{{ $salle->code }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="sm:col-span-2">
                        <button type="submit" class="rounded-xl bg-primary-800 px-5 py-2.5 text-sm font-bold text-white hover:bg-primary-700 min-h-[44px]">Ajouter (conflits vérifiés)</button>
                    </div>
                </form>
            </section>

            {{-- Exception ponctuelle --}}
            <section aria-label="Exception ponctuelle" class="rounded-2xl border border-amber-200 bg-amber-50/40 p-5 shadow-sm">
                <h2 class="text-base font-black text-slate-900">Exception ponctuelle <span class="text-xs font-bold text-slate-400">(une date seulement)</span></h2>
                <form method="POST" id="form-exception" action="" class="mt-3 grid grid-cols-1 sm:grid-cols-2 gap-3"
                      x-data="{ slotId: '' }" @submit.prevent="if (slotId) { $event.target.action = '{{ url('admin/emplois-du-temps/creneaux') }}/' + slotId + '/exceptions'; $event.target.submit(); }">
                    @csrf
                    <div class="sm:col-span-2">
                        <label for="slot-exception" class="mb-1 block text-[11px] font-bold uppercase text-slate-500">Créneau *</label>
                        <select id="slot-exception" x-model="slotId" required class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
                            <option value="">— Choisir —</option>
                            @foreach($planning->slots as $slot)
                                <option value="{{ $slot->id }}">{{ $joursFr[$slot->day_of_week] }} {{ substr($slot->start_time, 0, 5) }} — {{ $slot->matiere->nom }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label for="date-exception" class="mb-1 block text-[11px] font-bold uppercase text-slate-500">Date *</label>
                        <input type="date" id="date-exception" name="exception_date" required min="{{ date('Y-m-d') }}" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
                    </div>
                    <div>
                        <label for="statut-exception" class="mb-1 block text-[11px] font-bold uppercase text-slate-500">Statut *</label>
                        <select id="statut-exception" name="status" required class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
                            <option value="modified">Modifié</option>
                            <option value="cancelled">Annulé ce jour-là</option>
                            <option value="scheduled">Maintenu (précision)</option>
                        </select>
                    </div>
                    <div>
                        <label for="debut-exception" class="mb-1 block text-[11px] font-bold uppercase text-slate-500">Nouveau début</label>
                        <input type="time" id="debut-exception" name="new_start_time" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
                    </div>
                    <div>
                        <label for="fin-exception" class="mb-1 block text-[11px] font-bold uppercase text-slate-500">Nouvelle fin</label>
                        <input type="time" id="fin-exception" name="new_end_time" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
                    </div>
                    <div>
                        <label for="salle-exception" class="mb-1 block text-[11px] font-bold uppercase text-slate-500">Nouvelle salle</label>
                        <select id="salle-exception" name="new_room_id" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
                            <option value="">— Inchangée —</option>
                            @foreach($salles as $salle)
                                <option value="{{ $salle->id }}">{{ $salle->code }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label for="motif-exception" class="mb-1 block text-[11px] font-bold uppercase text-slate-500">Motif</label>
                        <input type="text" id="motif-exception" name="reason" maxlength="500" placeholder="Ex : maintenance salle" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
                    </div>
                    <div class="sm:col-span-2">
                        <button type="submit" class="rounded-xl bg-primary-800 px-5 py-2.5 text-sm font-bold text-white hover:bg-primary-700 min-h-[44px]">Enregistrer l'exception</button>
                    </div>
                </form>
            </section>
        </div>

        {{-- Historique --}}
        <section aria-label="Historique des modifications" class="mt-6 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            <h2 class="text-base font-black text-slate-900">Historique (30 dernières)</h2>
            @if($historique->isNotEmpty())
                <ul class="mt-3 space-y-2">
                    @foreach($historique as $entree)
                        <li class="rounded-xl border border-slate-100 bg-slate-50/60 px-3.5 py-2.5 text-xs">
                            <p class="font-bold text-slate-800">{{ $entree->change_type }} — créneau #{{ $entree->schedule_slot_id }}</p>
                            <p class="mt-0.5 text-slate-500">
                                {{ $entree->auteur?->name ?? 'Système' }} · <span class="tabular-nums">{{ $entree->created_at->format('d/m/Y H:i') }}</span>
                                @if($entree->reason) · {{ $entree->reason }} @endif
                            </p>
                        </li>
                    @endforeach
                </ul>
            @else
                <p class="mt-2 text-sm text-slate-500">Aucune modification enregistrée.</p>
            @endif
        </section>
    </div>
@endsection
