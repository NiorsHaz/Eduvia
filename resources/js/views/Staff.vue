<script setup>
import { computed, onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import { useI18n } from 'vue-i18n';
import DataTable from 'primevue/datatable';
import Column from 'primevue/column';
import Button from 'primevue/button';
import Dialog from 'primevue/dialog';
import InputText from 'primevue/inputtext';
import Password from 'primevue/password';
import Select from 'primevue/select';
import MultiSelect from 'primevue/multiselect';
import Message from 'primevue/message';
import { useToast } from 'primevue/usetoast';
import { api, firstError } from '../api';
import { auth, can } from '../stores/auth';

const { t } = useI18n();
const toast = useToast();
const router = useRouter();

const rows = ref([]);
const roles = ref([]);
const loading = ref(false);
const search = ref('');
const typeFilter = ref(null);
const accountFilter = ref(null);

const dialog = ref(false);
const saving = ref(false);
const error = ref('');
const target = ref(null);
const form = ref({});

const canManageAccounts = computed(() => can('users.manage'));

const typeOptions = [
    { label: 'Enseignant', value: 'teacher' },
    { label: 'Administratif', value: 'administrative' },
    { label: 'Finance', value: 'finance' },
    { label: 'Bibliothèque', value: 'library' },
    { label: 'Support', value: 'support' },
    { label: 'Autre', value: 'other' },
];

const accountOptions = [
    { label: 'Avec compte', value: 'linked' },
    { label: 'Sans compte', value: 'none' },
];

const typeLabels = Object.fromEntries(typeOptions.map((option) => [option.value, option.label]));

const roleOptions = computed(() => roles.value
    .filter((role) => auth.user?.roles?.includes('super_admin') || role.name !== 'super_admin')
    .map((role) => ({ label: t(`roles.${role.name}`), value: role.name })));

const teacherCount = computed(() => rows.value.filter((row) => row.staff_type === 'teacher').length);
const missingAccounts = computed(() => rows.value.filter((row) => !row.user).length);

async function load() {
    loading.value = true;
    try {
        const response = await api.get('/staff', {
            per_page: 300,
            q: search.value || undefined,
            staff_type: typeFilter.value || undefined,
            account: accountFilter.value || undefined,
        });
        rows.value = Array.isArray(response) ? response : response.data || [];
    } catch (e) {
        toast.add({ severity: 'error', summary: 'Erreur', detail: firstError(e), life: 4000 });
    } finally {
        loading.value = false;
    }
}

async function openAccount(member) {
    error.value = '';
    target.value = member;

    if (!roles.value.length) {
        try {
            roles.value = await api.get('/roles');
        } catch (e) {
            toast.add({ severity: 'error', summary: 'Erreur', detail: firstError(e), life: 4000 });
            return;
        }
    }

    const preferred = member.staff_type === 'finance' ? 'finance'
        : member.staff_type === 'teacher' ? 'teacher' : 'admin_staff';

    form.value = {
        name: `${member.first_name} ${member.last_name}`.trim(),
        email: member.email || null,
        phone: member.phone || null,
        password: null,
        roles: [preferred],
    };
    dialog.value = true;
}

async function save() {
    saving.value = true;
    error.value = '';
    try {
        await api.post('/users', {
            ...form.value,
            staff_id: target.value.id,
            status: 'active',
            locale: 'fr',
        });
        toast.add({ severity: 'success', summary: 'Compte créé', detail: `Connexion possible pour ${form.value.name}.`, life: 3000 });
        dialog.value = false;
        await load();
    } catch (e) {
        error.value = firstError(e);
    } finally {
        saving.value = false;
    }
}

onMounted(load);
</script>

<template>
    <div class="page">
        <div class="page-head">
            <div>
                <h1>Personnel &amp; accès</h1>
                <p>
                    {{ teacherCount }} enseignant(s) affiché(s) · {{ missingAccounts }} sans compte de connexion.
                    Chaque membre du personnel peut recevoir un identifiant pour accéder à l’application.
                </p>
            </div>
            <Button v-if="can('staff.manage')" label="Gérer les fiches" icon="pi pi-sitemap" size="small" severity="secondary" @click="router.push({ name: 'structure' })" />
        </div>

        <div class="card">
            <div class="toolbar">
                <InputText v-model="search" placeholder="Nom, matricule, email, téléphone…" class="w-search" @keyup.enter="load" />
                <Button icon="pi pi-search" text rounded aria-label="Rechercher" @click="load" />
                <Select v-model="typeFilter" :options="typeOptions" optionLabel="label" optionValue="value" placeholder="Tous les types" showClear class="w-filter" @update:modelValue="load" />
                <Select v-model="accountFilter" :options="accountOptions" optionLabel="label" optionValue="value" placeholder="Tous les comptes" showClear class="w-filter" @update:modelValue="load" />
                <div class="grow"></div>
                <Button v-if="canManageAccounts" label="Tous les comptes" icon="pi pi-users" size="small" text @click="router.push({ name: 'users' })" />
            </div>

            <DataTable :value="rows" :loading="loading" dataKey="id" size="small" stripedRows emptyMessage="Aucun membre du personnel">
                <Column field="employee_number" header="Matricule" style="width: 110px" />
                <Column header="Nom">
                    <template #body="{ data }">
                        <strong>{{ data.first_name }} {{ data.last_name }}</strong>
                        <div class="hint">{{ data.position }}</div>
                    </template>
                </Column>
                <Column header="Type" style="width: 130px">
                    <template #body="{ data }">
                        <span class="badge gray">{{ typeLabels[data.staff_type] || data.staff_type }}</span>
                    </template>
                </Column>
                <Column header="Département">
                    <template #body="{ data }">{{ data.department?.name || '—' }}</template>
                </Column>
                <Column header="Contact">
                    <template #body="{ data }">
                        {{ data.phone || '—' }}
                        <div class="hint">{{ data.email || '' }}</div>
                    </template>
                </Column>
                <Column header="Compte de connexion">
                    <template #body="{ data }">
                        <template v-if="data.user">
                            <div>{{ data.user.email || data.user.phone }}</div>
                            <div class="flex wrap gap-2" style="margin-top: 4px">
                                <span class="badge" :class="data.user.status === 'active' ? '' : 'danger'">
                                    {{ data.user.status === 'active' ? 'Actif' : 'Inactif' }}
                                </span>
                                <span v-for="role in data.user.roles" :key="role.id" class="badge gray">{{ t(`roles.${role.name}`) }}</span>
                            </div>
                        </template>
                        <span v-else class="badge warn">Aucun compte</span>
                    </template>
                </Column>
                <Column header="" style="width: 170px">
                    <template #body="{ data }">
                        <Button v-if="!data.user && canManageAccounts" label="Créer un compte" icon="pi pi-user-plus" size="small" text @click="openAccount(data)" />
                        <Button v-else-if="data.user && canManageAccounts" label="Gérer" icon="pi pi-cog" size="small" text @click="router.push({ name: 'users' })" />
                    </template>
                </Column>
            </DataTable>
        </div>

        <Dialog v-model:visible="dialog" modal :header="'Créer un compte — ' + (target ? target.first_name + ' ' + target.last_name : '')" :style="{ width: '560px' }">
            <Message v-if="error" severity="error" :closable="false" class="mb">{{ error }}</Message>
            <div class="form-grid">
                <div class="field span-2">
                    <label>Nom affiché *</label>
                    <InputText v-model="form.name" />
                </div>
                <div class="field">
                    <label>Email</label>
                    <InputText v-model="form.email" type="email" />
                </div>
                <div class="field">
                    <label>Téléphone</label>
                    <InputText v-model="form.phone" />
                </div>
                <div class="field span-2">
                    <label>Mot de passe *</label>
                    <Password v-model="form.password" :feedback="false" toggleMask inputClass="w-full" />
                </div>
                <div class="field span-2">
                    <label>Rôles</label>
                    <MultiSelect v-model="form.roles" :options="roleOptions" optionLabel="label" optionValue="value" display="chip" fluid />
                </div>
                <div class="field span-2">
                    <span class="hint">Un email ou un téléphone est obligatoire pour se connecter. Le mot de passe doit contenir au moins 8 caractères ; la personne devra le changer à la première connexion.</span>
                </div>
            </div>
            <template #footer>
                <Button label="Annuler" text severity="secondary" @click="dialog = false" />
                <Button label="Créer le compte" icon="pi pi-check" :loading="saving" @click="save" />
            </template>
        </Dialog>
    </div>
</template>

<style scoped>
.mb { margin-bottom: 12px; }
.w-search { min-width: 260px; }
.w-filter { min-width: 170px; }
</style>
