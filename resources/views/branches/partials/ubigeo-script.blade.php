{{--
    Ubigeo de la sede (departamento > provincia > distrito, public/data/ubigeo-pe.json) y ubicación
    automática de la dirección en el mapa. Lo usan el modal de Sucursales y la página de la sede:
    const ubigeo = BranchUbigeo({ city, province, district, address, status, onLocate(lat, lng, zoom) });
    ubigeo.set(city, province, district) llena los selects (una sede antigua con la ciudad en "Región",
    p. ej. "Trujillo", se reconoce como provincia de su departamento).
--}}
<script>
window.BranchUbigeo = function ({ city, province, district, address, status, onLocate }) {
    const el = (id) => document.getElementById(id);
    const [cityEl, provinceEl, districtEl, addressEl, statusEl] = [el(city), el(province), el(district), el(address), el(status)];
    const hint = statusEl.textContent;
    let data = {};
    const ready = fetch('{{ asset('data/ubigeo-pe.json') }}').then((r) => r.json()).then((j) => { data = j; }).catch(() => {});
    const same = (a, b) => a.localeCompare(b, 'es', { sensitivity: 'base' }) === 0;

    {{-- Un valor guardado que no está en la lista se conserva como "(actual)" --}}
    function fill(select, items, placeholder, value) {
        select.innerHTML = '';
        select.add(new Option(placeholder, ''));
        const sorted = [...items].sort((a, b) => a.localeCompare(b, 'es'));
        if (value && !sorted.includes(value)) select.add(new Option(`${value} (actual)`, value));
        sorted.forEach((name) => select.add(new Option(name, name)));
        select.value = value || '';
        select.disabled = select.options.length <= 1;
    }
    const provinces = () => data[cityEl.value] || {};
    const fillProvinces = (value) => fill(provinceEl, Object.keys(provinces()), cityEl.value ? 'Elige la provincia' : 'Elige el departamento', value);
    const fillDistricts = (value) => fill(districtEl, provinces()[provinceEl.value] || [], provinceEl.value ? 'Elige el distrito' : 'Elige la provincia', value);

    function setStatus(text, tone) {
        statusEl.textContent = text;
        statusEl.className = 'form-text small' + (tone ? ` text-${tone}` : '');
    }

    {{-- Geocodificador del sistema (Nominatim, 1 petición/s): dirección completa, luego sin el número y, si no, el distrito --}}
    let timer = null, seq = 0;
    function locate() {
        clearTimeout(timer);
        timer = setTimeout(async () => {
            const street = addressEl.value.trim();
            const area = [districtEl.value, provinceEl.value, cityEl.value].filter(Boolean);
            if (street.length < 4 && !area.length) return;
            const noNumber = street.replace(/\s+(n[°º.]?\s*)?\d+[\w-]*.*$/i, '').trim();
            const tries = [...new Set([[street, ...area], [noNumber, ...area], area]
                .map((p) => p.filter(Boolean)).filter((p) => p.length).map((p) => [...p, 'Perú'].join(', ')))];
            const mine = ++seq;
            setStatus('Ubicando la dirección en el mapa…');
            for (const [i, q] of tries.entries()) {
                try {
                    const res = await fetch(`{{ url('/geocode/search') }}?limit=1&q=${encodeURIComponent(q)}`, { headers: { Accept: 'application/json' } });
                    const json = await res.json();
                    if (mine !== seq) return;
                    const hit = json && json.success && json.data && json.data[0];
                    if (!hit) continue;
                    const exact = i === 0 && street.length >= 4;
                    onLocate(Number(hit.lat), Number(hit.lng), exact ? 18 : (noNumber && q.startsWith(noNumber) ? 16 : 14));
                    setStatus(exact ? `Ubicada: ${hit.label}` : `Ubicación aproximada (${hit.label}). Arrastra el pin al punto exacto.`, exact ? 'success' : 'warning');
                    return;
                } catch (e) {
                    if (mine !== seq) return;
                }
            }
            if (mine === seq) setStatus('No se encontró la dirección. Revisa el distrito o ubica el pin en el mapa.', 'danger');
        }, 900);
    }

    cityEl.addEventListener('change', () => { fillProvinces(''); fillDistricts(''); locate(); });
    provinceEl.addEventListener('change', () => { fillDistricts(''); locate(); });
    districtEl.addEventListener('change', locate);
    addressEl.addEventListener('input', locate);

    return {
        async set(cityValue, provinceValue, districtValue) {
            await ready;
            if (cityValue && !data[cityValue]) {
                const dept = Object.keys(data).find((d) => Object.keys(data[d]).some((p) => same(p, cityValue)));
                if (dept) {
                    provinceValue = provinceValue || Object.keys(data[dept]).find((p) => same(p, cityValue));
                    cityValue = dept;
                    cityEl.querySelectorAll('option[data-legacy]').forEach((o) => o.remove());
                }
            }
            if (cityValue && ![...cityEl.options].some((o) => o.value === cityValue)) {
                const legacy = new Option(`${cityValue} (actual)`, cityValue);
                legacy.dataset.legacy = '1';
                cityEl.add(legacy, 1);
            }
            cityEl.value = cityValue || '';
            fillProvinces(provinceValue);
            fillDistricts(districtValue);
            seq++;
            setStatus(hint);
        },
    };
};
</script>
