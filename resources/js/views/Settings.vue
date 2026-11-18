<script setup>
import { computed, onMounted, ref } from 'vue';
import Tabs from 'primevue/tabs';
import TabList from 'primevue/tablist';
import Tab from 'primevue/tab';
import TabPanels from 'primevue/tabpanels';
import TabPanel from 'primevue/tabpanel';
import Button from 'primevue/button';
import DataTable from 'primevue/datatable';
import Column from 'primevue/column';
import Dialog from 'primevue/dialog';
import Select from 'primevue/select';
import InputText from 'primevue/inputtext';
import InputNumber from 'primevue/inputnumber';
import ToggleSwitch from 'primevue/toggleswitch';
import Textarea from 'primevue/textarea';
import Message from 'primevue/message';
import Tag from 'primevue/tag';
import CrudTable from '../components/CrudTable.vue';
import { useToast } from 'primevue/usetoast';
import { api, firstError } from '../api';
import { can } from '../stores/auth';
import { lookups, currentYear } from '../stores/lookups';
import { number, humanize } from '../lib/format';

const toast = useToast();
const tab = ref('general');
const modules = ref({});
const policies = ref([]);
const metrics = ref([]);
const policyDialog = ref(false);
const ruleDialog = ref(false);
const subjectRuleDialog = ref(false);
const cloneDialog = ref(false);
const policyForm = ref({});
const selectedPolicy = ref(null);
const saving = ref(false);
const error = ref('');
const cloneTarget = ref(null);
const newRule = ref({});
const newSubjectRule = ref({});

const scope = ref({ academic_year_id: currentYear()?.id || null, level_id: null });

const moduleLabels = {
    students: 'Élèves', attendance: 'Présences', grading: 'Notation', finance: 'Finances', reportcard: 'Bulletins',
};

const policyFields = [
    { field: 'pass_mark', label: 'Note de passage', type: 'number' },
    { field: 'eliminatory_mark', label: 'Note éliminatoire', type: 'number' },
    { field: 'rounding_decimals', label: 'Décimales', type: 'number' },
    { field: 'support_threshold', label: 'Seuil de soutien', type: 'number' },
    { field: 'min_graded_assessments', label: 'Évaluations minimum', type: 'number' },
    { field: 'absence_handling', label: 'Absence', type: 'select', options: [
        { label: 'Ignorer', value: 'ignore' }, { label: 'Compter zéro', value: 'zero' },
        { label: 'Zéro si non justifiée', value: 'zero_if_unexcused' },
    ] },
    { field: 'missing_grade_handling', label: 'Note manquante', type: 'select', options: [
        { label: 'Ignorer', value: 'ignore' }, { label: 'Compter zéro', value: 'zero' },
        { label: 'Bloquer la validation', value: 'block_validation' },
    ] },
    { field: 'averaging_method', label: 'Moyenne des évaluations', type: 'select', options: [
        { label: 'Pondérée', value: 'weighted' }, { label: 'Moyenne simple', value: 'simple_mean' },
        { label: 'Meilleures N', value: 'best_n' }, { label: 'Retirer la plus basse', value: 'drop_lowest' },
    ] },
    { field: 'subject_aggregation', label: 'Moyenne des matières', type: 'select', options: [
        { label: 'Pondérée par coefficient', value: 'coefficient_weighted' }, { label: 'Moyenne simple', value: 'simple_mean' },
    ] },
    { field: 'term_aggregation', label: 'Moyenne annuelle', type: 'select', options: [
        { label: 'Trimestres pondérés', value: 'weighted_terms' }, { label: 'Moyenne simple', value: 'simple_mean' },
        { label: 'Dernier trimestre', value: 'last_term_only' },
    ] },
    { field: 'rounding_mode', label: 'Arrondi', type: 'select', options: [
        { label: 'Demi vers le haut', value: 'half_up' }, { label: 'Vers le bas', value: 'down' }, { label: 'Vers le haut', value: 'up' },
    ] },
];

async function loadRegistry() {
    const response = await api.get('/settings', { academic_year_id: scope.value.academic_year_id, level_id: scope.value.level_id });
    modules.value = response.modules;
}

async function loadPolicies() {
    policies.value = await api.get('/grading-policies', { academic_year_id: currentYear()?.id });
}

