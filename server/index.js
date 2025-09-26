import express from 'express';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { createBundle } from './mockData.js';

const app = express();
const PORT = process.env.PORT ?? 3001;

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const distPath = path.resolve(__dirname, '../dist');

let cache = createBundle();
let lastGenerated = Date.now();

const ensureFreshData = () => {
  const now = Date.now();
  if (now - lastGenerated > 5000) {
    cache = createBundle();
    lastGenerated = now;
  }
};

app.get('/api/snapshot', (req, res) => {
  ensureFreshData();
  res.json(cache.snapshot);
});

app.get('/api/history', (req, res) => {
  ensureFreshData();
  res.json(cache.history);
});

app.get('/api/shelly', (req, res) => {
  ensureFreshData();
  res.json(cache.shelly);
});

app.use(express.static(distPath));

app.get('*', (req, res, next) => {
  if (req.path.startsWith('/api')) {
    return next();
  }
  const indexFile = path.join(distPath, 'index.html');
  if (!fs.existsSync(indexFile)) {
    return res.status(404).send('Brak zbudowanego front-endu. Uruchom "npm run build".');
  }
  res.sendFile(indexFile, (err) => {
    if (err) {
      next(err);
    }
  });
});

app.listen(PORT, () => {
  console.log(`Serwer Express nasłuchuje na porcie ${PORT}`);
});
