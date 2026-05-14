<?php
/**
 * Database connection helper.
 *
 * Configure using environment variables (recommended), or edit the fallback values.
 *
 * Required env vars (preferred):
 *   DB_HOST, DB_NAME, DB_USER, DB_PASS
 */

declare(strict_types=1);

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$DB_HOST = getenv('DB_HOST') ?: 'localhost';
$DB_NAME = getenv('DB_NAME') ?: 'vso25polluste_haaletussysteem';

// cPanelis on DB kasutaja ja DB nimi tihti prefiksiga kujul: kasutaja_andmebaas
// Näited (asenda enda omadega):
//   DB_NAME = vso25polluste_haaletussysteem
//   DB_USER = vso25polluste_mysqlkasutaja
$DB_USER = getenv('DB_USER') ?: 'vso25polluste_vso25';

// Parool peab olema täpselt see, mis MySQL kasutajat tehes pandi.
$DB_PASS = getenv('DB_PASS') ?: 'Mannikabi14';

try {
    $conn = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME);
    $conn->set_charset('utf8mb4');
} catch (mysqli_sql_exception $e) {
    http_response_code(500);
    // Don't leak credentials/host info in production.
    exit('Database connection failed. Please check db.php configuration.');
}
