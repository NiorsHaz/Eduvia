<script setup>
import { computed, onMounted, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useI18n } from 'vue-i18n';
import Select from 'primevue/select';
import Button from 'primevue/button';
import Dialog from 'primevue/dialog';
import InputText from 'primevue/inputtext';
import Password from 'primevue/password';
import Message from 'primevue/message';
import { auth, logout, can, changePassword } from '../stores/auth';
import { loadLookups, resetLookups, currentYear } from '../stores/lookups';
import { locales, setLocale } from '../i18n';
import { firstError } from '../api';

const { t, locale } = useI18n();
const router = useRouter();
const route = useRoute();
const menuOpen = ref(false);
const ready = ref(false);
const passwordForm = ref({ current: '', next: '', confirm: '' });
const passwordError = ref('');
const passwordSaving = ref(false);
const passwordRequired = computed(() => !!auth.user?.must_change_password);

const year = computed(() => currentYear());
const roleLabel = computed(() => auth.user?.roles?.[0] || '');
const initials = computed(() => (auth.user?.name || '?').split(' ').map((p) => p[0]).slice(0, 2).join('').toUpperCase());

const nav = computed(() => [
    { group: t('nav.groups.school'), items: [
        { name: 'dashboard', label: t('nav.dashboard'), icon: 'pi pi-th-large', permission: 'dashboard.view' },
        { name: 'students', label: t('nav.students'), icon: 'pi pi-users', permission: 'students.view' },
        { name: 'guardians', label: t('nav.guardians'), icon: 'pi pi-id-card', permission: 'guardians.view' },
        { name: 'staff', label: t('nav.staff'), icon: 'pi pi-briefcase', permission: 'staff.view' },
        { name: 'attendance', label: t('nav.attendance'), icon: 'pi pi-calendar-clock', permission: 'attendance.view' },
        { name: 'timetable', label: t('nav.timetable'), icon: 'pi pi-calendar', permission: 'timetable.view' },
    ] },
    { group: t('nav.groups.academics'), items: [
        { name: 'gradebook', label: t('nav.gradebook'), icon: 'pi pi-pencil', permission: 'grading.view' },
        { name: 'results', label: t('nav.results'), icon: 'pi pi-chart-bar', permission: 'results.view' },
        { name: 'reportcards', label: t('nav.reportcards'), icon: 'pi pi-file-pdf', permission: 'results.view' },
    ] },
    { group: t('nav.groups.admin'), items: [
        { name: 'finance', label: t('nav.finance'), icon: 'pi pi-wallet', permission: 'finance.view' },
        { name: 'structure', label: t('nav.structure'), icon: 'pi pi-sitemap', permission: 'academics.view' },
        { name: 'settings', label: t('nav.rules'), icon: 'pi pi-sliders-h', permission: 'settings.view' },
        { name: 'users', label: t('nav.users'), icon: 'pi pi-user-plus', permission: 'users.manage' },
        { name: 'audit', label: t('nav.audit'), icon: 'pi pi-history', permission: 'audit.view' },
    ] },
].map((group) => ({ ...group, items: group.items.filter((item) => can(item.permission)) })).filter((group) => group.items.length));

onMounted(async () => {
    if (!auth.user) return;
    try {
        await loadLookups();
    } finally {
        ready.value = true;
    }
});

async function submitPassword() {
    passwordError.value = '';

    if (passwordForm.value.next.length < 8) {
        passwordError.value = 'Le nouveau mot de passe doit contenir au moins 8 caractères.';
        return;
    }

    if (passwordForm.value.next !== passwordForm.value.confirm) {
        passwordError.value = 'Les deux mots de passe ne correspondent pas.';
        return;
    }

    passwordSaving.value = true;
    try {
        await changePassword(passwordForm.value.current, passwordForm.value.next);
        // Reload so every screen fetches again now that the account is unlocked.
        window.location.reload();
    } catch (e) {
        passwordError.value = firstError(e);
    } finally {
        passwordSaving.value = false;
    }
}

