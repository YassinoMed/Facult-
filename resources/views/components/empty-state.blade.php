{{--
    État vide réutilisable : message explicite, titre, icône et action optionnels.
    Reste 100% rétrocompatible avec <x-empty-state :message="..." />.
--}}
@props(['message' => null, 'titre' => null, 'icone' => null])

<div {{ $attributes->merge(['class' => 'rounded-2xl border border-dashed border-slate-200 bg-slate-50/70 px-6 py-10 text-center']) }} role="status">
    <div class="mx-auto mb-3 flex h-12 w-12 items-center justify-center rounded-2xl bg-white shadow-xs text-slate-400 border border-slate-200/80" aria-hidden="true">
        @if($icone)
            <svg class="h-6 w-6 text-slate-400" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="{{ $icone }}" />
            </svg>
        @else
            <svg class="h-6 w-6 text-slate-400" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M20.25 7.5l-.625 10.632a2.25 2.25 0 01-2.247 2.118H6.622a2.25 2.25 0 01-2.247-2.118L3.75 7.5M10 11.25h4M3.375 7.5h17.25c.621 0 1.125-.504 1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125z" />
            </svg>
        @endif
    </div>
    @if($titre)
        <h3 class="text-sm font-bold text-slate-800">{{ $titre }}</h3>
    @endif
    <p class="text-xs text-slate-500 max-w-sm mx-auto {{ $titre ? 'mt-1' : '' }}">
        {{ $message ?? $slot }}
    </p>
    @if(isset($action))
        <div class="mt-4 flex justify-center">
            {{ $action }}
        </div>
    @endif
</div>
