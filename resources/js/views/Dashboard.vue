<script setup>
import { computed, onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import Button from 'primevue/button';
import ProgressBar from 'primevue/progressbar';
import { api, firstError } from '../api';
import { money, number, attendanceLabels } from '../lib/format';
import { useToast } from 'primevue/usetoast';

const data = ref(null);
const loading = ref(true);
const toast = useToast();
const router = useRouter();

const attendance = computed(() => {
    const rows = data.value?.attendance_today || {};
    const total = Object.values(rows).reduce((sum, value) => sum + Number(value), 0);
    return { rows, total };
});

const outstandingRatio = computed(() => {
    const finance = data.value?.finance;
    if (!finance || !finance.billed) return 0;
    return Math.min(100, Math.round((finance.outstanding / finance.billed) * 100));
});

onMounted(async () => {
    try {
        data.value = await api.get('/dashboard');
    } catch (e) {
        toast.add({ severity: 'error', summary: 'Erreur', detail: firstError(e), life: 5000 });
    } finally {
        loading.value = false;
    }
});
</script>

<template>
    <div class="page">
        <div class="page-head">
            <div>
                <h1>Tableau de bord</h1>
                <p>{{ data?.year?.name }} · {{ data?.term?.name || 'Aucun trimestre ouvert' }} — vue consolidée de l’établissement.</p>
            </div>
            <div class="flex gap-2">
                <Button label="Saisir les présences" icon="pi pi-calendar-clock" size="small" outlined @click="router.push({ name: 'attendance' })" />
                <Button label="Nouvel élève" icon="pi pi-plus" size="small" @click="router.push({ name: 'students' })" />
            </div>
        </div>

        <div class="grid cols-4">
            <div class="card">
                <div class="stat-label">Élèves inscrits</div>
                <div class="stat-value">{{ data?.counts?.students ?? '—' }}</div>
                <div class="stat-note">{{ data?.counts?.classes ?? 0 }} classes · {{ data?.counts?.staff ?? 0 }} membres du personnel</div>
            </div>
            <div class="card">
                <div class="stat-label">Présences aujourd’hui</div>
                <div class="stat-value">{{ attendance.total }}</div>
                <div class="stat-note">
                    <span v-for="(value, key) in attendance.rows" :key="key" class="pill">{{ attendanceLabels[key] || key }} {{ value }}</span>
                    <span v-if="!attendance.total" class="muted">Aucun appel enregistré</span>
                </div>
            </div>
            <div class="card">
                <div class="stat-label">Reste à percevoir</div>
                <div class="stat-value">{{ money(data?.finance?.outstanding) }}</div>
                <ProgressBar :value="outstandingRatio" :showValue="false" style="height: 6px; margin-top: 10px" />
                <div class="stat-note">{{ outstandingRatio }} % du facturé · {{ money(data?.finance?.collected_this_month) }} ce mois</div>
            </div>
            <div class="card">
                <div class="stat-label">À traiter</div>
                <div class="stat-value">{{ (data?.pending_validation ?? 0) + (data?.pending_promotions ?? 0) }}</div>
                <div class="stat-note">{{ data?.pending_validation ?? 0 }} évaluations ouvertes · {{ data?.pending_promotions ?? 0 }} décisions en attente</div>
            </div>
        </div>

        <div class="grid cols-2" style="margin-top: 14px">
            <div class="card">
                <div class="flex justify-between items-center" style="margin-bottom: 12px">
                    <h3 class="section-title" style="margin: 0">Effectifs par niveau</h3>
                    <Button label="Structure" text size="small" @click="router.push({ name: 'structure' })" />
                </div>
                <div v-if="data?.enrollment_by_level?.length" class="bars">
                    <div v-for="row in data.enrollment_by_level" :key="row.level" class="bar-row">
                        <span class="bar-label">{{ row.level }}</span>
                        <div class="bar-track">
                            <div class="bar-fill" :style="{ width: Math.min(100, (row.total / Math.max(...data.enrollment_by_level.map((r) => r.total))) * 100) + '%' }"></div>
                        </div>
                        <span class="bar-value mono">{{ row.total }}</span>
                    </div>
                </div>
                <div v-else class="empty">Aucune inscription pour l’année en cours.</div>
            </div>

            <div class="card">
                <div class="flex justify-between items-center" style="margin-bottom: 12px">
                    <h3 class="section-title" style="margin: 0">Activité récente</h3>
                    <Button label="Journal" text size="small" @click="router.push({ name: 'audit' })" />
                </div>
                <div v-if="data?.recent_activity?.length" class="activity">
                    <div v-for="item in data.recent_activity" :key="item.id" class="activity-row">
                        <div class="activity-dot"></div>
                        <div class="grow">
                            <div class="small"><strong>{{ item.causer || 'Système' }}</strong> — {{ item.description }}</div>
                            <div class="hint">{{ item.at }}</div>
                        </div>
                    </div>
                </div>
                <div v-else class="empty">Aucune activité enregistrée.</div>
            </div>
        </div>

        <div class="grid cols-3" style="margin-top: 14px">
            <div class="card quick" @click="router.push({ name: 'gradebook' })">
                <i class="pi pi-pencil"></i>
                <div>
                    <strong>Saisie des notes</strong>
                    <div class="hint">Évaluations, verrouillage et validation par classe.</div>
                </div>
            </div>
            <div class="card quick" @click="router.push({ name: 'finance' })">
                <i class="pi pi-wallet"></i>
                <div>
                    <strong>Encaisser un paiement</strong>
                    <div class="hint">{{ data?.finance?.pending_verification ?? 0 }} paiement(s) en attente de vérification.</div>
                </div>
            </div>
            <div class="card quick" @click="router.push({ name: 'settings' })">
                <i class="pi pi-sliders-h"></i>
                <div>
                    <strong>Règles de l’école</strong>
                    <div class="hint">Barème, promotion, frais et numérotation.</div>
                </div>
            </div>
        </div>
    </div>
</template>

<style scoped>
.pill { display: inline-block; margin-right: 6px; padding: 1px 6px; border-radius: 5px; background: #f1f5f2; margin-top: 4px; }
.bars { display: flex; flex-direction: column; gap: 9px; }
.bar-row { display: grid; grid-template-columns: 82px 1fr 34px; gap: 10px; align-items: center; font-size: 12px; }
.bar-label { color: var(--muted); }
.bar-track { height: 8px; background: #eef3ef; border-radius: 4px; overflow: hidden; }
.bar-fill { height: 100%; background: linear-gradient(90deg, #2f7d57, #62ab84); border-radius: 4px; }
.bar-value { text-align: right; }
.activity { display: flex; flex-direction: column; gap: 12px; }
.activity-row { display: flex; gap: 10px; align-items: flex-start; }
.activity-dot { width: 7px; height: 7px; border-radius: 50%; background: #7fb79b; margin-top: 6px; }
.quick { display: flex; gap: 12px; align-items: center; cursor: pointer; }
.quick:hover { border-color: #b9cfc2; }
.quick .pi { font-size: 18px; color: var(--brand); background: var(--brand-soft); padding: 10px; border-radius: 10px; }
</style>
