<template>
  <DashboardLayout
    title="HotBando HQ"
    :subtitle="pageTitle"
    :navItems="navItems"
    :user="auth.user"
    :onLogout="logout"
  >
    <router-view />
  </DashboardLayout>
</template>

<script setup>
import { computed, onMounted } from 'vue'
import { useRoute } from 'vue-router'
import { useAuthStore } from '../stores/auth'
import DashboardLayout from '../components/DashboardLayout.vue'

const route = useRoute()
const auth = useAuthStore()

const navItems = [
  { divider: 'COMMAND CENTER' },
  { path: '/admin/overview', label: 'Overview', icon: 'fas fa-globe' },
  { path: '/admin/dashboard', label: 'Analytics', icon: 'fas fa-chart-pie' },

  { divider: 'OPERATIONS' },
  { path: '/admin/branches', label: 'Branches', icon: 'fas fa-building' },
  { path: '/admin/staff', label: 'Staff & HR', icon: 'fas fa-user-tie' },
  { path: '/admin/routers', label: 'Router Fleet', icon: 'fas fa-wifi' },
  { path: '/admin/router-fleet', label: 'Remote Collectors', icon: 'fas fa-satellite-dish' },
  { path: '/admin/assets', label: 'Assets & Inventory', icon: 'fas fa-boxes' },

  { divider: 'FINANCE' },
  { path: '/admin/payments', label: 'Voucher Payments', icon: 'fas fa-receipt' },
  { path: '/admin/finance', label: 'Finance', icon: 'fas fa-chart-line' },
  { path: '/admin/withdrawals', label: 'Reseller Withdrawals', icon: 'fas fa-hand-holding-usd' },

  { divider: 'INTERNET BUSINESS' },
  { path: '/admin/users', label: 'Customers', icon: 'fas fa-users' },
  { path: '/admin/reseller-routers', label: 'Resellers & Routers', icon: 'fas fa-store' },
  { path: '/admin/reports', label: 'Reports', icon: 'fas fa-chart-bar' },
  { path: '/admin/packages', label: 'Packages', icon: 'fas fa-box' },

  { divider: 'COMPLIANCE' },
  { path: '/admin/compliance', label: 'Contracts & Licenses', icon: 'fas fa-gavel' },
  { path: '/admin/tickets', label: 'Support Tickets', icon: 'fas fa-headset' },
  { path: '/admin/audit', label: 'Audit Trail', icon: 'fas fa-shield-alt' },

  { divider: 'SYSTEM' },
  { path: '/admin/notifications', label: 'Notifications', icon: 'fas fa-bell' },
  { path: '/admin/settings', label: 'Settings', icon: 'fas fa-cog' },
  { path: '/admin/change-password', label: 'Change Password', icon: 'fas fa-key' },
]

const pageTitle = computed(() => {
  const map = {
    'overview': 'National Overview',
    'dashboard': 'Analytics Dashboard',
    'branches': 'Branch Management',
    'staff': 'Staff & HR',
    'routers': 'Router Fleet',
    'router-fleet': 'Remote Collectors',
    'assets': 'Assets & Inventory',
    'finance': 'Financial Management',
    'payments': 'Voucher Payments',
    'withdrawals': 'Reseller Withdrawals',
    'users': 'Customer Management',
    'reseller-routers': 'Resellers & Routers',
    'reports': 'Internet & Voucher Reports',
    'packages': 'Internet Packages',
    'compliance': 'Contracts & Compliance',
    'tickets': 'Support Tickets',
    'audit': 'Audit Trail',
    'notifications': 'Notifications',
    'settings': 'System Settings',
    'change-password': 'Change Password'
  }
  return map[route.name] || 'Admin'
})

function logout() {
  auth.logout().then(() => window.location.href = '/login')
}

onMounted(async () => {
  if (!(await auth.checkSession())) window.location.href = '/login'
})
</script>