async function signOut() {
    await logout();
    resetLookups();
    router.push({ name: 'login' });
}

function changeLocale(code) {
    setLocale(code);
    locale.value = code;
}

function go(name) {
    menuOpen.value = false;
    router.push({ name });
}
</script>

<template>
    <div class="app-shell">
        <aside class="app-sidebar" :class="{ open: menuOpen }">
            <div class="side-brand">
                <div class="side-mark">LH</div>
                <div>
                    <div class="side-name">Lycée Horizon</div>
                    <div class="side-sub">{{ t('appName') }}</div>
                </div>
            </div>

            <div class="year-chip">
                <i class="pi pi-calendar"></i>
                <span>{{ year?.name || '—' }}</span>
                <span class="dot"></span>
                <span class="small">Année en cours</span>
            </div>

            <nav class="side-nav">
                <div v-for="group in nav" :key="group.group" class="nav-group">
                    <div class="nav-group-label">{{ group.group }}</div>
                    <button
                        v-for="item in group.items"
                        :key="item.name"
                        class="nav-item"
                        :class="{ active: route.name === item.name || (item.name === 'students' && route.name === 'student') }"
                        @click="go(item.name)"
                    >
                        <i :class="item.icon"></i>
                        <span>{{ item.label }}</span>
                    </button>
                </div>
            </nav>

            <div class="side-foot">
                <div class="side-user">
                    <div class="avatar">{{ initials }}</div>
                    <div class="grow">
                        <div class="user-name">{{ auth.user?.name }}</div>
                        <div class="user-role">{{ t(`roles.${roleLabel}`) }}</div>
                    </div>
                </div>
                <Button icon="pi pi-sign-out" text rounded severity="secondary" aria-label="Déconnexion" @click="signOut" />
            </div>
        </aside>

        <div v-if="menuOpen" class="side-backdrop" @click="menuOpen = false"></div>

        <div class="app-main">
            <header class="app-topbar">
                <Button icon="pi pi-bars" text rounded class="menu-btn" aria-label="Menu" @click="menuOpen = !menuOpen" />
                <div class="crumb">
                    <span class="muted small">{{ t('appName') }}</span>
                    <i class="pi pi-angle-right small muted"></i>
                    <strong class="small">{{ t(`nav.${route.name === 'student' ? 'students' : (route.name === 'reportcards' ? 'reportcards' : route.name)}`) || '' }}</strong>
                </div>
                <div class="grow"></div>
                <Select :modelValue="locale" :options="locales" optionLabel="label" optionValue="code" size="small" class="lang-select" @update:modelValue="changeLocale" />
                <div class="top-date small muted">{{ new Date().toLocaleDateString('fr-FR', { weekday: 'short', day: '2-digit', month: 'short' }) }}</div>
            </header>

            <main class="app-content">
                <router-view v-if="ready" />
            </main>
        </div>

        <Dialog
            :visible="passwordRequired"
            modal
            :closable="false"
            :closeOnEscape="false"
            :draggable="false"
            header="Nouveau mot de passe requis"
            :style="{ width: '460px' }"
        >
            <Message v-if="passwordError" severity="error" :closable="false" class="mb">{{ passwordError }}</Message>
            <p class="hint" style="margin-top: 0">
                Ce compte a été créé avec un mot de passe temporaire. Choisissez un nouveau mot de passe pour continuer.
            </p>
            <div class="form-grid" style="grid-template-columns: 1fr">
                <div class="field">
                    <label>Mot de passe actuel *</label>
                    <Password v-model="passwordForm.current" :feedback="false" toggleMask inputClass="w-full" />
                </div>
                <div class="field">
                    <label>Nouveau mot de passe *</label>
                    <Password v-model="passwordForm.next" :feedback="false" toggleMask inputClass="w-full" />
                </div>
                <div class="field">
                    <label>Confirmation *</label>
                    <Password v-model="passwordForm.confirm" :feedback="false" toggleMask inputClass="w-full" />
                </div>
            </div>
            <template #footer>
                <Button label="Changer le mot de passe" icon="pi pi-lock" :loading="passwordSaving" @click="submitPassword" />
            </template>
        </Dialog>
    </div>
