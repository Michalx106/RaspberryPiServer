# RaspberryPiServer

Prosty panel statusu dla Raspberry Pi napisany w PHP.

## Tryb ciemny

Interfejs panelu posiada przełącznik trybu ciemnego umieszczony w górnym
nagłówku strony. Kliknięcie przycisku natychmiast przełącza klasę `theme-dark`
na elemencie `<body>` i zapisuje wybrany wariant kolorystyczny w `localStorage`
przeglądarki. Dzięki temu kolejne odwiedziny automatycznie korzystają z
ostatnio wybranego motywu.

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

## Częstotliwość strumienia statusu (SSE)

Panel udostępnia endpoint `?status=stream`, który wysyła aktualne dane w formacie
Server-Sent Events. Domyślnie nowe zdarzenia trafiają do przeglądarki co sekundę,
ale interwał możesz kontrolować zmienną środowiskową:

- `APP_STREAM_INTERVAL` – liczba sekund pomiędzy kolejnymi zdarzeniami SSE.

Wartość musi być liczbą całkowitą i mieścić się w przedziale od 1 do 60. Aplikacja
automatycznie obcina wartości spoza zakresu (np. `0` zostanie potraktowane jak `1`, a
`120` jak `60`). Przykładowa konfiguracja:

```bash
export APP_STREAM_INTERVAL=5
```

Po zmianie ustawień zrestartuj proces PHP, aby nowe wartości zostały odczytane. Mniejsza
częstotliwość (czyli większy interwał, np. 5–10 sekund) sprawdzi się, gdy Raspberry Pi ma
ograniczone zasoby CPU, monitorujesz wolniejsze usługi zewnętrzne lub chcesz zmniejszyć
generowany ruch sieciowy. Interwał jest przekazywany również do front-endu, dzięki czemu
panel może dopasować swoje komunikaty oraz zapasowe odświeżanie do realnego tempa
strumienia.

## Sterowanie Shelly

Panel zawiera dodatkową zakładkę pozwalającą monitorować i przełączać przekaźniki Shelly
z poziomu przeglądarki.

### Konfiguracja

1. W pliku `config/shelly.php` zdefiniuj urządzenia, podając etykietę i adres URL
   (np. `http://192.168.0.10`). Domyślnie dołączone są przykłady `boiler` oraz `gate`.
2. Host można nadpisać zmienną środowiskową `APP_SHELLY_<ID>_HOST`, np.
   `APP_SHELLY_BOILER_HOST`. Przydatne klucze:
   - `APP_SHELLY_<ID>_AUTH_KEY` – klucz dostępu (Bearer) do API Shelly,
   - `APP_SHELLY_<ID>_USERNAME` i `APP_SHELLY_<ID>_PASSWORD` – login i hasło do Basic Auth
     urządzenia.
3. Po zmianach pamiętaj o przeładowaniu PHP-FPM / serwera WWW, aby odczytać nową konfigurację.

### Wymagania

- Urządzenia Shelly muszą być osiągalne z serwera (ta sama sieć / przekierowany ruch).
- Włącz interfejs RPC w oprogramowaniu Shelly oraz ewentualną autoryzację (klucz lub Basic Auth).
- Dostęp do zakładki zabezpiecza ta sama ochrona HTTP Basic co resztę panelu.

### Bezpieczeństwo (CSRF)

- Podczas ładowania panelu generowany jest unikalny token CSRF zapisywany w ciasteczku `panel_csrf` (ważnym przez godzinę) oraz
  wstawiany do kodu HTML.
- Skrypty front-endu automatycznie dołączają token do wywołań `?shelly=list` i `?shelly=command` w nagłówku `X-CSRF-Token`
  (oraz jako pole `csrfToken` w JSON-ie polecenia).
- Backend porównuje token z ciasteczkiem i odrzuca żądania bez poprawnego identyfikatora, odpowiadając statusem HTTP `403`.
- Dodatkowo weryfikowany jest nagłówek `Origin`, więc polecenia muszą pochodzić bezpośrednio z panelu.
- Jeśli panel zgłosi błąd tokenu, odśwież stronę, aby wygenerować nową wartość i kontynuować pracę.

#### Wymagane rozszerzenia PHP

- Moduł Shelly wykorzystuje funkcje biblioteki cURL, dlatego na serwerze musi być zainstalowane rozszerzenie `php-curl`.

### Użycie

- Po zalogowaniu kliknij przycisk **Shelly** w nawigacji kart.
- Lista urządzeń pokazuje aktualny stan (`Włączone`, `Wyłączone`, bądź komunikat o błędzie).
- Każde urządzenie ma pojedynczy przycisk z ikoną zasilania, który przełącza
  przekaźnik pomiędzy stanami włącz/wyłącz; po każdej akcji etykieta i stan
  aktualizują się automatycznie.
- Błędy połączenia i komunikaty API są wyświetlane w formie czytelnych komunikatów nad listą.

### Diagnostyka konfiguracji

Jeśli chcesz ręcznie potwierdzić, że panel poprawnie reaguje na błędną konfigurację
Shelly (np. po zmianach w środowisku), możesz wykonać prosty test:

1. Ustaw zmienną środowiskową `APP_SHELLY_BOILER_HOST` na niepoprawny adres, np. `http://192.0.2.123`.
2. Przeładuj usługę PHP-FPM / serwer WWW i odśwież stronę panelu.
3. Zakładka **Shelly** wyświetli przyjazny komunikat o problemie z konfiguracją, natomiast
   pozostałe zakładki (status systemu, historia) pozostaną w pełni funkcjonalne.

Takie ręczne odtworzenie scenariusza pozwala upewnić się, że ewentualne błędy
w konfiguracji Shelly nie wpływają na działanie całego panelu.

## Historia metryk

Panel może zapisywać kolejne snapshoty stanu do pliku JSON i prezentować historię
na wykresach pokazujących m.in. temperaturę CPU, użycie pamięci RAM, zajętość dysku
oraz obciążenie systemu.

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
  Snapshots otrzymane przed upływem tego interwału są pomijane – ostatni zapis pozostaje
  niezmieniony, a nowy wpis pojawi się dopiero, gdy kolejne dane napłyną po przekroczeniu progu.

Dzięki temu historia zachowuje regularne odstępy czasowe i nie rozrasta się gwałtownie podczas
częstego odpytywania. Trzeba jednak pamiętać, że dostępne rekordy mogą być starsze maksymalnie o
ustawiony interwał, ponieważ wszystkie próbki otrzymane w krótszych odstępach czasu nie trafiają
do pliku.

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

Front-end rysuje wykresy metryk (domyślnie temperatury CPU) z wykorzystaniem elementu SVG
generowanego przez JavaScript. Wykorzystuje wyłącznie natywne możliwości przeglądarki, więc
aplikacja nie ładuje żadnych bibliotek zewnętrznych (np. Chart.js) ani zasobów z CDN.
Pod wykresem znajdziesz przełącznik, który pozwala wybrać jedną z dostępnych metryk (temperatura,
pamięć, dysk, obciążenie). Wybrana opcja jest zapisywana w `localStorage`, dlatego po odświeżeniu
strony panel od razu pokazuje preferowany wskaźnik.
Wykres aktualizuje się automatycznie po wczytaniu strony, ręcznym odświeżeniu oraz podczas pracy
w trybie strumieniowym. Jeżeli historia jest wyłączona lub niedostępna, panel wyświetli
odpowiedni komunikat. Wygląd i zachowanie wykresu możesz dopasować, modyfikując pliki
`public/index.php` oraz `public/styles.css`.
