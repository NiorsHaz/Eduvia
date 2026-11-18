<script setup>
import { computed, onMounted, ref } from 'vue';
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
import { auth } from '../stores/auth';
import { dateTime, statusLabels } from '../lib/format';

const toast = useToast();
const users = ref([]);
const roles = ref([]);
const loading = ref(false);
const dialog = ref(false);
const historyDialog = ref(false);
const history = ref([]);
const selectedUser = ref(null);
const editing = ref(null);
const saving = ref(false);
const error = ref('');
const form = ref({});

const roleOptions = computed(() => roles.value
    .filter((r) => auth.user?.roles?.includes('super_admin') || r.name !== 'super_admin')
    .map((r) => ({ label: r.name, value: r.name })));

const isSuperAdmin = computed(() => auth.user?.roles?.includes('super_admin') || false);
const editingSelf = computed(() => !!editing.value && editing.value.id === auth.user?.id);
const canResetPassword = computed(() => !editing.value || editingSelf.value || isSuperAdmin.value);

async function load() {
    loading.value = true;
    try {
        const [userList, roleList] = await Promise.all([api.get('/users'), api.get('/roles')]);
        users.value = userList.data;
        roles.value = roleList;
    } catch (e) {
        toast.add({ severity: 'error', summary: 'Erreur', detail: firstError(e), life: 4000 });
    } finally {
        loading.value = false;
    }
}

function openNew() {
    editing.value = null;
    error.value = '';
    form.value = { status: 'active', locale: 'fr', roles: [] };
    dialog.value = true;
}

function openEdit(user) {
    editing.value = user;
    error.value = '';
    form.value = { name: user.name, email: user.email, phone: user.phone, status: user.status, locale: user.locale, roles: (user.roles || []).map((r) => r.name), password: null };
    dialog.value = true;
}

async function save() {
    saving.value = true;
    error.value = '';
    try {
        const payload = { ...form.value };
        if (!payload.password) delete payload.password;
        if (editing.value) await api.put(`/users/${editing.value.id}`, payload);
        else await api.post('/users', payload);
        toast.add({ severity: 'success', summary: 'Utilisateur enregistré', life: 2000 });
        dialog.value = false;
        await load();
    } catch (e) {
        error.value = firstError(e);
    } finally {
        saving.value = false;
    }
}

async function deactivate(user) {
    if (!window.confirm(`Désactiver le compte de ${user.name} ?`)) return;
    try {
        await api.delete(`/users/${user.id}`);
        toast.add({ severity: 'success', summary: 'Compte désactivé', life: 2000 });
        await load();
    } catch (e) {
        toast.add({ severity: 'error', summary: 'Erreur', detail: firstError(e), life: 4000 });
    }
}

async function openHistory(user) {
    selectedUser.value = user;
    history.value = await api.get(`/users/${user.id}/login-history`);
    historyDialog.value = true;
}

onMounted(load);
</script>

<template>
    <div class="page">
        <div class="page-head">
            <div>
                <h1>Utilisateurs & rôles</h1>
                <p>Autorisation côté serveur par rôle et permission. Les comptes désactivés perdent immédiatement leurs jetons.</p>
            </div>
            <Button label="Nouvel utilisateur" icon="pi pi-plus" size="small" @click="openNew" />
        </div>

        <div class="card">
            <DataTable :value="users" :loading="loading" size="small" stripedRows emptyMessage="Aucun utilisateur">
                <Column field="name" header="Nom" />
                <Column field="email" header="Email" />
                <Column field="phone" header="Téléphone" />
                <Column header="Rôles">
                    <template #body="{ data }">
                        <span v-for="role in data.roles" :key="role.id" class="badge gray" style="margin-right: 4px">{{ role.name }}</span>
                    </template>
                </Column>
                <Column header="Statut"><template #body="{ data }">{{ statusLabels[data.status] || data.status }}</template></Column>
                <Column header="Dernière connexion"><template #body="{ data }">{{ data.last_login_at ? dateTime(data.last_login_at) : '—' }}</template></Column>
                <Column header="" style="width: 210px">
                    <template #body="{ data }">
                        <Button label="Journal" size="small" text @click="openHistory(data)" />
                        <Button label="Modifier" size="small" text @click="openEdit(data)" />
                        <Button icon="pi pi-ban" size="small" text severity="danger" aria-label="Désactiver" @click="deactivate(data)" />
                    </template>
                </Column>
            </DataTable>
        </div>

        <Dialog v-model:visible="dialog" modal :header="editing ? 'Modifier l’utilisateur' : 'Nouvel utilisateur'" :style="{ width: '620px' }">
            <Message v-if="error" severity="error" :closable="false" class="mb">{{ error }}</Message>
            <div class="form-grid">
                <div class="field span-2"><label>Nom *</label><InputText v-model="form.name" /></div>
                <div class="field"><label>Email</label><InputText v-model="form.email" type="email" /></div>
                <div class="field"><label>Téléphone</label><InputText v-model="form.phone" /></div>
                <div v-if="canResetPassword" class="field span-2"><label>Mot de passe {{ editing ? '(laisser vide pour conserver)' : '*' }}</label>
                    <Password v-model="form.password" :feedback="false" toggleMask inputClass="w-full" />
                    <span v-if="editing && !editingSelf" class="hint">Réinitialisation : la personne devra choisir un nouveau mot de passe à sa prochaine connexion.</span>
                </div>
                <div v-else class="field span-2">
                    <span class="hint">Seul un super administrateur peut réinitialiser le mot de passe d’un autre compte.</span>
                </div>
                <div class="field"><label>Rôles</label>
                    <MultiSelect v-model="form.roles" :options="roleOptions" optionLabel="label" optionValue="value" display="chip" fluid />
                </div>
                <div class="field"><label>Langue</label>
                    <Select v-model="form.locale" :options="[{label:'Français',value:'fr'},{label:'Malagasy',value:'mg'},{label:'English',value:'en'}]" optionLabel="label" optionValue="value" fluid />
                </div>
                <div class="field"><label>Statut</label>
                    <Select v-model="form.status" :disabled="editingSelf" :options="[{label:'Actif',value:'active'},{label:'Suspendu',value:'suspended'},{label:'Désactivé',value:'deactivated'}]" optionLabel="label" optionValue="value" fluid />
                    <span v-if="editingSelf" class="hint">Vous ne pouvez pas désactiver votre propre compte.</span>
                </div>
            </div>
            <template #footer>
                <Button label="Annuler" text severity="secondary" @click="dialog = false" />
                <Button label="Enregistrer" icon="pi pi-check" :loading="saving" @click="save" />
            </template>
        </Dialog>

        <Dialog v-model:visible="historyDialog" modal :header="'Connexions — ' + (selectedUser?.name || '')" :style="{ width: '620px' }">
            <DataTable :value="history" size="small" emptyMessage="Aucune connexion">
                <Column field="identifier" header="Identifiant" />
                <Column header="Résultat"><template #body="{ data }"><span class="badge" :class="data.success ? '' : 'danger'">{{ data.success ? 'Succès' : 'Échec' }}</span></template></Column>
                <Column field="ip_address" header="IP" />
                <Column header="Date"><template #body="{ data }">{{ dateTime(data.created_at) }}</template></Column>
            </DataTable>
        </Dialog>
    </div>
</template>

<style scoped>
.mb { margin-bottom: 12px; }
</style>
