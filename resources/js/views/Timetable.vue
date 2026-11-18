<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import Select from 'primevue/select';
import Button from 'primevue/button';
import Dialog from 'primevue/dialog';
import InputText from 'primevue/inputtext';
import DatePicker from 'primevue/datepicker';
import Message from 'primevue/message';
import { useToast } from 'primevue/usetoast';
import { api, firstError } from '../api';
import { can } from '../stores/auth';
import { lookups, className, staffName, subjectLabel } from '../stores/lookups';

const toast = useToast();
const dayNames = ['Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi', 'Dimanche'];
const classId = ref(null);
const staffId = ref(null);
const entries = ref([]);
const assignments = ref([]);
let assignmentsFor = null;
const loading = ref(false);
const dialog = ref(false);
const exceptionDialog = ref(false);
const saving = ref(false);
const error = ref('');
const form = ref({});
const exceptionForm = ref({});
const selectedEntry = ref(null);

const classOptions = computed(() => lookups.classes.map((c) => ({ label: className(c.id), value: c.id })));
const teachers = computed(() => lookups.staff.filter((s) => s.staff_type === 'teacher'));
const teacherOptions = computed(() => teachers.value.map((t) => ({ label: `${t.first_name} ${t.last_name}`, value: t.id })));
const byDay = computed(() => {
    const map = {};
    for (let day = 1; day <= 5; day++) map[day] = [];
    entries.value.forEach((entry) => {
        if (!map[entry.day_of_week]) map[entry.day_of_week] = [];
        map[entry.day_of_week].push(entry);
    });
    Object.values(map).forEach((list) => list.sort((a, b) => a.start_time.localeCompare(b.start_time) || a.class_id - b.class_id));
    return map;
});

async function load() {
    loading.value = true;
    try {
        entries.value = await api.get('/timetable', { class_id: classId.value || undefined, staff_id: staffId.value || undefined });
    } catch (e) {
        toast.add({ severity: 'error', summary: 'Erreur', detail: firstError(e), life: 4000 });
    } finally {
        loading.value = false;
    }
}

async function assignmentsForClass(cid) {
    if (!cid) return [];
    if (assignmentsFor === cid) return assignments.value;
    try {
        const detail = await api.get(`/classes/${cid}`);
        assignments.value = detail.subjects || [];
        assignmentsFor = cid;
    } catch (e) {
        assignments.value = [];
    }
    return assignments.value;
}

function applyAssignment(assignment) {
    form.value.subject_id = assignment?.subject_id ?? lookups.subjects[0]?.id ?? null;
    form.value.staff_id = assignment?.staff_id ?? teachers.value[0]?.id ?? null;
}

async function openNew(day = 1) {
    error.value = '';
    assignmentsFor = null;
    form.value = {
        academic_year_id: lookups.years.find((y) => y.is_current)?.id,
        class_id: classId.value || lookups.classes[0]?.id,
        subject_id: null,
        staff_id: null,
        room_id: null,
        day_of_week: day,
        start_time: '08:00',
        end_time: '10:00',
        valid_from: new Date(),
        valid_to: null,
    };
    const list = await assignmentsForClass(form.value.class_id);
    applyAssignment(list[0]);
    dialog.value = true;
}

async function onClassChange(cid) {
    if (!dialog.value) return;
    const list = await assignmentsForClass(cid);
    applyAssignment(list[0]);
}

function onSubjectChange(subjectId) {
    const assignment = assignments.value.find((a) => a.subject_id === subjectId);
    if (assignment) form.value.staff_id = assignment.staff_id;
}

async function save() {
    saving.value = true;
    error.value = '';
    try {
        const payload = { ...form.value };
        ['valid_from', 'valid_to'].forEach((key) => {
            if (payload[key] instanceof Date) payload[key] = payload[key].toISOString().slice(0, 10);
            if (payload[key] === '') payload[key] = null;
        });
        await api.post('/timetable', payload);
        toast.add({ severity: 'success', summary: 'Créneau ajouté', life: 2000 });
        dialog.value = false;
        await load();
    } catch (e) {
        error.value = firstError(e);
    } finally {
        saving.value = false;
    }
}

