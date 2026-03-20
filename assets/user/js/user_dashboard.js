// User Dashboard JavaScript

// Store remaining eggs data
let currentRemaining = 0;

// Modal Functions
function openModal(id) {
    const modal = document.getElementById(id);
    if (modal) {
        modal.classList.add('active');
        document.body.style.overflow = 'hidden';
    }
}

function closeModal(id) {
    const modal = document.getElementById(id);
    if (modal) {
        modal.classList.remove('active');
        document.body.style.overflow = 'auto';

        // Reset form
        const form = modal.querySelector('form');
        if (form) form.reset();

        // Hide validation message if visible
        const validationMsg = document.getElementById('validationMessage');
        if (validationMsg) validationMsg.style.display = 'none';
    }
}

function openUpdateModal(id, day, remaining) {
    document.getElementById('updateEggId').value = id;
    document.getElementById('modalDayNumber').innerText = day;
    document.getElementById('remainingEggs').innerText = remaining;
    currentRemaining = remaining;

    // Reset input values
    document.getElementById('failed_count').value = 0;
    document.getElementById('balut_count').value = 0;
    document.getElementById('chick_count').value = 0;

    // Hide validation message
    const validationMsg = document.getElementById('validationMessage');
    if (validationMsg) validationMsg.style.display = 'none';

    // Enable submit button
    const submitBtn = document.getElementById('submitBtn');
    if (submitBtn) {
        submitBtn.disabled = false;
        submitBtn.style.opacity = '1';
        submitBtn.style.cursor = 'pointer';
    }

    openModal('updateModal');
}

// Check if total input exceeds remaining eggs
function checkRemaining() {
    const failed = parseInt(document.getElementById('failed_count').value) || 0;
    const balut = parseInt(document.getElementById('balut_count').value) || 0;
    const chick = parseInt(document.getElementById('chick_count').value) || 0;

    const total = failed + balut + chick;
    const validationMsg = document.getElementById('validationMessage');
    const submitBtn = document.getElementById('submitBtn');

    if (total > currentRemaining) {
        validationMsg.innerHTML = `<i class="fas fa-exclamation-circle"></i> Total (${total}) exceeds remaining eggs (${currentRemaining})`;
        validationMsg.style.display = 'block';
        if (submitBtn) {
            submitBtn.disabled = true;
            submitBtn.style.opacity = '0.5';
            submitBtn.style.cursor = 'not-allowed';
        }
        return false;
    } else {
        validationMsg.style.display = 'none';
        if (submitBtn) {
            submitBtn.disabled = false;
            submitBtn.style.opacity = '1';
            submitBtn.style.cursor = 'pointer';
        }
        return true;
    }
}

// Form Validation Functions
function validateAddForm() {
    const totalEggs = document.getElementById('total_egg').value;
    if (!totalEggs || totalEggs <= 0) {
        alert('Please enter a valid number of eggs (greater than 0).');
        return false;
    }
    return true;
}

function validateUpdateForm() {
    const failed = parseInt(document.getElementById('failed_count').value) || 0;
    const balut = parseInt(document.getElementById('balut_count').value) || 0;
    const chick = parseInt(document.getElementById('chick_count').value) || 0;

    if (failed < 0 || balut < 0 || chick < 0) {
        alert('Values cannot be negative.');
        return false;
    }

    if (failed + balut + chick === 0) {
        alert('Please enter at least one value greater than 0.');
        return false;
    }

    // Check if total exceeds remaining
    if (!checkRemaining()) {
        return false;
    }

    return true;
}

// Confirm delete batch
function confirmDeleteBatch() {
    return confirm('⚠️ Are you sure you want to delete this batch?\n\nThis action cannot be undone. All daily logs for this batch will also be deleted.');
}

// Close modal when clicking outside
window.onclick = function (event) {
    if (event.target.classList.contains('modal')) {
        event.target.classList.remove('active');
        document.body.style.overflow = 'auto';
    }
}

// Close modal with Escape key
document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape') {
        const activeModal = document.querySelector('.modal.active');
        if (activeModal) {
            activeModal.classList.remove('active');
            document.body.style.overflow = 'auto';
        }
    }
});

// Auto-hide messages after 5 seconds
setTimeout(function () {
    const messages = document.querySelectorAll('.success-message, .error-message');
    messages.forEach(function (message) {
        message.style.opacity = '0';
        setTimeout(function () {
            message.style.display = 'none';
        }, 300);
    });
}, 5000);

// Log initialization
console.log('User Dashboard JavaScript loaded successfully');