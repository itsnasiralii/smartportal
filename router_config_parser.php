<?php

const ROUTER_PARSER_VERSION = 2;

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

function router_prefix_to_mask(int $prefix): string {
    $prefix = max(0, min(32, $prefix));
    if ($prefix === 0) return '0.0.0.0';
    $mask = (0xFFFFFFFF << (32 - $prefix)) & 0xFFFFFFFF;
    return long2ip($mask);
}

function router_clean_description(string $description): string {
    return trim($description, " \t\n\r\0\x0B*\"'");
}

function router_guess_platform(string $hostname, string $config): string {
    $haystack = strtoupper($hostname . "\n" . substr($config, 0, 16000));
    if (str_contains($haystack, 'S9306') || str_contains($haystack, 'S9300')) return 'S9306';
    if (str_contains($haystack, 'NE40') && str_contains($haystack, 'EGW')) return 'NE40E / EGW';
    if (str_contains($haystack, 'NE40')) return 'NE40E / NE40EX8';
    if (str_contains($haystack, 'S12700')) return 'S12700';
    if (str_contains($haystack, 'E8000') || str_contains($haystack, 'E9000')) return 'Huawei Edge';
    return 'Huawei';
}

function router_guess_role_site(string $hostname): array {
    $upper = strtoupper($hostname);
    $role = 'Router';
    foreach (['IGW', 'EGW', 'PE', 'CE', 'CDN'] as $candidate) {
        if (preg_match('/(^|-)' . preg_quote($candidate, '/') . '(-|$)/', $upper)) {
            $role = $candidate;
            break;
        }
    }

    $site = '';
    foreach (['LHR','KHI','ISB','RWP','FSD','MUX','MUL','PEW','PES','QTA','SKR','HYD'] as $candidate) {
        if (preg_match('/(^|-|_)' . $candidate . '(-|_|$)/', $upper)) {
            $site = $candidate;
            break;
        }
    }
    return [$role, $site];
}

function router_guess_client_from_vrf(string $vrf): string {
    if ($vrf === '') return '';
    if (preg_match('/^pt_psca(?:_|$)/i', $vrf)) return 'PSCA';
    if (preg_match('/^pt_(.+)$/i', $vrf, $m)) {
        $name = preg_replace('/_MPLS$/i', '', $m[1]);
        $name = str_replace(['_', '-'], ' ', $name);
        $name = trim(preg_replace('/\s+/', ' ', $name));
        return ucwords(strtolower($name));
    }
    return '';
}

function router_parse_service_metadata(string $description, string $vrf): array {
    $d = router_clean_description($description);
    $service = '';
    $client = router_guess_client_from_vrf($vrf);
    $site = '';
    $linkId = '';
    $bandwidth = '';

    if (preg_match('/^ESSClient[_\s]+([A-Za-z0-9-]+)[_\s]+(.+)$/i', $d, $m)) {
        $service = strtoupper($m[1]);
        $payload = trim($m[2]);

        if (preg_match('/\b((?:LNK|DIA|DPLC|IPLC)[A-Z0-9-]*\d[A-Z0-9-]*)\b/i', $payload, $lm)) {
            $linkId = $lm[1];
            $payload = str_ireplace($lm[0], '', $payload);
        }
        if (preg_match('/\b(\d+(?:\.\d+)?)\s*(Kbps|Mbps|Gbps)\b/i', $payload, $bm)) {
            $bandwidth = $bm[1] . $bm[2];
            $payload = str_ireplace($bm[0], '', $payload);
        }

        $payload = trim(str_replace(['***','**'], '', $payload), " _-");
        $payload = preg_replace('/\s+/', ' ', $payload);
        $vrfClient = router_guess_client_from_vrf($vrf);

        if ($vrfClient !== '' && preg_match('/^' . preg_quote($vrfClient, '/') . '(?:\s+|[-\/]+)(.+)$/i', $payload, $sm)) {
            $client = $vrfClient;
            $site = trim($sm[1], " _-");
        } elseif (str_contains($payload, '_')) {
            [$first, $rest] = array_pad(explode('_', $payload, 2), 2, '');
            $client = trim($first);
            $site = trim($rest);
        } elseif ($payload !== '') {
            $segments = preg_split('/\s{2,}/', $payload, 2);
            if (count($segments) === 2) {
                $client = trim($segments[0]);
                $site = trim($segments[1]);
            } elseif ($client === '') {
                $client = $payload;
            } else {
                $site = $payload;
            }
        }
    } else {
        if (preg_match('/\b(MPLS|DIA|DPLC|IPLC|TURBONET)\b/i', $d, $m)) $service = strtoupper($m[1]);
        if (preg_match('/\b((?:LNK|DIA|DPLC|IPLC)[A-Z0-9-]*\d[A-Z0-9-]*)\b/i', $d, $m)) $linkId = $m[1];
        if (preg_match('/\b(\d+(?:\.\d+)?)\s*(Kbps|Mbps|Gbps)\b/i', $d, $m)) $bandwidth = $m[1] . $m[2];
    }

    if ($client === '' && $d !== '') {
        $parts = array_values(array_filter(array_map('trim', explode('_', $d)), fn($v) => $v !== ''));
        if (count($parts) >= 3 && strcasecmp($parts[0], 'ESSClient') === 0) $client = $parts[2];
        elseif ($vrf !== '') $client = router_guess_client_from_vrf($vrf);
    }

    $site = trim(preg_replace('/\s+/', ' ', str_replace('_', ' ', $site)), " _-");
    $client = trim(preg_replace('/\s+/', ' ', str_replace('_', ' ', $client)), " _-");

    return [
        'service_type' => $service,
        'client_name' => $client,
        'site_name' => $site,
        'link_id' => $linkId,
        'bandwidth_label' => $bandwidth
    ];
}

