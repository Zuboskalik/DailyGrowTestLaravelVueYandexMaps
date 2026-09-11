<script setup>
import { computed } from 'vue'

const props = defineProps({
  status: {
    type: String,
    required: true,
  },
  errorMessage: {
    type: String,
    default: null,
  },
})

const labels = {
  idle: 'Ещё не собирались',
  pending: 'В очереди',
  processing: 'Собираем отзывы…',
  completed: 'Готово',
  failed: 'Ошибка',
}

const label = computed(() => labels[props.status] ?? props.status)
</script>

<template>
  <span class="badge" :class="`badge--${status}`">
    <span class="dot" />
    {{ label }}
    <span v-if="status === 'failed' && errorMessage" class="error-message">
      — {{ errorMessage }}
    </span>
  </span>
</template>

<style scoped>
.badge {
  display: inline-flex;
  align-items: center;
  gap: 0.4rem;
  padding: 0.25rem 0.6rem;
  border-radius: 999px;
  font-size: 0.85rem;
  font-weight: 500;
  background: #eee;
  color: #444;
}

.dot {
  width: 0.5rem;
  height: 0.5rem;
  border-radius: 50%;
  background: currentColor;
}

.badge--idle {
  background: #eee;
  color: #666;
}

.badge--pending {
  background: #fff3cd;
  color: #8a6d00;
}

.badge--processing {
  background: #d6e9ff;
  color: #1a56b0;
}

.badge--processing .dot {
  animation: pulse 1.2s ease-in-out infinite;
}

.badge--completed {
  background: #d9f2df;
  color: #197a3d;
}

.badge--failed {
  background: #fde0e0;
  color: #b00020;
}

.error-message {
  font-weight: 400;
  opacity: 0.9;
}

@keyframes pulse {
  0%,
  100% {
    opacity: 1;
  }
  50% {
    opacity: 0.3;
  }
}
</style>
