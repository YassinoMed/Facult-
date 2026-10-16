<div class="mx-auto max-w-7xl px-4 sm:px-6 py-8">
    {{-- Fil d'ariane & En-tête --}}
    <div class="flex items-center gap-2 text-xs font-semibold uppercase tracking-wider text-primary-700">
        <a href="{{ route('admin.courriels.index') }}" class="hover:underline">Courriels</a>
        <span>&rsaquo;</span>
        <a href="{{ route('admin.campagnes-courriel.index') }}" class="hover:underline">Campagnes</a>
        <span>&rsaquo;</span>
        <span>Suivi de campagne</span>
    </div>

    <div class="mt-2 flex flex-col md:flex-row md:items-center md:justify-between gap-4 pb-6 border-b border-slate-200">
        <div>
            <div class="flex items-center gap-3">
                <h1 class="text-2xl sm:text-3xl font-black text-slate-900 tracking-tight">
                    {{ $campagne->titre }}
                </h1>
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
                <span class="inline-flex items-center rounded-full border px-3 py-1 text-xs font-bold {{ $couleurs[$campagne->statut] ?? 'bg-slate-100 text-slate-700 border-slate-200' }}">
                    {{ ucfirst(str_replace('_', ' ', $campagne->statut)) }}
                </span>
            </div>
            <p class="mt-1 text-sm text-slate-500">
                Objet : <span class="font-semibold text-slate-700">{{ $campagne->sujet }}</span>
                @if($campagne->auteur)
                    · Créée par <span class="font-medium text-slate-700">{{ $campagne->auteur->name }}</span>
                @endif
                · Le {{ $campagne->created_at->format('d/m/Y H:i') }}
            </p>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <button wire:click="rafraichir" class="inline-flex items-center gap-1.5 rounded-xl border border-slate-300 bg-white px-3.5 py-2 text-xs font-bold text-slate-700 shadow-sm hover:bg-slate-50 transition">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0 3.181 3.183a8.25 8.25 0 0 0 13.803-3.7M4.031 9.865a8.25 8.25 0 0 1 13.803-3.7l3.181 3.182m0-4.991v4.99" /></svg>
                Actualiser
            </button>

            @if($campagne->peutEtreAnnulee() && auth()->user()->hasPermission('campagnes.manage'))
                <button wire:click="annuler" wire:confirm="Confirmer l'annulation de la campagne ? Les lots non démarrés ne seront pas envoyés." class="rounded-xl border border-rose-300 bg-white px-3.5 py-2 text-xs font-bold text-rose-700 shadow-sm hover:bg-rose-50 transition">
                    Annuler la campagne
                </button>
            @endif

            @if($campagne->peutEtreRelancee() && auth()->user()->hasPermission('campagnes.manage') && auth()->user()->hasPermission('mail.send'))
                <button wire:click="relancerEchecs" wire:confirm="Relancer sélectivement les destinataires en échec ?" class="rounded-xl bg-amber-600 px-4 py-2 text-xs font-bold text-white shadow-sm hover:bg-amber-700 transition">
                    Relancer les échecs ({{ $campagne->total_echecs }})
                </button>
            @endif

            @if($campagne->peutEtreModifiee() && auth()->user()->hasPermission('campagnes.manage'))
                <a href="{{ route('admin.campagnes-courriel.composer', $campagne) }}" class="rounded-xl bg-primary-600 px-4 py-2 text-xs font-bold text-white shadow-sm hover:bg-primary-700 transition">
                    Reprendre l'édition
                </a>
            @endif
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

    {{-- Synthèse & Barre de progression --}}
    <div class="mt-6 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
        <div class="flex items-center justify-between text-sm mb-2">
            <span class="font-bold text-slate-900">Progression globale</span>
            @php
                $pct = $campagne->total_destinataires > 0 ? round(($campagne->total_envoyes / $campagne->total_destinataires) * 100) : 0;
            @endphp
            <span class="font-extrabold text-primary-700 tabular-nums">{{ $pct }} % achevé</span>
        </div>
        <div class="h-3 w-full rounded-full bg-slate-100 overflow-hidden flex">
            <div class="bg-emerald-500 h-full transition-all duration-500" style="width: {{ $pct }}%"></div>
            @if($campagne->total_destinataires > 0 && $campagne->total_echecs > 0)
                @php $pctErr = round(($campagne->total_echecs / $campagne->total_destinataires) * 100); @endphp
                <div class="bg-rose-500 h-full transition-all duration-500" style="width: {{ $pctErr }}%"></div>
            @endif
        </div>

        <div class="mt-6 grid grid-cols-2 sm:grid-cols-5 gap-4 pt-6 border-t border-slate-100 text-center">
            <div>
                <p class="text-[11px] font-bold uppercase tracking-wider text-slate-500">Destinataires</p>
                <p class="mt-1 text-2xl font-black text-slate-900 tabular-nums">{{ number_format($campagne->total_destinataires) }}</p>
            </div>
            <div>
                <p class="text-[11px] font-bold uppercase tracking-wider text-slate-500">Envoyés</p>
                <p class="mt-1 text-2xl font-black text-emerald-600 tabular-nums">{{ number_format($campagne->total_envoyes) }}</p>
            </div>
            <div>
                <p class="text-[11px] font-bold uppercase tracking-wider text-slate-500">Échecs</p>
                <p class="mt-1 text-2xl font-black text-rose-600 tabular-nums">{{ number_format($campagne->total_echecs) }}</p>
            </div>
            <div>
                <p class="text-[11px] font-bold uppercase tracking-wider text-slate-500">Exclus / Ignorés</p>
                <p class="mt-1 text-2xl font-black text-slate-500 tabular-nums">{{ number_format($campagne->total_ignores) }}</p>
            </div>
            <div>
                <p class="text-[11px] font-bold uppercase tracking-wider text-slate-500">Taille de lot</p>
                <p class="mt-1 text-2xl font-black text-slate-700 tabular-nums">{{ $campagne->taille_lot }}</p>
            </div>
        </div>
    </div>

    {{-- Journal individuel des destinataires --}}
    <div class="mt-8">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
            <div>
                <h2 class="text-lg font-bold text-slate-900">Résultats par destinataire</h2>
                <p class="text-xs text-slate-500">Traçabilité individuelle sans exposition d'adresses en clair (conformité vie privée).</p>
            </div>

            <div class="flex items-center gap-3">
                <input type="search" wire:model.live.debounce.300ms="rechercheDestinataire" placeholder="Recherche par nom..." class="rounded-xl border border-slate-200 bg-white px-3 py-1.5 text-xs focus:border-primary-500 focus:outline-none" />

                <select wire:model.live="filtreStatutDestinataire" class="rounded-xl border border-slate-200 bg-white px-3 py-1.5 text-xs focus:border-primary-500 focus:outline-none">
                    <option value="">Tous les statuts</option>
                    @foreach($statutsDestinataires as $st)
                        <option value="{{ $st }}">{{ ucfirst(str_replace('_', ' ', $st)) }}</option>
                    @endforeach
                </select>
            </div>
        </div>

        <div class="mt-4 rounded-2xl border border-slate-200 bg-white shadow-sm overflow-hidden">
            <table class="w-full text-left text-sm">
                <thead class="bg-slate-50 text-[11px] font-bold uppercase tracking-wider text-slate-500 border-b border-slate-200">
                    <tr>
                        <th class="px-5 py-3">Destinataire</th>
                        <th class="px-5 py-3">Type</th>
                        <th class="px-5 py-3">Statut</th>
                        <th class="px-5 py-3 text-right">Tentatives</th>
                        <th class="px-5 py-3">Date d'envoi</th>
                        <th class="px-5 py-3">Diagnostic / Erreur</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse($destinataires as $dest)
                        <tr class="hover:bg-slate-50/60 transition">
                            <td class="px-5 py-3">
                                <span class="font-semibold text-slate-900">{{ $dest->nom_complet ?: 'Non renseigné' }}</span>
                                <span class="block text-xs font-mono text-slate-400">{{ $dest->emailMasque() }}</span>
                            </td>
                            <td class="px-5 py-3">
                                <span class="inline-flex rounded-md bg-slate-100 px-2 py-0.5 text-xs font-semibold text-slate-700">
                                    {{ $dest->type_destinataire }}
                                </span>
                            </td>
                            <td class="px-5 py-3">
                                @php
                                    $badgeDest = [
                                        'en_attente' => 'bg-slate-100 text-slate-700',
                                        'en_cours' => 'bg-indigo-50 text-indigo-700',
                                        'envoye' => 'bg-emerald-50 text-emerald-700',
                                        'echec' => 'bg-rose-50 text-rose-700',
                                        'annule' => 'bg-slate-100 text-slate-500',
                                        'ignore' => 'bg-slate-100 text-slate-400',
                                    ];
                                @endphp
                                <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-[11px] font-bold {{ $badgeDest[$dest->statut] ?? 'bg-slate-100 text-slate-700' }}">
                                    {{ ucfirst(str_replace('_', ' ', $dest->statut)) }}
                                </span>
                            </td>
                            <td class="px-5 py-3 text-right font-medium tabular-nums text-slate-700">
                                {{ $dest->tentatives }}
                            </td>
                            <td class="px-5 py-3 text-xs text-slate-500 whitespace-nowrap">
                                {{ $dest->envoye_a ? $dest->envoye_a->format('d/m/Y H:i:s') : '—' }}
                            </td>
                            <td class="px-5 py-3 text-xs">
                                @if($dest->derniere_erreur)
                                    <span class="font-mono text-rose-700 bg-rose-50 px-2 py-1 rounded inline-block max-w-md truncate" title="{{ $dest->derniere_erreur }}">
                                        {{ $dest->derniere_erreur }}
                                    </span>
                                @else
                                    <span class="text-slate-400">—</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-5 py-10 text-center text-sm text-slate-500">
                                Aucun destinataire ne correspond aux filtres.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>

            @if($destinataires->hasPages())
                <div class="px-5 py-4 border-t border-slate-200">
                    {{ $destinataires->links() }}
                </div>
            @endif
        </div>
    </div>
</div>
