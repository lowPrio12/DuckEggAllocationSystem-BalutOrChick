// Manager Dashboard JavaScript

// PHP data passed from server
const PHP = window.ManagerConfig || {};

// Export Activity Logs function
function exportActivityCSV() {
    window.location.href = '?export_activity=csv';
    showToast('Exporting activity logs...', 'success');
}

// Mobile Menu
function toggleMobileMenu() {
    const sidebar = document.getElementById('sidebar');
    if (sidebar) {
        sidebar.classList.toggle('open');
        const overlay = document.getElementById('sidebarOverlay');
        if (overlay) {
            overlay.style.display = sidebar.classList.contains('open') ? 'block' : 'none';
        }
    }
}

function closeMobileMenu() {
    const sidebar = document.getElementById('sidebar');
    if (sidebar) {
        sidebar.classList.remove('open');
        const overlay = document.getElementById('sidebarOverlay');
        if (overlay) {
            overlay.style.display = 'none';
        }
    }
}

// Close mobile menu on link click
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.sidebar-menu a').forEach(link => {
        link.addEventListener('click', () => {
            if (window.innerWidth <= 768) closeMobileMenu();
        });
    });
});

// Tab navigation
const tabTitles = {
    overview: 'Overview & metrics',
    analytics: 'Production analytics (Users only)',
    reports: 'Generate reports',
};

document.querySelectorAll('.nav-item[data-tab]').forEach(li => {
    li.querySelector('a').addEventListener('click', (e) => {
        e.preventDefault();
        const tab = li.dataset.tab;
        const url = new URL(window.location.href);
        url.searchParams.set('tab', tab);
        window.history.pushState({}, '', url);

        document.querySelectorAll('.nav-item').forEach(x => x.classList.remove('active'));
        li.classList.add('active');
        document.querySelectorAll('.tab-section').forEach(s => s.classList.remove('active'));
        const targetSection = document.getElementById(tab + '-section');
        if (targetSection) targetSection.classList.add('active');

        const subtitleEl = document.getElementById('page-subtitle');
        if (subtitleEl) subtitleEl.textContent = tabTitles[tab] || '';

        if (tab === 'analytics') initCharts();
        if (window.innerWidth <= 768) closeMobileMenu();
    });
});

window.addEventListener('popstate', () => {
    const params = new URLSearchParams(window.location.search);
    const tab = params.get('tab') || 'overview';
    const navItem = document.querySelector(`.nav-item[data-tab="${tab}"]`);
    if (navItem) navItem.classList.add('active');
    document.querySelectorAll('.tab-section').forEach(s => s.classList.remove('active'));
    const targetSection = document.getElementById(tab + '-section');
    if (targetSection) targetSection.classList.add('active');

    const subtitleEl = document.getElementById('page-subtitle');
    if (subtitleEl) subtitleEl.textContent = tabTitles[tab] || '';

    if (tab === 'analytics') initCharts();
});

// Modal functions
function openAddUserModal() {
    const modalTitle = document.getElementById('modalTitle');
    if (modalTitle) modalTitle.innerHTML = '<i class="fas fa-user-plus"></i> Add User';

    const editUserId = document.getElementById('editUserId');
    if (editUserId) editUserId.value = '';

    const modalUsername = document.getElementById('modalUsername');
    if (modalUsername) modalUsername.value = '';

    const modalPassword = document.getElementById('modalPassword');
    if (modalPassword) modalPassword.value = '';

    const modalRole = document.getElementById('modalRole');
    if (modalRole) modalRole.value = 'user';

    const passwordLabel = document.getElementById('passwordLabel');
    if (passwordLabel) passwordLabel.textContent = 'Password';

    const modalPasswordInput = document.getElementById('modalPassword');
    if (modalPasswordInput) modalPasswordInput.required = true;

    const userModal = document.getElementById('userModal');
    if (userModal) userModal.classList.add('active');
}

function openEditModal(id, username, role) {
    const modalTitle = document.getElementById('modalTitle');
    if (modalTitle) modalTitle.innerHTML = '<i class="fas fa-edit"></i> Edit User';

    const editUserId = document.getElementById('editUserId');
    if (editUserId) editUserId.value = id;

    const modalUsername = document.getElementById('modalUsername');
    if (modalUsername) modalUsername.value = username;

    const modalPassword = document.getElementById('modalPassword');
    if (modalPassword) modalPassword.value = '';

    const modalRole = document.getElementById('modalRole');
    if (modalRole) modalRole.value = role;

    const passwordLabel = document.getElementById('passwordLabel');
    if (passwordLabel) passwordLabel.textContent = 'Password (leave blank to keep)';

    const modalPasswordInput = document.getElementById('modalPassword');
    if (modalPasswordInput) modalPasswordInput.required = false;

    const userModal = document.getElementById('userModal');
    if (userModal) userModal.classList.add('active');
}

