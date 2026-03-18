// User Creation JavaScript - Only for creating new users

// Add User Modal
function openAddUserModal() {
    document.getElementById('addModal').classList.add('active');
    document.body.style.overflow = 'hidden';

    // Reset form
    document.getElementById('add_username').value = '';
    document.getElementById('add_password').value = '';
    document.getElementById('add_role').value = 'user';

    // Clear any previous error messages
    const errorDiv = document.getElementById('addUserError');
    if (errorDiv) {
        errorDiv.style.display = 'none';
        errorDiv.innerHTML = '';
    }
}

function closeAddModal() {
    document.getElementById('addModal').classList.remove('active');
    document.body.style.overflow = '';
}

// Create User Function with AJAX
function createUser(event) {
    event.preventDefault();

    // Get form data
    const username = document.getElementById('add_username').value.trim();
    const password = document.getElementById('add_password').value;
    const role = document.getElementById('add_role').value;

    // Basic client-side validation
    if (!username || username.length < 3) {
        showAddUserError('Username must be at least 3 characters');
        return;
    }

    if (username.length > 50) {
        showAddUserError('Username must be less than 50 characters');
        return;
    }

    if (!/^[a-zA-Z0-9_]+$/.test(username)) {
        showAddUserError('Username can only contain letters, numbers, and underscores');
        return;
    }

    if (!password || password.length < 6) {
        showAddUserError('Password must be at least 6 characters');
        return;
    }

    // Show loading state
    const submitBtn = document.querySelector('#addModal .btn-primary');
    const originalText = submitBtn.innerHTML;
    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Creating...';
    submitBtn.disabled = true;

    // Create form data
    const formData = new FormData();
    formData.append('username', username);
    formData.append('password', password);
    formData.append('user_role', role);

    // Send AJAX request
    fetch('../../view/users/user-create.php', {
        method: 'POST',
        body: formData,
        headers: {
            'X-Requested-With': 'XMLHttpRequest'
        }
    })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                // Show success message
                showNotification('User created successfully', 'success');

                // Close modal
                closeAddModal();

                // Reload the page to show new user
                setTimeout(() => {
                    window.location.reload();
                }, 1500);
            } else {
                // Show error message
                if (data.errors) {
                    showAddUserError(data.errors.join('<br>'));
                } else {
                    showAddUserError(data.message || 'Failed to create user');
                }

                // Reset button
                submitBtn.innerHTML = originalText;
                submitBtn.disabled = false;
            }
        })
        .catch(error => {
            console.error('Error:', error);
            showAddUserError('Network error occurred. Please try again.');

            // Reset button
            submitBtn.innerHTML = originalText;
            submitBtn.disabled = false;
        });
}

// Helper function to show error in add user modal
function showAddUserError(message) {
    let errorDiv = document.getElementById('addUserError');

    if (!errorDiv) {
        errorDiv = document.createElement('div');
        errorDiv.id = 'addUserError';
        errorDiv.className = 'error-message';
        const modalBody = document.querySelector('#addModal .modal-body');
        modalBody.insertBefore(errorDiv, modalBody.firstChild);
    }

    errorDiv.innerHTML = `<i class="fas fa-exclamation-circle"></i> ${message}`;
    errorDiv.style.display = 'block';

    // Auto hide after 5 seconds
    setTimeout(() => {
        if (errorDiv) {
            errorDiv.style.display = 'none';
        }
    }, 5000);
}

// Show notification function
function showNotification(message, type = 'info') {
    // Check if notification container exists
    let container = document.getElementById('notificationContainer');

    if (!container) {
        container = document.createElement('div');
        container.id = 'notificationContainer';
        container.style.cssText = `
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 9999;
        `;
        document.body.appendChild(container);
    }

    // Create notification
    const notification = document.createElement('div');
    notification.style.cssText = `
        background: ${type === 'success' ? '#10b981' : '#ef4444'};
        color: white;
        padding: 1rem 1.5rem;
        border-radius: 12px;
        margin-bottom: 10px;
        box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        display: flex;
        align-items: center;
        gap: 0.75rem;
        animation: slideIn 0.3s ease;
        font-family: 'Inter', sans-serif;
    `;

    notification.innerHTML = `
        <i class="fas ${type === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle'}"></i>
        <span>${message}</span>
    `;

    container.appendChild(notification);

    // Remove after 3 seconds
    setTimeout(() => {
        notification.style.animation = 'slideOut 0.3s ease';
        setTimeout(() => {
            if (notification.parentNode) {
                notification.parentNode.removeChild(notification);
            }
        }, 300);
    }, 3000);
}

// Close modal when clicking outside
window.addEventListener('click', function (event) {
    if (event.target.classList.contains('modal')) {
        event.target.classList.remove('active');
        document.body.style.overflow = '';
    }
});

// Close modal with Escape key
document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape') {
        const modal = document.getElementById('addModal');
        if (modal && modal.classList.contains('active')) {
            modal.classList.remove('active');
            document.body.style.overflow = '';
        }
    }
});

// Initialize on page load
document.addEventListener('DOMContentLoaded', function () {
    console.log('User Creation initialized');

    // Attach create user function to form
    const addUserForm = document.querySelector('#addModal form');
    if (addUserForm) {
        addUserForm.addEventListener('submit', createUser);
    }
});

// Add animations
const style = document.createElement('style');
style.textContent = `
    @keyframes slideIn {
        from {
            transform: translateX(100%);
            opacity: 0;
        }
        to {
            transform: translateX(0);
            opacity: 1;
        }
    }
    
    @keyframes slideOut {
        from {
            transform: translateX(0);
            opacity: 1;
        }
        to {
            transform: translateX(100%);
            opacity: 0;
        }
    }
    
    .error-message {
        background: #fee2e2;
        color: #991b1b;
        padding: 1rem;
        border-radius: 10px;
        margin-bottom: 1rem;
        font-size: 0.875rem;
        display: flex;
        align-items: center;
        gap: 0.5rem;
        border: 1px solid #fecaca;
        display: none;
    }
    
    .error-message i {
        font-size: 1rem;
    }
    
    .btn-primary:disabled {
        opacity: 0.7;
        cursor: not-allowed;
    }
`;
document.head.appendChild(style);

// Export functions for global use
window.openAddUserModal = openAddUserModal;
window.closeAddModal = closeAddModal;
window.createUser = createUser;