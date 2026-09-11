<script setup>
import { useRoute, useRouter } from 'vue-router'
import LoginForm from '../components/LoginForm.vue'

const router = useRouter()
const route = useRoute()

function onSuccess() {
  router.push(route.query.redirect || { name: 'dashboard' })
}
</script>

<template>
  <main class="login-view">
    <h1>Sign in</h1>
    <LoginForm @success="onSuccess" />
  </main>
</template>

<style scoped>
.login-view {
  max-width: 400px;
  margin: 4rem auto;
  padding: 1rem;
}
</style>
