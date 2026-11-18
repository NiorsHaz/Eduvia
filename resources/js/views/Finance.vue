<script setup>
import { computed, onMounted, ref } from 'vue';
import Tabs from 'primevue/tabs';
import TabList from 'primevue/tablist';
import Tab from 'primevue/tab';
import TabPanels from 'primevue/tabpanels';
import TabPanel from 'primevue/tabpanel';
import DataTable from 'primevue/datatable';
import Column from 'primevue/column';
import Button from 'primevue/button';
import Dialog from 'primevue/dialog';
import Select from 'primevue/select';
import InputText from 'primevue/inputtext';
import InputNumber from 'primevue/inputnumber';
import DatePicker from 'primevue/datepicker';
import Textarea from 'primevue/textarea';
import Message from 'primevue/message';
import { useToast } from 'primevue/usetoast';
import CrudTable from '../components/CrudTable.vue';
import { api, firstError } from '../api';
import { can } from '../stores/auth';
import { lookups, currentYear, className } from '../stores/lookups';
import { money, number, date, dateTime, statusLabels } from '../lib/format';

const toast = useToast();
const tab = ref('overview');
const year = computed(() => currentYear());
const summary = ref(null);
const charges = ref([]);
const payments = ref([]);
const reversals = ref([]);
const expenses = ref([]);
const students = ref([]);
const studentFilter = ref(null);
const busy = ref(false);

const paymentDialog = ref(false);
const chargeDialog = ref(false);
const adjustmentDialog = ref(false);
const generateDialog = ref(false);
const verifyDialog = ref(false);
const allocateDialog = ref(false);
const expenseDialog = ref(false);
const receiptDialog = ref(false);
const discountDialog = ref(false);

const error = ref('');
const paymentForm = ref({});
const chargeForm = ref({});
const adjustmentForm = ref({});
const generateForm = ref({});
const allocateForm = ref({});
const expenseForm = ref({});
const receipt = ref(null);
const selectedPayment = ref(null);
const configResource = ref('fee-structures');
const discountForm = ref({});
const grants = ref([]);

const configOptions = [
    { label: 'Frais (structures)', value: 'fee-structures' },
    { label: 'Plans de versement', value: 'installment-plans' },
    { label: 'Échéances des plans', value: 'installment-plan-items' },
    { label: 'Réductions & bourses', value: 'discounts' },
    { label: 'Pénalités', value: 'penalty-rules' },
    { label: 'Moyens de paiement', value: 'payment-methods' },
    { label: 'Catégories de dépenses', value: 'expense-categories' },
];

const studentOptions = computed(() => students.value.map((s) => ({ label: `${s.last_name} ${s.first_name} (${s.student_number})`, value: s.id })));

async function loadSummary() {
    summary.value = await api.get('/finance/summary', { academic_year_id: year.value?.id });
}

async function loadCharges() {
    const response = await api.get('/charges', {
        academic_year_id: year.value?.id,
        student_id: studentFilter.value || undefined,
        per_page: 100,
    });
    charges.value = response.data;
}

async function loadPayments() {
    const response = await api.get('/payments', { per_page: 50 });
    payments.value = response.data;
}

async function loadReversals() {
    reversals.value = await api.get('/finance/reversals');
}

async function loadExpenses() {
    const response = await api.get('/expenses', { per_page: 50 });
    expenses.value = response.data;
}

async function loadStudents() {
    const response = await api.get('/students', { per_page: 200 });
    students.value = response.data;
}

async function loadGrants() {
    grants.value = await api.get('/finance/student-discounts', { academic_year_id: year.value?.id });
}

async function refresh() {
    await Promise.all([loadSummary(), loadCharges(), loadPayments(), loadReversals(), loadExpenses(), loadStudents(), loadGrants()]);
}

function printReceipt() {
    window.print();
}

function openPayment(student = null) {
    error.value = '';
    paymentForm.value = {
        student_id: student?.student_id || studentFilter.value || students.value[0]?.id,
        payment_method_id: lookups.paymentMethods.find((m) => m.code === 'cash')?.id,
        amount: null,
        paid_at: new Date(),
        payer_name: '',
        reference: '',
        notes: '',
    };
    paymentDialog.value = true;
}

