import axios from 'axios';

window.axios = axios;
window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';

window.axios.interceptors.response.use(
    (response) => response,
    (error) => {
        if (error.response?.status === 429) {
            const retry = error.response.headers?.['retry-after'];
            const bodyMessage = error.response.data?.message;
            const message = typeof bodyMessage === 'string' && bodyMessage !== ''
                ? bodyMessage
                : (retry
                    ? `Too many requests. Please wait ${retry} seconds and try again.`
                    : 'Too many requests. Please wait a moment and try again.');

            window.dispatchEvent(new CustomEvent('erp:rate-limited', { detail: { message, retry } }));
        }

        return Promise.reject(error);
    },
);