async function remove(entry) {
    if (!window.confirm('Supprimer ce créneau ?')) return;
    try {
        await api.delete(`/timetable/${entry.id}`);
        await load();
    } catch (e) {
        toast.add({ severity: 'error', summary: 'Erreur', detail: firstError(e), life: 4000 });
    }
}

function openException(entry) {
    error.value = '';
    selectedEntry.value = entry;
    exceptionForm.value = { exception_date: new Date(), type: 'substitute', substitute_staff_id: null, reason: '' };
    exceptionDialog.value = true;
}

async function saveException() {
    saving.value = true;
    error.value = '';
    try {
        const payload = { ...exceptionForm.value };
        if (payload.exception_date instanceof Date) payload.exception_date = payload.exception_date.toISOString().slice(0, 10);
        await api.post(`/timetable/${selectedEntry.value.id}/exceptions`, payload);
        toast.add({ severity: 'success', summary: 'Exception enregistrée', life: 2000 });
        exceptionDialog.value = false;
    } catch (e) {
        error.value = firstError(e);
    } finally {
        saving.value = false;
    }
}

onMounted(() => {
    load();
});

watch([classId, staffId], load);
</script>

<template>
    <div class="page">
        <div class="page-head">
            <div>
                <h1>Emploi du temps</h1>
                <p>Un enseignant, une classe ou une salle ne peut pas être réservé deux fois au même moment.</p>
            </div>
            <div class="flex gap-2">
                <Select v-model="classId" :options="classOptions" optionLabel="label" optionValue="value" placeholder="Toutes les classes" showClear style="min-width: 190px" />
                <Select v-model="staffId" :options="teacherOptions" optionLabel="label" optionValue="value" placeholder="Tous les enseignants" showClear style="min-width: 210px" />
                <Button v-if="can('timetable.manage')" label="Nouveau créneau" icon="pi pi-plus" size="small" @click="openNew(1)" />
            </div>
        </div>

        <div class="card">
            <div v-if="loading" class="empty">Chargement…</div>
            <div v-else class="week">
                <div v-for="day in 5" :key="day" class="day-col">
                    <div class="day-head">{{ dayNames[day - 1] }}</div>
                    <div v-for="entry in byDay[day]" :key="entry.id" class="slot">
                        <div class="flex justify-between items-start">
                            <div>
                                <div class="slot-time mono">{{ entry.start_time.slice(0, 5) }} – {{ entry.end_time.slice(0, 5) }}</div>
                                <div class="slot-subject">{{ subjectLabel(entry.subject_id) }}</div>
                                <div v-if="!classId" class="slot-class">{{ entry.classroom?.level?.code }} {{ entry.classroom?.name }}</div>
                                <div class="hint">{{ staffName(entry.staff_id) }}<span v-if="entry.room"> · {{ entry.room.name }}</span></div>
                            </div>
                            <div v-if="can('timetable.manage')" class="slot-actions">
                                <Button icon="pi pi-calendar-plus" text rounded size="small" aria-label="Exception" @click="openException(entry)" />
                                <Button icon="pi pi-trash" text rounded size="small" severity="danger" aria-label="Supprimer" @click="remove(entry)" />
                            </div>
                        </div>
                    </div>
                    <Button v-if="can('timetable.manage')" label="Ajouter" icon="pi pi-plus" text size="small" class="add-slot" @click="openNew(day)" />
                </div>
            </div>
        </div>

        <Dialog v-model:visible="dialog" modal header="Nouveau créneau" :style="{ width: '620px' }">
            <Message v-if="error" severity="error" :closable="false" class="mb">{{ error }}</Message>
            <div class="form-grid">
                <div class="field"><label>Classe *</label>
                    <Select v-model="form.class_id" :options="classOptions" optionLabel="label" optionValue="value" fluid @change="onClassChange(form.class_id)" />
                </div>
                <div class="field"><label>Matière *</label>
                    <Select v-model="form.subject_id" :options="lookups.subjects" optionLabel="name" optionValue="id" filter fluid @change="onSubjectChange(form.subject_id)" />
                </div>
                <div class="field"><label>Enseignant *</label>
                    <Select v-model="form.staff_id" :options="teacherOptions" optionLabel="label" optionValue="value" filter fluid />
                </div>
                <div class="field"><label>Jour *</label>
                    <Select v-model="form.day_of_week" :options="[1,2,3,4,5,6].map((d) => ({ label: dayNames[d-1], value: d }))" optionLabel="label" optionValue="value" fluid />
                </div>
                <div class="field"><label>Salle</label>
                    <Select v-model="form.room_id" :options="lookups.rooms" optionLabel="name" optionValue="id" showClear fluid />
                </div>
                <div class="field"><label>Début (HH:MM) *</label><InputText v-model="form.start_time" placeholder="08:00" /></div>
                <div class="field"><label>Fin (HH:MM) *</label><InputText v-model="form.end_time" placeholder="10:00" /></div>
                <div class="field"><label>Valable à partir du *</label><DatePicker v-model="form.valid_from" dateFormat="dd/mm/yy" showIcon fluid /></div>
                <div class="field"><label>Jusqu’au</label><DatePicker v-model="form.valid_to" dateFormat="dd/mm/yy" showIcon fluid /></div>
            </div>
            <template #footer>
                <Button label="Annuler" text severity="secondary" @click="dialog = false" />
                <Button label="Créer" icon="pi pi-check" :loading="saving" @click="save" />
            </template>
        </Dialog>

        <Dialog v-model:visible="exceptionDialog" modal header="Exception ponctuelle" :style="{ width: '520px' }">
            <Message v-if="error" severity="error" :closable="false" class="mb">{{ error }}</Message>
            <div class="form-grid">
                <div class="field"><label>Date *</label><DatePicker v-model="exceptionForm.exception_date" dateFormat="dd/mm/yy" showIcon fluid /></div>
                <div class="field"><label>Type *</label>
                    <Select v-model="exceptionForm.type" :options="[{label:'Annulé',value:'cancelled'},{label:'Remplaçant',value:'substitute'},{label:'Changement de salle',value:'room_change'}]" optionLabel="label" optionValue="value" fluid />
                </div>
                <div class="field" v-if="exceptionForm.type === 'substitute'"><label>Remplaçant *</label>
                    <Select v-model="exceptionForm.substitute_staff_id" :options="lookups.staff" optionLabel="first_name" optionValue="id" filter fluid />
                </div>
                <div class="field" v-if="exceptionForm.type === 'room_change'"><label>Nouvelle salle *</label>
                    <Select v-model="exceptionForm.substitute_room_id" :options="lookups.rooms" optionLabel="name" optionValue="id" fluid />
                </div>
                <div class="field span-2"><label>Motif</label><InputText v-model="exceptionForm.reason" /></div>
            </div>
            <template #footer>
                <Button label="Annuler" text severity="secondary" @click="exceptionDialog = false" />
                <Button label="Enregistrer" icon="pi pi-check" :loading="saving" @click="saveException" />
            </template>
        </Dialog>
    </div>
</template>

<style scoped>
.week { display: grid; grid-template-columns: repeat(5, minmax(0, 1fr)); gap: 10px; }
.day-col { background: #f7faf8; border-radius: 10px; padding: 8px; min-height: 200px; }
.day-head { font-size: 12px; text-transform: uppercase; letter-spacing: .06em; color: var(--muted); padding: 4px 6px 10px; }
.slot { background: #fff; border: 1px solid var(--line); border-left: 3px solid #3c8a63; border-radius: 8px; padding: 8px; margin-bottom: 8px; }
.slot-time { font-size: 11px; color: var(--muted); }
.slot-subject { font-size: 13px; font-weight: 600; margin: 2px 0; }
.slot-class { display: inline-block; font-size: 10.5px; font-weight: 600; color: #2b6b4c; background: #e9f3ed; border-radius: 5px; padding: 1px 6px; margin-bottom: 3px; }
.slot-actions { display: flex; }
.add-slot { width: 100%; font-size: 12px; }
.mb { margin-bottom: 12px; }
@media (max-width: 900px) { .week { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
</style>
