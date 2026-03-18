// User Delete JavaScript - Only for deleting users

// Confirm and delete user function
function deleteUser(userId, username, event) {
    event.preventDefault();

    // Show custom confirmation dialog
    showDeleteConfirmation(userId, username);
}

// Show custom delete confirmation modal
function showDeleteConfirmation(userId, username) {
    // Create modal if it doesn't exist
    let confirmModal = document.getElementById('deleteConfirmModal');

    if (!confirmModal) {
        confirmModal = document.createElement('div');
        confirmModal.id = 'deleteConfirmModal';
        confirmModal.className = 'modal';
        confirmModal.innerHTML = `
            <div class="modal-content" style="max-width: 400px;">
                <div class="modal-header" style="background: #fee2e2;">
                    <h2 style="color: #991b1b;">
                        <i class="fas fa-exclamation-triangle"></i>
                        Confirm Delete
                    </h2>
                    <button type="button" class="modal-close" onclick="closeDeleteConfirmModal()">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                <div class="modal-body" style="text-align: center;">
                    <div style="font-size: 3rem; color: #ef4444; margin-bottom: 1rem;">
                        <i class="fas fa-user-slash"></i>
                    </div>
                    <p style="font-size: 1.1rem; color: #1e293b; margin-bottom: 1rem;">
                        Are you sure you want to delete user?
                    </p>
                    <p style="font-size: 1.2rem; font-weight: 600; color: #991b1b; margin-bottom: 1.5rem; background: #fee2e2; padding: 0.5rem; border-radius: 8px;">
                        "${username}"
                    </p>
                    <p style="color: #64748b; font-size: 0.9rem; margin-bottom: 1.5rem;">
                        This action cannot be undone. All user data including activity logs and egg batches will be permanently deleted.
                    </p>
                    <div class="form-actions" style="justify-content: center;">
                        <button type="button" class="btn btn-outline" onclick="closeDeleteConfirmModal()">
                            <i class="fas fa-times"></i>
                            Cancel
                        </button>
                        <button type="button" class="btn btn-primary" style="background: #ef4444;" onclick="executeDelete('${userId}')">
                            <i class="fas fa-trash"></i>
                            Yes, Delete User
                        </button>
                    </div>
                </div>
            </div>
        `;
        document.body.appendChild(confirmModal);
    } else {
        // Update the username in existing modal
        const usernameSpan = confirmModal.querySelector('p:nth-child(3)');
        if (usernameSpan) {
            usernameSpan.innerHTML = `"${username}"`;
        }
        // Update the delete button with new userId
        const deleteBtn = confirmModal.querySelector('button[onclick*="executeDelete"]');
        if (deleteBtn) {
            deleteBtn.setAttribute('onclick', `executeDelete('${userId}')`);
        }
    }

    // Show modal
    confirmModal.classList.add('active');
    document.body.style.overflow = 'hidden';
}

// Close delete confirmation modal
function closeDeleteConfirmModal() {
    const modal = document.getElementById('deleteConfirmModal');
    if (modal) {
        modal.classList.remove('active');
        document.body.style.overflow = '';
    }
}

