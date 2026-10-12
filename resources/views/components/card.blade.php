{{--
    Carte sobre (§5) + titre optionnel.
--}}
@props(['titre' => null])

<section {{ $attributes->merge(['class' => 'ds-card p-5 sm:p-6 shadow-sm']) }}>
    @if($titre !== null)
        <h2 class="text-base font-black text-slate-900">{{ $titre }}</h2>
        <div class="mt-3">{{ $slot }}</div>
    @else
        {{ $slot }}
    @endif
</section>
