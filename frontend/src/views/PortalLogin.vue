<template>
  <div class="min-h-screen flex items-center justify-center px-6 py-8 relative overflow-hidden" style="background: radial-gradient(900px 480px at 50% -5%, rgba(18,188,0,.14) 0%, transparent 65%), linear-gradient(180deg, #0b150d 0%, #0a0a0f 62%)">
    <!-- Subtle green glow on black -->
    <div class="pointer-events-none absolute -top-40 left-1/2 -translate-x-1/2 w-[640px] h-[360px] rounded-full opacity-[0.09]" style="background: radial-gradient(ellipse at center, #12BC00 0%, transparent 70%)"></div>

    <div class="w-full max-w-md relative page-enter">
      <div class="rounded-3xl p-6 sm:p-7" style="background: linear-gradient(160deg, rgba(18,188,0,.05), transparent 42%), #111a12; border: 1px solid rgba(165,214,167,.16); box-shadow: 0 24px 60px rgba(0,0,0,.45)">
        <!-- Brand -->
        <div class="flex items-center justify-center gap-2.5 mb-4">
          <span class="inline-flex items-center justify-center h-14 w-14 rounded-2xl text-2xl" style="background: rgba(18,188,0,.12); border: 1px solid rgba(18,188,0,.35); color: #12BC00">
            <i class="fas fa-wifi"></i>
          </span>
        </div>

        <!-- Heading block -->
        <div class="text-center mb-5">
          <p class="text-[10px] font-bold uppercase tracking-[3px] mb-1" style="color: #12BC00">Sign in</p>
          <h1 class="text-xl font-black text-white">Welcome back</h1>
          <p class="text-sm text-white/50">Enter your details to access your workspace</p>
        </div>

        <!-- Error -->
        <div v-if="error" class="rounded-2xl px-4 py-3 mb-5 text-sm" style="background: rgba(127,29,29,.18); border: 1px solid rgba(248,113,113,.3); color: #fca5a5">
          {{ error }}
        </div>

        <!-- Role preview -->
        <div v-if="detectedRole" class="mb-5 p-3 rounded-xl flex items-center gap-3" style="background: rgba(18,188,0,.08); border: 1px solid rgba(18,188,0,.3)">
          <span class="w-8 h-8 rounded-lg flex items-center justify-center text-sm" style="background: rgba(18,188,0,.18); color: #12BC00">
            <i :class="roleIcon"></i>
          </span>
          <div>
            <p class="text-xs font-bold uppercase tracking-wide" style="color: #12BC00">{{ roleLabel }}</p>
            <p class="text-xs text-white/60">Redirecting to {{ redirectPath }}...</p>
          </div>
        </div>

        <form @submit.prevent="handleLogin" class="space-y-3">
          <div>
            <label for="email" class="block text-sm font-semibold text-white/70 mb-1">Email address</label>
            <div class="relative">
              <i class="fas fa-envelope absolute left-3.5 top-1/2 -translate-y-1/2 text-sm pointer-events-none" style="color: #A5D6A7; opacity: .6"></i>
              <input id="email" v-model="email" type="email" required autofocus autocomplete="username"
                     placeholder="you@company.com"
                     class="w-full pl-11 pr-4 py-2.5 rounded-xl text-sm text-white outline-none transition-all duration-200 form-input" />
            </div>
          </div>

          <div>
            <div class="flex items-baseline justify-between mb-1">
              <label for="password" class="block text-sm font-semibold text-white/70">Password</label>
              <router-link to="/contact" class="text-xs font-medium transition-colors" style="color: #12BC00">Forgot password?</router-link>
            </div>
            <div class="relative">
              <i class="fas fa-lock absolute left-3.5 top-1/2 -translate-y-1/2 text-sm pointer-events-none" style="color: #A5D6A7; opacity: .6"></i>
              <input id="password" v-model="password" :type="showPassword ? 'text' : 'password'" required autocomplete="current-password"
                     placeholder="••••••••"
                     class="w-full pl-11 pr-11 py-2.5 rounded-xl text-sm text-white outline-none transition-all duration-200 form-input" />
              <button type="button" class="absolute inset-y-0 right-0 pr-3.5 flex items-center text-white/40 hover:text-white transition-colors" :aria-label="showPassword ? 'Hide password' : 'Show password'" @click="showPassword = !showPassword">
                <i :class="showPassword ? 'fas fa-eye-slash' : 'fas fa-eye'" class="text-sm"></i>
              </button>
            </div>
          </div>

          <label class="flex items-center gap-2 pt-1 cursor-pointer">
            <input v-model="remember" type="checkbox" class="w-4 h-4 rounded" style="accent-color: #12BC00" />
            <span class="text-sm text-white/60">Remember me</span>
          </label>

          <button type="submit" :disabled="loading"
                  class="w-full py-2.5 text-white rounded-xl font-bold text-sm uppercase tracking-wide transition-all duration-200 shadow-md hover:shadow-lg disabled:opacity-50 flex items-center justify-center gap-2"
                  style="background: #12BC00; box-shadow: 0 6px 18px rgba(18,188,0,.22)"
                  @mouseover="$event.currentTarget.style.background='#0B8A00'"
                  @mouseout="$event.currentTarget.style.background='#12BC00'">
            <i v-if="loading" class="fas fa-spinner fa-spin"></i>
            {{ loading ? 'Signing in...' : 'Sign in' }}
          </button>
        </form>

        <div class="mt-6 text-center space-y-1.5">
          <p class="text-sm text-white/50">Don't have an account?
            <router-link to="/reseller/signup" class="font-semibold" style="color: #12BC00">Register as Reseller</router-link>
          </p>
          <p class="text-sm text-white/50">WiFi user?
            <router-link to="/hotspot/login" class="font-semibold" style="color: #12BC00">Login with phone</router-link>
          </p>
        </div>
      </div>

      <p class="mt-4 pt-4 text-center text-xs text-white/30" style="border-top: 1px solid rgba(255,255,255,.06)">
        Fast, affordable internet for everyone
      </p>
    </div>
  </div>
</template>

<script setup>
import { ref, computed } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '../stores/auth'

const router = useRouter()
const auth = useAuthStore()
const email = ref('')
const password = ref('')
const remember = ref(false)
const error = ref('')
const loading = ref(false)
const detectedRole = ref(null)
const showPassword = ref(false)

const ROLE_META = {
  admin:        { label: 'HQ Panel',        icon: 'fas fa-shield-alt', redirect: '/admin/dashboard' },
  super_admin:  { label: 'HQ Panel',        icon: 'fas fa-crown',      redirect: '/admin/dashboard' },
  reseller:     { label: 'Reseller Portal', icon: 'fas fa-ticket-alt', redirect: '/reseller/dashboard' },
  customer:     { label: 'Customer Portal', icon: 'fas fa-wifi',       redirect: '/hotspot/dashboard' },
}

const roleLabel = computed(() => ROLE_META[detectedRole.value]?.label || '')
const roleIcon = computed(() => ROLE_META[detectedRole.value]?.icon || 'fas fa-user')
const redirectPath = computed(() => ROLE_META[detectedRole.value]?.redirect || '/login')

async function handleLogin() {
  loading.value = true
  error.value = ''
  try {
    const r = await auth.login(email.value, password.value)
    detectedRole.value = r.user.role
    await new Promise(resolve => setTimeout(resolve, 600))
    router.push(r.redirect)
  } catch (e) {
    error.value = e.response?.data?.error || e.response?.data?.message || 'Invalid email or password'
  } finally {
    loading.value = false
  }
}
</script>
