<script setup>
defineProps({ label: String, error: String, hint: String, for: String });
</script>

<template>
    <div>
        <label v-if="label" :for="$props.for" class="label">{{ label }}</label>
        <slot />
        <p v-if="error" class="mt-1 text-xs text-up">{{ error }}</p>
        <p v-else-if="hint" class="mt-1 text-xs text-steel-500">{{ hint }}</p>
    </div>
</template>