function closeModal() {
    const userModal = document.getElementById('userModal');
    if (userModal) userModal.classList.remove('active');
}

function saveUser(e) {
    e.preventDefault();
    const id = document.getElementById('editUserId')?.value || '';
    const username = document.getElementById('modalUsername')?.value.trim() || '';
    const password = document.getElementById('modalPassword')?.value || '';
    const role = document.getElementById('modalRole')?.value || 'user';
    const action = id ? 'edit_user' : 'create_user';
    const btn = document.getElementById('saveBtn');

    const body = new FormData();
    body.append('action', action);
    body.append('username', username);
    body.append('password', password);
    body.append('role', role);
    if (id) body.append('user_id', id);

    if (btn) {
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner"></span>';
    }

    fetch(window.location.href, {
        method: 'POST',
        headers: {
            'X-Requested-With': 'XMLHttpRequest'
        },
        body
    })
        .then(r => r.json())
        .then(res => {
            if (btn) {
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-save"></i> Save';
            }
            if (res.success) {
                closeModal();
                showToast(res.message, 'success');
                setTimeout(() => location.reload(), 1000);
            } else {
                showToast(res.message, 'error');
            }
        })
        .catch(err => {
            if (btn) {
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-save"></i> Save';
            }
            showToast('An error occurred', 'error');
        });
}

function deleteUser(id, username) {
    if (!confirm(`Delete "${username}"? This action cannot be undone.`)) return;

    const body = new FormData();
    body.append('action', 'delete_user');
    body.append('user_id', id);

    fetch(window.location.href, {
        method: 'POST',
        headers: {
            'X-Requested-With': 'XMLHttpRequest'
        },
        body
    })
        .then(r => r.json())
        .then(res => {
            if (res.success) {
                showToast(res.message, 'success');
                setTimeout(() => location.reload(), 1000);
            } else {
                showToast(res.message, 'error');
            }
        })
        .catch(err => {
            showToast('An error occurred', 'error');
        });
}

function showToast(msg, type = 'success') {
    const toast = document.getElementById('toast');
    const toastMsg = document.getElementById('toastMsg');
    if (!toast || !toastMsg) return;

    toastMsg.textContent = msg;
    toast.className = 'show ' + type;
    setTimeout(() => toast.className = '', 3000);
}

function generateReport() {
    const typeSelect = document.getElementById('reportType');
    const startDateInput = document.getElementById('startDate');
    const endDateInput = document.getElementById('endDate');

    const type = typeSelect ? typeSelect.value : '';
    const start = startDateInput ? startDateInput.value : '';
    const end = endDateInput ? endDateInput.value : '';

    window.location.href = `?tab=reports&report=${type}&start=${start}&end=${end}`;
}

