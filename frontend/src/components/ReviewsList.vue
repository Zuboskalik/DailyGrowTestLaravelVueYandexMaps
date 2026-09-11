<script setup>
defineProps({
  reviews: {
    type: Array,
    required: true,
  },
})

function formatDate(value) {
  if (!value) return null
  return new Date(value).toLocaleDateString('ru-RU')
}
</script>

<template>
  <p v-if="reviews.length === 0" class="empty-state">Отзывы пока не собраны.</p>

  <ul v-else class="reviews">
    <li v-for="review in reviews" :key="review.id" class="review">
      <div class="review-header">
        <strong>{{ review.author_name || 'Аноним' }}</strong>
        <span v-if="review.rating" class="rating">{{ review.rating }} / 5</span>
        <span v-if="review.review_created_at" class="date">
          {{ formatDate(review.review_created_at) }}
        </span>
      </div>

      <p v-if="review.has_text" class="text">{{ review.text }}</p>
      <p v-else class="text-only-rating">Только оценка, без комментария.</p>
    </li>
  </ul>
</template>

<style scoped>
.empty-state {
  color: #777;
  font-style: italic;
}

.reviews {
  list-style: none;
  margin: 0;
  padding: 0;
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
}

.review {
  padding: 0.75rem;
  border: 1px solid #e5e5e5;
  border-radius: 6px;
}

.review-header {
  display: flex;
  gap: 0.75rem;
  align-items: baseline;
  font-size: 0.9rem;
}

.rating {
  color: #d9a400;
  font-weight: 600;
}

.date {
  color: #999;
  margin-left: auto;
}

.text {
  margin: 0.4rem 0 0;
}

.text-only-rating {
  margin: 0.4rem 0 0;
  color: #999;
  font-style: italic;
}
</style>
