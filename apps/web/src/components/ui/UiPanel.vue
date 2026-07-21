<template>
  <section
    ref="panelElement"
    class="ui-panel"
    :aria-labelledby="titleId"
  >
    <header
      v-if="title || label || $slots.actions"
      class="ui-panel__header"
    >
      <div>
        <p
          v-if="label"
          class="panel-label"
        >
          {{ label }}
        </p>
        <h3
          v-if="title"
          :id="titleId"
        >
          {{ title }}
        </h3>
        <p
          v-if="description"
          class="meta"
        >
          {{ description }}
        </p>
      </div>
      <slot name="actions" />
    </header>
    <slot />
  </section>
</template>

<script setup lang="ts">
import { computed, ref, useId } from 'vue'

const props = withDefaults(defineProps<{
  title?: string
  label?: string
  description?: string
  id?: string
}>(), {
  title: '',
  label: '',
  description: '',
  id: undefined,
})

const generatedId = useId()
const titleId = computed(() => props.id ?? `panel-${generatedId}`)
const panelElement = ref<HTMLElement | null>(null)

defineExpose({
  focus: () => panelElement.value?.focus(),
})
</script>
