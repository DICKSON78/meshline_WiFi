import { createRouter, createWebHistory } from 'vue-router'
import { useAuthStore } from '../stores/auth'
import { useHotspotStore } from '../stores/hotspot'

// HotBando is focused on internet vouchers, resellers and the HQ panel only.
const routes = [
  // Public website
  { path: '/', name: 'landing', component: () => import('../views/website/Home.vue') },
  { path: '/services', name: 'website-services', component: () => import('../views/website/Services.vue') },
  { path: '/pricing', name: 'website-pricing', component: () => import('../views/website/Pricing.vue') },
  { path: '/about', name: 'website-about', component: () => import('../views/website/About.vue') },
  { path: '/contact', name: 'website-contact', component: () => import('../views/website/Contact.vue') },

  // Login & reseller signup
  { path: '/login', name: 'portal-login', component: () => import('../views/PortalLogin.vue') },
  { path: '/admin/login', redirect: '/login' },
  { path: '/reseller/login', redirect: '/login' },
  { path: '/reseller/signup', name: 'reseller-signup', component: () => import('../views/reseller/Signup.vue') },
  { path: '/apply/reseller', redirect: '/reseller/signup' },

  // Legacy URLs kept as harmless redirects
  { path: '/admin/vouchers', redirect: '/admin/packages' },
  { path: '/admin/generate-vouchers', redirect: '/admin/packages' },
  { path: '/admin/analytics', redirect: '/admin/dashboard' },
  { path: '/admin/setup-wizard', redirect: '/admin/routers' },

  // ==================== HQ / Admin Panel ====================
  {
    path: '/admin',
    component: () => import('../layouts/AdminLayout.vue'),
    meta: { requiresAuth: true, roles: ['admin', 'super_admin'] },
    children: [
      { path: '', redirect: '/admin/overview' },
      { path: 'overview', name: 'overview', component: () => import('../views/admin/Overview.vue') },
      { path: 'dashboard', name: 'dashboard', component: () => import('../views/admin/Dashboard.vue') },
      { path: 'branches', name: 'branches', component: () => import('../views/admin/Branches.vue') },
      { path: 'staff', name: 'staff', component: () => import('../views/admin/Staff.vue') },
      { path: 'finance', name: 'finance', component: () => import('../views/admin/Finance.vue') },
      { path: 'assets', name: 'assets', component: () => import('../views/admin/Assets.vue') },
      { path: 'tickets', name: 'tickets', component: () => import('../views/admin/Tickets.vue') },
      { path: 'audit', name: 'audit', component: () => import('../views/admin/Audit.vue') },
      { path: 'compliance', name: 'compliance', component: () => import('../views/admin/Compliance.vue') },
      { path: 'reports', name: 'reports', component: () => import('../views/admin/Reports.vue') },
      { path: 'users', name: 'users', component: () => import('../views/admin/Users.vue') },
      { path: 'routers', name: 'routers', component: () => import('../views/admin/Routers.vue') },
      { path: 'router-fleet', name: 'router-fleet', component: () => import('../views/admin/RouterFleet.vue') },
      { path: 'reseller-routers', name: 'reseller-routers', component: () => import('../views/admin/ResellerRouters.vue') },
      { path: 'withdrawals', name: 'withdrawals', component: () => import('../views/admin/Withdrawals.vue') },
      { path: 'payments', name: 'payments', component: () => import('../views/admin/Payments.vue') },
      { path: 'packages', name: 'packages', component: () => import('../views/admin/Packages.vue') },
      { path: 'notifications', name: 'notifications', component: () => import('../views/admin/Notifications.vue') },
      { path: 'settings', name: 'settings', component: () => import('../views/admin/Settings.vue') },
      { path: 'change-password', name: 'change-password', component: () => import('../views/admin/ChangePassword.vue') }
    ]
  },

  // ==================== Customer / Hotspot Portal ====================
  {
    path: '/hotspot',
    component: () => import('../layouts/HotspotLayout.vue'),
    children: [
      { path: '', name: 'hotspot-index', component: () => import('../views/hotspot/Index.vue') },
      { path: 'login', name: 'hotspot-login', component: () => import('../views/hotspot/Login.vue') },
      { path: 'signup', name: 'hotspot-signup', component: () => import('../views/hotspot/Signup.vue') },
      { path: 'dashboard', name: 'hotspot-dashboard', component: () => import('../views/hotspot/Dashboard.vue') },
      { path: 'subscribe', name: 'hotspot-subscribe', component: () => import('../views/hotspot/Subscribe.vue') }
    ]
  },

  // ==================== Reseller Portal ====================
  {
    path: '/reseller',
    component: () => import('../layouts/ResellerLayout.vue'),
    meta: { requiresAuth: true, roles: ['reseller'] },
    children: [
      { path: '', redirect: '/reseller/dashboard' },
      { path: 'dashboard', name: 'reseller-dashboard', component: () => import('../views/reseller/Dashboard.vue') },
      { path: 'vouchers', name: 'reseller-vouchers', component: () => import('../views/reseller/Vouchers.vue') },
      { path: 'withdraw', name: 'reseller-withdraw', component: () => import('../views/reseller/Withdraw.vue') },
      { path: 'topup', name: 'reseller-topup', component: () => import('../views/reseller/TopUp.vue') },
      { path: 'settings', name: 'reseller-settings', component: () => import('../views/reseller/Settings.vue') }
    ]
  },

  { path: '/:pathMatch(.*)*', name: 'not-found', component: () => import('../views/NotFound.vue') }
]

const router = createRouter({
  history: createWebHistory(),
  routes,
  scrollBehavior(to, from, savedPosition) {
    if (savedPosition) return savedPosition
    if (to.hash) return { el: to.hash, behavior: 'smooth' }
    return { top: 0 }
  }
})

// ==================== Unified RBAC Guard ====================
router.beforeEach(async (to) => {
  const hotspotGuestRoutes = ['hotspot-login', 'hotspot-signup']
  if (hotspotGuestRoutes.includes(to.name)) {
    const hotspot = useHotspotStore()
    await hotspot.init(to.query)
    if (hotspot.isLoggedIn) return '/hotspot/dashboard'
    return
  }

  const hotspotAuthRoutes = ['hotspot-dashboard', 'hotspot-subscribe']
  if (hotspotAuthRoutes.includes(to.name)) {
    const hotspot = useHotspotStore()
    await hotspot.init(to.query)
    if (!hotspot.isLoggedIn) return '/hotspot/login'
    if (!hotspot.onWifi) return '/hotspot'
    return
  }

  if (to.meta.requiresAuth) {
    const auth = useAuthStore()
    if (!auth.user) {
      const ok = await auth.checkSession()
      if (!ok) return '/login'
    }
    if (to.meta.roles && to.meta.roles.length > 0 && !auth.hasRole(...to.meta.roles)) {
      return auth.dashboardUrl()
    }
  }
})

export default router
