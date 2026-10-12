{{--
    Timeline d'activité réutilisable : remplace les listes inline en dur.
--}}
@props(['elements'])

<ul class="divide-y divide-slate-100">
    @forelse($elements as $element)
        <li class="flex gap-3 px-4 py-2.5 text-sm">
            <span class="mt-0.5 h-2 w-2 shrink-0 rounded-full bg-primary-600" aria-hidden="true"></span>
            <div class="min-w-0">
                <p class="font-bold text-slate-900">{{ $element['titre'] }}</p>
                @if(!empty($element['detail']))
                    <p class="truncate text-xs text-slate-500">{{ $element['detail'] }}</p>
                @endif
            </div>
            @if(!empty($element['date']))
                <span class="ml-auto shrink-0 text-[11px] tabular-nums text-slate-400">{{ $element['date'] }}</span>
            @endif
        </li>
    @empty
        <li class="px-4 py-3 text-sm text-slate-500">Aucune activité récente.</li>
    @endforelse
</ul>
