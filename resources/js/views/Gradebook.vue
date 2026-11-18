<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import Select from 'primevue/select';
import Button from 'primevue/button';
import Dialog from 'primevue/dialog';
import DataTable from 'primevue/datatable';
import Column from 'primevue/column';
import InputText from 'primevue/inputtext';
import InputNumber from 'primevue/inputnumber';
import DatePicker from 'primevue/datepicker';
import Message from 'primevue/message';
import Tag from 'primevue/tag';
import { useToast } from 'primevue/usetoast';
import { api, firstError } from '../api';
import { can } from '../stores/auth';
import { lookups, className, subjectLabel, staffName } from '../stores/lookups';
import { date, number, statusLabels } from '../lib/format';

const toast = useToast();
const classId = ref(null);
const termId = ref(null);
const subjectId = ref(null);
const assessments = ref([]);
const classDetail = ref(null);
const loading = ref(false);

const createDialog = ref(false);
const sheetDialog = ref(false);
const saving = ref(false);
const error = ref('');
const form = ref({});
const sheet = ref(null);
const sheetRows = ref([]);
const selectedAssessment = ref(null);

const classOptions = computed(() => lookups.classes.map((c) => ({ label: className(c.id), value: c.id })));
const termOptions = computed(() => lookups.terms.map((t) => ({ label: t.name, value: t.id })));
const assignments = computed(() => classDetail.value?.subjects || []);
const subjectOptions = computed(() => {
    const map = new Map();
    assignments.value.forEach((a) => map.set(a.subject_id, { label: a.subject, value: a.subject_id }));
    return [...map.values()];
});

function statusSeverity(status) {
    return { validated: 'success', submitted: 'warn', open: 'info' }[status] || 'secondary';
}

async function loadClass() {
    if (!classId.value) return;
    classDetail.value = await api.get(`/classes/${classId.value}`);
}

async function load() {
    if (!classId.value) return;
    loading.value = true;
    try {
        assessments.value = await api.get('/assessments', {
            class_id: classId.value,
            term_id: termId.value || undefined,
            subject_id: subjectId.value || undefined,
        });
    } catch (e) {
        toast.add({ severity: 'error', summary: 'Erreur', detail: firstError(e), life: 4000 });
    } finally {
        loading.value = false;
    }
}

function openCreate() {
    error.value = '';
    const first = assignments.value.find((a) => !subjectId.value || a.subject_id === subjectId.value) || assignments.value[0];
    form.value = {
        class_subject_teacher_id: first?.id,
        term_id: termId.value || lookups.terms[0]?.id,
        assessment_type_id: lookups.assessmentTypes[0]?.id,
        title: '',
        assessment_date: new Date(),
        max_score: 20,
        weight: 1,
    };
    createDialog.value = true;
}

async function saveAssessment() {
    saving.value = true;
    error.value = '';
    try {
        const payload = { ...form.value };
        if (payload.assessment_date instanceof Date) payload.assessment_date = payload.assessment_date.toISOString().slice(0, 10);
        await api.post('/assessments', payload);
        toast.add({ severity: 'success', summary: 'Évaluation créée', life: 2000 });
        createDialog.value = false;
        await load();
    } catch (e) {
        error.value = firstError(e);
    } finally {
        saving.value = false;
    }
}

async function openSheet(assessment) {
    error.value = '';
    selectedAssessment.value = assessment;
    const data = await api.get(`/assessments/${assessment.id}`);
    sheet.value = data;
    sheetRows.value = data.students.map((student) => {
        const grade = data.grades[student.id];
        return {
            student_id: student.id,
            name: `${student.last_name} ${student.first_name}`,
            status: grade?.status || 'not_graded',
            score: grade?.score !== undefined && grade?.score !== null ? Number(grade.score) : null,
            comment: grade?.comment || '',
        };
    });
    sheetDialog.value = true;
}

