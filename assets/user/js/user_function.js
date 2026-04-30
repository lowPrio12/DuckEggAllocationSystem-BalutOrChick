// User Dashboard JavaScript
// Mobile menu functions
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
        if (overlay) overlay.style.display = 'none';
    }
}

// Modal functions
function openAddModal() {
    const modal = document.getElementById('addModal');
    if (modal) modal.classList.add('active');
}

function openUpdateModal(eggId, day, remaining, locked) {
    document.getElementById('updateEggId').value = eggId;
    document.getElementById('modalDay').innerText = day;
    document.getElementById('remainingText').innerText = remaining + ' eggs remaining';
    currentRemaining = remaining;
    isLocked = locked;

    document.getElementById('failedInput').value = 0;
    document.getElementById('balutInput').value = 0;
    document.getElementById('chickInput').value = 0;

    const balutGroup = document.getElementById('balutGroup');
    const chickGroup = document.getElementById('chickGroup');
    const lockNotice = document.getElementById('lockNotice');

    if (locked) {
        if (balutGroup) balutGroup.style.display = 'none';
        if (chickGroup) chickGroup.style.display = 'none';
        if (lockNotice) lockNotice.style.display = 'flex';
        document.getElementById('balutInput').value = 0;
        document.getElementById('chickInput').value = 0;
    } else {
        if (balutGroup) balutGroup.style.display = 'block';
        if (chickGroup) chickGroup.style.display = 'block';
        if (lockNotice) lockNotice.style.display = 'none';
    }

    const modal = document.getElementById('updateModal');
    if (modal) modal.classList.add('active');
}

function closeModal(modalId) {
    const modal = document.getElementById(modalId);
    if (modal) modal.classList.remove('active');
}

function checkTotal() {
    const failed = parseInt(document.getElementById('failedInput').value) || 0;
    const balut = parseInt(document.getElementById('balutInput').value) || 0;
    const chick = parseInt(document.getElementById('chickInput').value) || 0;
    const total = failed + balut + chick;
    const validationMsg = document.getElementById('validationMsg');
    const submitBtn = document.getElementById('submitUpdateBtn');

    if (total === 0) {
        if (validationMsg) {
            validationMsg.style.display = 'block';
            validationMsg.innerHTML = '<i class="fas fa-exclamation-circle"></i> Enter at least one value greater than 0.';
        }
        if (submitBtn) submitBtn.disabled = true;
    } else if (total > currentRemaining) {
        if (validationMsg) {
            validationMsg.style.display = 'block';
            validationMsg.innerHTML = '<i class="fas fa-exclamation-circle"></i> Total exceeds remaining eggs (' + currentRemaining + ').';
        }
        if (submitBtn) submitBtn.disabled = true;
    } else {
        if (validationMsg) validationMsg.style.display = 'none';
        if (submitBtn) submitBtn.disabled = false;
    }
}

function validateUpdate() {
    const submitBtn = document.getElementById('submitUpdateBtn');
    return submitBtn ? !submitBtn.disabled : true;
}

// Report Functions (Matching Manager Dashboard)
function generateReport() {
    const typeSelect = document.getElementById('reportType');
    const startDateInput = document.getElementById('startDate');
    const endDateInput = document.getElementById('endDate');

    const type = typeSelect ? typeSelect.value : '';
    const start = startDateInput ? startDateInput.value : '';
    const end = endDateInput ? endDateInput.value : '';

    if (!start || !end) {
        showToast('Please select both start and end dates.', 'error');
        return;
    }

    // Reload page with report parameters - switches to Reports tab automatically
    window.location.href = `?tab=reports&report=${type}&start=${start}&end=${end}`;
}

