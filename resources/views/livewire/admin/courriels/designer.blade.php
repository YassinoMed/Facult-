@php
    /** @var \App\Livewire\Admin\Courriels\DesignerCourriel $this */
@endphp
<div class="mx-auto max-w-[110rem] px-4 sm:px-6 py-6">
    {{-- ═══ Barre supérieure : tout le cycle sur cette page ═══ --}}
    <div class="rounded-2xl border border-slate-200 bg-white px-4 py-3 shadow-sm">
        <div class="flex flex-wrap items-center gap-2">
            @if ($this->modele)
                <h1 class="text-lg font-black text-slate-900">{{ $this->modele['code'] }}</h1>
                <span class="rounded-full bg-slate-100 px-2.5 py-0.5 text-[11px] font-bold text-slate-700">{{ $this->modele['statut'] }}</span>
                <span class="text-xs text-slate-500">v{{ $this->modele['version_courante'] ?? '—' }} · base v{{ $baseVersion }}</span>
            @else
                <h1 class="text-lg font-black text-slate-900">Nouveau modèle</h1>
            @endif

            <span wire:loading wire:target="sauvegarder" class="text-xs font-bold text-slate-500">Sauvegarde…</span>
            <span x-data x-show="$wire.sale" class="text-xs font-bold text-amber-700" role="status">● Non enregistré</span>
            @if ($message !== '')
                <span class="text-xs font-bold" :class="{{ $messageOk ? "'text-emerald-700'" : "'text-rose-700'" }}" role="status">{{ $message }}</span>
            @endif

            <span class="ml-auto flex flex-wrap gap-1.5" role="toolbar" aria-label="Actions du designer">
                <button type="button" wire:click="annuler" @if (! $this->peutAnnuler) disabled @endif
                        class="rounded-xl border px-3 py-2 text-xs font-bold disabled:opacity-40">↩ Annuler</button>
                <button type="button" wire:click="retablir" @if (! $this->peutRetablir) disabled @endif
                        class="rounded-xl border px-3 py-2 text-xs font-bold disabled:opacity-40">↪ Rétablir</button>
                <button type="button" wire:click="rafraichirApercu"
                        class="rounded-xl border px-3 py-2 text-xs font-bold">Aperçu</button>
                <button type="button" wire:click="sauvegarder" @if ($this->modele && $this->modele['verrou_systeme']) disabled @endif
                        class="rounded-xl bg-primary-600 px-4 py-2 text-xs font-bold text-white disabled:opacity-40">
                    {{ $this->modele ? 'Enregistrer' : 'Créer le brouillon' }}
                </button>
                @if ($this->modele && $this->modele['statut'] === 'brouillon')
                    <button type="button" wire:click="soumettre" wire:confirm="Soumettre ce modèle à validation ?"
                            class="rounded-xl border px-3 py-2 text-xs font-bold">Soumettre</button>
                @endif
                @can('mail.modeles.publier')
                    @if ($this->modele && in_array($this->modele['statut'], ['brouillon', 'a_valider'], true))
                        <button type="button" wire:click="publier" wire:confirm="Publier ce modèle (version courante) ?"
                                class="rounded-xl bg-emerald-600 px-4 py-2 text-xs font-bold text-white">Publier</button>
                    @endif
                @endcan
            </span>
        </div>
    </div>

    {{-- ═══ Bibliothèque (page) OU éditeur (même page, aucune navigation) ═══ --}}
    @if (! $this->modele)
        <div class="mt-4 grid grid-cols-1 lg:grid-cols-3 gap-4">
            <div class="lg:col-span-2 rounded-2xl border border-slate-200 bg-white p-5">
                <h2 class="text-sm font-black text-slate-900">Créer un modèle</h2>
                <div class="mt-3 grid grid-cols-1 sm:grid-cols-3 gap-3 text-sm">
                    <div>
                        <label for="d-code" class="mb-1 block text-xs font-bold uppercase tracking-wider text-slate-500">Code *</label>
                        <input id="d-code" type="text" wire:model="code" required maxlength="60"
                               class="w-full rounded-xl border border-slate-200 px-3 py-2 font-mono">
                    </div>
                    <div>
                        <label for="d-nom" class="mb-1 block text-xs font-bold uppercase tracking-wider text-slate-500">Nom *</label>
                        <input id="d-nom" type="text" wire:model="nom" required maxlength="150"
                               class="w-full rounded-xl border border-slate-200 px-3 py-2">
                    </div>
                    <div>
                        <label for="d-cat" class="mb-1 block text-xs font-bold uppercase tracking-wider text-slate-500">Catégorie</label>
                        <input id="d-cat" type="text" wire:model="categorie" maxlength="40"
                               class="w-full rounded-xl border border-slate-200 px-3 py-2">
                    </div>
                </div>
                @error('code')<p class="mt-1 text-xs font-bold text-rose-700" role="alert">{{ $message }}</p>@enderror

                <h3 class="mt-5 text-xs font-black uppercase tracking-wider text-slate-500">Partir d'un squelette</h3>
                <div class="mt-2 flex flex-wrap gap-2">
                    @foreach ($squelettes as $index => $squelette)
                        <button type="button" wire:click="appliquerSquelette({{ $index }})"
                                class="rounded-xl border border-dashed border-slate-300 px-3 py-2 text-xs font-bold hover:border-primary-500">
                            ⧉ {{ $squelette['meta']['objet'] ?? 'Squelette' }}
                        </button>
                    @endforeach
                </div>

                <button type="button" wire:click="creerBrouillon" wire:loading.attr="disabled"
                        class="mt-4 rounded-xl bg-primary-600 px-5 py-2.5 text-xs font-bold text-white disabled:opacity-60">
                    Créer le brouillon et composer
                </button>
            </div>

            <div class="rounded-2xl border border-slate-200 bg-white p-5">
                <h2 class="text-sm font-black text-slate-900">Bibliothèque</h2>
                <input type="search" wire:model.live.debounce.300ms="recherche" placeholder="Nom ou code…"
                       class="mt-2 w-full rounded-xl border border-slate-200 px-3 py-2 text-sm" aria-label="Recherche">
                <ul class="mt-3 divide-y divide-slate-100 text-sm">
                    @forelse ($this->modeles as $modele)
                        <li class="flex items-center justify-between gap-2 py-2">
                            <span>
                                <span class="font-mono text-xs font-bold">{{ $modele->code }}</span>
                                <span class="block text-[11px] text-slate-500">{{ $modele->statut }} · v{{ $modele->version_courante ?? '—' }}</span>
                            </span>
                            @if (! $modele->verrou_systeme && $modele->statut !== 'archive')
                                <button type="button" wire:click="chargerModele({{ $modele->id }})"
                                        class="text-xs font-bold text-primary-800 underline underline-offset-2">Ouvrir</button>
                            @else
                                <span class="text-[11px] text-slate-400">{{ $modele->verrou_systeme ? '🔒 système' : 'archivé' }}</span>
                            @endif
                        </li>
                    @empty
                        <li class="py-3 text-slate-500">Aucun modèle.</li>
                    @endforelse
                </ul>
                <div class="mt-2">{{ $this->modeles->links() }}</div>
            </div>
        </div>
    @else
        <div class="mt-4 grid grid-cols-1 lg:grid-cols-12 gap-3">
            {{-- Bibliothèque de blocs (gauche) --}}
            <aside class="lg:col-span-2 rounded-2xl border border-slate-200 bg-white p-3" aria-label="Bibliothèque de blocs">
                <h2 class="text-xs font-black uppercase tracking-wider text-slate-500">Blocs</h2>
                <div class="mt-2 grid grid-cols-2 lg:grid-cols-1 gap-1.5">
                    <button type="button" wire:click="ajouterBloc({{ json_encode(['type' => 'titre', 'texte' => '', 'niveau' => 'h2', 'alignement' => 'gauche', 'id' => null, 'verrouille' => false]) }})"
                            class="rounded-xl border border-dashed border-slate-300 px-2 py-2 text-left text-xs font-bold hover:border-primary-500">+ Titre</button>
                    <button type="button" wire:click="ajouterBloc({{ json_encode(['type' => 'texte', 'contenu' => '', 'alignement' => 'gauche', 'id' => null, 'verrouille' => false]) }})"
                            class="rounded-xl border border-dashed border-slate-300 px-2 py-2 text-left text-xs font-bold hover:border-primary-500">+ Texte</button>
                    <button type="button" wire:click="ajouterBloc({{ json_encode(['type' => 'bouton', 'libelle' => '', 'url' => '', 'alignement' => 'centre', 'id' => null, 'verrouille' => false]) }})"
                            class="rounded-xl border border-dashed border-slate-300 px-2 py-2 text-left text-xs font-bold hover:border-primary-500">+ Bouton</button>
                    <button type="button" wire:click="ajouterBloc({{ json_encode(['type' => 'image', 'url' => '', 'texte_alt' => '', 'largeur' => 560, 'alignement' => 'gauche', 'id' => null, 'verrouille' => false]) }})"
                            class="rounded-xl border border-dashed border-slate-300 px-2 py-2 text-left text-xs font-bold hover:border-primary-500">+ Image</button>
                    <button type="button" wire:click="ajouterBloc({{ json_encode(['type' => 'separateur', 'style' => 'fin', 'alignement' => 'gauche', 'id' => null, 'verrouille' => false]) }})"
                            class="rounded-xl border border-dashed border-slate-300 px-2 py-2 text-left text-xs font-bold hover:border-primary-500">+ Séparateur</button>
                    <button type="button" wire:click="ajouterBloc({{ json_encode(['type' => 'liste', 'elements' => [''], 'alignement' => 'gauche', 'id' => null, 'verrouille' => false]) }})"
                            class="rounded-xl border border-dashed border-slate-300 px-2 py-2 text-left text-xs font-bold hover:border-primary-500">+ Liste</button>
                    <button type="button" wire:click="ajouterBloc({{ json_encode(['type' => 'espaceur', 'hauteur' => 24, 'alignement' => 'gauche', 'id' => null, 'verrouille' => false]) }})"
                            class="rounded-xl border border-dashed border-slate-300 px-2 py-2 text-left text-xs font-bold hover:border-primary-500">+ Espace</button>
                    <button type="button" wire:click="ajouterBloc({{ json_encode(['type' => 'colonnes', 'colonnes' => [[], []], 'alignement' => 'gauche', 'id' => null, 'verrouille' => false]) }})"
                            class="rounded-xl border border-dashed border-slate-300 px-2 py-2 text-left text-xs font-bold hover:border-primary-500">+ Colonnes</button>
                    <button type="button" wire:click="ajouterBloc({{ json_encode(['type' => 'carte', 'titre' => '', 'texte' => '', 'couleur_bordure' => null, 'alignement' => 'gauche', 'id' => null, 'verrouille' => false]) }})"
                            class="rounded-xl border border-dashed border-slate-300 px-2 py-2 text-left text-xs font-bold hover:border-primary-500">+ Carte</button>
                    <button type="button" wire:click="ajouterBloc({{ json_encode(['type' => 'tableau', 'entetes' => ['Colonne 1'], 'lignes' => [], 'alignement' => 'gauche', 'id' => null, 'verrouille' => false]) }})"
                            class="rounded-xl border border-dashed border-slate-300 px-2 py-2 text-left text-xs font-bold hover:border-primary-500">+ Tableau</button>
                    <button type="button" wire:click="ajouterBloc({{ json_encode(['type' => 'signature', 'texte' => '', 'alignement' => 'gauche', 'id' => null, 'verrouille' => false]) }})"
                            class="rounded-xl border border-dashed border-slate-300 px-2 py-2 text-left text-xs font-bold hover:border-primary-500">+ Signature</button>
                </div>
            </aside>

            {{-- Canvas central --}}
            <main class="lg:col-span-6 rounded-2xl border border-slate-200 bg-slate-50 p-3" aria-label="Canevas">
                <div class="mb-2 rounded-xl bg-white border border-slate-200 p-3">
                    <label for="d-objet" class="block text-xs font-bold uppercase tracking-wider text-slate-500">Objet *</label>
                    <input id="d-objet" type="text" wire:model="schema.meta.objet" wire:change="marquerSale" maxlength="150"
                           class="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2 text-sm font-bold">
                    <label for="d-preheader" class="mt-2 block text-xs font-bold uppercase tracking-wider text-slate-500">Pré-en-tête</label>
                    <input id="d-preheader" type="text" wire:model="schema.meta.preheader" wire:change="marquerSale" maxlength="150"
                           class="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2 text-sm text-slate-600">
                </div>

                <ol class="space-y-2">
                    @foreach ($schema['blocs'] as $index => $bloc)
                        <li @click="$wire.selectionner({{ $index }})"
                            @dragstart="$wire.instantane(); window.__deplace = {{ $index }}"
                            @dragover.prevent
                            @drop="$wire.deposerBloc(window.__deplace, {{ $index }}); window.__deplace = null"
                            draggable="{{ ($bloc['verrouille'] ?? false) ? 'false' : 'true' }}"
                            class="rounded-xl border-2 bg-white p-2 {{ $selection === $index ? 'border-primary-600 ring-2 ring-primary-500/20' : 'border-slate-200' }} cursor-pointer">
                            <div class="flex items-center gap-2 text-[11px] font-bold uppercase tracking-wider text-slate-400">
                                <span>{{ $index + 1 }} · {{ $bloc['type'] }}@if ($bloc['verrouille'] ?? false) 🔒@endif</span>
                                <span class="ml-auto flex gap-1" @click.stop>
                                    <button type="button" wire:click="monterBloc({{ $index }})" @if ($index === 0 || ($bloc['verrouille'] ?? false)) disabled @endif aria-label="Monter" class="rounded border px-1.5 disabled:opacity-40">↑</button>
                                    <button type="button" wire:click="descendreBloc({{ $index }})" @if ($index === count($schema['blocs']) - 1 || ($bloc['verrouille'] ?? false)) disabled @endif aria-label="Descendre" class="rounded border px-1.5 disabled:opacity-40">↓</button>
                                    <button type="button" wire:click="dupliquerBloc({{ $index }})" aria-label="Dupliquer" class="rounded border px-1.5">⧉</button>
                                    <button type="button" wire:click="supprimerBloc({{ $index }})" @if ($bloc['verrouille'] ?? false) disabled @endif wire:confirm="Supprimer ce bloc ?" aria-label="Supprimer" class="rounded border border-rose-300 px-1.5 text-rose-600 disabled:opacity-40">✕</button>
                                </span>
                            </div>
                            <p class="mt-1 truncate text-sm text-slate-700">
                                @if (($bloc['type'] ?? '') === 'titre')
                                    <strong>{{ \Illuminate\Support\Str::limit($bloc['texte'] ?? '', 70) }}</strong>
                                @elseif (($bloc['type'] ?? '') === 'texte')
                                    {{ \Illuminate\Support\Str::limit($bloc['contenu'] ?? '', 110) }}
                                @elseif (($bloc['type'] ?? '') === 'bouton')
                                    ▣ {{ \Illuminate\Support\Str::limit($bloc['libelle'] ?? '', 40) }}
                                @elseif (($bloc['type'] ?? '') === 'image')
                                    🖼 {{ \Illuminate\Support\Str::limit($bloc['texte_alt'] ?: $bloc['url'] ?? '', 60) }}
                                @elseif (($bloc['type'] ?? '') === 'liste')
                                    • {{ count($bloc['elements'] ?? []) }} élément(s)
                                @elseif (($bloc['type'] ?? '') === 'espaceur')
                                    <span class="text-slate-400">↕ {{ $bloc['hauteur'] ?? 0 }} px</span>
                                @elseif (($bloc['type'] ?? '') === 'colonnes')
                                    ▥ {{ collect($bloc['colonnes'] ?? [])->map(fn ($c) => count($c).' bloc(s)')->implode(' | ') }}
                                @elseif (($bloc['type'] ?? '') === 'carte')
                                    ▦ <strong>{{ \Illuminate\Support\Str::limit($bloc['titre'] ?? '', 40) }}</strong> — {{ \Illuminate\Support\Str::limit($bloc['texte'] ?? '', 60) }}
                                @elseif (($bloc['type'] ?? '') === 'tableau')
                                    ▦ tableau {{ count($bloc['entetes'] ?? []) }}×{{ count($bloc['lignes'] ?? []) }}
                                @elseif (($bloc['type'] ?? '') === 'signature')
                                    <em>{{ \Illuminate\Support\Str::limit($bloc['texte'] ?: '(signature du thème)', 60) }}</em>
                                @endif
                            </p>
                        </li>
                    @endforeach
                    @if (count($schema['blocs']) === 0)
                        <li class="rounded-xl border-2 border-dashed border-slate-200 bg-white py-10 text-center text-sm text-slate-400">
                            Canevas vide — ajoutez des blocs depuis la bibliothèque.
                        </li>
                    @endif
                </ol>
            </main>

            {{-- Propriétés (droite) --}}
            <aside class="lg:col-span-4 rounded-2xl border border-slate-200 bg-white p-3" aria-label="Propriétés du bloc sélectionné">
                @if ($selection === null || ! isset($schema['blocs'][$selection]))
                    <p class="text-sm text-slate-400">Sélectionnez un bloc du canevas pour régler ses propriétés.</p>
                @else
                    @php($bloc = $schema['blocs'][$selection])
                    <h2 class="text-xs font-black uppercase tracking-wider text-slate-500">
                        Bloc {{ $selection + 1 }} — {{ $bloc['type'] }}@if ($bloc['verrouille'] ?? false) 🔒@endif
                    </h2>

                    @if (! ($bloc['verrouille'] ?? false))
                        @if (in_array($bloc['type'], ['titre', 'texte', 'bouton', 'image', 'liste']))
                            <label class="mt-2 block text-xs font-bold">Alignement
                                <select wire:model="schema.blocs.{{ $selection }}.alignement" wire:change="marquerSale"
                                        class="mt-1 block w-full rounded-xl border px-2 py-1.5">
                                    <option value="gauche">Gauche</option><option value="centre">Centre</option><option value="droite">Droite</option>
                                </select>
                            </label>
                        @endif

                        @if ($bloc['type'] === 'titre')
                            <label class="mt-2 block text-xs font-bold">Texte *
                                <input type="text" wire:model="schema.blocs.{{ $selection }}.texte" wire:change="marquerSale" maxlength="200"
                                       class="mt-1 block w-full rounded-xl border px-3 py-2">
                            </label>
                            <label class="mt-2 block text-xs font-bold">Niveau
                                <select wire:model="schema.blocs.{{ $selection }}.niveau" wire:change="marquerSale" class="mt-1 block w-full rounded-xl border px-2 py-1.5">
                                    <option value="h1">H1</option><option value="h2">H2</option><option value="h3">H3</option>
                                </select>
                            </label>
                        @elseif ($bloc['type'] === 'texte')
                            <label class="mt-2 block text-xs font-bold">Contenu *
                                <textarea wire:model="schema.blocs.{{ $selection }}.contenu" wire:change="marquerSale" rows="5" maxlength="5000"
                                          class="mt-1 block w-full rounded-xl border px-3 py-2"></textarea>
                            </label>
                        @elseif ($bloc['type'] === 'bouton')
                            <label class="mt-2 block text-xs font-bold">Libellé *
                                <input type="text" wire:model="schema.blocs.{{ $selection }}.libelle" wire:change="marquerSale" maxlength="80"
                                       class="mt-1 block w-full rounded-xl border px-3 py-2">
                            </label>
                            <label class="mt-2 block text-xs font-bold">URL (https / mailto / {{ '{' }}url_action{{ '}' }}) *
                                <input type="text" wire:model="schema.blocs.{{ $selection }}.url" wire:change="marquerSale" maxlength="500"
                                       class="mt-1 block w-full rounded-xl border px-3 py-2 font-mono text-xs">
                            </label>
                        @elseif ($bloc['type'] === 'image')
                            <label class="mt-2 block text-xs font-bold">URL (https, jpg/png/gif/webp) *
                                <input type="text" wire:model="schema.blocs.{{ $selection }}.url" wire:change="marquerSale" maxlength="500"
                                       class="mt-1 block w-full rounded-xl border px-3 py-2 font-mono text-xs">
                            </label>
                            <label class="mt-2 block text-xs font-bold">Texte alternatif *
                                <input type="text" wire:model="schema.blocs.{{ $selection }}.texte_alt" wire:change="marquerSale" maxlength="200"
                                       class="mt-1 block w-full rounded-xl border px-3 py-2">
                            </label>
                            <label class="mt-2 block text-xs font-bold">Largeur (100–600)
                                <input type="number" wire:model="schema.blocs.{{ $selection }}.largeur" wire:change="marquerSale" min="100" max="600"
                                       class="mt-1 block w-28 rounded-xl border px-2 py-1.5">
                            </label>
                        @elseif ($bloc['type'] === 'separateur')
                            <label class="mt-2 block text-xs font-bold">Style
                                <select wire:model="schema.blocs.{{ $selection }}.style" wire:change="marquerSale" class="mt-1 block w-full rounded-xl border px-2 py-1.5">
                                    <option value="fin">Fin</option><option value="epais">Épais</option><option value="pointille">Pointillé</option>
                                </select>
                            </label>
                        @elseif ($bloc['type'] === 'liste')
                            <span class="mt-2 block text-xs font-bold">Éléments</span>
                            @foreach ($bloc['elements'] as $j => $element)
                                <div class="mt-1 flex gap-1">
                                    <input type="text" wire:model="schema.blocs.{{ $selection }}.elements.{{ $j }}" wire:change="marquerSale" maxlength="200"
                                           class="flex-1 rounded-xl border px-3 py-1.5">
                                    <button type="button" wire:click="retirerElementListe({{ $selection }}, {{ $j }})" aria-label="Retirer l’élément"
                                            class="rounded-lg border px-2 text-xs">✕</button>
                                </div>
                            @endforeach
                            <button type="button" wire:click="ajouterElementListe({{ $selection }})"
                                    class="mt-1 text-xs font-bold text-primary-800">+ Élément</button>
                        @elseif ($bloc['type'] === 'espaceur')
                            <label class="mt-2 block text-xs font-bold">Hauteur (8–80)
                                <input type="number" wire:model="schema.blocs.{{ $selection }}.hauteur" wire:change="marquerSale" min="8" max="80"
                                       class="mt-1 block w-28 rounded-xl border px-2 py-1.5">
                            </label>
                        @elseif ($bloc['type'] === 'carte')
                            <label class="mt-2 block text-xs font-bold">Titre *
                                <input type="text" wire:model="schema.blocs.{{ $selection }}.titre" wire:change="marquerSale" maxlength="120"
                                       class="mt-1 block w-full rounded-xl border px-3 py-2">
                            </label>
                            <label class="mt-2 block text-xs font-bold">Texte *
                                <textarea wire:model="schema.blocs.{{ $selection }}.texte" wire:change="marquerSale" rows="3" maxlength="2000"
                                          class="mt-1 block w-full rounded-xl border px-3 py-2"></textarea>
                            </label>
                            <label class="mt-2 block text-xs font-bold">Couleur de bordure (hex, vide = accent)
                                <input type="text" wire:model="schema.blocs.{{ $selection }}.couleur_bordure" wire:change="marquerSale" maxlength="7"
                                       placeholder="#4f46e5" class="mt-1 block w-full rounded-xl border px-3 py-2 font-mono">
                            </label>
                        @elseif ($bloc['type'] === 'tableau')
                            <span class="mt-2 block text-xs font-bold">En-têtes (1 à 6)</span>
                            @foreach ($bloc['entetes'] as $j => $entete)
                                <input type="text" wire:model="schema.blocs.{{ $selection }}.entetes.{{ $j }}" wire:change="marquerSale" maxlength="60"
                                       placeholder="En-tête {{ $j + 1 }}" class="mt-1 block w-full rounded-xl border px-3 py-1.5 font-bold">
                            @endforeach
                            <span class="mt-3 block text-xs font-bold">Lignes (0 à 20)</span>
                            @foreach ($bloc['lignes'] as $r => $ligne)
                                <div class="mt-1 flex gap-1">
                                    @foreach ($ligne as $k => $cellule)
                                        <input type="text" wire:model="schema.blocs.{{ $selection }}.lignes.{{ $r }}.{{ $k }}" wire:change="marquerSale" maxlength="100"
                                               class="w-full min-w-0 rounded-xl border px-2 py-1.5 text-xs">
                                    @endforeach
                                    <button type="button" wire:click="retirerLigneTableau({{ $selection }}, {{ $r }})" aria-label="Retirer la ligne"
                                            class="rounded-lg border px-2 text-xs">✕</button>
                                </div>
                            @endforeach
                            <button type="button" wire:click="ajouterLigneTableau({{ $selection }})"
                                    class="mt-1 text-xs font-bold text-primary-800">+ Ligne</button>
                        @elseif ($bloc['type'] === 'signature')
                            <label class="mt-2 block text-xs font-bold">Texte (vide = signature du thème)
                                <textarea wire:model="schema.blocs.{{ $selection }}.texte" wire:change="marquerSale" rows="3" maxlength="500"
                                          class="mt-1 block w-full rounded-xl border px-3 py-2"></textarea>
                            </label>
                        @endif
                    @else
                        <p class="mt-2 text-xs text-slate-500">Bloc verrouillé : consultation seule.</p>
                    @endif

                    <div class="mt-3 grid grid-cols-2 gap-2">
                        <label class="block text-xs font-bold">Identifiant
                            <input type="text" wire:model="schema.blocs.{{ $selection }}.id" wire:change="marquerSale" maxlength="32"
                                   placeholder="optionnel" class="mt-1 block w-full rounded-xl border px-2 py-1.5 font-mono text-xs">
                        </label>
                        <label class="flex items-end gap-1.5 pb-2 text-xs font-bold">
                            <input type="checkbox" wire:model="schema.blocs.{{ $selection }}.verrouille" wire:change="marquerSale" class="h-4 w-4"> Verrouiller
                        </label>
                    </div>
                @endif

                <div class="mt-4 rounded-2xl border border-slate-200 p-3">
                    <h3 class="text-xs font-black uppercase tracking-wider text-slate-500">Thème</h3>
                    <div class="mt-2 grid grid-cols-2 gap-2 text-xs font-bold">
                        @foreach (['couleur_principale', 'couleur_accent', 'fond', 'fond_contenu'] as $cle)
                            <label>{{ $cle }}
                                <input type="color" wire:model="schema.theme.{{ $cle }}" wire:change="marquerSale"
                                       class="mt-1 block h-8 w-full rounded-lg border">
                            </label>
                        @endforeach
                        <label>Police
                            <select wire:model="schema.theme.police" wire:change="marquerSale" class="mt-1 block w-full rounded-xl border px-2 py-1.5">
                                <option value="arial">Arial</option><option value="georgia">Georgia</option><option value="systeme">Système</option>
                            </select>
                        </label>
                        <label>Boutons
                            <select wire:model="schema.theme.style_bouton" wire:change="marquerSale" class="mt-1 block w-full rounded-xl border px-2 py-1.5">
                                <option value="arrondi">Arrondis</option><option value="angle">Angles</option>
                            </select>
                        </label>
                        <label>Largeur (480–800)
                            <input type="number" wire:model="schema.theme.largeur" wire:change="marquerSale" min="480" max="800"
                                   class="mt-1 block w-full rounded-xl border px-2 py-1.5">
                        </label>
                        <label>Logo (https)
                            <input type="text" wire:model="schema.theme.logo_url" wire:change="marquerSale" maxlength="500"
                                   class="mt-1 block w-full rounded-xl border px-2 py-1.5 font-mono text-[11px]">
                        </label>
                    </div>
                    <label class="mt-2 block text-xs font-bold">Établissement
                        <input type="text" wire:model="schema.theme.nom_etablissement" wire:change="marquerSale" maxlength="120"
                               class="mt-1 block w-full rounded-xl border px-2 py-1.5">
                    </label>
                    <label class="mt-2 block text-xs font-bold">Signature
                        <textarea wire:model="schema.theme.signature" wire:change="marquerSale" rows="2" maxlength="500"
                                  class="mt-1 block w-full rounded-xl border px-2 py-1.5"></textarea>
                    </label>
                    <label class="mt-2 block text-xs font-bold">Mentions
                        <textarea wire:model="schema.theme.mentions" wire:change="marquerSale" rows="2" maxlength="500"
                                  class="mt-1 block w-full rounded-xl border px-2 py-1.5"></textarea>
                    </label>
                </div>

                <div class="mt-3 rounded-2xl border border-slate-200 p-3">
                    <h3 class="text-xs font-black uppercase tracking-wider text-slate-500">Variables autorisées</h3>
                    <div class="mt-1 flex flex-wrap gap-1">
                        @foreach ($variables as $nom => $definition)
                            <span title="{{ $definition['description'] }} (ex. {{ $definition['exemple'] }})"
                                  class="rounded-lg bg-slate-100 border border-slate-200 px-1.5 py-0.5 font-mono text-[11px] text-slate-700">{!! '{{'.$nom.'}}' !!}</span>
                        @endforeach
                    </div>
                    <p class="mt-1 text-[11px] text-slate-500">Saisissez {{ '{' }}nom{{ '}' }} dans un champ : inconnue = refus à la sauvegarde.</p>
                </div>
            </aside>
        </div>

        {{-- Aperçu isolé (rendu SERVEUR, données fictives) --}}
        <div class="mt-3 rounded-2xl border border-slate-200 bg-white p-3">
            <div class="flex flex-wrap items-center gap-2">
                <h2 class="text-sm font-black">Aperçu isolé (rendu serveur, données fictives)</h2>
                <span class="flex gap-1" role="group" aria-label="Largeur d’aperçu">
                    @foreach (['100%' => 'Bureau', '768px' => 'Tablette', '375px' => 'Mobile'] as $largeur => $libelle)
                        <button type="button" wire:click="$set('largeurApercu', '{{ $largeur }}')"
                                class="rounded-lg border px-2 py-1 text-xs font-bold {{ $largeurApercu === $largeur ? 'bg-slate-900 text-white' : '' }}">{{ $libelle }}</button>
                    @endforeach
                </span>
                <span class="flex gap-1 text-xs font-bold" role="tablist" aria-label="Format d’aperçu">
                    <button type="button" wire:click="$set('ongletApercu', 'html')" class="px-2 py-1 {{ $ongletApercu === 'html' ? 'underline' : '' }}">HTML</button>
                    <button type="button" wire:click="$set('ongletApercu', 'texte')" class="px-2 py-1 {{ $ongletApercu === 'texte' ? 'underline' : '' }}">Texte brut</button>
                </span>
                <button type="button" wire:click="rafraichirApercu" wire:loading.attr="disabled"
                        class="ml-auto rounded-lg border px-2 py-1 text-xs font-bold disabled:opacity-40">Actualiser</button>
            </div>
            @if ($erreurApercu !== '')
                <p class="mt-2 text-xs font-bold text-rose-700" role="alert">{{ $erreurApercu }}</p>
            @endif
            <div class="mt-2 overflow-auto rounded-xl border bg-slate-50">
                @if ($ongletApercu === 'html')
                    <iframe title="Aperçu isolé du courriel" sandbox="" srcdoc="{{ $apercuHtml }}"
                            style="width:{{ $largeurApercu }};max-width:100%;height:560px;border:0;background:#fff;"></iframe>
                @else
                    <pre class="p-3 text-xs whitespace-pre-wrap" style="height:560px;overflow:auto;">{{ $apercuTexte }}</pre>
                @endif
            </div>
            <p class="mt-1 text-[11px] text-slate-500">Sandbox sans origine : aucun script du modèle ne s’exécute ici. Rendu serveur = vérité.</p>
        </div>
    @endif
</div>
