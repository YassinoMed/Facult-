{{-- Phase 16 §16.7 : aperçu sandbox d'un modèle (variables échappées, sans écriture). --}}
<div class="sandbox-apercu">
    <p>{{ $contenu }}</p>
    <dl>
        @foreach($variables as $cle => $valeur)
            <div><dt>{{ $cle }}</dt><dd>{!! $valeur !!}</dd></div>
        @endforeach
    </dl>
</div>
