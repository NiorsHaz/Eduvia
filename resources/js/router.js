import { createRouter, createWebHistory } from 'vue-router';
import { auth, fetchMe, can } from './stores/auth';
import { getToken } from './api';

const routes = [
    { path: '/login', name: 'login', component: () => import('./views/Login.vue'), meta: { public: true } },
    {
        path: '/',
        component: () => import('./layout/AppLayout.vue'),
        children: [
            { path: '', name: 'dashboard', component: () => import('./views/Dashboard.vue'), meta: { permission: 'dashboard.view' } },
            { path: 'students', name: 'students', component: () => import('./views/Students.vue'), meta: { permission: 'students.view' } },
            { path: 'students/:id', name: 'student', component: () => import('./views/StudentDetail.vue'), meta: { permission: 'students.view' } },
            { path: 'guardians', name: 'guardians', component: () => import('./views/Guardians.vue'), meta: { permission: 'guardians.view' } },
            { path: 'staff', name: 'staff', component: () => import('./views/Staff.vue'), meta: { permission: 'staff.view' } },
            { path: 'structure', name: 'structure', component: () => import('./views/Structure.vue'), meta: { permission: 'academics.view' } },
            { path: 'timetable', name: 'timetable', component: () => import('./views/Timetable.vue'), meta: { permission: 'timetable.view' } },
            { path: 'attendance', name: 'attendance', component: () => import('./views/Attendance.vue'), meta: { permission: 'attendance.view' } },
            { path: 'gradebook', name: 'gradebook', component: () => import('./views/Gradebook.vue'), meta: { permission: 'grading.view' } },
            { path: 'results', name: 'results', component: () => import('./views/Results.vue'), meta: { permission: 'results.view' } },
            { path: 'report-cards', name: 'reportcards', component: () => import('./views/ReportCards.vue'), meta: { permission: 'results.view' } },
            { path: 'finance', name: 'finance', component: () => import('./views/Finance.vue'), meta: { permission: 'finance.view' } },
            { path: 'settings', name: 'settings', component: () => import('./views/Settings.vue'), meta: { permission: 'settings.view' } },
            { path: 'users', name: 'users', component: () => import('./views/Users.vue'), meta: { permission: 'users.manage' } },
            { path: 'audit', name: 'audit', component: () => import('./views/Audit.vue'), meta: { permission: 'audit.view' } },
        ],
    },
    { path: '/:pathMatch(.*)*', redirect: '/' },
];

const router = createRouter({
    history: createWebHistory(),
    routes,
});

router.beforeEach(async (to) => {
    if (to.meta.public) return true;

    if (!getToken()) return { name: 'login' };

    if (!auth.user) await fetchMe();
    if (!auth.user) return { name: 'login' };

    if (to.meta.permission && !can(to.meta.permission)) {
        return { name: 'dashboard' };
    }

    return true;
});

export default router;
