// Chart instances
let dailyChart = null;
let hourlyChart = null;

// Initialize all charts
function initializeCharts(data) {
    initializeDailyChart(data);
    initializeHourlyChart(data);
}

// Initialize Daily Activity Chart
function initializeDailyChart(data) {
    const ctx = document.getElementById('dailyActivityChart').getContext('2d');

    // Destroy existing chart if it exists
    if (dailyChart) {
        dailyChart.destroy();
    }

    dailyChart = new Chart(ctx, {
        type: 'line',
        data: {
            labels: data.dates,
            datasets: [{
                label: 'Total Activities',
                data: data.activityTrend,
                borderColor: '#10b981',
                backgroundColor: 'rgba(16, 185, 129, 0.1)',
                borderWidth: 3,
                pointBackgroundColor: '#10b981',
                pointBorderColor: 'white',
                pointBorderWidth: 2,
                pointRadius: 4,
                pointHoverRadius: 6,
                tension: 0.4,
                fill: true
            }, {
                label: 'Unique Users',
                data: data.uniqueUsers,
                borderColor: '#3b82f6',
                backgroundColor: 'rgba(59, 130, 246, 0.1)',
                borderWidth: 2,
                borderDash: [5, 5],
                pointRadius: 3,
                tension: 0.4,
                yAxisID: 'y1'
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: {
                mode: 'index',
                intersect: false
            },
            plugins: {
                legend: {
                    display: true,
                    position: 'top',
                    labels: {
                        usePointStyle: true,
                        boxWidth: 6
                    }
                },
                tooltip: {
                    backgroundColor: '#1e293b',
                    titleColor: '#f8fafc',
                    bodyColor: '#cbd5e1',
                    padding: 12,
                    cornerRadius: 8
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    grid: {
                        color: '#f1f5f9'
                    },
                    title: {
                        display: true,
                        text: 'Total Activities'
                    }
                },
                y1: {
                    beginAtZero: true,
                    position: 'right',
                    grid: {
                        drawOnChartArea: false
                    },
                    title: {
                        display: true,
                        text: 'Unique Users'
                    }
                }
            }
        }
    });
}

// Initialize Hourly Activity Chart
function initializeHourlyChart(data) {
    const ctx = document.getElementById('hourlyActivityChart').getContext('2d');

    // Destroy existing chart if it exists
    if (hourlyChart) {
        hourlyChart.destroy();
    }

    // Generate hour labels
    const hourLabels = Array.from({ length: 24 }, (_, i) => `${String(i).padStart(2, '0')}:00`);

    hourlyChart = new Chart(ctx, {
        type: 'bar',
        data: {
            labels: hourLabels,
            datasets: [{
                label: 'Activities',
                data: data.hourlyActivity,
                backgroundColor: (context) => {
                    const value = context.dataset.data[context.dataIndex];
                    const max = Math.max(...context.dataset.data);
                    const opacity = 0.3 + (value / max) * 0.7;
                    return `rgba(16, 185, 129, ${opacity})`;
                },
                borderRadius: 6
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    display: false
                },
                tooltip: {
                    callbacks: {
                        title: function (context) {
                            return `Hour: ${context[0].label}`;
                        }
                    }
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    grid: {
                        color: '#f1f5f9'
                    }
                },
                x: {
                    grid: {
                        display: false
                    }
                }
            }
        }
    });
}

// Toggle chart type
function toggleChartType(chartId) {
    if (chartId === 'daily' && dailyChart) {
        const currentType = dailyChart.config.type;
        const newType = currentType === 'line' ? 'bar' : 'line';

        dailyChart.config.type = newType;
        dailyChart.update();

        // Update button icon
        const button = event.currentTarget;
        const icon = button.querySelector('i');
        if (icon) {
            icon.className = newType === 'line' ? 'fas fa-chart-bar' : 'fas fa-chart-line';
        }
    }
}

// Export to CSV
function exportToCSV() {
    // Get action stats from the global variable
    if (typeof chartData !== 'undefined' && chartData.actionStats) {
        const actions = chartData.actionStats;
        let csv = 'Action,Total Count,Unique Users,Last Performed\n';

        actions.forEach(action => {
            csv += `"${action.action}",${action.total_count},${action.unique_users},"${action.last_performed}"\n`;
        });

        // Create download link
        const blob = new Blob([csv], { type: 'text/csv' });
        const url = window.URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = `activity_analytics_${new Date().toISOString().split('T')[0]}.csv`;
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        window.URL.revokeObjectURL(url);
    }
}

// Refresh logs
function refreshLogs() {
    // Show loading state
    const refreshBtn = event.currentTarget;
    const originalHtml = refreshBtn.innerHTML;
    refreshBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Refreshing...';
    refreshBtn.disabled = true;

    // Reload the page
    setTimeout(() => {
        location.reload();
    }, 500);
}

// Auto-refresh simulation (every 30 seconds)
let refreshInterval = setInterval(() => {
    console.log('Fetching latest data...');
    // You could implement AJAX refresh here
}, 30000);

// Clean up interval on page unload
window.addEventListener('beforeunload', () => {
    if (refreshInterval) {
        clearInterval(refreshInterval);
    }
});

// Handle responsive behavior
function handleResponsive() {
    if (window.innerWidth <= 768) {
        // Mobile adjustments if needed
        if (dailyChart) {
            dailyChart.options.plugins.legend.position = 'bottom';
            dailyChart.update();
        }
    } else {
        if (dailyChart) {
            dailyChart.options.plugins.legend.position = 'top';
            dailyChart.update();
        }
    }
}

// Add resize listener
window.addEventListener('resize', handleResponsive);

// Initialize on load
document.addEventListener('DOMContentLoaded', () => {
    handleResponsive();

    // Add tooltip functionality
    const tooltips = document.querySelectorAll('.tooltip');
    tooltips.forEach(tooltip => {
        tooltip.addEventListener('mouseenter', (e) => {
            const rect = tooltip.getBoundingClientRect();
            // Position adjustment if needed
        });
    });
});