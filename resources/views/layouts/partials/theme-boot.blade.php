{{-- Aplica el tema LIGHT por defecto --}}
<script>
    (function () {
        var t = 'light';
        try {
            var saved = localStorage.getItem('panel-theme');
            if (saved === 'dark' || saved === 'light') {
                t = saved;
            } else {
                localStorage.setItem('panel-theme', 'light');
            }
        } catch (e) {}
        document.documentElement.setAttribute('data-bs-theme', t);
        {{-- Menú lateral reducido a íconos (preferencia de escritorio), antes de pintar para que no salte --}}
        try { if (localStorage.getItem('panel-sidebar') === 'rail') document.documentElement.setAttribute('data-sidebar', 'rail'); } catch (e) {}
    })();
</script>

