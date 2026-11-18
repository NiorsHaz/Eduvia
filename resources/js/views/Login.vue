<script setup>
import { ref } from 'vue';
import { useRouter } from 'vue-router';
import { useI18n } from 'vue-i18n';
import Button from 'primevue/button';
import InputText from 'primevue/inputtext';
import Password from 'primevue/password';
import Message from 'primevue/message';
import { login } from '../stores/auth';
import { loadLookups, resetLookups } from '../stores/lookups';
import { firstError } from '../api';
import { setLocale, locales } from '../i18n';

const router = useRouter();
const { t, locale } = useI18n();
const identifier = ref('admin@school.mg');
const password = ref('Password123!');
const loading = ref(false);
const error = ref('');
const schoolName = ref('Lycée Horizon');

async function submit() {
    error.value = '';
    loading.value = true;
    try {
        resetLookups();
        await login(identifier.value, password.value);
        await loadLookups();
        router.push({ name: 'dashboard' });
    } catch (e) {
        error.value = firstError(e);
    } finally {
        loading.value = false;
    }
}

function changeLocale(code) {
    setLocale(code);
    locale.value = code;
}
</script>

<template>
    <div class="login-shell">
        <div class="login-panel">
            <div class="brand">
                <div class="brand-mark">LH</div>
                <div>
                    <div class="brand-name">{{ schoolName }}</div>
                    <div class="brand-sub">{{ t('appName') }}</div>
                </div>
            </div>

            <h1>Connexion à l’espace d’administration</h1>
            <p class="muted">Gestion des élèves, de la scolarité et des finances.</p>

            <Message v-if="error" severity="error" :closable="false" class="mb">{{ error }}</Message>

            <form @submit.prevent="submit" class="form">
                <div class="field">
                    <label>Email ou téléphone</label>
                    <InputText v-model="identifier" autocomplete="username" placeholder="admin@school.mg" required />
                </div>
                <div class="field">
                    <label>Mot de passe</label>
                    <Password v-model="password" :feedback="false" toggleMask inputClass="w-full" required />
                </div>
                <Button type="submit" label="Se connecter" icon="pi pi-arrow-right" iconPos="right" :loading="loading" class="submit" />
            </form>

            <div class="login-foot">
                <div class="demo">
                    <div class="small muted">Comptes de démonstration — mot de passe <span class="mono">Password123!</span></div>
                    <div class="small"><strong>admin@school.mg</strong> · directeur, enseignant, comptable et parent disponibles</div>
                </div>
                <div class="langs">
                    <button v-for="l in locales" :key="l.code" class="lang" :class="{ on: locale === l.code }" type="button" @click="changeLocale(l.code)">
                        {{ l.label }}
                    </button>
                </div>
            </div>
        </div>

        <aside class="login-aside">
            <div class="aside-inner">
                <div class="eyebrow">Blueprint v1.1 · 2026-2027</div>
                <h2>Chaque règle de l’école est configurable.</h2>
                <p>Barèmes, promotion, frais, pénalités et numérotation des reçus sont des données, pas du code. Les résultats validés conservent la politique qui les a produits.</p>
                <ul>
                    <li><i class="pi pi-check-circle"></i> Dossiers élèves, tuteurs vérifiés et inscriptions</li>
                    <li><i class="pi pi-check-circle"></i> Notes, délibérations et bulletins versionnés</li>
                    <li><i class="pi pi-check-circle"></i> Caisse, reçus sans rupture et reversements contrôlés</li>
                </ul>
            </div>
        </aside>
    </div>
</template>

<style scoped>
.login-shell { min-height: 100vh; display: grid; grid-template-columns: minmax(0, 520px) minmax(0, 1fr); }
.login-panel { padding: 56px 64px; display: flex; flex-direction: column; background: #fff; }
.brand { display: flex; gap: 12px; align-items: center; margin-bottom: 48px; }
.brand-mark { width: 42px; height: 42px; border-radius: 11px; background: var(--brand); color: #fff; display: grid; place-items: center; font-weight: 700; letter-spacing: .02em; }
.brand-name { font-weight: 700; font-size: 15px; }
.brand-sub { font-size: 12px; color: var(--muted); }
h1 { font-size: 26px; margin-bottom: 8px; }
.form { display: flex; flex-direction: column; gap: 16px; margin-top: 22px; }
.submit { justify-content: center; }
.mb { margin-bottom: 14px; }
.login-foot { margin-top: auto; display: flex; flex-direction: column; gap: 12px; padding-top: 32px; }
.demo { border: 1px dashed var(--line); border-radius: 10px; padding: 10px 12px; background: #fafcfa; }
.langs { display: flex; gap: 6px; }
.lang { border: 1px solid var(--line); background: #fff; border-radius: 999px; padding: 5px 10px; font-size: 12px; color: var(--muted); cursor: pointer; }
.lang.on { border-color: var(--brand); color: var(--brand); background: var(--brand-soft); font-weight: 600; }
.login-aside {
    background: radial-gradient(1200px 600px at 80% 0%, #1e4a35 0%, #10251b 60%);
    color: #eaf3ee; display: flex; align-items: center; padding: 64px;
}
.aside-inner { max-width: 520px; }
.eyebrow { text-transform: uppercase; letter-spacing: .14em; font-size: 11px; color: #9fc4af; margin-bottom: 18px; }
.login-aside h2 { font-size: 34px; line-height: 1.2; margin-bottom: 16px; }
.login-aside p { color: #c4d8cc; line-height: 1.65; }
.login-aside ul { list-style: none; padding: 0; margin: 28px 0 0; display: flex; flex-direction: column; gap: 12px; }
.login-aside li { display: flex; gap: 10px; align-items: center; color: #dbe9e1; font-size: 14px; }
.login-aside .pi { color: #86c3a1; }
@media (max-width: 900px) { .login-shell { grid-template-columns: 1fr; } .login-aside { display: none; } .login-panel { padding: 32px 24px; } }
</style>