async function savePayment() {
    busy.value = true;
    error.value = '';
    try {
        const studentId = paymentForm.value.student_id;
        const chargeList = await api.get('/charges', { student_id: studentId, academic_year_id: year.value?.id, status: 'open', per_page: 200 });
        const payload = {
            academic_year_id: year.value?.id,
            payment_method_id: paymentForm.value.payment_method_id,
            amount: Number(paymentForm.value.amount),
            paid_at: paymentForm.value.paid_at instanceof Date ? paymentForm.value.paid_at.toISOString().slice(0, 19).replace('T', ' ') : paymentForm.value.paid_at,
            reference: paymentForm.value.reference || null,
            payer_name: paymentForm.value.payer_name || 'Parent',
            notes: paymentForm.value.notes || null,
            client_uuid: crypto.randomUUID(),
            charge_ids: chargeList.data.map((c) => c.id),
        };
        const payment = await api.post('/payments', payload);
        toast.add({
            severity: 'success',
            summary: payment.status === 'verified' ? 'Paiement encaissé' : 'Paiement enregistré',
            detail: payment.status === 'verified' ? `Reçu ${payment.receipt?.receipt_number || ''}` : 'En attente de vérification',
            life: 3500,
        });
        paymentDialog.value = false;
        await refresh();
    } catch (e) {
        error.value = firstError(e);
    } finally {
        busy.value = false;
    }
}

async function verifyPayment(payment) {
    try {
        await api.post(`/payments/${payment.id}/verify`, {});
        toast.add({ severity: 'success', summary: 'Paiement vérifié et reçu émis', life: 2500 });
        await refresh();
    } catch (e) {
        toast.add({ severity: 'error', summary: 'Erreur', detail: firstError(e), life: 5000 });
    }
}

function openAllocate(payment) {
    error.value = '';
    selectedPayment.value = payment;
    allocateForm.value = { student_id: null, amount: payment.unallocated ?? payment.amount };
    allocateDialog.value = true;
}

async function saveAllocation() {
    busy.value = true;
    error.value = '';
    try {
        const chargeList = await api.get('/charges', { student_id: allocateForm.value.student_id, academic_year_id: year.value?.id, status: 'open', per_page: 200 });
        let remaining = Number(allocateForm.value.amount);
        const allocations = [];
        for (const charge of chargeList.data) {
            if (remaining <= 0) break;
            const due = charge.balances?.outstanding || 0;
            const amount = Math.min(remaining, due);
            if (amount > 0) {
                allocations.push({ charge_id: charge.id, amount });
                remaining -= amount;
            }
        }
        await api.post(`/payments/${selectedPayment.value.id}/allocate`, { allocations });
        toast.add({ severity: 'success', summary: 'Affectation enregistrée', life: 2000 });
        allocateDialog.value = false;
        await refresh();
    } catch (e) {
        error.value = firstError(e);
    } finally {
        busy.value = false;
    }
}

function openCharge() {
    error.value = '';
    chargeForm.value = { student_id: students.value[0]?.id, fee_category_id: lookups.feeCategories[0]?.id, amount: null, due_date: new Date(), description: '', source: 'manual' };
    chargeDialog.value = true;
}

async function saveCharge() {
    busy.value = true;
    error.value = '';
    try {
        const payload = { ...chargeForm.value, academic_year_id: year.value?.id };
        if (payload.due_date instanceof Date) payload.due_date = payload.due_date.toISOString().slice(0, 10);
        await api.post('/charges', payload);
        toast.add({ severity: 'success', summary: 'Frais ajouté', life: 2000 });
        chargeDialog.value = false;
        await refresh();
    } catch (e) {
        error.value = firstError(e);
    } finally {
        busy.value = false;
    }
}

function openAdjustment(charge) {
    error.value = '';
    adjustmentForm.value = { charge_id: charge.id, kind: 'discount', amount: 0, reason: '' };
    adjustmentDialog.value = true;
}

async function saveAdjustment() {
    busy.value = true;
    error.value = '';
    try {
        await api.post(`/charges/${adjustmentForm.value.charge_id}/adjustments`, {
            kind: adjustmentForm.value.kind,
            amount: Number(adjustmentForm.value.amount),
            reason: adjustmentForm.value.reason || null,
        });
        toast.add({ severity: 'success', summary: 'Ajustement en attente d’approbation', life: 2500 });
        adjustmentDialog.value = false;
        await refresh();
    } catch (e) {
        error.value = firstError(e);
    } finally {
        busy.value = false;
    }
}

function openGenerate() {
    error.value = '';
    generateForm.value = { level_id: null, include_optional: false, preview: false };
    generateDialog.value = true;
}

