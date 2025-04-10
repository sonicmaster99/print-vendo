document.getElementById('uploadForm').addEventListener('submit', function(event) {
    event.preventDefault();
    const fileInput = document.getElementById('document');
    const promptInput = document.getElementById('prompt').value;
    const model = document.getElementById('model').value;
    const machineId = document.getElementById('machineId').value;
    const file = fileInput.files[0];

    if (!file) {
        alert('Please upload a document.');
        return;
    }

    const formData = new FormData();
    formData.append('document', file);
    formData.append('prompt', promptInput);
    formData.append('model', model);
    formData.append('machineId', machineId);

    fetch('/upload', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        document.getElementById('cost').textContent = data.cost;
        // Additional logic to handle file monitoring and download unlocking
    })
    .catch(error => console.error('Error:', error));
});

fetch('/pricing')
    .then(response => response.json())
    .then(pricing => {
        const modelSelect = document.getElementById('model');
        modelSelect.innerHTML = `
            <option value="3.5">Model 3.5 - PHP ${pricing['3.5']}/100 tokens</option>
            <option value="4.0">Model 4.0 - PHP ${pricing['4.0']}/100 tokens</option>
            <option value="4.5">Model 4.5 - PHP ${pricing['4.5']}/100 tokens</option>
        `;
    })
    .catch(error => console.error('Error fetching pricing:', error));
