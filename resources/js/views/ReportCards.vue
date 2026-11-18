<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import Select from 'primevue/select';
import Button from 'primevue/button';
import DataTable from 'primevue/datatable';
import Column from 'primevue/column';
import Dialog from 'primevue/dialog';
import { useToast } from 'primevue/usetoast';
import { api, firstError } from '../api';
import { can } from '../stores/auth';
import { lookups, className } from '../stores/lookups';
import { number, date, money, statusLabels } from '../lib/format';

const toast = useToast();
const classId = ref(null);
const termId = ref(null);
const cards = ref([]);
const loading = ref(false);
const preview = ref(null);
const previewVisible = ref(false);
const busy = ref(false);

const classOptions = computed(() => lookups.classes.map((c) => ({ label: className(c.id), value: c.id })));
const termOptions = computed(() => lookups.terms.map((t) => ({ label: t.name, value: t.id })));

async function load() {
    loading.value = true;
    try {
        const response = await api.get('/report-cards', {
            class_id: classId.value || undefined,
            term_id: termId.value || undefined,
            per_page: 100,
        });
        cards.value = response.data;
    } catch (e) {
        toast.add({ severity: 'error', summary: 'Erreur', detail: firstError(e), life: 4000 });
    } finally {
        loading.value = false;
    }
}

async function buildClass() {
    busy.value = true;
    try {
        const response = await api.post('/report-cards/build-class', { class_id: classId.value, term_id: termId.value });
        toast.add({ severity: 'success', summary: `${response.built} bulletin(s) générés`, detail: response.errors?.length ? `${response.errors.length} erreur(s)` : '', life: 4000 });
        await load();
    } catch (e) {
        toast.add({ severity: 'error', summary: 'Erreur', detail: firstError(e), life: 6000 });
    } finally {
        busy.value = false;
    }
}

async function publish(card) {
    try {
        await api.post(`/report-cards/${card.id}/publish`);
        toast.add({ severity: 'success', summary: 'Bulletin publié', life: 2000 });
        await load();
    } catch (e) {
        toast.add({ severity: 'error', summary: 'Erreur', detail: firstError(e), life: 4000 });
    }
}

async function open(card) {
    preview.value = await api.get(`/report-cards/${card.id}`);
    previewVisible.value = true;
}

function printCard() {
    window.print();
}

onMounted(() => {
    classId.value = lookups.classes[0]?.id || null;
    termId.value = lookups.terms[0]?.id || null;
    load();
});

watch([classId, termId], load);
</script>

