@extends('layouts.app')

@section('title', 'Courriels — message manuel')

@section('content')
<div class="mx-auto max-w-3xl px-4 sm:px-6 py-8">
    <x-page-header titre="Message manuel" description="Modèle unique, destinataire validé, aperçu avant confirmation. Envoi réel après confirmation explicite."
        :fil="['Courriels' => route('admin.courriels.index')]" />

    @if(session('error'))
        <div class="mt-4"><x-alert type="error">{{ session('error') }}</x-alert></div>
    @endif
    @if($errors->any())
        <div class="mt-4"><x-alert type="error">{{ $errors->first() }}</x-alert></div>
    @endif

    <form method="POST" action="{{ route('admin.courriels.envoyer') }}" class="mt-4 rounded-2xl border border-slate-200 bg-white p-5 space-y-4">
        @csrf
        <div>
            <label for="destinataire" class="mb-1 block text-xs font-bold uppercase tracking-wider text-slate-500">Destinataire *</label>
            <input id="destinataire" name="destinataire" type="email" required maxlength="254" autocomplete="off"
                   value="{{ old('destinataire', $destinataire ?? '') }}"
                   class="w-full rounded-xl border border-slate-200 px-3.5 py-2.5 text-sm">
        </div>
        <div>
            <label for="message" class="mb-1 block text-xs font-bold uppercase tracking-wider text-slate-500">Message (500 caractères max, texte brut) *</label>
            <textarea id="message" name="message" rows="5" required maxlength="500"
                      class="w-full rounded-xl border border-slate-200 px-3.5 py-2.5 text-sm">{{ old('message', $message ?? '') }}</textarea>
        </div>

        @if(isset($apercu))
            <div class="rounded-xl border border-amber-300 bg-amber-50 p-4" role="status">
                <p class="text-xs font-black uppercase tracking-wider text-amber-900">Aperçu avant envoi réel</p>
                <p class="mt-1 text-sm font-bold">{{ $apercu['sujet'] }}</p>
                @foreach($apercu['lignes'] as $ligne)
                    <p class="mt-1 text-sm">{{ $ligne }}</p>
                @endforeach
                <label class="mt-3 flex items-start gap-2 text-sm font-bold text-amber-900">
                    <input type="checkbox" name="confirmation" value="1" required class="mt-0.5 h-4 w-4">
                    Je confirme l'envoi réel de ce message.
                </label>
            </div>
            <input type="hidden" name="etape" value="confirmer">
            <button type="submit" class="rounded-xl bg-rose-600 px-5 py-2.5 text-xs font-bold text-white">Confirmer l'envoi réel</button>
        @else
            <input type="hidden" name="etape" value="apercu">
            <button type="submit" class="rounded-xl bg-primary-600 px-5 py-2.5 text-xs font-bold text-white">Prévisualiser (aucun envoi)</button>
        @endif
    </form>
</div>
@endsection
