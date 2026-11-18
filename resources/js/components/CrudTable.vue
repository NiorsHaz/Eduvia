<script setup>
import { onMounted, ref, watch } from 'vue';
import DataTable from 'primevue/datatable';
import Column from 'primevue/column';
import Dialog from 'primevue/dialog';
import Button from 'primevue/button';
import InputText from 'primevue/inputtext';
import InputNumber from 'primevue/inputnumber';
import Select from 'primevue/select';
import Checkbox from 'primevue/checkbox';
import Textarea from 'primevue/textarea';
import DatePicker from 'primevue/datepicker';
import Message from 'primevue/message';
import { useToast } from 'primevue/usetoast';
import { api, firstError } from '../api';
import { lookups } from '../stores/lookups';
import { date, money, number, statusLabels } from '../lib/format';
import { className } from '../stores/lookups';

const props = defineProps({
    endpoint: { type: String, required: true },
    basePath: { type: String, default: '/reference/' },
    columns: { type: Array, default: () => [] },
    fields: { type: Array, default: () => [] },
    params: { type: Object, default: () => ({}) },
    title: { type: String, default: '' },
    dialogWidth: { type: String, default: '680px' },
    canCreate: { type: Boolean, default: true },
    canEdit: { type: Boolean, default: true },
    canDelete: { type: Boolean, default: true },
    rowKey: { type: String, default: 'id' },
    perPage: { type: Number, default: 200 },
    searchable: { type: Boolean, default: true },
    clickable: { type: Boolean, default: false },
    emptyText: { type: String, default: 'Aucune donnée' },
});

const emit = defineEmits(['saved', 'row-click']);
const toast = useToast();

const rows = ref([]);
const total = ref(0);
const loading = ref(false);
const search = ref('');
const dialog = ref(false);
const saving = ref(false);
const editing = ref(null);
const error = ref('');
const form = ref({});

function optionsFor(field) {
    if (field.options) return field.options;
    if (field.optionsFrom === 'staff') {
        return (lookups.staff || []).map((item) => ({ label: `${item.first_name} ${item.last_name}`, value: item.id }));
    }
    if (field.optionsFrom === 'classes') {
        return (lookups.classes || []).map((item) => ({ label: className(item.id), value: item.id }));
    }
    if (field.optionsFrom) {
        return (lookups[field.optionsFrom] || []).map((item) => ({
            label: field.optionLabel ? item[field.optionLabel] : item.name || item.label || item.code,
            value: item.id ?? item.code,
        }));
    }
    return [];
}

function labelFor(field, value) {
    const option = optionsFor(field).find((o) => o.value === value);
    return option?.label ?? value ?? '—';
}

async function load() {
    loading.value = true;
    try {
        const response = await api.get(props.basePath + props.endpoint, {
            per_page: props.perPage,
            q: search.value || undefined,
            ...props.params,
        });
        rows.value = Array.isArray(response) ? response : response.data || [];
        total.value = Array.isArray(response) ? response.length : response.total ?? rows.value.length;
    } catch (e) {
        toast.add({ severity: 'error', summary: 'Erreur', detail: firstError(e), life: 4000 });
    } finally {
        loading.value = false;
    }
}

function defaultForm() {
    const blank = {};
    props.fields.forEach((field) => {
        blank[field.field] = field.default !== undefined ? field.default : field.type === 'checkbox' ? false : null;
    });
    return blank;
}

function openNew() {
    editing.value = null;
    error.value = '';
    form.value = defaultForm();
    dialog.value = true;
}

function openEdit(row) {
    editing.value = row;
    error.value = '';
    const values = {};
    props.fields.forEach((field) => {
        let value = row[field.field];
        if (field.type === 'date' && value) value = new Date(value);
        if (field.type === 'checkbox') value = !!value;
        values[field.field] = value ?? (field.type === 'checkbox' ? false : null);
    });
    form.value = values;
    dialog.value = true;
}

function payload() {
    const data = {};
    props.fields.forEach((field) => {
        let value = form.value[field.field];
        if (field.type === 'date' && value instanceof Date) {
            value = value.toISOString().slice(0, 10);
        }
        if (field.type === 'number' && value !== null && value !== undefined && value !== '') value = Number(value);
        data[field.field] = value;
    });
    return data;
}

async function save() {
    error.value = '';
    saving.value = true;
    try {
        if (editing.value) {
            await api.put(`${props.basePath}${props.endpoint}/${editing.value[props.rowKey]}`, payload());
        } else {
            await api.post(props.basePath + props.endpoint, payload());
        }
        toast.add({ severity: 'success', summary: 'Enregistré', life: 2000 });
        dialog.value = false;
        await load();
        emit('saved');
    } catch (e) {
        error.value = firstError(e);
    } finally {
        saving.value = false;
    }
}

async function remove(row) {
    if (!window.confirm('Confirmer la suppression de cet enregistrement ?')) return;
    try {
        await api.delete(`${props.basePath}${props.endpoint}/${row[props.rowKey]}`);
        toast.add({ severity: 'success', summary: 'Supprimé', life: 2000 });
        await load();
        emit('saved');
    } catch (e) {
        toast.add({ severity: 'error', summary: 'Suppression impossible', detail: firstError(e), life: 5000 });
    }
}

