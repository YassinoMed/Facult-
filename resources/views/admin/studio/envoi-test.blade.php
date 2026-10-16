@extends('layouts.app')

@section('title', 'Studio e-mail — envoi de test')

@section('content')
<div class="mx-auto max-w-3xl px-4 sm:px-6 py-8">
    <x-page-header titre="Envoi de test — {{ $modele->code }} v{{ $version }}" description="Données fictives, destinataire autorisé uniquement, confirmation explicite exigée. Jamais de modèle système."
        :fil="['Studio' => route('admin.studio.voir', $modele)]" />

    <div class="mt-4 rounded-2xl border border-amber-300 bg-amber-50 p-4 text-sm" role="status">
        <p class="font-black text-amber-900">Aperçu avant envoi réel — {{ $rendu['sujet'] }}</p>
        <div class="mt-2 text-amber-900">
            @foreach(array_slice(explode("\n", $rendu['texte']), 0, 12) as $ligne)
                <p>{{ $ligne }}</p>
            @endforeach
        </div>
    </div>

    <form method="POST" action="{{ route('admin.studio.envoi-test', $modele) }}" class="mt-4 rounded-2xl border border-slate-200 bg-white p-5 space-y-4">
        @csrf
        <input type="hidden" name="version" value="{{ $version }}">
        <div>
            <label for="destinataire-test" class="mb-1 block text-xs font-bold uppercase tracking-wider text-slate-500">Destinataire autorisé *</label>
            <input id="destinataire-test" name="destinataire" type="email" required maxlength="254" autocomplete="off"
                   value="{{ old('destinataire', $destinataire) }}"
                   class="w-full rounded-xl border border-slate-200 px-3.5 py-2.5 text-sm">
        </div>
        <label class="flex items-start gap-2 text-sm font-bold text-slate-700">
            <input type="checkbox" name="confirmation" value="1" required class="mt-0.5 h-4 w-4">
            Je confirme l'envoi réel de test vers cette adresse (données fictives).
        </label>
        <button type="submit" class="rounded-xl bg-rose-600 px-5 py-2.5 text-xs font-bold text-white">Confirmer l'envoi de test</button>
    </form>
</div>
@endsection
