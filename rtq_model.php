<?php
function rtq_fields() { return ['CSP TT','Client','UniqueID','NMS User Label','Service Type','Bandwidth (Mbps)','Last Mile Vendor','Last Mile Medium','Account Region','Source City','Sink City','Reported Issue Category','Reported Issue Type','Complaint Date/Time','Customer Intimation Date/Time','Resolution Date / Time','Issue Found At','SLA Sheet Working','RCA','Status','Email Subject','Teams Involved in TroubleShooting']; }
function rtq_validate($input, $config) {
    $out = [];
    foreach (rtq_fields() as $key) {
        if (isset($input[$key]) && !is_scalar($input[$key])) throw new InvalidArgumentException('Invalid field: '.$key);
        $out[$key] = trim((string)($input[$key] ?? ''));
        if (strlen($out[$key]) > 4000) throw new InvalidArgumentException('Field too long: '.$key);
    }
    foreach (['NMS User Label','CSP TT','Issue Found At','SLA Sheet Working','RCA','Status'] as $key) if ($out[$key] === '') throw new InvalidArgumentException($key.' is required.');
    foreach (['Issue Found At','SLA Sheet Working','RCA','Status'] as $key) if (!in_array($out[$key],$config[$key],true)) throw new InvalidArgumentException('Select a current option for '.$key);
    foreach (['Complaint Date/Time','Customer Intimation Date/Time','Resolution Date / Time'] as $key) {
        if ($out[$key] === '') continue;
        $d = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $out[$key]);
        if (!$d || $d->format('Y-m-d\TH:i') !== $out[$key]) throw new InvalidArgumentException('Invalid date: '.$key);
    }
    $start=$out['Complaint Date/Time']; $end=$out['Resolution Date / Time']; $notice=$out['Customer Intimation Date/Time'];
    if ($end && (!$start || $end < $start)) throw new InvalidArgumentException('Resolution must be on or after complaint time.');
    if ($notice && $start && $notice < $start) throw new InvalidArgumentException('Intimation must be on or after complaint time.');
    return $out;
}
function rtq_validate_config($config) {
    $defaults=json_decode(file_get_contents(__DIR__.'/rtq_defaults.json'),true);
    if (!is_array($config)) throw new InvalidArgumentException('Settings must be a JSON object.');
    foreach ($defaults as $key=>$value) {
        if (!isset($config[$key]) || !is_array($config[$key]) || !count($config[$key]) || count($config[$key])>200) throw new InvalidArgumentException('Invalid settings: '.$key);
        if ($key!=='presets') foreach ($config[$key] as $option) if (!is_string($option) || trim($option)==='' || strlen($option)>200) throw new InvalidArgumentException('Invalid option: '.$key);
    }
    foreach ($config['presets'] as $preset) {
        if (!is_array($preset) || empty($preset['name']) || !is_string($preset['name'])) throw new InvalidArgumentException('Preset name required.');
        foreach (['Issue Found At','SLA Sheet Working','RCA','Status','Teams Involved in TroubleShooting'] as $key) if (!isset($preset[$key]) || !in_array($preset[$key],$config[$key],true)) throw new InvalidArgumentException('Invalid preset option: '.$key);
    }
    return array_intersect_key($config,$defaults);
}
