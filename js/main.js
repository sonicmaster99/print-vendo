// Store Machine ID in session storage
document.getElementById('machineIdForm').addEventListener('submit', function(e) {
    e.preventDefault();
    const machineId = document.getElementById('machineId').value;
    
    // Show loading indicator
    const submitBtn = this.querySelector('button[type="submit"]');
    const originalBtnText = submitBtn.innerHTML;
    submitBtn.disabled = true;
    submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Validating...';
    
    // Clear any previous error messages
    const errorDiv = document.getElementById('machineIdError');
    if (errorDiv) {
        errorDiv.remove();
    }
    
    // Validate machine ID with server
    fetch('check_machine_id.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: 'machineId=' + encodeURIComponent(machineId)
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // Store validated machine ID
            sessionStorage.setItem('machineId', machineId);
            
            // Show cleaning up message
            submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Cleaning up last session...';
            
            // Call cleanup endpoint
            return fetch('cleanup_session.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'machineId=' + encodeURIComponent(machineId)
            })
            .then(response => response.json())
            .then(cleanupData => {
                console.log('Cleanup completed:', cleanupData);
                
                // Show main content
                document.getElementById('machineIdForm').closest('.row').classList.add('d-none');
                document.getElementById('mainContent').classList.remove('d-none');
                
                return cleanupData;
            })
            .catch(error => {
                console.error('Cleanup error:', error);
                // Continue anyway even if cleanup fails
                document.getElementById('machineIdForm').closest('.row').classList.add('d-none');
                document.getElementById('mainContent').classList.remove('d-none');
                throw error;
            });
        } else {
            // Show error message
            const errorDiv = document.createElement('div');
            errorDiv.id = 'machineIdError';
            errorDiv.className = 'alert alert-danger mt-3';
            errorDiv.textContent = data.message || 'Invalid machine ID. Please try again.';
            document.getElementById('machineIdForm').appendChild(errorDiv);
            
            // Reset button
            submitBtn.disabled = false;
            submitBtn.innerHTML = originalBtnText;
        }
    })
    .catch(error => {
        console.error('Error:', error);
        
        // Show error message
        const errorDiv = document.createElement('div');
        errorDiv.id = 'machineIdError';
        errorDiv.className = 'alert alert-danger mt-3';
        errorDiv.textContent = 'Server error. Please try again later.';
        document.getElementById('machineIdForm').appendChild(errorDiv);
        
        // Reset button
        submitBtn.disabled = false;
        submitBtn.innerHTML = originalBtnText;
    });
});

// Check for existing Machine ID on page load
window.addEventListener('load', function() {
    const machineId = sessionStorage.getItem('machineId');
    if (machineId) {
        // Validate stored machine ID
        fetch('check_machine_id.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: 'machineId=' + encodeURIComponent(machineId)
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                // Machine ID is valid, show main content
                document.getElementById('machineId').value = machineId;
                document.getElementById('machineIdForm').closest('.row').classList.add('d-none');
                document.getElementById('mainContent').classList.remove('d-none');
            } else {
                // Invalid machine ID, clear session storage
                sessionStorage.removeItem('machineId');
                // Show machine ID form
                document.getElementById('machineIdForm').closest('.row').classList.remove('d-none');
                document.getElementById('mainContent').classList.add('d-none');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            // On error, keep showing the machine ID form
            sessionStorage.removeItem('machineId');
            document.getElementById('machineIdForm').closest('.row').classList.remove('d-none');
            document.getElementById('mainContent').classList.add('d-none');
        });
    }
});