<template>
    <div class="page">
        <div class="page-head">
            <div>
                <h1>Bulletins</h1>
                <p>Une seule version active par élève et période. Une correction crée une nouvelle version et conserve l’ancienne.</p>
            </div>
            <div class="flex gap-2 wrap">
                <Select v-model="classId" :options="classOptions" optionLabel="label" optionValue="value" style="min-width: 160px" />
                <Select v-model="termId" :options="termOptions" optionLabel="label" optionValue="value" style="min-width: 150px" />
                <Button v-if="can('results.manage')" label="Générer pour la classe" icon="pi pi-file" size="small" :loading="busy" @click="buildClass" />
            </div>
        </div>

        <div class="card">
            <DataTable :value="cards" :loading="loading" size="small" stripedRows emptyMessage="Aucun bulletin généré">
                <Column header="Élève"><template #body="{ data }">{{ data.student?.last_name }} {{ data.student?.first_name }}</template></Column>
                <Column header="Classe"><template #body="{ data }">{{ data.classroom?.level?.code }} {{ data.classroom?.name }}</template></Column>
                <Column header="Période"><template #body="{ data }">{{ data.term?.name }}</template></Column>
                <Column header="Version"><template #body="{ data }">v{{ data.version }}</template></Column>
                <Column header="Statut">
                    <template #body="{ data }"><span class="badge" :class="data.status === 'published' ? '' : 'gray'">{{ statusLabels[data.status] || data.status }}</span></template>
                </Column>
                <Column header="Actions" style="width: 220px">
                    <template #body="{ data }">
                        <Button label="Voir" size="small" text @click="open(data)" />
                        <Button v-if="can('results.manage') && data.status !== 'published'" label="Publier" size="small" text severity="success" @click="publish(data)" />
                    </template>
                </Column>
            </DataTable>
        </div>

        <Dialog v-model:visible="previewVisible" modal :style="{ width: '900px' }" header="Aperçu du bulletin">
            <div v-if="preview" class="report-card" id="report-card-print">
                <div class="report-head">
                    <div>
                        <div style="font-weight: 700; font-size: 18px">Lycée Horizon</div>
                        <div class="hint">Antananarivo, Madagascar</div>
                        <div class="hint">Année scolaire {{ preview.snapshot.academic_year }}</div>
                    </div>
                    <div style="text-align: right">
                        <div style="font-weight: 700">Bulletin — {{ preview.snapshot.term }}</div>
                        <div class="hint">Version {{ preview.version }}</div>
                    </div>
                </div>

                <div class="student-line">
                    <div><span class="muted">Élève :</span> <strong>{{ preview.snapshot.student.name }}</strong></div>
                    <div><span class="muted">N° :</span> {{ preview.snapshot.student.number }}</div>
                    <div><span class="muted">Classe :</span> {{ preview.snapshot.class }}</div>
                </div>

                <table>
                    <thead>
                        <tr><th>Matière</th><th>Moyenne</th><th>Coef.</th><th>Points</th><th>Moy. classe</th><th>Appréciation</th></tr>
                    </thead>
                    <tbody>
                        <tr v-for="subject in preview.snapshot.subjects" :key="subject.subject_id">
                            <td>{{ subject.subject }}</td>
                            <td>{{ number(subject.average) }}</td>
                            <td>{{ subject.coefficient }}</td>
                            <td>{{ number(subject.points) }}</td>
                            <td>{{ number(subject.class_average) }}</td>
                            <td>{{ subject.appreciation || '—' }}</td>
                        </tr>
                    </tbody>
                </table>

                <div class="report-totals">
                    <div><span class="muted">Moyenne générale</span><strong>{{ number(preview.snapshot.term_result.average) }}</strong></div>
                    <div><span class="muted">Moyenne de classe</span><strong>{{ number(preview.snapshot.term_result.class_average) }}</strong></div>
                    <div v-if="preview.snapshot.term_result.rank"><span class="muted">Rang</span><strong>{{ preview.snapshot.term_result.rank }} / {{ preview.snapshot.term_result.class_size }}</strong></div>
                    <div><span class="muted">Absences</span><strong>{{ preview.snapshot.term_result.days_absent }} j</strong></div>
                </div>

                <div class="comments">
                    <div v-if="preview.snapshot.comments.teacher"><span class="muted">Appréciation de l’enseignant :</span> {{ preview.snapshot.comments.teacher }}</div>
                    <div v-if="preview.snapshot.comments.class_supervisor"><span class="muted">Professeur principal :</span> {{ preview.snapshot.comments.class_supervisor }}</div>
                    <div v-if="preview.snapshot.comments.director"><span class="muted">Le directeur :</span> {{ preview.snapshot.comments.director }}</div>
                </div>
            </div>
            <template #footer>
                <Button label="Fermer" text severity="secondary" @click="previewVisible = false" />
                <Button label="Imprimer" icon="pi pi-print" @click="printCard" />
            </template>
        </Dialog>
    </div>
</template>

<style scoped>
.student-line { display: flex; gap: 24px; margin-bottom: 16px; font-size: 13px; flex-wrap: wrap; }
.report-totals { display: flex; gap: 26px; margin-top: 16px; padding-top: 12px; border-top: 2px solid #111; flex-wrap: wrap; }
.report-totals div { display: flex; flex-direction: column; }
.report-totals strong { font-size: 16px; }
.comments { margin-top: 18px; display: flex; flex-direction: column; gap: 8px; font-size: 12.5px; }
</style>
