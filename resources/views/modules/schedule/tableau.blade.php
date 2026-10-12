@extends('layouts.app')

@section('title', 'Tableau déplaçable — ' . $planning->nom . ' — Scolarité')

@section('content')
    @php($joursFr = ['monday' => 'Lundi', 'tuesday' => 'Mardi', 'wednesday' => 'Mercredi', 'thursday' => 'Jeudi', 'friday' => 'Vendredi', 'saturday' => 'Samedi', 'sunday' => 'Dimanche'])
    <div class="mx-auto max-w-[88rem] px-4 sm:px-6 py-8"
         x-data="tableauDeplacement(@js(route('admin.emplois-du-temps.slots.verifier', ['slot' => '__ID__'])), @js(route('admin.emplois-du-temps.slots.deplacer', ['slot' => '__ID__'])), document.querySelector('meta[name=csrf-token]').content)">
        <x-page-header :titre="'Tableau : ' . $planning->nom" description="Glissez un créneau vers un autre jour, ou utilisez « Déplacer » (clavier et mobile). La vérification des conflits est immédiate, la sauvegarde toujours confirmée."
            :fil="['Emplois du temps' => route('admin.emplois-du-temps.index'), $planning->nom => route('admin.emplois-du-temps.show', $planning), 'Tableau' => '#']">
            <x-slot:actions>
                <x-btn variant="secondary" href="{{ route('admin.emplois-du-temps.show', $planning) }}">Grille détaillée</x-btn>
            </x-slot:actions>
        </x-page-header>

        <div x-show="message" x-cloak class="mt-4">
            <x-alert type="success" titre="Déplacement enregistré."><span x-text="message"></span></x-alert>
        </div>

        <div class="mt-4 grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-3">
            @foreach($jours as $jour)
                <section :aria-label="'{{ $joursFr[$jour] }}'" class="ds-card flex min-h-[180px] flex-col p-3"
                         :class="colonneSurvolee === '{{ $jour }}' ? 'border-primary-500 ring-2 ring-primary-500/30' : ''"
                         @dragover.prevent="colonneSurvolee = '{{ $jour }}'"
                         @dragleave="colonneSurvolee = null"
                         @drop.prevent="deposer('{{ $jour }}')">
                    <h2 class="px-1 pb-2 text-xs font-black uppercase tracking-wider text-slate-500">
                        {{ $joursFr[$jour] }}
                        <span class="ml-1 rounded-full bg-slate-100 px-2 py-0.5 tabular-nums">{{ $planning->slots->where('day_of_week', $jour)->count() }}</span>
                    </h2>
                    <ul class="space-y-2" aria-label="Créneaux du {{ strtolower($joursFr[$jour]) }}">
                        @foreach($planning->slots->where('day_of_week', $jour) as $slot)
                            <li>
                                <article draggable="true"
                                         @dragstart="prendre({{ $slot->id }}, @js($slot->matiere->nom), '{{ $slot->start_time }}', '{{ $slot->end_time }}', {{ $slot->room_id ?? 'null' }}); $event.dataTransfer.effectAllowed = 'move'"
                                         @dragend="colonneSurvolee = null"
                                         :class="deplace?.id === {{ $slot->id }} ? 'opacity-50' : ''"
                                         class="cursor-grab rounded-xl border border-slate-200 bg-white p-3 shadow-sm active:cursor-grabbing"
                                         :aria-label="'Créneau {{ $slot->matiere->nom }}, {{ strtolower($joursFr[$jour]) }} {{ $slot->start_time }}'">
                                    <p class="text-sm font-black text-slate-900">{{ $slot->matiere->nom }}</p>
                                    <p class="mt-0.5 text-[11px] font-bold text-slate-500 tabular-nums" data-horaire>
                                        {{ $slot->start_time }} → {{ $slot->end_time }} · {{ $slot->room?->code ?? 'sans salle' }}
                                    </p>
                                    <p class="mt-0.5 text-[11px] text-slate-500">{{ $slot->teacher?->nomComplet() ?? '—' }}</p>
                                    <div class="mt-2 flex items-center justify-between gap-2">
                                        <span class="inline-flex items-center gap-1.5 text-[11px] font-bold text-slate-600">
                                            @if($slot->status === 'cancelled') <span aria-hidden="true">❌</span> Annulé
                                            @elseif($slot->status === 'modified') <span aria-hidden="true">⚠</span> Modifié
                                            @elseif($slot->status === 'completed') <span aria-hidden="true">✓</span> Terminé
                                            @else <span aria-hidden="true">●</span> Planifié
                                            @endif
                                        </span>
                                        @if($peutDeplacer)
                                            <button type="button"
                                                    @click="ouvrirModale({{ $slot->id }}, @js($slot->matiere->nom), '{{ $jour }}', '{{ $slot->start_time }}', '{{ $slot->end_time }}', {{ $slot->room_id ?? 'null' }})"
                                                    class="rounded-lg border border-slate-200 px-2.5 py-1.5 text-[11px] font-bold text-primary-800 hover:bg-primary-50 min-h-[36px]">
                                                Déplacer
                                            </button>
                                        @endif
                                    </div>
                                </article>
                            </li>
                        @endforeach
                    </ul>
                    @if($planning->slots->where('day_of_week', $jour)->isEmpty())
                        <p class="px-1 py-3 text-[11px] text-slate-400">Aucun créneau — déposez ici.</p>
                    @endif
                </section>
            @endforeach
        </div>

        @if($peutDeplacer)
            <x-modal titre="Déplacer le créneau" nom="modaleOuverte">
                <div class="space-y-3">
                    <p class="text-sm"><strong x-text="deplace?.matiere"></strong></p>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div>
                            <label for="dep-jour" class="mb-1 block text-xs font-bold uppercase tracking-wider text-slate-500">Jour</label>
                            <select id="dep-jour" x-model="cible.jour" @change="reverifier()"
                                    class="w-full rounded-xl border border-slate-300 px-3 py-2.5 text-sm min-h-[44px]">
                                @foreach($jours as $jour)
                                    <option value="{{ $jour }}">{{ $joursFr[$jour] }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label for="dep-salle" class="mb-1 block text-xs font-bold uppercase tracking-wider text-slate-500">Salle</label>
                            <select id="dep-salle" x-model.number="cible.salle" @change="reverifier()"
                                    class="w-full rounded-xl border border-slate-300 px-3 py-2.5 text-sm min-h-[44px]">
                                <option :value="null">Conserver (actuelle)</option>
                                @foreach($salles as $salle)
                                    <option value="{{ $salle->id }}">{{ $salle->code }} ({{ $salle->capacite ?? '—' }} places)</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label for="dep-debut" class="mb-1 block text-xs font-bold uppercase tracking-wider text-slate-500">Début</label>
                            <input id="dep-debut" type="time" x-model="cible.debut" @change="reverifier()"
                                   class="w-full rounded-xl border border-slate-300 px-3 py-2.5 text-sm min-h-[44px]">
                        </div>
                        <div>
                            <label for="dep-fin" class="mb-1 block text-xs font-bold uppercase tracking-wider text-slate-500">Fin</label>
                            <input id="dep-fin" type="time" x-model="cible.fin" @change="reverifier()"
                                   class="w-full rounded-xl border border-slate-300 px-3 py-2.5 text-sm min-h-[44px]">
                        </div>
                    </div>
                    <div x-show="verification === 'ok'" class="rounded-xl border border-emerald-300 bg-emerald-50 px-3.5 py-2.5 text-xs font-bold text-emerald-900" role="status">
                        ✓ Créneau disponible — aucune règle métier violée.
                    </div>
                    <div x-show="verification === 'conflit'" class="rounded-xl border border-rose-300 bg-rose-50 px-3.5 py-2.5 text-xs text-rose-900" role="alert">
                        <p class="font-black">⚠ Conflit détecté — déplacement impossible :</p>
                        <ul class="mt-1 list-disc list-inside space-y-0.5">
                            <template x-for="conflit in conflits" :key="conflit"><li x-text="conflit"></li></template>
                        </ul>
                    </div>
                    <p x-show="verification === 'attente'" class="text-xs text-slate-500"><x-loading message="Vérification…" /></p>
                </div>
                <x-slot:actions>
                    <x-btn variant="secondary" type="button" @click="modaleOuverte = false">Annuler</x-btn>
                    <x-btn type="button" @click="confirmer()" ::disabled="verification !== 'ok' || envoi">
                        <span x-show="!envoi">Confirmer le déplacement</span>
                        <span x-show="envoi">Déplacement…</span>
                    </x-btn>
                </x-slot:actions>
            </x-modal>
        @endif
    </div>

    <script>
        function tableauDeplacement(urlVerifier, urlDeplacer, jetonCsrf) {
            return {
                deplace: null,
                colonneSurvolee: null,
                modaleOuverte: false,
                cible: { jour: 'monday', debut: '08:00', fin: '10:00', salle: null },
                verification: null,
                conflits: [],
                envoi: false,
                message: '',
                prendre(id, matiere, debut, fin, salle) {
                    this.deplace = { id, matiere };
                    this.cible = { jour: this.cible.jour, debut, fin, salle };
                },
                deposer(jour) {
                    if (!this.deplace) return;
                    this.colonneSurvolee = null;
                    this.cible.jour = jour;
                    this.ouvrirModale(this.deplace.id, this.deplace.matiere, jour, this.cible.debut, this.cible.fin, this.cible.salle);
                },
                ouvrirModale(id, matiere, jour, debut, fin, salle) {
                    this.deplace = { id, matiere };
                    this.cible = { jour, debut, fin, salle };
                    this.modaleOuverte = true;
                    this.reverifier();
                },
                async reverifier() {
                    if (!this.deplace || this.cible.debut >= this.cible.fin) {
                        this.verification = null;
                        this.conflits = this.cible.debut >= this.cible.fin ? ['L’heure de fin doit être postérieure à l’heure de début.'] : [];
                        if (this.cible.debut >= this.cible.fin) this.verification = 'conflit';
                        return;
                    }
                    this.verification = 'attente';
                    try {
                        const reponse = await fetch(urlVerifier.replace('__ID__', this.deplace.id), {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': jetonCsrf, 'Accept': 'application/json' },
                            body: JSON.stringify({ day_of_week: this.cible.jour, start_time: this.cible.debut, end_time: this.cible.fin, room_id: this.cible.salle }),
                        });
                        const donnees = await reponse.json();
                        if (reponse.status === 422 || !donnees.disponible) {
                            this.verification = 'conflit';
                            this.conflits = donnees.conflits || Object.values(donnees.errors || {}).flat();
                        } else {
                            this.verification = 'ok';
                            this.conflits = [];
                        }
                    } catch (e) {
                        this.verification = 'conflit';
                        this.conflits = ['Vérification impossible : réessayez.'];
                    }
                },
                async confirmer() {
                    if (this.verification !== 'ok' || this.envoi) return;
                    this.envoi = true;
                    try {
                        const reponse = await fetch(urlDeplacer.replace('__ID__', this.deplace.id), {
                            method: 'PATCH',
                            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': jetonCsrf, 'Accept': 'application/json' },
                            body: JSON.stringify({ day_of_week: this.cible.jour, start_time: this.cible.debut, end_time: this.cible.fin, room_id: this.cible.salle }),
                        });
                        const donnees = await reponse.json();
                        if (!reponse.ok) {
                            this.verification = 'conflit';
                            this.conflits = donnees.conflits || [donnees.message || 'Déplacement refusé.'];
                            return;
                        }
                        this.modaleOuverte = false;
                        this.message = (donnees.slot ? donnees.message + ' ' : 'Créneau déplacé.') + ' Rechargez pour voir le planning à jour.';
                        window.scrollTo({ top: 0, behavior: 'smooth' });
                    } finally {
                        this.envoi = false;
                    }
                },
            };
        }
    </script>
@endsection
