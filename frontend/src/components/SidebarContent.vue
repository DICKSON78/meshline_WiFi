<template>
  <div class="h-full flex flex-col sidebar-scroll">
    <!-- Brand / collapse header -->
    <div class="p-3 md:p-5 md:pb-3 flex items-center gap-3" :class="collapsed ? 'md:!justify-center md:!p-3' : ''">
      <span class="w-9 h-9 rounded-xl flex items-center justify-center shrink-0 bg-white/5 border border-[#12BC00]/30 text-[#12BC00] text-sm">
        <i class="fas fa-wifi"></i>
      </span>
      <div class="min-w-0 flex-1" :class="collapsed ? 'md:!hidden' : ''">
        <h1 class="text-white font-bold text-[15px] leading-tight truncate">MESHLINE</h1>
        <p class="text-[#A5D6A7]/60 text-[11px] truncate">{{ subtitle || 'Internet · Vouchers · HQ' }}</p>
      </div>

      <button v-if="collapsible" type="button"
              class="hidden md:inline-flex shrink-0 items-center justify-center h-8 w-8 rounded-lg text-[#A5D6A7]/70 hover:text-white hover:bg-white/10 transition-colors"
              :title="collapsed ? 'Expand sidebar' : 'Collapse sidebar'"
              :aria-expanded="(!collapsed).toString()"
              aria-label="Toggle sidebar"
              @click="$emit('toggle')">
        <i class="fas fa-chevron-left text-xs transition-transform duration-300" :class="collapsed ? 'rotate-180' : ''"></i>
      </button>

      <button type="button" class="md:hidden shrink-0 h-8 w-8 rounded-lg text-[#A5D6A7]/70 hover:text-white hover:bg-white/10 transition-colors" aria-label="Close menu" @click="$emit('close')">
        <i class="fas fa-times"></i>
      </button>
    </div>

    <!-- Grouped navigation -->
    <nav class="flex-1 overflow-y-auto overflow-x-hidden px-3 py-2 sidebar-scroll">
      <div v-for="(group, gi) in groups" :key="group.label" class="mb-2">
        <template v-if="!collapsed">
          <button type="button"
                  class="w-full flex items-center justify-between px-3 py-2 text-[11px] font-bold uppercase tracking-wider text-[#A5D6A7]/80 hover:text-white transition-colors rounded-lg"
                  @click="toggleGroup(gi)">
            <span>{{ group.label }}</span>
            <i class="fas fa-chevron-right text-[10px] transition-transform duration-200" :style="isOpen(gi) ? 'transform: rotate(90deg)' : ''"></i>
          </button>
        </template>
        <template v-else>
          <div class="hidden md:block my-1.5 mx-2 border-t border-white/10"></div>
        </template>

        <div v-show="isOpen(gi)">
          <div class="space-y-0.5">
            <router-link v-for="item in group.items" :key="item.path" :to="item.path"
                         class="group relative flex items-center gap-3 px-3 py-2.5 rounded-xl text-sm font-medium transition-all duration-200"
                         :class="isActive(item.path)
                           ? 'text-white shadow-lg'
                           : 'text-white/60 hover:text-white hover:bg-white/10'"
                         :style="isActive(item.path) ? 'background: linear-gradient(135deg, #12BC00, #0B8A00); box-shadow: 0 4px 14px rgba(18,188,0,0.28)' : ''"
                         :title="collapsed ? item.label : null"
                         @click="$emit('navigate')">
              <i :class="item.icon" class="shrink-0 w-5 text-center text-sm"></i>
              <span class="truncate" :class="collapsed ? 'md:!hidden' : ''">{{ item.label }}</span>

              <span v-if="collapsed"
                    class="hidden md:block absolute left-full ml-3 z-50 whitespace-nowrap rounded-lg bg-[#0a0a0f] px-3 py-1.5 text-xs font-medium text-white shadow-lg border border-[#12BC00]/30 opacity-0 pointer-events-none group-hover:opacity-100 transition-opacity duration-150">
                {{ item.label }}
              </span>
            </router-link>
          </div>
        </div>
      </div>
    </nav>

    <!-- Logout -->
    <div class="px-3 pb-4 border-t border-white/10 pt-3">
      <button type="button"
              class="group relative w-full flex items-center gap-3 px-3 py-2.5 rounded-xl text-sm font-medium text-white/60 hover:text-white hover:bg-white/10 transition-all duration-200"
              :class="collapsed ? 'md:!justify-center md:!px-2' : ''"
              @click="$emit('logout')">
        <i class="fas fa-sign-out-alt shrink-0 w-5 text-center text-sm"></i>
        <span :class="collapsed ? 'md:!hidden' : ''">Log out</span>
        <span v-if="collapsed" class="hidden md:block absolute left-full ml-3 z-50 whitespace-nowrap rounded-lg bg-[#0a0a0f] px-3 py-1.5 text-xs text-white shadow-lg border border-[#12BC00]/30 opacity-0 pointer-events-none group-hover:opacity-100">Log out</span>
      </button>
    </div>
  </div>
</template>

<script setup>
import { computed, reactive } from 'vue'
import { useRoute } from 'vue-router'

const props = defineProps({
  navItems: { type: Array, default: () => [] },
  subtitle: { type: String, default: '' },
  collapsed: { type: Boolean, default: false },
  collapsible: { type: Boolean, default: true },
})
defineEmits(['toggle', 'close', 'logout', 'navigate'])

const route = useRoute()
const openGroups = reactive({})

const groups = computed(() => {
  const out = []
  let current = { label: '', items: [] }
  for (const item of props.navItems) {
    if (item.divider) {
      if (current.items.length) out.push(current)
      current = { label: item.divider, items: [] }
    } else {
      current.items.push(item)
    }
  }
  if (current.items.length) out.push(current)
  return out
})

function isOpen(index) {
  return openGroups[index] !== false
}
function toggleGroup(index) {
  openGroups[index] = !isOpen(index)
}
function isActive(path) {
  return route.path === path || route.path.startsWith(path + '/')
}
</script>
