# RaspberryPiServer (Vue + Express)

Repozytorium zostało przebudowane na aplikację typu SPA opartą o [Vue 3](https://vuejs.org/) zbudowaną przy
użyciu [Vite](https://vitejs.dev/). Front-end komunikuje się z lekkim serwerem HTTP napisanym w [Express.js](https://expressjs.com/),
który udostępnia symulowane dane dotyczące Raspberry Pi.

## Najważniejsze funkcje

- **Zakładka „Status”** – kafelki prezentujące podstawowe metryki urządzenia z przyciskiem ręcznego odświeżania.
- **Zakładka „Historia”** – prosty wykres SVG z możliwością przełączania monitorowanej metryki (temperatura, RAM, dysk, load average).
- **Zakładka „Shelly”** – lista symulowanych urządzeń z informacją o łączności, temperaturze i napięciu.
- **Tryb ciemny** – przełącznik zapisujący preferencje użytkownika w `localStorage` oraz respektujący ustawienia systemowe.
- **Vue Query** – cykliczne odświeżanie danych z backendu wraz z narzędziami deweloperskimi.

## Wymagania

- Node.js w wersji 18 lub nowszej.
- Menedżer pakietów `npm` (instalowany razem z Node.js).

## Instalacja

```bash
npm install
```

## Tryb deweloperski

Deweloperskie uruchomienie uruchamia równolegle serwer Express (port `3001`) oraz Vite (port `5173`):

```bash
npm run dev
```

Podczas pracy w trybie dev Vite proxy'uje zapytania `/api` do serwera Express, dzięki czemu front-end może korzystać z tych samych
ścieżek, co po zbudowaniu aplikacji.

## Budowanie i podgląd produkcyjny

```bash
npm run build
npm run preview # opcjonalnie podgląd front-endu z użyciem Vite
```

Do uruchomienia zbudowanej aplikacji wraz z backendem Express wykorzystaj polecenie:

```bash
npm start
```

Serwer Express będzie serwował statyczne pliki z katalogu `dist/` oraz udostępniał endpointy:

- `GET /api/snapshot`
- `GET /api/history`
- `GET /api/shelly`

Dane są generowane losowo przy każdym odświeżeniu (z krótką pamięcią podręczną po stronie serwera), co pozwala zasymulować zmienność parametrów.
