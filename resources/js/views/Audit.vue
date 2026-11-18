<script setup>
import { onMounted, ref } from 'vue';
import DataTable from 'primevue/datatable';
import Column from 'primevue/column';
import InputText from 'primevue/inputtext';
import Select from 'primevue/select';
import DatePicker from 'primevue/datepicker';
import Button from 'primevue/button';
import { api, firstError } from '../api';
import { dateTime } from '../lib/format';
import { useToast } from 'primevue/usetoast';

const toast = useToast();
const rows = ref([]);
const logs = ref([]);
const loading = ref(false);
const q = ref('');
const logName = ref(null);
const from = ref(null);
const to = ref(null);
const page = ref(1);
const total = ref(0);

function iso(value) {
    return value instanceof Date ? value.toISOString().slice(0, 10) : undefined;
}

async function load(reset = false) {
    if (reset) page.value = 1;
    loading.value = true;
    try {
        const response = await api.get('/audit', {
            q: q.value || undefined,
            log_name: logName.value || undefined,
            from: iso(from.value),
            to: iso(to.value),
            page: page.value,
        });
        rows.value = response.data;
        total.value = response.total;
    } catch (e) {
        toast.add({ severity: 'error', summary: 'Erreur', detail: firstError(e), life: 4000 });
    } finally {
        loading.value = false;
    }
}

onMounted(async () => {
    try {
        logs.value = await api.get('/audit/logs');
    } catch (e) { /* optional */ }
    load();
});
</script>

<template>
    <div class="page">
        <div class="page-head">
            <div>
                <h1>Journal d’audit</h1>
                <p>Notes, présences, paiements, permissions et dossiers sensibles sont tracés avec l’auteur et l’horodatage.</p>
            </div>
        </div>

        <div class="card">
            <div class="toolbar">
                <InputText v-model="q" placeholder="Rechercher une action…" style="min-width: 240px" @keyup.enter="load(true)" />
                <Select v-model="logName" :options="logs" placeholder="Tous les journaux" showClear style="min-width: 180px" @change="load(true)" />
                <DatePicker v-model="from" dateFormat="dd/mm/yy" placeholder="Du" showIcon />
                <DatePicker v-model="to" dateFormat="dd/mm/yy" placeholder="Au" showIcon />
                <Button icon="pi pi-search" text rounded @click="load(true)" />
            </div>

            <DataTable :value="rows" :loading="loading" size="small" stripedRows emptyMessage="Aucune activité">
                <Column field="log_name" header="Journal" style="width: 130px" />
                <Column field="description" header="Action" />
                <Column header="Auteur"><template #body="{ data }">{{ data.causer?.name || 'Système' }}</template></Column>
                <Column header="Détails">
                    <template #body="{ data }">
                        <span class="hint mono">{{ data.properties ? JSON.stringify(data.properties).slice(0, 90) : '—' }}</span>
                    </template>
                </Column>
                <Column header="Date"><template #body="{ data }">{{ dateTime(data.created_at) }}</template></Column>
            </DataTable>

            <div class="pager">
                <Button icon="pi pi-chevron-left" text size="small" :disabled="page <= 1" @click="page--; load()" />
                <span class="small muted">Page {{ page }} · {{ total }} entrées</span>
                <Button icon="pi pi-chevron-right" text size="small" :disabled="page * 50 >= total" @click="page++; load()" />
            </div>
        </div>
    </div>
</template>

<style scoped>
.pager { display: flex; justify-content: flex-end; align-items: center; gap: 8px; padding-top: 10px; }
</style>
