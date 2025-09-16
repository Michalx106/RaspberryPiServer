# RaspberryPiServer

Prosty panel statusu dla Raspberry Pi napisany w PHP.

## Tryb ciemny

Interfejs panelu posiada przełącznik trybu ciemnego umieszczony w stopce sekcji
„Panel stanu Raspberry Pi”. Kliknięcie przycisku natychmiast przełącza klasę
`theme-dark` na elemencie `<body>` i zapisuje wybrany wariant kolorystyczny w
`localStorage` przeglądarki. Dzięki temu kolejne odwiedziny automatycznie
korzystają z ostatnio wybranego motywu.

## Ochrona dostępu (HTTP Basic Auth)

Aplikacja może być chroniona prostą autoryzacją HTTP Basic. Dane logowania są pobierane
z pliku `config/auth.php`, który odczytuje wartości ze zmiennych środowiskowych:

- `APP_BASIC_AUTH_USER` – nazwa użytkownika,
- `APP_BASIC_AUTH_PASSWORD` – hasło.

Jeśli którakolwiek z wartości jest pusta lub niezdefiniowana, ochrona jest wyłączona.

### Jak włączyć

1. Ustaw zmienne środowiskowe (np. w konfiguracji usługi systemd lub pliku `.env` używanym przez serwer WWW):
   ```bash
   export APP_BASIC_AUTH_USER="twoj_login"
   export APP_BASIC_AUTH_PASSWORD="bardzo_tajne_haslo"
   ```
2. Uruchom ponownie serwer WWW / PHP-FPM, aby nowe wartości zostały odczytane.

Po włączeniu ochrona wymaga podania poprawnych poświadczeń przy każdej próbie dostępu
(do czasu zapisania ich przez przeglądarkę). W przypadku błędnych danych aplikacja
odpowie statusem HTTP `401` oraz nagłówkiem `WWW-Authenticate`, co poinformuje
przeglądarkę o konieczności podania loginu i hasła. Weryfikacja poświadczeń odbywa się
przy użyciu funkcji `hash_equals`, dzięki czemu porównanie ma stały czas wykonania.

## Historia metryk

Panel może zapisywać kolejne snapshoty stanu do pliku JSON i prezentować historię
na prostym wykresie temperatury CPU.

### Konfiguracja zapisu

Historia jest domyślnie włączona i zapisuje dane w pliku `var/status-history.json`.
Możesz dostosować zachowanie za pomocą zmiennych środowiskowych:

- `APP_HISTORY_PATH` – niestandardowa ścieżka do pliku JSON z historią.
  Upewnij się, że proces PHP ma prawa zapisu do katalogu.
- `APP_HISTORY_MAX_ENTRIES` – maksymalna liczba przechowywanych snapshotów (domyślnie 360).
  Ustaw wartość `0`, aby całkowicie wyłączyć zapisywanie historii.
- `APP_HISTORY_MAX_AGE` – (opcjonalnie) maksymalny wiek danych w sekundach. Starsze wpisy
  będą usuwane podczas kolejnych zapisów.
- `APP_HISTORY_MIN_INTERVAL` – minimalny odstęp (w sekundach) pomiędzy kolejnymi zapisami
  historii. Domyślnie 60 sekund; ustaw `0`, aby zapisywać każdy snapshot niezależnie od czasu.

Jeżeli nowy snapshot pojawi się szybciej niż wynika to z ustawienia `APP_HISTORY_MIN_INTERVAL`,
aplikacja zaktualizuje ostatni rekord zamiast dopisywać kolejny. Dzięki temu historia zachowuje
regularne odstępy czasowe i nie rozrasta się gwałtownie podczas częstego odpytywania.

Katalog `var/` zawiera plik `.gitignore`, dzięki czemu dane historii nie trafiają do repozytorium.

### API

Do pobrania historii służy nowe zapytanie HTTP:

```
GET /index.php?status=history
```

Odpowiedź zawiera m.in. pola `enabled`, `maxEntries`, `maxAge`, `count` oraz tablicę `entries`
z uporządkowanymi rekordami historii (z wartościami liczbowymi i etykietami). Możesz zawęzić liczbę
zwracanych elementów parametrem `limit`, np. `?status=history&limit=120`.

### Wykres w interfejsie

Front-end rysuje wykres temperatury CPU z wykorzystaniem elementu SVG generowanego przez
JavaScript. Wykorzystuje wyłącznie natywne możliwości przeglądarki, więc aplikacja nie ładuje
żadnych bibliotek zewnętrznych (np. Chart.js) ani zasobów z CDN.
Wykres aktualizuje się automatycznie po wczytaniu strony, ręcznym odświeżeniu oraz podczas pracy
w trybie strumieniowym. Jeżeli historia jest wyłączona lub niedostępna, panel wyświetli
odpowiedni komunikat. Wygląd i zachowanie wykresu możesz dopasować, modyfikując pliki
`public/index.php` oraz `public/styles.css`.
