{{-- Script d'initialisation GrapheJS isolé avec wire:ignore --}}
<script>
    (function() {
        if (typeof grapesjs === 'undefined') return;

        // GrapesJS initialization - container isolé, aucun impact Livewire
        const settings = @json($grapesJsSettings ?? ['container' => '#grapes-js-container', 'plugins' => ['mjml']]);
        const editor = grapesjs.init(settings);

        // Désactiver auto-save, on gère via Livewire
        editor.setConfig('storageManager', false);

        // Empêcher les conflits DOM avec Alpine.js
        editor.on('selection:change', () => {
            // Déclencher l'événement Livewire (déboité)
            if (window.Livewire) {
                const debounce = () => {
                    if (typeof window.Livewire.dispatch === 'function') {
                        window.Livewire.dispatch('grapesJsChanged', { html: editor.getHtml() });
                    } else if (typeof window.Livewire.emit === 'function') {
                        window.Livewire.emit('grapesJsChanged', editor.getHtml());
                    }
                };
                setTimeout(debounce, 300);
            }
        });

        // Gérer l'événement save
        editor.on('save', () => {
            if (window.Livewire) {
                if (typeof window.Livewire.dispatch === 'function') {
                    window.Livewire.dispatch('grapesJsSave');
                } else if (typeof window.Livewire.emit === 'function') {
                    window.Livewire.emit('grapesJsSave');
                }
            }
        });

        // Gérer l'événement preview
        editor.on('preview', () => {
            if (window.Livewire) {
                if (typeof window.Livewire.dispatch === 'function') {
                    window.Livewire.dispatch('grapesJsPreview');
                } else if (typeof window.Livewire.emit === 'function') {
                    window.Livewire.emit('grapesJsPreview');
                }
            }
        });

        // Nettoyage au démontage
        window.addEventListener('beforeunload', () => {
            if (editor) {
                editor.destroy();
            }
        });
    })();
</script>