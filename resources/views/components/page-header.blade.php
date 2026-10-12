{{--
    En-tête de page (§40 : où suis-je ? que puis-je faire ?) :
    fil d'Ariane + titre + description + actions.
--}}
@props(['titre', 'description' => null, 'fil' => []])

<nav aria-label="Fil d'Ariane" class="mb-1">
    <ol class="flex flex-wrap items-center gap-1 text-[11px] font-semibold text-slate-400">
        @foreach($fil as $etiquette => $url)
            <li class="flex items-center gap-1">
                @if(!$loop->last)
                    <a href="{{ $url }}" class="hover:text-primary-700 hover:underline underline-offset-2">{{ $etiquette }}</a>
                    <span aria-hidden="true" class="text-slate-300">/</span>
                @else
                    <span aria-current="page" class="text-slate-600">{{ $etiquette }}</span>
                @endif
            </li>
        @endforeach
    </ol>
</nav>

<div class="flex flex-wrap items-start justify-between gap-3">
    <div class="min-w-0">
        <h1 class="text-2xl sm:text-3xl font-black tracking-tight text-slate-900">{{ $titre }}</h1>
        @if($description !== null)
            <p class="mt-1 text-xs sm:text-sm text-slate-500">{{ $description }}</p>
        @endif
    </div>
    @if(isset($actions))
        <div class="flex flex-wrap gap-2">{{ $actions }}</div>
    @endif
</div>
