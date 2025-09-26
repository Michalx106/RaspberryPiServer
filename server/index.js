import express from 'express';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { getHistory, getSnapshot } from './systemMonitor.js';
import { getShelly } from './mockData.js';

const app = express();
const PORT = process.env.PORT ?? 3001;

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const distPath = path.resolve(__dirname, '../dist');

app.get('/api/snapshot', async (req, res, next) => {
  try {
    const snapshot = await getSnapshot();
    res.json(snapshot);
  } catch (error) {
    next(error);
  }
});

app.get('/api/history', async (req, res, next) => {
  try {
    const history = await getHistory();
    res.json(history);
  } catch (error) {
    next(error);
  }
});

app.get('/api/shelly', (req, res) => {
  res.json(getShelly());
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
