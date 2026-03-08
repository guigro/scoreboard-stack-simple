# Action Game Scoreboard (Simple) — Score only

This simplified version keeps **only the score** (no chests).

## Expected Google Sheet columns
Headers (exact):
- `DateTime` → UNIX timestamp (GMT), seconds or milliseconds
- `TeamName`
- `ScoreTotal`

## Deploy the Apps Script API
1. Google Sheet → Extensions → Apps Script
2. Replace `Code.gs` with `apps-script/scoreboard_score_only.gs`
3. Edit at top:
   - `SHEET_NAME`
   - `API_KEY`
4. Project Settings → Time zone: `Europe/Paris` (recommended)
5. Deploy → New deployment → Web app (Execute as Me, Anyone with link)
6. Copy the `/exec` URL

Test:
`.../exec?type=top&limit=10&window=week&key=YOUR_KEY&nocache=1`

## Standalone PHP
1. Copy `php-standalone/` to your server
2. `config.php.example` → `config.php`
3. Set `AG_API_URL` and `AG_API_KEY`

URLs:
- Top week: `scoreboard.php?view=top&period=week`
- Top all: `scoreboard.php?view=top&period=all`
- Recent: `scoreboard.php?view=recent`
- Totals: `scoreboard.php?view=totals`

Kiosk:
- `scoreboard.php?kiosk=1&token=YOUR_KIOSK_TOKEN`

Check the youtube demo : https://youtu.be/thLpJQFPBtg