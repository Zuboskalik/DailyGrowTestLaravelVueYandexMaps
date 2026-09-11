<script setup>
const props = defineProps({
  currentPage: {
    type: Number,
    required: true,
  },
  lastPage: {
    type: Number,
    required: true,
  },
})

const emit = defineEmits(['update:page'])

function goTo(page) {
  if (page < 1 || page > props.lastPage || page === props.currentPage) return
  emit('update:page', page)
}
</script>

<template>
  <nav v-if="lastPage > 1" class="pagination" aria-label="Пагинация">
    <button type="button" :disabled="currentPage === 1" @click="goTo(currentPage - 1)">
      Назад
    </button>
    <span class="status">Страница {{ currentPage }} из {{ lastPage }}</span>
    <button type="button" :disabled="currentPage === lastPage" @click="goTo(currentPage + 1)">
      Далее
    </button>
  </nav>
</template>

<style scoped>
.pagination {
  display: flex;
  align-items: center;
  gap: 0.75rem;
  margin-top: 1rem;
}

.pagination button {
  padding: 0.35rem 0.8rem;
  border: 1px solid #ccc;
  border-radius: 4px;
  background: white;
  cursor: pointer;
}

.pagination button:disabled {
  opacity: 0.5;
  cursor: default;
}

.status {
  font-size: 0.85rem;
  color: #555;
}
</style>
