<template>
  <div class="flex h-screen overflow-hidden page-shell" style="background: #0a0a0f">
    <!-- Mobile sidebar overlay -->
    <div v-if="sidebarOpen" class="fixed inset-0 z-40 bg-black/60 md:hidden" @click="sidebarOpen = false"></div>

    <!-- Mobile drawer -->
    <div class="fixed inset-y-0 left-0 z-50 w-[272px] max-w-[85vw] md:hidden"
         :class="sidebarOpen ? 'translate-x-0' : '-translate-x-full'"
         style="transition: transform .3s ease; background: linear-gradient(180deg, #0a0a0f 0%, #0e180f 55%, #070b08 100%)">
      <SidebarContent
        :nav-items="navItems"
        :subtitle="title"
        :collapsed="false"
        :collapsible="false"
        @close="sidebarOpen = false"
        @navigate="sidebarOpen = false"
        @logout="handleLogout"
      />
    </div>

    <!-- Desktop sidebar: full rail or collapsed icon rail -->
    <aside class="hidden md:flex shrink-0 flex-col transition-[width] duration-300 ease-in-out"
           :class="collapsed ? 'w-[76px]' : 'w-[272px]'"
           style="background: linear-gradient(180deg, #0a0a0f 0%, #0e180f 55%, #070b08 100%); border-right: 1px solid rgba(165,214,167,.08)">
      <SidebarContent
        :nav-items="navItems"
        :subtitle="title"
        :collapsed="collapsed"
        :collapsible="true"
        @toggle="collapsed = !collapsed"
        @logout="handleLogout"
      />
    </aside>

    <div class="flex-1 flex flex-col min-w-0">
      <!-- Topbar -->
      <header class="h-14 px-3 md:px-6 flex items-center justify-between gap-2 shrink-0 topbar">
        <div class="flex items-center gap-3 min-w-0">
          <button type="button" class="btn-ghost md:hidden shrink-0" aria-label="Open menu" @click="sidebarOpen = true">
            <i class="fas fa-bars"></i>
          </button>
          <div class="hidden sm:flex items-center gap-3 text-sm text-white/50">
            <span class="inline-flex items-center gap-1.5 whitespace-nowrap">
              <i class="far fa-calendar-alt text-[#A5D6A7]"></i>{{ currentDate }}
            </span>
            <span class="inline-flex items-center gap-1.5 whitespace-nowrap">
              <i class="far fa-clock text-[#A5D6A7]"></i>{{ currentTime }}
            </span>
          </div>
          <slot name="header-actions" />
        </div>

        <div class="flex items-center gap-2 md:gap-3">
          <!-- Notifications -->
          <div class="relative" @click.self="showNotifications = false">
            <button type="button"
                    class="relative w-9 h-9 rounded-xl flex items-center justify-center transition-colors"
                    :class="showNotifications ? 'bg-[#12BC00]/15 text-[#12BC00]' : 'text-white/50 hover:text-white hover:bg-white/5'"
                    @click="showNotifications = !showNotifications">
              <i class="fas fa-bell text-sm"></i>
              <span v-if="unread > 0" class="absolute -top-0.5 -right-0.5 min-w-4 h-4 px-1 rounded-full bg-[#12BC00] text-[#0a0a0f] text-[9px] font-bold flex items-center justify-center">
                {{ unread > 9 ? '9+' : unread }}
              </span>
            </button>
            <div v-if="showNotifications" class="absolute right-0 top-full mt-2 w-80 rounded-xl shadow-2xl z-50 overflow-hidden dropdown-panel">
              <div class="px-4 py-3 border-b border-white/10 flex items-center justify-between">
                <span class="text-sm font-bold text-white">Notifications</span>
                <span class="text-[11px] text-[#A5D6A7]/70">{{ unread }} unread</span>
              </div>
              <div class="max-h-80 overflow-y-auto sidebar-scroll">
                <div v-for="n in notifications" :key="n.id" class="px-4 py-3 border-b border-white/5 transition-colors hover:bg-white/5">
                  <div class="flex items-start gap-2.5">
                    <span class="mt-1.5 w-2 h-2 rounded-full shrink-0" :class="n.is_read ? 'bg-white/20' : 'bg-[#12BC00]'"></span>
                    <div class="min-w-0">
                      <p class="text-sm font-semibold text-white truncate">{{ n.title }}</p>
                      <p class="text-xs text-white/40 line-clamp-2">{{ n.message }}</p>
                    </div>
                  </div>
                </div>
                <div v-if="!notifications || !notifications.length" class="p-6 text-center text-sm text-white/30">No notifications</div>
              </div>
            </div>
          </div>

          <!-- User dropdown -->
          <div class="relative">
            <button type="button"
                    class="flex items-center gap-2.5 pl-1 pr-2 md:pr-3 py-1 rounded-full hover:bg-white/5 transition-colors"
                    @click="showUserMenu = !showUserMenu">
              <span class="h-9 w-9 rounded-full bg-[#12BC00] text-[#0a0a0f] text-xs font-bold flex items-center justify-center border-2 border-[#A5D6A7]">
                {{ userInitials }}
              </span>
              <span class="hidden sm:block text-left leading-tight">
                <span class="block text-sm font-semibold text-white">{{ user?.name || 'User' }}</span>
                <span class="block text-[11px] text-[#A5D6A7]/60 capitalize">{{ user?.role || '' }}</span>
              </span>
              <i class="fas fa-chevron-down text-[10px] text-white/40"></i>
            </button>
            <div v-if="showUserMenu" class="absolute right-0 top-full mt-2 w-56 rounded-xl py-2 z-50 dropdown-panel fade-in">
              <div class="px-4 py-2 border-b border-white/10">
                <p class="text-sm font-semibold text-white truncate">{{ user?.name || 'User' }}</p>
                <p class="text-xs text-white/40 truncate">{{ user?.email || '' }}</p>
              </div>
              <button type="button" class="w-full flex items-center gap-3 px-4 py-2.5 text-sm text-red-400 hover:bg-white/5 transition-colors" @click="handleLogout">
                <i class="fas fa-sign-out-alt text-sm"></i> Log out
              </button>
            </div>
          </div>
        </div>
      </header>

      <!-- Page content -->
      <main class="flex-1 overflow-y-auto content-area" id="app-main">
        <div class="p-4 md:p-6 page-enter">
          <div v-if="subtitle" class="mb-5">
            <h1 class="page-title">{{ subtitle }}</h1>
            <div class="mt-2 h-0.5 w-14 rounded-full" style="background: linear-gradient(90deg, #12BC00, #66BB6A)"></div>
          </div>
          <slot />
        </div>
      </main>
    </div>
  </div>
