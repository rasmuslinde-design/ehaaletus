# ehaaletus – simple PHP voting UI

This folder contains a minimal PHP + MySQL voting interface.

## Files

- `index.php` – voter dropdown + vote submit + scoreboard
- `db.php` – MySQL connection
- `styles.css` – basic UI styling

## Database tables expected

- `HAALETUS` (`id`, `eesnimi`, `perenimi`, `otsus`)
- `TULEMUSED` (`id`, `h_alguse_aeg`, `osalejate_arv`, `poolt`, `vastu`)
- `LOGI` (`id`, `haaletaja_id`, `vana_otsus`, `uus_otsus`, `muutmise_aeg`) _(optional; insert is best-effort)_

## Configure DB connection

`db.php` uses environment variables (recommended) and falls back to hardcoded values.

- Default `DB_NAME` fallback is set to: `vso25polluste_haaletussysteem`
- Update placeholders in `db.php` for:
  - `DB_USER`
  - `DB_PASS`
  - (optionally) `DB_HOST`

## Run locally (macOS)

You need PHP installed.

```zsh
brew install php
php -S 127.0.0.1:8000 -t /Users/rasmuslinde/Documents/ehaaletus/ehaaletus
```

Open:

- http://127.0.0.1:8000/index.php

### Local env vars (optional)

```zsh
export DB_HOST='localhost'
export DB_NAME='vso25polluste_haaletussysteem'
export DB_USER='...'
export DB_PASS='...'
php -S 127.0.0.1:8000 -t /Users/rasmuslinde/Documents/ehaaletus/ehaaletus
```

## Deploy to cPanel

1. Upload `index.php`, `db.php`, `styles.css` to **`public_html/`**.
2. Make sure the main file is named **exactly** `index.php` (lowercase). cPanel/Linux hosting is case-sensitive.
3. Set the correct MySQL credentials in `db.php` (or env vars, if supported).
4. Confirm the DB user has permissions for SELECT/UPDATE/INSERT on the three tables.

## VS Code extensions

- **PHP Intelephense** (`bmewburn.vscode-intelephense-client`) – PHP IntelliSense
- **PHP Server** (`brapifra.phpserver`) – quick local preview (optional)
- **Prettier** (`esbenp.prettier-vscode`) – HTML/CSS formatting (optional)
