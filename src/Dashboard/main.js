import { startDashboard } from './dashboard/app.js';
import { initializeDashboardSession } from './dashboard/auth/session.js';

document.addEventListener('DOMContentLoaded', () => {
    void initializeDashboardSession(startDashboard);
});