function exportCSV() {
    const table = document.querySelector('#reportContent table');
    if (!table) {
        showToast('Generate a report first before exporting.', 'error');
        return;
    }

    let csv = [];
    const headers = [];

    // Get headers
    table.querySelectorAll('thead th').forEach(th => {
        headers.push('"' + th.innerText.replace(/"/g, '""') + '"');
    });
    csv.push(headers.join(','));

    // Get data rows
    table.querySelectorAll('tbody tr').forEach(tr => {
        const row = [];
        tr.querySelectorAll('td').forEach(td => {
            row.push('"' + td.innerText.replace(/"/g, '""') + '"');
        });
        csv.push(row.join(','));
    });

    // Download CSV
    const blob = new Blob(["\uFEFF" + csv.join('\n')], {
        type: 'text/csv;charset=utf-8;'
    });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    const reportSelect = document.getElementById('reportType');
    const reportName = reportSelect ? reportSelect.options[reportSelect.selectedIndex].text : 'report';
    a.download = `${reportName}_${new Date().toISOString().slice(0, 19).replace(/:/g, '-')}.csv`;
    a.click();
    URL.revokeObjectURL(url);
    showToast('Report exported successfully!', 'success');
}

function printReport() {
    window.print();
}

function showToast(message, type = 'success') {
    const toast = document.getElementById('customToast');
    if (!toast) return;

    toast.style.backgroundColor = type === 'success' ? '#10b981' : '#ef4444';
    toast.innerHTML = `<i class="fas fa-${type === 'success' ? 'check-circle' : 'exclamation-circle'}"></i> ${message}`;
    toast.style.display = 'flex';

    setTimeout(() => {
        toast.style.display = 'none';
    }, 3000);
}

// Global variables for update modal
let currentRemaining = 0;
let isLocked = false;

// Initialize Charts and Event Listeners
document.addEventListener('DOMContentLoaded', function () {
    // Mobile menu button listener
    const mobileMenuBtn = document.getElementById('mobileMenuBtn');
    if (mobileMenuBtn) {
        mobileMenuBtn.addEventListener('click', toggleMobileMenu);
    }

    // Sidebar overlay listener
    const sidebarOverlay = document.getElementById('sidebarOverlay');
    if (sidebarOverlay) {
        sidebarOverlay.addEventListener('click', closeMobileMenu);
    }

    // Initialize report on page load if report parameters exist
    const urlParams = new URLSearchParams(window.location.search);
    const reportParam = urlParams.get('report');
    if (reportParam) {
        // Ensure reports tab is active
        document.querySelectorAll('.tab-section').forEach(s => s.classList.remove('active'));
        const reportsSection = document.getElementById('reports-section');
        if (reportsSection) reportsSection.classList.add('active');
    }

    // Pie Chart
    const pieCtx = document.getElementById('pieChartCanvas');
    if (pieCtx && window.EggFlowConfig) {
        new Chart(pieCtx, {
            type: 'pie',
            data: {
                labels: ['Chicks (' + window.EggFlowConfig.totalChicks + ')', 'Balut (' + window.EggFlowConfig.totalBalut + ')', 'Failed (' + window.EggFlowConfig.totalFailed + ')'],
                datasets: [{
                    data: [window.EggFlowConfig.totalChicks, window.EggFlowConfig.totalBalut, window.EggFlowConfig.totalFailed],
                    backgroundColor: ['#10b981', '#f59e0b', '#ef4444'],
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

    // Daily Chart
    const dailyCtx = document.getElementById('dailyChartCanvas');
    if (dailyCtx && window.EggFlowConfig && window.EggFlowConfig.dailyAnalytics && window.EggFlowConfig.dailyAnalytics.length > 0) {
        const days = window.EggFlowConfig.dailyAnalytics.map(d => 'Day ' + d.day_number);
        const balut = window.EggFlowConfig.dailyAnalytics.map(d => d.balut || 0);
        const chicks = window.EggFlowConfig.dailyAnalytics.map(d => d.chicks || 0);

        new Chart(dailyCtx, {
            type: 'bar',
            data: {
                labels: days,
                datasets: [{
                    label: 'Balut',
                    data: balut,
                    backgroundColor: '#f59e0b',
                    borderRadius: 6
                },
                {
                    label: 'Chicks',
                    data: chicks,
                    backgroundColor: '#10b981',
                    borderRadius: 6
                }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                plugins: {
                    legend: {
                        position: 'top',
                        labels: {
                            font: {
                                size: 10
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        grid: {
                            color: '#e2e8f0'
                        }
                    },
                    x: {
                        ticks: {
                            font: {
                                size: 9
                            }
                        }
                    }
                }
            }
        });
    }
});