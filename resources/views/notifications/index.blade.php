@extends('layouts.app')

@section('title', 'Notifications')

@section('content')
    <div class="mx-auto max-w-3xl px-4 sm:px-6 py-8">
        <x-page-header titre="Notifications" :description="$nonLues > 0 ? $nonLues . ' non lue(s).' : 'Vous êtes à jour.'" :fil="['Accueil' => $retour, 'Notifications' => '#']">
            <x-slot:actions>
                @if($nonLues > 0)
                    <form method="POST" action="{{ $actionToutLire }}">
                        @csrf
                        <x-btn variant="secondary" type="submit">Tout marquer comme lu</x-btn>
                    </form>
                @endif
                @if(request()->routeIs('etudiant.*'))
                    <x-btn variant="secondary" size="sm" href="{{ route('etudiant.notifications.preferences') }}">Préférences</x-btn>
                @endif
            </x-slot:actions>
        </x-page-header>

        <form method="GET" class="mt-4 flex flex-wrap gap-2" aria-label="Filtres">
            @foreach(['toutes' => 'Toutes', 'non-lues' => 'Non lues', 'importantes' => 'Importantes'] as $valeur => $libelle)
                <a href="?{{ http_build_query(array_merge(request()->except('page'), ['filtre' => $valeur])) }}"
                   aria-current="{{ ($filtre ?? 'toutes') === $valeur ? 'page' : 'false' }}"
                   class="rounded-full border px-3 py-1.5 text-xs font-bold {{ ($filtre ?? 'toutes') === $valeur ? 'border-primary-700 bg-primary-50 text-primary-900' : 'border-slate-200 text-slate-500' }}">{{ $libelle }}</a>
            @endforeach
        </form>

        <section aria-label="Liste des notifications" class="mt-4 ds-card overflow-hidden">
            @if($notifications->isNotEmpty())
                <ul class="divide-y divide-slate-100">
                    @foreach($notifications as $notification)
                        <li class="px-5 py-4 {{ $notification->read_at === null ? 'bg-primary-50/50' : '' }}">
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <p class="flex items-center gap-2 text-sm font-black text-slate-900">
                                        @if($notification->read_at === null)
                                            <span class="h-2 w-2 flex-shrink-0 rounded-full bg-primary-700" aria-hidden="true"></span>
                                        @endif
                                        {{ $notification->titre }}
                                    </p>
                                    <p class="mt-0.5 text-sm text-slate-600">{{ $notification->message }}</p>
                                    <div class="mt-1 flex flex-wrap items-center gap-2">
                                        <span class="text-[11px] text-slate-400 tabular-nums">{{ $notification->created_at->format('d/m/Y H:i') }}</span>
                                        @if($notification->data['remise_confirmee'] ?? false)
                                            <span class="inline-flex items-center rounded-full bg-emerald-100 text-emerald-800 text-[10px] px-2 py-0.5 font-bold">
                                                ✓ Remise confirmée ({{ $notification->data['mode_preuve'] ?? 'preuve' }})
                                            </span>
                                        @elseif($notification->data['en_contestation'] ?? false)
                                            <span class="inline-flex items-center rounded-full bg-amber-100 text-amber-800 text-[10px] px-2 py-0.5 font-bold">
                                                ⚖️ En contestation
                                            </span>
                                        @elseif(($notification->data['statut_remise'] ?? '') === 'refusee')
                                            <span class="inline-flex items-center rounded-full bg-rose-100 text-rose-800 text-[10px] px-2 py-0.5 font-bold">
                                                ⚠️ Preuve refusée
                                            </span>
                                        @elseif(($notification->data['statut_envoi'] ?? '') === 'envoye')
                                            <span class="inline-flex items-center rounded-full bg-slate-100 text-slate-600 text-[10px] px-2 py-0.5 font-medium">
                                                ✉️ Acheminé (remise non confirmée)
                                            </span>
                                        @endif
                                    </div>
                                    @if(request()->routeIs('etudiant.*') && ($notification->data['statut_remise'] ?? null) === 'refusee')
                                        <details class="mt-3 rounded-lg border border-amber-200 bg-amber-50/60 p-3">
                                            <summary class="cursor-pointer text-xs font-bold text-amber-900">Contester ce refus de preuve</summary>
                                            <form method="POST" action="{{ route('etudiant.notifications.contester', $notification) }}" enctype="multipart/form-data" class="mt-3 space-y-2">
                                                @csrf
                                                <div>
                                                    <label for="motif-contestation-{{ $notification->id }}" class="block text-xs font-bold text-slate-700">Motif de la contestation</label>
                                                    <textarea id="motif-contestation-{{ $notification->id }}" name="motif_contestation" rows="3" required minlength="10" maxlength="2000" class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm">{{ old('motif_contestation') }}</textarea>
                                                    @error('motif_contestation')<p class="mt-1 text-xs text-rose-700">{{ $message }}</p>@enderror
                                                </div>
                                                <div>
                                                    <label for="piece-contestation-{{ $notification->id }}" class="block text-xs font-bold text-slate-700">Pièce justificative (facultative, PDF ou image, 5 Mo max)</label>
                                                    <input id="piece-contestation-{{ $notification->id }}" type="file" name="piece_contestation" accept=".pdf,.jpg,.jpeg,.png" class="mt-1 block w-full text-xs">
                                                    @error('piece_contestation')<p class="mt-1 text-xs text-rose-700">{{ $message }}</p>@enderror
                                                </div>
                                                <p class="text-[11px] text-slate-500">La contestation est enregistrée sans modifier le statut. Elle doit être portée par la voie officielle de recours compétente.</p>
                                                <x-btn variant="secondary" type="submit">Enregistrer la contestation</x-btn>
                                            </form>
                                        </details>
                                    @endif
                                </div>
                                @if($notification->lien !== null)
                                    <form method="POST" action="{{ request()->routeIs('admin.*') ? route('admin.notifications.lire', $notification) : (request()->routeIs('enseignant.*') ? route('enseignant.notifications.lire', $notification) : route('etudiant.notifications.lire', $notification)) }}" class="flex-shrink-0">
                                        @csrf @method('PATCH')
                                        <button type="submit" class="text-xs font-bold text-primary-800 underline underline-offset-2">Ouvrir</button>
                                    </form>
                                @elseif($notification->read_at === null)
                                    <form method="POST" action="{{ request()->routeIs('admin.*') ? route('admin.notifications.lire', $notification) : (request()->routeIs('enseignant.*') ? route('enseignant.notifications.lire', $notification) : route('etudiant.notifications.lire', $notification)) }}" class="flex-shrink-0">
                                        @csrf @method('PATCH')
                                        <button type="submit" class="text-xs font-bold text-primary-800 underline underline-offset-2">Marquer lue</button>
                                    </form>
                                @endif
                            </div>
                        </li>
                    @endforeach
                </ul>
                <div class="px-5 py-3 border-t border-slate-100">{{ $notifications->links() }}</div>
            @else
                <div class="p-5">
                    <x-empty-state message="Aucune notification pour le moment." />
                </div>
            @endif
        </section>
    </div>
@endsection
