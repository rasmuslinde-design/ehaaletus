<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

// --- Contract ---
// Input (POST): voter_id (int), otsus ('poolt'|'vastu')
// Output: HTML page with form + latest scoreboard from TULEMUSED

$flash = null; // ['type' => 'success'|'error', 'message' => string]

function h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// Handle vote submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $voterId = filter_input(INPUT_POST, 'voter_id', FILTER_VALIDATE_INT);
    $otsusRaw = (string)($_POST['otsus'] ?? '');
    $otsus = in_array($otsusRaw, ['poolt', 'vastu'], true) ? $otsusRaw : null;

    if (!$voterId || !$otsus) {
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
        } catch (Throwable $e) {
            $flash = ['type' => 'error', 'message' => 'Midagi läks valesti hääle salvestamisel.'];
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

// Fetch latest scoreboard row
$score = ['osalejate_arv' => 0, 'poolt' => 0, 'vastu' => 0, 'h_alguse_aeg' => null];
try {
    $result = $conn->query('SELECT h_alguse_aeg, osalejate_arv, poolt, vastu FROM TULEMUSED ORDER BY id DESC LIMIT 1');
    $r = $result->fetch_assoc();
    if ($r) {
        $score = array_merge($score, $r);
    }
} catch (Throwable $e) {
    // Keep defaults
}

$selectedVoterId = (int)($_POST['voter_id'] ?? 0);
$selectedOtsus = (string)($_POST['otsus'] ?? '');

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
                $suffix = $current ? " (praegu: {$current})" : '';
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
          <button type="submit">Salvesta hääl</button>
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
</body>
</html>