function exportCSV() {
    const table = document.querySelector('#reportContent table');
    if (!table) {
        showToast('Generate a report first.', 'error');
        return;
    }

    let csv = [];
    const headers = [];

    table.querySelectorAll('thead th').forEach(th => headers.push(th.innerText));
    csv.push(headers.join(','));

    table.querySelectorAll('tbody tr').forEach(tr => {
        const row = [];
        tr.querySelectorAll('td').forEach(td => row.push('"' + td.innerText.replace(/"/g, '""') + '"'));
        csv.push(row.join(','));
    });

    const blob = new Blob(["\uFEFF" + csv.join('\n')], {
        type: 'text/csv;charset=utf-8;'
    });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = `report_${Date.now()}.csv`;
    a.click();
    URL.revokeObjectURL(url);
    showToast('Report exported!', 'success');
}

// Charts
let chartsInitialized = false;
let chartInstances = {};

function initCharts() {
    if (chartsInitialized) return;
    chartsInitialized = true;

    // Destroy existing charts to avoid duplication
    Object.values(chartInstances).forEach(chart => {
        if (chart && typeof chart.destroy === 'function') chart.destroy();
    });
    chartInstances = {};

    // Balut Chart
    const balutCtx = document.getElementById('balutChart');
    if (balutCtx && PHP.balutPerUser && PHP.balutPerUser.length > 0) {
        chartInstances.balutChart = new Chart(balutCtx, {
            type: 'bar',
            data: {
                labels: PHP.balutPerUser.map(r => r.username),
                datasets: [{
                    label: 'Total Balut',
                    data: PHP.balutPerUser.map(r => parseInt(r.total_balut)),
                    backgroundColor: 'rgba(16,185,129,.7)',
                    borderColor: '#10b981',
                    borderWidth: 2,
                    borderRadius: 6
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                plugins: {
                    legend: {
                        display: false
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        grid: {
                            color: '#e2e8f0'
                        }
                    }
                }
            }
        });
    }

    // Trend Chart
    const trendCtx = document.getElementById('trendChart');
    if (trendCtx && PHP.weeklyTrend && PHP.weeklyTrend.length > 0) {
        const labels = PHP.weeklyTrend.map(r => r.day);
        chartInstances.trendChart = new Chart(trendCtx, {
            type: 'line',
            data: {
                labels,
                datasets: [{
                    label: 'Balut',
                    data: PHP.weeklyTrend.map(r => +r.balut),
                    borderColor: '#10b981',
                    backgroundColor: 'rgba(16,185,129,0.1)',
                    fill: true,
                    tension: 0.4
                },
                {
                    label: 'Chicks',
                    data: PHP.weeklyTrend.map(r => +r.chicks),
                    borderColor: '#3b82f6',
                    backgroundColor: 'rgba(59,130,246,0.1)',
                    fill: true,
                    tension: 0.4
                },
                {
                    label: 'Failed',
                    data: PHP.weeklyTrend.map(r => +r.failed),
                    borderColor: '#ef4444',
                    backgroundColor: 'rgba(239,68,68,0.1)',
                    fill: true,
                    tension: 0.4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                scales: {
                    y: {
                        beginAtZero: true,
                        grid: {
                            color: '#e2e8f0'
                        }
                    }
                },
                plugins: {
                    legend: {
                        position: 'top',
                        labels: {
                            font: {
                                size: 10
                            }
                        }
                    }
                }
            }
        });
    }

    // Pie Chart
    const pieCtx = document.getElementById('pieChart');
    if (pieCtx) {
        chartInstances.pieChart = new Chart(pieCtx, {
            type: 'doughnut',
            data: {
                labels: ['Balut', 'Chicks', 'Failed'],
                datasets: [{
                    data: [PHP.totalBalut || 0, PHP.totalChicks || 0, PHP.totalFailed || 0],
                    backgroundColor: ['#10b981', '#3b82f6', '#ef4444'],
                    borderWidth: 0
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            font: {
                                size: 10
                            }
                        }
                    }
                }
            }
        });
    }

    // Status Chart
    const statusCtx = document.getElementById('statusChart');
    if (statusCtx) {
        chartInstances.statusChart = new Chart(statusCtx, {
            type: 'doughnut',
            data: {
                labels: ['Incubating', 'Complete'],
                datasets: [{
                    data: [PHP.incubating || 0, PHP.complete || 0],
                    backgroundColor: ['#f59e0b', '#10b981'],
                    borderWidth: 0
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            font: {
                                size: 10
                            }
                        }
                    }
                }
            }
        });
    }
}

// Helper function to escape HTML
function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Auto-refresh activity logs every 30 seconds (real-time feel)
let activityRefreshInterval = null;

function startActivityRefresh() {
    if (activityRefreshInterval) clearInterval(activityRefreshInterval);

    activityRefreshInterval = setInterval(function () {
        const activeTabInput = document.getElementById('activeTab');
        const activeTab = activeTabInput ? activeTabInput.value : '';

        if (activeTab === 'overview') {
            fetch(window.location.href + '?get_activity_ajax=1&nocache=' + Date.now(), {
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            })
                .then(response => response.json())
                .then(data => {
                    if (data.logs && data.logs.length > 0) {
                        const tbody = document.getElementById('activityLogsBody');
                        if (tbody) {
                            let newHtml = '';
                            data.logs.forEach(log => {
                                newHtml += `
                                <tr>
                                    <td class="activity-time">
                                        <i class="far fa-clock"></i> ${escapeHtml(log.formatted_date)}
                                        <small>(${escapeHtml(log.time_ago)})</small>
                                    </td>
                                    <td>${escapeHtml(log.username)}</td>
                                    <td>${escapeHtml(log.action)}</td>
                                </tr>
                            `;
                            });
                            tbody.innerHTML = newHtml;
                        }
                    }
                })
                .catch(err => console.log('Auto-refresh failed:', err));
        }
    }, 30000);
}

// Close modal on outside click
window.onclick = function (event) {
    const modal = document.getElementById('userModal');
    if (event.target === modal) closeModal();
};

// Initialize on page load
document.addEventListener('DOMContentLoaded', function () {
    // Initialize charts if on analytics tab
    const activeTabInput = document.getElementById('activeTab');
    const activeTab = activeTabInput ? activeTabInput.value : '';
    if (activeTab === 'analytics') {
        initCharts();
    }

    // Start auto-refresh for activity logs
    startActivityRefresh();
});