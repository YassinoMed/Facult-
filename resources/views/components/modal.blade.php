{{--
    Modale accessible (§25) : titre, explication, Escape, clic extérieur,
    focus initial. Pilotée par Alpine du parent (x-data + open).
    Usage : <div x-data="{ ouvert: false }"> <x-btn @click="ouvert = true">… <x-modal titre="…" nom="ouvert">…actions…</x-modal> </div>
--}}
@props(['titre', 'nom' => 'ouvert'])

<div x-show="{{ $nom }}" x-cloak
     @keydown.escape.window="{{ $nom }} = false"
     x-transition:enter="transition ease-out duration-150" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
     x-transition:leave="transition ease-in duration-100" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0"
     class="fixed inset-0 z-[70] flex items-center justify-center p-4" role="dialog" aria-modal="true" aria-label="{{ $titre }}">
    <div class="absolute inset-0 bg-slate-900/50" @click="{{ $nom }} = false" aria-hidden="true"></div>
    <div x-trap.noscroll="{{ $nom }}"
         class="relative w-full max-w-md rounded-2xl border border-slate-200 bg-white p-6 shadow-xl">
        <h2 class="text-base font-black text-slate-900">{{ $titre }}</h2>
        <div class="mt-2 text-sm text-slate-600">{{ $slot }}</div>
        @if(isset($actions))
            <div class="mt-5 flex flex-col-reverse sm:flex-row sm:justify-end gap-2">{{ $actions }}</div>
        @endif
        <button type="button" @click="{{ $nom }} = false" aria-label="Fermer"
                class="absolute top-3 right-3 rounded-lg p-2 text-slate-400 hover:bg-slate-100 hover:text-slate-700 min-h-[44px] min-w-[44px]">✕</button>
    </div>
</div>