function cellValue(row, col) {
    if (col.compute) return col.compute(row);
    return row[col.field];
}

function onRowClick(event) {
    if (props.clickable) emit('row-click', event.data);
}

onMounted(load);
watch(() => props.params, load, { deep: true });
defineExpose({ load, openNew });
</script>

<template>
    <div class="card">
        <div class="toolbar">
            <InputText v-if="searchable" v-model="search" placeholder="Rechercher…" class="w-search" @keyup.enter="load" />
            <Button v-if="searchable" icon="pi pi-search" text rounded aria-label="Rechercher" @click="load" />
            <div class="grow"></div>
            <slot name="filters"></slot>
            <slot name="actions"></slot>
            <Button v-if="canCreate" label="Nouveau" icon="pi pi-plus" size="small" @click="openNew" />
        </div>

        <DataTable
            :value="rows"
            :loading="loading"
            dataKey="id"
            size="small"
            stripedRows
            paginator
            :rows="15"
            :rowsPerPageOptions="[15, 30, 60]"
            :emptyMessage="emptyText"
            scrollable
            @row-click="onRowClick"
            :rowClass="clickable ? () => 'clickable-row' : ''"
        >
            <Column v-for="col in columns" :key="col.field" :header="col.header" :style="col.style">
                <template #body="{ data }">
                    <span v-if="col.type === 'money'" class="mono">{{ money(cellValue(data, col)) }}</span>
                    <span v-else-if="col.type === 'number'" class="mono">{{ number(cellValue(data, col), col.digits ?? 2) }}</span>
                    <span v-else-if="col.type === 'date'">{{ date(cellValue(data, col)) }}</span>
                    <span v-else-if="col.type === 'badge'" class="badge gray">{{ cellValue(data, col) }}</span>
                    <span v-else-if="col.type === 'boolean'">{{ cellValue(data, col) ? 'Oui' : 'Non' }}</span>
                    <span v-else-if="col.type === 'select'">{{ labelFor(col, cellValue(data, col)) }}</span>
                    <span v-else-if="col.type === 'status'" class="badge gray">{{ statusLabels[cellValue(data, col)] || cellValue(data, col) }}</span>
                    <span v-else>{{ cellValue(data, col) ?? '—' }}</span>
                </template>
            </Column>
            <Column header="" style="width: 96px">
                <template #body="{ data }">
                    <div class="flex gap-2">
                        <Button v-if="canEdit" icon="pi pi-pencil" text rounded size="small" aria-label="Modifier" @click.stop="openEdit(data)" />
                        <Button v-if="canDelete" icon="pi pi-trash" text rounded size="small" severity="danger" aria-label="Supprimer" @click.stop="remove(data)" />
                        <slot name="row-actions" :row="data"></slot>
                    </div>
                </template>
            </Column>
        </DataTable>
    </div>

    <Dialog v-model:visible="dialog" modal :style="{ width: dialogWidth }" :header="editing ? 'Modifier' : 'Nouvel enregistrement'">
        <Message v-if="error" severity="error" :closable="false" class="mb">{{ error }}</Message>
        <div class="form-grid">
            <div v-for="field in fields" :key="field.field" class="field" :class="{ 'span-2': field.span === 2 }">
                <label>{{ field.label }}<span v-if="field.required"> *</span></label>

                <InputText v-if="!field.type || field.type === 'text'" v-model="form[field.field]" />
                <InputText v-else-if="field.type === 'email'" v-model="form[field.field]" type="email" />
                <Textarea v-else-if="field.type === 'textarea'" v-model="form[field.field]" rows="3" autoResize />
                <InputNumber v-else-if="field.type === 'number'" v-model="form[field.field]" :min="field.min ?? 0" :maxFractionDigits="field.digits ?? 0" fluid />
                <DatePicker v-else-if="field.type === 'date'" v-model="form[field.field]" dateFormat="dd/mm/yy" showIcon fluid />
                <Select v-else-if="field.type === 'select'" v-model="form[field.field]" :options="optionsFor(field)" optionLabel="label" optionValue="value" showClear fluid :placeholder="field.placeholder || 'Sélectionner'" />
                <div v-else-if="field.type === 'checkbox'" class="flex items-center gap-2 checkbox-line">
                    <Checkbox v-model="form[field.field]" binary :inputId="'chk-' + field.field" />
                    <label :for="'chk-' + field.field" class="small">{{ field.hint || 'Oui' }}</label>
                </div>
                <span v-if="field.hint && field.type !== 'checkbox'" class="hint">{{ field.hint }}</span>
            </div>
        </div>
        <template #footer>
            <Button label="Annuler" text severity="secondary" @click="dialog = false" />
            <Button label="Enregistrer" icon="pi pi-check" :loading="saving" @click="save" />
        </template>
    </Dialog>
</template>

<style scoped>
.w-search { min-width: 240px; }
.mb { margin-bottom: 12px; }
.checkbox-line { height: 38px; }
:deep(.clickable-row) { cursor: pointer; }
</style>
