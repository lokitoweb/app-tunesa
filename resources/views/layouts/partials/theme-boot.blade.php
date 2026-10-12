{{-- Aplica el tema antes de pintar (sin parpadeo): el elegido en el panel o, si nunca se eligió, el del sistema --}}
<script>
    (function () {
        var t = null;
        try { t = localStorage.getItem('panel-theme'); } catch (e) {}
        if (t !== 'dark' && t !== 'light') t = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
        document.documentElement.setAttribute('data-bs-theme', t);
    })();
</script>
