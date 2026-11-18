<script setup>
import { computed, onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import DataTable from 'primevue/datatable';
import Column from 'primevue/column';
import Button from 'primevue/button';
import Dialog from 'primevue/dialog';
import InputText from 'primevue/inputtext';
import Select from 'primevue/select';
import DatePicker from 'primevue/datepicker';
import FileUpload from 'primevue/fileupload';
import Message from 'primevue/message';
import { useToast } from 'primevue/usetoast';
import { api, firstError } from '../api';
import { lookups, className } from '../stores/lookups';
import { can } from '../stores/auth';
import { date, statusLabels } from '../lib/format';

const router = useRouter();
const toast = useToast();

const rows = ref([]);
const total = ref(0);
const loading = ref(false);
const q = ref('');
const classFilter = ref(null);
const statusFilter = ref(null);
const page = ref(1);

const dialog = ref(false);
const importDialog = ref(false);
const saving = ref(false);
const error = ref('');
const file = ref(null);
const importResult = ref(null);
const form = ref({});

const statuses = [
    { label: 'Inscrit', value: 'enrolled' },
    { label: 'Candidat', value: 'applicant' },
    { label: 'Suspendu', value: 'suspended' },
    { label: 'Retiré', value: 'withdrawn' },
    { label: 'Transféré', value: 'transferred_out' },
    { label: 'Diplômé', value: 'graduated' },
];

const classOptions = computed(() => lookups.classes.map((c) => ({ label: className(c.id), value: c.id })));

async function load(reset = false) {
    if (reset) page.value = 1;
    loading.value = true;
    try {
        const response = await api.get('/students', {
            q: q.value || undefined,
            class_id: classFilter.value || undefined,
            status: statusFilter.value || undefined,
            page: page.value,
            per_page: 25,
        });
        rows.value = response.data;
        total.value = response.total;
    } catch (e) {
        toast.add({ severity: 'error', summary: 'Erreur', detail: firstError(e), life: 4000 });
    } finally {
        loading.value = false;
    }
}

function openNew() {
    error.value = '';
    form.value = { status: 'enrolled', gender: 'female', admission_date: new Date() };
    dialog.value = true;
}

async function save() {
    saving.value = true;
    error.value = '';
    try {
        const payload = { ...form.value };
        if (payload.admission_date instanceof Date) payload.admission_date = payload.admission_date.toISOString().slice(0, 10);
        if (payload.date_of_birth instanceof Date) payload.date_of_birth = payload.date_of_birth.toISOString().slice(0, 10);
        await api.post('/students', payload);
        toast.add({ severity: 'success', summary: 'Élève enregistré', life: 2500 });
        dialog.value = false;
        await load(true);
    } catch (e) {
        error.value = firstError(e);
    } finally {
        saving.value = false;
    }
}

async function doImport() {
    if (!file.value) return;
    saving.value = true;
    importResult.value = null;
    error.value = '';
    try {
        const data = new FormData();
        data.append('file', file.value);
        importResult.value = await api.upload('/students/import', data);
        toast.add({ severity: 'success', summary: importResult.value.imported + ' élève(s) importé(s)', life: 3000 });
        await load(true);
    } catch (e) {
        error.value = firstError(e);
        if (e.status === 422) importResult.value = e.payload || null;
    } finally {
        saving.value = false;
    }
}

function onFile(event) {
    file.value = event.files[0];
}

onMounted(() => load());
</script>

<template>
    <div class="page">
        <div class="page-head">
            <div>
                <h1>Élèves</h1>
                <p>{{ total }} dossier(s) · identifiants uniques, inscriptions et liens tuteurs vérifiés.</p>
            </div>
            <div class="flex gap-2" v-if="can('students.manage')">
                <Button label="Importer" icon="pi pi-upload" outlined size="small" @click="importDialog = true" />
                <Button label="Nouvel élève" icon="pi pi-plus" size="small" @click="openNew" />
            </div>
        </div>

        <div class="card">
            <div class="toolbar">
                <InputText v-model="q" placeholder="Nom, numéro, téléphone…" style="min-width: 260px" @keyup.enter="load(true)" />
                <Select v-model="classFilter" :options="classOptions" optionLabel="label" optionValue="value" placeholder="Classe" showClear style="min-width: 160px" @change="load(true)" />
                <Select v-model="statusFilter" :options="statuses" optionLabel="label" optionValue="value" placeholder="Statut" showClear style="min-width: 150px" @change="load(true)" />
                <Button icon="pi pi-search" text rounded aria-label="Rechercher" @click="load(true)" />
            </div>

            <DataTable :value="rows" :loading="loading" size="small" stripedRows scrollable :paginator="false" emptyMessage="Aucun élève">
                <Column field="student_number" header="N° élève" style="width: 150px" />
                <Column header="Nom">
                    <template #body="{ data }">
                        <div class="flex items-center gap-2">
                            <div class="avatar-sm">{{ (data.first_name?.[0] || '') + (data.last_name?.[0] || '') }}</div>
                            <div>
                                <div><strong>{{ data.last_name }} {{ data.first_name }}</strong></div>
                                <div class="hint">{{ data.date_of_birth ? date(data.date_of_birth) : '' }}</div>
                            </div>
                        </div>
                    </template>
                </Column>
                <Column header="Classe" style="width: 130px">
                    <template #body="{ data }">
                        <span v-if="data.class_id">{{ data.level_code }} {{ data.class_name }}</span>
                        <span v-else class="muted">Non inscrit</span>
                    </template>
                </Column>
                <Column field="phone" header="Téléphone" style="width: 140px" />
                <Column header="Statut" style="width: 120px">
                    <template #body="{ data }">
                        <span class="badge" :class="data.status === 'enrolled' ? '' : 'gray'">{{ statusLabels[data.status] || data.status }}</span>
                    </template>
                </Column>
                <Column header="" style="width: 90px">
                    <template #body="{ data }">
                        <Button icon="pi pi-arrow-right" text rounded size="small" @click="router.push({ name: 'student', params: { id: data.id } })" />
                    </template>
                </Column>
            </DataTable>

            <div class="pager">
                <Button icon="pi pi-chevron-left" text size="small" :disabled="page <= 1" @click="page--; load()" />
                <span class="small muted">Page {{ page }}</span>
                <Button icon="pi pi-chevron-right" text size="small" :disabled="page * 25 >= total" @click="page++; load()" />
            </div>
        </div>

        <Dialog v-model:visible="dialog" modal header="Nouvel élève" :style="{ width: '720px' }">
            <Message v-if="error" severity="error" :closable="false" class="mb">{{ error }}</Message>
            <div class="form-grid">
                <div class="field"><label>Prénom *</label><InputText v-model="form.first_name" /></div>
                <div class="field"><label>Nom *</label><InputText v-model="form.last_name" /></div>
                <div class="field"><label>Date de naissance *</label><DatePicker v-model="form.date_of_birth" dateFormat="dd/mm/yy" showIcon fluid /></div>
                <div class="field"><label>Genre</label>
                    <Select v-model="form.gender" :options="[{label:'Féminin',value:'female'},{label:'Masculin',value:'male'}]" optionLabel="label" optionValue="value" fluid />
                </div>
                <div class="field"><label>Classe</label>
                    <Select v-model="form.class_id" :options="classOptions" optionLabel="label" optionValue="value" filter showClear fluid placeholder="Affecter plus tard" />
                </div>
                <div class="field"><label>Téléphone</label><InputText v-model="form.phone" /></div>
                <div class="field"><label>Email</label><InputText v-model="form.email" type="email" /></div>
                <div class="field"><label>École précédente</label><InputText v-model="form.previous_school" /></div>
                <div class="field span-2"><label>Adresse</label><InputText v-model="form.address" /></div>
                <div class="field"><label>Date d’admission</label><DatePicker v-model="form.admission_date" dateFormat="dd/mm/yy" showIcon fluid /></div>
                <div class="field"><label>Statut</label><Select v-model="form.status" :options="statuses" optionLabel="label" optionValue="value" fluid /></div>
                <div class="field span-2">
                    <label>Catégorie de frais</label>
                    <Select v-model="form.student_category_id" :options="lookups.studentCategories" optionLabel="name" optionValue="id" showClear fluid />
                    <span class="hint">Le numéro d’élève suit le format défini dans les paramètres.</span>
                </div>
            </div>
            <template #footer>
                <Button label="Annuler" text severity="secondary" @click="dialog = false" />
                <Button label="Enregistrer" icon="pi pi-check" :loading="saving" @click="save" />
            </template>
        </Dialog>

        <Dialog v-model:visible="importDialog" modal header="Importer des élèves (CSV)" :style="{ width: '620px' }">
            <p class="small muted">Colonnes attendues : <span class="mono">first_name,last_name,date_of_birth,gender,class_id</span>. Les lignes invalides sont rejetées avec un rapport.</p>
            <Message v-if="error" severity="error" :closable="false">{{ error }}</Message>
            <FileUpload mode="basic" accept=".csv,text/csv" :auto="false" chooseLabel="Choisir un fichier" @select="onFile" />
            <div v-if="importResult?.errors?.length" class="import-errors">
                <div v-for="(err, index) in importResult.errors.slice(0, 8)" :key="index" class="small">
                    Ligne {{ err.row_number }} — {{ err.message }}
                </div>
            </div>
            <template #footer>
                <Button label="Fermer" text severity="secondary" @click="importDialog = false" />
                <Button label="Importer" icon="pi pi-upload" :loading="saving" @click="doImport" />
            </template>
        </Dialog>
    </div>
</template>

<style scoped>
.avatar-sm { width: 30px; height: 30px; border-radius: 50%; background: var(--brand-soft); color: var(--brand); display: grid; place-items: center; font-size: 10.5px; font-weight: 700; }
.pager { display: flex; justify-content: flex-end; align-items: center; gap: 8px; padding-top: 10px; }
.mb { margin-bottom: 12px; }
.import-errors { margin-top: 12px; padding: 10px; background: #fdf6f5; border-radius: 8px; color: var(--danger); }
</style>
