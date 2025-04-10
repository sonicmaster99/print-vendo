const express = require('express');
const multer = require('multer');
const fs = require('fs');
const path = require('path');
const { google } = require('googleapis');
require('dotenv').config();
const { Configuration, OpenAIApi } = require('openai');
const pricing = require('./pricing.json');

const app = express();
const upload = multer({ dest: 'uploads/' });

app.use(express.static('public'));

const drive = google.drive('v3');

// Load client secrets from a local file.
const credentialsPath = path.join(__dirname, '..', 'Testing Printer', 'printgptphl-ef5b74c33191.json');
fs.readFile(credentialsPath, (err, content) => {
    if (err) return console.log('Error loading client secret file:', err);
    authorize(JSON.parse(content));
});

function authorize(credentials) {
    const { client_secret, client_id, redirect_uris } = credentials.installed;
    const oAuth2Client = new google.auth.OAuth2(
        client_id, client_secret, redirect_uris[0]
    );

    // Set the credentials
    const REFRESH_TOKEN = 'YOUR_REFRESH_TOKEN';
    oAuth2Client.setCredentials({ refresh_token: REFRESH_TOKEN });
    // Now you can use oAuth2Client for authenticated requests
    oauth2Client = oAuth2Client;
}

let oauth2Client;

const openai = new OpenAIApi(new Configuration({
    apiKey: process.env.OPENAI_API_KEY,
}));

async function findMachineFolder(machineId) {
    try {
        const response = await drive.files.list({
            q: `name='machine_${machineId}' and mimeType='application/vnd.google-apps.folder' and '1xHjJU_wnhLgd1RoCWFrCexHPPtBuNrdI' in parents`,
            fields: 'files(id, name)'
        });
        if (response.data.files.length > 0) {
            return response.data.files[0].id;
        } else {
            throw new Error('Machine folder not found');
        }
    } catch (error) {
        console.error('Error finding machine folder:', error);
        throw error;
    }
}

async function uploadFile(fileName, filePath, folderId) {
    try {
        const response = await drive.files.create({
            requestBody: {
                name: fileName,
                parents: [folderId]
            },
            media: {
                mimeType: 'text/plain',
                body: fs.createReadStream(filePath)
            }
        });
        console.log('File uploaded successfully:', response.data);
    } catch (error) {
        console.error('Error uploading file:', error);
    }
}

async function monitorFile(fileName, folderId) {
    try {
        const response = await drive.files.list({
            q: `name='${fileName}' and '${folderId}' in parents`,
            fields: 'files(id, name)'
        });
        return response.data.files.length > 0;
    } catch (error) {
        console.error('Error monitoring file:', error);
        return false;
    }
}

async function processDocumentWithChatGPT(documentContent, prompt, model) {
    try {
        const response = await openai.createCompletion({
            model: `gpt-${model}`,
            prompt: `${prompt}\n\n${documentContent}`,
            max_tokens: 1500
        });
        return response.data.choices[0].text;
    } catch (error) {
        console.error('Error processing document with ChatGPT:', error);
        return null;
    }
}

app.post('/upload', upload.single('document'), async (req, res) => {
    const { prompt, model, machineId } = req.body;
    const documentPath = req.file.path;

    try {
        const folderId = await findMachineFolder(machineId);
        // Use folderId for further operations

        const documentContent = fs.readFileSync(documentPath, 'utf-8');
        const chatGPTResponse = await processDocumentWithChatGPT(documentContent, prompt, model);

        const cost = calculateCost(prompt, model);
        const amountRequestPath = path.join(__dirname, 'amount_request.txt');
        fs.writeFileSync(amountRequestPath, `Cost: PHP ${cost}`);

        await uploadFile('amount_request.txt', amountRequestPath, folderId);

        res.json({ cost, chatGPTResponse });
    } catch (error) {
        res.status(500).send('Error processing request');
    }
});

function calculateCost(prompt, model) {
    // Placeholder for cost calculation logic
    const baseCost = 0.01; // Example base cost per token
    const tokenCount = prompt.split(' ').length;
    const modelMultiplier = model === '3.5' ? 1 : model === '4.0' ? 1.5 : 2;
    const cost = baseCost * tokenCount * modelMultiplier * 20; // 20x markup
    return cost.toFixed(2);
}

app.get('/pricing', (req, res) => {
    const markup = 20;
    const phpConversionRate = 50; // Example conversion rate, adjust as needed
    const pricingWithMarkup = {};

    for (const model in pricing) {
        pricingWithMarkup[model] = (pricing[model] * markup * phpConversionRate).toFixed(2);
    }

    res.json(pricingWithMarkup);
});

// Periodically check for amount_paid.txt
setInterval(async () => {
    const fileExists = await monitorFile('amount_paid.txt', '1xHjJU_wnhLgd1RoCWFrCexHPPtBuNrdI');
    if (fileExists) {
        // Logic to unlock download button
        console.log('amount_paid.txt detected. Unlocking download.');
    }
}, 250);

const PORT = process.env.PORT || 3000;
app.listen(PORT, () => {
    console.log(`Server running on port ${PORT}`);
});
