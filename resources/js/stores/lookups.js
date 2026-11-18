import { reactive } from 'vue';
import { api } from '../api';

export const lookups = reactive({
    loaded: false,
    levels: [],
    tracks: [],
    subjects: [],
    classes: [],
    years: [],
    terms: [],
    staff: [],
    feeCategories: [],
    studentCategories: [],
    paymentMethods: [],
    rooms: [],
    buildings: [],
    departments: [],
    assessmentTypes: [],
    discountPrograms: [],
    expenseCategories: [],
    roles: [],
    gradingScales: [],
});

let pending = null;

export async function loadLookups() {
    if (lookups.loaded) return lookups;
    if (pending) return pending;

    pending = (async () => {
        const list = (resource, params = { per_page: 500 }) =>
            api.get(`/reference/${resource}`, params).then((r) => r.data ?? r).catch(() => []);

        const [levels, tracks, subjects, years, staff, feeCategories, studentCategories, paymentMethods, rooms, buildings, departments, assessmentTypes, discountPrograms, expenseCategories, gradingScales] =
            await Promise.all([
                list('levels'),
                list('tracks'),
                list('subjects'),
                list('academic-years'),
                list('staff'),
                list('fee-categories'),
                list('student-categories'),
                list('payment-methods'),
                list('rooms'),
                list('buildings'),
                list('departments'),
                list('assessment-types'),
                list('discounts'),
                list('expense-categories'),
                list('grading-scales'),
            ]);

        Object.assign(lookups, {
            levels,
            tracks,
            subjects,
            years,
            staff,
            feeCategories,
            studentCategories,
            paymentMethods,
            rooms,
            buildings,
            departments,
            assessmentTypes,
            discountPrograms,
            expenseCategories,
            gradingScales,
        });

        const current = years.find((y) => y.is_current) || years[0];
        if (current) {
            const [terms, classes] = await Promise.all([
                list('terms', { per_page: 100, academic_year_id: current.id }),
                api.get('/classes', { academic_year_id: current.id }).catch(() => []),
            ]);
            lookups.terms = terms;
            lookups.classes = classes;
        }

        lookups.loaded = true;
        return lookups;
    })();

    return pending;
}

export function resetLookups() {
    lookups.loaded = false;
    pending = null;
}

export const currentYear = () => lookups.years.find((y) => y.is_current) || lookups.years[0] || null;

export const levelLabel = (id) => lookups.levels.find((l) => l.id === id)?.name || '';
export const subjectLabel = (id) => lookups.subjects.find((s) => s.id === id)?.name || '';
export const className = (id) => {
    const classroom = lookups.classes.find((c) => c.id === id);
    if (!classroom) return '';
    const level = lookups.levels.find((l) => l.id === classroom.level_id);
    return `${level?.code || ''} ${classroom.name}`.trim();
};
export const staffName = (id) => {
    const member = lookups.staff.find((s) => s.id === id);
    return member ? `${member.first_name} ${member.last_name}` : '';
};