async function saveGrades() {
    saving.value = true;
    error.value = '';
    try {
        await api.post(`/assessments/${selectedAssessment.value.id}/grades`, {
            grades: sheetRows.value.map((row) => ({
                student_id: row.student_id,
                status: row.status,
                score: row.status === 'graded' ? row.score : null,
                comment: row.comment || null,
            })),
        });
        toast.add({ severity: 'success', summary: 'Notes enregistrées', life: 2000 });
        sheetDialog.value = false;
        await load();
    } catch (e) {
        error.value = firstError(e);
    } finally {
        saving.value = false;
    }
}

async function workflow(action) {
    let reason = null;
    if (action === 'reopen' || action === 'reject') {
        reason = window.prompt('Motif (obligatoire, journalisé) :');
        if (!reason) return;
    }
    if (action === 'validate' && !window.confirm('Valider les notes de cette matière ? La politique de notation sera verrouillée et les résultats calculés.')) return;

    try {
        await api.post('/gradebook/workflow', {
            class_id: classId.value,
            subject_id: subjectId.value,
            term_id: termId.value,
            action,
            reason,
        });
        toast.add({ severity: 'success', summary: 'Workflow mis à jour', detail: action, life: 2500 });
        await load();
    } catch (e) {
        toast.add({ severity: 'error', summary: 'Erreur', detail: firstError(e), life: 5000 });
    }
}

onMounted(async () => {
    classId.value = lookups.classes[0]?.id || null;
    termId.value = lookups.terms[0]?.id || null;
    await loadClass();
    await load();
});

watch(classId, async () => {
    subjectId.value = null;
    await loadClass();
    await load();
});
watch([termId, subjectId], load);
</script>

