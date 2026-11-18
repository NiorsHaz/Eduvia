<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import Select from 'primevue/select';
import Button from 'primevue/button';
import DataTable from 'primevue/datatable';
import Column from 'primevue/column';
import Message from 'primevue/message';
import { useToast } from 'primevue/usetoast';
import { api, firstError } from '../api';
import { can } from '../stores/auth';
import { lookups, className } from '../stores/lookups';
import { number, decisionLabels, money } from '../lib/format';

const toast = useToast();
const classId = ref(null);
const termId = ref(null);
const results = ref([]);
const annual = ref([]);
const suggestions = ref([]);
const loading = ref(false);
const busy = ref(false);
const message = ref('');

const classOptions = computed(() => lookups.classes.map((c) => ({ label: className(c.id), value: c.id })));
const termOptions = computed(() => lookups.terms.map((t) => ({ label: t.name, value: t.id })));
const year = computed(() => lookups.years.find((y) => y.is_current) || lookups.years[0]);

async function load() {
    if (!classId.value) return;
    loading.value = true;
    try {
        const response = await api.get('/results/term', { class_id: classId.value, term_id: termId.value });
        results.value = response.results;
    } catch (e) {
        toast.add({ severity: 'error', summary: 'Erreur', detail: firstError(e), life: 4000 });
    } finally {
        loading.value = false;
    }
}

async function compute() {
    busy.value = true;
    message.value = '';
    try {
        const response = await api.post('/results/compute-term', { class_id: classId.value, term_id: termId.value });
        message.value = `${response.computed} moyennes calculées sur ${response.subjects} matières.`;
        toast.add({ severity: 'success', summary: 'Résultats calculés', detail: message.value, life: 3000 });
        await load();
    } catch (e) {
        toast.add({ severity: 'error', summary: 'Erreur', detail: firstError(e), life: 6000 });
    } finally {
        busy.value = false;
    }
}

async function loadAnnual() {
    if (!classId.value) return;
    try {
        annual.value = await api.get('/results/annual', { class_id: classId.value, academic_year_id: year.value.id });
    } catch (e) {
        annual.value = [];
    }
}

async function previewPromotion() {
    busy.value = true;
    try {
        const response = await api.post('/results/promotion-preview', { class_id: classId.value });
        suggestions.value = response.suggestions;
        toast.add({ severity: 'success', summary: `${response.suggestions.length} suggestion(s)`, detail: 'Les décisions restent à confirmer.', life: 3000 });
        await loadAnnual();
    } catch (e) {
        toast.add({ severity: 'error', summary: 'Erreur', detail: firstError(e), life: 6000 });
    } finally {
        busy.value = false;
    }
}

async function confirm(row, decision) {
    try {
        await api.post(`/results/${row.id}/confirm`, { decision });
        toast.add({ severity: 'success', summary: 'Décision confirmée', life: 2000 });
        await loadAnnual();
    } catch (e) {
        toast.add({ severity: 'error', summary: 'Erreur', detail: firstError(e), life: 4000 });
    }
}

onMounted(() => {
    classId.value = lookups.classes[0]?.id || null;
    termId.value = lookups.terms[0]?.id || null;
    load();
    loadAnnual();
});

watch([classId, termId], load);
watch(classId, loadAnnual);
</script>

