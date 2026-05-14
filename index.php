<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

// --- Contract ---
// Input (POST): voter_id (int), otsus ('poolt'|'vastu')
// Output: HTML page with form + latest scoreboard from TULEMUSED

$flash = null; // ['type' => 'success'|'error', 'message' => string]

// Voting window length (seconds)
$VOTING_WINDOW_SECONDS = 5 * 60;

function h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Loads the latest voting session from TULEMUSED.
 * Returns associative array with keys: id, h_alguse_aeg, osalejate_arv, poolt, vastu
 */
function load_latest_session(mysqli $conn): ?array {
  $result = $conn->query('SELECT id, h_alguse_aeg, osalejate_arv, poolt, vastu FROM TULEMUSED ORDER BY id DESC LIMIT 1');
  $row = $result->fetch_assoc();
  return $row ?: null;
}

/**
 * Recalculates session totals from HAALETUS (live counts) and writes them into TULEMUSED row.
 */
function update_session_totals(mysqli $conn, int $sessionId): void {
  $res = $conn->query(
    "SELECT\n"
    . "  SUM(CASE WHEN otsus IN ('poolt','vastu') THEN 1 ELSE 0 END) AS osalejate_arv,\n"
    . "  SUM(CASE WHEN otsus = 'poolt' THEN 1 ELSE 0 END) AS poolt,\n"
    . "  SUM(CASE WHEN otsus = 'vastu' THEN 1 ELSE 0 END) AS vastu\n"
    . "FROM HAALETUS"
  );
  $counts = $res->fetch_assoc() ?: ['osalejate_arv' => 0, 'poolt' => 0, 'vastu' => 0];
  $osalejate = (int)($counts['osalejate_arv'] ?? 0);
  $poolt = (int)($counts['poolt'] ?? 0);
  $vastu = (int)($counts['vastu'] ?? 0);

  $stmt = $conn->prepare('UPDATE TULEMUSED SET osalejate_arv = ?, poolt = ?, vastu = ? WHERE id = ?');
  $stmt->bind_param('iiii', $osalejate, $poolt, $vastu, $sessionId);
  $stmt->execute();
  $stmt->close();
}

// Load current voting session state
$session = null;
$sessionActive = false;
$sessionEndsAtTs = null;
try {
  $session = load_latest_session($conn);
  if ($session && !empty($session['h_alguse_aeg'])) {
    $startTs = strtotime((string)$session['h_alguse_aeg']);
    if ($startTs !== false) {
      $sessionEndsAtTs = $startTs + $VOTING_WINDOW_SECONDS;
      $sessionActive = time() < $sessionEndsAtTs;
    }
  }
} catch (Throwable $e) {
  // session remains null
}

// Handle vote submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  // Start voting button
  if (isset($_POST['start_voting'])) {
    try {
      $stmt = $conn->prepare('INSERT INTO TULEMUSED (h_alguse_aeg, osalejate_arv, poolt, vastu) VALUES (NOW(), 0, 0, 0)');
      $stmt->execute();
      $stmt->close();

  // Use PRG (Post/Redirect/Get) so the page reloads with fresh session state
  // and the timer starts immediately without any stale state/caching.
  header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?') . '?started=1');
  exit;
    } catch (Throwable $e) {
      $flash = ['type' => 'error', 'message' => 'Hääletuse alustamine ebaõnnestus.'];
    }
  } else {
    $voterId = filter_input(INPUT_POST, 'voter_id', FILTER_VALIDATE_INT);
    $otsusRaw = (string)($_POST['otsus'] ?? '');
    $otsus = in_array($otsusRaw, ['poolt', 'vastu'], true) ? $otsusRaw : null;

  // Enforce active session server-side (prevents DB changes after 5 minutes)
  if (!$sessionActive) {
    $flash = ['type' => 'error', 'message' => 'Hääletus ei ole aktiivne või aeg on läbi.'];
  } elseif (!$voterId || !$otsus) {
        $flash = ['type' => 'error', 'message' => 'Palun vali nimi ja otsus (poolt/vastu).'];
    } else {
        try {
            // Fetch current decision for logging
            $stmt = $conn->prepare('SELECT otsus FROM HAALETUS WHERE id = ? LIMIT 1');
            $stmt->bind_param('i', $voterId);
            $stmt->execute();
            $res = $stmt->get_result();
            $row = $res->fetch_assoc();
            $old = $row['otsus'] ?? null;
            $stmt->close();

            // Update vote
            $stmt = $conn->prepare('UPDATE HAALETUS SET otsus = ? WHERE id = ?');
            $stmt->bind_param('si', $otsus, $voterId);
            $stmt->execute();
            $stmt->close();

            // Optional: log changes (won't fail the whole request if LOGI is missing perms)
            try {
                $stmt = $conn->prepare('INSERT INTO LOGI (haaletaja_id, vana_otsus, uus_otsus, muutmise_aeg) VALUES (?, ?, ?, NOW())');
                $stmt->bind_param('iss', $voterId, $old, $otsus);
                $stmt->execute();
                $stmt->close();
            } catch (Throwable $e) {
                // ignore
            }

            $flash = ['type' => 'success', 'message' => 'Hääl salvestatud.'];

      // Keep TULEMUSED in sync for the live scoreboard
      if ($session && isset($session['id'])) {
        try {
          update_session_totals($conn, (int)$session['id']);
        } catch (Throwable $e) {
          // ignore
        }
      }
        } catch (Throwable $e) {
            $flash = ['type' => 'error', 'message' => 'Midagi läks valesti hääle salvestamisel.'];
        }
    }
  }
}

