<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import Select from 'primevue/select';
import DatePicker from 'primevue/datepicker';
import Button from 'primevue/button';
import DataTable from 'primevue/datatable';
import Column from 'primevue/column';
import InputNumber from 'primevue/inputnumber';
import { useToast } from 'primevue/usetoast';
import { api, firstError } from '../api';
import { can } from '../stores/auth';
import { lookups, className } from '../stores/lookups';
import { attendanceLabels, date } from '../lib/format';

const toast = useToast();
const classId = ref(null);
const selectedDate = ref(new Date());
const roster = ref([]);
const statuses = ref({});
const lateMinutes = ref({});
const sessions = ref([]);
const justifications = ref([]);
const loading = ref(false);
const saving = ref(false);

const classOptions = computed(() => lookups.classes.map((c) => ({ label: className(c.id), value: c.id })));
const statusOptions = Object.entries(attendanceLabels).map(([value, label]) => ({ label, value }));

function isoDate(value) {
    if (!value) return '';
    return value instanceof Date ? value.toISOString().slice(0, 10) : String(value).slice(0, 10);
}

async function loadRoster() {
    if (!classId.value || !selectedDate.value) return;
    loading.value = true;
    try {
        const data = await api.get('/attendance/roster', { class_id: classId.value, date: isoDate(selectedDate.value) });
        roster.value = data.students || [];
        const next = {};
        const nextLate = {};
        (data.students || []).forEach((student) => {
            const existing = data.records?.[student.id];
            next[student.id] = existing?.status || 'present';
            nextLate[student.id] = existing?.minutes_late || null;
        });
        statuses.value = next;
        lateMinutes.value = nextLate;
    } catch (e) {
        toast.add({ severity: 'error', summary: 'Erreur', detail: firstError(e), life: 4000 });
    } finally {
        loading.value = false;
    }
}

async function loadHistory() {
    try {
        const [list, justs] = await Promise.all([
            api.get('/attendance', { class_id: classId.value, per_page: 20 }),
            api.get('/attendance-justifications', { status: 'pending' }),
        ]);
        sessions.value = list.data || [];
        justifications.value = justs.data || [];
    } catch (e) {
        /* non-blocking */
    }
}

async function save() {
    saving.value = true;
    try {
        const records = Object.entries(statuses.value).map(([studentId, status]) => ({
            student_id: Number(studentId),
            status,
            minutes_late: status === 'late' ? lateMinutes.value[studentId] || null : null,
        }));
        await api.post('/attendance', {
            class_id: classId.value,
            session_date: isoDate(selectedDate.value),
            term_id: lookups.terms[0]?.id || null,
            client_uuid: crypto.randomUUID(),
            records,
        });
        toast.add({ severity: 'success', summary: 'Appel enregistré', detail: `${records.length} élèves`, life: 2500 });
        await loadHistory();
    } catch (e) {
        toast.add({ severity: 'error', summary: 'Erreur', detail: firstError(e), life: 5000 });
    } finally {
        saving.value = false;
    }
}

async function review(justification, status) {
    try {
        await api.post(`/attendance-justifications/${justification.id}/review`, { status });
        toast.add({ severity: 'success', summary: 'Justification traitée', life: 2000 });
        await loadHistory();
        await loadRoster();
    } catch (e) {
        toast.add({ severity: 'error', summary: 'Erreur', detail: firstError(e), life: 4000 });
    }
}

function counts() {
    const result = { present: 0, absent: 0, late: 0, excused: 0, left_early: 0 };
    Object.values(statuses.value).forEach((status) => { result[status] = (result[status] || 0) + 1; });
    return result;
}

onMounted(() => {
    classId.value = lookups.classes[0]?.id || null;
    loadRoster();
    loadHistory();
});

watch([classId, selectedDate], () => {
    loadRoster();
    loadHistory();
});
</script>

<template>
    <div class="page">
        <div class="page-head">
            <div>
                <h1>Présences</h1>
                <p>Appel par classe et par date. Chaque modification conserve l’auteur et le motif ; les envois hors ligne sont idempotents.</p>
            </div>
            <div class="flex gap-2">
                <Select v-model="classId" :options="classOptions" optionLabel="label" optionValue="value" style="min-width: 180px" />
                <DatePicker v-model="selectedDate" dateFormat="dd/mm/yy" showIcon />
                <Button label="Enregistrer l’appel" icon="pi pi-check" size="small" :loading="saving" :disabled="!can('attendance.manage')" @click="save" />
            </div>
        </div>

        <div class="card">
            <div class="toolbar">
                <span class="badge">Présents {{ counts().present }}</span>
                <span class="badge warn">Retards {{ counts().late }}</span>
                <span class="badge danger">Absents {{ counts().absent }}</span>
                <span class="badge gray">Justifiés {{ counts().excused }}</span>
                <div class="grow"></div>
                <span class="hint">{{ roster.length }} élèves · {{ date(selectedDate) }}</span>
            </div>

            <DataTable :value="roster" :loading="loading" size="small" stripedRows emptyMessage="Aucun élève dans cette classe">
                <Column header="Élève">
                    <template #body="{ data }">{{ data.last_name }} {{ data.first_name }}</template>
                </Column>
                <Column field="student_number" header="N°" style="width: 150px" />
                <Column header="Statut" style="width: 180px">
                    <template #body="{ data }">
                        <Select v-model="statuses[data.id]" :options="statusOptions" optionLabel="label" optionValue="value" size="small" fluid />
                    </template>
                </Column>
                <Column header="Minutes de retard" style="width: 160px">
                    <template #body="{ data }">
                        <InputNumber v-if="statuses[data.id] === 'late'" v-model="lateMinutes[data.id]" :min="0" :max="600" size="small" fluid />
                        <span v-else class="muted">—</span>
                    </template>
                </Column>
            </DataTable>
        </div>

        <div class="grid cols-2" style="margin-top: 14px">
            <div class="card">
                <h3 class="section-title">Derniers appels</h3>
                <DataTable :value="sessions" size="small" stripedRows emptyMessage="Aucun appel enregistré">
                    <Column header="Date"><template #body="{ data }">{{ date(data.session_date) }}</template></Column>
                    <Column header="Élèves"><template #body="{ data }">{{ data.records_count }}</template></Column>
                    <Column header="Saisi par"><template #body="{ data }">{{ data.taken_by }}</template></Column>
                </DataTable>
            </div>
            <div class="card">
                <h3 class="section-title">Justifications à traiter</h3>
                <div v-if="!justifications.length" class="empty">Aucune demande en attente.</div>
                <div v-for="just in justifications" :key="just.id" class="just-row">
                    <div>
                        <div class="small"><strong>{{ just.student?.last_name }} {{ just.student?.first_name }}</strong></div>
                        <div class="hint">{{ date(just.date_from) }} → {{ date(just.date_to) }} · {{ just.reason }}</div>
                    </div>
                    <div class="flex gap-2">
                        <Button label="Accepter" size="small" text @click="review(just, 'approved')" />
                        <Button label="Refuser" size="small" text severity="danger" @click="review(just, 'rejected')" />
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>

<style scoped>
.just-row { display: flex; justify-content: space-between; align-items: center; gap: 12px; padding: 10px 0; border-bottom: 1px solid var(--line); }
.just-row:last-child { border-bottom: none; }
</style>