<template>
    <div class="page">
        <div class="page-head">
            <div>
                <h1>Résultats</h1>
                <p>Moyennes calculées par la politique de notation, enregistrées comme instantanés explicables.</p>
            </div>
            <div class="flex gap-2 wrap">
                <Select v-model="classId" :options="classOptions" optionLabel="label" optionValue="value" style="min-width: 160px" />
                <Select v-model="termId" :options="termOptions" optionLabel="label" optionValue="value" style="min-width: 150px" />
                <Button v-if="can('results.manage')" label="Calculer le trimestre" icon="pi pi-calculator" size="small" :loading="busy" @click="compute" />
            </div>
        </div>

        <Message v-if="message" severity="success" :closable="false" class="mb">{{ message }}</Message>

        <div class="card">
            <h3 class="section-title">Classement du trimestre</h3>
            <DataTable :value="results" :loading="loading" size="small" stripedRows emptyMessage="Aucun résultat calculé pour ce trimestre">
                <Column header="Rang" style="width: 70px"><template #body="{ data }">{{ data.rank || '—' }}</template></Column>
                <Column header="Élève"><template #body="{ data }">{{ data.student }}</template></Column>
                <Column field="student_number" header="N°" />
                <Column header="Moyenne"><template #body="{ data }"><strong>{{ number(data.average) }}</strong></template></Column>
                <Column header="Moyenne de classe"><template #body="{ data }">{{ number(data.class_average) }}</template></Column>
                <Column header="Absences"><template #body="{ data }">{{ data.days_absent }}</template></Column>
                <Column header="Soutien">
                    <template #body="{ data }"><span v-if="data.needs_support" class="badge warn">À soutenir</span><span v-else class="muted">—</span></template>
                </Column>
            </DataTable>
        </div>

        <div class="card" style="margin-top: 14px">
            <div class="flex justify-between items-center" style="margin-bottom: 10px">
                <div>
                    <h3 class="section-title" style="margin: 0">Décisions annuelles</h3>
                    <div class="hint">Chaque suggestion enregistre la règle appliquée. Une personne autorisée doit confirmer.</div>
                </div>
                <Button v-if="can('results.manage')" label="Évaluer les règles de promotion" icon="pi pi-share-alt" size="small" outlined :loading="busy" @click="previewPromotion" />
            </div>

            <DataTable :value="annual" size="small" stripedRows emptyMessage="Aucune décision calculée">
                <Column header="Élève"><template #body="{ data }">{{ data.student?.last_name }} {{ data.student?.first_name }}</template></Column>
                <Column header="Moyenne annuelle"><template #body="{ data }">{{ number(data.average) }}</template></Column>
                <Column header="Décision"><template #body="{ data }"><span class="badge" :class="data.decision_source === 'manual' ? '' : 'gray'">{{ decisionLabels[data.decision] || data.decision }}</span></template></Column>
                <Column header="Source"><template #body="{ data }">{{ data.decision_source === 'manual' ? 'Confirmée' : 'Suggestion' }}</template></Column>
                <Column header="Règle"><template #body="{ data }"><span class="hint">{{ data.decision_note }}</span></template></Column>
                <Column header="Confirmer" style="width: 190px">
                    <template #body="{ data }">
                        <Select
                            v-if="can('results.manage')"
                            :modelValue="data.decision"
                            :options="Object.entries(decisionLabels).filter(([k]) => k !== 'pending').map(([value, label]) => ({ label, value }))"
                            optionLabel="label" optionValue="value" size="small" placeholder="Décider"
                            @update:modelValue="(value) => confirm(data, value)"
                        />
                    </template>
                </Column>
            </DataTable>
        </div>

        <div class="card" style="margin-top: 14px" v-if="suggestions.length">
            <h3 class="section-title">Dernières suggestions</h3>
            <div v-for="row in suggestions" :key="row.student_id" class="suggest-row">
                <div>
                    <strong class="small">{{ row.student }}</strong>
                    <div class="hint">Moyenne {{ number(row.average) }} — {{ row.rule || 'aucune règle' }}</div>
                </div>
                <span class="badge gray">{{ decisionLabels[row.decision] || row.decision }}</span>
            </div>
        </div>
    </div>
</template>

<style scoped>
.mb { margin-bottom: 12px; }
.suggest-row { display: flex; justify-content: space-between; align-items: center; padding: 8px 0; border-bottom: 1px solid var(--line); }
.suggest-row:last-child { border-bottom: none; }
</style>