async function loadMetrics() {
    metrics.value = await api.get('/rule-metrics');
}

async function saveSetting(setting, value) {
    try {
        const payloadValue = setting.value_type === 'json' ? JSON.parse(value) : value;
        await api.put('/settings', { key: setting.key, value: payloadValue, scope: { academic_year_id: scope.value.academic_year_id, level_id: scope.value.level_id } });
        setting.value = payloadValue;
        setting.is_default = false;
        toast.add({ severity: 'success', summary: 'Paramètre enregistré', life: 1800 });
    } catch (e) {
        toast.add({ severity: 'error', summary: 'Erreur', detail: firstError(e), life: 5000 });
    }
}

function openNewPolicy() {
    error.value = '';
    policyForm.value = {
        academic_year_id: currentYear()?.id,
        scale_id: null,
        name: 'Politique standard /20',
        pass_mark: 10,
        eliminatory_mark: 5,
        rounding_decimals: 2,
        rounding_mode: 'half_up',
        ranking_enabled: true,
        absence_handling: 'ignore',
        missing_grade_handling: 'ignore',
        averaging_method: 'weighted',
        subject_aggregation: 'coefficient_weighted',
        term_aggregation: 'weighted_terms',
        min_graded_assessments: 1,
        support_threshold: 10,
        show_rank_on_report_card: true,
        with_standard_promotion_rules: true,
    };
    selectedPolicy.value = null;
    policyDialog.value = true;
}

async function editPolicy(policy) {
    error.value = '';
    policyForm.value = { ...policy, with_standard_promotion_rules: false };
    selectedPolicy.value = policy;
    policyDialog.value = true;
}

async function savePolicy() {
    saving.value = true;
    error.value = '';
    try {
        const payload = { ...policyForm.value };
        if (payload.pass_mark !== null) payload.pass_mark = Number(payload.pass_mark);
        if (selectedPolicy.value) {
            await api.put(`/grading-policies/${selectedPolicy.value.id}`, payload);
        } else {
            await api.post('/grading-policies', payload);
        }
        toast.add({ severity: 'success', summary: 'Politique enregistrée', life: 2000 });
        policyDialog.value = false;
        await loadPolicies();
        if (selectedPolicy.value) await openPolicy(selectedPolicy.value);
    } catch (e) {
        error.value = firstError(e);
    } finally {
        saving.value = false;
    }
}

async function openPolicy(policy) {
    try {
        const detail = await api.get(`/grading-policies/${policy.id}`);
        selectedPolicy.value = detail;
        tab.value = 'notation';
    } catch (e) {
        toast.add({ severity: 'error', summary: 'Erreur', detail: firstError(e), life: 4000 });
    }
}

async function lockPolicy() {
    const reason = window.prompt('Motif du verrouillage :');
    if (!reason) return;
    try {
        await api.post(`/grading-policies/${selectedPolicy.value.id}/lock`, { reason });
        toast.add({ severity: 'success', summary: 'Politique verrouillée', life: 2000 });
        await openPolicy(selectedPolicy.value);
        await loadPolicies();
    } catch (e) {
        toast.add({ severity: 'error', summary: 'Erreur', detail: firstError(e), life: 5000 });
    }
}

function openClone() {
    cloneTarget.value = lookups.years.find((y) => y.id !== selectedPolicy.value.academic_year_id)?.id || null;
    cloneDialog.value = true;
}

async function clonePolicy() {
    saving.value = true;
    try {
        const copy = await api.post(`/grading-policies/${selectedPolicy.value.id}/clone`, { target_academic_year_id: cloneTarget.value });
        toast.add({ severity: 'success', summary: 'Nouvelle version créée', life: 2500 });
        cloneDialog.value = false;
        await loadPolicies();
        await openPolicy(copy);
    } catch (e) {
        toast.add({ severity: 'error', summary: 'Erreur', detail: firstError(e), life: 5000 });
    } finally {
        saving.value = false;
    }
}

function openSubjectRule() {
    newSubjectRule.value = { subject_id: null, pass_mark: null, eliminatory_mark: null, counts_in_average: true, is_core: false, scale_id: null };
    subjectRuleDialog.value = true;
}

