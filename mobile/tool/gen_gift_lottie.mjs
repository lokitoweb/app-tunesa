// Genera las animaciones Lottie de los regalos de cumpleaños (assets/gifts/*.json).
// Son vectoriales, propias (sin licencias de terceros) y a 60 FPS.
// Uso: node tool/gen_gift_lottie.mjs
// Para usar animaciones de LottieFiles basta con reemplazar el .json de la misma clave.
import { writeFileSync, mkdirSync } from 'node:fs';

const W = 512, H = 512, FR = 60, OP = 180; // 3 s en bucle
const C = W / 2;

// Aleatorio reproducible: el mismo archivo en cada ejecución
let seed = 7;
const rnd = () => ((seed = (seed * 16807) % 2147483647) - 1) / 2147483646;
const pick = (a) => a[Math.floor(rnd() * a.length)];
const hex = (h) => [1, 3, 5].map((i) => parseInt(h.slice(i, i + 2), 16) / 255).concat(1);

const S = (k) => ({ a: 0, k });
const ease = { i: { x: [0.4], y: [1] }, o: { x: [0.6], y: [0] } };
const linear = { i: { x: [1], y: [1] }, o: { x: [0], y: [0] } };
// keys: [[frame, valor], ...]; los valores escalares van como [v]
const A = (keys, curve = ease) => ({
  a: 1,
  k: keys.map(([t, v], i) => (i === keys.length - 1 ? { t, s: [].concat(v) } : { t, s: [].concat(v), ...curve })),
});

const tr = () => ({ ty: 'tr', p: S([0, 0]), a: S([0, 0]), s: S([100, 100]), r: S(0), o: S(100), sk: S(0), sa: S(0) });
const fill = (c) => ({ ty: 'fl', c: S(hex(c)), o: S(100), r: 1 });
const stroke = (c, w) => ({ ty: 'st', c: S(hex(c)), o: S(100), w: S(w), lc: 2, lj: 2 });
const group = (items) => ({ ty: 'gr', it: [...items, tr()] });
const ellipse = (d) => ({ ty: 'el', d: 1, p: S([0, 0]), s: S([d, d]) });
const rect = (w, h, r = 0) => ({ ty: 'rc', d: 1, p: S([0, 0]), s: S([w, h]), r: S(r) });
const star = (outer, inner, points = 4) => ({ ty: 'sr', sy: 1, d: 1, pt: S(points), p: S([0, 0]), r: S(0), ir: S(inner), is: S(0), or: S(outer), os: S(0) });

let ind = 0;
const layer = (shapes, { p = S([C, C, 0]), s = S([100, 100, 100]), o = S(100), r = S(0) } = {}) => ({
  ddd: 0, ind: ++ind, ty: 4, nm: `l${ind}`, sr: 1, ao: 0, bm: 0, ip: 0, op: OP, st: 0,
  ks: { o, r, p, a: S([0, 0, 0]), s },
  shapes,
});

// Valor cíclico: arranca en `offset` y da la vuelta para que el bucle no salte
const loop = (offset, from, to) => {
  const t0 = Math.round(offset * OP);
  if (t0 === 0) return [[0, from], [OP, to]];
  const mid = from + ((to - from) * t0) / OP; // valor en el punto del ciclo donde arranca
  return [[0, mid], [OP - t0, to], [OP - t0 + 0.01, from], [OP, mid]];
};

// ---------- efectos ----------
const glow = (color, d = 300, min = 22, max = 40) =>
  layer([group([ellipse(d), fill(color)])], {
    o: A([[0, min], [OP / 2, max], [OP, min]]),
    s: A([[0, [92, 92, 100]], [OP / 2, [108, 108, 100]], [OP, [92, 92, 100]]]),
  });

const rays = (color, n = 12, len = 520, w = 26, opacity = 18, turn = 60) =>
  layer(
    Array.from({ length: n }, (_, i) => ({
      ty: 'gr',
      it: [rect(w, len, w / 2), fill(color), { ...tr(), r: S((180 / n) * i) }],
    })),
    { o: S(opacity), r: A([[0, 0], [OP, turn]], linear) },
  );

const shockwave = (colors, count = 4, maxScale = 260) =>
  Array.from({ length: count }, (_, i) => {
    const off = i / count;
    return layer([group([ellipse(200), stroke(colors[i % colors.length], 6)])], {
      s: A(loop(off, 20, maxScale).map(([t, v]) => [t, [v, v, 100]]), linear),
      o: A(loop(off, 95, 0), linear),
    });
  });

const sparkles = (colors, n = 14, area = 230, size = [10, 26]) =>
  Array.from({ length: n }, () => {
    const ang = rnd() * Math.PI * 2, dist = 60 + rnd() * area;
    const x = C + Math.cos(ang) * dist, y = C + Math.sin(ang) * dist;
    const t0 = Math.round(rnd() * (OP - 60)), dur = 40 + Math.round(rnd() * 30);
    const sz = size[0] + rnd() * (size[1] - size[0]);
    return layer([group([star(sz, sz * 0.32), fill(pick(colors))])], {
      p: S([x, y, 0]),
      s: A([[0, [0, 0, 100]], [t0, [0, 0, 100]], [t0 + dur / 2, [100, 100, 100]], [t0 + dur, [0, 0, 100]], [OP, [0, 0, 100]]]),
      r: A([[0, 0], [OP, 90 + rnd() * 90]], linear),
    });
  });

