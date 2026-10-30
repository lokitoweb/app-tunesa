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
        {{-- Menú lateral: barra de íconos que se abre al pasar el mouse, salvo que el usuario lo haya fijado abierto. Antes de pintar para que no salte --}}
        var pinned = false;
        try { pinned = localStorage.getItem('panel-sidebar-pinned') === '1'; } catch (e) {}
        if (!pinned) document.documentElement.setAttribute('data-sidebar', 'rail');
    })();
</script>