function router_parse_static_route(string $line): ?array {
    $rest = trim(preg_replace('/^ip\s+route-static\s+/i', '', $line));
    $vrf = '';

    if (preg_match('/^vpn-instance\s+(\S+)\s+(.+)$/i', $rest, $m)) {
        $vrf = $m[1];
        $rest = $m[2];
    }

    if (!preg_match('/^(\d+\.\d+\.\d+\.\d+)\s+(\d+\.\d+\.\d+\.\d+|\d{1,2})\s*(.*)$/', $rest, $m)) return null;
    $destination = $m[1];
    $maskToken = $m[2];
    $tail = trim($m[3]);

    $prefix = str_contains($maskToken, '.') ? router_mask_to_prefix($maskToken) : (int)$maskToken;
    $mask = str_contains($maskToken, '.') ? $maskToken : router_prefix_to_mask($prefix);

    if ($vrf === '' && preg_match('/^vpn-instance\s+(\S+)\s+(.+)$/i', $tail, $vm)) {
        $vrf = $vm[1];
        $tail = $vm[2];
    }

    $description = '';
    if (preg_match('/\s+description\s+(.+)$/i', ' ' . $tail, $dm)) {
        $description = router_clean_description($dm[1]);
        $tail = trim(preg_replace('/\s+description\s+.+$/i', '', $tail));
    }

    $preference = null;
    if (preg_match('/\bpreference\s+(\d+)/i', $tail, $pm)) $preference = (int)$pm[1];

    $cleanTail = preg_replace('/\b(?:preference\s+\d+|track\s+\S+(?:\s+\S+)?|tag\s+\d+|bfd\s+\S+).*$/i', '', $tail);
    $tokens = preg_split('/\s+/', trim((string)$cleanTail));
    $outInterface = '';
    $nextHop = '';

    foreach ($tokens as $token) {
        if (filter_var($token, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $nextHop = $token;
            break;
        }
        if ($outInterface === '' && preg_match('/^(?:Vlanif|Eth-Trunk|GigabitEthernet|XGigabitEthernet|100GE|50GE|25GE|10GE|LoopBack)/i', $token)) {
            $outInterface = $token;
        }
    }

    return [
        'vrf' => $vrf,
        'destination' => $destination,
        'subnet_mask' => $mask,
        'prefix_length' => $prefix,
        'outgoing_interface' => $outInterface,
        'next_hop' => $nextHop,
        'preference' => $preference,
        'description' => $description
    ];
}

function router_parse_config(string $config, string $sourceName = ''): array {
    $config = str_replace(["\r\n", "\r"], "\n", $config);
    $lines = explode("\n", $config);

    $hostname = '';
    $softwareVersion = '';
    $configUpdatedAt = '';
    $configSavedAt = '';
    $routerId = '';
    $bgpAsn = '';

    $interfaces = [];
    $vrfs = [];
    $peers = [];
    $prefixLists = [];
    $routePolicies = [];
    $qosProfiles = [];
    $staticRoutes = [];
    $ospfProcesses = [];
    $isisProcesses = [];

    $currentInterface = null;
    $currentVrf = null;
    $currentVrfFamily = '';
    $currentQos = null;
    $currentOspf = null;
    $currentIsis = null;
    $currentBgpVrf = '';
    $currentBgpAf = '';
    $inBgp = false;
    $peerGroupSources = [];

    $ensureVrf = function(string $name) use (&$vrfs): void {
        if ($name === '') return;
        if (!isset($vrfs[$name])) {
            $vrfs[$name] = [
                'name' => $name,
                'route_distinguisher' => '',
                'label_mode' => '',
                'import_route_policy' => '',
                'has_ipv4' => 0,
                'has_ipv6' => 0,
                'import_targets' => [],
                'export_targets' => [],
                'bgp_imports' => []
            ];
        }
    };

    $ensurePeer = function(string $vrf, string $ip) use (&$peers): int {
        foreach ($peers as $idx => $peer) {
            if ($peer['vrf'] === $vrf && $peer['peer_ip'] === $ip) return $idx;
        }
        $peers[] = [
            'vrf' => $vrf,
            'peer_ip' => $ip,
            'remote_asn' => '',
            'description' => '',
            'peer_group' => '',
            'source_interface' => '',
            'address_families' => [],
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

        if ($softwareVersion === '' && preg_match('/^!Software Version\s+(.+)$/i', $trim, $m)) {
            $softwareVersion = trim($m[1]);
            continue;
        }
        if ($configUpdatedAt === '' && preg_match('/^!Last configuration was updated at\s+(.+?)\s+by\s+/i', $trim, $m)) {
            $configUpdatedAt = trim($m[1]);
            continue;
        }
        if ($configSavedAt === '' && preg_match('/^!Last configuration was saved at\s+(.+?)\s+by\s+/i', $trim, $m)) {
            $configSavedAt = trim($m[1]);
            continue;
        }
        if ($hostname === '' && preg_match('/^sysname\s+(.+)$/i', $trim, $m)) {
            $hostname = trim($m[1]);
            continue;
        }
        if ($routerId === '' && preg_match('/^router\s+id\s+(\d+\.\d+\.\d+\.\d+)/i', $trim, $m)) {
            $routerId = $m[1];
            continue;
        }

        if ($trim === '#') {
            $currentInterface = null;
            $currentVrf = null;
            $currentVrfFamily = '';
            $currentQos = null;
            $currentOspf = null;
            $currentIsis = null;
            $currentBgpVrf = '';
            $currentBgpAf = '';
            continue;
        }

        if (preg_match('/^qos-profile\s+(\S+)/i', $trim, $m)) {
            $currentQos = $m[1];
            $qosProfiles[$currentQos] = $qosProfiles[$currentQos] ?? [
                'name' => $currentQos, 'cir_kbps' => null, 'pir_kbps' => null, 'applied_count' => 0
            ];
            $currentInterface = null;
            $currentVrf = null;
            continue;
        }
        if ($currentQos !== null && preg_match('/^user-queue\s+cir\s+(\d+)\s+pir\s+(\d+)/i', $trim, $m)) {
            $qosProfiles[$currentQos]['cir_kbps'] = (int)$m[1];
            $qosProfiles[$currentQos]['pir_kbps'] = (int)$m[2];
            continue;
        }

        if (preg_match('/^ip\s+vpn-instance\s+(\S+)/i', $trim, $m)) {
            $currentVrf = $m[1];
            $currentVrfFamily = '';
            $ensureVrf($currentVrf);
            $currentInterface = null;
            continue;
        }
        if ($currentVrf !== null) {
            if (preg_match('/^ipv4-family$/i', $trim)) {
                $currentVrfFamily = 'ipv4';
                $vrfs[$currentVrf]['has_ipv4'] = 1;
                continue;
            }
            if (preg_match('/^ipv6-family$/i', $trim)) {
                $currentVrfFamily = 'ipv6';
                $vrfs[$currentVrf]['has_ipv6'] = 1;
                continue;
            }
            if (preg_match('/^route-distinguisher\s+(\S+)/i', $trim, $m)) {
                $vrfs[$currentVrf]['route_distinguisher'] = $m[1];
                continue;
            }
            if (preg_match('/^apply-label\s+(\S+)/i', $trim, $m)) {
                $vrfs[$currentVrf]['label_mode'] = $m[1];
                continue;
            }
            if (preg_match('/^import\s+route-policy\s+(\S+)/i', $trim, $m)) {
                $vrfs[$currentVrf]['import_route_policy'] = $m[1];
                $routePolicies[$m[1]] = ($routePolicies[$m[1]] ?? 0);
                continue;
            }
            if (preg_match('/^vpn-target\s+(\S+)\s+(import-extcommunity|export-extcommunity)/i', $trim, $m)) {
                $key = stripos($m[2], 'import') === 0 ? 'import_targets' : 'export_targets';
                if (!in_array($m[1], $vrfs[$currentVrf][$key], true)) $vrfs[$currentVrf][$key][] = $m[1];
                continue;
            }
        }

        if (preg_match('/^interface\s+(.+)$/i', $trim, $m)) {
            $name = trim($m[1]);
            $type = preg_match('/^([A-Za-z0-9-]+)/', $name, $tm) ? $tm[1] : $name;
            $parent = preg_replace('/\.\d+$/', '', $name);
            $interfaces[$name] = $interfaces[$name] ?? [
                'name' => $name,
                'interface_type' => $type,
                'parent_interface' => $parent !== $name ? $parent : '',
                'description' => '',
                'client_name' => '',
                'service_type' => '',
                'site_name' => '',
                'link_id' => '',
                'bandwidth_label' => '',
                'vlan_id' => '',
                'vrf' => '',
                'bandwidth_kbps' => '',
                'qos_in_profile' => '',
                'qos_out_profile' => '',
                'qos_in_cir_kbps' => null,
                'qos_in_pir_kbps' => null,
                'qos_out_cir_kbps' => null,
                'qos_out_pir_kbps' => null,
                'mtu' => null,
                'ospf_cost' => null,
                'ospf_network_type' => '',
                'isis_process' => '',
                'ips' => []
            ];
            $currentInterface = $name;
            $currentVrf = null;
            $currentQos = null;
            continue;
        }

        if ($currentInterface !== null) {
            if (preg_match('/^description\s+(.+)$/i', $trim, $m)) {
                $interfaces[$currentInterface]['description'] = router_clean_description($m[1]);
                continue;
            }
            if (preg_match('/^vlan-type\s+dot1q\s+(\d+)/i', $trim, $m) || preg_match('/^dot1q\s+termination\s+vid\s+(\d+)/i', $trim, $m)) {
                $interfaces[$currentInterface]['vlan_id'] = $m[1];
                continue;
            }
            if (preg_match('/^ip\s+binding\s+vpn-instance\s+(\S+)/i', $trim, $m)) {
                $interfaces[$currentInterface]['vrf'] = $m[1];
                $ensureVrf($m[1]);
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
            if (preg_match('/^ip\s+address\s+(\d+\.\d+\.\d+\.\d+)\s+(\d{1,2})(?:\s+(sub))?/i', $trim, $m)) {
                $prefix = max(0, min(32, (int)$m[2]));
                $interfaces[$currentInterface]['ips'][] = [
                    'ip_address' => $m[1],
                    'subnet_mask' => router_prefix_to_mask($prefix),
                    'prefix_length' => $prefix,
                    'is_secondary' => !empty($m[3]) ? 1 : 0
                ];
                continue;
            }
            if (preg_match('/^qos-profile\s+(\S+)\s+(inbound|outbound)/i', $trim, $m)) {
                $direction = strtolower($m[2]);
                $interfaces[$currentInterface]['qos_' . ($direction === 'inbound' ? 'in' : 'out') . '_profile'] = $m[1];
                continue;
            }
            if (preg_match('/^qos\s+car\s+cir\s+(\d+)/i', $trim, $m)) {
                $interfaces[$currentInterface]['bandwidth_kbps'] = $m[1];
                continue;
            }
            if (preg_match('/^mtu\s+(\d+)/i', $trim, $m)) {
                $interfaces[$currentInterface]['mtu'] = (int)$m[1];
                continue;
            }
            if (preg_match('/^ospf\s+cost\s+(\d+)/i', $trim, $m)) {
                $interfaces[$currentInterface]['ospf_cost'] = (int)$m[1];
                continue;
            }
            if (preg_match('/^ospf\s+network-type\s+(\S+)/i', $trim, $m)) {
                $interfaces[$currentInterface]['ospf_network_type'] = $m[1];
                continue;
            }
            if (preg_match('/^isis\s+enable\s+(\S+)/i', $trim, $m)) {
                $interfaces[$currentInterface]['isis_process'] = $m[1];
                continue;
            }
        }

        if (preg_match('/^ip\s+ip-prefix\s+(\S+)/i', $trim, $m)) {
            $prefixLists[$m[1]] = ($prefixLists[$m[1]] ?? 0) + 1;
            continue;
        }

        if (preg_match('/^route-policy\s+(\S+)\s+(?:permit|deny)(?:\s+node\s+\d+)?/i', $trim, $m)) {
            $routePolicies[$m[1]] = ($routePolicies[$m[1]] ?? 0) + 1;
            $inBgp = false;
            continue;
        }

        if (preg_match('/^ip\s+route-static\s+/i', $trim)) {
            $route = router_parse_static_route($trim);
            if ($route) {
                $staticRoutes[] = $route;
                if ($route['vrf'] !== '') $ensureVrf($route['vrf']);
            }
            continue;
        }

        if (preg_match('/^isis\s+(\S+)/i', $trim, $m)) {
            $inBgp = false;
            $currentIsis = count($isisProcesses);
            $isisProcesses[] = [
                'process_id' => $m[1],
                'level' => '',
                'network_entity' => '',
                'is_name' => ''
            ];
            continue;
        }
        if ($currentIsis !== null) {
            if (preg_match('/^is-level\s+(.+)$/i', $trim, $m)) $isisProcesses[$currentIsis]['level'] = trim($m[1]);
            elseif (preg_match('/^network-entity\s+(\S+)/i', $trim, $m)) $isisProcesses[$currentIsis]['network_entity'] = $m[1];
            elseif (preg_match('/^is-name\s+(.+)$/i', $trim, $m)) $isisProcesses[$currentIsis]['is_name'] = trim($m[1]);
            continue;
        }

        if (preg_match('/^(ospf|ospfv3)\s+(\S+)(.*)$/i', $trim, $m)) {
            $inBgp = false;
            $tail = $m[3];
            $vrf = '';
            $ospfRouterId = '';
            if (preg_match('/vpn-instance\s+(\S+)/i', $tail, $vm)) $vrf = $vm[1];
            if (preg_match('/router-id\s+(\d+\.\d+\.\d+\.\d+)/i', $tail, $rm)) $ospfRouterId = $rm[1];
            if ($vrf !== '') $ensureVrf($vrf);
            $currentOspf = count($ospfProcesses);
            $ospfProcesses[] = [
                'protocol' => strtolower($m[1]) === 'ospfv3' ? 'OSPFv3' : 'OSPF',
                'process_id' => $m[2],
                'vrf' => $vrf,
                'router_id' => $ospfRouterId,
                'areas' => [],
                'network_count' => 0,
                'default_advertise' => 0,
                'imports' => []
            ];
            continue;
        }
        if ($currentOspf !== null) {
            if (preg_match('/^router-id\s+(\d+\.\d+\.\d+\.\d+)/i', $trim, $m)) $ospfProcesses[$currentOspf]['router_id'] = $m[1];
            elseif (preg_match('/^area\s+(\S+)/i', $trim, $m)) $ospfProcesses[$currentOspf]['areas'][$m[1]] = true;
            elseif (preg_match('/^network\s+\S+\s+\S+/i', $trim)) $ospfProcesses[$currentOspf]['network_count']++;
            elseif (preg_match('/^default-route-advertise\b/i', $trim)) $ospfProcesses[$currentOspf]['default_advertise'] = 1;
            elseif (preg_match('/^import-route\s+(.+)/i', $trim, $m)) $ospfProcesses[$currentOspf]['imports'][] = trim($m[1]);
            continue;
        }

        if (preg_match('/^bgp\s+(\d+)/i', $trim, $m)) {
            $inBgp = true;
            $bgpAsn = $m[1];
            $currentBgpVrf = '';
            $currentBgpAf = 'global';
            $currentInterface = null;
            continue;
        }

        if ($inBgp) {
            if (preg_match('/^router-id\s+(\d+\.\d+\.\d+\.\d+)/i', $trim, $m)) {
                if ($routerId === '') $routerId = $m[1];
                continue;
            }
            if (preg_match('/^(ipv4-family|ipv6-family)\s+vpn-instance\s+(\S+)/i', $trim, $m)) {
                $currentBgpAf = strtolower($m[1]) === 'ipv6-family' ? 'ipv6-vrf' : 'ipv4-vrf';
                $currentBgpVrf = $m[2];
                $ensureVrf($currentBgpVrf);
                if ($currentBgpAf === 'ipv6-vrf') $vrfs[$currentBgpVrf]['has_ipv6'] = 1;
                else $vrfs[$currentBgpVrf]['has_ipv4'] = 1;
                continue;
            }
            if (preg_match('/^(ipv4-family|ipv6-family)\s+(unicast|vpnv4|vpnv6)/i', $trim, $m)) {
                $currentBgpAf = strtolower($m[2]);
                $currentBgpVrf = '';
                continue;
            }
            if ($currentBgpVrf !== '' && preg_match('/^import-route\s+(.+)/i', $trim, $m)) {
                $vrfs[$currentBgpVrf]['bgp_imports'][] = trim($m[1]);
                continue;
            }
            if (preg_match('/^peer\s+(\S+)\s+connect-interface\s+(\S+)/i', $trim, $m)) {
                if (filter_var($m[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                    $idx = $ensurePeer($currentBgpVrf, $m[1]);
                    $peers[$idx]['source_interface'] = $m[2];
                } else {
                    $peerGroupSources[$m[1]] = $m[2];
                }
                continue;
            }
            if (preg_match('/^peer\s+(\d+\.\d+\.\d+\.\d+)\s+as-number\s+(\d+)/i', $trim, $m)) {
                $idx = $ensurePeer($currentBgpVrf, $m[1]);
                $peers[$idx]['remote_asn'] = $m[2];
                continue;
            }
            if (preg_match('/^peer\s+(\d+\.\d+\.\d+\.\d+)\s+description\s+(.+)/i', $trim, $m)) {
                $idx = $ensurePeer($currentBgpVrf, $m[1]);
                $peers[$idx]['description'] = router_clean_description($m[2]);
                continue;
            }
            if (preg_match('/^peer\s+(\d+\.\d+\.\d+\.\d+)\s+group\s+(\S+)/i', $trim, $m)) {
                $idx = $ensurePeer($currentBgpVrf, $m[1]);
                $peers[$idx]['peer_group'] = $m[2];
                if (isset($peerGroupSources[$m[2]])) $peers[$idx]['source_interface'] = $peerGroupSources[$m[2]];
                continue;
            }
            if (preg_match('/^peer\s+(\d+\.\d+\.\d+\.\d+)\s+enable$/i', $trim, $m)) {
                $idx = $ensurePeer($currentBgpVrf, $m[1]);
                if ($currentBgpAf !== '' && !in_array($currentBgpAf, $peers[$idx]['address_families'], true)) {
                    $peers[$idx]['address_families'][] = $currentBgpAf;
                }
                continue;
            }
            if (preg_match('/^peer\s+(\d+\.\d+\.\d+\.\d+)\s+route-policy\s+(\S+)\s+(import|export)/i', $trim, $m)) {
                $idx = $ensurePeer($currentBgpVrf, $m[1]);
                $peers[$idx][strtolower($m[3]) . '_policy'] = $m[2];
                $routePolicies[$m[2]] = ($routePolicies[$m[2]] ?? 0);
                continue;
            }
            if (preg_match('/^peer\s+(\d+\.\d+\.\d+\.\d+)\s+ip-prefix\s+(\S+)\s+(import|export)/i', $trim, $m)) {
                $idx = $ensurePeer($currentBgpVrf, $m[1]);
                $peers[$idx][strtolower($m[3]) . '_prefix'] = $m[2];
                $prefixLists[$m[2]] = ($prefixLists[$m[2]] ?? 0);
                continue;
            }
        }
    }

    if ($hostname === '') {
        $hostname = $sourceName !== '' ? pathinfo($sourceName, PATHINFO_FILENAME) : 'Imported-Huawei-Router';
    }

    [$role, $siteCode] = router_guess_role_site($hostname);

    foreach ($interfaces as &$iface) {
        if ($iface['vlan_id'] === '' && preg_match('/\.(\d+)$/', $iface['name'], $m)) {
            $iface['vlan_id'] = $m[1];
        } elseif ($iface['vlan_id'] === '' && preg_match('/^Vlanif\s*(\d+)$/i', $iface['name'], $m)) {
            $iface['vlan_id'] = $m[1];
        }

        $meta = router_parse_service_metadata($iface['description'], $iface['vrf']);
        foreach ($meta as $key => $value) {
            if ($value !== '' || empty($iface[$key])) $iface[$key] = $value;
        }

        foreach (['in', 'out'] as $direction) {
            $profileName = $iface['qos_' . $direction . '_profile'];
            if ($profileName !== '' && isset($qosProfiles[$profileName])) {
                $qosProfiles[$profileName]['applied_count']++;
                $iface['qos_' . $direction . '_cir_kbps'] = $qosProfiles[$profileName]['cir_kbps'];
                $iface['qos_' . $direction . '_pir_kbps'] = $qosProfiles[$profileName]['pir_kbps'];
                if ($iface['bandwidth_kbps'] === '' && $qosProfiles[$profileName]['cir_kbps'] !== null) {
                    $iface['bandwidth_kbps'] = (string)$qosProfiles[$profileName]['cir_kbps'];
                }
            }
        }
    }
    unset($iface);

    foreach ($peers as &$peer) {
        if ($peer['source_interface'] === '' && $peer['peer_group'] !== '' && isset($peerGroupSources[$peer['peer_group']])) {
            $peer['source_interface'] = $peerGroupSources[$peer['peer_group']];
        }
    }
    unset($peer);

    foreach ($ospfProcesses as &$process) {
        $process['area_count'] = count($process['areas']);
        unset($process['areas']);
        $process['imports'] = array_values(array_unique($process['imports']));
    }
    unset($process);

    return [
        'hostname' => $hostname,
        'platform' => router_guess_platform($hostname, $config),
        'role' => $role,
        'site_code' => $siteCode,
        'software_version' => $softwareVersion,
        'router_id' => $routerId,
        'bgp_asn' => $bgpAsn,
        'config_updated_at' => $configUpdatedAt,
        'config_saved_at' => $configSavedAt,
        'config_line_count' => count($lines),
        'interfaces' => array_values($interfaces),
        'vrfs' => array_values($vrfs),
        'peers' => $peers,
        'prefix_lists' => $prefixLists,
        'route_policies' => $routePolicies,
        'qos_profiles' => array_values($qosProfiles),
        'static_routes' => $staticRoutes,
        'ospf_processes' => $ospfProcesses,
        'isis_processes' => $isisProcesses
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
            $pdo->prepare("
                UPDATE router_devices
                SET platform = ?, source_name = ?, software_version = ?, router_id = ?, bgp_asn = ?,
                    role = ?, site_code = ?, config_updated_at = ?, config_saved_at = ?,
                    config_line_count = ?, parser_version = ?, imported_at = CURRENT_TIMESTAMP
                WHERE id = ?
            ")->execute([
                $parsed['platform'], $sourceName, $parsed['software_version'], $parsed['router_id'], $parsed['bgp_asn'],
                $parsed['role'], $parsed['site_code'], $parsed['config_updated_at'], $parsed['config_saved_at'],
                $parsed['config_line_count'], ROUTER_PARSER_VERSION, $deviceId
            ]);

            foreach ([
                'router_bgp_peers','router_prefix_lists','router_route_policies','router_vrfs',
                'router_qos_profiles','router_static_routes','router_ospf_processes','router_isis_processes'
            ] as $table) {
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
            $pdo->prepare("
                INSERT INTO router_devices
                (hostname, platform, source_name, software_version, router_id, bgp_asn, role, site_code,
                 config_updated_at, config_saved_at, config_line_count, parser_version)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ")->execute([
                $parsed['hostname'], $parsed['platform'], $sourceName, $parsed['software_version'],
                $parsed['router_id'], $parsed['bgp_asn'], $parsed['role'], $parsed['site_code'],
                $parsed['config_updated_at'], $parsed['config_saved_at'],
                $parsed['config_line_count'], ROUTER_PARSER_VERSION
            ]);
            $deviceId = (int)$pdo->lastInsertId();
        }

        $vrfStmt = $pdo->prepare("
            INSERT INTO router_vrfs
            (device_id, vrf_name, route_distinguisher, label_mode, import_route_policy,
             has_ipv4, has_ipv6, import_targets, export_targets, bgp_imports)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        foreach ($parsed['vrfs'] as $vrf) {
            $vrfStmt->execute([
                $deviceId, $vrf['name'], $vrf['route_distinguisher'], $vrf['label_mode'],
                $vrf['import_route_policy'], $vrf['has_ipv4'], $vrf['has_ipv6'],
                json_encode($vrf['import_targets'], JSON_UNESCAPED_SLASHES),
                json_encode($vrf['export_targets'], JSON_UNESCAPED_SLASHES),
                json_encode(array_values(array_unique($vrf['bgp_imports'])), JSON_UNESCAPED_SLASHES)
            ]);
        }

        $ifStmt = $pdo->prepare("
            INSERT INTO router_interfaces
            (device_id, interface_name, interface_type, parent_interface, description, client_name,
             service_type, site_name, link_id, bandwidth_label, vlan_id, vrf, bandwidth_kbps,
             qos_in_profile, qos_out_profile, qos_in_cir_kbps, qos_in_pir_kbps,
             qos_out_cir_kbps, qos_out_pir_kbps, mtu, ospf_cost, ospf_network_type, isis_process)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $ipStmt = $pdo->prepare("
            INSERT INTO router_interface_ips (interface_id, ip_address, subnet_mask, prefix_length, is_secondary)
            VALUES (?, ?, ?, ?, ?)
        ");
        foreach ($parsed['interfaces'] as $iface) {
            $ifStmt->execute([
                $deviceId, $iface['name'], $iface['interface_type'], $iface['parent_interface'],
                $iface['description'], $iface['client_name'], $iface['service_type'], $iface['site_name'],
                $iface['link_id'], $iface['bandwidth_label'], $iface['vlan_id'], $iface['vrf'],
                $iface['bandwidth_kbps'], $iface['qos_in_profile'], $iface['qos_out_profile'],
                $iface['qos_in_cir_kbps'], $iface['qos_in_pir_kbps'],
                $iface['qos_out_cir_kbps'], $iface['qos_out_pir_kbps'],
                $iface['mtu'], $iface['ospf_cost'], $iface['ospf_network_type'], $iface['isis_process']
            ]);
            $interfaceId = (int)$pdo->lastInsertId();
            foreach ($iface['ips'] as $ip) {
                $ipStmt->execute([$interfaceId, $ip['ip_address'], $ip['subnet_mask'], $ip['prefix_length'], $ip['is_secondary']]);
            }
        }

        $peerStmt = $pdo->prepare("
            INSERT INTO router_bgp_peers
            (device_id, vrf, peer_ip, remote_asn, description, peer_group, source_interface,
             address_families, import_policy, export_policy, import_prefix, export_prefix)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        foreach ($parsed['peers'] as $peer) {
            $peerStmt->execute([
                $deviceId, $peer['vrf'], $peer['peer_ip'], $peer['remote_asn'], $peer['description'],
                $peer['peer_group'], $peer['source_interface'],
                json_encode(array_values(array_unique($peer['address_families'])), JSON_UNESCAPED_SLASHES),
                $peer['import_policy'], $peer['export_policy'], $peer['import_prefix'], $peer['export_prefix']
            ]);
        }

        $prefixStmt = $pdo->prepare("INSERT INTO router_prefix_lists (device_id, name, entry_count) VALUES (?, ?, ?)");
        foreach ($parsed['prefix_lists'] as $name => $count) $prefixStmt->execute([$deviceId, $name, $count]);

        $policyStmt = $pdo->prepare("INSERT INTO router_route_policies (device_id, name, node_count) VALUES (?, ?, ?)");
        foreach ($parsed['route_policies'] as $name => $count) $policyStmt->execute([$deviceId, $name, $count]);

        $qosStmt = $pdo->prepare("
            INSERT INTO router_qos_profiles (device_id, name, cir_kbps, pir_kbps, applied_count)
            VALUES (?, ?, ?, ?, ?)
        ");
        foreach ($parsed['qos_profiles'] as $qos) {
            $qosStmt->execute([$deviceId, $qos['name'], $qos['cir_kbps'], $qos['pir_kbps'], $qos['applied_count']]);
        }

        $routeStmt = $pdo->prepare("
            INSERT INTO router_static_routes
            (device_id, vrf, destination, subnet_mask, prefix_length, outgoing_interface, next_hop, preference, description)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        foreach ($parsed['static_routes'] as $route) {
            $routeStmt->execute([
                $deviceId, $route['vrf'], $route['destination'], $route['subnet_mask'], $route['prefix_length'],
                $route['outgoing_interface'], $route['next_hop'], $route['preference'], $route['description']
            ]);
        }

        $ospfStmt = $pdo->prepare("
            INSERT INTO router_ospf_processes
            (device_id, process_id, vrf, router_id, area_count, network_count, default_advertise, imports, protocol)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        foreach ($parsed['ospf_processes'] as $process) {
            $ospfStmt->execute([
                $deviceId, $process['process_id'], $process['vrf'], $process['router_id'],
                $process['area_count'], $process['network_count'], $process['default_advertise'],
                json_encode($process['imports'], JSON_UNESCAPED_SLASHES), $process['protocol']
            ]);
        }

        $isisStmt = $pdo->prepare("
            INSERT INTO router_isis_processes (device_id, process_id, level, network_entity, is_name)
            VALUES (?, ?, ?, ?, ?)
        ");
        foreach ($parsed['isis_processes'] as $process) {
            $isisStmt->execute([$deviceId, $process['process_id'], $process['level'], $process['network_entity'], $process['is_name']]);
        }

        $pdo->commit();

        $clients = [];
        foreach ($parsed['interfaces'] as $iface) {
            if ($iface['client_name'] !== '') $clients[strtolower($iface['client_name'])] = $iface['client_name'];
        }

        return [
            'device_id' => $deviceId,
            'hostname' => $parsed['hostname'],
            'platform' => $parsed['platform'],
            'role' => $parsed['role'],
            'site_code' => $parsed['site_code'],
            'interfaces' => count($parsed['interfaces']),
            'clients' => count($clients),
            'vrfs' => count($parsed['vrfs']),
            'bgp_peers' => count($parsed['peers']),
            'prefix_lists' => count($parsed['prefix_lists']),
            'route_policies' => count($parsed['route_policies']),
            'qos_profiles' => count($parsed['qos_profiles']),
            'static_routes' => count($parsed['static_routes']),
            'ospf_processes' => count($parsed['ospf_processes']),
            'isis_processes' => count($parsed['isis_processes'])
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}
