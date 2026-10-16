<div class="mx-auto max-w-7xl px-4 sm:px-6 py-8">
    <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4 pb-6 border-b border-slate-200">
        <div>
            <div class="flex items-center gap-2 text-xs font-semibold uppercase tracking-wider text-primary-700">
                <a href="{{ route('admin.courriels.index') }}" class="hover:underline">Courriels</a>
                <span>&rsaquo;</span>
                <span>Campagnes d'envoi</span>
            </div>
            <h1 class="mt-1 text-2xl sm:text-3xl font-black text-slate-900 tracking-tight">Campagnes de courriels</h1>
            <p class="mt-1 text-sm text-slate-500">
                Communication ciblée multicanale vers les étudiants et enseignants universitaires.
            </p>
        </div>
        <div class="flex flex-wrap items-center gap-3">
            <a href="{{ route('admin.campagnes-courriel.groupes') }}" class="inline-flex items-center gap-2 rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-xs font-bold text-slate-700 shadow-sm hover:bg-slate-50 transition">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M18 18.72a9.094 9.094 0 0 0 3.741-.479 3 3 0 0 0-4.682-2.72m.94 3.198.001.031c0 .225-.012.447-.037.666A11.944 11.944 0 0 1 12 21c-2.17 0-4.207-.576-5.963-1.584A6.062 6.062 0 0 1 6 18.719m12 0a5.971 5.971 0 0 0-.941-3.197m0 0A5.995 5.995 0 0 0 12 12.75a5.995 5.995 0 0 0-5.058 2.772m0 0a3 3 0 0 0-4.681 2.72 8.986 8.986 0 0 0 3.74.477m.94-3.197a5.971 5.971 0 0 0-.94 3.197M15 6.75a3 3 0 1 1-6 0 3 3 0 0 1 6 0Zm6 3a2.25 2.25 0 1 1-4.5 0 2.25 2.25 0 0 1 4.5 0Zm-13.5 0a2.25 2.25 0 1 1-4.5 0 2.25 2.25 0 0 1 4.5 0Z" /></svg>
                Groupes enregistrés
            </a>
            @can('campagnes.manage')
            <a href="{{ route('admin.campagnes-courriel.composer') }}" class="inline-flex items-center gap-2 rounded-xl bg-primary-600 px-4 py-2.5 text-xs font-bold text-white shadow-sm hover:bg-primary-700 transition">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                Nouvelle campagne
            </a>
            @endcan
        </div>
    </div>

    @if($messageSucces)
        <div class="mt-4 rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm font-semibold text-emerald-800">
            {{ $messageSucces }}
        </div>
    @endif
    @if($messageErreur)
        <div class="mt-4 rounded-xl border border-rose-200 bg-rose-50 p-4 text-sm font-semibold text-rose-800">
            {{ $messageErreur }}
        </div>
    @endif

    {{-- KPIs --}}
    <div class="mt-6 grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-3">
        <button wire:click="$set('filtreStatut', '')" class="rounded-2xl border p-4 text-left transition {{ $filtreStatut === '' ? 'border-primary-500 bg-primary-50/50 shadow-sm' : 'border-slate-200 bg-white hover:border-slate-300' }}">
            <p class="text-[11px] font-bold uppercase tracking-wider text-slate-500">Toutes</p>
            <p class="mt-1 text-2xl font-black text-slate-900">{{ $compteurs['total'] }}</p>
        </button>

        <button wire:click="$set('filtreStatut', 'brouillon')" class="rounded-2xl border p-4 text-left transition {{ $filtreStatut === 'brouillon' ? 'border-amber-500 bg-amber-50/50 shadow-sm' : 'border-slate-200 bg-white hover:border-slate-300' }}">
            <p class="text-[11px] font-bold uppercase tracking-wider text-slate-500">Brouillons</p>
            <p class="mt-1 text-2xl font-black text-amber-600">{{ $compteurs['brouillons'] }}</p>
        </button>

        <button wire:click="$set('filtreStatut', 'planifiee')" class="rounded-2xl border p-4 text-left transition {{ $filtreStatut === 'planifiee' ? 'border-blue-500 bg-blue-50/50 shadow-sm' : 'border-slate-200 bg-white hover:border-slate-300' }}">
            <p class="text-[11px] font-bold uppercase tracking-wider text-slate-500">Planifiées</p>
            <p class="mt-1 text-2xl font-black text-blue-600">{{ $compteurs['planifiees'] }}</p>
        </button>

        <button wire:click="$set('filtreStatut', 'en_cours')" class="rounded-2xl border p-4 text-left transition {{ $filtreStatut === 'en_cours' ? 'border-indigo-500 bg-indigo-50/50 shadow-sm' : 'border-slate-200 bg-white hover:border-slate-300' }}">
            <p class="text-[11px] font-bold uppercase tracking-wider text-slate-500">En cours</p>
            <p class="mt-1 text-2xl font-black text-indigo-600">{{ $compteurs['en_cours'] }}</p>
        </button>

        <button wire:click="$set('filtreStatut', 'terminee')" class="rounded-2xl border p-4 text-left transition {{ $filtreStatut === 'terminee' ? 'border-emerald-500 bg-emerald-50/50 shadow-sm' : 'border-slate-200 bg-white hover:border-slate-300' }}">
            <p class="text-[11px] font-bold uppercase tracking-wider text-slate-500">Terminées</p>
            <p class="mt-1 text-2xl font-black text-emerald-600">{{ $compteurs['terminees'] }}</p>
        </button>
    </div>

    {{-- Filtres & Recherche --}}
    <div class="mt-6 flex flex-col sm:flex-row gap-3">
        <div class="flex-1">
            <input type="search" wire:model.live.debounce.300ms="recherche" placeholder="Rechercher par titre, objet..." class="w-full rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm placeholder-slate-400 focus:border-primary-500 focus:outline-none focus:ring-1 focus:ring-primary-500" />
        </div>
        <div class="w-full sm:w-64">
            <select wire:model.live="filtreStatut" class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm focus:border-primary-500 focus:outline-none focus:ring-1 focus:ring-primary-500">
                <option value="">Tous les statuts</option>
                @foreach($statuts as $statut)
                    <option value="{{ $statut }}">{{ ucfirst(str_replace('_', ' ', $statut)) }}</option>
                @endforeach
            </select>
        </div>
    </div>

    {{-- Table des campagnes --}}
    <div class="mt-4 rounded-2xl border border-slate-200 bg-white shadow-sm overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead class="bg-slate-50 text-[11px] font-bold uppercase tracking-wider text-slate-500 border-b border-slate-200">
                    <tr>
                        <th class="px-5 py-3">Campagne</th>
                        <th class="px-5 py-3">Audience & Type</th>
                        <th class="px-5 py-3">Statut</th>
                        <th class="px-5 py-3 text-right">Destinataires</th>
                        <th class="px-5 py-3 text-right">Progression</th>
                        <th class="px-5 py-3">Date</th>
                        <th class="px-5 py-3 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse($campagnes as $campagne)
                        <tr class="hover:bg-slate-50/60 transition">
                            <td class="px-5 py-3.5">
                                <a href="{{ route('admin.campagnes-courriel.details', $campagne) }}" class="font-bold text-slate-900 hover:text-primary-700">
                                    {{ $campagne->titre }}
                                </a>
                                <p class="text-xs text-slate-500 truncate max-w-xs">{{ $campagne->sujet }}</p>
                            </td>
                            <td class="px-5 py-3.5">
                                <span class="inline-flex items-center rounded-md bg-slate-100 px-2 py-0.5 text-xs font-semibold text-slate-700">
                                    {{ str_replace('_', ' ', $campagne->type_ciblage) }}
                                </span>
                            </td>
                            <td class="px-5 py-3.5">
                                @php
                                    $couleurs = [
                                        'brouillon' => 'bg-amber-50 text-amber-700 border-amber-200',
                                        'planifiee' => 'bg-blue-50 text-blue-700 border-blue-200',
                                        'en_attente' => 'bg-sky-50 text-sky-700 border-sky-200',
                                        'en_cours' => 'bg-indigo-50 text-indigo-700 border-indigo-200 animate-pulse',
                                        'terminee' => 'bg-emerald-50 text-emerald-700 border-emerald-200',
                                        'terminee_avec_erreurs' => 'bg-orange-50 text-orange-700 border-orange-200',
                                        'annulee' => 'bg-slate-100 text-slate-700 border-slate-200',
                                        'echouee' => 'bg-rose-50 text-rose-700 border-rose-200',
                                    ];
                                @endphp
                                <span class="inline-flex items-center rounded-full border px-2.5 py-0.5 text-[11px] font-bold {{ $couleurs[$campagne->statut] ?? 'bg-slate-100 text-slate-700 border-slate-200' }}">
                                    {{ ucfirst(str_replace('_', ' ', $campagne->statut)) }}
                                </span>
                            </td>
                            <td class="px-5 py-3.5 text-right font-medium tabular-nums text-slate-700">
                                {{ number_format($campagne->total_destinataires) }}
                            </td>
                            <td class="px-5 py-3.5 text-right tabular-nums">
                                @if($campagne->total_destinataires > 0)
                                    @php
                                        $pourcentage = round(($campagne->total_envoyes / $campagne->total_destinataires) * 100);
                                    @endphp
                                    <div class="flex items-center justify-end gap-2">
                                        <div class="w-16 bg-slate-100 rounded-full h-2 overflow-hidden">
                                            <div class="bg-primary-600 h-2 rounded-full" style="width: {{ $pourcentage }}%"></div>
                                        </div>
                                        <span class="text-xs font-semibold text-slate-600">{{ $pourcentage }}%</span>
                                    </div>
                                    @if($campagne->total_echecs > 0)
                                        <span class="text-[10px] font-bold text-rose-600">{{ $campagne->total_echecs }} échec(s)</span>
                                    @endif
                                @else
                                    <span class="text-xs text-slate-400">—</span>
                                @endif
                            </td>
                            <td class="px-5 py-3.5 text-xs text-slate-500 whitespace-nowrap">
                                @if($campagne->planifiee_a)
                                    <span title="Planifiée pour le {{ $campagne->planifiee_a->format('d/m/Y H:i') }}">
                                        📅 {{ $campagne->planifiee_a->format('d/m/Y H:i') }}
                                    </span>
                                @elseif($campagne->commencee_a)
                                    <span>{{ $campagne->commencee_a->format('d/m/Y H:i') }}</span>
                                @else
                                    <span>{{ $campagne->created_at->format('d/m/Y') }}</span>
                                @endif
                            </td>
                            <td class="px-5 py-3.5 text-right space-x-1 whitespace-nowrap">
                                <a href="{{ route('admin.campagnes-courriel.details', $campagne) }}" class="rounded-lg border border-slate-200 bg-white px-2.5 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-50">
                                    Détails
                                </a>
                                @if($campagne->peutEtreModifiee() && auth()->user()->hasPermission('campagnes.manage'))
                                    <a href="{{ route('admin.campagnes-courriel.composer', $campagne) }}" class="rounded-lg border border-primary-200 bg-primary-50 px-2.5 py-1.5 text-xs font-semibold text-primary-700 hover:bg-primary-100">
                                        Modifier
                                    </a>
                                @endif
                                @if($campagne->peutEtreAnnulee() && auth()->user()->hasPermission('campagnes.manage'))
                                    <button wire:click="annulerCampagne({{ $campagne->id }})" wire:confirm="Confirmer l'annulation de cette campagne ?" class="rounded-lg border border-slate-200 px-2.5 py-1.5 text-xs font-semibold text-slate-600 hover:bg-rose-50 hover:text-rose-700">
                                        Annuler
                                    </button>
                                @endif
                                @if($campagne->peutEtreRelancee() && auth()->user()->hasPermission('campagnes.manage') && auth()->user()->hasPermission('mail.send'))
                                    <button wire:click="relancerCampagne({{ $campagne->id }})" wire:confirm="Relancer les destinataires en échec ?" class="rounded-lg border border-amber-300 bg-amber-50 px-2.5 py-1.5 text-xs font-bold text-amber-800 hover:bg-amber-100">
                                        Relancer
                                    </button>
                                @endif
                                @if($campagne->statut === 'brouillon' && auth()->user()->hasPermission('campagnes.manage'))
                                    <button wire:click="supprimerBrouillon({{ $campagne->id }})" wire:confirm="Supprimer définitivement ce brouillon ?" class="rounded-lg p-1.5 text-slate-400 hover:text-rose-600">
                                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0" /></svg>
                                    </button>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-5 py-12 text-center text-sm text-slate-500">
                                Aucune campagne trouvée.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if($campagnes->hasPages())
            <div class="px-5 py-4 border-t border-slate-200">
                {{ $campagnes->links() }}
            </div>
        @endif
    </div>
</div>
