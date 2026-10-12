@extends('layouts.app')

@section('title', 'Générateur de fiche de présence — Scolarité')
@section('meta_description', 'Assistant de sélection de créneau, groupe et modèle de fiche de présence avec prévisualisation en direct.')

@section('content')
    <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 py-8 sm:py-10" x-data="feuilleGenerateur()">
        <!-- En-tête -->
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-8">
            <div>
                <a href="{{ route('admin.presences.index') }}" class="text-xs text-slate-500 hover:text-slate-800 font-semibold inline-flex items-center gap-1">
                    ← Retour aux présences
                </a>
                <h1 class="mt-2 text-2xl sm:text-3xl font-black tracking-tight text-slate-900">Générateur de fiche de présence</h1>
                <p class="text-sm text-slate-500 mt-1">
                    Sélectionnez un groupe ou une séance, choisissez le modèle adapté et prévisualisez la fiche avant impression.
                </p>
            </div>
            <div class="flex items-center gap-2">
                <x-btn href="{{ route('admin.presences.modeles.index') }}" variant="ghost">
                    Gérer les modèles
                </x-btn>
            </div>
        </div>

        @if($errors->any())
            <div class="mb-6 rounded-xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-800">
                <p class="font-bold">Impossible de générer le document :</p>
                <ul class="mt-1 list-disc list-inside text-xs">
                    @foreach($errors->all() as $err)
                        <li>{{ $err }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <div class="flex flex-col lg:flex-row gap-8 items-start">
            <!-- Colonne configuration -->
            <div class="w-full lg:w-[460px] xl:w-[480px] shrink-0 space-y-6 bg-white p-6 rounded-2xl border border-slate-200 shadow-sm">
                <!-- Étape 1 : Séance / Groupe -->
                <div>
                    <div class="flex items-center gap-2 mb-3">
                        <span class="flex items-center justify-center w-6 h-6 rounded-full bg-primary-100 text-primary-700 font-bold text-xs">1</span>
                        <h2 class="text-sm font-bold uppercase tracking-wider text-slate-800">Séance & Périmètre</h2>
                    </div>

                    <!-- Choix du mode : Créneau planning ou Sélection libre -->
                    <div class="space-y-3">
                        @if($creneaux->isNotEmpty())
                            <div>
                                <label for="slot_id" class="block text-xs font-semibold text-slate-600 mb-1">
                                    Créneau d'emploi du temps (recommandé)
                                </label>
                                <select id="slot_id" x-model="slotId" @change="onSlotChange()"
                                        class="w-full rounded-xl border border-slate-300 px-3 py-2 text-xs focus:border-primary-500">
                                    <option value="">Sélectionner un créneau existant...</option>
                                    @foreach($creneaux as $creneau)
                                        <option value="{{ $creneau->id }}"
                                                data-groupe="{{ $creneau->schedule?->groupe_id }}"
                                                data-matiere="{{ $creneau->matiere_id }}"
                                                data-type="{{ $creneau->type }}"
                                                data-horaire="{{ substr($creneau->start_time, 0, 5) }} - {{ substr($creneau->end_time, 0, 5) }}"
                                                data-enseignant="{{ $creneau->teacher?->nom_complet }}">
                                            {{ $creneau->schedule?->groupe?->code }} · {{ $creneau->matiere?->code ?? 'Séance' }} ({{ ucfirst($creneau->type) }} {{ substr($creneau->start_time, 0, 5) }}-{{ substr($creneau->end_time, 0, 5) }})
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                        @endif

                        <div>
                            <label for="groupe_id" class="block text-xs font-semibold text-slate-600 mb-1">
                                Groupe d'étudiants <span class="text-rose-500">*</span>
                            </label>
                            <select id="groupe_id" x-model="groupeId" required @change="mettreAJourApercu()"
                                    class="w-full rounded-xl border border-slate-300 px-3 py-2 text-xs focus:border-primary-500">
                                <option value="">Sélectionner un groupe...</option>
                                @foreach($groupes as $groupe)
                                    <option value="{{ $groupe->id }}">{{ $groupe->code }} — {{ $groupe->nom }} ({{ $groupe->faculte?->code }})</option>
                                @endforeach
                            </select>
                        </div>

                        <div class="grid grid-cols-2 gap-2">
                            <div>
                                <label for="date" class="block text-xs font-semibold text-slate-600 mb-1">Date</label>
                                <input type="date" id="date" x-model="date" @change="mettreAJourApercu()"
                                       class="w-full rounded-xl border border-slate-300 px-3 py-1.5 text-xs focus:border-primary-500">
                            </div>
                            <div>
                                <label for="seance" class="block text-xs font-semibold text-slate-600 mb-1">Horaire / Séance</label>
                                <input type="text" id="seance" x-model="seance" placeholder="Ex: 08:00 - 10:00" @input.debounce.500ms="mettreAJourApercu()"
                                       class="w-full rounded-xl border border-slate-300 px-3 py-1.5 text-xs focus:border-primary-500">
                            </div>
                        </div>

                        <div>
                            <label for="matiere_id" class="block text-xs font-semibold text-slate-600 mb-1">Matière / ECUE</label>
                            <select id="matiere_id" x-model="matiereId" @change="mettreAJourApercu()"
                                    class="w-full rounded-xl border border-slate-300 px-3 py-2 text-xs focus:border-primary-500">
                                <option value="">Toutes matières / Non spécifiée</option>
                                @foreach($matieres as $mat)
                                    <option value="{{ $mat->id }}">{{ $mat->code }} — {{ $mat->nom }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                </div>

                <!-- Étape 2 : Choix du modèle -->
                <div class="pt-5 border-t border-slate-200">
                    <div class="flex items-center justify-between mb-3">
                        <div class="flex items-center gap-2">
                            <span class="flex items-center justify-center w-6 h-6 rounded-full bg-primary-100 text-primary-700 font-bold text-xs">2</span>
                            <h2 class="text-sm font-bold uppercase tracking-wider text-slate-800">Modèle de fiche</h2>
                        </div>
                        <template x-if="modeleSuggere">
                            <span class="text-[11px] font-semibold text-emerald-700 bg-emerald-50 px-2 py-0.5 rounded-full border border-emerald-200">
                                Suggéré : <span x-text="modeleSuggereNom"></span>
                            </span>
                        </template>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-2.5 max-h-72 overflow-y-auto pr-1">
                        @foreach($modeles as $modele)
                            <label class="relative flex flex-col p-3 rounded-xl border cursor-pointer transition-all text-xs"
                                   :class="modeleId == {{ $modele->id }} ? 'border-primary-600 bg-primary-50/40 ring-1 ring-primary-500' : 'border-slate-200 hover:border-slate-300 bg-white'">
                                <div class="flex items-center justify-between">
                                    <div class="flex items-center gap-2">
                                        <input type="radio" name="modele_selection" value="{{ $modele->id }}" x-model="modeleId" @change="onModeleChange('{{ $modele->type }}')"
                                               class="w-3.5 h-3.5 text-primary-600 focus:ring-primary-500">
                                        <span class="font-bold text-slate-900">{{ $modele->name }}</span>
                                    </div>
                                    <span class="text-[10px] uppercase font-mono text-slate-400">{{ $modele->orientation() }}</span>
                                </div>
                                <p class="text-[11px] text-slate-500 mt-1 line-clamp-2">{{ $modele->description }}</p>
                            </label>
                        @endforeach
                    </div>
                </div>

                <!-- Étape 3 : Options complémentaires (si Examen) -->
                <div class="pt-5 border-t border-slate-200" x-show="modeleType === 'exam'" x-transition>
                    <div class="flex items-center gap-2 mb-3">
                        <span class="flex items-center justify-center w-6 h-6 rounded-full bg-rose-100 text-rose-700 font-bold text-xs">3</span>
                        <h2 class="text-sm font-bold uppercase tracking-wider text-slate-800">Paramètres d'examen</h2>
                    </div>
                    <div class="space-y-2">
                        <div class="grid grid-cols-2 gap-2">
                            <div>
                                <label class="block text-[11px] font-semibold text-slate-600 mb-0.5">Type d'examen</label>
                                <input type="text" x-model="typeExamen" placeholder="Ex: Examen Terminal" @input.debounce.500ms="mettreAJourApercu()"
                                       class="w-full rounded-xl border border-slate-300 px-2.5 py-1.5 text-xs">
                            </div>
                            <div>
                                <label class="block text-[11px] font-semibold text-slate-600 mb-0.5">Session</label>
                                <input type="text" x-model="sessionExamen" placeholder="Ex: Session Principale" @input.debounce.500ms="mettreAJourApercu()"
                                       class="w-full rounded-xl border border-slate-300 px-2.5 py-1.5 text-xs">
                            </div>
                        </div>
                        <div class="grid grid-cols-2 gap-2">
                            <div>
                                <label class="block text-[11px] font-semibold text-slate-600 mb-0.5">Surveillant 1</label>
                                <input type="text" x-model="surveillant1" placeholder="Nom du surveillant titulaire" @input.debounce.500ms="mettreAJourApercu()"
                                       class="w-full rounded-xl border border-slate-300 px-2.5 py-1.5 text-xs">
                            </div>
                            <div>
                                <label class="block text-[11px] font-semibold text-slate-600 mb-0.5">Surveillant 2</label>
                                <input type="text" x-model="surveillant2" placeholder="Nom du surveillant adjoint" @input.debounce.500ms="mettreAJourApercu()"
                                       class="w-full rounded-xl border border-slate-300 px-2.5 py-1.5 text-xs">
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Actions de génération -->
                <div class="pt-4 border-t border-slate-200 flex flex-col sm:flex-row gap-2">
                    <button type="button" @click="genererPdf()" :disabled="!groupeId"
                            class="flex-1 inline-flex items-center justify-center gap-2 px-4 py-2.5 rounded-xl font-bold text-xs bg-primary-700 text-white hover:bg-primary-800 disabled:opacity-50 disabled:cursor-not-allowed shadow-sm transition-all">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                        Télécharger en PDF (A4)
                    </button>
                    <button type="button" @click="imprimerApercu()" :disabled="!groupeId"
                            class="inline-flex items-center justify-center gap-1.5 px-4 py-2.5 rounded-xl font-bold text-xs bg-slate-100 text-slate-700 hover:bg-slate-200 disabled:opacity-50 disabled:cursor-not-allowed transition-all">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/></svg>
                        Imprimer
                    </button>
                </div>
            </div>

            <!-- Colonne prévisualisation en direct -->
            <div class="w-full flex-1 min-w-0 bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden flex flex-col h-[780px] lg:h-[840px] sticky top-20">
                <div class="px-5 py-3.5 border-b border-slate-100 bg-slate-50 flex items-center justify-between">
                    <div class="flex items-center gap-2">
                        <span class="w-2 h-2 rounded-full bg-emerald-500 animate-pulse"></span>
                        <span class="text-xs font-bold text-slate-700">Aperçu en direct de la fiche</span>
                    </div>
                    <span class="text-[11px] text-slate-400">Rendu fidèle à l'impression</span>
                </div>

                <div class="flex-1 bg-slate-100/60 p-3 overflow-auto flex justify-center">
                    <template x-if="chargement">
                        <div class="flex items-center justify-center h-full">
                            <span class="text-xs text-slate-400 font-semibold">Génération de l'aperçu...</span>
                        </div>
                    </template>
                    <iframe x-show="!chargement" id="apercuFrame" :src="urlApercu"
                            class="w-full h-full border-0 bg-white rounded-lg shadow-sm"
                            @load="chargement = false"></iframe>
                </div>
            </div>
        </div>
    </div>

    <script>
        function feuilleGenerateur() {
            return {
                slotId: '',
                groupeId: '{{ $groupes->first()?->id ?? '' }}',
                modeleId: '{{ request('template_id', $modeles->first()?->id ?? '') }}',
                modeleType: '{{ $modeles->first()?->type ?? 'classic' }}',
                modeleSuggere: null,
                modeleSuggereNom: '',
                date: '{{ now()->format('Y-m-d') }}',
                seance: '',
                matiereId: '',
                typeExamen: 'Examen Terminal',
                sessionExamen: 'Session Principale',
                surveillant1: '',
                surveillant2: '',
                urlApercu: '',
                chargement: false,

                init() {
                    this.mettreAJourApercu();
                },

                onSlotChange() {
                    const select = document.getElementById('slot_id');
                    const selected = select.options[select.selectedIndex];
                    if (!selected || !selected.value) return;

                    this.groupeId = selected.dataset.groupe || this.groupeId;
                    this.matiereId = selected.dataset.matiere || '';
                    this.seance = selected.dataset.horaire || '';

                    const type = selected.dataset.type;
                    if (type === 'td') {
                        this.suggererModeleParType('td');
                    } else if (type === 'tp') {
                        this.suggererModeleParType('tp');
                    } else if (type === 'exam') {
                        this.suggererModeleParType('exam');
                    } else {
                        this.suggererModeleParType('classic');
                    }

                    this.mettreAJourApercu();
                },

                suggererModeleParType(type) {
                    const modeles = @json($modeles);
                    const match = modeles.find(m => m.type === type);
                    if (match) {
                        this.modeleSuggere = match.id;
                        this.modeleSuggereNom = match.name;
                        this.modeleId = match.id;
                        this.modeleType = match.type;
                    }
                },

                onModeleChange(type) {
                    this.modeleType = type;
                    this.mettreAJourApercu();
                },

                construireParams() {
                    const p = new URLSearchParams();
                    if (this.groupeId) p.set('groupe_id', this.groupeId);
                    if (this.modeleId) p.set('template_id', this.modeleId);
                    if (this.slotId) p.set('schedule_slot_id', this.slotId);
                    if (this.matiereId) p.set('matiere_id', this.matiereId);
                    if (this.date) p.set('date', this.date);
                    if (this.seance) p.set('seance', this.seance);
                    if (this.typeExamen) p.set('type_examen', this.typeExamen);
                    if (this.sessionExamen) p.set('session', this.sessionExamen);
                    if (this.surveillant1) p.set('surveillant_1', this.surveillant1);
                    if (this.surveillant2) p.set('surveillant_2', this.surveillant2);
                    return p.toString();
                },

                mettreAJourApercu() {
                    if (!this.groupeId && !this.slotId) return;
                    this.chargement = true;
                    this.urlApercu = '{{ route('admin.presences.feuille.apercu') }}?' + this.construireParams();
                },

                genererPdf() {
                    window.location.href = '{{ route('admin.presences.feuille') }}?' + this.construireParams();
                },

                imprimerApercu() {
                    const frame = document.getElementById('apercuFrame');
                    if (frame && frame.contentWindow) {
                        frame.contentWindow.focus();
                        frame.contentWindow.print();
                    }
                }
            }
        }
    </script>
@endsection
