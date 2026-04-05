// Common JavaScript functions for the medical system

// Show notification
function showNotification(message, type = 'info') {
    const notification = document.createElement('div');
    notification.className = `notification ${type}`;
    notification.innerHTML = message;
    document.body.appendChild(notification);
    
    // Show notification
    setTimeout(() => {
        notification.classList.add('show');
    }, 100);
    
    // Hide notification after 5 seconds
    setTimeout(() => {
        notification.classList.remove('show');
        setTimeout(() => {
            document.body.removeChild(notification);
        }, 300);
    }, 5000);
}
// Show modal
function showModal(modalId) {
    document.getElementById(modalId).style.display = 'block';
}

// Hide modal
function hideModal(modalId) {
    document.getElementById(modalId).style.display = 'none';
}
// Toggle password visibility
function togglePasswordVisibility(inputId, iconId) {
    const passwordInput = document.getElementById(inputId);
    const icon = document.getElementById(iconId);
    
    if (passwordInput.type === 'password') {
        passwordInput.type = 'text';
        icon.classList.remove('fa-eye');
        icon.classList.add('fa-eye-slash');
    } else {
        passwordInput.type = 'password';
        icon.classList.remove('fa-eye-slash');
        icon.classList.add('fa-eye');
    }
}

// Validate email format
function isValidEmail(email) {
    const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    return emailRegex.test(email);
}

// Validate form inputs
function validateForm(formId) {
    const form = document.getElementById(formId);
    let isValid = true;
    
    // Check required fields
    const requiredFields = form.querySelectorAll('[required]');
    requiredFields.forEach(field => {
        if (!field.value.trim()) {
            isValid = false;
            field.classList.add('error');
        } else {
            field.classList.remove('error');
        }
    });
    
    // Check email format
    const emailField = form.querySelector('input[type="email"]');
    if (emailField && emailField.value.trim() && !isValidEmail(emailField.value.trim())) {
        isValid = false;
        emailField.classList.add('error');
    }
    
    // Check password match if confirm password exists
    const passwordField = form.querySelector('input[name="password"]');
    const confirmPasswordField = form.querySelector('input[name="confirm_password"]');
    
    if (passwordField && confirmPasswordField && 
        passwordField.value.trim() && confirmPasswordField.value.trim() && 
        passwordField.value !== confirmPasswordField.value) {
        isValid = false;
        confirmPasswordField.classList.add('error');
    }
    
    return isValid;
}

// Format date to display format
function formatDate(dateString) {
    const date = new Date(dateString);
    return date.toLocaleDateString('en-US', { 
        year: 'numeric', 
        month: 'long', 
        day: 'numeric' 
    });
}

// Format time to display format
function formatTime(timeString) {
    const [hours, minutes] = timeString.split(':');
    const date = new Date();
    date.setHours(hours);
    date.setMinutes(minutes);
    
    return date.toLocaleTimeString('en-US', { 
        hour: 'numeric', 
        minute: '2-digit', 
        hour12: true 
    });
}

// Print content
function printContent(elementId) {
    const content = document.getElementById(elementId);
    const printWindow = window.open('', '_blank');
    
    printWindow.document.write(`
        <html>
        <head>
            <title>Print</title>
            <link rel="stylesheet" href="css/style.css">
            <link rel="stylesheet" href="css/dashboard.css">
            <style>
                body { font-family: Arial, sans-serif; }
                @media print {
                    body { padding: 20px; }
                }
            </style>
        </head>
        <body>
            ${content.innerHTML}
        </body>
        </html>
    `);
    
    printWindow.document.close();
    printWindow.focus();
    
    // Print after content is loaded
    printWindow.onload = function() {
        printWindow.print();
        printWindow.close();
    };
}

// Show loading spinner
function showLoading() {
    const loadingOverlay = document.createElement('div');
    loadingOverlay.className = 'loading-overlay';
    loadingOverlay.innerHTML = '<div class="spinner"></div>';
    document.body.appendChild(loadingOverlay);
}

// Hide loading spinner
function hideLoading() {
    const loadingOverlay = document.querySelector('.loading-overlay');
    if (loadingOverlay) {
        document.body.removeChild(loadingOverlay);
    }
}

// Confirm action
function confirmAction(message) {
    return confirm(message);
}

// Document ready function
document.addEventListener('DOMContentLoaded', function() {
    // Add error class on invalid inputs
    const formInputs = document.querySelectorAll('.form-control');
    formInputs.forEach(input => {
        input.addEventListener('blur', function() {
            if (this.hasAttribute('required') && !this.value.trim()) {
                this.classList.add('error');
            } else {
                this.classList.remove('error');
            }
            
            if (this.type === 'email' && this.value.trim() && !isValidEmail(this.value.trim())) {
                this.classList.add('error');
            }
        });
        
        input.addEventListener('focus', function() {
            this.classList.remove('error');
        });
    });
    
    // Password confirmation validation
    const passwordFields = document.querySelectorAll('input[name="password"]');
    const confirmPasswordFields = document.querySelectorAll('input[name="confirm_password"]');
    
    if (passwordFields.length > 0 && confirmPasswordFields.length > 0) {
        confirmPasswordFields[0].addEventListener('blur', function() {
            if (this.value.trim() && passwordFields[0].value !== this.value) {
                this.classList.add('error');
            } else {
                this.classList.remove('error');
            }
        });
    }
});
