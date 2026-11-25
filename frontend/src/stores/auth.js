import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import { apiGet, apiPost } from '../api/client'

// The platform now serves only three portals: HQ/admin, reseller and customer
// (the customer uses the hotspot store).
const PORTAL_ROLES = ['admin', 'super_admin', 'reseller']

export const useAuthStore = defineStore('auth', () => {
  const user = ref(null)
  const loading = ref(false)

  const isLoggedIn = computed(() => !!user.value)
  const isAdmin = computed(() => ['admin', 'super_admin'].includes(user.value?.role))
  const isSuperAdmin = computed(() => user.value?.role === 'super_admin')
  const isReseller = computed(() => user.value?.role === 'reseller')
  const userName = computed(() => user.value?.name || user.value?.email || '')
  const userRole = computed(() => user.value?.role || '')

  const PORTAL_REDIRECTS = {
    admin: '/admin/dashboard',
    super_admin: '/admin/dashboard',
    reseller: '/reseller/dashboard'
  }

  async function checkSession() {
    loading.value = true
    try {
      const r = await apiGet('/me')
      if (r.user && PORTAL_ROLES.includes(r.user.role)) {
        user.value = r.user
        return true
      }
      user.value = null
      return false
    } catch {
      user.value = null
      return false
    } finally {
      loading.value = false
    }
  }

  async function login(email, password) {
    const r = await apiPost('/login', { email, password })
    user.value = r.user
    return r
  }

  async function logout() {
    try { await apiPost('/logout', {}) } catch { /* ignore */ }
    user.value = null
  }

  function hasRole(...roles) {
    return roles.includes(user.value?.role)
  }

  function dashboardUrl() {
    return PORTAL_REDIRECTS[user.value?.role] || '/login'
  }

  return {
    user, loading,
    isLoggedIn, isAdmin, isSuperAdmin, isReseller,
    userName, userRole,
    checkSession, login, logout, hasRole, dashboardUrl
  }
})
