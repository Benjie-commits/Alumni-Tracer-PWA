import { createRouter, createWebHistory } from 'vue-router'
import { homeRoute, isSignedIn, isStudent } from './session.js'

// Views are lazy-loaded so the first paint on a 3G connection only pays for the screen it needs.
const routes = [
  { path: '/', name: 'profile', component: () => import('./views/ProfileView.vue'), meta: { auth: true, alumni: true } },
  { path: '/work', name: 'work', component: () => import('./views/EmploymentView.vue'), meta: { auth: true, alumni: true } },
  { path: '/opportunities', name: 'opportunities', component: () => import('./views/OpportunitiesView.vue'), meta: { auth: true } },
  // Continuing students: their home (mentor request) and their own registration form.
  { path: '/student', name: 'student-home', component: () => import('./views/StudentHomeView.vue'), meta: { auth: true, student: true } },
  { path: '/register/student', name: 'register-student', component: () => import('./views/StudentRegisterView.vue'), meta: { guest: true } },
  { path: '/login', name: 'login', component: () => import('./views/LoginView.vue'), meta: { guest: true } },
  { path: '/register', name: 'register', component: () => import('./views/RegisterView.vue'), meta: { guest: true } },
  { path: '/forgot-password', name: 'forgot-password', component: () => import('./views/ForgotPasswordView.vue'), meta: { guest: true } },
  // Opened from the reset email; open to everyone (the token in the link is what counts).
  { path: '/reset-password', name: 'reset-password', component: () => import('./views/ResetPasswordView.vue') },
  // The link in an SMS/WhatsApp message. Open to everyone: the token in the URL is the credential,
  // so an alumnus with no saved sign-in can answer in two minutes.
  { path: '/s/:token', name: 'survey', component: () => import('./views/SurveyView.vue') },
  { path: '/:pathMatch(.*)*', redirect: '/' },
]

export const router = createRouter({
  history: createWebHistory(import.meta.env.BASE_URL),
  routes,
  scrollBehavior: () => ({ top: 0 }),
})

router.beforeEach((to) => {
  if (to.meta.auth && !isSignedIn.value) {
    return { name: 'login', query: to.fullPath !== '/' ? { redirect: to.fullPath } : {} }
  }
  if (to.meta.guest && isSignedIn.value) return homeRoute()
  // Each account type only sees its own screens.
  if (to.meta.alumni && isStudent.value) return homeRoute()
  if (to.meta.student && !isStudent.value) return homeRoute()
})
