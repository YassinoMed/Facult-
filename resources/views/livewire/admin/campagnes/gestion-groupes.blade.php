<div class="mx-auto max-w-7xl px-4 sm:px-6 py-8">
    {{-- Fil d'ariane & En-tête --}}
    <div class="flex items-center gap-2 text-xs font-semibold uppercase tracking-wider text-primary-700">
        <a href="{{ route('admin.courriels.index') }}" class="hover:underline">Courriels</a>
        <span>&rsaquo;</span>
        <a href="{{ route('admin.campagnes-courriel.index') }}" class="hover:underline">Campagnes</a>
        <span>&rsaquo;</span>
        <span>Groupes enregistrés</span>
    </div>

    <div class="mt-2 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 pb-6 border-b border-slate-200">
        <div>
            <h1 class="text-2xl sm:text-3xl font-black text-slate-900 tracking-tight">Groupes de destinataires</h1>
            <p class="mt-1 text-sm text-slate-500">
                Bibliothèque de segments d’audience dynamiques et de listes fixes réutilisables.
            </p>
        </div>
        <div class="flex items-center gap-3">
            <button wire:click="ouvrirCreation" class="inline-flex items-center gap-2 rounded-xl bg-primary-600 px-4 py-2.5 text-xs font-bold text-white shadow-sm hover:bg-primary-700 transition">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                Créer un groupe
            </button>
        </div>
    </div>

    @if($messageSucces)
        <div class="mt-4 rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm font-semibold text-emerald-800">
            {{ $messageSucces }}
        </div>
    @endif

    {{-- Filtres & Recherche --}}
    <div class="mt-6 flex flex-col sm:flex-row items-center justify-between gap-4">
        <div class="w-full sm:w-80">
            <input type="search" wire:model.live.debounce.300ms="recherche" placeholder="Rechercher un groupe..." class="w-full rounded-xl border border-slate-200 bg-white px-4 py-2 text-sm focus:border-primary-500 focus:outline-none" />
        </div>
        <label class="flex items-center gap-2 text-xs font-semibold text-slate-700 cursor-pointer">
            <input type="checkbox" wire:model.live="afficherArchives" class="rounded text-primary-600 focus:ring-primary-500" />
            Afficher les groupes archivés
        </label>
    </div>

    {{-- Table des groupes --}}
    <div class="mt-4 rounded-2xl border border-slate-200 bg-white shadow-sm overflow-hidden">
        <table class="w-full text-left text-sm">
            <thead class="bg-slate-50 text-[11px] font-bold uppercase tracking-wider text-slate-500 border-b border-slate-200">
                <tr>
                    <th class="px-5 py-3">Nom du groupe</th>
                    <th class="px-5 py-3">Audience & Type</th>
                    <th class="px-5 py-3">Statut</th>
                    <th class="px-5 py-3">Créé le</th>
                    <th class="px-5 py-3 text-right">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse($groupes as $groupe)
                    <tr class="hover:bg-slate-50/60 transition {{ $groupe->est_archive ? 'opacity-60 bg-slate-50/40' : '' }}">
                        <td class="px-5 py-3.5">
                            <span class="font-bold text-slate-900">{{ $groupe->nom }}</span>
                            @if($groupe->description)
                                <p class="text-xs text-slate-500">{{ $groupe->description }}</p>
                            @endif
                        </td>
                        <td class="px-5 py-3.5">
                            <span class="inline-flex rounded-md bg-slate-100 px-2.5 py-0.5 text-xs font-semibold text-slate-700">
                                {{ ucfirst(str_replace('_', ' ', $groupe->type)) }}
                            </span>
                        </td>
                        <td class="px-5 py-3.5">
                            @if($groupe->est_archive)
                                <span class="inline-flex items-center rounded-full bg-slate-100 px-2 py-0.5 text-[11px] font-bold text-slate-600">
                                    Archivé
                                </span>
                            @else
                                <span class="inline-flex items-center rounded-full bg-emerald-50 px-2 py-0.5 text-[11px] font-bold text-emerald-700">
                                    Actif
                                </span>
                            @endif
                        </td>
                        <td class="px-5 py-3.5 text-xs text-slate-500">
                            {{ $groupe->created_at->format('d/m/Y') }}
                        </td>
                        <td class="px-5 py-3.5 text-right space-x-1 whitespace-nowrap">
                            @can('campagnes.manage')
                                <button wire:click="editerGroupe({{ $groupe->id }})" class="rounded-lg border border-slate-200 px-2.5 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-50">
                                    Modifier
                                </button>
                                <button wire:click="dupliquerGroupe({{ $groupe->id }})" class="rounded-lg border border-slate-200 px-2.5 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-50">
                                    Dupliquer
                                </button>
                                <button wire:click="archiverGroupe({{ $groupe->id }})" class="rounded-lg border border-slate-200 px-2.5 py-1.5 text-xs font-semibold text-slate-600 hover:bg-slate-50">
                                    {{ $groupe->est_archive ? 'Restaurer' : 'Archiver' }}
                                </button>
                            @endcan
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="px-5 py-10 text-center text-sm text-slate-500">
                            Aucun groupe enregistré.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>

        @if($groupes->hasPages())
            <div class="px-5 py-4 border-t border-slate-200">
                {{ $groupes->links() }}
            </div>
        @endif
    </div>

    {{-- Modale Création / Édition --}}
    @if($modalOuverte)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 p-4">
            <div class="w-full max-w-xl rounded-2xl bg-white p-6 shadow-xl border border-slate-200 space-y-4">
                <div class="flex items-center justify-between border-b border-slate-100 pb-3">
                    <h3 class="text-lg font-bold text-slate-900">
                        {{ $groupeEnCoursId ? 'Modifier le groupe' : 'Nouveau groupe de destinataires' }}
                    </h3>
                    <button wire:click="$set('modalOuverte', false)" class="text-slate-400 hover:text-slate-600">✕</button>
                </div>

                @if($messageErreur)
                    <div class="rounded-lg bg-rose-50 p-3 text-xs font-semibold text-rose-800">
                        {{ $messageErreur }}
                    </div>
                @endif

                <div class="space-y-3">
                    <div>
                        <label class="block text-xs font-bold text-slate-700">Nom du groupe</label>
                        <input type="text" wire:model.live="nom" placeholder="Ex : Étudiants L3 Informatique 2026" maxlength="120" class="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2 text-sm" />
                    </div>

                    <div>
                        <label class="block text-xs font-bold text-slate-700">Description (facultative)</label>
                        <input type="text" wire:model.live="description" placeholder="Description courte..." maxlength="255" class="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2 text-sm" />
                    </div>

                    <div>
                        <label class="block text-xs font-bold text-slate-700">Type de groupe</label>
                        <select wire:model.live="type" class="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2 text-sm">
                            <option value="dynamique_etudiants">Dynamique (Étudiants)</option>
                            <option value="dynamique_enseignants">Dynamique (Enseignants)</option>
                        </select>
                    </div>

                    {{-- Critères dynamiques étudiants --}}
                    @if($type === 'dynamique_etudiants')
                        <div class="p-3 bg-slate-50 rounded-xl space-y-2 border border-slate-100">
                            <p class="text-xs font-bold text-slate-700">Critères de ciblage</p>
                            <div class="grid grid-cols-2 gap-2 text-xs">
                                <div>
                                    <label class="font-medium text-slate-600">Faculté</label>
                                    <select wire:model.live="filtreFaculteId" class="mt-0.5 w-full rounded-lg border border-slate-200 bg-white px-2 py-1 text-xs">
                                        <option value="">Toutes</option>
                                        @foreach($facultes as $f)
                                            <option value="{{ $f->id }}">{{ $f->nom }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div>
                                    <label class="font-medium text-slate-600">Programme</label>
                                    <select wire:model.live="filtreProgrammeId" class="mt-0.5 w-full rounded-lg border border-slate-200 bg-white px-2 py-1 text-xs">
                                        <option value="">Tous</option>
                                        @foreach($programmes as $p)
                                            <option value="{{ $p->id }}">{{ $p->nom }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div>
                                    <label class="font-medium text-slate-600">Niveau</label>
                                    <select wire:model.live="filtreNiveau" class="mt-0.5 w-full rounded-lg border border-slate-200 bg-white px-2 py-1 text-xs">
                                        <option value="">Tous</option>
                                        <option value="L1">L1</option>
                                        <option value="L2">L2</option>
                                        <option value="L3">L3</option>
                                        <option value="M1">M1</option>
                                        <option value="M2">M2</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="font-medium text-slate-600">Année académique</label>
                                    <select wire:model.live="filtreAnneeAcademique" class="mt-0.5 w-full rounded-lg border border-slate-200 bg-white px-2 py-1 text-xs">
                                        <option value="">Toutes</option>
                                        <option value="2025-2026">2025-2026</option>
                                        <option value="2026-2027">2026-2027</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                    @endif

                    {{-- Critères dynamiques enseignants --}}
                    @if($type === 'dynamique_enseignants')
                        <div class="p-3 bg-slate-50 rounded-xl space-y-2 border border-slate-100">
                            <p class="text-xs font-bold text-slate-700">Critères de ciblage</p>
                            <div class="grid grid-cols-2 gap-2 text-xs">
                                <div>
                                    <label class="font-medium text-slate-600">Département</label>
                                    <select wire:model.live="filtreDepartementId" class="mt-0.5 w-full rounded-lg border border-slate-200 bg-white px-2 py-1 text-xs">
                                        <option value="">Tous</option>
                                        @foreach($departements as $d)
                                            <option value="{{ $d->id }}">{{ $d->nom }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div>
                                    <label class="font-medium text-slate-600">Grade</label>
                                    <select wire:model.live="filtreGradeId" class="mt-0.5 w-full rounded-lg border border-slate-200 bg-white px-2 py-1 text-xs">
                                        <option value="">Tous</option>
                                        @foreach($grades as $gr)
                                            <option value="{{ $gr->id }}">{{ $gr->nom }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>
                        </div>
                    @endif

                    <div class="rounded-lg bg-primary-50 p-3 flex items-center justify-between text-xs">
                        <span class="font-semibold text-primary-900">Membres éligibles calculés en temps réel :</span>
                        <span class="font-bold text-primary-800 text-sm tabular-nums">{{ number_format($estimationMembres) }}</span>
                    </div>
                </div>

                <div class="flex items-center justify-end gap-2 pt-3 border-t border-slate-100">
                    <button wire:click="$set('modalOuverte', false)" class="rounded-xl border border-slate-300 px-4 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-50">
                        Annuler
                    </button>
                    <button wire:click="sauvegarder" class="rounded-xl bg-primary-600 px-4 py-2 text-xs font-bold text-white shadow-sm hover:bg-primary-700">
                        Enregistrer le groupe
                    </button>
                </div>
            </div>
        </div>
    @endif
</div>
