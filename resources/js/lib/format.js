export function money(value) {
    const amount = Number(value || 0);
    return new Intl.NumberFormat('fr-MG', { maximumFractionDigits: 0 }).format(amount) + ' Ar';
}

export function number(value, digits = 2) {
    if (value === null || value === undefined || value === '') return '—';
    return new Intl.NumberFormat('fr-FR', { minimumFractionDigits: digits, maximumFractionDigits: digits }).format(Number(value));
}

export function date(value) {
    if (!value) return '—';
    return new Date(value).toLocaleDateString('fr-FR', { day: '2-digit', month: 'short', year: 'numeric' });
}

export function dateTime(value) {
    if (!value) return '—';
    return new Date(value).toLocaleString('fr-FR', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' });
}

export function percent(value) {
    return `${number(value, 0)} %`;
}

export const attendanceLabels = {
    present: 'Présent',
    absent: 'Absent',
    late: 'Retard',
    excused: 'Justifié',
    left_early: 'Parti tôt',
};

export const decisionLabels = {
    pending: 'En attente',
    promoted: 'Promu',
    repeat: 'Redouble',
    conditional: 'Conditionnel',
    graduated: 'Diplômé',
    left: 'Sorti',
    council_review: 'Conseil de classe',
};

export const genderLabels = { female: 'Féminin', male: 'Masculin', other: 'Autre' };

export const statusLabels = {
    enrolled: 'Inscrit', applicant: 'Candidat', suspended: 'Suspendu', withdrawn: 'Retiré',
    transferred_out: 'Transféré', graduated: 'Diplômé', alumni: 'Ancien',
    active: 'Actif', inactive: 'Inactif', closed: 'Clôturé',
    pending: 'En attente', verified: 'Vérifié', rejected: 'Rejeté', reversed: 'Annulé',
    open: 'Ouvert', draft: 'Brouillon', published: 'Publié', validated: 'Validé',
    submitted: 'Soumis', cancelled: 'Annulé', issued: 'Émis', voided: 'Annulé',
    requested: 'Demandé', approved: 'Approuvé', paid: 'Payé', partially_paid: 'Partiellement payé',
    planned: 'Planifié', draft: 'Brouillon', locked: 'Verrouillé', archived: 'Archivé',
};

export const optionLabels = {
    daily: 'Quotidien', per_lesson: 'Par cours',
    first_absence: 'Première absence', two_consecutive: 'Deux consécutives', never: 'Jamais',
    on_entry: 'À la saisie', after_validation: 'Après validation', on_report_card_only: 'Sur le bulletin uniquement',
    oldest_due_first: 'Plus ancienne échéance d’abord', by_category_priority: 'Priorité par catégorie', manual: 'Manuel',
    stack: 'Cumulables', best_only: 'Meilleure seulement',
    promoted: 'Promu', repeat: 'Redouble', conditional: 'Conditionnel', council_review: 'Conseil de classe',
    graduated: 'Diplômé', left: 'Sorti', transferred_out: 'Transféré',
    ignore: 'Ignorer', zero: 'Compter zéro', zero_if_unexcused: 'Zéro si non justifiée',
    block_validation: 'Bloquer la validation', weighted: 'Pondérée', simple_mean: 'Moyenne simple',
    best_n: 'Meilleures N', drop_lowest: 'Retirer la plus basse',
    coefficient_weighted: 'Pondérée par coefficient', weighted_terms: 'Trimestres pondérés',
    last_term_only: 'Dernier trimestre', half_up: 'Demi vers le haut', down: 'Vers le bas', up: 'Vers le haut',
};

export function humanize(value) {
    if (value === null || value === undefined || value === '') return '—';
    if (optionLabels[value]) return optionLabels[value];
    return String(value).replace(/_/g, ' ').replace(/^./, (c) => c.toUpperCase());
}
