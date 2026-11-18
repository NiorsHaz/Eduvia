import { createI18n } from 'vue-i18n';

const fr = {
    appName: 'Gestion scolaire',
    nav: {
        dashboard: 'Tableau de bord',
        students: 'Élèves',
        guardians: 'Tuteurs',
        staff: 'Personnel',
        structure: 'Structure',
        timetable: 'Emploi du temps',
        attendance: 'Présences',
        gradebook: 'Notes',
        results: 'Résultats',
        reportcards: 'Bulletins',
        finance: 'Finances',
        rules: 'Règles & paramètres',
        users: 'Utilisateurs',
        audit: 'Journal',
        groups: { school: 'Vie scolaire', academics: 'Pédagogie', admin: 'Administration' },
    },
    common: {
        search: 'Rechercher', new: 'Nouveau', save: 'Enregistrer', cancel: 'Annuler', delete: 'Supprimer',
        edit: 'Modifier', close: 'Fermer', actions: 'Actions', all: 'Tous', none: 'Aucun', loading: 'Chargement…',
        noData: 'Aucune donnée', confirmDelete: 'Confirmer la suppression ?', yes: 'Oui', no: 'Non',
        total: 'Total', details: 'Détails', print: 'Imprimer', export: 'Exporter', filters: 'Filtres',
        language: 'Langue', logout: 'Déconnexion', required: 'Champ obligatoire', saved: 'Enregistré',
    },
    attendance: { present: 'Présent', absent: 'Absent', late: 'Retard', excused: 'Justifié', left_early: 'Parti tôt' },
    finance: {
        billed: 'Facturé', collected: 'Encaissé', outstanding: 'Reste à payer', overdue: 'En retard',
        payments: 'Paiements', charges: 'Frais', receipts: 'Reçus', expenses: 'Dépenses',
    },
    roles: { super_admin: 'Super administrateur', director: 'Directeur', academic_director: 'Directeur académique', admin_staff: 'Administration', finance: 'Comptabilité', teacher: 'Enseignant', guardian: 'Tuteur', student: 'Élève', class_supervisor: 'Professeur principal' },
};

const mg = {
    appName: 'Fitantanana sekoly',
    nav: {
        dashboard: 'Tabilao',
        students: 'Mpianatra',
        guardians: 'Mpiandraikitra',
        staff: 'Mpiasa',
        structure: 'Fandaminana',
        timetable: 'Fandaharam-potoana',
        attendance: 'Fanatrehana',
        gradebook: 'Naoty',
        results: 'Valiny',
        reportcards: 'Taratasy',
        finance: 'Vola',
        rules: 'Fitsipika',
        users: 'Mpampiasa',
        audit: 'Tantarana',
        groups: { school: 'Fiainam-pianarana', academics: 'Fampianarana', admin: 'Fitantanana' },
    },
    common: {
        search: 'Hikaroka', new: 'Vaovao', save: 'Hitahiry', cancel: 'Hanafoana', delete: 'Hamafa',
        edit: 'Hanova', close: 'Hidio', actions: 'Asa', all: 'Rehetra', none: 'Tsy misy', loading: 'Eo am-pakàna…',
        noData: 'Tsy misy angona', confirmDelete: 'Hamafa tokoa?', yes: 'Eny', no: 'Tsia',
        total: 'Fitambarany', details: 'Antsipiriany', print: 'Hanonta', export: 'Hanondrana',
        filters: 'Sivana', language: 'Fiteny', logout: 'Hiala', required: 'Tsy maintsy fenoina', saved: 'Voatahiry',
    },
    attendance: { present: 'Eto', absent: 'Tsy eto', late: 'Tara', excused: 'Nahazo alalana', left_early: 'Niala aloha' },
    finance: { billed: 'Vola tokony aloa', collected: 'Vola voaray', outstanding: 'Sisa aloa', overdue: 'Tara', payments: 'Fandoavana', charges: 'Sarany', receipts: 'Rosià', expenses: 'Fandaniana' },
    roles: { super_admin: 'Mpitantana ambony', director: 'Tale', academic_director: 'Tale mpampianatra', admin_staff: 'Fitantanana', finance: 'Vola', teacher: 'Mpampianatra', guardian: 'Mpiandraikitra', student: 'Mpianatra', class_supervisor: 'Mpampianatra lehibe' },
};

const en = {
    appName: 'School management',
    nav: {
        dashboard: 'Dashboard', students: 'Students', guardians: 'Guardians', structure: 'Structure',
        timetable: 'Timetable', attendance: 'Attendance', gradebook: 'Gradebook', results: 'Results',
        reportcards: 'Report cards', finance: 'Finance', rules: 'Rules & settings', users: 'Users', staff: 'Staff',
        audit: 'Audit log', groups: { school: 'School life', academics: 'Academics', admin: 'Administration' },
    },
    common: {
        search: 'Search', new: 'New', save: 'Save', cancel: 'Cancel', delete: 'Delete', edit: 'Edit',
        close: 'Close', actions: 'Actions', all: 'All', none: 'None', loading: 'Loading…',
        noData: 'No data', confirmDelete: 'Delete this record?', yes: 'Yes', no: 'No', total: 'Total',
        details: 'Details', print: 'Print', export: 'Export', filters: 'Filters', language: 'Language',
        logout: 'Sign out', required: 'Required', saved: 'Saved',
    },
    attendance: { present: 'Present', absent: 'Absent', late: 'Late', excused: 'Excused', left_early: 'Left early' },
    finance: { billed: 'Billed', collected: 'Collected', outstanding: 'Outstanding', overdue: 'Overdue', payments: 'Payments', charges: 'Charges', receipts: 'Receipts', expenses: 'Expenses' },
    roles: { super_admin: 'Super administrator', director: 'Director', academic_director: 'Academic director', admin_staff: 'Admin staff', finance: 'Finance', teacher: 'Teacher', guardian: 'Guardian', student: 'Student', class_supervisor: 'Class supervisor' },
};

export const i18n = createI18n({
    legacy: false,
    locale: localStorage.getItem('school_locale') || 'fr',
    fallbackLocale: 'fr',
    messages: { fr, mg, en },
});

export function setLocale(locale) {
    i18n.global.locale.value = locale;
    localStorage.setItem('school_locale', locale);
}

export const locales = [
    { code: 'fr', label: 'Français' },
    { code: 'mg', label: 'Malagasy' },
    { code: 'en', label: 'English' },
];
