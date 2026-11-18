import { createApp } from 'vue';
import PrimeVue from 'primevue/config';
import Aura from '@primevue/themes/aura';
import ToastService from 'primevue/toastservice';
import ConfirmationService from 'primevue/confirmationservice';

import App from './App.vue';
import router from './router';
import { i18n } from './i18n';
import 'primeicons/primeicons.css';
import '../css/app.css';

const app = createApp(App);

app.use(router);
app.use(i18n);
app.use(PrimeVue, {
    ripple: true,
    theme: {
        preset: Aura,
        options: {
            darkModeSelector: 'none',
            cssLayer: false,
            prefix: 'p',
        },
    },
});
app.use(ToastService);
app.use(ConfirmationService);

app.mount('#app');
