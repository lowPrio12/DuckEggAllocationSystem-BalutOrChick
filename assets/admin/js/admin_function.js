// Admin Dashboard JavaScript - Matching Manager Dashboard structure

// Chart instances storage
let charts = {};

// Initialize Overview Charts
function initOverviewCharts() {
    // User Growth Chart
    const ctx1 = document.getElementById('userGrowthChart')?.getContext('2d');
    if (ctx1 && window.AdminConfig) {
        charts.userGrowth = new Chart(ctx1, {
            type: 'line',
            data: {
                labels: window.AdminConfig.growthLabels,
                datasets: [{
                    label: 'New Users',
                    data: window.AdminConfig.userGrowth,
                    borderColor: '#10b981',
                    backgroundColor: 'rgba(16, 185, 129, 0.1)',
                    borderWidth: 2,
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
                        stepSize: 1,
                        grid: {
                            color: '#e2e8f0'
                        }
                    },
                    x: {
                        grid: {
                            display: false
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

    // Batch Status Chart
    const ctx2 = document.getElementById('batchStatusChart')?.getContext('2d');
    if (ctx2 && window.AdminConfig) {
        charts.batchStatus = new Chart(ctx2, {
            type: 'doughnut',
            data: {
                labels: ['Incubating', 'Complete'],
                datasets: [{
                    data: [window.AdminConfig.incubating, window.AdminConfig.complete],
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

// Initialize Analytics Charts
function initAnalyticsCharts() {
    if (!window.AdminConfig) return;

    // Daily Production Chart
    const ctx1 = document.getElementById('dailyProductionChart')?.getContext('2d');
    if (ctx1 && window.AdminConfig.dailyProduction) {
        charts.dailyProduction = new Chart(ctx1, {
            type: 'line',
            data: {
                labels: window.AdminConfig.dailyLabels,
                datasets: [{
                    label: 'Balut',
                    data: window.AdminConfig.dailyProduction.map(d => d.balut),
                    borderColor: '#f59e0b',
                    backgroundColor: 'transparent',
                    borderWidth: 2,
                    tension: 0.4,
                    pointRadius: 2
                },
                {
                    label: 'Chicks',
                    data: window.AdminConfig.dailyProduction.map(d => d.chicks),
                    borderColor: '#10b981',
                    backgroundColor: 'transparent',
                    borderWidth: 2,
                    tension: 0.4,
                    pointRadius: 2
                },
                {
                    label: 'Failed',
                    data: window.AdminConfig.dailyProduction.map(d => d.failed),
                    borderColor: '#ef4444',
                    backgroundColor: 'transparent',
                    borderWidth: 2,
                    tension: 0.4,
                    pointRadius: 2
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

    // Weekly Summary Chart
    const ctx2 = document.getElementById('weeklySummaryChart')?.getContext('2d');
    if (ctx2 && window.AdminConfig.weeklySummary) {
        charts.weeklySummary = new Chart(ctx2, {
            type: 'bar',
            data: {
                labels: window.AdminConfig.weeklySummary.map(w => w.week_label),
                datasets: [{
                    label: 'Balut',
                    data: window.AdminConfig.weeklySummary.map(w => w.balut),
                    backgroundColor: '#f59e0b',
                    borderRadius: 4
                },
                {
                    label: 'Chicks',
                    data: window.AdminConfig.weeklySummary.map(w => w.chicks),
                    backgroundColor: '#10b981',
                    borderRadius: 4
                },
                {
                    label: 'Failed',
                    data: window.AdminConfig.weeklySummary.map(w => w.failed),
                    backgroundColor: '#ef4444',
                    borderRadius: 4
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
                    },
                    x: {
                        stacked: false
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

    // Efficiency Distribution Chart
    const ctx3 = document.getElementById('efficiencyChart')?.getContext('2d');
    if (ctx3 && window.AdminConfig.efficiencyDistribution && window.AdminConfig.efficiencyDistribution.length > 0) {
        charts.efficiency = new Chart(ctx3, {
            type: 'pie',
            data: {
                labels: window.AdminConfig.efficiencyDistribution.map(e => e.efficiency_level),
                datasets: [{
                    data: window.AdminConfig.efficiencyDistribution.map(e => e.batch_count),
                    backgroundColor: ['#10b981', '#3b82f6', '#f59e0b', '#ef4444'],
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
                    },
                    tooltip: {
                        callbacks: {
                            label: function (context) {
                                const label = context.label || '';
                                const value = context.parsed || 0;
                                const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                const percentage = total > 0 ? Math.round((value / total) * 100) : 0;
                                return `${label}: ${value} batches (${percentage}%)`;
                            }
                        }
                    }
                }
            }
        });
    }

    // Hourly Activity Chart
    const ctx4 = document.getElementById('hourlyActivityChart')?.getContext('2d');
    if (ctx4 && window.AdminConfig.hourlyActivity) {
        const hourLabels = Array.from({ length: 24 }, (_, i) => `${String(i).padStart(2, '0')}:00`);
        charts.hourlyActivity = new Chart(ctx4, {
            type: 'bar',
            data: {
                labels: hourLabels,
                datasets: [{
                    label: 'User Actions',
                    data: window.AdminConfig.hourlyActivity,
                    backgroundColor: '#8b5cf6',
                    borderRadius: 4
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
                    },
                    x: {
                        ticks: {
                            maxRotation: 45,
                            minRotation: 45,
                            autoSkip: true,
                            maxTicksLimit: 12
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
}

// Tab Switching
function switchTab(tabName) {
    const url = new URL(window.location.href);
    url.searchParams.set('tab', tabName);
    window.history.pushState({}, '', url);

    // Update active state in sidebar
    document.querySelectorAll('.sidebar-menu li').forEach(li => li.classList.remove('active'));
    const activeLi = document.querySelector(`.sidebar-menu li[data-tab="${tabName}"]`);
    if (activeLi) activeLi.classList.add('active');

    // Show active section
    document.querySelectorAll('.tab-section').forEach(section => section.classList.remove('active'));
    const activeSection = document.getElementById(`${tabName}-section`);
    if (activeSection) activeSection.classList.add('active');

    // Update subtitle
    const subtitles = {
        overview: 'System overview & real-time metrics',
        analytics: 'Deep dive analytics & insights',
        reports: 'Generate & export reports'
    };
    const subtitleEl = document.getElementById('page-subtitle');
    if (subtitleEl) subtitleEl.textContent = subtitles[tabName] || '';

    // Resize charts when switching tabs
    setTimeout(() => {
        if (tabName === 'overview') {
            if (charts.userGrowth) charts.userGrowth.resize();
            if (charts.batchStatus) charts.batchStatus.resize();
        } else if (tabName === 'analytics') {
            if (charts.dailyProduction) charts.dailyProduction.resize();
            if (charts.weeklySummary) charts.weeklySummary.resize();
            if (charts.efficiency) charts.efficiency.resize();
            if (charts.hourlyActivity) charts.hourlyActivity.resize();
        }
    }, 100);

    // Close mobile menu on mobile
    if (window.innerWidth <= 768) closeMobileMenu();
}

// Mobile Menu Functions
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

// Export Functions
function exportActivityCSV() {
    window.location.href = '?export_activity=csv';
    showToast('Exporting activity logs...', 'success');
}

function generateReport() {
    const reportType = document.getElementById('reportType')?.value;
    const startDate = document.getElementById('startDate')?.value;
    const endDate = document.getElementById('endDate')?.value;

    if (reportType && startDate && endDate) {
        window.location.href = `?tab=reports&report=${reportType}&start=${startDate}&end=${endDate}`;
    } else {
        showToast('Please fill in all report fields', 'error');
    }
}

function exportReportCSV() {
    const reportType = document.getElementById('reportType')?.value;
    const startDate = document.getElementById('startDate')?.value;
    const endDate = document.getElementById('endDate')?.value;

    if (reportType) {
        window.location.href = `?export_csv=1&report_type=${reportType}&start=${startDate}&end=${endDate}`;
        showToast('Exporting report...', 'success');
    } else {
        showToast('Please generate a report first', 'error');
    }
}

function printReport() {
    const reportTitle = document.getElementById('reportTitle')?.innerText || 'System Report';
    const reportDateRange = document.getElementById('reportDateRange')?.innerText || '';
    const printContent = document.getElementById('print-area')?.innerHTML || '';

    const printWindow = window.open('', '_blank');
    printWindow.document.write(`
        <!DOCTYPE html>
        <html>
        <head>
            <title>${escapeHtml(reportTitle.replace(/<[^>]*>/g, ''))}</title>
            <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
            <style>
                * { margin: 0; padding: 0; box-sizing: border-box; }
                body { font-family: 'Inter', sans-serif; padding: 0.5in; background: white; }
                .report-header { text-align: center; margin-bottom: 20px; }
                .report-header h2 { font-size: 18pt; margin-bottom: 5px; color: #0f172a; }
                .report-header p { font-size: 10pt; color: #64748b; }
                .data-table { width: 100%; font-size: 9pt; border-collapse: collapse; margin-top: 15px; }
                .data-table th { background: #f1f5f9; padding: 8px; text-align: left; border: 1px solid #e2e8f0; }
                .data-table td { padding: 6px 8px; border: 1px solid #e2e8f0; }
                .summary-box { margin-top: 20px; padding: 10px; background: #f0fdf4; border-radius: 8px; text-align: center; }
                @media print {
                    body { padding: 0; }
                }
            </style>
        </head>
        <body>
            <div class="report-header">
                <h2>${escapeHtml(reportTitle.replace(/<[^>]*>/g, ''))}</h2>
                <p>Generated on: ${new Date().toLocaleString()} | ${escapeHtml(reportDateRange)}</p>
            </div>
            ${printContent}
        </body>
        </html>
    `);
    printWindow.document.close();
    printWindow.print();
    printWindow.onafterprint = () => printWindow.close();
    showToast('Preparing print...', 'success');
}

function showToast(msg, type = 'success') {
    const toast = document.getElementById('toast');
    const toastMsg = document.getElementById('toastMsg');
    if (!toast || !toastMsg) return;

    toastMsg.textContent = msg;
    toast.className = `toast show ${type}`;
    setTimeout(() => toast.classList.remove('show'), 3000);
}

function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Auto-refresh activity logs every 30 seconds
let refreshInterval = null;

function startActivityRefresh() {
    if (refreshInterval) clearInterval(refreshInterval);

    refreshInterval = setInterval(function () {
        const activeTab = document.querySelector('.tab-section.active')?.id?.replace('-section', '');

        if (activeTab === 'overview') {
            fetch(window.location.href + '&get_activity_ajax=1&nocache=' + Date.now(), {
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            })
                .then(response => response.json())
                .then(data => {
                    if (data.logs && data.logs.length > 0) {
                        const tbody = document.getElementById('activityLogsBody');
                        if (tbody) {
                            let html = '';
                            data.logs.forEach(log => {
                                html += `
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
                            tbody.innerHTML = html;
                        }
                    }
                })
                .catch(err => console.log('Auto-refresh failed:', err));
        }
    }, 30000);
}

// Handle browser back/forward navigation
window.addEventListener('popstate', () => {
    const params = new URLSearchParams(window.location.search);
    const tab = params.get('tab') || 'overview';
    switchTab(tab);
});

// Initialize on page load
document.addEventListener('DOMContentLoaded', function () {
    // Initialize charts
    initOverviewCharts();
    initAnalyticsCharts();

    // Set up mobile menu button
    const mobileBtn = document.getElementById('mobileMenuBtn');
    if (mobileBtn) {
        mobileBtn.addEventListener('click', toggleMobileMenu);
    }

    // Set up sidebar overlay
    const overlay = document.getElementById('sidebarOverlay');
    if (overlay) {
        overlay.addEventListener('click', closeMobileMenu);
    }

    // Close mobile menu on sidebar link click
    document.querySelectorAll('.sidebar-menu a').forEach(link => {
        link.addEventListener('click', () => {
            if (window.innerWidth <= 768) closeMobileMenu();
        });
    });

    // Resize charts on window resize
    let resizeTimeout;
    window.addEventListener('resize', function () {
        clearTimeout(resizeTimeout);
        resizeTimeout = setTimeout(() => {
            Object.values(charts).forEach(chart => {
                if (chart && typeof chart.resize === 'function') {
                    chart.resize();
                }
            });
        }, 250);
    });

    // Start auto-refresh for activity logs
    startActivityRefresh();
});