async function runGeneration() {
    busy.value = true;
    error.value = '';
    try {
        const response = await api.post('/finance/fees/generate', {
            academic_year_id: year.value?.id,
            level_ids: generateForm.value.level_id ? [generateForm.value.level_id] : [],
            include_optional: !!generateForm.value.include_optional,
            preview: !!generateForm.value.preview,
        });
        toast.add({ severity: 'success', summary: generateForm.value.preview ? 'Aperçu calculé' : 'Frais générés', detail: `${response.created} charge(s), ${response.skipped} ignorée(s)`, life: 4000 });
        generateDialog.value = false;
        await refresh();
    } catch (e) {
        error.value = firstError(e);
    } finally {
        busy.value = false;
    }
}

async function runPenalties() {
    try {
        const response = await api.post('/finance/penalties/run', { academic_year_id: year.value?.id });
        toast.add({ severity: 'success', summary: `${response.penalties_applied} pénalité(s) appliquée(s)`, life: 3000 });
        await refresh();
    } catch (e) {
        toast.add({ severity: 'error', summary: 'Erreur', detail: firstError(e), life: 4000 });
    }
}

function openReversal(payment) {
    const reason = window.prompt('Motif de l’annulation / du remboursement :');
    if (!reason) return;
    api.post(`/payments/${payment.id}/reversal`, { kind: 'reversal', amount: payment.amount, reason })
        .then(() => {
            toast.add({ severity: 'success', summary: 'Demande enregistrée', detail: 'Une autre personne doit l’approuver.', life: 3000 });
            return refresh();
        })
        .catch((e) => toast.add({ severity: 'error', summary: 'Erreur', detail: firstError(e), life: 4000 }));
}

async function approveReversal(reversal) {
    try {
        await api.post(`/finance/reversals/${reversal.id}/approve`);
        toast.add({ severity: 'success', summary: 'Annulation approuvée', life: 2000 });
        await refresh();
    } catch (e) {
        toast.add({ severity: 'error', summary: 'Erreur', detail: firstError(e), life: 5000 });
    }
}

async function openReceipt(payment) {
    if (!payment.receipt) return;
    receipt.value = await api.get(`/receipts/${payment.receipt.id}`);
    receiptDialog.value = true;
}

function openExpense() {
    error.value = '';
    expenseForm.value = { category_id: lookups.expenseCategories[0]?.id, description: '', amount: null, expense_date: new Date(), supplier: '' };
    expenseDialog.value = true;
}

async function saveExpense() {
    busy.value = true;
    error.value = '';
    try {
        const payload = { ...expenseForm.value, academic_year_id: year.value?.id };
        if (payload.expense_date instanceof Date) payload.expense_date = payload.expense_date.toISOString().slice(0, 10);
        await api.post('/expenses', payload);
        toast.add({ severity: 'success', summary: 'Dépense enregistrée', life: 2000 });
        expenseDialog.value = false;
        await refresh();
    } catch (e) {
        error.value = firstError(e);
    } finally {
        busy.value = false;
    }
}

async function decideExpense(expense, status) {
    try {
        await api.post(`/expenses/${expense.id}/decide`, { status });
        toast.add({ severity: 'success', summary: 'Dépense mise à jour', life: 2000 });
        await refresh();
    } catch (e) {
        toast.add({ severity: 'error', summary: 'Erreur', detail: firstError(e), life: 5000 });
    }
}

function openGrant() {
    discountForm.value = { student_id: null, discount_id: lookups.discountPrograms[0]?.id };
    discountDialog.value = true;
}

async function saveGrant() {
    busy.value = true;
    try {
        await api.post('/finance/student-discounts', {
            student_id: discountForm.value.student_id,
            discount_id: discountForm.value.discount_id,
            academic_year_id: year.value?.id,
        });
        toast.add({ severity: 'success', summary: 'Bourse demandée', life: 2000 });
        discountDialog.value = false;
        await loadGrants();
    } catch (e) {
        toast.add({ severity: 'error', summary: 'Erreur', detail: firstError(e), life: 4000 });
    } finally {
        busy.value = false;
    }
}

