<?php

function router_mask_to_prefix(string $mask): int {
    $parts = array_map('intval', explode('.', $mask));
    if (count($parts) !== 4) return 0;
    $bits = '';
    foreach ($parts as $part) {
        if ($part < 0 || $part > 255) return 0;
        $bits .= str_pad(decbin($part), 8, '0', STR_PAD_LEFT);
    }
    if (!preg_match('/^1*0*$/', $bits)) return 0;
    return substr_count($bits, '1');
}

function router_guess_platform(string $hostname, string $config): string {
    $haystack = strtoupper($hostname . "\n" . substr($config, 0, 12000));
    if (str_contains($haystack, 'S9306') || str_contains($haystack, 'S9300')) return 'S9306';
    if (str_contains($haystack, 'NE40')) return 'NE40E / NE40EX8';
    if (str_contains($haystack, 'S12700')) return 'S12700';
    if (str_contains($haystack, 'E8000') || str_contains($haystack, 'E9000')) return 'Huawei Edge';
    return 'Huawei';
}

function router_clean_description(string $description): string {
    return trim($description, " \t\n\r\0\x0B*\"'");
}

function router_guess_client(string $description): string {
    $d = router_clean_description($description);
    if ($d === '') return '';

    if (preg_match('/^ESSClient[_\s]+(?:DIA|MPLS|DPLC|IPLC|Central|Turbonet)[_\s]+([^_]+?)(?:[_\s]+\d+(?:Mbps|Gbps)|[_\s]+LNK|[_\s]+DIA\d|$)/i', $d, $m)) {
        return trim($m[1], " _-");
    }

    $parts = array_values(array_filter(array_map('trim', explode('_', $d)), fn($v) => $v !== ''));
    if (count($parts) >= 3 && strcasecmp($parts[0], 'ESSClient') === 0) {
        return $parts[2];
    }
    return $d;
}

