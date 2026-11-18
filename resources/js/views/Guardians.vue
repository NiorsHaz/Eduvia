<script setup>
import CrudTable from '../components/CrudTable.vue';
import { can } from '../stores/auth';

const columns = [
    { field: 'last_name', header: 'Nom' },
    { field: 'first_name', header: 'Prénom' },
    { field: 'phone', header: 'Téléphone' },
    { field: 'email', header: 'Email' },
    { field: 'students_count', header: 'Élèves liés', type: 'number', digits: 0 },
];

const fields = [
    { field: 'last_name', label: 'Nom', required: true },
    { field: 'first_name', label: 'Prénom', required: true },
    { field: 'phone', label: 'Téléphone' },
    { field: 'phone_alt', label: 'Téléphone secondaire' },
    { field: 'email', label: 'Email', type: 'email' },
    { field: 'occupation', label: 'Profession' },
    { field: 'national_id', label: 'Pièce d’identité' },
    { field: 'address', label: 'Adresse', type: 'textarea', span: 2 },
];
</script>

<template>
    <div class="page">
        <div class="page-head">
            <div>
                <h1>Tuteurs</h1>
                <p>Un accès au portail exige un lien vérifié et non révoqué. Les droits académiques et financiers sont distincts.</p>
            </div>
        </div>

        <CrudTable
            endpoint="guardians"
            base-path="/"
            :columns="columns"
            :fields="fields"
            :can-create="can('guardians.manage')"
            :can-edit="can('guardians.manage')"
            :can-delete="can('guardians.manage')"
            empty-text="Aucun tuteur enregistré"
        >
            <template #row-actions="{ row }">
                <span class="badge gray" v-if="!row.students_count">Sans élève</span>
            </template>
        </CrudTable>
    </div>
</template>