// Fetch voters (11)
$voters = [];
try {
    $result = $conn->query('SELECT id, eesnimi, perenimi, otsus FROM HAALETUS ORDER BY id ASC LIMIT 11');
    while ($r = $result->fetch_assoc()) {
        $voters[] = $r;
    }
} catch (Throwable $e) {
    // Leave empty; UI will show message
}

// Scoreboard: show results from latest session row in TULEMUSED
$score = ['osalejate_arv' => 0, 'poolt' => 0, 'vastu' => 0, 'h_alguse_aeg' => null];
try {
  $latest = load_latest_session($conn);
  if ($latest) {
    $score = array_merge($score, $latest);
  }
} catch (Throwable $e) {
  // Keep defaults
}

$selectedVoterId = (int)($_POST['voter_id'] ?? 0);
$selectedOtsus = (string)($_POST['otsus'] ?? '');

if (!$flash && isset($_GET['started'])) {
  $flash = ['type' => 'success', 'message' => 'Hääletus alustatud. Aega on 5 minutit.'];
}

?><!doctype html>
<html lang="et">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Hääletus</title>
  <link rel="stylesheet" href="styles.css" />
</head>
<body>
  <div class="container">
    <div class="header">
      <h1>Hääletus</h1>
      <p>Vali hääletaja ja anna hääl (“poolt” või “vastu”).</p>
    </div>

    <div class="card">
      <div class="session">
        <div>
          <div class="session-title">Hääletuse staatus</div>
          <div class="session-sub">
            <?php if ($sessionActive): ?>
              Hääletus on aktiivne.
            <?php else: ?>
              Hääletus ei ole aktiivne.
            <?php endif; ?>
          </div>
        </div>

        <div class="timer" aria-label="Taimer">
          <div class="timer-label">Aega jäänud</div>
          <div id="timer-value" class="timer-value">--:--</div>
          <div id="timer-bar" class="timer-bar" style="--p: 0%;"></div>
          <?php if (!$sessionEndsAtTs): ?>
            <div class="small">Alusta hääletust, et taimer käivituks.</div>
          <?php endif; ?>
        </div>

        <form method="post" action="" class="start-form">
          <button type="submit" name="start_voting" value="1" <?= $sessionActive ? 'disabled' : '' ?>>Alusta hääletust (5 min)</button>
          <div class="small">Käivitab uue hääletuse ja avab 5 minuti akna.</div>
        </form>
      </div>

      <form method="post" action="">
        <div class="row">
          <div>
            <label for="voter_id">Hääletaja</label>
            <select id="voter_id" name="voter_id" required>
              <option value="" disabled <?= $selectedVoterId ? '' : 'selected' ?>>Vali nimi…</option>
              <?php foreach ($voters as $v):
                $id = (int)$v['id'];
                $name = trim(($v['eesnimi'] ?? '') . ' ' . ($v['perenimi'] ?? ''));
                $current = (string)($v['otsus'] ?? '');
                // Kuvame hetkevaliku ainult siis, kui otsus on olemas (poolt/vastu)
                $suffix = $current !== '' ? " (Valik: {$current})" : '';
              ?>
                <option value="<?= $id ?>" <?= $selectedVoterId === $id ? 'selected' : '' ?>><?= h($name . $suffix) ?></option>
              <?php endforeach; ?>
            </select>
            <?php if (count($voters) === 0): ?>
              <div class="small" style="margin-top:8px;">Ei saanud hääletajate nimekirja laadida. Kontrolli DB ühendust ja tabelit `HAALETUS`.</div>
            <?php endif; ?>
          </div>

          <div>
            <label>Otsus</label>
            <fieldset>
              <legend>Vali üks</legend>
              <div class="radio-group">
                <label class="radio">
                  <input type="radio" name="otsus" value="poolt" required <?= $selectedOtsus === 'poolt' ? 'checked' : '' ?> />
                  poolt
                </label>
                <label class="radio">
                  <input type="radio" name="otsus" value="vastu" required <?= $selectedOtsus === 'vastu' ? 'checked' : '' ?> />
                  vastu
                </label>
              </div>
            </fieldset>
          </div>
        </div>

        <div class="actions">
          <button type="submit" <?= $sessionActive ? '' : 'disabled' ?>>Salvesta hääl</button>
          <span class="small">Andmed salvestatakse tabelisse `HAALETUS`.</span>
        </div>

        <?php if ($flash): ?>
          <div class="notice <?= h($flash['type']) ?>" role="status">
            <?= h($flash['message']) ?>
          </div>
        <?php endif; ?>
      </form>

      <div class="scoreboard" aria-label="Tulemused">
        <div class="stat">
          <div class="k">Kokku hääli</div>
          <div class="v"><?= (int)$score['osalejate_arv'] ?></div>
        </div>
        <div class="stat">
          <div class="k">Poolt</div>
          <div class="v" style="color: var(--success);">
            <?= (int)$score['poolt'] ?>
          </div>
        </div>
        <div class="stat">
          <div class="k">Vastu</div>
          <div class="v" style="color: var(--danger);">
            <?= (int)$score['vastu'] ?>
          </div>
        </div>
      </div>

      <div class="footer">
        <?php if (!empty($score['h_alguse_aeg'])): ?>
          Viimane seisu uuendus: <?= h((string)$score['h_alguse_aeg']) ?>
        <?php else: ?>
          Seisu allikas: tabel `TULEMUSED` (viimane rida).
        <?php endif; ?>
      </div>
    </div>

    <div class="footer">
      <div class="small">Nipp: cPanelis pane samad ühenduse andmed ka `db.php` failis või kasuta keskkonnamuutujaid (DB_HOST/DB_NAME/DB_USER/DB_PASS).</div>
    </div>
  </div>

  <script>
    (function () {
      const endsAt = <?= $sessionEndsAtTs ? (int)$sessionEndsAtTs : 'null' ?>;
  const isActive = <?= $sessionActive ? 'true' : 'false' ?>;
      const windowSeconds = <?= (int)$VOTING_WINDOW_SECONDS ?>;
      const el = document.getElementById('timer-value');
      const bar = document.getElementById('timer-bar');

      function pad(n) {
        return String(n).padStart(2, '0');
      }

      function tick() {
        if (!el) return;
        if (!endsAt) {
          el.textContent = '--:--';
          if (bar) bar.style.setProperty('--p', '0%');
          return;
        }
        const now = Math.floor(Date.now() / 1000);
        let remaining = endsAt - now;
        if (remaining < 0) remaining = 0;

        const mm = Math.floor(remaining / 60);
        const ss = remaining % 60;
        el.textContent = pad(mm) + ':' + pad(ss);

        if (bar) {
          const p = Math.max(0, Math.min(1, remaining / windowSeconds));
          bar.style.setProperty('--p', Math.round(p * 100) + '%');
        }

        if (remaining === 0) {
          // Session ended: show 00:00 and disable voting until refresh.
          const buttons = document.querySelectorAll('form button[type="submit"]');
          buttons.forEach((b) => {
            if (b.name !== 'start_voting') b.disabled = true;
          });
        } else {
          setTimeout(tick, 250);
        }
      }

  // If server says session is active, start ticking immediately.
  // If not active, still render a stable UI.
  if (isActive) tick();
  else tick();
    })();
  </script>
</body>
</html>
