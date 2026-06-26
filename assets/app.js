import './styles/app.css';
import { createApp } from 'vue';
import Dashboard from './Dashboard.vue';

const el = document.getElementById('app');

createApp(Dashboard, {
    initialState: JSON.parse(el.dataset.initialState),
}).mount(el);