async function saveSubjectRule() {
    saving.value = true;
    try {
        await api.post(`/grading-policies/${selectedPolicy.value.id}/subject-rules`, newSubjectRule.value);
        toast.add({ severity: 'success', summary: 'Exception enregistrée', life: 2000 });
        subjectRuleDialog.value = false;
        await openPolicy(selectedPolicy.value);
    } catch (e) {
        toast.add({ severity: 'error', summary: 'Erreur', detail: firstError(e), life: 5000 });
    } finally {
        saving.value = false;
    }
}

function openRule() {
    newRule.value = { priority: 10, label: '', metric: 'annual_average', op: '>=', value: 10, outcome: 'promoted', requires_human_confirmation: true };
    ruleDialog.value = true;
}

async function saveRule() {
    saving.value = true;
    try {
        const set = selectedPolicy.value.rule_sets?.[0];
        if (!set) throw new Error('Créez d’abord un jeu de règles.');
        await api.post(`/rule-sets/${set.id}/rules`, {
            priority: Number(newRule.value.priority),
            label: newRule.value.label,
            condition: { all: [{ metric: newRule.value.metric, op: newRule.value.op, value: Number(newRule.value.value) }] },
            outcome: newRule.value.outcome,
            requires_human_confirmation: true,
            is_active: true,
        });
        toast.add({ severity: 'success', summary: 'Règle ajoutée', life: 2000 });
        ruleDialog.value = false;
        await openPolicy(selectedPolicy.value);
    } catch (e) {
        toast.add({ severity: 'error', summary: 'Erreur', detail: firstError(e), life: 5000 });
    } finally {
        saving.value = false;
    }
}

async function deleteRule(rule) {
    if (!window.confirm('Supprimer cette règle ?')) return;
    try {
        await api.delete(`/promotion-rules/${rule.id}`);
        await openPolicy(selectedPolicy.value);
    } catch (e) {
        toast.add({ severity: 'error', summary: 'Erreur', detail: firstError(e), life: 5000 });
    }
}

const writable = computed(() => can('settings.manage'));

onMounted(async () => {
    await Promise.all([loadRegistry(), loadPolicies(), loadMetrics()]);
});
</script>

