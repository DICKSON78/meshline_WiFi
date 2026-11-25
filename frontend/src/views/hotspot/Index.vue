<template>
  <div class="min-h-screen flex flex-col items-center justify-center p-6 relative overflow-hidden" style="background: #0b120c">
    <div class="w-full max-w-[380px] text-center relative z-10">
      <span class="inline-flex items-center justify-center rounded-full bg-[#12BC00]/15 border border-[#12BC00]/30 h-20 w-20 mx-auto mb-3 text-4xl"><i class="fas fa-wifi text-[#12BC00]"></i></span>
      <h1 class="ig-wordmark text-4xl mb-1">Meshline WiFi</h1>
      <p class="text-white/50 mb-6">Simple, affordable internet for everyone</p>

      <!-- NOT on WiFi — show connection instruction -->
      <div v-if="store.initialized && !store.onWifi" class="space-y-4">
        <div class="rounded-2xl shadow-2xl p-6 text-center" style="background: #111118; border: 1px solid #1e1e2a">
          <div class="w-16 h-16 rounded-full bg-[#12BC00]/10 flex items-center justify-center mx-auto mb-4">
            <i class="fas fa-wifi text-2xl text-[#12BC00]"></i>
          </div>
          <h2 class="text-lg font-bold text-white mb-2">Connect to Meshline WiFi</h2>
          <p class="text-sm text-white/60 mb-4">
            To access free internet, connect to a Meshline WiFi hotspot first.
          </p>
          <div class="bg-[#0a0a0f] rounded-xl p-4 text-left space-y-3 border border-[#1e1e2a]">
            <div class="flex items-start gap-3">
              <span class="text-[#12BC00] font-bold text-sm">1.</span>
              <p class="text-sm text-white/70">Open your WiFi settings and connect to <b class="text-white">Meshline WiFi</b></p>
            </div>
            <div class="flex items-start gap-3">
              <span class="text-[#12BC00] font-bold text-sm">2.</span>
              <p class="text-sm text-white/70">Open your browser — you'll be redirected here automatically</p>
            </div>
            <div class="flex items-start gap-3">
              <span class="text-[#12BC00] font-bold text-sm">3.</span>
              <p class="text-sm text-white/70">Log in or register, then buy a voucher package to get online</p>
            </div>
          </div>
        </div>
        <p class="text-xs text-white/30">Access from outside the network is limited</p>
        <InstallApp />
      </div>

      <!-- ON WiFi — show normal landing -->
      <div v-else-if="store.initialized">
        <p v-if="store.ssidshow" class="text-white/50 mb-8 text-sm">
          Connected to <b>{{ store.ssidshow }}</b>
        </p>
        <div v-if="store.isLoggedIn" class="space-y-3">
          <router-link to="/hotspot/dashboard" class="w-full bg-[#12BC00] hover:bg-[#66BB6A] text-white font-semibold py-2.5 rounded-xl transition-all shadow-lg shadow-[#12BC00]/20 flex items-center justify-center">Continue to Dashboard</router-link>
        </div>
        <div v-else class="space-y-3">
          <router-link to="/hotspot/login" class="w-full bg-[#12BC00] hover:bg-[#66BB6A] text-white font-semibold py-2.5 rounded-xl transition-all shadow-lg shadow-[#12BC00]/20 flex items-center justify-center">Log in</router-link>
          <div class="rounded-xl p-4 text-sm" style="background: #111118; border: 1px solid #1e1e2a">
            <span class="text-white/50">Don't have an account?</span>
            <router-link to="/hotspot/signup" class="text-[#12BC00] font-semibold hover:underline"> Register</router-link>
          </div>
          <InstallApp />
        </div>
      </div>

      <!-- Loading -->
      <div v-else class="text-center py-8">
        <i class="fas fa-spinner fa-spin text-2xl text-[#12BC00]"></i>
        <p class="text-sm text-white/40 mt-3">Connecting...</p>
      </div>
    </div>
  </div>
</template>

<script setup>
import { useHotspotStore } from '../../stores/hotspot'
import InstallApp from '../../components/InstallApp.vue'

const store = useHotspotStore()
// HotspotLayout initializes the session (including the captive-portal query).
</script>
