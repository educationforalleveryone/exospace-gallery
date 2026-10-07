<?php

/**
 * Standalone decrypter for Exospace backup archives (format "EXOBAK01").
 * Needs only PHP with the sodium extension — no Laravel, no composer.
 *
 *   BACKUP_PASSWORD='...' php scripts/backup-decrypt.php "2026-10-07-01-00-19.zip" restored.zip
 *   unzip restored.zip        # then restore db-dumps/*.sql and the files as in docs/DISASTER-RECOVERY.md
 *
 * The passphrase is read from the BACKUP_PASSWORD environment variable so it
 * never appears in the process list or shell history.
 * Format: see app/Services/BackupArchiveCipher.php.
 */
if (PHP_SAPI !== 'cli') {
    exit(1);
}

if ($argc !== 3) {
    fwrite(STDERR, "usage: BACKUP_PASSWORD=... php backup-decrypt.php <encrypted.zip> <output.zip>\n");
    exit(2);
}

if (! function_exists('sodium_crypto_pwhash')) {
    fwrite(STDERR, "the sodium PHP extension is required\n");
    exit(2);
}

$pass = getenv('BACKUP_PASSWORD');

if (! is_string($pass) || $pass === '') {
    fwrite(STDERR, "set BACKUP_PASSWORD in the environment\n");
    exit(2);
}

[, $srcPath, $dstPath] = $argv;

function fail(string $msg, $out = null, ?string $dst = null): never
{
    if (is_resource($out)) {
        fclose($out);
    }
    if ($dst !== null && is_file($dst)) {
        unlink($dst);
    }
    fwrite(STDERR, "ERROR: $msg\n");
    exit(1);
}

function readExactly($h, int $n): ?string
{
    $d = '';
    while (strlen($d) < $n) {
        $p = fread($h, $n - strlen($d));
        if ($p === false || $p === '') {
            return $d === '' ? null : fail('unexpected end of file — archive is truncated');
        }
        $d .= $p;
    }

    return $d;
}

$in = @fopen($srcPath, 'rb') ?: fail("cannot read $srcPath");
$header = readExactly($in, 56);

if ($header === null || substr($header, 0, 8) !== 'EXOBAK01') {
    fail('not an Exospace encrypted backup (bad magic) — if it starts with "PK" it is a plain zip');
}

$l = unpack('Nops/Nmem', substr($header, 8, 8));
$key = sodium_crypto_pwhash(32, $pass, substr($header, 16, 16), $l['ops'], $l['mem'], SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13);
$state = sodium_crypto_secretstream_xchacha20poly1305_init_pull(substr($header, 32, 24), $key);
sodium_memzero($key);

$out = @fopen($dstPath, 'wb') ?: fail("cannot write $dstPath");
$final = false;
$first = true;

while (($lb = readExactly($in, 4)) !== null) {
    if ($final) {
        fail('unexpected data after the final record', $out, $dstPath);
    }
    $len = unpack('Nlen', $lb)['len'];
    if ($len < 17 || $len > 16777216 + 17) {
        fail('record length out of range — archive is corrupt', $out, $dstPath);
    }
    $c = readExactly($in, $len) ?? fail('archive is truncated mid-record', $out, $dstPath);
    $r = sodium_crypto_secretstream_xchacha20poly1305_pull($state, $c);
    if ($r === false) {
        fail($first ? 'decryption failed — wrong BACKUP_PASSWORD or corrupted archive' : 'authentication failed — archive corrupted or tampered with', $out, $dstPath);
    }
    $first = false;
    fwrite($out, $r[0]);
    $final = $r[1] === SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL;
}

if (! $final) {
    fail('archive is truncated — final record missing', $out, $dstPath);
}

fclose($out);
fclose($in);
fwrite(STDOUT, "OK: decrypted to $dstPath (".filesize($dstPath)." bytes)\n");
