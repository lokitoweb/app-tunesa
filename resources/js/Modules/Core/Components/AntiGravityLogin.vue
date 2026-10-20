<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue'

const props = defineProps({
  tradeName: {
    type: String,
    default: 'Grupo Negreiros'
  },
  year: {
    type: [Number, String],
    default: () => new Date().getFullYear()
  },
  backgroundImage: {
    type: String,
    default: '/img/antigravity-bg.jpg'
  },
  csrfToken: {
    type: String,
    default: () => {
      const el = typeof document !== 'undefined' ? document.querySelector('meta[name="csrf-token"]') : null
      return el ? el.getAttribute('content') : ''
    }
  },
  submitUrl: {
    type: String,
    default: '/login'
  },
  forgotPasswordUrl: {
    type: String,
    default: '/forgot-password'
  },
  initialEmail: {
    type: String,
    default: ''
  },
  errorMessage: {
    type: String,
    default: ''
  },
  statusMessage: {
    type: String,
    default: ''
  }
})

const emit = defineEmits(['submit', 'forgotPassword', 'themeChange'])

// Reactive Form State
const email = ref(props.initialEmail)
const password = ref('')
const remember = ref(false)
const showPassword = ref(false)
const isLoading = ref(false)
const capsLockActive = ref(false)
const isDark = ref(false)

// Mouse 3D Anti-Gravity Tilt System
const containerRef = ref(null)
const mouseTilt = ref({ x: 0, y: 0 })
const smoothTilt = ref({ x: 0, y: 0 })
let animationFrameId = null

const handleMouseMove = (e) => {
  if (typeof window === 'undefined' || window.innerWidth < 1040) return
  const { clientX, clientY } = e
  const { innerWidth, innerHeight } = window
  // Normalized coordinates [-1, 1]
  const x = (clientX / innerWidth - 0.5) * 2
  const y = (clientY / innerHeight - 0.5) * 2
  mouseTilt.value = {
    x: +(x * 3.8).toFixed(2),
    y: +(-y * 3.8).toFixed(2)
  }
}

const handleMouseLeave = () => {
  mouseTilt.value = { x: 0, y: 0 }
}

const updateSpringTilt = () => {
  smoothTilt.value.x += (mouseTilt.value.x - smoothTilt.value.x) * 0.08
  smoothTilt.value.y += (mouseTilt.value.y - smoothTilt.value.y) * 0.08
  animationFrameId = requestAnimationFrame(updateSpringTilt)
}

const checkCapsLock = (e) => {
  if (e.getModifierState) {
    capsLockActive.value = e.getModifierState('CapsLock')
  }
}

const togglePasswordVisibility = () => {
  showPassword.value = !showPassword.value
}

const toggleTheme = () => {
  isDark.value = !isDark.value
  if (typeof document !== 'undefined') {
    document.documentElement.setAttribute('data-bs-theme', isDark.value ? 'dark' : 'light')
    if (isDark.value) {
      document.documentElement.classList.add('dark')
    } else {
      document.documentElement.classList.remove('dark')
    }
    try {
      localStorage.setItem('panel-theme', isDark.value ? 'dark' : 'light')
    } catch (_) {}
  }
  emit('themeChange', isDark.value ? 'dark' : 'light')
}

const handleSubmit = () => {
  if (!email.value || !password.value) return
  isLoading.value = true
  emit('submit', {
    email: email.value,
    password: password.value,
    remember: remember.value
  })
}

const monolithTransform = computed(() => {
  if (typeof window !== 'undefined' && window.innerWidth < 1040) return {}
  return {
    transform: `perspective(1200px) rotateX(${smoothTilt.value.y}deg) rotateY(${smoothTilt.value.x}deg) translateZ(0)`,
    transition: 'transform 0.1s ease-out'
  }
})