function router_parse_config(string $config, string $sourceName = ''): array {
    $config = str_replace(["\r\n", "\r"], "\n", $config);
    $lines = explode("\n", $config);

    $hostname = '';
    $interfaces = [];
    $vrfs = [];
    $peers = [];
    $prefixLists = [];
    $routePolicies = [];

    $currentInterface = null;
    $currentBgpVrf = '';
    $inBgp = false;

    $ensurePeer = function(string $vrf, string $ip) use (&$peers): int {
        foreach ($peers as $idx => $peer) {
            if ($peer['vrf'] === $vrf && $peer['peer_ip'] === $ip) return $idx;
        }
        $peers[] = [
            'vrf' => $vrf,
            'peer_ip' => $ip,
            'remote_asn' => '',
            'import_policy' => '',
            'export_policy' => '',
            'import_prefix' => '',
            'export_prefix' => ''
        ];
        return count($peers) - 1;
    };

    foreach ($lines as $rawLine) {
        $trim = trim($rawLine);
        if ($trim === '') continue;

        if ($hostname === '' && preg_match('/^sysname\s+(.+)$/i', $trim, $m)) {
            $hostname = trim($m[1]);
            continue;
        }

        if ($trim === '#') {
            $currentInterface = null;
            continue;
        }

        if (preg_match('/^interface\s+(.+)$/i', $trim, $m)) {
            $name = trim($m[1]);
            $interfaces[$name] = $interfaces[$name] ?? [
                'name' => $name,
                'description' => '',
                'client_name' => '',
                'vlan_id' => '',
                'vrf' => '',
                'bandwidth_kbps' => '',
                'ips' => []
            ];
            $currentInterface = $name;
            $currentBgpVrf = '';
            continue;
        }

        if ($currentInterface !== null) {
            if (preg_match('/^description\s+(.+)$/i', $trim, $m)) {
                $interfaces[$currentInterface]['description'] = router_clean_description($m[1]);
                $interfaces[$currentInterface]['client_name'] = router_guess_client($m[1]);
                continue;
            }
            if (preg_match('/^vlan-type\s+dot1q\s+(\d+)/i', $trim, $m) || preg_match('/^dot1q\s+termination\s+vid\s+(\d+)/i', $trim, $m)) {
                $interfaces[$currentInterface]['vlan_id'] = $m[1];
                continue;
            }
            if (preg_match('/^ip\s+binding\s+vpn-instance\s+(\S+)/i', $trim, $m)) {
                $interfaces[$currentInterface]['vrf'] = $m[1];
                $vrfs[$m[1]] = true;
                continue;
            }
            if (preg_match('/^ip\s+address\s+(\d+\.\d+\.\d+\.\d+)\s+(\d+\.\d+\.\d+\.\d+)(?:\s+(sub))?/i', $trim, $m)) {
                $interfaces[$currentInterface]['ips'][] = [
                    'ip_address' => $m[1],
                    'subnet_mask' => $m[2],
                    'prefix_length' => router_mask_to_prefix($m[2]),
                    'is_secondary' => !empty($m[3]) ? 1 : 0
                ];
                continue;
            }
            if (preg_match('/^qos\s+car\s+cir\s+(\d+)/i', $trim, $m)) {
                $interfaces[$currentInterface]['bandwidth_kbps'] = $m[1];
                continue;
            }
        }

        if (preg_match('/^ip\s+vpn-instance\s+(\S+)/i', $trim, $m)) {
            $vrfs[$m[1]] = true;
            continue;
        }

        if (preg_match('/^ip\s+ip-prefix\s+(\S+)/i', $trim, $m)) {
            $prefixLists[$m[1]] = true;
            continue;
        }

        if (preg_match('/^route-policy\s+(\S+)\s+(?:permit|deny)/i', $trim, $m)) {
            $routePolicies[$m[1]] = true;
            continue;
        }

        if (preg_match('/^bgp\s+(\d+)/i', $trim)) {
            $inBgp = true;
            $currentInterface = null;
            $currentBgpVrf = '';
            continue;
        }

        if ($inBgp && preg_match('/^ipv4-family\s+vpn-instance\s+(\S+)/i', $trim, $m)) {
            $currentBgpVrf = $m[1];
            $vrfs[$m[1]] = true;
            continue;
        }

        if ($inBgp && preg_match('/^ipv4-family\s+(?:unicast|vpnv4)/i', $trim)) {
            $currentBgpVrf = '';
            continue;
        }

        if ($inBgp && preg_match('/^peer\s+(\d+\.\d+\.\d+\.\d+)\s+as-number\s+(\d+)/i', $trim, $m)) {
            $idx = $ensurePeer($currentBgpVrf, $m[1]);
            $peers[$idx]['remote_asn'] = $m[2];
            continue;
        }

        if ($inBgp && preg_match('/^peer\s+(\d+\.\d+\.\d+\.\d+)\s+route-policy\s+(\S+)\s+(import|export)/i', $trim, $m)) {
            $idx = $ensurePeer($currentBgpVrf, $m[1]);
            $peers[$idx][strtolower($m[3]) . '_policy'] = $m[2];
            $routePolicies[$m[2]] = true;
            continue;
        }

        if ($inBgp && preg_match('/^peer\s+(\d+\.\d+\.\d+\.\d+)\s+ip-prefix\s+(\S+)\s+(import|export)/i', $trim, $m)) {
            $idx = $ensurePeer($currentBgpVrf, $m[1]);
            $peers[$idx][strtolower($m[3]) . '_prefix'] = $m[2];
            $prefixLists[$m[2]] = true;
            continue;
        }
    }

    if ($hostname === '') {
        $hostname = $sourceName !== '' ? pathinfo($sourceName, PATHINFO_FILENAME) : 'Imported-Huawei-Router';
    }

    foreach ($interfaces as &$iface) {
        if ($iface['client_name'] === '' && $iface['description'] !== '') {
            $iface['client_name'] = router_guess_client($iface['description']);
        }
        if ($iface['vlan_id'] === '' && preg_match('/\.(\d+)$/', $iface['name'], $m)) {
            $iface['vlan_id'] = $m[1];
        }
    }
    unset($iface);

    return [
        'hostname' => $hostname,
        'platform' => router_guess_platform($hostname, $config),
        'interfaces' => array_values($interfaces),
        'vrfs' => array_keys($vrfs),
        'peers' => $peers,
        'prefix_lists' => array_keys($prefixLists),
        'route_policies' => array_keys($routePolicies)
    ];
}

