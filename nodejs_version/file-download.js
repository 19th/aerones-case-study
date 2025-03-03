const axios = require('axios');
const fs = require('fs');
const path = require('path');

// Ensure temp and complete directories exist
const tempDir = path.join(__dirname, 'temp');
const completeDir = path.join(__dirname, 'completed');
if (!fs.existsSync(tempDir)) fs.mkdirSync(tempDir);
if (!fs.existsSync(completeDir)) fs.mkdirSync(completeDir);

// List of file URLs to download
const fileUrls = [
    'https://storage.googleapis.com/public_test_access_ae/output_20sec.mp4',
    'https://storage.googleapis.com/public_test_access_ae/output_30sec.mp4',
    'https://storage.googleapis.com/public_test_access_ae/output_40sec.mp4',
    'https://storage.googleapis.com/public_test_access_ae/output_50sec.mp4',
    'https://storage.googleapis.com/public_test_access_ae/output_60sec.mp4',
    // Add more URLs as needed
];

// Download a single file with retries and log progress
async function downloadFile(url, retries = 3) {
    const fileName = path.basename(url);
    const tempFilePath = path.join(tempDir, fileName);
    const completeFilePath = path.join(completeDir, fileName);

    for (let attempt = 1; attempt <= retries; attempt++) {
        try {
            console.log(`Starting download for ${url}. Attempt ${attempt} of ${retries}.`);
            const headers = fs.existsSync(tempFilePath) ? { Range: `bytes=${fs.statSync(tempFilePath).size}-` } : {};
            
            const response = await axios.get(url, { responseType: 'stream', headers, timeout: 1000 });
            const writer = fs.createWriteStream(tempFilePath, { flags: 'a' });

            const totalLength = response.headers['content-length'];
            let downloadedLength = 0;

            let lastLoggedProgress = 0;
            response.data.on('data', (chunk) => {
                downloadedLength += chunk.length;
                const progress = Math.floor((downloadedLength / totalLength) * 100);
                if (progress > lastLoggedProgress) {
                    process.stdout.write(`Downloading ${fileName}: ${progress}%\n`);
                    lastLoggedProgress = progress;
                }
            });

            response.data.pipe(writer);

            // Return a promise that resolves when the file is fully downloaded
            await new Promise((resolve, reject) => {
                writer.on('finish', resolve);
                writer.on('error', reject);
            });

            // Move file from temp to complete directory
            fs.renameSync(tempFilePath, completeFilePath);
            console.log(`Successfully downloaded ${url} to ${completeFilePath}`);
            return;
        } catch (error) {
            console.error(`Error downloading ${url}. Attempt ${attempt} of ${retries}. Error: ${error.message}`);

            if (attempt === retries) {
                return { url, error: error.message };
            }
            // Exponential backoff
            const delay = Math.pow(2, attempt) * 1000;
            console.log(`Waiting for ${delay}ms before retrying...`);
            await new Promise(resolve => setTimeout(resolve, delay));
        }
    }
}

// Download all files concurrently with retry on failure
async function downloadFiles(urls) {

    const downloadPromises = urls.map(url => downloadFile(url, 3));
    try {
        await Promise.all(downloadPromises);
    } catch (err) {
        console.error(`Error downloading files: ${err.message}`);
    }
}

// Start the download process
downloadFiles(fileUrls);
