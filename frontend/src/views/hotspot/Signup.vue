<template>
  <div class="min-h-screen flex items-center justify-center p-4" style="background: #0b120c">
    <div class="w-full max-w-md rounded-3xl p-7" style="background: #111a12; border: 1px solid rgba(165,214,167,.16); box-shadow: 0 24px 60px rgba(0,0,0,.45)">
      <div class="text-center mb-5">
        <span class="inline-flex items-center justify-center h-14 w-14 rounded-2xl text-2xl mx-auto" style="background: rgba(18,188,0,.12); border: 1px solid rgba(18,188,0,.35); color: #12BC00">
          <i class="fas fa-wifi"></i>
        </span>
        <p class="text-[10px] font-bold uppercase tracking-[3px] mt-4 mb-1" style="color: #12BC00">Customer portal</p>
        <h1 class="text-xl font-black text-white">Create account</h1>
        <p class="text-sm text-white/50">Register to buy voucher packages</p>
      </div>

      <p v-if="error" class="rounded-2xl px-4 py-3 mb-5 text-sm" style="background: rgba(127,29,29,.18); border: 1px solid rgba(248,113,113,.3); color: #fca5a5">{{ error }}</p>

      <!-- NOT on WiFi -->
      <div v-if="store.initialized && !store.onWifi" class="text-center">
        <h2 class="text-base font-bold text-white mb-2">Connect to Meshline WiFi</h2>
        <p class="text-sm text-white/60 mb-5">You must be connected to a Meshline WiFi network to register.</p>
        <div class="rounded-xl p-4 text-left space-y-3" style="background: #0b120c; border: 1px solid rgba(165,214,167,.14)">
          <div class="flex items-start gap-3"><span class="font-bold text-sm" style="color:#12BC00">1.</span><p class="text-sm text-white/70">Connect to <b class="text-white">Meshline WiFi</b> in your WiFi settings</p></div>
          <div class="flex items-start gap-3"><span class="font-bold text-sm" style="color:#12BC00">2.</span><p class="text-sm text-white/70">Open your browser — you'll be redirected here automatically</p></div>
          <div class="flex items-start gap-3"><span class="font-bold text-sm" style="color:#12BC00">3.</span><p class="text-sm text-white/70">Register and buy a package to get internet access</p></div>
        </div>
        <router-link to="/hotspot" class="ig-btn ig-btn-primary w-full py-2.5 mt-6 justify-center">Back</router-link>
      </div>

      <!-- ON WiFi -->
      <form v-else @submit.prevent="handleRegister" class="space-y-3">
        <div>
          <label class="block text-sm font-semibold text-white/70 mb-1">Full name</label>
          <div class="relative"><i class="fas fa-user absolute left-3.5 top-1/2 -translate-y-1/2 text-sm pointer-events-none" style="color:#A5D6A7;opacity:.6"></i><input v-model="name" type="text" required class="input pl-11" placeholder="John Doe"></div>
        </div>
        <div>
          <label class="block text-sm font-semibold text-white/70 mb-1">Phone number</label>
          <div class="relative"><i class="fas fa-phone absolute left-3.5 top-1/2 -translate-y-1/2 text-sm pointer-events-none" style="color:#A5D6A7;opacity:.6"></i><input v-model="phoneFull" type="tel" required maxlength="10" class="input pl-11" placeholder="07XXXXXXXX"></div>
        </div>
        <div>
          <label class="block text-sm font-semibold text-white/70 mb-1">Email <span class="text-white/40 font-normal">(optional)</span></label>
          <div class="relative"><i class="fas fa-envelope absolute left-3.5 top-1/2 -translate-y-1/2 text-sm pointer-events-none" style="color:#A5D6A7;opacity:.6"></i><input v-model="email" type="email" class="input pl-11" placeholder="you@email.com"></div>
        </div>
        <div>
          <label class="block text-sm font-semibold text-white/70 mb-1">Password</label>
          <div class="relative">
            <i class="fas fa-lock absolute left-3.5 top-1/2 -translate-y-1/2 text-sm pointer-events-none" style="color:#A5D6A7;opacity:.6"></i>
            <input v-model="password" :type="showPassword ? 'text' : 'password'" required minlength="6" class="input pl-11 pr-11" placeholder="Minimum 6 characters">
            <button type="button" class="absolute inset-y-0 right-0 pr-3.5 flex items-center text-white/40 hover:text-white" :aria-label="showPassword ? 'Hide password' : 'Show password'" @click="showPassword = !showPassword"><i :class="showPassword ? 'fas fa-eye-slash' : 'fas fa-eye'" class="text-sm"></i></button>
          </div>
        </div>
        <div>
          <label class="block text-sm font-semibold text-white/70 mb-1">Confirm password</label>
          <div class="relative"><i class="fas fa-lock absolute left-3.5 top-1/2 -translate-y-1/2 text-sm pointer-events-none" style="color:#A5D6A7;opacity:.6"></i><input v-model="passwordConfirm" :type="showConfirm ? 'text' : 'password'" required minlength="6" class="input pl-11" placeholder="Re-enter password"></div>
        </div>
        <div>
          <label class="block text-sm font-semibold text-white/70 mb-1">Referral code <span class="text-white/40 font-normal">(optional)</span></label>
          <div class="relative"><i class="fas fa-tag absolute left-3.5 top-1/2 -translate-y-1/2 text-sm pointer-events-none" style="color:#A5D6A7;opacity:.6"></i><input v-model="referralCode" type="text" class="input pl-11" placeholder="Optional"></div>
        </div>
        <button type="submit" :disabled="loading" class="w-full py-2.5 text-white rounded-xl font-bold text-sm uppercase tracking-wide flex items-center justify-center gap-2 disabled:opacity-50" style="background:#12BC00">
          <i v-if="loading" class="fas fa-spinner fa-spin"></i>{{ loading ? 'Creating account...' : 'Register' }}
        </button>
      </form>

      <p class="text-center text-sm text-white/50 mt-5">Already have an account?
        <router-link to="/hotspot/login" class="font-semibold" style="color:#12BC00">Sign in</router-link>
      </p>
    </div>
  </div>