// Execute delete after confirmation
function executeDelete(userId) {
    // Close confirmation modal
    closeDeleteConfirmModal();

    // Show loading state on the delete button that was clicked
    const deleteBtn = document.querySelector(`button[onclick*="deleteUser('${userId}"]`);
    if (deleteBtn) {
        deleteBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
        deleteBtn.disabled = true;
    }

    // Create form data
    const formData = new FormData();
    formData.append('user_id', userId);

    // Send AJAX request
    fetch('../../view/users/user-delete.php', {
        method: 'POST',
        body: formData,
        headers: {
            'X-Requested-With': 'XMLHttpRequest'
        }
    })
        .then(response => {
            if (!response.ok) {
                throw new Error('Network response was not ok');
            }
            const contentType = response.headers.get('content-type');
            if (!contentType || !contentType.includes('application/json')) {
                return response.text().then(text => {
                    console.error('Non-JSON response:', text);
                    throw new Error('Server returned non-JSON response');
                });
            }
            return response.json();
        })
        .then(data => {
            if (data.success) {
                // Show success notification
                showNotification(data.message, 'success');

                // Remove the deleted user row from table
                const userRow = document.querySelector(`tr[data-user-id="${userId}"]`);
                if (userRow) {
                    userRow.style.animation = 'slideOut 0.3s ease';
                    setTimeout(() => {
                        userRow.remove();

                        // Update user count in subtitle
                        const subtitle = document.querySelector('.table-subtitle');
                        if (subtitle) {
                            const currentCount = parseInt(subtitle.textContent.match(/\d+/)) || 0;
                            subtitle.innerHTML = `<i class="fas fa-user-check"></i> ${currentCount - 1} total users`;
                        }

                        // Show detailed notification
                        showNotification(`Deleted user: ${data.details.username}. Removed ${data.details.logs_deleted} activity logs and ${data.details.batches_deleted} egg batches.`, 'info');
                    }, 300);
                } else {
                    // If row not found, reload page
                    setTimeout(() => {
                        window.location.reload();
                    }, 1500);
                }
            } else {
                // Show error message
                showNotification(data.message || 'Failed to delete user', 'error');

                // Reset delete button
                const deleteBtn = document.querySelector(`button[onclick*="deleteUser('${userId}"]`);
                if (deleteBtn) {
                    deleteBtn.innerHTML = '<i class="fas fa-trash"></i>';
                    deleteBtn.disabled = false;
                }
            }
        })
        .catch(error => {
            console.error('Error:', error);
            showNotification('Error: ' + error.message, 'error');

            // Reset delete button
            const deleteBtn = document.querySelector(`button[onclick*="deleteUser('${userId}"]`);
            if (deleteBtn) {
                deleteBtn.innerHTML = '<i class="fas fa-trash"></i>';
                deleteBtn.disabled = false;
            }
        });
}

// Reuse showNotification from user-update.js or define here
if (typeof window.showNotification !== 'function') {
    window.showNotification = function (message, type = 'success') {
        // Remove any existing notifications with the same message
        const existingNotifications = document.querySelectorAll('.notification-item');
        for (let notif of existingNotifications) {
            if (notif.innerText.includes(message)) {
                notif.remove();
            }
        }

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
        notification.className = 'notification-item';
        notification.style.cssText = `
            background: ${type === 'success' ? '#10b981' : type === 'error' ? '#ef4444' : type === 'warning' ? '#f59e0b' : '#3b82f6'};
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
            min-width: 300px;
        `;

        let icon = 'fa-check-circle';
        let title = 'Success';

        if (type === 'warning') {
            icon = 'fa-exclamation-triangle';
            title = 'Warning';
        } else if (type === 'error') {
            icon = 'fa-exclamation-circle';
            title = 'Error';
        } else if (type === 'info') {
            icon = 'fa-info-circle';
            title = 'Info';
        }

        notification.innerHTML = `
            <div style="display: flex; align-items: center; gap: 1rem; width: 100%;">
                <i class="fas ${icon}" style="font-size: 1.25rem;"></i>
                <div style="flex: 1;">
                    <div style="font-weight: 600; margin-bottom: 0.25rem;">${title}</div>
                    <div style="font-size: 0.875rem; opacity: 0.9;">${message}</div>
                </div>
                <i class="fas fa-times" style="cursor: pointer; opacity: 0.7; hover:opacity: 1;" onclick="this.parentElement.parentElement.remove()"></i>
            </div>
        `;

        container.appendChild(notification);

        // Remove after 4 seconds (longer for delete operations)
        setTimeout(() => {
            if (notification.parentNode) {
                notification.style.animation = 'slideOut 0.3s ease';
                setTimeout(() => {
                    if (notification.parentNode) {
                        notification.parentNode.removeChild(notification);
                    }
                }, 300);
            }
        }, 4000);
    };
}

// Close modal when clicking outside
window.addEventListener('click', function (event) {
    if (event.target.classList.contains('modal')) {
        if (event.target.id === 'deleteConfirmModal') {
            closeDeleteConfirmModal();
        }
    }
});

// Close modal with Escape key
document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape') {
        const modal = document.getElementById('deleteConfirmModal');
        if (modal && modal.classList.contains('active')) {
            closeDeleteConfirmModal();
        }
    }
});

// Export functions for global use
window.deleteUser = deleteUser;
window.closeDeleteConfirmModal = closeDeleteConfirmModal;
window.executeDelete = executeDelete;