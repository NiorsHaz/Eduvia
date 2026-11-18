import { reactive } from 'vue';
import { api, setToken, getToken } from '../api';

export const auth = reactive({
    user: null,
    loading: true,
});

export async function fetchMe() {
    if (!getToken()) {
        auth.loading = false;
        return null;
    }
    try {
        auth.user = await api.get('/auth/me');
    } catch (error) {
        auth.user = null;
    } finally {
        auth.loading = false;
    }
    return auth.user;
}

export async function login(identifier, password) {
    const result = await api.post('/auth/login', { identifier, password, device_name: 'web' });
    setToken(result.token);
    auth.user = result.user;
    return result.user;
}

export async function changePassword(currentPassword, password) {
    await api.post('/auth/password', {
        current_password: currentPassword,
        password,
        password_confirmation: password,
    });
    return fetchMe();
}

export async function logout() {
    try {
        await api.post('/auth/logout');
    } catch (error) {
        /* token may already be invalid */
    }
    setToken(null);
    auth.user = null;
}

export function can(permission) {
    if (!auth.user) return false;
    if (auth.user.roles?.includes('super_admin')) return true;
    return (auth.user.permissions || []).includes(permission);
}