<template>
    <div class="page">
        <div class="page-head">
            <div>
                <h1>Règles & paramètres</h1>
                <p>Chaque règle est une donnée : barèmes, promotion, frais, présences et numérotation. Une politique verrouillée ne peut plus être modifiée, seulement versionnée.</p>
            </div>
            <div class="flex gap-2">
                <Select v-model="scope.academic_year_id" :options="lookups.years" optionLabel="name" optionValue="id" showClear style="min-width: 150px" @change="loadRegistry" />
                <Select v-model="scope.level_id" :options="lookups.levels" optionLabel="name" optionValue="id" showClear placeholder="Tous niveaux" style="min-width: 150px" @change="loadRegistry" />
            </div>
        </div>

        <Tabs v-model:value="tab">
            <TabList>
                <Tab value="general">Paramètres</Tab>
                <Tab value="notation">Politiques de notation</Tab>
                <Tab value="promotion">Promotion</Tab>
                <Tab value="numbering">Numérotation</Tab>
            </TabList>

            <TabPanels>
                <TabPanel value="general">
                    <div v-for="(definitions, module) in modules" :key="module" class="card" style="margin-bottom: 14px">
                        <h3 class="section-title">{{ moduleLabels[module] || module }}</h3>
                        <div class="settings-list">
                            <div v-for="setting in definitions" :key="setting.key" class="setting-row">
                                <div class="setting-copy">
                                    <div class="setting-label">{{ setting.label_key }}</div>
                                    <div class="hint">{{ setting.key }} <span v-if="!setting.is_default" class="badge gray">modifié</span></div>
                                </div>
                                <div class="setting-input">
                                    <ToggleSwitch v-if="setting.value_type === 'boolean'" v-model="setting.value" :disabled="!writable" @change="saveSetting(setting, setting.value)" />
                                    <Select v-else-if="setting.value_type === 'enum'" v-model="setting.value" :options="(setting.allowed_values || []).map((v) => ({ label: humanize(v), value: v }))" optionLabel="label" optionValue="value" :disabled="!writable" style="min-width: 220px" @change="saveSetting(setting, setting.value)" />
                                    <InputNumber v-else-if="setting.value_type === 'integer' || setting.value_type === 'decimal'" v-model="setting.value" :min="0" :disabled="!writable" @blur="saveSetting(setting, setting.value)" />
                                    <InputText v-else-if="setting.value_type === 'string'" v-model="setting.value" :disabled="!writable" style="min-width: 220px" @blur="saveSetting(setting, setting.value)" />
                                    <Textarea v-else v-model="setting.value" rows="2" :disabled="!writable" @blur="saveSetting(setting, Array.isArray(setting.value) ? setting.value : setting.value)" />
                                </div>
                            </div>
                        </div>
                    </div>
                </TabPanel>

                <TabPanel value="notation">
                    <div class="card">
                        <div class="toolbar">
                            <span class="hint">La politique la plus spécifique (niveau) gagne sur la politique par défaut.</span>
                            <div class="grow"></div>
                            <Button v-if="writable" label="Nouvelle politique" icon="pi pi-plus" size="small" @click="openNewPolicy" />
                        </div>
                        <DataTable :value="policies" size="small" stripedRows emptyMessage="Aucune politique">
                            <Column header="Nom"><template #body="{ data }"><strong>{{ data.name }}</strong><div class="hint">{{ data.academic_year?.name }}</div></template></Column>
                            <Column header="Niveau"><template #body="{ data }">{{ data.level?.name || 'Tous' }}</template></Column>
                            <Column header="Barème"><template #body="{ data }">{{ number(data.scale?.max_score, 0) }} · passage {{ number(data.pass_mark, 0) }}</template></Column>
                            <Column header="Version"><template #body="{ data }">v{{ data.version }}</template></Column>
                            <Column header="État">
                                <template #body="{ data }">
                                    <Tag v-if="data.locked_at" value="Verrouillée" severity="warn" />
                                    <Tag v-else-if="data.is_active" value="Active" severity="success" />
                                    <Tag v-else value="Inactive" severity="secondary" />
                                </template>
                            </Column>
                            <Column header="" style="width: 200px">
                                <template #body="{ data }">
                                    <Button label="Ouvrir" size="small" text @click="openPolicy(data)" />
                                    <Button v-if="writable" label="Modifier" size="small" text @click="editPolicy(data)" />
                                </template>
                            </Column>
                        </DataTable>
                    </div>

                    <div v-if="selectedPolicy" class="card" style="margin-top: 14px">
                        <div class="flex justify-between items-center wrap gap-2">
                            <div>
                                <h3 class="section-title" style="margin: 0">{{ selectedPolicy.name }}</h3>
                                <div class="hint">Absences : {{ humanize(selectedPolicy.absence_handling) }} · notes manquantes : {{ humanize(selectedPolicy.missing_grade_handling) }} · moyenne : {{ humanize(selectedPolicy.averaging_method) }}</div>
                            </div>
                            <div class="flex gap-2" v-if="writable">
                                <Button label="Verrouiller" icon="pi pi-lock" size="small" outlined @click="lockPolicy" />
                                <Button label="Nouvelle version" icon="pi pi-copy" size="small" @click="openClone" />
                            </div>
                        </div>

                        <h4 class="sub-title">Exceptions par matière</h4>
                        <DataTable :value="selectedPolicy.subject_rules" size="small" emptyMessage="Aucune exception">
                            <Column header="Matière"><template #body="{ data }">{{ data.subject?.name }}</template></Column>
                            <Column header="Note de passage"><template #body="{ data }">{{ data.pass_mark ?? '—' }}</template></Column>
                            <Column header="Éliminatoire"><template #body="{ data }">{{ data.eliminatory_mark ?? '—' }}</template></Column>
                            <Column header="Comptée"><template #body="{ data }">{{ data.counts_in_average ? 'Oui' : 'Non' }}</template></Column>
                            <Column header="Principale"><template #body="{ data }">{{ data.is_core ? 'Oui' : 'Non' }}</template></Column>
                        </DataTable>
                        <Button v-if="writable" label="Ajouter une exception" icon="pi pi-plus" text size="small" style="margin-top: 8px" @click="openSubjectRule" />

                        <template v-if="selectedPolicy.rule_sets?.length">
                            <h4 class="sub-title">{{ selectedPolicy.rule_sets[0].name }}</h4>
                            <DataTable :value="selectedPolicy.rule_sets[0].rules" size="small" emptyMessage="Aucune règle">
                                <Column field="priority" header="Priorité" style="width: 90px" />
                                <Column field="label" header="Condition" />
                                <Column header="Décision"><template #body="{ data }">{{ humanize(data.outcome) }}</template></Column>
                                <Column header="Confirmation"><template #body="{ data }">{{ data.requires_human_confirmation ? 'Humaine' : 'Automatique' }}</template></Column>
                                <Column v-if="writable" header="" style="width: 80px">
                                    <template #body="{ data }"><Button icon="pi pi-trash" text rounded size="small" severity="danger" @click="deleteRule(data)" /></template>
                                </Column>
                            </DataTable>
                            <Button v-if="writable" label="Ajouter une règle" icon="pi pi-plus" text size="small" style="margin-top: 8px" @click="openRule" />
                        </template>
                        <div v-else class="hint" style="margin-top: 10px">Cette politique n’a pas encore de jeu de règles de promotion.</div>
                    </div>
                    <div v-else class="empty">Sélectionnez une politique pour voir ses règles.</div>
                </TabPanel>

                <TabPanel value="promotion">
                    <div class="card">
                        <h3 class="section-title">Métriques autorisées</h3>
                        <p class="hint">Les conditions ne sont jamais exécutées comme du code : seules ces métriques sont lues par l’évaluateur.</p>
                        <DataTable :value="metrics" size="small">
                            <Column field="code" header="Code" />
                            <Column field="label_key" header="Libellé" />
                            <Column field="value_type" header="Type" />
                        </DataTable>
                    </div>
                </TabPanel>

                <TabPanel value="numbering">
                    <CrudTable
                        endpoint="number-series"
                        :columns="[
                            { field: 'code', header: 'Série' },
                            { field: 'pattern', header: 'Modèle' },
                            { field: 'is_active', header: 'Active', type: 'boolean' },
                        ]"
                        :fields="[
                            { field: 'code', label: 'Code (receipt, invoice)', required: true },
                            { field: 'pattern', label: 'Modèle', required: true, hint: 'Ex. REC-{YEAR}-{SEQ:6}' },
                            { field: 'is_active', label: 'Active', type: 'checkbox', default: true },
                        ]"
                        :can-create="writable"
                        :can-edit="writable"
                        :can-delete="false"
                        :row-key="'code'"
                    />
                </TabPanel>
            </TabPanels>
        </Tabs>

        <Dialog v-model:visible="policyDialog" modal :header="selectedPolicy ? 'Modifier la politique' : 'Nouvelle politique'" :style="{ width: '760px' }">
            <Message v-if="error" severity="error" :closable="false" class="mb">{{ error }}</Message>
            <div class="form-grid">
                <div class="field span-2"><label>Nom *</label><InputText v-model="policyForm.name" /></div>
                <div class="field"><label>Année académique *</label>
                    <Select v-model="policyForm.academic_year_id" :options="lookups.years" optionLabel="name" optionValue="id" :disabled="!!selectedPolicy" fluid />
                </div>
                <div class="field"><label>Barème *</label>
                    <Select v-model="policyForm.scale_id" :options="lookups.gradingScales || []" optionLabel="name" optionValue="id" fluid placeholder="Barème /20 par défaut" />
                </div>
                <div class="field" v-for="field in policyFields" :key="field.field">
                    <label>{{ field.label }}</label>
                    <InputNumber v-if="field.type === 'number'" v-model="policyForm[field.field]" :min="0" fluid />
                    <Select v-else v-model="policyForm[field.field]" :options="field.options" optionLabel="label" optionValue="value" fluid />
                </div>
                <div class="field span-2 flex gap-3 wrap">
                    <div class="flex items-center gap-2"><ToggleSwitch v-model="policyForm.ranking_enabled" /><label class="small">Classement activé</label></div>
                    <div class="flex items-center gap-2"><ToggleSwitch v-model="policyForm.show_rank_on_report_card" /><label class="small">Rang sur le bulletin</label></div>
                </div>
            </div>
            <template #footer>
                <Button label="Annuler" text severity="secondary" @click="policyDialog = false" />
                <Button label="Enregistrer" icon="pi pi-check" :loading="saving" @click="savePolicy" />
            </template>
        </Dialog>

        <Dialog v-model:visible="subjectRuleDialog" modal header="Exception par matière" :style="{ width: '560px' }">
            <div class="form-grid">
                <div class="field span-2"><label>Matière *</label><Select v-model="newSubjectRule.subject_id" :options="lookups.subjects" optionLabel="name" optionValue="id" filter fluid /></div>
                <div class="field"><label>Note de passage</label><InputNumber v-model="newSubjectRule.pass_mark" :min="0" fluid /></div>
                <div class="field"><label>Note éliminatoire</label><InputNumber v-model="newSubjectRule.eliminatory_mark" :min="0" fluid /></div>
                <div class="field flex items-center gap-2"><ToggleSwitch v-model="newSubjectRule.counts_in_average" /><label class="small">Comptée dans la moyenne</label></div>
                <div class="field flex items-center gap-2"><ToggleSwitch v-model="newSubjectRule.is_core" /><label class="small">Matière principale</label></div>
            </div>
            <template #footer>
                <Button label="Annuler" text severity="secondary" @click="subjectRuleDialog = false" />
                <Button label="Enregistrer" icon="pi pi-check" :loading="saving" @click="saveSubjectRule" />
            </template>
        </Dialog>

        <Dialog v-model:visible="ruleDialog" modal header="Nouvelle règle de promotion" :style="{ width: '600px' }">
            <div class="form-grid">
                <div class="field"><label>Priorité *</label><InputNumber v-model="newRule.priority" :min="1" fluid /></div>
                <div class="field"><label>Décision *</label>
                    <Select v-model="newRule.outcome" :options="[{label:'Promu',value:'promoted'},{label:'Redouble',value:'repeat'},{label:'Conditionnel',value:'conditional'},{label:'Conseil de classe',value:'council_review'},{label:'Diplômé',value:'graduated'},{label:'Sorti',value:'left'}]" optionLabel="label" optionValue="value" fluid />
                </div>
                <div class="field span-2"><label>Libellé *</label><InputText v-model="newRule.label" placeholder="Moyenne ≥ 10 sans note éliminatoire" /></div>
                <div class="field"><label>Métrique</label>
                    <Select v-model="newRule.metric" :options="metrics" optionLabel="label_key" optionValue="code" fluid />
                </div>
                <div class="field"><label>Opérateur</label>
                    <Select v-model="newRule.op" :options="['>=','>','<=','<','=','!=']" fluid />
                </div>
                <div class="field"><label>Valeur</label><InputNumber v-model="newRule.value" fluid /></div>
            </div>
            <p class="hint">La condition est stockée en JSON structuré et lue par un évaluateur à liste blanche.</p>
            <template #footer>
                <Button label="Annuler" text severity="secondary" @click="ruleDialog = false" />
                <Button label="Ajouter" icon="pi pi-check" :loading="saving" @click="saveRule" />
            </template>
        </Dialog>

        <Dialog v-model:visible="cloneDialog" modal header="Créer une nouvelle version" :style="{ width: '480px' }">
            <div class="field">
                <label>Année cible</label>
                <Select v-model="cloneTarget" :options="lookups.years" optionLabel="name" optionValue="id" fluid />
            </div>
            <p class="hint">La même année crée une nouvelle version de la politique. Les résultats existants conservent leur politique d’origine.</p>
            <template #footer>
                <Button label="Annuler" text severity="secondary" @click="cloneDialog = false" />
                <Button label="Copier" icon="pi pi-copy" :loading="saving" @click="clonePolicy" />
            </template>
        </Dialog>
    </div>
</template>

<style scoped>
.settings-list { display: flex; flex-direction: column; }
.setting-row { display: flex; justify-content: space-between; align-items: center; gap: 20px; padding: 10px 0; border-bottom: 1px solid var(--line); }
.setting-row:last-child { border-bottom: none; }
.setting-label { font-size: 13px; font-weight: 600; }
.setting-input { display: flex; justify-content: flex-end; }
.sub-title { font-size: 13px; margin: 20px 0 8px; text-transform: uppercase; letter-spacing: .05em; color: var(--muted); }
.mb { margin-bottom: 12px; }
</style>