async function decideGrant(grant, status) {
    try {
        const response = await api.post(`/finance/student-discounts/${grant.id}/decide`, { status });
        toast.add({ severity: 'success', summary: 'Décision enregistrée', detail: status === 'approved' ? `${response.adjustments_created} ajustement(s)` : '', life: 3000 });
        await refresh();
    } catch (e) {
        toast.add({ severity: 'error', summary: 'Erreur', detail: firstError(e), life: 4000 });
    }
}

onMounted(refresh);
</script>

<template>
    <div class="page">
        <div class="page-head">
            <div>
                <h1>Finances</h1>
                <p>Journal en ajout seul : aucun paiement n’est supprimé ni réécrit. Les soldes sont toujours recalculés.</p>
            </div>
            <div class="flex gap-2" v-if="can('finance.manage')">
                <Button label="Générer les frais" icon="pi pi-refresh" outlined size="small" @click="openGenerate" />
                <Button label="Encaisser" icon="pi pi-plus" size="small" @click="openPayment()" />
            </div>
        </div>

        <Tabs v-model:value="tab">
            <TabList>
                <Tab value="overview">Aperçu</Tab>
                <Tab value="charges">Frais & soldes</Tab>
                <Tab value="payments">Paiements</Tab>
                <Tab value="expenses">Dépenses</Tab>
                <Tab value="config">Configuration</Tab>
            </TabList>

            <TabPanels>
                <TabPanel value="overview">
                    <div class="grid cols-4">
                        <div class="card"><div class="stat-label">Encaissé ce mois</div><div class="stat-value">{{ money(summary?.collected_month) }}</div><div class="stat-note">{{ money(summary?.collected_today) }} aujourd’hui</div></div>
                        <div class="card"><div class="stat-label">Reste à percevoir</div><div class="stat-value">{{ money(summary?.totals?.outstanding) }}</div><div class="stat-note">{{ summary?.overdue_charges ?? 0 }} échéance(s) en retard</div></div>
                        <div class="card"><div class="stat-label">Crédit non affecté</div><div class="stat-value">{{ money(summary?.unallocated) }}</div><div class="stat-note">Paiements vérifiés non rattachés</div></div>
                        <div class="card"><div class="stat-label">À vérifier</div><div class="stat-value">{{ summary?.pending_payments ?? 0 }}</div><div class="stat-note">Virements et mobile money</div></div>
                    </div>

                    <div class="grid cols-2" style="margin-top: 14px">
                        <div class="card">
                            <h3 class="section-title">Encaissements par moyen</h3>
                            <DataTable :value="summary?.by_method || []" size="small">
                                <Column field="method" header="Moyen" />
                                <Column field="payments" header="Opérations" />
                                <Column header="Montant"><template #body="{ data }">{{ money(data.total) }}</template></Column>
                            </DataTable>
                        </div>
                        <div class="card">
                            <h3 class="section-title">Attendu vs encaissé par niveau</h3>
                            <DataTable :value="summary?.by_level || []" size="small">
                                <Column field="level" header="Niveau" />
                                <Column header="Attendu"><template #body="{ data }">{{ money(data.expected) }}</template></Column>
                                <Column header="Encaissé"><template #body="{ data }">{{ money(data.collected) }}</template></Column>
                                <Column header="Reste"><template #body="{ data }"><span class="badge warn">{{ money(data.outstanding) }}</span></template></Column>
                            </DataTable>
                        </div>
                    </div>

                    <div class="card" style="margin-top: 14px">
                        <div class="flex gap-2 wrap">
                            <Button v-if="can('finance.manage')" label="Appliquer les pénalités" icon="pi pi-exclamation-triangle" outlined size="small" @click="runPenalties" />
                            <Button label="Voir les impayés" icon="pi pi-filter" text size="small" @click="tab = 'charges'" />
                            <Button label="Attribuer une bourse" icon="pi pi-star" text size="small" @click="openGrant" />
                        </div>
                    </div>
                </TabPanel>

                <TabPanel value="charges">
                    <div class="card">
                        <div class="toolbar">
                            <Select v-model="studentFilter" :options="studentOptions" optionLabel="label" optionValue="value" filter showClear placeholder="Filtrer par élève" style="min-width: 280px" @change="loadCharges" />
                            <Button icon="pi pi-refresh" text rounded @click="loadCharges" />
                            <div class="grow"></div>
                            <Button v-if="can('finance.manage')" label="Frais manuel" icon="pi pi-plus" size="small" @click="openCharge" />
                        </div>
                        <DataTable :value="charges" size="small" stripedRows scrollable emptyMessage="Aucun frais">
                            <Column header="Élève"><template #body="{ data }">{{ data.student?.last_name }} {{ data.student?.first_name }}</template></Column>
                            <Column field="description" header="Libellé" />
                            <Column header="Échéance"><template #body="{ data }">{{ date(data.due_date) }}</template></Column>
                            <Column header="Montant"><template #body="{ data }">{{ money(data.amount) }}</template></Column>
                            <Column header="Reste"><template #body="{ data }"><strong :class="{ due: (data.balances?.outstanding || 0) > 0 }">{{ money(data.balances?.outstanding) }}</strong></template></Column>
                            <Column header="Statut">
                                <template #body="{ data }">
                                    <span v-if="data.balances?.is_overdue" class="badge danger">En retard</span>
                                    <span v-else-if="(data.balances?.outstanding || 0) <= 0" class="badge">Soldé</span>
                                    <span v-else class="badge gray">Ouvert</span>
                                </template>
                            </Column>
                            <Column header="" style="width: 170px">
                                <template #body="{ data }">
                                    <Button v-if="can('finance.manage')" label="Payer" size="small" text @click="openPayment(data)" />
                                    <Button v-if="can('finance.manage')" label="Ajuster" size="small" text @click="openAdjustment(data)" />
                                </template>
                            </Column>
                        </DataTable>
                    </div>
                </TabPanel>

                <TabPanel value="payments">
                    <div class="card">
                        <DataTable :value="payments" size="small" stripedRows scrollable emptyMessage="Aucun paiement">
                            <Column header="Référence">
                                <template #body="{ data }">
                                    <div class="mono">{{ data.reference || '—' }}</div>
                                    <div class="hint">{{ data.payer_name }}</div>
                                </template>
                            </Column>
                            <Column header="Montant"><template #body="{ data }"><strong>{{ money(data.amount) }}</strong></template></Column>
                            <Column header="Moyen"><template #body="{ data }">{{ data.method?.name }}</template></Column>
                            <Column header="Date"><template #body="{ data }">{{ dateTime(data.paid_at) }}</template></Column>
                            <Column header="Statut">
                                <template #body="{ data }">
                                    <span class="badge" :class="data.status === 'verified' ? '' : (data.status === 'pending' ? 'warn' : 'danger')">{{ statusLabels[data.status] || data.status }}</span>
                                </template>
                            </Column>
                            <Column header="Reçu"><template #body="{ data }">{{ data.receipt?.receipt_number || '—' }}</template></Column>
                            <Column header="" style="width: 240px">
                                <template #body="{ data }">
                                    <Button v-if="can('finance.manage') && data.status === 'pending'" label="Vérifier" size="small" text severity="success" @click="verifyPayment(data)" />
                                    <Button v-if="can('finance.manage') && data.status === 'verified'" label="Affecter" size="small" text @click="openAllocate(data)" />
                                    <Button v-if="data.receipt" label="Reçu" size="small" text @click="openReceipt(data)" />
                                    <Button v-if="can('finance.manage') && data.status === 'verified'" icon="pi pi-times" size="small" text severity="danger" aria-label="Annuler" @click="openReversal(data)" />
                                </template>
                            </Column>
                        </DataTable>
                    </div>

                    <div class="card" style="margin-top: 14px">
                        <h3 class="section-title">Annulations et remboursements</h3>
                        <DataTable :value="reversals" size="small" emptyMessage="Aucune demande">
                            <Column header="Paiement"><template #body="{ data }">#{{ data.payment_id }}</template></Column>
                            <Column field="kind" header="Type" />
                            <Column header="Montant"><template #body="{ data }">{{ money(data.amount) }}</template></Column>
                            <Column field="reason" header="Motif" />
                            <Column field="status" header="Statut" />
                            <Column header="" style="width: 130px">
                                <template #body="{ data }">
                                    <Button v-if="can('finance.approve') && data.status === 'requested'" label="Approuver" size="small" text severity="success" @click="approveReversal(data)" />
                                </template>
                            </Column>
                        </DataTable>
                    </div>
                </TabPanel>

                <TabPanel value="expenses">
                    <div class="card">
                        <div class="toolbar">
                            <span class="hint">Les dépenses suivent la règle des quatre yeux : demande et approbation par deux personnes.</span>
                            <div class="grow"></div>
                            <Button v-if="can('finance.manage')" label="Nouvelle dépense" icon="pi pi-plus" size="small" @click="openExpense" />
                        </div>
                        <DataTable :value="expenses" size="small" stripedRows emptyMessage="Aucune dépense">
                            <Column field="description" header="Description" />
                            <Column header="Catégorie"><template #body="{ data }">{{ data.category?.name }}</template></Column>
                            <Column header="Montant"><template #body="{ data }">{{ money(data.amount) }}</template></Column>
                            <Column header="Date"><template #body="{ data }">{{ date(data.expense_date) }}</template></Column>
                            <Column field="status" header="Statut" />
                            <Column header="Demandeur"><template #body="{ data }">{{ data.requester?.name }}</template></Column>
                            <Column header="" style="width: 200px">
                                <template #body="{ data }">
                                    <template v-if="can('finance.approve') && data.status === 'requested'">
                                        <Button label="Approuver" size="small" text severity="success" @click="decideExpense(data, 'approved')" />
                                        <Button label="Rejeter" size="small" text severity="danger" @click="decideExpense(data, 'rejected')" />
                                    </template>
                                    <Button v-if="can('finance.approve') && data.status === 'approved'" label="Marquer payée" size="small" text @click="decideExpense(data, 'paid')" />
                                </template>
                            </Column>
                        </DataTable>
                    </div>
                </TabPanel>

                <TabPanel value="config">
                    <div class="card" style="margin-bottom: 14px">
                        <div class="toolbar" style="margin-bottom: 0">
                            <Select v-model="configResource" :options="configOptions" optionLabel="label" optionValue="value" style="min-width: 240px" />
                            <div class="grow"></div>
                            <Button v-if="can('finance.manage')" label="Bourse / réduction" icon="pi pi-star" size="small" @click="openGrant" />
                        </div>
                    </div>

                    <CrudTable
                        :key="configResource"
                        :endpoint="configResource"
                        :params="{ academic_year_id: configResource === 'fee-structures' || configResource === 'installment-plans' ? year?.id : undefined }"
                        :can-create="can('finance.manage')"
                        :can-edit="can('finance.manage')"
                        :can-delete="can('finance.manage')"
                        :dialog-width="'720px'"
                    />

                    <div class="card" style="margin-top: 14px">
                        <h3 class="section-title">Bourses attribuées</h3>
                        <DataTable :value="grants" size="small" emptyMessage="Aucune bourse">
                            <Column header="Élève"><template #body="{ data }">#{{ data.student_id }}</template></Column>
                            <Column header="Programme"><template #body="{ data }">{{ data.discount?.name }}</template></Column>
                            <Column field="status" header="Statut" />
                            <Column header="" style="width: 180px">
                                <template #body="{ data }">
                                    <template v-if="data.status === 'pending' && can('finance.approve')">
                                        <Button label="Approuver" size="small" text severity="success" @click="decideGrant(data, 'approved')" />
                                        <Button label="Rejeter" size="small" text severity="danger" @click="decideGrant(data, 'rejected')" />
                                    </template>
                                </template>
                            </Column>
                        </DataTable>
                    </div>
                </TabPanel>
            </TabPanels>
        </Tabs>

        <Dialog v-model:visible="paymentDialog" modal header="Encaisser un paiement" :style="{ width: '620px' }">
            <Message v-if="error" severity="error" :closable="false" class="mb">{{ error }}</Message>
            <div class="form-grid">
                <div class="field span-2"><label>Élève *</label>
                    <Select v-model="paymentForm.student_id" :options="studentOptions" optionLabel="label" optionValue="value" filter fluid />
                </div>
                <div class="field"><label>Montant (Ar) *</label><InputNumber v-model="paymentForm.amount" :min="1" fluid /></div>
                <div class="field"><label>Moyen de paiement *</label>
                    <Select v-model="paymentForm.payment_method_id" :options="lookups.paymentMethods" optionLabel="name" optionValue="id" fluid />
                </div>
                <div class="field"><label>Date</label><DatePicker v-model="paymentForm.paid_at" showIcon dateFormat="dd/mm/yy" fluid /></div>
                <div class="field"><label>Référence</label><InputText v-model="paymentForm.reference" placeholder="N° virement, MVola…" /></div>
                <div class="field span-2"><label>Payeur</label><InputText v-model="paymentForm.payer_name" placeholder="Nom du parent" /></div>
                <div class="field span-2"><label>Notes</label><Textarea v-model="paymentForm.notes" rows="2" autoResize /></div>
            </div>
            <p class="hint">Le paiement est affecté automatiquement aux échéances les plus anciennes. Un moyen nécessitant vérification reste en attente.</p>
            <template #footer>
                <Button label="Annuler" text severity="secondary" @click="paymentDialog = false" />
                <Button label="Enregistrer" icon="pi pi-check" :loading="busy" @click="savePayment" />
            </template>
        </Dialog>

        <Dialog v-model:visible="receiptDialog" modal header="Reçu" :style="{ width: '560px' }">
            <div v-if="receipt" class="receipt">
                <div class="flex justify-between">
                    <div>
                        <div style="font-weight: 700">Lycée Horizon</div>
                        <div class="hint">Antananarivo, Madagascar</div>
                    </div>
                    <div style="text-align: right">
                        <div style="font-weight: 700">REÇU</div>
                        <div class="mono">{{ receipt.receipt_number }}</div>
                    </div>
                </div>
                <hr />
                <div class="small"><span class="muted">Payeur :</span> {{ receipt.payment?.payer_name }}</div>
                <div class="small"><span class="muted">Date :</span> {{ dateTime(receipt.payment?.paid_at) }}</div>
                <div class="small"><span class="muted">Moyen :</span> {{ receipt.payment?.method?.name }}</div>
                <div style="font-size: 22px; font-weight: 700; margin: 14px 0">{{ money(receipt.payment?.amount) }}</div>
                <table style="width: 100%; font-size: 12px">
                    <tbody>
                        <tr v-for="allocation in receipt.payment?.allocations || []" :key="allocation.id">
                            <td>{{ allocation.charge?.description }}</td>
                            <td style="text-align: right">{{ money(allocation.amount) }}</td>
                        </tr>
                    </tbody>
                </table>
                <div class="hint" style="margin-top: 16px">Reçu généré dans la même transaction que la vérification du paiement.</div>
            </div>
            <template #footer>
                <Button label="Imprimer" icon="pi pi-print" @click="printReceipt" />
                <Button label="Fermer" text severity="secondary" @click="receiptDialog = false" />
            </template>
        </Dialog>

        <Dialog v-model:visible="chargeDialog" modal header="Frais manuel" :style="{ width: '560px' }">
            <Message v-if="error" severity="error" :closable="false" class="mb">{{ error }}</Message>
            <div class="form-grid">
                <div class="field span-2"><label>Élève *</label><Select v-model="chargeForm.student_id" :options="studentOptions" optionLabel="label" optionValue="value" filter fluid /></div>
                <div class="field"><label>Catégorie *</label><Select v-model="chargeForm.fee_category_id" :options="lookups.feeCategories" optionLabel="name" optionValue="id" fluid /></div>
                <div class="field"><label>Montant (Ar) *</label><InputNumber v-model="chargeForm.amount" :min="0" fluid /></div>
                <div class="field span-2"><label>Libellé *</label><InputText v-model="chargeForm.description" /></div>
                <div class="field"><label>Échéance *</label><DatePicker v-model="chargeForm.due_date" dateFormat="dd/mm/yy" showIcon fluid /></div>
            </div>
            <template #footer>
                <Button label="Annuler" text severity="secondary" @click="chargeDialog = false" />
                <Button label="Ajouter" icon="pi pi-check" :loading="busy" @click="saveCharge" />
            </template>
        </Dialog>

        <Dialog v-model:visible="adjustmentDialog" modal header="Ajustement" :style="{ width: '520px' }">
            <Message v-if="error" severity="error" :closable="false" class="mb">{{ error }}</Message>
            <div class="form-grid">
                <div class="field"><label>Type *</label>
                    <Select v-model="adjustmentForm.kind" :options="[{label:'Remise',value:'discount'},{label:'Bourse',value:'scholarship'},{label:'Exonération',value:'waiver'},{label:'Pénalité',value:'penalty'},{label:'Correction',value:'correction'}]" optionLabel="label" optionValue="value" fluid />
                </div>
                <div class="field"><label>Montant (Ar) *</label><InputNumber v-model="adjustmentForm.amount" :min="1" fluid /></div>
                <div class="field span-2"><label>Motif *</label><InputText v-model="adjustmentForm.reason" /></div>
            </div>
            <p class="hint">Un ajustement doit être approuvé par une autre personne avant d’affecter le solde.</p>
            <template #footer>
                <Button label="Annuler" text severity="secondary" @click="adjustmentDialog = false" />
                <Button label="Demander" icon="pi pi-check" :loading="busy" @click="saveAdjustment" />
            </template>
        </Dialog>

        <Dialog v-model:visible="generateDialog" modal header="Générer les frais" :style="{ width: '520px' }">
            <Message v-if="error" severity="error" :closable="false" class="mb">{{ error }}</Message>
            <div class="form-grid">
                <div class="field span-2"><label>Niveau (optionnel)</label>
                    <Select v-model="generateForm.level_id" :options="lookups.levels" optionLabel="name" optionValue="id" showClear fluid />
                </div>
                <div class="field"><label>Inclure les frais optionnels</label>
                    <Select v-model="generateForm.include_optional" :options="[{label:'Non',value:false},{label:'Oui',value:true}]" optionLabel="label" optionValue="value" fluid />
                </div>
                <div class="field"><label>Mode</label>
                    <Select v-model="generateForm.preview" :options="[{label:'Créer les charges',value:false},{label:'Aperçu seulement',value:true}]" optionLabel="label" optionValue="value" fluid />
                </div>
            </div>
            <p class="hint">La génération est répétable : une échéance déjà créée ne sera jamais dupliquée.</p>
            <template #footer>
                <Button label="Annuler" text severity="secondary" @click="generateDialog = false" />
                <Button label="Lancer" icon="pi pi-refresh" :loading="busy" @click="runGeneration" />
            </template>
        </Dialog>

        <Dialog v-model:visible="allocateDialog" modal header="Affecter le crédit" :style="{ width: '520px' }">
            <Message v-if="error" severity="error" :closable="false" class="mb">{{ error }}</Message>
            <div class="form-grid">
                <div class="field span-2"><label>Élève *</label><Select v-model="allocateForm.student_id" :options="studentOptions" optionLabel="label" optionValue="value" filter fluid /></div>
                <div class="field span-2"><label>Montant à affecter (Ar) *</label><InputNumber v-model="allocateForm.amount" :min="1" fluid /></div>
            </div>
            <p class="hint">Affectation automatique aux échéances les plus anciennes, sans dépasser le montant dû.</p>
            <template #footer>
                <Button label="Annuler" text severity="secondary" @click="allocateDialog = false" />
                <Button label="Affecter" icon="pi pi-check" :loading="busy" @click="saveAllocation" />
            </template>
        </Dialog>

        <Dialog v-model:visible="expenseDialog" modal header="Nouvelle dépense" :style="{ width: '560px' }">
            <Message v-if="error" severity="error" :closable="false" class="mb">{{ error }}</Message>
            <div class="form-grid">
                <div class="field"><label>Catégorie *</label><Select v-model="expenseForm.category_id" :options="lookups.expenseCategories" optionLabel="name" optionValue="id" fluid /></div>
                <div class="field"><label>Montant (Ar) *</label><InputNumber v-model="expenseForm.amount" :min="1" fluid /></div>
                <div class="field span-2"><label>Description *</label><InputText v-model="expenseForm.description" /></div>
                <div class="field"><label>Fournisseur</label><InputText v-model="expenseForm.supplier" /></div>
                <div class="field"><label>Date *</label><DatePicker v-model="expenseForm.expense_date" dateFormat="dd/mm/yy" showIcon fluid /></div>
            </div>
            <template #footer>
                <Button label="Annuler" text severity="secondary" @click="expenseDialog = false" />
                <Button label="Enregistrer" icon="pi pi-check" :loading="busy" @click="saveExpense" />
            </template>
        </Dialog>

        <Dialog v-model:visible="discountDialog" modal header="Bourse / réduction" :style="{ width: '520px' }">
            <div class="form-grid">
                <div class="field span-2"><label>Élève *</label><Select v-model="discountForm.student_id" :options="studentOptions" optionLabel="label" optionValue="value" filter fluid /></div>
                <div class="field span-2"><label>Programme *</label><Select v-model="discountForm.discount_id" :options="lookups.discountPrograms" optionLabel="name" optionValue="id" fluid /></div>
            </div>
            <template #footer>
                <Button label="Annuler" text severity="secondary" @click="discountDialog = false" />
                <Button label="Demander" icon="pi pi-check" :loading="busy" @click="saveGrant" />
            </template>
        </Dialog>
    </div>
</template>

<style scoped>
.mb { margin-bottom: 12px; }
.due { color: var(--danger); }
</style>
