# RaspberryPiServer

Prosty panel statusu dla Raspberry Pi napisany w PHP.

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
przeglądarkę o konieczności podania loginu i hasła.
