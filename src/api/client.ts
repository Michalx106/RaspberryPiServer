import type { HistoryResponse, ShellyResponse, SystemSnapshot } from '../types/panel';

const fetchJson = async <T>(input: RequestInfo, init?: RequestInit): Promise<T> => {
  const response = await fetch(input, init);
  if (!response.ok) {
    throw new Error(`Błąd żądania: ${response.status}`);
  }
  return (await response.json()) as T;
};

export const fetchSnapshot = () => fetchJson<SystemSnapshot>('/api/snapshot');

export const fetchHistory = () => fetchJson<HistoryResponse>('/api/history');

export const fetchShelly = () => fetchJson<ShellyResponse>('/api/shelly');
