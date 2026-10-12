<div class="space-y-6 w-full">
    {{-- Notifications de statut et d'erreur --}}
    @if($statusMessage)
        <div class="flex items-center justify-between gap-3 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-800 shadow-sm" role="status">
            <div class="flex items-center gap-2">
                <svg class="h-5 w-5 text-emerald-600 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                </svg>
                <span>{{ $statusMessage }}</span>
            </div>
            <button type="button" wire:click="$set('statusMessage', null)" class="text-emerald-600 hover:text-emerald-900 text-sm">✕</button>
        </div>
    @endif

    @if($errorMessage)
        <div class="flex items-center justify-between gap-3 rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-semibold text-rose-800 shadow-sm" role="alert">
            <div class="flex items-center gap-2">
                <svg class="h-5 w-5 text-rose-600 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                </svg>
                <span>{{ $errorMessage }}</span>
            </div>
            <button type="button" wire:click="$set('errorMessage', null)" class="text-rose-600 hover:text-rose-900 text-sm">✕</button>
        </div>
    @endif

    {{-- Layout principal : 30% sidebar (w-80 à w-96) / 70% builder cockpit (flex-1) --}}
    <div class="flex flex-col lg:flex-row gap-6 items-start w-full">
        
        {{-- ── COLONNE GAUCHE (30% : fixe 340px sur desktop, pleine largeur sur mobile) ── --}}
        <aside class="w-full lg:w-80 xl:w-96 lg:flex-shrink-0 space-y-4">
            <div class="rounded-2xl border border-slate-200 bg-white p-4 sm:p-5 shadow-sm space-y-4">
                <div class="flex items-center justify-between gap-2 border-b border-slate-100 pb-3">
                    <div>
                        <h2 class="text-base font-black tracking-tight text-slate-900">Modèles de présence</h2>
                        <p class="text-xs text-slate-500">{{ $templates->count() }} gabarit(s) disponible(s)</p>
                    </div>
                    @can('attendance.templates.manage')
                        <button type="button" wire:click="createNewTemplate"
                                class="inline-flex items-center gap-1.5 rounded-xl bg-primary-800 px-3 py-1.5 text-xs font-bold text-white shadow-sm hover:bg-primary-900 transition-colors focus:ring-2 focus:ring-primary-500">
                            <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 4v16m8-8H4"/>
                            </svg>
                            <span>Nouveau</span>
                        </button>
                    @endcan
                </div>

                {{-- Filtres et recherche rapide --}}
                <div class="space-y-2.5">
                    <div>
                        <label for="search-input" class="sr-only">Rechercher</label>
                        <div class="relative">
                            <span class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3 text-slate-400">
                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                                </svg>
                            </span>
                            <input id="search-input" type="text" wire:model.live.debounce.250ms="search" placeholder="Rechercher nom ou code..."
                                   class="w-full rounded-xl border border-slate-200 bg-slate-50/50 py-2 pl-9 pr-3 text-xs placeholder:text-slate-400 focus:bg-white focus:border-primary-500 focus:ring-1 focus:ring-primary-500">
                        </div>
                    </div>

                    <div class="grid grid-cols-2 gap-2">
                        <select wire:model.live="typeFilter" aria-label="Filtrer par type" class="rounded-lg border border-slate-200 bg-white px-2 py-1.5 text-[11px] text-slate-700">
                            <option value="">Tous types</option>
                            @foreach($types as $cle => $libelle)
                                <option value="{{ $cle }}">{{ $libelle }}</option>
                            @endforeach
                        </select>
                        <select wire:model.live="systemFilter" aria-label="Filtrer par origine" class="rounded-lg border border-slate-200 bg-white px-2 py-1.5 text-[11px] text-slate-700">
                            <option value="">Tous gabarits</option>
                            <option value="1">Système</option>
                            <option value="0">Personnalisés</option>
                        </select>
                    </div>
                </div>

                {{-- Liste défilable des modèles --}}
                <div class="max-h-[640px] overflow-y-auto space-y-2 pr-1 divide-y divide-slate-100">
                    @forelse($templates as $tmpl)
                        <div wire:key="tmpl-card-{{ $tmpl->id }}"
                             wire:click="selectTemplate({{ $tmpl->id }})"
                             class="group cursor-pointer rounded-xl p-3 pt-3.5 transition-all text-left {{ $selectedTemplateId === $tmpl->id ? 'bg-primary-50/70 border-2 border-primary-600 shadow-sm' : 'hover:bg-slate-50 border border-transparent' }}">
                            <div class="flex items-start justify-between gap-2">
                                <div class="min-w-0 flex-1">
                                    <div class="flex items-center gap-1.5 flex-wrap">
                                        <span class="font-bold text-xs {{ $selectedTemplateId === $tmpl->id ? 'text-primary-950' : 'text-slate-800' }} truncate">
                                            {{ $tmpl->name }}
                                        </span>
                                        @if($tmpl->is_system)
                                            <span class="inline-flex items-center gap-0.5 rounded px-1.5 py-0.5 text-[10px] font-semibold bg-indigo-50 text-indigo-700 border border-indigo-200" title="Gabarit officiel protégé">
                                                Système
                                            </span>
                                        @else
                                            <span class="inline-flex items-center rounded px-1.5 py-0.5 text-[10px] font-semibold bg-amber-50 text-amber-700 border border-amber-200">
                                                Custom
                                            </span>
                                        @endif
                                    </div>
                                    <p class="font-mono text-[10px] text-slate-400 mt-0.5">{{ $tmpl->code }}</p>
                                    @if($tmpl->description)
                                        <p class="text-[11px] text-slate-500 line-clamp-1 mt-1">{{ $tmpl->description }}</p>
                                    @endif
                                </div>

                                {{-- Indicateur statut --}}
                                <span class="h-2 w-2 rounded-full flex-shrink-0 mt-1 {{ $tmpl->is_active ? 'bg-emerald-500' : 'bg-rose-400' }}" title="{{ $tmpl->is_active ? 'Actif' : 'Inactif' }}"></span>
                            </div>

                            <div class="mt-2.5 flex items-center justify-between text-[11px] text-slate-500 pt-2 border-t border-slate-100/60">
                                <span class="capitalize">{{ $types[$tmpl->type] ?? $tmpl->type }} · {{ $tmpl->orientation() }}</span>
                                <span class="text-primary-700 font-semibold group-hover:underline">Éditer →</span>
                            </div>
                        </div>
                    @empty
                        <div class="p-6 text-center text-xs text-slate-400">
                            Aucun modèle trouvé pour ces filtres.
                        </div>
                    @endforelse
                </div>
            </div>
        </aside>

        {{-- ── COLONNE DROITE (70% : prend tout le reste sans jamais rétrécir) ── --}}
        <main class="w-full lg:flex-1 min-w-0 space-y-5">
            {{-- Barre supérieure du modèle actif --}}
            <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm space-y-4">
                <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-4 border-b border-slate-100 pb-4">
                    <div class="min-w-0 flex-1">
                        <div class="flex items-center gap-2 flex-wrap">
                            <span class="text-[11px] font-bold uppercase tracking-wider text-slate-400">
                                {{ $is_system ? 'Modèle système officiel' : 'Gabarit personnalisé' }}
                            </span>
                            @if($selectedTemplateId)
                                <span class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[10px] font-bold {{ $is_active ? 'bg-emerald-50 text-emerald-700' : 'bg-rose-50 text-rose-700' }}">
                                    <span class="h-1.5 w-1.5 rounded-full {{ $is_active ? 'bg-emerald-500' : 'bg-rose-500' }}"></span>
                                    {{ $is_active ? 'Actif' : 'Inactif' }}
                                </span>
                            @else
                                <span class="inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-bold bg-sky-50 text-sky-700">
                                    Nouveau (non sauvegardé)
                                </span>
                            @endif
                        </div>
                        <h1 class="text-xl sm:text-2xl font-black tracking-tight text-slate-900 mt-1">
                            {{ $name ?: 'Nouveau modèle' }}
                        </h1>
                        <p class="font-mono text-xs text-slate-400 mt-0.5">{{ $code }}</p>
                    </div>

                    {{-- Actions principales --}}
                    <div class="flex items-center gap-2 flex-wrap sm:flex-nowrap flex-shrink-0">
                        {{-- Bouton Aperçu PDF --}}
                        @if($selectedTemplateId)
                            <a href="{{ route('admin.presences.modeles.apercu-pdf', $selectedTemplateId) }}" target="_blank"
                               class="inline-flex items-center gap-1.5 rounded-xl border border-slate-300 bg-white px-3.5 py-2 text-xs font-bold text-slate-700 shadow-sm hover:bg-slate-50 transition-colors"
                               title="Générer et prévisualiser le document PDF via Dompdf">
                                <svg class="h-4 w-4 text-rose-600" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M4 4a2 2 0 012-2h4.586A2 2 0 0112 2.586L15.414 6A2 2 0 0116 7.414V16a2 2 0 01-2 2H6a2 2 0 01-2-2V4zm2 6a1 1 0 011-1h6a1 1 0 110 2H7a1 1 0 01-1-1zm1 3a1 1 0 100 2h6a1 1 0 100-2H7z" clip-rule="evenodd"/>
                                </svg>
                                <span>Aperçu PDF</span>
                            </a>
                        @endif

                        @can('attendance.templates.manage')
                            {{-- Bouton Dupliquer --}}
                            @if($selectedTemplateId)
                                <button type="button" wire:click="duplicate"
                                        class="inline-flex items-center gap-1.5 rounded-xl border border-slate-300 bg-white px-3 py-2 text-xs font-bold text-slate-700 shadow-sm hover:bg-slate-50 transition-colors"
                                        title="Créer une copie modifiable de ce gabarit">
                                    <svg class="h-4 w-4 text-slate-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"/>
                                    </svg>
                                    <span>Dupliquer</span>
                                </button>
                            @endif

                            {{-- Bouton Toggle Actif --}}
                            @if($selectedTemplateId)
                                <button type="button" wire:click="toggleActive"
                                        class="inline-flex items-center gap-1.5 rounded-xl border border-slate-300 bg-white px-3 py-2 text-xs font-bold text-slate-700 shadow-sm hover:bg-slate-50 transition-colors">
                                    <span>{{ $is_active ? 'Désactiver' : 'Activer' }}</span>
                                </button>
                            @endif

                            {{-- Bouton Enregistrer --}}
                            <button type="button" wire:click="save"
                                    class="inline-flex items-center gap-1.5 rounded-xl bg-primary-800 px-4 py-2 text-xs font-bold text-white shadow-sm hover:bg-primary-900 transition-colors focus:ring-2 focus:ring-primary-500">
                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.2" d="M5 13l4 4L19 7"/>
                                </svg>
                                <span>Enregistrer</span>
                            </button>

                            {{-- Supprimer (custom uniquement) --}}
                            @if($selectedTemplateId && ! $is_system)
                                <button type="button" wire:click="deleteTemplate"
                                        wire:confirm="Confirmer la suppression définitive de ce modèle personnalisé ?"
                                        class="inline-flex items-center rounded-xl p-2 text-rose-600 hover:bg-rose-50 transition-colors"
                                        title="Supprimer le modèle">
                                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                                    </svg>
                                </button>
                            @endif
                        @endcan
                    </div>
                </div>

                {{-- Onglets cockpit : Structure & Blocs vs Aperçu Live (Rendu Blade robuste) --}}
                <div class="flex items-center gap-2 border-b border-slate-100 pb-2">
                    <button type="button" wire:click="$set('activeTab', 'editor')"
                            class="rounded-xl px-4 py-2 text-xs font-bold transition-colors {{ $activeTab === 'editor' ? 'bg-primary-800 text-white shadow-sm' : 'text-slate-600 hover:bg-slate-100' }}">
                        1. Structure & Blocs (Builder)
                    </button>
                    <button type="button" wire:click="$set('activeTab', 'preview')"
                            class="rounded-xl px-4 py-2 text-xs font-bold transition-colors {{ $activeTab === 'preview' ? 'bg-primary-800 text-white shadow-sm' : 'text-slate-600 hover:bg-slate-100' }}">
                        2. Aperçu direct (Données fictives)
                    </button>
                </div>
            </div>

            {{-- ── TAB 1 : BUILDER (STRUCTURE, DRAG & DROP, OPTIONS DU BLOC) ── --}}
            @if($activeTab === 'editor')
                <div class="space-y-5">
                    {{-- Paramètres généraux du modèle --}}
                    <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm space-y-4">
                        <h3 class="text-xs font-bold uppercase tracking-wider text-slate-400">Paramètres généraux du document</h3>
                        
                        <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-4 gap-4">
                            <div class="sm:col-span-2">
                                <label class="block text-xs font-bold text-slate-700">Nom du modèle</label>
                                <input type="text" wire:model.live.debounce.300ms="name"
                                       class="mt-1 w-full rounded-xl border border-slate-300 px-3 py-2 text-xs focus:border-primary-500 focus:ring-1 focus:ring-primary-500">
                                @error('name') <span class="text-[11px] text-rose-600">{{ $message }}</span> @enderror
                            </div>

                            <div>
                                <label class="block text-xs font-bold text-slate-700">Code unique</label>
                                <input type="text" wire:model.live.debounce.300ms="code"
                                       class="mt-1 w-full rounded-xl border border-slate-300 px-3 py-2 text-xs font-mono focus:border-primary-500 focus:ring-1 focus:ring-primary-500">
                                @error('code') <span class="text-[11px] text-rose-600">{{ $message }}</span> @enderror
                            </div>

                            <div>
                                <label class="block text-xs font-bold text-slate-700">Orientation</label>
                                <select wire:model.live="orientation" class="mt-1 w-full rounded-xl border border-slate-300 px-3 py-2 text-xs">
                                    <option value="portrait">Portrait (Vertical)</option>
                                    <option value="landscape">Paysage (Horizontal)</option>
                                </select>
                            </div>
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-4 gap-4">
                            <div>
                                <label class="block text-xs font-bold text-slate-700">Format papier</label>
                                <select wire:model.live="paper" class="mt-1 w-full rounded-xl border border-slate-300 px-3 py-2 text-xs">
                                    <option value="A4">A4 (210 × 297 mm)</option>
                                    <option value="A3">A3 (297 × 420 mm)</option>
                                    <option value="Letter">Letter US</option>
                                </select>
                            </div>

                            <div class="sm:col-span-3">
                                <label class="block text-xs font-bold text-slate-700">Description / Usage</label>
                                <input type="text" wire:model.live.debounce.300ms="description" placeholder="Ex: Feuille officielle pour examens terminaux..."
                                       class="mt-1 w-full rounded-xl border border-slate-300 px-3 py-2 text-xs focus:border-primary-500 focus:ring-1 focus:ring-primary-500">
                            </div>
                        </div>
                    </div>

                    {{-- Structure modulaire : Drag & Drop des blocs + Inspecteur d'options --}}
                    <div class="flex flex-col xl:flex-row gap-5 items-start w-full">
                        
                        {{-- Colonne des Blocs (xl:w-5/12) avec Alpine Drag & Drop --}}
                        <div class="w-full xl:w-5/12 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm space-y-4 flex-shrink-0">
                            <div class="flex items-center justify-between gap-2 border-b border-slate-100 pb-3">
                                <div>
                                    <h3 class="text-xs font-bold uppercase tracking-wider text-slate-400">Blocs du document</h3>
                                    <p class="text-[11px] text-slate-500">Glisser-déposer pour ordonner</p>
                                </div>
                                <span class="rounded-full bg-slate-100 px-2 py-0.5 text-[10px] font-bold text-slate-600">
                                    {{ count($blocks) }} blocs
                                </span>
                            </div>

                            {{-- Conteneur Alpine Drag & Drop sans dépendance externe --}}
                            <div x-data="{
                                    draggedIndex: null,
                                    overIndex: null,
                                    onDragStart(idx, ev) {
                                        this.draggedIndex = idx;
                                        ev.dataTransfer.effectAllowed = 'move';
                                        ev.dataTransfer.setData('text/plain', idx);
                                    },
                                    onDragOver(idx, ev) {
                                        ev.preventDefault();
                                        this.overIndex = idx;
                                    },
                                    onDrop(idx, ev, wire = null) {
                                        ev.preventDefault();
                                        const w = wire || this.$wire;
                                        if (this.draggedIndex !== null && this.draggedIndex !== idx && w) {
                                            w.moveBlock(this.draggedIndex, idx);
                                        }
                                        this.draggedIndex = null;
                                        this.overIndex = null;
                                    },
                                    onDragEnd() {
                                        this.draggedIndex = null;
                                        this.overIndex = null;
                                    }
                                 }"
                                 class="space-y-2">
                                
                                @foreach($blocks as $index => $bloc)
                                    @php
                                        $estActif = $bloc['enabled'] ?? true;
                                        $typeNom = match($bloc['type']) {
                                            'header' => '1. En-tête officiel',
                                            'metadata' => '2. Informations séance',
                                            'student_table' => '3. Tableau des présences',
                                            'statistics' => '4. Statistiques séance',
                                            'signature' => '5. Signatures & Visas',
                                            'qr' => '6. Sécurité QR Code',
                                            'footer' => '7. Pied de page',
                                            default => ucfirst($bloc['type']),
                                        };
                                    @endphp
                                    <div wire:key="bloc-card-{{ $bloc['type'] }}-{{ $index }}"
                                         draggable="true"
                                         @dragstart="onDragStart({{ $index }}, $event)"
                                         @dragover="onDragOver({{ $index }}, $event)"
                                         @drop="onDrop({{ $index }}, $event, $wire)"
                                         @dragend="onDragEnd()"
                                         :class="{
                                             'border-primary-500 bg-primary-50/50': overIndex === {{ $index }},
                                             'opacity-40': draggedIndex === {{ $index }}
                                         }"
                                         class="group rounded-xl border p-2.5 transition-all {{ $selectedBlockIndex === $index ? 'border-primary-600 bg-primary-50/40 shadow-sm ring-1 ring-primary-500' : 'border-slate-200 bg-white hover:border-slate-300' }}">
                                        
                                        <div class="flex items-center justify-between gap-2">
                                            <div class="flex items-center gap-2 min-w-0 flex-1 cursor-pointer" wire:click="selectBlock({{ $index }})">
                                                {{-- Handle de drag --}}
                                                <span class="cursor-grab active:cursor-grabbing text-slate-400 hover:text-slate-700 p-0.5" title="Glisser pour réorganiser">
                                                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 8h16M4 16h16"/>
                                                    </svg>
                                                </span>

                                                <div class="min-w-0 flex-1">
                                                    <span class="block text-xs font-bold {{ $estActif ? 'text-slate-800' : 'text-slate-400 line-through' }} truncate">
                                                        {{ $typeNom }}
                                                    </span>
                                                    <span class="block text-[10px] font-mono text-slate-400">pos: {{ $bloc['position'] }}</span>
                                                </div>
                                            </div>

                                            {{-- Contrôles du bloc : monter, descendre, toggle actif, supprimer --}}
                                            <div class="flex items-center gap-1 flex-shrink-0">
                                                <button type="button" wire:click="moveBlockUp({{ $index }})"
                                                        @disabled($index === 0)
                                                        class="rounded p-1 text-slate-400 hover:text-slate-700 disabled:opacity-20 text-[10px]" title="Monter">
                                                    ▲
                                                </button>
                                                <button type="button" wire:click="moveBlockDown({{ $index }})"
                                                        @disabled($index === count($blocks) - 1)
                                                        class="rounded p-1 text-slate-400 hover:text-slate-700 disabled:opacity-20 text-[10px]" title="Descendre">
                                                    ▼
                                                </button>
                                                <button type="button" wire:click="toggleBlock({{ $index }})"
                                                        class="rounded px-1.5 py-0.5 text-[10px] font-bold {{ $estActif ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-400' }}"
                                                        title="{{ $estActif ? 'Désactiver le bloc' : 'Activer le bloc' }}">
                                                    {{ $estActif ? 'Actif' : 'Masqué' }}
                                                </button>
                                                <button type="button" wire:click="removeBlock({{ $index }})"
                                                        class="rounded p-1 text-slate-300 hover:text-rose-600 transition-colors"
                                                        title="Retirer ce bloc">
                                                    ✕
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                @endforeach
                            </div>

                            {{-- Menu d'ajout de bloc manquant --}}
                            <div class="pt-2 border-t border-slate-100">
                                <label class="block text-[11px] font-bold text-slate-600 mb-1">Ajouter un bloc disponible</label>
                                <div x-data="{ typeAajouter: '{{ $allowedBlockTypes[0] ?? '' }}' }" class="flex items-center gap-1.5">
                                    <select x-model="typeAajouter" class="flex-1 rounded-xl border border-slate-300 px-2 py-1.5 text-xs">
                                        @foreach($allowedBlockTypes as $typeDispo)
                                            <option value="{{ $typeDispo }}">{{ ucfirst(str_replace('_', ' ', $typeDispo)) }}</option>
                                        @endforeach
                                    </select>
                                    <button type="button" @click="$wire.addBlock(typeAajouter)"
                                            class="rounded-xl bg-slate-100 px-3 py-1.5 text-xs font-bold text-slate-700 hover:bg-slate-200 transition-colors">
                                        + Ajouter
                                    </button>
                                </div>
                            </div>
                        </div>

                        {{-- Colonne Inspecteur d'options du bloc sélectionné (xl:w-7/12) --}}
                        <div class="w-full xl:w-7/12 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm space-y-4 flex-1">
                            @if(isset($blocks[$selectedBlockIndex]))
                                @php
                                    $blocEnCours = $blocks[$selectedBlockIndex];
                                    $configBloc = $blocEnCours['config'] ?? [];
                                    $typeBloc = $blocEnCours['type'];
                                @endphp

                                <div class="flex items-center justify-between gap-2 border-b border-slate-100 pb-3">
                                    <div>
                                        <h3 class="text-xs font-bold uppercase tracking-wider text-slate-400">Configuration du bloc</h3>
                                        <h4 class="text-sm font-black text-slate-900 mt-0.5">
                                            {{ ucfirst(str_replace('_', ' ', $typeBloc)) }} (Position {{ $blocEnCours['position'] }})
                                        </h4>
                                    </div>
                                    <span class="rounded px-2 py-0.5 text-xs font-bold {{ ($blocEnCours['enabled'] ?? true) ? 'bg-emerald-50 text-emerald-700' : 'bg-rose-50 text-rose-700' }}">
                                        {{ ($blocEnCours['enabled'] ?? true) ? 'Visible à l\'impression' : 'Masqué' }}
                                    </span>
                                </div>

                                {{-- Formulaire spécifique selon le type de bloc --}}
                                @if($typeBloc === 'header')
                                    <div class="space-y-3">
                                        <p class="text-xs text-slate-500">Personnalisez les mentions administratives de l'en-tête officiel.</p>
                                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-2 text-xs">
                                            <label class="flex items-center gap-2 p-2 rounded-lg border border-slate-200 hover:bg-slate-50 cursor-pointer">
                                                <input type="checkbox" wire:click="updateBlockOption('show_institution', {{ ($configBloc['show_institution'] ?? true) ? 'false' : 'true' }})"
                                                       @checked($configBloc['show_institution'] ?? true) class="rounded text-primary-600">
                                                <span>Nom institution</span>
                                            </label>
                                            <label class="flex items-center gap-2 p-2 rounded-lg border border-slate-200 hover:bg-slate-50 cursor-pointer">
                                                <input type="checkbox" wire:click="updateBlockOption('show_faculte', {{ ($configBloc['show_faculte'] ?? true) ? 'false' : 'true' }})"
                                                       @checked($configBloc['show_faculte'] ?? true) class="rounded text-primary-600">
                                                <span>Faculté</span>
                                            </label>
                                            <label class="flex items-center gap-2 p-2 rounded-lg border border-slate-200 hover:bg-slate-50 cursor-pointer">
                                                <input type="checkbox" wire:click="updateBlockOption('show_departement', {{ ($configBloc['show_departement'] ?? true) ? 'false' : 'true' }})"
                                                       @checked($configBloc['show_departement'] ?? true) class="rounded text-primary-600">
                                                <span>Département</span>
                                            </label>
                                            <label class="flex items-center gap-2 p-2 rounded-lg border border-slate-200 hover:bg-slate-50 cursor-pointer">
                                                <input type="checkbox" wire:click="updateBlockOption('show_reference', {{ ($configBloc['show_reference'] ?? true) ? 'false' : 'true' }})"
                                                       @checked($configBloc['show_reference'] ?? true) class="rounded text-primary-600">
                                                <span>N° référence document</span>
                                            </label>
                                        </div>
                                        <div>
                                            <label class="block text-xs font-bold text-slate-700">Alignement du titre</label>
                                            <select wire:change="updateBlockOption('title_alignment', $event.target.value)" class="mt-1 w-full rounded-xl border border-slate-300 px-3 py-2 text-xs">
                                                <option value="right" @selected(($configBloc['title_alignment'] ?? 'right') === 'right')>Droite</option>
                                                <option value="center" @selected(($configBloc['title_alignment'] ?? '') === 'center')>Centré</option>
                                                <option value="left" @selected(($configBloc['title_alignment'] ?? '') === 'left')>Gauche</option>
                                            </select>
                                        </div>
                                    </div>

                                @elseif($typeBloc === 'metadata')
                                    <div class="space-y-3">
                                        <p class="text-xs text-slate-500">Champs d'informations de la séance (cartouche supérieur).</p>
                                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-2 text-xs">
                                            <label class="flex items-center gap-2 p-2 rounded-lg border border-slate-200 hover:bg-slate-50 cursor-pointer">
                                                <input type="checkbox" wire:click="updateBlockOption('show_groupe', {{ ($configBloc['show_groupe'] ?? true) ? 'false' : 'true' }})"
                                                       @checked($configBloc['show_groupe'] ?? true) class="rounded text-primary-600">
                                                <span>Groupe / Classe</span>
                                            </label>
                                            <label class="flex items-center gap-2 p-2 rounded-lg border border-slate-200 hover:bg-slate-50 cursor-pointer">
                                                <input type="checkbox" wire:click="updateBlockOption('show_matiere', {{ ($configBloc['show_matiere'] ?? true) ? 'false' : 'true' }})"
                                                       @checked($configBloc['show_matiere'] ?? true) class="rounded text-primary-600">
                                                <span>Matière / ECUE</span>
                                            </label>
                                            <label class="flex items-center gap-2 p-2 rounded-lg border border-slate-200 hover:bg-slate-50 cursor-pointer">
                                                <input type="checkbox" wire:click="updateBlockOption('show_enseignant', {{ ($configBloc['show_enseignant'] ?? true) ? 'false' : 'true' }})"
                                                       @checked($configBloc['show_enseignant'] ?? true) class="rounded text-primary-600">
                                                <span>Enseignant</span>
                                            </label>
                                            <label class="flex items-center gap-2 p-2 rounded-lg border border-slate-200 hover:bg-slate-50 cursor-pointer">
                                                <input type="checkbox" wire:click="updateBlockOption('show_date', {{ ($configBloc['show_date'] ?? true) ? 'false' : 'true' }})"
                                                       @checked($configBloc['show_date'] ?? true) class="rounded text-primary-600">
                                                <span>Date</span>
                                            </label>
                                            <label class="flex items-center gap-2 p-2 rounded-lg border border-slate-200 hover:bg-slate-50 cursor-pointer">
                                                <input type="checkbox" wire:click="updateBlockOption('show_horaire', {{ ($configBloc['show_horaire'] ?? true) ? 'false' : 'true' }})"
                                                       @checked($configBloc['show_horaire'] ?? true) class="rounded text-primary-600">
                                                <span>Horaire séance</span>
                                            </label>
                                            <label class="flex items-center gap-2 p-2 rounded-lg border border-slate-200 hover:bg-slate-50 cursor-pointer">
                                                <input type="checkbox" wire:click="updateBlockOption('show_salle', {{ ($configBloc['show_salle'] ?? true) ? 'false' : 'true' }})"
                                                       @checked($configBloc['show_salle'] ?? true) class="rounded text-primary-600">
                                                <span>Salle</span>
                                            </label>
                                        </div>
                                        <div>
                                            <label class="block text-xs font-bold text-slate-700">Disposition</label>
                                            <select wire:change="updateBlockOption('layout', $event.target.value)" class="mt-1 w-full rounded-xl border border-slate-300 px-3 py-2 text-xs">
                                                <option value="grid_4" @selected(($configBloc['layout'] ?? 'grid_4') === 'grid_4')>Grille compacte (4 colonnes)</option>
                                                <option value="grid_2" @selected(($configBloc['layout'] ?? '') === 'grid_2')>Grille aérée (2 colonnes)</option>
                                            </select>
                                        </div>
                                    </div>

                                @elseif($typeBloc === 'student_table')
                                    <div class="space-y-4">
                                        <p class="text-xs text-slate-500">Configuration des colonnes et styles du tableau des étudiants (§7).</p>
                                        
                                        {{-- Colonnes configurables --}}
                                        <div class="space-y-2">
                                            <span class="block text-xs font-bold text-slate-700">Colonnes visibles :</span>
                                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-2 text-xs">
                                                <label class="flex items-center gap-2 p-2 rounded-lg border border-slate-200 hover:bg-slate-50 cursor-pointer">
                                                    <input type="checkbox" wire:click="updateBlockOption('show_matricule', {{ ($configBloc['show_matricule'] ?? true) ? 'false' : 'true' }})"
                                                           @checked($configBloc['show_matricule'] ?? true) class="rounded text-primary-600">
                                                    <span>☑ Matricule</span>
                                                </label>
                                                <label class="flex items-center gap-2 p-2 rounded-lg border border-slate-200 hover:bg-slate-50 cursor-pointer">
                                                    <input type="checkbox" wire:click="updateBlockOption('show_cin', {{ ($configBloc['show_cin'] ?? false) ? 'false' : 'true' }})"
                                                           @checked($configBloc['show_cin'] ?? false) class="rounded text-primary-600">
                                                    <span>CIN étudiant</span>
                                                </label>
                                                <label class="flex items-center gap-2 p-2 rounded-lg border border-slate-200 hover:bg-slate-50 cursor-pointer">
                                                    <input type="checkbox" wire:click="updateBlockOption('show_presence', {{ ($configBloc['show_presence'] ?? true) ? 'false' : 'true' }})"
                                                           @checked($configBloc['show_presence'] ?? true) class="rounded text-primary-600">
                                                    <span>☑ Statut présence</span>
                                                </label>
                                                <label class="flex items-center gap-2 p-2 rounded-lg border border-slate-200 hover:bg-slate-50 cursor-pointer">
                                                    <input type="checkbox" wire:click="updateBlockOption('show_signature', {{ ($configBloc['show_signature'] ?? true) ? 'false' : 'true' }})"
                                                           @checked($configBloc['show_signature'] ?? true) class="rounded text-primary-600">
                                                    <span>☑ Case Émargement</span>
                                                </label>
                                                <label class="flex items-center gap-2 p-2 rounded-lg border border-slate-200 hover:bg-slate-50 cursor-pointer">
                                                    <input type="checkbox" wire:click="updateBlockOption('show_observation', {{ ($configBloc['show_observation'] ?? true) ? 'false' : 'true' }})"
                                                           @checked($configBloc['show_observation'] ?? true) class="rounded text-primary-600">
                                                    <span>Observations</span>
                                                </label>
                                            </div>
                                        </div>

                                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                            <div>
                                                <label class="block text-xs font-bold text-slate-700">Taille texte</label>
                                                <select wire:change="updateBlockOption('font_size', $event.target.value)" class="mt-1 w-full rounded-xl border border-slate-300 px-3 py-2 text-xs">
                                                    <option value="7.5pt" @selected(($configBloc['font_size'] ?? '') === '7.5pt')>7.5 pt (très compact)</option>
                                                    <option value="8pt" @selected(($configBloc['font_size'] ?? '') === '8pt')>8 pt</option>
                                                    <option value="8.5pt" @selected(($configBloc['font_size'] ?? '8.5pt') === '8.5pt')>8.5 pt (standard)</option>
                                                    <option value="9pt" @selected(($configBloc['font_size'] ?? '') === '9pt')>9 pt</option>
                                                    <option value="10pt" @selected(($configBloc['font_size'] ?? '') === '10pt')>10 pt (grand)</option>
                                                </select>
                                            </div>
                                            <div>
                                                <label class="block text-xs font-bold text-slate-700">Hauteur de ligne</label>
                                                <select wire:change="updateBlockOption('row_height', $event.target.value)" class="mt-1 w-full rounded-xl border border-slate-300 px-3 py-2 text-xs">
                                                    <option value="compact" @selected(($configBloc['row_height'] ?? '') === 'compact')>Compacte</option>
                                                    <option value="normal" @selected(($configBloc['row_height'] ?? 'normal') === 'normal')>Normale</option>
                                                    <option value="large" @selected(($configBloc['row_height'] ?? '') === 'large')>Grande (émargement)</option>
                                                </select>
                                            </div>
                                        </div>
                                    </div>

                                @elseif($typeBloc === 'statistics')
                                    <div class="space-y-3">
                                        <p class="text-xs text-slate-500">Cartouche récapitulatif du nombre de présents, absents et retards.</p>
                                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-2 text-xs">
                                            <label class="flex items-center gap-2 p-2 rounded-lg border border-slate-200 hover:bg-slate-50 cursor-pointer">
                                                <input type="checkbox" wire:click="updateBlockOption('show_total', {{ ($configBloc['show_total'] ?? true) ? 'false' : 'true' }})"
                                                       @checked($configBloc['show_total'] ?? true) class="rounded text-primary-600">
                                                <span>Effectif total</span>
                                            </label>
                                            <label class="flex items-center gap-2 p-2 rounded-lg border border-slate-200 hover:bg-slate-50 cursor-pointer">
                                                <input type="checkbox" wire:click="updateBlockOption('show_presents', {{ ($configBloc['show_presents'] ?? true) ? 'false' : 'true' }})"
                                                       @checked($configBloc['show_presents'] ?? true) class="rounded text-primary-600">
                                                <span>Présents</span>
                                            </label>
                                            <label class="flex items-center gap-2 p-2 rounded-lg border border-slate-200 hover:bg-slate-50 cursor-pointer">
                                                <input type="checkbox" wire:click="updateBlockOption('show_absents', {{ ($configBloc['show_absents'] ?? true) ? 'false' : 'true' }})"
                                                       @checked($configBloc['show_absents'] ?? true) class="rounded text-primary-600">
                                                <span>Absents</span>
                                            </label>
                                            <label class="flex items-center gap-2 p-2 rounded-lg border border-slate-200 hover:bg-slate-50 cursor-pointer">
                                                <input type="checkbox" wire:click="updateBlockOption('show_taux', {{ ($configBloc['show_taux'] ?? true) ? 'false' : 'true' }})"
                                                       @checked($configBloc['show_taux'] ?? true) class="rounded text-primary-600">
                                                <span>Taux d'assiduité (%)</span>
                                            </label>
                                        </div>
                                    </div>

                                @elseif($typeBloc === 'signature')
                                    <div class="space-y-3">
                                        <p class="text-xs text-slate-500">Cadres pour le visa et la signature des intervenants.</p>
                                        <div>
                                            <label class="block text-xs font-bold text-slate-700">Type de signature</label>
                                            <select wire:change="updateBlockOption('type', $event.target.value)" class="mt-1 w-full rounded-xl border border-slate-300 px-3 py-2 text-xs">
                                                <option value="single" @selected(($configBloc['type'] ?? 'single') === 'single')>Simple (Enseignant uniquement)</option>
                                                <option value="double" @selected(($configBloc['type'] ?? '') === 'double')>Double (Enseignant + Administration)</option>
                                                <option value="exam" @selected(($configBloc['type'] ?? '') === 'exam')>Examen (Surveillants + Commission)</option>
                                            </select>
                                        </div>
                                        <div>
                                            <label class="block text-xs font-bold text-slate-700">Mention légale</label>
                                            <input type="text" value="{{ $configBloc['mention_legale'] ?? 'Document officiel de scolarité.' }}"
                                                   wire:change="updateBlockOption('mention_legale', $event.target.value)"
                                                   class="mt-1 w-full rounded-xl border border-slate-300 px-3 py-2 text-xs">
                                        </div>
                                    </div>

                                @elseif($typeBloc === 'qr')
                                    <div class="space-y-3">
                                        <p class="text-xs text-slate-500">QR Code de certification cryptographique anti-falsification.</p>
                                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                            <div>
                                                <label class="block text-xs font-bold text-slate-700">Position</label>
                                                <select wire:change="updateBlockOption('position', $event.target.value)" class="mt-1 w-full rounded-xl border border-slate-300 px-3 py-2 text-xs">
                                                    <option value="inline" @selected(($configBloc['position'] ?? 'inline') === 'inline')>Intégré au tableau</option>
                                                    <option value="header" @selected(($configBloc['position'] ?? '') === 'header')>En-tête</option>
                                                    <option value="footer" @selected(($configBloc['position'] ?? '') === 'footer')>Pied de page</option>
                                                </select>
                                            </div>
                                            <div>
                                                <label class="block text-xs font-bold text-slate-700">Dimension (px)</label>
                                                <select wire:change="updateBlockOption('size', parseInt($event.target.value))" class="mt-1 w-full rounded-xl border border-slate-300 px-3 py-2 text-xs">
                                                    <option value="40" @selected(($configBloc['size'] ?? 60) == 40)>40 px (discret)</option>
                                                    <option value="60" @selected(($configBloc['size'] ?? 60) == 60)>60 px (standard)</option>
                                                    <option value="80" @selected(($configBloc['size'] ?? 60) == 80)>80 px (lisibilité haute)</option>
                                                </select>
                                            </div>
                                        </div>
                                    </div>

                                @elseif($typeBloc === 'footer')
                                    <div class="space-y-3">
                                        <p class="text-xs text-slate-500">Pied de page institutionnel et pagination.</p>
                                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-2 text-xs">
                                            <label class="flex items-center gap-2 p-2 rounded-lg border border-slate-200 hover:bg-slate-50 cursor-pointer">
                                                <input type="checkbox" wire:click="updateBlockOption('show_page_number', {{ ($configBloc['show_page_number'] ?? true) ? 'false' : 'true' }})"
                                                       @checked($configBloc['show_page_number'] ?? true) class="rounded text-primary-600">
                                                <span>Numéro de page</span>
                                            </label>
                                            <label class="flex items-center gap-2 p-2 rounded-lg border border-slate-200 hover:bg-slate-50 cursor-pointer">
                                                <input type="checkbox" wire:click="updateBlockOption('show_generation_date', {{ ($configBloc['show_generation_date'] ?? true) ? 'false' : 'true' }})"
                                                       @checked($configBloc['show_generation_date'] ?? true) class="rounded text-primary-600">
                                                <span>Date d'édition</span>
                                            </label>
                                        </div>
                                        <div>
                                            <label class="block text-xs font-bold text-slate-700">Mention personnalisée</label>
                                            <input type="text" value="{{ $configBloc['custom_notice'] ?? '' }}"
                                                   placeholder="Ex: Document strictement confidentiel — UniRegistre"
                                                   wire:change="updateBlockOption('custom_notice', $event.target.value)"
                                                   class="mt-1 w-full rounded-xl border border-slate-300 px-3 py-2 text-xs">
                                        </div>
                                    </div>
                                @endif

                            @else
                                <div class="p-8 text-center text-xs text-slate-400">
                                    Sélectionnez un bloc à gauche pour modifier ses options.
                                </div>
                            @endif
                        </div>
                    </div>

                    {{-- Bouton raccourci vers l'aperçu --}}
                    <div class="flex justify-end pt-2">
                        <button type="button" wire:click="$set('activeTab', 'preview')"
                                class="inline-flex items-center gap-2 rounded-xl bg-slate-900 px-4 py-2.5 text-xs font-bold text-white hover:bg-slate-800 transition-colors shadow-sm">
                            <span>Voir l'aperçu direct de la fiche →</span>
                        </button>
                    </div>
                </div>
            @endif

            {{-- ── TAB 2 : APERÇU DIRECT (DONNÉES FICTIVES SANS PII) ── --}}
            @if($activeTab === 'preview')
                <div class="space-y-4">
                    <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm flex items-center justify-between gap-3 flex-wrap">
                        <div class="flex items-center gap-2">
                            <span class="inline-flex h-2.5 w-2.5 rounded-full bg-emerald-500 animate-pulse"></span>
                            <span class="text-xs font-bold text-slate-800">Aperçu interactif temps réel</span>
                            <span class="text-xs text-slate-400">· Données fictives contrôlées sans PII</span>
                        </div>

                        <div class="flex items-center gap-2">
                            <button type="button" wire:click="$set('activeTab', 'editor')"
                                    class="rounded-xl border border-slate-200 px-3 py-1.5 text-xs font-bold text-slate-700 hover:bg-slate-50 transition-colors">
                                ← Revenir à l'éditeur
                            </button>
                            <button type="button" wire:click="$refresh" class="rounded-xl border border-slate-200 px-3 py-1.5 text-xs font-bold text-slate-700 hover:bg-slate-50 transition-colors">
                                🔄 Actualiser
                            </button>
                            @if($selectedTemplateId)
                                <a href="{{ route('admin.presences.modeles.apercu-pdf', $selectedTemplateId) }}" target="_blank"
                                   class="rounded-xl bg-primary-800 px-3.5 py-1.5 text-xs font-bold text-white hover:bg-primary-900 transition-colors shadow-sm">
                                    Ouvrir en PDF →
                                </a>
                            @endif
                        </div>
                    </div>

                    {{-- Iframe de prévisualisation isolée pour préserver les styles du document d'émargement --}}
                    <div class="rounded-2xl border border-slate-300 bg-slate-100 p-3 sm:p-5 shadow-inner">
                        <iframe srcdoc="{{ $this->previewHtml }}"
                                class="w-full min-h-[780px] rounded-xl border border-slate-300 bg-white shadow-md transition-all"
                                title="Aperçu du modèle d'émargement"></iframe>
                    </div>
                </div>
            @endif

        </main>
    </div>
</div>