</template>

<style scoped>
.app-shell { display: flex; min-height: 100vh; }
.mb { margin-bottom: 12px; }
.app-sidebar {
    width: 254px; flex: 0 0 254px; background: var(--sidebar); color: #d5e4db;
    display: flex; flex-direction: column; padding: 18px 14px; position: sticky; top: 0; height: 100vh; z-index: 30;
}
.side-brand { display: flex; gap: 10px; align-items: center; padding: 4px 8px 18px; }
.side-mark { width: 36px; height: 36px; border-radius: 10px; background: #e9f3ed; color: var(--brand); display: grid; place-items: center; font-weight: 800; font-size: 13px; }
.side-name { font-weight: 700; font-size: 14px; color: #fff; }
.side-sub { font-size: 11px; color: #8fae9d; }
.year-chip { margin: 0 8px 18px; padding: 8px 10px; border-radius: 9px; background: rgba(255,255,255,.06); display: flex; align-items: center; gap: 7px; font-size: 12px; color: #d9e9e0; }
.year-chip .pi { font-size: 12px; color: #9dc7af; }
.year-chip .dot { width: 5px; height: 5px; border-radius: 50%; background: #60c48e; }
.side-nav { flex: 1; overflow-y: auto; padding: 0 4px; }
.nav-group { margin-bottom: 14px; }
.nav-group-label { font-size: 10px; letter-spacing: .12em; text-transform: uppercase; color: #7b9688; padding: 6px 10px; }
.nav-item {
    width: 100%; display: flex; gap: 10px; align-items: center; background: transparent; border: none;
    color: #c9dbd0; padding: 9px 11px; border-radius: 9px; font-size: 13px; cursor: pointer; text-align: left;
    font-family: inherit;
}
.nav-item i { font-size: 13px; color: #8fae9d; width: 16px; }
.nav-item:hover { background: rgba(255,255,255,.06); color: #fff; }
.nav-item.active { background: #e9f3ed; color: #113826; font-weight: 600; }
.nav-item.active i { color: #113826; }
.side-foot { border-top: 1px solid rgba(255,255,255,.08); padding-top: 12px; display: flex; align-items: center; gap: 6px; }
.side-user { display: flex; gap: 9px; align-items: center; flex: 1; min-width: 0; padding: 4px 6px; }
.avatar { width: 32px; height: 32px; border-radius: 50%; background: #20402f; color: #cfe8da; display: grid; place-items: center; font-size: 11px; font-weight: 700; }
.user-name { font-size: 12.5px; color: #eef7f2; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.user-role { font-size: 10.5px; color: #8fae9d; }
.app-main { flex: 1; min-width: 0; display: flex; flex-direction: column; }
.app-topbar {
    height: 58px; display: flex; align-items: center; gap: 12px; padding: 0 24px;
    background: rgba(255,255,255,.86); backdrop-filter: blur(8px); border-bottom: 1px solid var(--line);
    position: sticky; top: 0; z-index: 20;
}
.crumb { display: flex; align-items: center; gap: 8px; }
.menu-btn { display: none; }
.app-content { flex: 1; }
.lang-select { min-width: 118px; }
.side-backdrop { display: none; }
@media (max-width: 1000px) {
    .app-sidebar { position: fixed; left: 0; top: 0; transform: translateX(-100%); transition: transform .2s ease; }
    .app-sidebar.open { transform: translateX(0); }
    .side-backdrop { display: block; position: fixed; inset: 0; background: rgba(0,0,0,.35); z-index: 25; }
    .menu-btn { display: inline-flex; }
    .app-topbar { padding: 0 14px; }
    .top-date { display: none; }
}
</style>
