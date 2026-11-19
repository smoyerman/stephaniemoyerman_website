<?php
/*
 * Desert Glass House: layout storage.
 * Upload this file next to desert-glass-house-layout.html and create an empty
 * folder named "layouts" beside it (the script will try to create it if missing).
 *
 * Anyone with the page link can open saved layouts.
 * Saving or deleting needs the passcode below. Change it before you upload.
 */
const PASSCODE   = 'passcode1234!';
const LAYOUT_DIR = __DIR__ . '/layouts';
const MAX_BYTES  = 1000000;   // largest layout file accepted
const MAX_FILES  = 200;       // most layouts kept in the folder

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function out($code, $arr) { http_response_code($code); echo json_encode($arr); exit; }
function slug($s) {
    $s = strtolower(trim((string)$s));
    $s = preg_replace('/[^a-z0-9]+/', '-', $s);
    return substr(trim($s, '-'), 0, 60);
}
function path_for($id) { return LAYOUT_DIR . '/' . $id . '.json'; }
function check_pass($given) {
    if (PASSCODE === 'change-me-please') out(403, ['error' => 'Set a passcode at the top of layouts.php before saving.']);
    if (!is_string($given) || !hash_equals(PASSCODE, $given)) out(403, ['error' => 'Wrong passcode.']);
}

if (!is_dir(LAYOUT_DIR) && !@mkdir(LAYOUT_DIR, 0755, true)) {
    out(500, ['error' => 'The layouts folder is missing and could not be created. Create a folder named layouts next to this file.']);
}

$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

if ($action === 'list' && $method === 'GET') {
    $rows = [];
    foreach (glob(LAYOUT_DIR . '/*.json') ?: [] as $f) {
        $d = json_decode((string)@file_get_contents($f), true);
        if (!is_array($d)) continue;
        $id = basename($f, '.json');
        $rows[] = [
            'id'    => $id,
            'name'  => $d['name'] ?? $id,
            'saved' => $d['saved'] ?? date('c', filemtime($f)),
            'items' => is_array($d['items'] ?? null) ? count($d['items']) : 0,
            'zones' => is_array($d['zones'] ?? null) ? count($d['zones']) : 0,
            't'     => filemtime($f),
        ];
    }
    usort($rows, function ($a, $b) { return $b['t'] <=> $a['t']; });
    out(200, ['layouts' => $rows]);
}

if ($action === 'load' && $method === 'GET') {
    $id = slug($_GET['id'] ?? '');
    $p = $id ? path_for($id) : '';
    if (!$id || !is_file($p)) out(404, ['error' => 'That layout was not found.']);
    echo file_get_contents($p);
    exit;
}

if ($method !== 'POST') out(400, ['error' => 'Unknown request.']);

$raw = file_get_contents('php://input');
if (strlen($raw) > MAX_BYTES + 4096) out(413, ['error' => 'That layout is too large.']);
$in = json_decode($raw, true);
if (!is_array($in)) out(400, ['error' => 'Could not read the request.']);
check_pass($in['pass'] ?? '');

if ($action === 'save') {
    $name = trim((string)($in['name'] ?? ''));
    $id = slug($name);
    if ($id === '') out(400, ['error' => 'Give the layout a name using letters or numbers.']);
    $data = $in['data'] ?? null;
    if (!is_array($data) || !is_array($data['items'] ?? null) || !is_array($data['zones'] ?? null)) {
        out(400, ['error' => 'That does not look like a layout.']);
    }
    $p = path_for($id);
    $exists = is_file($p);
    if ($exists && empty($in['overwrite'])) out(409, ['error' => 'exists']);
    if (!$exists && count(glob(LAYOUT_DIR . '/*.json') ?: []) >= MAX_FILES) {
        out(409, ['error' => 'The layouts folder is full. Delete an old layout first.']);
    }
    $file = [
        'app'     => 'desert-glass-house',
        'version' => 1,
        'name'    => function_exists('mb_substr') ? mb_substr($name, 0, 60) : substr($name, 0, 60),
        'saved'   => date('c'),
        'items'   => $data['items'],
        'zones'   => $data['zones'],
    ];
    $json = json_encode($file, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    if ($json === false || strlen($json) > MAX_BYTES) out(413, ['error' => 'That layout is too large.']);
    if (@file_put_contents($p, $json, LOCK_EX) === false) {
        out(500, ['error' => 'The server could not write to the layouts folder. Check that it is writable.']);
    }
    out(200, ['ok' => true, 'id' => $id, 'name' => $file['name']]);
}

if ($action === 'delete') {
    $id = slug($in['id'] ?? '');
    $p = $id ? path_for($id) : '';
    if (!$id || !is_file($p)) out(404, ['error' => 'That layout was not found.']);
    if (!@unlink($p)) out(500, ['error' => 'Could not delete that layout.']);
    out(200, ['ok' => true]);
}

out(400, ['error' => 'Unknown request.']);
