@extends('layouts.app')

@section('title', 'Explorer les emplois du temps — Scolarité')

@section('content')
    @php($joursFr = ['monday' => 'Lundi', 'tuesday' => 'Mardi', 'wednesday' => 'Mercredi', 'thursday' => 'Jeudi', 'friday' => 'Vendredi', 'saturday' => 'Samedi', 'sunday' => 'Dimanche'])
    @php($joursAffiches = ($filtres['vue'] ?? 'semaine') === 'jour' ? [($filtres['jour_focus'] ?? $filtres['jour'] ?? 'monday')] : $jours)
    <div class="mx-auto max-w-[88rem] px-4 sm:px-6 py-8" x-data="{ detail: null }">
        <x-page-header titre="Explorer les emplois du temps" description="Recherche multi-axes, disponibilités, charge, conflits et occupation — calculés côté serveur."
            :fil="['Emplois du temps' => route('admin.emplois-du-temps.index'), 'Explorer' => '#']">
            <x-slot:actions>
                <x-btn variant="secondary" href="{{ route('admin.emplois-du-temps.disponibilites') }}">Disponibilités</x-btn>
                <x-btn variant="ghost" href="{{ route('admin.emplois-du-temps.conflits') }}">Conflits</x-btn>
            </x-slot:actions>
        </x-page-header>

        {{-- Barre de recherche multi-axes --}}
        <form method="GET" action="{{ route('admin.emplois-du-temps.explorer') }}"
              class="mt-4 ds-card p-4 sm:p-5" role="search" aria-label="Recherche multi-axes">
            <div class="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-5 gap-3">
                <div>
                    <label for="f-enseignant" class="mb-1 block text-[11px] font-bold uppercase tracking-wider text-slate-500">Enseignant</label>
                    <select id="f-enseignant" name="enseignant_id" class="w-full rounded-xl border border-slate-300 px-3 py-2.5 text-sm min-h-[44px]">
                        <option value="">Tous</option>
                        @foreach($enseignants as $enseignant)
                            <option value="{{ $enseignant->id }}" @selected(($filtres['enseignant_id'] ?? null) == $enseignant->id)>{{ $enseignant->nomComplet() }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="f-salle" class="mb-1 block text-[11px] font-bold uppercase tracking-wider text-slate-500">Salle</label>
                    <select id="f-salle" name="salle_id" class="w-full rounded-xl border border-slate-300 px-3 py-2.5 text-sm min-h-[44px]">
                        <option value="">Toutes</option>
                        @foreach($salles as $salle)
                            <option value="{{ $salle->id }}" @selected(($filtres['salle_id'] ?? null) == $salle->id)>{{ $salle->code }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="f-groupe" class="mb-1 block text-[11px] font-bold uppercase tracking-wider text-slate-500">Groupe</label>
                    <select id="f-groupe" name="groupe_id" class="w-full rounded-xl border border-slate-300 px-3 py-2.5 text-sm min-h-[44px]">
                        <option value="">Tous</option>
                        @foreach($groupes as $groupe)
                            <option value="{{ $groupe->id }}" @selected(($filtres['groupe_id'] ?? null) == $groupe->id)>{{ $groupe->code }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="f-matiere" class="mb-1 block text-[11px] font-bold uppercase tracking-wider text-slate-500">Matière</label>
                    <select id="f-matiere" name="matiere_id" class="w-full rounded-xl border border-slate-300 px-3 py-2.5 text-sm min-h-[44px]">
                        <option value="">Toutes</option>
                        @foreach($matieres as $matiere)
                            <option value="{{ $matiere->id }}" @selected(($filtres['matiere_id'] ?? null) == $matiere->id)>{{ $matiere->code }} — {{ $matiere->nom }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="f-jour" class="mb-1 block text-[11px] font-bold uppercase tracking-wider text-slate-500">Jour</label>
                    <select id="f-jour" name="jour" class="w-full rounded-xl border border-slate-300 px-3 py-2.5 text-sm min-h-[44px]">
                        <option value="">Tous</option>
                        @foreach($jours as $jour)
                            <option value="{{ $jour }}" @selected(($filtres['jour'] ?? null) === $jour)>{{ $joursFr[$jour] }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="f-filiere" class="mb-1 block text-[11px] font-bold uppercase tracking-wider text-slate-500">Filière</label>
                    <select id="f-filiere" name="filiere" class="w-full rounded-xl border border-slate-300 px-3 py-2.5 text-sm min-h-[44px]">
                        <option value="">Toutes</option>
                        @foreach($filieres as $filiere)
                            <option value="{{ $filiere }}" @selected(($filtres['filiere'] ?? null) === $filiere)>{{ $filiere }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="f-niveau" class="mb-1 block text-[11px] font-bold uppercase tracking-wider text-slate-500">Niveau</label>
                    <select id="f-niveau" name="niveau" class="w-full rounded-xl border border-slate-300 px-3 py-2.5 text-sm min-h-[44px]">
                        <option value="">Tous</option>
                        @foreach(['L1','L2','L3','M1','M2','D1','D2','D3'] as $niveau)
                            <option value="{{ $niveau }}" @selected(($filtres['niveau'] ?? null) === $niveau)>{{ $niveau }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="f-semestre" class="mb-1 block text-[11px] font-bold uppercase tracking-wider text-slate-500">Semestre</label>
                    <select id="f-semestre" name="semestre" class="w-full rounded-xl border border-slate-300 px-3 py-2.5 text-sm min-h-[44px]">
                        <option value="">Tous</option>
                        <option value="1" @selected(($filtres['semestre'] ?? null) == 1)>S1</option>
                        <option value="2" @selected(($filtres['semestre'] ?? null) == 2)>S2</option>
                    </select>
                </div>
                <div>
                    <label for="f-annee" class="mb-1 block text-[11px] font-bold uppercase tracking-wider text-slate-500">Année académique</label>
                    <select id="f-annee" name="annee_universitaire_id" class="w-full rounded-xl border border-slate-300 px-3 py-2.5 text-sm min-h-[44px]">
                        <option value="">Toutes</option>
                        @foreach($annees as $annee)
                            <option value="{{ $annee->id }}" @selected(($filtres['annee_universitaire_id'] ?? null) == $annee->id)>{{ $annee->libelle }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="f-vue" class="mb-1 block text-[11px] font-bold uppercase tracking-wider text-slate-500">Vue</label>
                    <select id="f-vue" name="vue" class="w-full rounded-xl border border-slate-300 px-3 py-2.5 text-sm min-h-[44px]">
                        <option value="semaine" @selected(($filtres['vue'] ?? 'semaine') === 'semaine')>Semaine</option>
                        <option value="jour" @selected(($filtres['vue'] ?? '') === 'jour')>Jour</option>
                    </select>
                </div>
            </div>
            <div class="mt-3 flex flex-wrap gap-2">
                <x-btn type="submit">Rechercher</x-btn>
                <x-btn variant="secondary" href="{{ route('admin.emplois-du-temps.explorer') }}">Réinitialiser</x-btn>
            </div>
        </form>

        {{-- Filtres actifs (chips) + compteur --}}
        @php($etiquettes = ['enseignant_id' => 'Enseignant', 'salle_id' => 'Salle', 'groupe_id' => 'Groupe', 'matiere_id' => 'Matière', 'jour' => 'Jour', 'filiere' => 'Filière', 'niveau' => 'Niveau', 'semestre' => 'Semestre', 'annee_universitaire_id' => 'Année'])
        @if(array_intersect_key($filtres, $etiquettes) !== [])
            <div class="mt-3 flex flex-wrap items-center gap-2" aria-label="Filtres actifs">
                <span class="text-[11px] font-black uppercase tracking-wider text-slate-500">Filtres actifs :</span>
                @foreach($etiquettes as $cle => $libelle)
                    @if(!empty($filtres[$cle]))
                        <a href="{{ route('admin.emplois-du-temps.explorer', array_merge(request()->except($cle), ['semaine' => $decalage])) }}"
                           class="inline-flex items-center gap-1.5 rounded-full border border-primary-300 bg-primary-50 px-3 py-1.5 text-[11px] font-bold text-primary-900 min-h-[32px]"
                           aria-label="Retirer le filtre {{ $libelle }}">
                            {{ $libelle }} <span aria-hidden="true">×</span>
                        </a>
                    @endif
                @endforeach
            </div>
        @endif
        <p class="mt-2 text-xs text-slate-500" role="status"><strong class="tabular-nums">{{ $creneaux->count() }}</strong> créneau(x) trouvé(s).</p>

        {{-- Navigation semaine --}}
        <nav class="mt-3 flex flex-wrap items-center gap-2" aria-label="Navigation par semaine">
            <x-btn variant="secondary" href="{{ route('admin.emplois-du-temps.explorer', array_merge(request()->all(), ['semaine' => $decalage - 1])) }}">← Semaine précédente</x-btn>
            <x-btn variant="ghost" href="{{ route('admin.emplois-du-temps.explorer', array_merge(request()->all(), ['semaine' => 0])) }}">Aujourd'hui</x-btn>
            <x-btn variant="secondary" href="{{ route('admin.emplois-du-temps.explorer', array_merge(request()->all(), ['semaine' => $decalage + 1])) }}">Semaine suivante →</x-btn>
            <span class="text-xs font-bold text-slate-500">Semaine du {{ $lundi->format('d/m/Y') }}</span>
        </nav>

        {{-- Grille semaine / jour --}}
        @if($creneaux->isNotEmpty())
            <div class="mt-4 grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-3" id="grille-planning">
                @foreach($joursAffiches as $jour)
                    @php($index = array_search($jour, $jours))
                    @php($dateJour = $lundi->copy()->addDays($index)->toDateString())
                    @php($estAujourdhui = $dateJour === $aujourdhui)
                    <section aria-label="{{ $joursFr[$jour] }} {{ $lundi->copy()->addDays($index)->format('d/m') }}"
                             @if($estAujourdhui) id="colonne-aujourdhui" @endif
                             class="ds-card flex min-h-[160px] flex-col p-3 {{ $estAujourdhui ? 'border-primary-400 ring-2 ring-primary-500/20' : '' }}">
                        <h2 class="px-1 pb-2 text-xs font-black uppercase tracking-wider {{ $estAujourdhui ? 'text-primary-900' : 'text-slate-500' }}">
                            {{ $joursFr[$jour] }} <span class="font-semibold">{{ $lundi->copy()->addDays($index)->format('d/m') }}</span>
                            @if($estAujourdhui)<span class="ml-1 rounded-full bg-primary-800 px-2 py-0.5 text-[10px] text-white">aujourd'hui · {{ $maintenant }}</span>@endif
                        </h2>
                        <ul class="space-y-2">
                            @foreach($creneaux->where('day_of_week', $jour) as $slot)
                                @php($debutSlot = substr($slot->start_time, 0, 5))
                                @php($finSlot = substr($slot->end_time, 0, 5))
                                @php($enCours = $estAujourdhui && $maintenant >= $debutSlot && $maintenant < $finSlot)
                                <li>
                                    <button type="button"
                                            @click="detail = @js(['matiere' => $slot->matiere->nom, 'groupe' => $slot->schedule->groupe->code, 'enseignant' => $slot->teacher?->nomComplet() ?? '—', 'salle' => $slot->room?->code ?? '—', 'horaire' => $joursFr[$jour] . ' ' . $debutSlot . ' → ' . $finSlot, 'status' => $slot->status, 'planning' => $slot->schedule_id])"
                                            class="w-full rounded-xl border p-3 text-left shadow-sm transition hover:shadow-md min-h-[44px] {{ $slot->status === 'cancelled' ? 'border-rose-300 bg-rose-50/50' : ($enCours ? 'border-primary-500 bg-primary-50 ring-2 ring-primary-500/30' : 'border-slate-200 bg-white') }}"
                                            aria-label="Détails {{ $slot->matiere->nom }}, {{ strtolower($joursFr[$jour]) }} {{ $debutSlot }}">
                                        <p class="flex items-center gap-1.5 text-sm font-black text-slate-900">
                                            @if($enCours)<span class="inline-flex items-center gap-1 rounded-full bg-primary-800 px-2 py-0.5 text-[10px] font-black text-white"><span class="h-1.5 w-1.5 rounded-full bg-white animate-pulse" aria-hidden="true"></span>EN COURS</span>@endif
                                            {{ $slot->matiere->nom }}
                                        </p>
                                        <p class="mt-0.5 text-[11px] font-bold text-slate-500 tabular-nums">{{ $slot->schedule->groupe->code }} · {{ $debutSlot }} → {{ $finSlot }} · {{ $slot->room?->code ?? 'sans salle' }}</p>
                                        <p class="mt-0.5 flex items-center gap-1.5 text-[11px] text-slate-500">
                                            @if($slot->status === 'cancelled')<span aria-hidden="true">⊘</span> Annulé
                                            @elseif($slot->status === 'modified')<span aria-hidden="true">⚠</span> Modifié
                                            @elseif($slot->status === 'completed')<span aria-hidden="true">✓</span> Terminé
                                            @else<span aria-hidden="true">●</span> {{ $slot->teacher?->nomComplet() ?? 'Sans enseignant' }}
                                            @endif
                                        </p>
                                        @if($enCours)
                                            <p class="mt-1 text-[11px] font-bold text-primary-800">Fin dans {{ (int) max(1, ceil(now()->diffInMinutes(\Carbon\Carbon::parse($finSlot)))) }} min</p>
                                        @endif
                                    </button>
                                </li>
                            @endforeach
                        </ul>
                        @if($creneaux->where('day_of_week', $jour)->isEmpty())
                            <p class="px-1 py-3 text-[11px] text-slate-400">Aucun créneau.</p>
                        @endif
                    </section>
                @endforeach
            </div>
        @else
            <div class="mt-4"><x-empty-state message="Aucun créneau ne correspond à ces critères." /></div>
        @endif

        {{-- Panneau détail du créneau --}}
        <x-modal titre="Détails du cours" nom="detail">
            <template x-if="detail">
                <dl class="space-y-1.5 text-sm">
                    <div class="flex justify-between gap-3"><dt class="text-slate-500">Matière</dt><dd class="font-black text-slate-900" x-text="detail.matiere"></dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-slate-500">Groupe</dt><dd class="font-bold" x-text="detail.groupe"></dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-slate-500">Horaire</dt><dd class="font-bold tabular-nums" x-text="detail.horaire"></dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-slate-500">Salle</dt><dd class="font-bold" x-text="detail.salle"></dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-slate-500">Enseignant</dt><dd class="font-bold" x-text="detail.enseignant"></dd></div>
                </dl>
            </template>
            <x-slot:actions>
                <template x-if="detail">
                    <a :href="'{{ url('admin/emplois-du-temps') }}/' + detail.planning" class="rounded-xl bg-primary-800 px-4 py-2.5 text-xs font-bold text-white min-h-[44px] inline-flex items-center">Voir le planning</a>
                </template>
            </x-slot:actions>
        </x-modal>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const colonne = document.getElementById('colonne-aujourdhui');
            if (colonne) colonne.scrollIntoView({ behavior: 'smooth', block: 'nearest', inline: 'center' });
        });
    </script>
@endsection
