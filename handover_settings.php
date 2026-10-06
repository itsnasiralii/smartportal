<?php
// Shared configuration only; operational handovers retain the existing browser storage.
function handover_settings($pdo) {
    $pdo->exec('CREATE TABLE IF NOT EXISTS handover_settings (id INTEGER PRIMARY KEY, settings TEXT NOT NULL)');
    $saved = json_decode($pdo->query('SELECT settings FROM handover_settings WHERE id = 1')->fetchColumn() ?: '{}', true);
    return array_merge([
        'vendors' => array_values(array_unique(array_merge($pdo->query('SELECT name FROM vendors ORDER BY name')->fetchAll(PDO::FETCH_COLUMN), ['Digital Links']))),
        'teams' => ['Regulatory', 'NSS', 'TWA', 'PIE'],
        'regions' => ['RCBS North', 'RCBS South', 'RCBS Central'],
        'comments' => ['CE issue', 'MV issue', 'Stats awaited', 'CE traces awaited', 'Restored | RCA awaited'],
        'overdueMinutes' => 30,
    ], is_array($saved) ? $saved : []);
}
