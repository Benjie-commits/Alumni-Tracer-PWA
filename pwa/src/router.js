import { createRouter, createWebHistory } from 'vue-router'
import { isSignedIn } from './session.js'

// Views are lazy-loaded so the first paint on a 3G connection only pays for the screen it needs.
const routes = [
  { path: '/', name: 'profile', component: () => import('./views/ProfileView.vue'), meta: { auth: true } },
  { path: '/work', name: 'work', component: () => import('./views/EmploymentView.vue'), meta: { auth: true } },
  { path: '/login', name: 'login', component: () => import('./views/LoginView.vue'), meta: { guest: true } },
  { path: '/register', name: 'register', component: () => import('./views/RegisterView.vue'), meta: { guest: true } },
  // The link in an SMS/WhatsApp message. Open to everyone: the token in the URL is the credential,
  // so an alumnus with no saved sign-in can answer in two minutes.
  { path: '/s/:token', name: 'survey', component: () => import('./views/SurveyView.vue') },
  { path: '/:pathMatch(.*)*', redirect: '/' },
]

export const router = createRouter({
  history: createWebHistory(),
  routes,
  scrollBehavior: () => ({ top: 0 }),
})

router.beforeEach((to) => {
  if (to.meta.auth && !isSignedIn.value) {
    return { name: 'login', query: to.fullPath !== '/' ? { redirect: to.fullPath } : {} }
  }
  if (to.meta.guest && isSignedIn.value) return { name: 'profile' }
})
