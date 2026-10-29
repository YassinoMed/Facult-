@extends('modules.aide.gabarit')

@section('titre', $titre)

@section('contenu')
    <div class="bg-white rounded-2xl shadow-sm p-6 md:p-8">
        <p class="text-xs uppercase tracking-wider text-slate-400 mb-2">
            <a href="{{ route('aide.index') }}" class="hover:text-primary-700">Centre d'aide</a>
            › {{ $titre }}
        </p>
        <div class="aide-contenu">
            {!! $contenu !!}
        </div>
    </div>
@endsection