const floatingHeadlineTransform = computed(() => {
  if (typeof window !== 'undefined' && window.innerWidth < 1040) return {}
  return {
    transform: `translate3d(${-smoothTilt.value.x * 1.5}px, ${-smoothTilt.value.y * 1.5}px, 20px)`,
    transition: 'transform 0.15s ease-out'
  }
})

onMounted(() => {
  if (typeof window !== 'undefined') {
    window.addEventListener('mousemove', handleMouseMove)
    window.addEventListener('mouseleave', handleMouseLeave)
    animationFrameId = requestAnimationFrame(updateSpringTilt)
    
    const saved = localStorage.getItem('panel-theme')
    if (saved === 'dark' || document.documentElement.getAttribute('data-bs-theme') === 'dark') {
      isDark.value = true
    }
  }
})

onUnmounted(() => {
  if (typeof window !== 'undefined') {
    window.removeEventListener('mousemove', handleMouseMove)
    window.removeEventListener('mouseleave', handleMouseLeave)
    if (animationFrameId) cancelAnimationFrame(animationFrameId)
  }
})
</script>

<template>
  <div
    ref="containerRef"
    class="relative w-screen h-screen max-h-screen overflow-hidden flex flex-col justify-between font-sans select-none bg-slate-900 bg-cover bg-center bg-no-repeat transition-all duration-500"
    :style="{ backgroundImage: `url('${backgroundImage}')` }"
  >
    <!-- Caustic Light Refraction Overlay -->
    <div
      class="absolute inset-0 pointer-events-none z-0"
      style="background: radial-gradient(circle at 68% 45%, rgba(254, 240, 138, 0.08) 0%, transparent 55%), radial-gradient(circle at 35% 65%, rgba(186, 230, 253, 0.06) 0%, transparent 50%), radial-gradient(circle at 50% 20%, rgba(255, 255, 255, 0.05) 0%, transparent 40%);"
      aria-hidden="true"
    />

    <!-- Ambient Dark Overlay -->
    <div
      class="absolute inset-0 pointer-events-none z-0 transition-opacity duration-500"
      :class="isDark ? 'bg-slate-950/40' : 'bg-transparent'"
      aria-hidden="true"
    />

    <!-- Top Minimal Bar: Status Badge & Theme Switcher -->
    <header class="relative z-30 w-full px-6 py-4 lg:px-10 flex justify-between items-center">
      <div class="inline-flex items-center gap-2 px-3.5 py-1.5 rounded-full bg-white/30 dark:bg-slate-900/40 backdrop-blur-md border border-white/50 dark:border-white/10 shadow-sm text-xs font-medium text-slate-800 dark:text-slate-200">
        <span class="w-1.5 h-1.5 rounded-full bg-emerald-500 shadow-[0_0_8px_#10b981] animate-pulse" />
        <span>ERP Corporativo • {{ tradeName }}</span>
      </div>

      <button
        type="button"
        @click="toggleTheme"
        class="w-9 h-9 rounded-full bg-white/35 dark:bg-slate-900/40 backdrop-blur-md border border-white/50 dark:border-white/15 text-slate-800 dark:text-amber-300 flex items-center justify-center hover:scale-105 active:scale-95 transition-all shadow-sm cursor-pointer"
        :title="isDark ? 'Modo claro' : 'Modo oscuro'"
        :aria-label="isDark ? 'Cambiar a modo claro' : 'Cambiar a modo oscuro'"
      >
        <svg v-if="isDark" class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <circle cx="12" cy="12" r="5" /><line x1="12" y1="1" x2="12" y2="3" /><line x1="12" y1="21" x2="12" y2="23" />
          <line x1="4.22" y1="4.22" x2="5.64" y2="5.64" /><line x1="18.36" y1="18.36" x2="19.78" y2="19.78" />
          <line x1="1" y1="12" x2="3" y2="12" /><line x1="21" y1="12" x2="23" y2="12" />
          <line x1="4.22" y1="19.78" x2="5.64" y2="18.36" /><line x1="18.36" y1="5.64" x2="19.78" y2="4.22" />
        </svg>
        <svg v-else class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z" />
        </svg>
      </button>
    </header>

    <!-- Main Anti-Gravity Composition Area -->
    <main class="relative z-10 flex-1 flex items-center justify-center px-4 sm:px-6 min-h-0">
      <div class="w-full max-w-[1150px] flex flex-col justify-center origin-center">
        
        <!-- Editorial Serif Floating Headline: Left-Aligned Directly Over Ribbon Start -->
        <div
          class="mb-3.5 pl-9 text-left transition-transform"
          :style="floatingHeadlineTransform"
        >
          <h1 class="font-serif text-[2rem] lg:text-[2.6rem] font-medium leading-[1.15] tracking-[-0.02em] text-slate-900 dark:text-slate-100 drop-shadow-[0_1px_2px_rgba(255,255,255,0.55)] dark:drop-shadow-[0_2px_10px_rgba(0,0,0,0.6)]">
            Control operativo, financiero<br />y de capital humano
          </h1>
        </div>

        <!-- Continuous Sculpted Monolith Assembly: Seamless Ribbon + Card with Concave Fillet Union -->
        <div
          class="relative w-full max-w-[1150px] h-auto lg:h-[580px] mx-auto filter drop-shadow-[0_25px_45px_rgba(10,20,30,0.28)]"
          :style="monolithTransform"
        >
          <!-- Layer 1: Clipped Frosted Glass Backdrop -->
          <div
            class="hidden lg:block absolute inset-0 w-full h-full z-1 backdrop-blur-[22px] saturate-[135%]"
            style="clip-path: path('M 24 190 L 635 190 C 660 190 680 170 680 145 L 680 36 C 680 16 696 0 716 0 L 1114 0 C 1134 0 1150 16 1150 36 L 1150 544 C 1150 564 1134 580 1114 580 L 716 580 C 696 580 680 564 680 544 L 680 430 C 680 405 660 385 635 385 L 24 385 C 10.7 385 0 374.3 0 361 L 0 214 C 0 200.7 10.7 190 24 190 Z'); background: linear-gradient(135deg, rgba(245, 252, 248, 0.48) 0%, rgba(228, 240, 235, 0.35) 45%, rgba(195, 218, 212, 0.42) 100%);"
            aria-hidden="true"
          />

          <!-- Layer 2: Continuous Specular SVG Outline with S-Curves -->
          <svg class="hidden lg:block absolute inset-0 w-full h-full pointer-events-none z-2" viewBox="0 0 1150 580" fill="none" preserveAspectRatio="none" aria-hidden="true">
            <defs>
              <linearGradient id="vueSpecularOutline" x1="0%" y1="0%" x2="100%" y2="100%">
                <stop offset="0%" stop-color="rgba(255, 255, 255, 0.9)" />
                <stop offset="25%" stop-color="rgba(255, 255, 255, 0.75)" />
                <stop offset="55%" stop-color="rgba(235, 248, 242, 0.6)" />
                <stop offset="85%" stop-color="rgba(205, 230, 222, 0.5)" />
                <stop offset="100%" stop-color="rgba(190, 220, 212, 0.75)" />
              </linearGradient>
            </defs>
            <path
              d="M 24 190 L 635 190 C 660 190 680 170 680 145 L 680 36 C 680 16 696 0 716 0 L 1114 0 C 1134 0 1150 16 1150 36 L 1150 544 C 1150 564 1134 580 1114 580 L 716 580 C 696 580 680 564 680 544 L 680 430 C 680 405 660 385 635 385 L 24 385 C 10.7 385 0 374.3 0 361 L 0 214 C 0 200.7 10.7 190 24 190 Z"
              stroke="url(#vueSpecularOutline)"
              stroke-width="2"
              vector-effect="non-scaling-stroke"
            />
          </svg>

          <!-- Layer 3: Interactive Content Layout (Left Ribbon + Right Card) -->
          <div class="relative lg:absolute lg:inset-0 w-full h-full z-3 flex flex-col lg:block">
            
            <!-- LEFT SECTION: Metrics Ribbon (3 Columns) -->
            <section
              class="w-full lg:w-[680px] h-auto lg:h-[195px] lg:absolute lg:left-0 lg:top-[190px] flex flex-col lg:flex-row items-stretch p-4 lg:py-0 lg:px-5 rounded-3xl lg:rounded-none bg-white/40 lg:bg-transparent backdrop-blur-xl lg:backdrop-blur-none border border-white/60 lg:border-none shadow-lg lg:shadow-none mb-4 lg:mb-0"
              aria-label="Métricas corporativas"
            >
              <!-- Column 1: Control Operativo & 100% -->
              <div class="flex-1 flex flex-col justify-between p-3.5 lg:p-4 min-w-0">
                <div class="flex items-start gap-2.5">
                  <svg class="w-5 h-5 text-slate-800 dark:text-slate-100 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="12" cy="12" r="3" />
                    <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z" />
                  </svg>
                  <span class="text-[13.5px] font-semibold text-slate-800 dark:text-slate-100 leading-tight">Control<br />Operativo</span>
                </div>
                <div class="flex items-center gap-2.5 mt-auto pt-3">
                  <div class="w-9 h-9 shrink-0 flex items-center justify-center">
                    <svg viewBox="0 0 36 36" fill="none" class="w-full h-full">
                      <defs>
                        <linearGradient id="vueCopperGrad" x1="0%" y1="0%" x2="100%" y2="100%">
                          <stop offset="0%" stop-color="#f59e0b" />
                          <stop offset="45%" stop-color="#b45309" />
                          <stop offset="100%" stop-color="#78350f" />
                        </linearGradient>
                      </defs>
                      <path d="M14 8a6 6 0 1 0 0 12 6 6 0 0 0 0-12zm0 9a3 3 0 1 1 0-6 3 3 0 0 1 0 6z" fill="url(#vueCopperGrad)" />
                      <path d="M22 16a5 5 0 1 0 0 10 5 5 0 0 0 0-10zm0 7.5a2.5 2.5 0 1 1 0-5 2.5 2.5 0 0 1 0 5z" fill="url(#vueCopperGrad)" />
                      <circle cx="14" cy="14" r="1.5" fill="#fef3c7" />
                      <circle cx="22" cy="21" r="1.2" fill="#fef3c7" />
                    </svg>
                  </div>
                  <div class="flex flex-col min-w-0">
                    <span class="text-[27px] font-bold leading-none tracking-tight bg-gradient-to-br from-amber-600 via-amber-700 to-amber-900 dark:from-amber-400 dark:to-amber-600 bg-clip-text text-transparent drop-shadow-[0_1px_1px_rgba(255,255,255,0.7)]">100%</span>
                    <span class="text-[11.5px] text-slate-600 dark:text-slate-400 leading-tight mt-0.5">Cobertura de Asistencia</span>
                  </div>
                </div>
              </div>

              <!-- Hairline Separator -->
              <div class="hidden lg:block w-[1px] h-[65%] self-center bg-white/45 dark:bg-white/10 shadow-[1px_0_0_rgba(0,0,0,0.03)]" aria-hidden="true" />

              <!-- Column 2: Capital Humano & Planillas -->
              <div class="flex-1 flex flex-col justify-between p-3.5 lg:p-4 min-w-0 border-t lg:border-t-0 border-white/20 dark:border-white/5">
                <div class="flex items-start gap-2.5">
                  <svg class="w-5 h-5 text-slate-800 dark:text-slate-100 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2" />
                    <circle cx="9" cy="7" r="4" />
                    <path d="M23 21v-2a4 4 0 0 0-3-3.87" />
                    <path d="M16 3.13a4 4 0 0 1 0 7.75" />
                  </svg>
                  <span class="text-[13.5px] font-semibold text-slate-800 dark:text-slate-100 leading-tight">Gestión de<br />Capital Humano</span>
                </div>
                <div class="flex items-center gap-2.5 mt-auto pt-3">
                  <div class="w-9 h-9 shrink-0 flex items-center justify-center">
                    <svg viewBox="0 0 32 32" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" class="w-7 h-7 text-amber-700 dark:text-amber-500">
                      <path d="M10 4h10l6 6v16a2 2 0 0 1-2 2H10a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2z" />
                      <polyline points="20 4 20 10 26 10" />
                      <path d="M13 18l2 2 4-4" />
                      <line x1="12" y1="13" x2="16" y2="13" />
                    </svg>
                  </div>
                  <div class="flex flex-col min-w-0">
                    <span class="text-[12px] font-medium text-slate-700 dark:text-slate-300 leading-tight">Optimización de Planillas Automática</span>
                  </div>
                </div>
              </div>

              <!-- Hairline Separator -->
              <div class="hidden lg:block w-[1px] h-[65%] self-center bg-white/45 dark:bg-white/10 shadow-[1px_0_0_rgba(0,0,0,0.03)]" aria-hidden="true" />

              <!-- Column 3: Seguridad & 24/7 -->
              <div class="flex-1 flex flex-col justify-between p-3.5 lg:p-4 min-w-0 border-t lg:border-t-0 border-white/20 dark:border-white/5">
                <div class="flex items-start gap-2.5">
                  <svg class="w-5 h-5 text-slate-800 dark:text-slate-100 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z" />
                    <circle cx="12" cy="11" r="3" />
                  </svg>
                  <span class="text-[13.5px] font-semibold text-slate-800 dark:text-slate-100 leading-tight">Seguridad y<br />Auditoría</span>
                </div>
                <div class="flex items-center gap-2.5 mt-auto pt-3">
                  <div class="flex flex-col min-w-0 pl-1">
                    <span class="text-[27px] font-bold leading-none tracking-tight bg-gradient-to-br from-amber-600 via-amber-700 to-amber-900 dark:from-amber-400 dark:to-amber-600 bg-clip-text text-transparent drop-shadow-[0_1px_1px_rgba(255,255,255,0.7)]">24/7</span>
                    <span class="text-[11.5px] text-slate-600 dark:text-slate-400 leading-tight mt-0.5">Monitoreo y Geocercas GPS</span>
                  </div>
                </div>
              </div>
            </section>

            <!-- RIGHT SECTION: Vertical Login Card -->
            <section
              class="w-full lg:w-[470px] h-auto lg:h-[580px] lg:absolute lg:left-[680px] lg:top-0 p-8 lg:p-10 flex flex-col justify-between rounded-3xl lg:rounded-none bg-white/45 lg:bg-transparent backdrop-blur-xl lg:backdrop-blur-none border border-white/70 lg:border-none shadow-xl lg:shadow-none"
              aria-label="Panel de inicio de sesión"
            >
              <div>
                <!-- Badge Acceso Autorizado -->
                <div class="flex items-center gap-1.5 mb-1">
                  <svg class="w-3.5 h-3.5 text-amber-700 dark:text-amber-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z" />
                    <path d="m9 12 2 2 4-4" />
                  </svg>
                  <span class="text-[11px] font-bold tracking-[0.12em] text-amber-800 dark:text-amber-300 uppercase">ACCESO AUTORIZADO</span>
                </div>

                <!-- Titles -->
                <h2 class="font-serif text-[29px] font-semibold text-slate-900 dark:text-slate-100 tracking-[-0.015em] mb-0.5">
                  Iniciar sesión
                </h2>
                <p class="text-[12.5px] text-slate-600 dark:text-slate-400 leading-normal mb-4">
                  Ingresa tus credenciales para acceder al ERP empresarial.
                </p>

                <!-- Feedback Banners -->
                <div v-if="errorMessage" class="mb-3 p-2.5 rounded-xl bg-red-500/15 border border-red-500/35 text-red-800 dark:text-red-300 text-xs flex items-center gap-2">
                  <svg class="w-4 h-4 shrink-0 text-red-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                  <span>{{ errorMessage }}</span>
                </div>
                <div v-if="statusMessage" class="mb-3 p-2.5 rounded-xl bg-emerald-500/15 border border-emerald-500/35 text-emerald-800 dark:text-emerald-300 text-xs flex items-center gap-2">
                  <svg class="w-4 h-4 shrink-0 text-emerald-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                  <span>{{ statusMessage }}</span>
                </div>

                <!-- Form Fields -->
                <form @submit.prevent="handleSubmit" class="flex flex-col">
                  <input v-if="csrfToken" type="hidden" name="_token" :value="csrfToken" />

                  <!-- Correo Corporativo -->
                  <div class="mb-3.5">
                    <label for="vue_email" class="block text-[12.5px] font-medium text-slate-700 dark:text-slate-300 mb-1">
                      Correo corporativo
                    </label>
                    <div class="relative h-[46px] rounded-xl border border-amber-200/40 dark:border-white/15 bg-stone-900/10 dark:bg-slate-900/40 px-3 flex items-center shadow-[inset_0_2px_4px_rgba(15,23,42,0.06)] focus-within:border-white focus-within:bg-stone-900/20 focus-within:ring-2 focus-within:ring-white/30 transition-all">
                      <div class="mr-2 text-amber-700 dark:text-amber-400 shrink-0">
                        <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                          <rect x="2" y="4" width="20" height="16" rx="2" /><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7" />
                        </svg>
                      </div>
                      <input
                        id="vue_email"
                        v-model="email"
                        type="email"
                        required
                        autocomplete="username"
                        placeholder="nombre@empresa.com"
                        class="w-full bg-transparent border-none outline-none text-[13.5px] text-slate-900 dark:text-slate-100 placeholder:text-slate-500"
                      />
                      <!-- Antique Brass Key -->
                      <div class="ml-2 shrink-0" title="Credencial verificada" aria-hidden="true">
                        <svg viewBox="0 0 32 32" class="w-6 h-6 filter drop-shadow-[0_1px_2px_rgba(120,53,15,0.35)] transition-transform duration-200 hover:rotate-[-10deg]">
                          <defs>
                            <linearGradient id="vueKeyGrad2" x1="0%" y1="0%" x2="100%" y2="100%">
                              <stop offset="0%" stop-color="#fde68a" />
                              <stop offset="40%" stop-color="#d97706" />
                              <stop offset="100%" stop-color="#92400e" />
                            </linearGradient>
                          </defs>
                          <path d="M22 6a5 5 0 0 0-4.9 4H5v4h3v3h3v-3h2v3h3v-3h1.1A5 5 0 1 0 22 6zm0 7a2 2 0 1 1 0-4 2 2 0 0 1 0 4z" fill="url(#vueKeyGrad2)" />
                        </svg>
                      </div>
                    </div>
                  </div>

                  <!-- Contraseña -->
                  <div class="mb-3.5">
                    <div class="flex justify-between items-baseline mb-1">
                      <label for="vue_password" class="text-[12.5px] font-medium text-slate-700 dark:text-slate-300">Contraseña</label>
                      <a :href="forgotPasswordUrl" @click.prevent="emit('forgotPassword')" class="text-[11.5px] font-medium text-amber-800 dark:text-amber-300 hover:underline">¿Olvidaste tu contraseña?</a>
                    </div>
                    <div class="relative h-[46px] rounded-xl border border-amber-200/40 dark:border-white/15 bg-stone-900/10 dark:bg-slate-900/40 px-3 flex items-center shadow-[inset_0_2px_4px_rgba(15,23,42,0.06)] focus-within:border-white focus-within:bg-stone-900/20 focus-within:ring-2 focus-within:ring-white/30 transition-all">
                      <div class="mr-2 text-amber-700 dark:text-amber-400 shrink-0">
                        <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                          <rect x="3" y="11" width="18" height="11" rx="2" ry="2" /><path d="M7 11V7a5 5 0 0 1 10 0v4" />
                        </svg>
                      </div>
                      <input
                        id="vue_password"
                        v-model="password"
                        :type="showPassword ? 'text' : 'password'"
                        required
                        autocomplete="current-password"
                        placeholder="Ingresa tu contraseña"
                        @keydown="checkCapsLock"
                        @keyup="checkCapsLock"
                        class="w-full bg-transparent border-none outline-none text-[13.5px] text-slate-900 dark:text-slate-100 placeholder:text-slate-500"
                      />
                      <!-- Wax Seal Medallion -->
                      <button
                        type="button"
                        @click="togglePasswordVisibility"
                        class="ml-2 shrink-0 p-0 bg-transparent border-none cursor-pointer group"
                        :title="showPassword ? 'Ocultar contraseña' : 'Mostrar contraseña'"
                      >
                        <svg viewBox="0 0 32 32" class="w-7 h-7 transition-transform duration-200 group-hover:scale-110">
                          <defs>
                            <linearGradient id="vueWaxGrad2" x1="0%" y1="0%" x2="100%" y2="100%">
                              <stop offset="0%" stop-color="#dc2626" />
                              <stop offset="45%" stop-color="#991b1b" />
                              <stop offset="100%" stop-color="#450a0a" />
                            </linearGradient>
                            <radialGradient id="vueWaxLight2" cx="35%" cy="35%" r="65%">
                              <stop offset="0%" stop-color="#f87171" stop-opacity="0.8" />
                              <stop offset="50%" stop-color="#b91c1c" stop-opacity="0.2" />
                              <stop offset="100%" stop-color="#450a0a" stop-opacity="0.9" />
                            </radialGradient>
                          </defs>
                          <path d="M16 2.5c2.5 0 4 1.2 5.5 1.4s3-.7 4.5 0 2.2 2.2 3.1 3.8.4 3.4 1 5 1.5 3 1 4.5-2 2.5-2.8 4-.2 3.5-1.2 4.8-2.6 1.8-4 2.6-2.5 2-4 2.2-3.1-.8-4.7-.8-3.2 1-4.7.8-2.5-1.4-4-2.2-3-.2-4-1.5-.4-3.3-1.2-4.8-2.3-2.5-2.8-4 0-3.2 1-4.7 1.8-2.8 3.1-4.2 1.8-.7 3.3-1.5 2.8.2 4.5 0S13.5 2.5 16 2.5z" fill="url(#vueWaxGrad2)" />
                          <path d="M16 4.5c2 0 3.5 1 5 1.2s2.5-.5 3.8.2 1.8 1.8 2.6 3.2.3 2.8.8 4.2 1.2 2.5.8 3.8-1.7 2-2.3 3.3-.2 3-1 4.1-2.2 1.5-3.4 2.2-2.1 1.7-3.4 1.8-2.6-.7-4-.7-2.7.8-4 .7-2.1-1.2-3.4-1.8-2.5-.2-3.4-1.3-.3-2.8-1-4-2-2.1-2.4-3.4 0-2.7.8-4 1.5-2.4 2.6-3.6 1.5-.6 2.8-1.3 2.4.2 3.8 0S14 4.5 16 4.5z" fill="url(#vueWaxLight2)" />
                          <circle cx="16" cy="16" r="8" fill="none" stroke="#7f1d1d" stroke-width="1.2" />
                          <circle cx="16" cy="16" r="6.2" fill="none" stroke="#fca5a5" stroke-opacity="0.3" stroke-width="0.8" />
                          <path v-if="!showPassword" d="M13.5 18.5l2.5-5 2.5 5h-5z" fill="#450a0a" opacity="0.6" />
                          <circle v-if="!showPassword" cx="16" cy="13" r="1.5" fill="#fca5a5" opacity="0.5" />
                          <circle v-else cx="16" cy="16" r="3" fill="#fef08a" opacity="0.9" />
                        </svg>
                      </button>
                    </div>
                  </div>

                  <!-- Remember Me -->
                  <div class="mb-3.5 flex items-center">
                    <label class="inline-flex items-center gap-2 cursor-pointer select-none">
                      <input v-model="remember" type="checkbox" class="w-4 h-4 rounded border-white/70 dark:border-white/20 bg-white/35 dark:bg-slate-900/40 text-emerald-600 focus:ring-emerald-500/30" />
                      <span class="text-[12.5px] text-slate-700 dark:text-slate-300">Mantener sesión iniciada</span>
                    </label>
                  </div>

                  <!-- Organic Stone Action Button -->
                  <button
                    type="submit"
                    :disabled="isLoading"
                    class="w-full h-[48px] rounded-xl border-none cursor-pointer flex items-center justify-center gap-2 mt-1 text-lime-50 font-semibold text-[15px] tracking-wide shadow-[0_10px_24px_-2px_rgba(35,48,16,0.55),inset_0_1px_1.5px_rgba(255,255,255,0.4),inset_0_-2px_4px_rgba(0,0,0,0.4)] hover:shadow-[0_14px_28px_-2px_rgba(35,48,16,0.65),inset_0_1px_2px_rgba(255,255,255,0.48),inset_0_-2px_4px_rgba(0,0,0,0.35)] hover:-translate-y-0.5 active:translate-y-0.5 transition-all duration-200 group"
                    style="background: linear-gradient(180deg, #5b6f2b 0%, #465721 50%, #303d16 100%);"
                  >
                    <span v-if="!isLoading" class="drop-shadow-[0_1px_2px_rgba(0,0,0,0.45)]">Ingresar al sistema</span>
                    <span v-if="!isLoading" class="text-base group-hover:translate-x-1 transition-transform drop-shadow-[0_1px_2px_rgba(0,0,0,0.45)]">→</span>
                    <span v-if="isLoading" class="w-4 h-4 border-2 border-white/30 border-t-white rounded-full animate-spin mr-2" />
                    <span v-if="isLoading">Ingresando…</span>
                  </button>
                </form>
              </div>

              <!-- Bottom Security Note -->
              <div class="mt-3 flex items-center justify-center gap-1.5 text-[11.5px] text-slate-600 dark:text-slate-400 text-center">
                <svg class="w-3.5 h-3.5 text-slate-500 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                  <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z" /><path d="M12 8v4" /><circle cx="12" cy="16" r="0.8" fill="currentColor" />
                </svg>
                <span>Para un acceso seguro y cifrado, verificado por humanos.</span>
              </div>
            </section>
          </div>
        </div>

      </div>
    </main>

    <!-- Base Monolith Etching & Floor Script -->
    <div
      class="hidden lg:block absolute bottom-5 left-[49%] -translate-x-1/2 font-serif italic text-[13.5px] text-stone-800/75 dark:text-slate-300/60 tracking-wider pointer-events-none drop-shadow-[0_1px_1px_rgba(255,255,255,0.4)]"
      aria-hidden="true"
    >
      © {{ year }} {{ tradeName }}
    </div>

    <div
      class="hidden lg:block absolute bottom-5 right-[21%] text-[11.5px] text-slate-700/75 dark:text-slate-400/60 pointer-events-none"
      aria-hidden="true"
    >
      © {{ year }} {{ tradeName }}
    </div>
  </div>
</template>

<style scoped>
@import url('https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,400;0,500;0,600;0,700;1,400&family=Roboto:wght@300;400;500;600;700&display=swap');

.font-serif {
  font-family: 'Playfair Display', Georgia, serif;
}
.font-sans {
  font-family: 'Roboto', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
}
</style>