function router_import_config(PDO $pdo, string $config, string $sourceName = '', string $hostnameOverride = ''): array {
    $parsed = router_parse_config($config, $sourceName);
    if ($hostnameOverride !== '') $parsed['hostname'] = trim($hostnameOverride);

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("SELECT id FROM router_devices WHERE hostname = ? COLLATE NOCASE LIMIT 1");
        $stmt->execute([$parsed['hostname']]);
        $deviceId = (int)($stmt->fetchColumn() ?: 0);

        if ($deviceId > 0) {
            $pdo->prepare("UPDATE router_devices SET platform = ?, source_name = ?, imported_at = CURRENT_TIMESTAMP WHERE id = ?")
                ->execute([$parsed['platform'], $sourceName, $deviceId]);
            foreach (['router_bgp_peers','router_prefix_lists','router_route_policies','router_vrfs'] as $table) {
                $pdo->prepare("DELETE FROM {$table} WHERE device_id = ?")->execute([$deviceId]);
            }
            $interfaceIds = $pdo->prepare("SELECT id FROM router_interfaces WHERE device_id = ?");
            $interfaceIds->execute([$deviceId]);
            $ids = $interfaceIds->fetchAll(PDO::FETCH_COLUMN);
            if ($ids) {
                $marks = implode(',', array_fill(0, count($ids), '?'));
                $pdo->prepare("DELETE FROM router_interface_ips WHERE interface_id IN ({$marks})")->execute($ids);
            }
            $pdo->prepare("DELETE FROM router_interfaces WHERE device_id = ?")->execute([$deviceId]);
        } else {
            $pdo->prepare("INSERT INTO router_devices (hostname, platform, source_name) VALUES (?, ?, ?)")
                ->execute([$parsed['hostname'], $parsed['platform'], $sourceName]);
            $deviceId = (int)$pdo->lastInsertId();
        }

        $vrfStmt = $pdo->prepare("INSERT INTO router_vrfs (device_id, vrf_name) VALUES (?, ?)");
        foreach ($parsed['vrfs'] as $vrf) $vrfStmt->execute([$deviceId, $vrf]);

        $ifStmt = $pdo->prepare("INSERT INTO router_interfaces (device_id, interface_name, description, client_name, vlan_id, vrf, bandwidth_kbps) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $ipStmt = $pdo->prepare("INSERT INTO router_interface_ips (interface_id, ip_address, subnet_mask, prefix_length, is_secondary) VALUES (?, ?, ?, ?, ?)");
        foreach ($parsed['interfaces'] as $iface) {
            $ifStmt->execute([
                $deviceId, $iface['name'], $iface['description'], $iface['client_name'],
                $iface['vlan_id'], $iface['vrf'], $iface['bandwidth_kbps']
            ]);
            $interfaceId = (int)$pdo->lastInsertId();
            foreach ($iface['ips'] as $ip) {
                $ipStmt->execute([$interfaceId, $ip['ip_address'], $ip['subnet_mask'], $ip['prefix_length'], $ip['is_secondary']]);
            }
        }

        $peerStmt = $pdo->prepare("INSERT INTO router_bgp_peers (device_id, vrf, peer_ip, remote_asn, import_policy, export_policy, import_prefix, export_prefix) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        foreach ($parsed['peers'] as $peer) {
            $peerStmt->execute([
                $deviceId, $peer['vrf'], $peer['peer_ip'], $peer['remote_asn'],
                $peer['import_policy'], $peer['export_policy'], $peer['import_prefix'], $peer['export_prefix']
            ]);
        }

        $prefixStmt = $pdo->prepare("INSERT INTO router_prefix_lists (device_id, name) VALUES (?, ?)");
        foreach ($parsed['prefix_lists'] as $name) $prefixStmt->execute([$deviceId, $name]);

        $policyStmt = $pdo->prepare("INSERT INTO router_route_policies (device_id, name) VALUES (?, ?)");
        foreach ($parsed['route_policies'] as $name) $policyStmt->execute([$deviceId, $name]);

        $pdo->commit();

        return [
            'device_id' => $deviceId,
            'hostname' => $parsed['hostname'],
            'platform' => $parsed['platform'],
            'interfaces' => count($parsed['interfaces']),
            'vrfs' => count($parsed['vrfs']),
            'bgp_peers' => count($parsed['peers']),
            'prefix_lists' => count($parsed['prefix_lists']),
            'route_policies' => count($parsed['route_policies'])
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}