</template>

<script setup>
import { computed, onMounted, onUnmounted, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import SidebarContent from './SidebarContent.vue'

const props = defineProps({
  title: { type: String, default: 'Panel' },
  subtitle: { type: String, default: '' },
  navItems: { type: Array, default: () => [] },
  user: { type: Object, default: () => ({}) },
  onLogout: { type: Function, default: () => {} },
  notifications: { type: Array, default: () => [] },
  unreadCount: { type: Number, default: null },
})

const route = useRoute()
const sidebarOpen = ref(false)
const showNotifications = ref(false)
const showUserMenu = ref(false)
const collapsed = ref(false)
const currentDate = ref('')
const currentTime = ref('')

const userInitials = computed(() => {
  const name = props.user?.name || props.user?.email || 'U'
  return name.split(' ').map(w => w[0]).join('').substring(0, 2).toUpperCase()
})
const unread = computed(() => props.unreadCount ?? props.notifications.filter(n => !n.is_read).length)

function handleLogout() {
  showUserMenu.value = false
  props.onLogout()
}

let timer
function updateClock() {
  const now = new Date()
  currentDate.value = now.toLocaleDateString('en-GB', { weekday: 'long', day: '2-digit', month: 'long', year: 'numeric' })
  currentTime.value = now.toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit' })
}
onMounted(() => {
  collapsed.value = localStorage.getItem('meshlineSidebarCollapsed') === 'true'
  updateClock()
  timer = setInterval(updateClock, 30000)
})
onUnmounted(() => clearInterval(timer))
watch(collapsed, value => localStorage.setItem('meshlineSidebarCollapsed', String(value)))
watch(route, () => {
  sidebarOpen.value = false
  showNotifications.value = false
  showUserMenu.value = false
})
</script>