const orbit = (colors, rings = [[110, 7, 1], [170, 10, -1], [225, 13, 1]]) =>
  rings.map(([radius, count, dir], ri) =>
    layer(
      Array.from({ length: count }, (_, i) => {
        const a = (Math.PI * 2 * i) / count + ri;
        const d = 6 + rnd() * 10;
        return { ty: 'gr', it: [ellipse(d), fill(pick(colors)), { ...tr(), p: S([Math.cos(a) * radius, Math.sin(a) * radius * 0.55]) }] };
      }),
      { r: A([[0, 0], [OP, 360 * dir / (ri + 1)]], linear) },
    ),
  );

const bubbles = (colors, n = 16) =>
  Array.from({ length: n }, () => {
    const x = 40 + rnd() * (W - 80), d = 10 + rnd() * 30, off = rnd();
    const drift = (rnd() - 0.5) * 60;
    return layer([group([ellipse(d), stroke(pick(colors), 3)])], {
      p: A(loop(off, 0, 1).map(([t, v]) => [t, [x + drift * v, H + 30 - (H + 80) * v, 0]]), linear),
      o: A(loop(off, 90, 10), linear),
    });
  });

const confetti = (colors, n = 28) =>
  Array.from({ length: n }, () => {
    const x = rnd() * W, off = rnd(), sway = (rnd() - 0.5) * 80;
    const w = 8 + rnd() * 8, h = 14 + rnd() * 10;
    return layer([group([rect(w, h, 2), fill(pick(colors))])], {
      p: A(loop(off, 0, 1).map(([t, v]) => [t, [x + sway * Math.sin(v * Math.PI * 2), -30 + (H + 60) * v, 0]]), linear),
      r: A([[0, rnd() * 180], [OP, 540 + rnd() * 360]]),
    });
  });

const fireworks = (colors, bursts = 4, sparks = 14) =>
  Array.from({ length: bursts }).flatMap((_, b) => {
    const cx = 110 + rnd() * (W - 220), cy = 90 + rnd() * 220;
    const color = colors[b % colors.length];
    const t0 = Math.round((b / bursts) * OP), dur = 70;
    return Array.from({ length: sparks }, (_, i) => {
      const a = (Math.PI * 2 * i) / sparks, R = 90 + rnd() * 40;
      const end = [cx + Math.cos(a) * R, cy + Math.sin(a) * R + 25, 0];
      const k = (t, v) => [Math.min(OP, t), v];
      return layer([group([ellipse(9), fill(color)])], {
        p: A([[0, [cx, cy, 0]], k(t0, [cx, cy, 0]), k(t0 + dur, end), [OP, end]]),
        o: A([[0, 0], k(t0, 0), k(t0 + 4, 100), k(t0 + dur, 0), [OP, 0]]),
      });
    });
  });

// ---------- regalos ----------
const GOLD = ['#FFD54A', '#FFC107', '#FFF3B0'];
const gifts = {
  lion: () => [glow('#FF9F1C', 320), rays('#FFC857', 14, 560, 30, 16, 45), ...shockwave(['#FFB300', '#FF7A00', '#FFE08A'], 4), ...sparkles(GOLD, 12)],
  crown: () => [glow('#FFD54A', 300, 25, 45), rays('#FFE58A', 16, 540, 18, 20, 90), ...sparkles(['#FFFFFF', ...GOLD], 22, 230, [8, 22])],
  galaxy: () => [glow('#7C3AED', 360, 25, 45), glow('#22D3EE', 180, 18, 32), ...orbit(['#C084FC', '#22D3EE', '#F472B6', '#FFFFFF']), ...sparkles(['#FFFFFF', '#E9D5FF'], 16, 240, [6, 16])],
  space_whale: () => [glow('#1D4ED8', 380, 25, 45), ...bubbles(['#7DD3FC', '#A5F3FC', '#FFFFFF'], 16), ...sparkles(['#FFFFFF', '#BAE6FD'], 14, 240, [6, 16]), ...orbit(['#93C5FD', '#FFFFFF'], [[200, 9, 1]])],
  cake: () => [glow('#F472B6', 300, 18, 32), ...confetti(['#F472B6', '#FACC15', '#34D399', '#60A5FA', '#A78BFA', '#FB923C'], 30), ...sparkles(GOLD, 10)],
  fireworks: () => [...fireworks(['#F43F5E', '#FACC15', '#22D3EE', '#A78BFA', '#34D399'], 5, 14), ...sparkles(['#FFFFFF', ...GOLD], 12, 240, [6, 14])],
  // Fondo del modal del cumpleañero
  celebration: () => [...confetti(['#FFD54A', '#F5C2C7', '#FFFFFF', '#E8C39E', '#93C5FD'], 34), ...sparkles(['#FFFFFF', ...GOLD], 16, 240, [6, 16])],
};

mkdirSync(new URL('../assets/gifts/', import.meta.url), { recursive: true });
for (const [key, build] of Object.entries(gifts)) {
  ind = 0;
  seed = 7 + key.length * 131;
  const layers = build().reverse(); // en Lottie la primera capa queda arriba
  const json = { v: '5.7.4', fr: FR, ip: 0, op: OP, w: W, h: H, nm: key, ddd: 0, assets: [], layers };
  const file = new URL(`../assets/gifts/${key}.json`, import.meta.url);
  writeFileSync(file, JSON.stringify(json));
  console.log(`${key}.json · ${layers.length} capas · ${(JSON.stringify(json).length / 1024).toFixed(1)} KB`);
}
