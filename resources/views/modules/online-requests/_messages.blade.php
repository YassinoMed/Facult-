{{-- Fil de discussion (Phase 9 §61) : partagé admin / étudiant.
     Variables : $demande (messages.auteur eager), $action (route POST), $peutEcrire (bool). --}}
<section aria-label="Discussion" class="mt-6 rounded-2xl border border-slate-200 bg-white shadow-sm overflow-hidden">
    <h2 class="px-5 py-3 border-b border-slate-100 text-sm font-black text-slate-900">Discussion ({{ $demande->messages->count() }})</h2>
    @if($demande->messages->isNotEmpty())
        <ul class="divide-y divide-slate-100">
            @foreach($demande->messages as $message)
                <li class="px-5 py-3 text-sm">
                    <p class="text-slate-800">{{ $message->message }}</p>
                    <p class="mt-1 text-[11px] text-slate-500">
                        {{ $message->estAdministration() ? ($message->auteur?->name ?? 'Administration') : 'Vous' }}
                        · {{ $message->created_at->format('d/m/Y H:i') }}
                    </p>
                </li>
            @endforeach
        </ul>
    @else
        <div class="p-5"><x-empty-state message="Aucun message." /></div>
    @endif
    @if($peutEcrire)
        <form method="POST" action="{{ $action }}" class="flex flex-col sm:flex-row gap-2 border-t border-slate-100 px-5 py-4">
            @csrf
            <input type="text" name="message" required maxlength="2000" placeholder="Écrire un message…"
                   aria-label="Écrire un message" class="flex-1 rounded-xl border border-slate-300 px-3 py-2 text-sm">
            <button type="submit" class="rounded-xl bg-primary-800 px-5 py-2.5 text-sm font-bold text-white hover:bg-primary-700 min-h-[44px]">Envoyer</button>
        </form>
    @endif
</section>
