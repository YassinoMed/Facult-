{{--
    Skeleton de chargement (§20) : forme du contenu final.
--}}
@props(['lignes' => 3])

<div aria-hidden="true" class="space-y-2">
    @for($i = 0; $i < $lignes; $i++)
        <div class="ds-skeleton h-4 {{ $i === $lignes - 1 ? 'w-2/3' : 'w-full' }}">&nbsp;</div>
    @endfor
</div>
<p class="sr-only" role="status">Chargement en cours…</p>
