<?php
require_once __DIR__ . '/../router_config_parser.php';

$config = <<<'CFG'
!Software Version V800R021C00SPC100
sysname LHR-PE-NE40EX8A-B1
#
qos-profile Nadra_MPLS_2Mbps
 user-queue cir 2160 pir 2160
#
ip vpn-instance pt_Nadra_MPLS
 ipv4-family
  route-distinguisher 65000:5123
  apply-label per-instance
  vpn-target 65000:5123 export-extcommunity
  vpn-target 65000:5123 import-extcommunity
#
interface Eth-Trunk31.800
 vlan-type dot1q 800
 description ESSClient_MPLS_Nadra-Sharqpur_2Mbps_LNKLHR3195880358
 ip binding vpn-instance pt_Nadra_MPLS
 ip address 192.168.135.73 255.255.255.252
 qos-profile Nadra_MPLS_2Mbps inbound identifier none
 qos-profile Nadra_MPLS_2Mbps outbound identifier none
#
bgp 65000
 router-id 10.31.20.63
 peer 10.31.16.51 as-number 65000
 peer 10.31.16.51 description ISB-PRR-NE40EX16-A1
 #
 ipv4-family vpnv4
  peer 10.31.16.51 enable
#
ospf 20 router-id 10.81.231.143 vpn-instance pt_Nadra_MPLS
 import-route direct
 area 0.0.0.0
  network 192.168.135.72 0.0.0.3
#
ip ip-prefix NADRA_TEST index 10 permit 192.0.2.0 24
#
route-policy NADRA_IMPORT permit node 10
#
CFG;

$result = router_parse_config($config, 'smoke.cfg');

$fail = function (string $message): void {
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
};

if (($result['hostname'] ?? '') !== 'LHR-PE-NE40EX8A-B1') $fail('hostname');
if (($result['platform'] ?? '') !== 'NE40E / NE40EX8') $fail('platform');
if (($result['role'] ?? '') !== 'PE' || ($result['site_code'] ?? '') !== 'LHR') $fail('role/site');
if (($result['bgp_asn'] ?? '') !== '65000' || ($result['router_id'] ?? '') !== '10.31.20.63') $fail('BGP identity');

$iface = $result['interfaces'][0] ?? [];
if (($iface['interface_name'] ?? $iface['name'] ?? '') !== 'Eth-Trunk31.800') $fail('interface name');
if (($iface['client_name'] ?? '') !== 'Nadra') $fail('client normalization');
if (($iface['site_name'] ?? '') !== 'Sharqpur') $fail('site normalization');
if (($iface['service_type'] ?? '') !== 'MPLS') $fail('service type');
if (($iface['link_id'] ?? '') !== 'LNKLHR3195880358') $fail('link id');
if (($iface['bandwidth_label'] ?? '') !== '2Mbps') $fail('bandwidth label');
if (($iface['qos_in_cir_kbps'] ?? 0) !== 2160 || ($iface['qos_out_cir_kbps'] ?? 0) !== 2160) $fail('QoS resolution');

$vrf = $result['vrfs'][0] ?? [];
if (($vrf['name'] ?? '') !== 'pt_Nadra_MPLS' || ($vrf['route_distinguisher'] ?? '') !== '65000:5123') $fail('VRF details');

if (count($result['peers'] ?? []) < 1 || ($result['peers'][0]['peer_ip'] ?? '') !== '10.31.16.51') $fail('BGP peer');
if (count($result['ospf_processes'] ?? []) !== 1 || ($result['ospf_processes'][0]['vrf'] ?? '') !== 'pt_Nadra_MPLS') $fail('OSPF');
if (($result['prefix_lists']['NADRA_TEST'] ?? 0) !== 1) $fail('prefix list count');
if (($result['route_policies']['NADRA_IMPORT'] ?? 0) !== 1) $fail('route policy count');

echo "Router parser smoke test passed.\n";