</template>

<script setup>
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { hotspotPost } from '../../api/client'
import { useHotspotStore } from '../../stores/hotspot'

const router = useRouter()
const store = useHotspotStore()
const name = ref('')
const phoneFull = ref('')
const email = ref('')
const password = ref('')
const passwordConfirm = ref('')
const referralCode = ref('')
const showPassword = ref(false)
const showConfirm = ref(false)
const error = ref('')
const loading = ref(false)

function normalizePhone(p) {
  if (!p) return p
  let phone = String(p).trim().replace(/[\s\-\(\)]/g, '')
  if (phone.startsWith('+')) phone = phone.substring(1)
  if (phone.startsWith('255') && phone.length === 12) phone = '0' + phone.substring(3)
  if (/^7[0-9]{8}$/.test(phone)) phone = '0' + phone
  if (/^6[0-9]{8}$/.test(phone)) phone = '0' + phone
  return phone
}

async function handleRegister() {
  if (password.value !== passwordConfirm.value) {
    error.value = 'Passwords do not match'
    return
  }
  loading.value = true; error.value = ''
  const localPhone = normalizePhone(phoneFull.value)
  try {
    await hotspotPost('/register', {
      name: name.value,
      phone_number: localPhone,
      email: email.value,
      password: password.value,
      password_confirm: passwordConfirm.value,
      referral_code: referralCode.value,
      router_id: store.routerID || '',
      mac: store.mac || ''
    })
    await store.refreshUser()
    router.replace('/hotspot/dashboard')
  } catch (e) {
    error.value = e.response?.data?.error || 'Registration failed'
  } finally { loading.value = false }
}
</script>
