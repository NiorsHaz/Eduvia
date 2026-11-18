<script setup>
import { onMounted, onUnmounted } from 'vue';
import { useRouter } from 'vue-router';
import Toast from 'primevue/toast';
import { fetchMe, auth } from './stores/auth';
import { setToken } from './api';

const router = useRouter();

function handleExpired() {
    auth.user = null;
    if (router.currentRoute.value.name !== 'login') router.push({ name: 'login' });
}

onMounted(() => {
    fetchMe();
    window.addEventListener('auth:expired', handleExpired);
});

onUnmounted(() => window.removeEventListener('auth:expired', handleExpired));
</script>

<template>
    <Toast position="top-right" />
    <router-view />
</template>
