@extends('layouts.app')

@section('title', 'Sessions actives')

@section('content')
    <div class="mx-auto max-w-3xl px-4 sm:px-6 py-8">
        <x-page-header titre="Sessions actives" description="Vos connexions — révoquez celles que vous ne reconnaissez pas."
            :fil="['Sessions' => '#']" />

        @if(session('success'))
            <div class="mt-4"><x-alert type="success">{{ session('success') }}</x-alert></div>
        @endif

        <ul class="mt-4 space-y-2">
            @foreach($sessions as $session)
                <li class="ds-card flex items-center justify-between gap-3 p-4">
                    <div class="min-w-0 text-sm">
                        <p class="font-bold text-slate-900">{{ $session['ip'] }} {{ $session['courante'] ? '(cette session)' : '' }}</p>
                        <p class="truncate text-xs text-slate-500">{{ $session['agent'] }}</p>
                        <p class="text-[11px] tabular-nums text-slate-400">Dernière activité : {{ $session['activite'] }}</p>
                    </div>
                    @unless($session['courante'])
                        <form method="POST" action="{{ route('admin.sessions.destroy', $session['id']) }}">
                            @csrf @method('DELETE')
                            <x-btn size="sm" variant="secondary" type="submit">Révoquer</x-btn>
                        </form>
                    @endunless
                </li>
            @endforeach
        </ul>
    </div>
@endsection
