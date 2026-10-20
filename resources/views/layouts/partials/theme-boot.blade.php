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
    })();
</script>

