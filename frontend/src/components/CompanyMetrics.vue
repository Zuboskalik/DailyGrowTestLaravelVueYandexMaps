<script setup>
import { computed } from 'vue'

const props = defineProps({
  company: {
    type: Object,
    required: true,
  },
})

const lastParsedAt = computed(() => {
  if (!props.company.last_parsed_at) return 'Never'
  return new Date(props.company.last_parsed_at).toLocaleString()
})
</script>

<template>
  <dl class="metrics">
    <div class="metric">
      <dt>Rating</dt>
      <dd>{{ company.rating ?? '—' }}</dd>
    </div>
    <div class="metric">
      <dt>Ratings</dt>
      <dd>{{ company.ratings_count ?? '—' }}</dd>
    </div>
    <div class="metric">
      <dt>Reviews collected</dt>
      <dd>{{ company.reviews_count ?? 0 }}</dd>
    </div>
    <div class="metric">
      <dt>Last parsed</dt>
      <dd>{{ lastParsedAt }}</dd>
    </div>
  </dl>
</template>

<style scoped>
.metrics {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
  gap: 1rem;
  margin: 0;
  padding: 1rem;
  background: #f7f7f8;
  border-radius: 8px;
}

.metric dt {
  font-size: 0.75rem;
  color: #777;
  text-transform: uppercase;
  letter-spacing: 0.03em;
}

.metric dd {
  margin: 0.15rem 0 0;
  font-size: 1.25rem;
  font-weight: 600;
}
</style>
