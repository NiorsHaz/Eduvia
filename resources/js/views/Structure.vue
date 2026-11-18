<script setup>
import { computed, ref } from 'vue';
import Select from 'primevue/select';
import CrudTable from '../components/CrudTable.vue';
import { can } from '../stores/auth';
import { lookups } from '../stores/lookups';

const active = ref('academic-years');

const resource = [
    {
        key: 'academic-years', label: 'Années académiques', icon: 'pi pi-calendar',
        columns: [
            { field: 'name', header: 'Année' },
            { field: 'start_date', header: 'Début', type: 'date' },
            { field: 'end_date', header: 'Fin', type: 'date' },
            { field: 'status', header: 'Statut', type: 'status' },
            { field: 'is_current', header: 'En cours', type: 'boolean' },
        ],
        fields: [
            { field: 'name', label: 'Nom (ex. 2026-2027)', required: true },
            { field: 'start_date', label: 'Début', type: 'date', required: true },
            { field: 'end_date', label: 'Fin', type: 'date', required: true },
            { field: 'status', label: 'Statut', type: 'select', default: 'planned', options: [
                { label: 'Planifiée', value: 'planned' }, { label: 'Active', value: 'active' },
                { label: 'Clôturée', value: 'closed' }, { label: 'Archivée', value: 'archived' },
            ] },
            { field: 'is_current', label: 'Année en cours', type: 'checkbox', hint: 'Une seule année peut être courante.' },
        ],
    },
    {
        key: 'terms', label: 'Trimestres', icon: 'pi pi-list',
        columns: [
            { field: 'academic_year_id', header: 'Année', type: 'select', optionsFrom: 'years', optionLabel: 'name' },
            { field: 'name', header: 'Trimestre' },
            { field: 'sequence', header: 'N°', type: 'number' },
            { field: 'start_date', header: 'Début', type: 'date' },
            { field: 'end_date', header: 'Fin', type: 'date' },
            { field: 'weight', header: 'Poids', type: 'number' },
            { field: 'data_entry_open', header: 'Saisie', type: 'boolean' },
        ],
        fields: [
            { field: 'academic_year_id', label: 'Année', type: 'select', optionsFrom: 'years', optionLabel: 'name', required: true },
            { field: 'name', label: 'Nom', required: true },
            { field: 'sequence', label: 'Ordre', type: 'number', min: 1, required: true },
            { field: 'start_date', label: 'Début', type: 'date', required: true },
            { field: 'end_date', label: 'Fin', type: 'date', required: true },
            { field: 'weight', label: 'Poids dans la moyenne annuelle', type: 'number', digits: 2, default: 1 },
            { field: 'data_entry_open', label: 'Saisie ouverte', type: 'checkbox' },
        ],
    },
    {
        key: 'levels', label: 'Niveaux', icon: 'pi pi-sort-amount-up',
        columns: [
            { field: 'code', header: 'Code' }, { field: 'name', header: 'Niveau' },
            { field: 'cycle', header: 'Cycle' }, { field: 'sort_order', header: 'Ordre', type: 'number' },
        ],
        fields: [
            { field: 'code', label: 'Code', required: true },
            { field: 'name', label: 'Nom', required: true },
            { field: 'cycle', label: 'Cycle' },
            { field: 'sort_order', label: 'Ordre', type: 'number' },
        ],
    },
    {
        key: 'tracks', label: 'Filières', icon: 'pi pi-share-alt',
        columns: [{ field: 'code', header: 'Code' }, { field: 'name', header: 'Filière' }],
        fields: [{ field: 'code', label: 'Code', required: true }, { field: 'name', label: 'Nom', required: true }],
    },
    {
        key: 'subjects', label: 'Matières', icon: 'pi pi-book',
        columns: [
            { field: 'code', header: 'Code' }, { field: 'name', header: 'Matière' },
            { field: 'is_optional', header: 'Optionnelle', type: 'boolean' },
            { field: 'is_active', header: 'Active', type: 'boolean' },
        ],
        fields: [
            { field: 'code', label: 'Code', required: true }, { field: 'name', label: 'Nom', required: true },
            { field: 'is_optional', label: 'Matière optionnelle', type: 'checkbox' },
            { field: 'is_active', label: 'Active', type: 'checkbox', default: true },
        ],
    },
    {
        key: 'buildings', label: 'Bâtiments', icon: 'pi pi-building',
        columns: [
            { field: 'name', header: 'Bâtiment' },
            { field: 'campus_id', header: 'Campus', type: 'select', options: [{ label: 'Principal', value: 1 }] },
        ],
        fields: [
            { field: 'name', label: 'Nom', required: true },
            { field: 'campus_id', label: 'Campus', type: 'select', options: [{ label: 'Principal', value: 1 }] },
        ],
    },
    {
        key: 'rooms', label: 'Salles', icon: 'pi pi-home',
        columns: [
            { field: 'name', header: 'Salle' },
            { field: 'building_id', header: 'Bâtiment', type: 'select', optionsFrom: 'buildings', optionLabel: 'name' },
            { field: 'room_type', header: 'Type' },
            { field: 'capacity', header: 'Capacité', type: 'number' },
            { field: 'is_active', header: 'Active', type: 'boolean' },
        ],
        fields: [
            { field: 'name', label: 'Nom', required: true },
            { field: 'building_id', label: 'Bâtiment', type: 'select', optionsFrom: 'buildings', optionLabel: 'name' },
            { field: 'room_type', label: 'Type', type: 'select', default: 'classroom', options: [
                { label: 'Classe', value: 'classroom' }, { label: 'Laboratoire', value: 'laboratory' },
                { label: 'Bibliothèque', value: 'library' }, { label: 'Salle polyvalente', value: 'hall' },
                { label: 'Sport', value: 'sports' }, { label: 'Bureau', value: 'office' },
            ] },
            { field: 'capacity', label: 'Capacité', type: 'number' },
            { field: 'is_active', label: 'Active', type: 'checkbox', default: true },
        ],
    },
    {
        key: 'departments', label: 'Départements', icon: 'pi pi-sitemap',
        columns: [
            { field: 'name', header: 'Département' },
            { field: 'head_staff_id', header: 'Responsable', type: 'select', optionsFrom: 'staff' },
        ],
        fields: [
            { field: 'name', label: 'Nom', required: true },
            { field: 'head_staff_id', label: 'Responsable', type: 'select', optionsFrom: 'staff' },
        ],
    },
    {
        key: 'staff', label: 'Personnel', icon: 'pi pi-user',
        columns: [
            { field: 'employee_number', header: 'Matricule' },
            { field: 'last_name', header: 'Nom' },
            { field: 'first_name', header: 'Prénom' },
            { field: 'position', header: 'Fonction' },
            { field: 'staff_type', header: 'Type' },
            { field: 'employment_status', header: 'Statut' },
            { field: 'phone', header: 'Téléphone' },
        ],
        fields: [
            { field: 'employee_number', label: 'Matricule', required: true },
            { field: 'last_name', label: 'Nom', required: true },
            { field: 'first_name', label: 'Prénom', required: true },
            { field: 'position', label: 'Fonction', required: true },
            { field: 'staff_type', label: 'Type', type: 'select', default: 'teacher', options: [
                { label: 'Enseignant', value: 'teacher' }, { label: 'Administratif', value: 'administrative' },
                { label: 'Finance', value: 'finance' }, { label: 'Bibliothèque', value: 'library' },
                { label: 'Support', value: 'support' }, { label: 'Autre', value: 'other' },
            ] },
            { field: 'employment_status', label: 'Statut d’emploi', type: 'select', default: 'active', options: [
                { label: 'Actif', value: 'active' }, { label: 'En congé', value: 'on_leave' },
                { label: 'Suspendu', value: 'suspended' }, { label: 'Démission', value: 'resigned' },
                { label: 'Licencié', value: 'terminated' }, { label: 'Retraité', value: 'retired' },
            ] },
            { field: 'department_id', label: 'Département', type: 'select', optionsFrom: 'departments' },
            { field: 'gender', label: 'Genre', type: 'select', options: [{ label: 'Féminin', value: 'female' }, { label: 'Masculin', value: 'male' }] },
            { field: 'phone', label: 'Téléphone' },
            { field: 'email', label: 'Email', type: 'email' },
            { field: 'hire_date', label: 'Date d’embauche', type: 'date' },
            { field: 'qualifications', label: 'Qualifications', type: 'textarea', span: 2 },
        ],
    },
    {
        key: 'classes', label: 'Classes', icon: 'pi pi-th-large',
        columns: [
            { field: 'academic_year_id', header: 'Année', type: 'select', optionsFrom: 'years', optionLabel: 'name' },
            { field: 'level_id', header: 'Niveau', type: 'select', optionsFrom: 'levels', optionLabel: 'code' },
            { field: 'name', header: 'Classe' },
            { field: 'track_id', header: 'Filière', type: 'select', optionsFrom: 'tracks', optionLabel: 'code' },
            { field: 'capacity', header: 'Capacité', type: 'number' },
            { field: 'supervisor_staff_id', header: 'Prof. principal', type: 'select', optionsFrom: 'staff' },
        ],
        fields: [
            { field: 'academic_year_id', label: 'Année', type: 'select', optionsFrom: 'years', optionLabel: 'name', required: true },
            { field: 'level_id', label: 'Niveau', type: 'select', optionsFrom: 'levels', optionLabel: 'code', required: true },
            { field: 'name', label: 'Nom de la classe', required: true },
            { field: 'track_id', label: 'Filière', type: 'select', optionsFrom: 'tracks', optionLabel: 'code' },
            { field: 'capacity', label: 'Capacité', type: 'number', default: 40 },
            { field: 'supervisor_staff_id', label: 'Professeur principal', type: 'select', optionsFrom: 'staff' },
            { field: 'home_room_id', label: 'Salle principale', type: 'select', optionsFrom: 'rooms', optionLabel: 'name' },
        ],
    },
    {
        key: 'level-subjects', label: 'Coefficients', icon: 'pi pi-percentage',
        columns: [
            { field: 'academic_year_id', header: 'Année', type: 'select', optionsFrom: 'years', optionLabel: 'name' },
            { field: 'level_id', header: 'Niveau', type: 'select', optionsFrom: 'levels', optionLabel: 'code' },
            { field: 'track_id', header: 'Filière', type: 'select', optionsFrom: 'tracks', optionLabel: 'code' },
            { field: 'subject_id', header: 'Matière', type: 'select', optionsFrom: 'subjects', optionLabel: 'name' },
            { field: 'coefficient', header: 'Coefficient', type: 'number' },
            { field: 'weekly_hours', header: 'Heures/sem.', type: 'number' },
        ],
        fields: [
            { field: 'academic_year_id', label: 'Année', type: 'select', optionsFrom: 'years', optionLabel: 'name', required: true },
            { field: 'level_id', label: 'Niveau', type: 'select', optionsFrom: 'levels', optionLabel: 'code', required: true },
            { field: 'track_id', label: 'Filière', type: 'select', optionsFrom: 'tracks', optionLabel: 'code' },
            { field: 'subject_id', label: 'Matière', type: 'select', optionsFrom: 'subjects', optionLabel: 'name', required: true },
            { field: 'coefficient', label: 'Coefficient', type: 'number', digits: 2, default: 1 },
            { field: 'weekly_hours', label: 'Heures par semaine', type: 'number', digits: 1 },
        ],
    },
    {
        key: 'class-subject-teachers', label: 'Affectations', icon: 'pi pi-user-edit',
        columns: [
            { field: 'class_id', header: 'Classe', type: 'select', optionsFrom: 'classes' },
            { field: 'subject_id', header: 'Matière', type: 'select', optionsFrom: 'subjects', optionLabel: 'name' },
            { field: 'staff_id', header: 'Enseignant', type: 'select', optionsFrom: 'staff' },
            { field: 'starts_on', header: 'Depuis', type: 'date' },
        ],
        fields: [
            { field: 'class_id', label: 'Classe', type: 'select', optionsFrom: 'classes', required: true },
            { field: 'subject_id', label: 'Matière', type: 'select', optionsFrom: 'subjects', optionLabel: 'name', required: true },
            { field: 'staff_id', label: 'Enseignant', type: 'select', optionsFrom: 'staff', required: true },
            { field: 'starts_on', label: 'À partir du', type: 'date' },
            { field: 'ends_on', label: 'Jusqu’au', type: 'date' },
        ],
    },
    {
        key: 'calendar-periods', label: 'Calendrier', icon: 'pi pi-calendar-plus',
        columns: [
            { field: 'academic_year_id', header: 'Année', type: 'select', optionsFrom: 'years', optionLabel: 'name' },
            { field: 'type', header: 'Type' },
            { field: 'title', header: 'Titre' },
            { field: 'start_date', header: 'Début', type: 'date' },
            { field: 'end_date', header: 'Fin', type: 'date' },
        ],
        fields: [
            { field: 'academic_year_id', label: 'Année', type: 'select', optionsFrom: 'years', optionLabel: 'name', required: true },
            { field: 'type', label: 'Type', type: 'select', options: [
                { label: 'Vacances', value: 'holiday' }, { label: 'Fermeture', value: 'closure' },
                { label: 'Examens', value: 'exam_window' }, { label: 'Échéance', value: 'deadline' },
                { label: 'Autre', value: 'other' },
            ], required: true },
            { field: 'title', label: 'Titre', required: true, span: 2 },
            { field: 'start_date', label: 'Début', type: 'date', required: true },
            { field: 'end_date', label: 'Fin', type: 'date', required: true },
        ],
    },
];

const config = computed(() => resource.find((r) => r.key === active.value) || resource[0]);
const options = resource.map((r) => ({ label: r.label, value: r.key }));
const writable = computed(() => can(['staff', 'departments'].includes(active.value) ? 'staff.manage' : 'academics.manage'));
</script>

<template>
    <div class="page">
        <div class="page-head">
            <div>
                <h1>Structure académique</h1>
                <p>Années, niveaux, classes, matières et coefficients. Les règles de calcul vivent dans « Règles & paramètres ».</p>
            </div>
            <Select v-model="active" :options="options" optionLabel="label" optionValue="value" style="min-width: 220px" />
        </div>

        <CrudTable
            :key="config.key"
            :endpoint="config.key"
            :columns="config.columns"
            :fields="config.fields"
            :can-create="writable"
            :can-edit="writable"
            :can-delete="writable"
            :dialog-width="'720px'"
            :empty-text="'Aucun enregistrement'"
        />
    </div>
</template>