<template>
    <div class="page">
        <div class="page-head">
            <div>
                <h1>Notes</h1>
                <p>Évaluations liées à votre affectation classe/matière. Une évaluation validée est verrouillée ; sa réouverture exige un motif.</p>
            </div>
            <div class="flex gap-2 wrap">
                <Select v-model="classId" :options="classOptions" optionLabel="label" optionValue="value" style="min-width: 150px" />
                <Select v-model="subjectId" :options="subjectOptions" optionLabel="label" optionValue="value" placeholder="Toutes les matières" showClear style="min-width: 170px" />
                <Select v-model="termId" :options="termOptions" optionLabel="label" optionValue="value" style="min-width: 140px" />
                <Button v-if="can('grading.entry')" label="Nouvelle évaluation" icon="pi pi-plus" size="small" @click="openCreate" />
            </div>
        </div>

        <div class="card">
            <div class="toolbar">
                <span class="hint">{{ assessments.length }} évaluation(s)</span>
                <div class="grow"></div>
                <template v-if="can('grading.validate') && subjectId && termId">
                    <Button label="Soumettre" size="small" text @click="workflow('submit')" />
                    <Button label="Valider" size="small" severity="success" @click="workflow('validate')" />
                    <Button label="Réouvrir" size="small" text severity="warn" @click="workflow('reopen')" />
                </template>
            </div>

            <DataTable :value="assessments" :loading="loading" size="small" stripedRows emptyMessage="Aucune évaluation">
                <Column header="Évaluation">
                    <template #body="{ data }">
                        <div><strong>{{ data.title }}</strong></div>
                        <div class="hint">{{ data.class_subject_teacher?.classroom?.level?.code }} {{ data.class_subject_teacher?.classroom?.name }} · {{ data.class_subject_teacher?.subject?.name }}</div>
                    </template>
                </Column>
                <Column header="Date"><template #body="{ data }">{{ date(data.assessment_date) }}</template></Column>
                <Column header="Barème"><template #body="{ data }">/ {{ number(data.max_score, 0) }} · coef. {{ number(data.weight, 1) }}</template></Column>
                <Column header="Notes"><template #body="{ data }">{{ data.grades_count }}</template></Column>
                <Column header="Statut">
                    <template #body="{ data }"><Tag :value="statusLabels[data.status] || data.status" :severity="statusSeverity(data.status)" /></template>
                </Column>
                <Column header="" style="width: 120px">
                    <template #body="{ data }">
                        <Button label="Saisir" size="small" text icon="pi pi-pencil" @click="openSheet(data)" />
                    </template>
                </Column>
            </DataTable>
            <div v-if="!subjectId || !termId" class="hint" style="margin-top: 10px">Choisissez une matière et un trimestre pour utiliser le workflow de validation.</div>
        </div>

        <Dialog v-model:visible="createDialog" modal header="Nouvelle évaluation" :style="{ width: '620px' }">
            <Message v-if="error" severity="error" :closable="false" class="mb">{{ error }}</Message>
            <div class="form-grid">
                <div class="field span-2"><label>Matière / classe *</label>
                    <Select v-model="form.class_subject_teacher_id" :options="assignments" optionLabel="subject" optionValue="id" filter fluid />
                </div>
                <div class="field"><label>Titre *</label><InputText v-model="form.title" placeholder="Devoir 1" /></div>
                <div class="field"><label>Type *</label>
                    <Select v-model="form.assessment_type_id" :options="lookups.assessmentTypes" optionLabel="name" optionValue="id" fluid />
                </div>
                <div class="field"><label>Trimestre *</label>
                    <Select v-model="form.term_id" :options="termOptions" optionLabel="label" optionValue="value" fluid />
                </div>
                <div class="field"><label>Date</label><DatePicker v-model="form.assessment_date" dateFormat="dd/mm/yy" showIcon fluid /></div>
                <div class="field"><label>Note maximale *</label><InputNumber v-model="form.max_score" :min="1" fluid /></div>
                <div class="field"><label>Poids</label><InputNumber v-model="form.weight" :min="0" :maxFractionDigits="2" fluid /></div>
            </div>
            <template #footer>
                <Button label="Annuler" text severity="secondary" @click="createDialog = false" />
                <Button label="Créer" icon="pi pi-check" :loading="saving" @click="saveAssessment" />
            </template>
        </Dialog>

        <Dialog v-model:visible="sheetDialog" modal :header="selectedAssessment?.title" :style="{ width: '820px' }">
            <Message v-if="error" severity="error" :closable="false" class="mb">{{ error }}</Message>
            <Message v-if="sheet?.locked" severity="warn" :closable="false" class="mb">Évaluation validée : les notes sont verrouillées.</Message>
            <DataTable :value="sheetRows" size="small" scrollable>
                <Column field="name" header="Élève" />
                <Column header="Statut" style="width: 160px">
                    <template #body="{ data }">
                        <Select v-model="data.status" :options="[{label:'Noté',value:'graded'},{label:'Absent',value:'absent'},{label:'Justifié',value:'excused'},{label:'Non noté',value:'not_graded'}]" optionLabel="label" optionValue="value" size="small" fluid :disabled="sheet?.locked" />
                    </template>
                </Column>
                <Column header="Note" style="width: 130px">
                    <template #body="{ data }">
                        <InputNumber v-model="data.score" :min="0" :max="Number(selectedAssessment?.max_score || 20)" :maxFractionDigits="2" size="small" fluid :disabled="sheet?.locked || data.status !== 'graded'" />
                    </template>
                </Column>
                <Column header="Commentaire" style="width: 220px">
                    <template #body="{ data }"><InputText v-model="data.comment" size="small" fluid :disabled="sheet?.locked" /></template>
                </Column>
            </DataTable>
            <template #footer>
                <Button label="Fermer" text severity="secondary" @click="sheetDialog = false" />
                <Button label="Enregistrer les notes" icon="pi pi-check" :loading="saving" :disabled="sheet?.locked" @click="saveGrades" />
            </template>
        </Dialog>
    </div>
</template>

<style scoped>
.mb { margin-bottom: 12px; }
</style>
