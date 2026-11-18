<script setup>
import { computed, onMounted, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import Tabs from 'primevue/tabs';
import TabList from 'primevue/tablist';
import Tab from 'primevue/tab';
import TabPanels from 'primevue/tabpanels';
import TabPanel from 'primevue/tabpanel';
import Button from 'primevue/button';
import Dialog from 'primevue/dialog';
import Select from 'primevue/select';
import InputText from 'primevue/inputtext';
import InputNumber from 'primevue/inputnumber';
import Checkbox from 'primevue/checkbox';
import DatePicker from 'primevue/datepicker';
import Message from 'primevue/message';
import DataTable from 'primevue/datatable';
import Column from 'primevue/column';
import { useToast } from 'primevue/usetoast';
import { api, firstError } from '../api';
import { can } from '../stores/auth';
import { lookups, className } from '../stores/lookups';
import { date, money, attendanceLabels, statusLabels, genderLabels } from '../lib/format';

const route = useRoute();
const router = useRouter();
const toast = useToast();

const data = ref(null);
const loading = ref(true);
const tab = ref('profile');
const error = ref('');

const linkDialog = ref(false);
const enrollDialog = ref(false);
const healthDialog = ref(false);
const guardians = ref([]);
const saving = ref(false);
const linkForm = ref({ relationship: 'mother', can_view_academic: true, can_view_finance: true, verified: true });
const enrollForm = ref({});
const healthForm = ref({});

const student = computed(() => data.value?.student);
const balance = computed(() => data.value?.balance);
const classOptions = computed(() => lookups.classes.map((c) => ({ label: className(c.id), value: c.id })));

async function load() {
    loading.value = true;
    try {
        data.value = await api.get(`/students/${route.params.id}`);
        healthForm.value = { ...(data.value.health || {}) };
    } catch (e) {
        toast.add({ severity: 'error', summary: 'Erreur', detail: firstError(e), life: 5000 });
    } finally {
        loading.value = false;
    }
}

async function loadGuardians() {
    try {
        const response = await api.get('/guardians', { per_page: 500 });
        guardians.value = response.data.map((g) => ({ label: `${g.last_name} ${g.first_name} — ${g.phone || g.email || ''}`, value: g.id }));
    } catch (e) {
        guardians.value = [];
    }
}

async function linkGuardian() {
    saving.value = true;
    error.value = '';
    try {
        const link = await api.post(`/students/${student.value.id}/guardians`, {
            guardian_id: linkForm.value.guardian_id,
            relationship: linkForm.value.relationship,
            is_primary_contact: !!linkForm.value.is_primary_contact,
            is_emergency_contact: !!linkForm.value.is_emergency_contact,
            can_view_academic: !!linkForm.value.can_view_academic,
            can_view_finance: !!linkForm.value.can_view_finance,
        });
        if (linkForm.value.verified) {
            await api.post(`/guardian-links/${link.id}/verify`);
        }
        toast.add({ severity: 'success', summary: 'Tuteur lié', life: 2000 });
        linkDialog.value = false;
        await load();
    } catch (e) {
        error.value = firstError(e);
    } finally {
        saving.value = false;
    }
}

async function verifyLink(link) {
    try {
        await api.post(`/guardian-links/${link.id}/verify`);
        toast.add({ severity: 'success', summary: 'Lien vérifié', life: 2000 });
        await load();
    } catch (e) {
        toast.add({ severity: 'error', summary: 'Erreur', detail: firstError(e), life: 4000 });
    }
}

async function revokeLink(link) {
    const reason = window.prompt('Motif de la révocation :');
    if (!reason) return;
    try {
        await api.post(`/guardian-links/${link.id}/revoke`, { reason });
        toast.add({ severity: 'success', summary: 'Accès révoqué', life: 2000 });
        await load();
    } catch (e) {
        toast.add({ severity: 'error', summary: 'Erreur', detail: firstError(e), life: 4000 });
    }
}

async function enroll() {
    saving.value = true;
    error.value = '';
    try {
        const payload = { class_id: enrollForm.value.class_id };
        payload.start_date = enrollForm.value.start_date instanceof Date
            ? enrollForm.value.start_date.toISOString().slice(0, 10)
            : enrollForm.value.start_date;
        await api.post(`/students/${student.value.id}/enroll`, payload);
        toast.add({ severity: 'success', summary: 'Inscription enregistrée', life: 2500 });
        enrollDialog.value = false;
        await load();
    } catch (e) {
        error.value = firstError(e);
    } finally {
        saving.value = false;
    }
}

async function saveHealth() {
    saving.value = true;
    try {
        await api.post(`/students/${student.value.id}/health`, healthForm.value);
        toast.add({ severity: 'success', summary: 'Dossier médical enregistré', life: 2500 });
        healthDialog.value = false;
        await load();
    } catch (e) {
        toast.add({ severity: 'error', summary: 'Erreur', detail: firstError(e), life: 4000 });
    } finally {
        saving.value = false;
    }
}

function openEnroll() {
    error.value = '';
    enrollForm.value = { class_id: lookups.classes[0]?.id, start_date: new Date() };
    enrollDialog.value = true;
}

function openLink() {
    error.value = '';
    linkForm.value = { relationship: 'mother', can_view_academic: true, can_view_finance: true, verified: true };
    loadGuardians();
    linkDialog.value = true;
}

onMounted(load);
</script>

<template>
    <div class="page" v-if="student">
        <div class="page-head">
            <div class="flex items-center gap-3">
                <Button icon="pi pi-arrow-left" text rounded @click="router.push({ name: 'students' })" />
                <div>
                    <h1>{{ student.last_name }} {{ student.first_name }}</h1>
                    <p>
                        <span class="mono">{{ student.student_number }}</span>
                        <span v-if="data.enrollments?.[0]" class="dot">·</span>
                        <span v-if="data.enrollments?.[0]">{{ data.enrollments[0].classroom?.level?.code }} {{ data.enrollments[0].classroom?.name }}</span>
                        · {{ statusLabels[student.status] || student.status }}
                    </p>
                </div>
            </div>
            <div class="flex gap-2">
                <Button v-if="can('students.manage')" label="Inscrire / transférer" icon="pi pi-sign-in" outlined size="small" @click="openEnroll" />
                <Button v-if="can('guardians.manage')" label="Lier un tuteur" icon="pi pi-link" size="small" @click="openLink" />
            </div>
        </div>

        <div class="grid cols-4">
            <div class="card"><div class="stat-label">Solde de l’année</div><div class="stat-value" :class="{ negative: (balance?.total_outstanding || 0) > 0 }">{{ money(balance?.total_outstanding) }}</div><div class="stat-note">{{ money(balance?.total_paid) }} payés sur {{ money(balance?.total_due) }}</div></div>
            <div class="card"><div class="stat-label">Absences</div><div class="stat-value">{{ data.attendance?.absent || 0 }}</div><div class="stat-note">{{ data.attendance?.late || 0 }} retard(s) · {{ data.attendance?.excused || 0 }} justifiée(s)</div></div>
            <div class="card"><div class="stat-label">Tuteurs</div><div class="stat-value">{{ student.guardians?.length || 0 }}</div><div class="stat-note">{{ student.guardians?.filter((g) => g.pivot?.verified_at && !g.pivot?.revoked_at).length || 0 }} avec accès vérifié</div></div>
            <div class="card"><div class="stat-label">Catégorie de frais</div><div class="stat-value" style="font-size: 20px">{{ student.category?.name || 'Standard' }}</div><div class="stat-note">Les tarifs suivent la catégorie et le niveau.</div></div>
        </div>

        <Tabs v-model:value="tab" style="margin-top: 18px">
            <TabList>
                <Tab value="profile">Profil</Tab>
                <Tab value="guardians">Tuteurs</Tab>
                <Tab value="enrollment">Scolarité</Tab>
                <Tab value="finance">Finances</Tab>
            </TabList>
            <TabPanels>
                <TabPanel value="profile">
                    <div class="card">
                        <div class="form-grid">
                            <div><div class="stat-label">Date de naissance</div><div>{{ date(student.date_of_birth) }}</div></div>
                            <div><div class="stat-label">Genre</div><div>{{ student.gender || '—' }}</div></div>
                            <div><div class="stat-label">Lieu de naissance</div><div>{{ student.place_of_birth || '—' }}</div></div>
                            <div><div class="stat-label">Nationalité</div><div>{{ student.nationality || '—' }}</div></div>
                            <div><div class="stat-label">Téléphone</div><div>{{ student.phone || '—' }}</div></div>
                            <div><div class="stat-label">Email</div><div>{{ student.email || '—' }}</div></div>
                            <div><div class="stat-label">Adresse</div><div>{{ student.address || '—' }}</div></div>
                            <div><div class="stat-label">École précédente</div><div>{{ student.previous_school || '—' }}</div></div>
                        </div>
                    </div>
                    <div class="card" style="margin-top: 14px" v-if="data.health">
                        <div class="flex justify-between items-center" style="margin-bottom: 10px">
                            <h3 class="section-title" style="margin: 0">Dossier médical (restreint)</h3>
                            <Button v-if="can('students.medical')" label="Modifier" text size="small" @click="healthDialog = true" />
                        </div>
                        <div class="form-grid">
                            <div><div class="stat-label">Allergies</div><div>{{ data.health.allergies || '—' }}</div></div>
                            <div><div class="stat-label">Conditions</div><div>{{ data.health.medical_conditions || '—' }}</div></div>
                            <div><div class="stat-label">Médicaments</div><div>{{ data.health.medications || '—' }}</div></div>
                            <div><div class="stat-label">Urgence</div><div>{{ data.health.emergency_notes || '—' }}</div></div>
                        </div>
                    </div>
                </TabPanel>

                <TabPanel value="guardians">
                    <div class="card">
                        <DataTable :value="student.guardians || []" size="small" stripedRows emptyMessage="Aucun tuteur lié">
                            <Column header="Tuteur">
                                <template #body="{ data: row }">{{ row.last_name }} {{ row.first_name }}</template>
                            </Column>
                            <Column field="pivot.relationship" header="Lien" />
                            <Column field="phone" header="Téléphone" />
                            <Column header="Droits">
                                <template #body="{ data: row }">
                                    <span class="badge gray" v-if="row.pivot?.can_view_academic">Académique</span>
                                    <span class="badge gray" v-if="row.pivot?.can_view_finance">Financier</span>
                                </template>
                            </Column>
                            <Column header="Accès">
                                <template #body="{ data: row }">
                                    <span v-if="row.pivot?.revoked_at" class="badge danger">Révoqué</span>
                                    <span v-else-if="row.pivot?.verified_at" class="badge">Vérifié</span>
                                    <span v-else class="badge warn">À vérifier</span>
                                </template>
                            </Column>
                            <Column v-if="can('guardians.manage')" header="" style="width: 180px">
                                <template #body="{ data: row }">
                                    <Button v-if="!row.pivot?.verified_at && !row.pivot?.revoked_at" label="Vérifier" size="small" text @click="verifyLink(row.pivot)" />
                                    <Button v-if="!row.pivot?.revoked_at && row.pivot?.verified_at" label="Révoquer" size="small" text severity="danger" @click="revokeLink(row.pivot)" />
                                </template>
                            </Column>
                        </DataTable>
                    </div>
                </TabPanel>

                <TabPanel value="enrollment">
                    <div class="card">
                        <DataTable :value="data.enrollments" size="small" stripedRows emptyMessage="Aucune inscription">
                            <Column header="Année"><template #body="{ data: row }">{{ row.academic_year?.name }}</template></Column>
                            <Column header="Classe"><template #body="{ data: row }">{{ row.classroom?.level?.code }} {{ row.classroom?.name }}</template></Column>
                            <Column header="Début"><template #body="{ data: row }">{{ date(row.start_date) }}</template></Column>
                            <Column header="Fin"><template #body="{ data: row }">{{ row.end_date ? date(row.end_date) : 'En cours' }}</template></Column>
                            <Column field="status" header="Statut" />
                            <Column field="outcome" header="Décision" />
                        </DataTable>
                    </div>
                </TabPanel>

                <TabPanel value="finance">
                    <div class="card">
                        <div class="flex justify-between items-center" style="margin-bottom: 12px">
                            <div>
                                <div class="stat-label">Reste à payer</div>
                                <div class="stat-value">{{ money(balance?.total_outstanding) }}</div>
                            </div>
                            <Button label="Ouvrir la caisse" icon="pi pi-wallet" size="small" outlined @click="router.push({ name: 'finance' })" />
                        </div>
                        <div class="hint">Les soldes sont calculés à partir des frais, ajustements approuvés et affectations de paiements — jamais stockés.</div>
                    </div>
                </TabPanel>
            </TabPanels>
        </Tabs>

        <Dialog v-model:visible="linkDialog" modal header="Lier un tuteur" :style="{ width: '560px' }">
            <Message v-if="error" severity="error" :closable="false" class="mb">{{ error }}</Message>
            <div class="form-grid">
                <div class="field span-2"><label>Tuteur *</label>
                    <Select v-model="linkForm.guardian_id" :options="guardians" optionLabel="label" optionValue="value" filter fluid placeholder="Sélectionner un tuteur" />
                </div>
                <div class="field"><label>Lien de parenté *</label>
                    <Select v-model="linkForm.relationship" :options="[{label:'Mère',value:'mother'},{label:'Père',value:'father'},{label:'Tuteur légal',value:'legal_guardian'},{label:'Grand-parent',value:'grandparent'},{label:'Frère/sœur',value:'sibling'},{label:'Autre',value:'other'}]" optionLabel="label" optionValue="value" fluid />
                </div>
                <div class="field">
                    <label>Options</label>
                    <div class="flex gap-3 wrap">
                        <div class="flex items-center gap-2"><Checkbox v-model="linkForm.is_primary_contact" binary inputId="pc" /><label for="pc" class="small">Contact principal</label></div>
                        <div class="flex items-center gap-2"><Checkbox v-model="linkForm.verified" binary inputId="vf" /><label for="vf" class="small">Vérifier</label></div>
                    </div>
                </div>
                <div class="field">
                    <label>Droits d’accès</label>
                    <div class="flex gap-3">
                        <div class="flex items-center gap-2"><Checkbox v-model="linkForm.can_view_academic" binary inputId="ac" /><label for="ac" class="small">Académique</label></div>
                        <div class="flex items-center gap-2"><Checkbox v-model="linkForm.can_view_finance" binary inputId="fi" /><label for="fi" class="small">Financier</label></div>
                    </div>
                </div>
            </div>
            <template #footer>
                <Button label="Annuler" text severity="secondary" @click="linkDialog = false" />
                <Button label="Lier" icon="pi pi-link" :loading="saving" @click="linkGuardian" />
            </template>
        </Dialog>

        <Dialog v-model:visible="enrollDialog" modal header="Inscription / transfert" :style="{ width: '480px' }">
            <Message v-if="error" severity="error" :closable="false" class="mb">{{ error }}</Message>
            <div class="form-grid">
                <div class="field span-2"><label>Classe *</label><Select v-model="enrollForm.class_id" :options="classOptions" optionLabel="label" optionValue="value" fluid /></div>
                <div class="field span-2"><label>Date d’effet *</label><DatePicker v-model="enrollForm.start_date" dateFormat="dd/mm/yy" showIcon fluid /></div>
            </div>
            <p class="hint">Un transfert clôture l’inscription précédente et ouvre la nouvelle, sans perdre l’historique.</p>
            <template #footer>
                <Button label="Annuler" text severity="secondary" @click="enrollDialog = false" />
                <Button label="Inscrire" icon="pi pi-check" :loading="saving" @click="enroll" />
            </template>
        </Dialog>

        <Dialog v-model:visible="healthDialog" modal header="Dossier médical (restreint)" :style="{ width: '600px' }">
            <div class="form-grid">
                <div class="field span-2"><label>Allergies</label><InputText v-model="healthForm.allergies" /></div>
                <div class="field span-2"><label>Conditions médicales</label><textarea class="p-inputtext" rows="3" v-model="healthForm.medical_conditions"></textarea></div>
                <div class="field span-2"><label>Médicaments</label><InputText v-model="healthForm.medications" /></div>
                <div class="field span-2"><label>Notes d’urgence</label><InputText v-model="healthForm.emergency_notes" /></div>
            </div>
            <template #footer>
                <Button label="Annuler" text severity="secondary" @click="healthDialog = false" />
                <Button label="Enregistrer" icon="pi pi-check" :loading="saving" @click="saveHealth" />
            </template>
        </Dialog>
    </div>
</template>

<style scoped>
.dot { margin: 0 6px; }
.negative { color: var(--danger); }
.mb { margin-bottom: 12px; }
.negative { color: var(--danger); }
</style>
