<?php
/**
 * db.php - Database connection and automatic schema migration
 */

date_default_timezone_set('Asia/Karachi');

$use_sqlite = true; // Toggle to false for MySQL

if ($use_sqlite) {
    try {
        $noc_data_dir = getenv('NOC_DATA_DIR') ?: __DIR__ . '/noc_data';
        if (!is_dir($noc_data_dir)) @mkdir($noc_data_dir, 0777, true);
        // Keep the existing installation database unless a data directory is configured.
        $db_path = getenv('NOC_DATA_DIR') ? $noc_data_dir . '/welcome.sqlite' : __DIR__ . '/welcome.sqlite';
        $pdo = new PDO("sqlite:" . $db_path);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA busy_timeout = 5000');
        $pdo->exec('CREATE TABLE IF NOT EXISTS outage_history (id INTEGER PRIMARY KEY AUTOINCREMENT, processed_time TEXT, file_name TEXT, total_links INTEGER, priority_count INTEGER, summary TEXT, occurred_time TEXT)');

        // 1. Welcome messages table
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS welcome_messages (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                title TEXT NOT NULL,
                subtitle TEXT,
                message TEXT NOT NULL,
                is_active INTEGER DEFAULT 1,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ");

        // 2. Complaints table
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS complaints (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                service_label TEXT NOT NULL,
                service_id TEXT,
                service_type TEXT,
                issue_type TEXT,
                ticket TEXT,
                priority TEXT DEFAULT 'Normal',
                status TEXT DEFAULT 'QUEUED', -- QUEUED, OPEN, CLOSED
                added_time DATETIME DEFAULT CURRENT_TIMESTAMP,
                reported_time DATETIME,
                restoration_time DATETIME,
                root_cause TEXT,
                issue_found_at TEXT,
                corrective_action TEXT,
                last_stage TEXT
            )
        ");

        // 3. Vendors table
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS vendors (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT UNIQUE NOT NULL
            )
        ");

        // 4. Vendor contacts table
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS vendor_contacts (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                vendor_id INTEGER,
                level TEXT NOT NULL,
                name TEXT NOT NULL,
                designation TEXT,
                escalation_time TEXT,
                phone TEXT,
                email TEXT,
                FOREIGN KEY(vendor_id) REFERENCES vendors(id) ON DELETE CASCADE
            )
        ");

        // 5. Users table for corporate authentication & permissions
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                username TEXT UNIQUE COLLATE NOCASE NOT NULL,
                password_hash TEXT NOT NULL,
                role TEXT DEFAULT 'user',
                permissions TEXT DEFAULT 'all',
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ");

        // Migration: check if permissions column exists in users table
        $cols = $pdo->query("PRAGMA table_info(users)")->fetchAll(PDO::FETCH_COLUMN, 1);
        if (!in_array('permissions', $cols)) {
            $pdo->exec("ALTER TABLE users ADD COLUMN permissions TEXT DEFAULT 'all'");
        }

        // Seed initial users if empty
        $u_count = $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
        if ($u_count == 0) {
            $stmt_u = $pdo->prepare("INSERT INTO users (username, password_hash, role, permissions) VALUES (?, ?, ?, 'all')");
            $stmt_u->execute(['ijaz', password_hash('ijaz123', PASSWORD_DEFAULT), 'admin']);
            $stmt_u->execute(['admin', password_hash('admin123', PASSWORD_DEFAULT), 'admin']);
        }

        // 6. VPBX / Regulatory Testing Portal tables
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS vpbx_outgoing (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                case_num INTEGER,
                client TEXT NOT NULL,
                timestamp TEXT,
                master_num TEXT,
                child_num TEXT,
                party_b TEXT,
                dial_mode TEXT,
                dialed_party_b TEXT,
                operator TEXT,
                ivr TEXT,
                status TEXT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS vpbx_incoming (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                case_num INTEGER,
                client TEXT NOT NULL,
                timestamp TEXT,
                party_a TEXT,
                party_b TEXT,
                operator TEXT,
                ivr TEXT,
                status TEXT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ");

        // Seed initial VPBX outgoing data if empty
        $out_cnt = $pdo->query("SELECT COUNT(*) FROM vpbx_outgoing")->fetchColumn();
        if ($out_cnt == 0) {
            $stmt_vo = $pdo->prepare("INSERT INTO vpbx_outgoing (case_num, client, timestamp, master_num, child_num, party_b, dial_mode, dialed_party_b, operator, ivr, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $initial_outgoing = [
                [1, "Nasir Penthouse", "02:30 PM", "02138459201", "03125439871", "03018892345", "With 66", "6603018892345", "Jazz", "Standard IVR", "Connected"],
                [2, "Inam Villa", "02:45 PM", "04237194852", "03214561234", "03334455667", "Without 66", "03334455667", "Zong", "Number is not reachable at the moment.", "Failed"],
                [3, "Ijaz Business Complex", "03:00 PM", "05189234015", "03456782345", "03091238765", "With 66", "6603091238765", "Ufone", "Standard IVR", "Connected"],
                [4, "Ijaz Business Complex", "03:15 PM", "05189234015", "03456782346", "03465544332", "Without 66", "03465544332", "Telenor", "The dialed number is not available right now.", "Busy / No Answer"]
            ];
            foreach ($initial_outgoing as $r) {
                $stmt_vo->execute($r);
            }
        }

        // Seed initial VPBX incoming data if empty
        $inc_cnt = $pdo->query("SELECT COUNT(*) FROM vpbx_incoming")->fetchColumn();
        if ($inc_cnt == 0) {
            $stmt_vi = $pdo->prepare("INSERT INTO vpbx_incoming (case_num, client, timestamp, party_a, party_b, operator, ivr, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $initial_incoming = [
                [1, "Nasir Penthouse", "02:25 PM", "03057788991", "02138459201", "Jazz", "Standard IVR", "Received"],
                [2, "Inam Villa", "02:40 PM", "03223344556", "04237194852", "Zong", "Continous IVR playing", "Blocked"],
                [3, "Ijaz Business Complex", "02:55 PM", "03419988776", "05189234015", "Ufone", "Standard IVR", "Received"]
            ];
            foreach ($initial_incoming as $r) {
                $stmt_vi->execute($r);
            }
        }

        // 7. VPBX IVR library table
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS vpbx_ivrs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                message TEXT UNIQUE NOT NULL,
                is_default INTEGER DEFAULT 0,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ");

        $ivr_cnt = $pdo->query("SELECT COUNT(*) FROM vpbx_ivrs")->fetchColumn();
        if ($ivr_cnt == 0) {
            $stmt_ivr = $pdo->prepare("INSERT OR IGNORE INTO vpbx_ivrs (message, is_default) VALUES (?, ?)");
            $default_ivrs = [
                ['Standard IVR', 1],
                ['Main Menu (2)', 0],
                ['The dialed number is not available right now.', 0],
                ['The dialed number is powered off.', 0],
                ['Number is not reachable at the moment.', 0],
                ['Call not reached at the moment.', 0],
                ['Apka Mila hovo Number Banda hai', 0],
                ['Continous IVR playing', 0],
            ];
            foreach ($default_ivrs as $ivr_item) {
                $stmt_ivr->execute($ivr_item);
            }
        }

        // 8. NMS Customer Clients table & search indexes
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS nms_clients (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                row_num INTEGER,
                unique_link_id TEXT COLLATE NOCASE,
                client_name TEXT COLLATE NOCASE,
                department TEXT COLLATE NOCASE,
                service TEXT COLLATE NOCASE,
                industry_segment TEXT,
                customer_type TEXT,
                otc TEXT,
                otc_type TEXT,
                mrc TEXT,
                mrc_type TEXT,
                client_longitude TEXT,
                client_latitude TEXT,
                ntn_no TEXT,
                kam_name TEXT COLLATE NOCASE,
                kam_email TEXT,
                kam_contact TEXT,
                handling_region TEXT COLLATE NOCASE,
                fll_sale_id TEXT,
                status TEXT COLLATE NOCASE,
                msisdn TEXT,
                source_city TEXT COLLATE NOCASE,
                sink_city TEXT COLLATE NOCASE,
                last_action_taken TEXT COLLATE NOCASE,
                last_action_taken_on TEXT,
                approval_status TEXT COLLATE NOCASE,
                is_integrated TEXT,
                is_modified TEXT,
                is_upgraded TEXT,
                is_downgraded TEXT,
                is_terminated TEXT,
                termination_workorder TEXT,
                termination_go_ahead TEXT,
                termination_implementation TEXT,
                configuration_deleted TEXT,
                termination_reason TEXT,
                termination_remarks TEXT,
                solution_design_bw TEXT,
                configured_bw TEXT,
                bw_percentage TEXT,
                nms_user_label TEXT COLLATE NOCASE,
                tier_level TEXT COLLATE NOCASE,
                core_network_protection TEXT,
                last_mile_protection TEXT,
                rerouting_requirement TEXT,
                sla TEXT,
                vlan TEXT COLLATE NOCASE,
                txn_nms TEXT,
                routed_static_ips TEXT,
                solution_type TEXT,
                isp TEXT COLLATE NOCASE,
                gateway_ips TEXT,
                peering_ips TEXT,
                polygon TEXT,
                ont_type TEXT,
                ont_owner TEXT,
                asn_number TEXT,
                advertised_ips TEXT,
                ftth_panel TEXT,
                additional_media TEXT,
                site TEXT COLLATE NOCASE,
                site_owner TEXT,
                region TEXT COLLATE NOCASE,
                sub_region TEXT COLLATE NOCASE,
                site_latitude TEXT,
                site_longitude TEXT,
                address TEXT,
                optical_network TEXT,
                optical_source_node TEXT,
                optical_source_port TEXT,
                network_type_l1 TEXT,
                node_type TEXT,
                txn_network_l1 TEXT,
                optical_sink_node TEXT,
                optical_sink_port TEXT,
                datacom_network TEXT,
                datacom_location TEXT,
                datacom_type TEXT,
                datacom_node TEXT,
                datacom_port TEXT,
                microwave_network TEXT,
                mw_source_node TEXT,
                mw_source_port TEXT,
                mw_sink_node TEXT,
                mw_sink_port TEXT,
                last_mile_medium TEXT,
                last_mile_vendor TEXT,
                hop_length TEXT,
                fiber_length TEXT,
                tower_pole_length TEXT,
                fab_link TEXT,
                tower_pole_owner TEXT,
                poc_name TEXT,
                poc_contact_no TEXT,
                poc_email TEXT,
                network_layers TEXT,
                work_order TEXT,
                traffic_statistics TEXT,
                instance_created TEXT,
                customer_mrtg TEXT,
                dashboard_required TEXT,
                dashboard_username TEXT COLLATE NOCASE,
                go_ahead_date TEXT,
                deployment_date TEXT,
                change_request_date TEXT,
                is_delay TEXT,
                delay_at TEXT,
                delay_remarks TEXT,
                feasibility_comments TEXT,
                all_data_json TEXT,
                is_zte INTEGER DEFAULT 0
            )
        ");

        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_nms_unique_link ON nms_clients(unique_link_id)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_nms_client_name ON nms_clients(client_name)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_nms_nms_label ON nms_clients(nms_user_label)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_nms_service ON nms_clients(service)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_nms_department ON nms_clients(department)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_nms_site ON nms_clients(site)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_nms_isp ON nms_clients(isp)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_nms_vlan ON nms_clients(vlan)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_nms_region ON nms_clients(region)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_nms_status ON nms_clients(status)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_nms_approval ON nms_clients(approval_status)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_nms_username ON nms_clients(dashboard_username)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_nms_is_zte ON nms_clients(is_zte)");

        // 9. Router command library — database-backed and admin-manageable
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS router_commands (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                title TEXT NOT NULL,
                platform TEXT NOT NULL COLLATE NOCASE,
                category TEXT NOT NULL COLLATE NOCASE,
                command_template TEXT NOT NULL,
                description TEXT,
                is_active INTEGER DEFAULT 1,
                sort_order INTEGER DEFAULT 100,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_router_commands_platform ON router_commands(platform)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_router_commands_category ON router_commands(category)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_router_commands_active ON router_commands(is_active)");

        $router_command_count = (int)$pdo->query("SELECT COUNT(*) FROM router_commands")->fetchColumn();
        if ($router_command_count === 0) {
            $router_seed = [
                ['Find VLAN / service in configuration', 'NE40E / NE40EX8', 'Discovery', 'display current-configuration | i {search}', 'Search configuration for a VLAN, IP, interface suffix or service keyword.', 10],
                ['Interface brief', 'NE40E / NE40EX8', 'Interface', 'display interface brief', 'Quick view of physical/protocol state, utilization and errors.', 10],
                ['Eth-Trunk sub-interface status', 'NE40E / NE40EX8', 'Interface', 'display interface Eth-Trunk{trunk}.{vlan}', 'Check state, traffic, errors and encapsulation.', 20],
                ['Eth-Trunk sub-interface configuration', 'NE40E / NE40EX8', 'Interface', 'display current-configuration interface Eth-Trunk{trunk}.{vlan}', 'Show VLAN tagging, VPN-instance, IP and applied policies.', 30],
                ['ARP on Eth-Trunk sub-interface', 'NE40E / NE40EX8', 'ARP', 'display arp interface Eth-Trunk{trunk}.{vlan}', 'Verify Layer-2 adjacency and peer MAC learning.', 10],
                ['VPN-instance ping', 'NE40E / NE40EX8', 'Ping / Reachability', 'ping -vpn-instance {vrf} {ip}', 'Ping from the correct VPN-instance.', 10],
                ['VRF route lookup', 'NE40E / NE40EX8', 'Routing', 'display ip routing-table vpn-instance {vrf} {ip}', 'Check the route for a destination inside a VPN-instance.', 10],
                ['BGP VPN peer status', 'NE40E / EGW', 'BGP', 'display bgp vpnv4 vpn-instance {vrf} peer | I {peer_ip}', 'Check BGP peer state and prefix count.', 10],
                ['BGP received routes', 'NE40E / EGW', 'BGP', 'display bgp vpnv4 vpn-instance {vrf} routing-table peer {peer_ip} received-routes', 'Display routes received from a BGP peer.', 20],
                ['BGP advertised routes', 'NE40E / EGW', 'BGP', 'display bgp vpnv4 vpn-instance {vrf} routing-table peer {peer_ip} advertised-routes', 'Display routes advertised to a BGP peer.', 30],
                ['Route-policy details', 'NE40E / EGW', 'BGP Policy', 'display route-policy {policy}', 'Inspect route-policy nodes and match clauses.', 10],
                ['IP prefix-list details', 'NE40E / EGW', 'BGP Policy', 'display ip ip-prefix {prefix}', 'Inspect an IP prefix-list.', 20],
                ['Candidate configuration changes', 'NE40E / EGW', 'Safety / Review', 'display configuration candidate changes', 'Review uncommitted candidate changes.', 10],
                ['Vlanif interface status', 'S9306', 'Interface', 'display interface Vlanif {vlan}', 'Check Vlanif state, traffic and errors.', 10],
                ['Vlanif IP details', 'S9306', 'Interface', 'display ip interface Vlanif {vlan}', 'Show interface addressing and IP counters.', 20],
                ['Vlanif configuration', 'S9306', 'Interface', 'display current-configuration interface Vlanif{vlan}', 'Show Vlanif addressing, VPN-instance and policies.', 30],
                ['ARP on Vlanif', 'S9306', 'ARP', 'display arp interface Vlanif {vlan}', 'Check ARP resolution on a Vlanif.', 10],
                ['Find IP interface', 'S9306', 'Discovery', 'display ip interface brief | include {ip}', 'Find which interface owns or references an IP.', 10],
                ['Search current configuration', 'S9306', 'Discovery', 'display current-configuration | include {search}', 'Search configuration for an IP, VLAN or keyword.', 20],
                ['Interface description search', 'S9306', 'Discovery', 'display interface description | include {search}', 'Locate an interface by service label or keyword.', 30],
                ['Global route lookup', 'S9306', 'Routing', 'display ip routing-table {ip}', 'Check the routing-table entry for a destination.', 10],
                ['Standard ping', 'S9306', 'Ping / Reachability', 'ping {ip}', 'Basic global-table reachability test.', 10],
                ['VPN-instance ping', 'S9306', 'Ping / Reachability', 'ping -vpn-instance {vrf} {ip}', 'Reachability test inside a VPN-instance.', 20],
            ];
            $router_seed_stmt = $pdo->prepare("INSERT INTO router_commands (title, platform, category, command_template, description, sort_order) VALUES (?, ?, ?, ?, ?, ?)");
            foreach ($router_seed as $router_row) {
                $router_seed_stmt->execute($router_row);
            }
        }

        $NOC_FEATURES = [
            'complaints' => '📁 Complaint Manager',
            'opening' => '🚨 Opening',
            'customer' => '👤 Customer End / Findings',
            'escalation' => '📨 Vendor / Escalation',
            'closure' => '✅ Closure / RFO',
            'stats' => '🧪 Stats',
            'progress' => '🔄 Progress',
            'dashboard' => '📋 Dashboard',
            'outage' => '📈 Outage Analyzer',
            'matrix' => '🏪 Vendor Matrix',
            'roster' => '📅 Duty Roster',
            'vpbx' => '📞 VPBX / Regulatory',
            'nms' => '🏷️ NMS Customer Search',
            'router' => '🛠️ Router Commands'
        ];

        // Seed default vendors if empty
        $v_count = $pdo->query("SELECT COUNT(*) FROM vendors")->fetchColumn();
        if ($v_count == 0) {
            $stmt = $pdo->prepare("INSERT INTO vendors (name) VALUES (?)");
            $c_stmt = $pdo->prepare("INSERT INTO vendor_contacts (vendor_id, level, name, designation, escalation_time, phone, email) VALUES (?, ?, ?, ?, ?, ?, ?)");

            // Netsat
            $stmt->execute(['Netsat']);
            $netsat_id = $pdo->lastInsertId();
            $netsat_contacts = [
                ['Level 1', 'Netsat Support Desk', 'Help desk / CNOC Netsat', '1 Hour', '021-111638728', 'support.cmpak@netsat.net.pk'],
                ['Level 1', 'Ali Nadeem', 'Help desk / CNOC Netsat', '1 Hour', '0309 1112422; 0312-2431968', 'ali.nadeem@netsat.net.pk'],
                ['Level 2 (Central Region)', 'Zafar Iqbal (CTR)', 'Regional head (Lahore)', '3 hours', '0301-8114185', 'zafar.iqbal@netsat.net.pk'],
                ['Level 2 (South Region)', 'Farhan (Sindh)', 'Regional head (Sindh)', '3 hours', '0301-8114182', 'm.farhan@netsat.net.pk'],
                ['Level 3', 'M. Tahir Qureshi', 'Operational Support', '6 hours', '0300 8232647', 'm.tahir@netsat.net.pk'],
                ['Level 4', 'Syed Mubashir Imam', 'Senior Operational Head', '12 hours', '0301-8292632', 'mubashir.imam@netsat.net.pk'],
            ];
            foreach ($netsat_contacts as $c) {
                $c_stmt->execute(array_merge([$netsat_id], $c));
            }

            // Comstar
            $stmt->execute(['Comstar']);
            $comstar_id = $pdo->lastInsertId();
            $comstar_contacts = [
                ['Level 1', 'Support Desk', 'CS', '10-15min', '0333-1312343', 'cs@comstar.com.pk'],
                ['Level 2', 'Abdul Wajid', 'TL (South)', '15-30min', '0334-2594529', 'awajid@comstar.com.pk'],
                ['Level 3', 'Osman Javaid', 'Manager Engineering', '30-45min', '0336-5479293', 'ojavaid@comstar.com.pk'],
            ];
            foreach ($comstar_contacts as $c) {
                $c_stmt->execute(array_merge([$comstar_id], $c));
            }

            // Vision Telecom
            $stmt->execute(['Vision Telecom']);
            $vt_id = $pdo->lastInsertId();
            $vt_contacts = [
                ['Level 1', 'Corporate Helpdesk', 'Support', 'Immediate', '0308-8881418', 'support@visiontelecom.com.pk'],
                ['Level 2', 'Rizwan Younis', 'Team Lead', '30min', '0300-0807140', 'rizwan.younis@visiontelecom.com.pk'],
            ];
            foreach ($vt_contacts as $c) {
                $c_stmt->execute(array_merge([$vt_id], $c));
            }
        }

    } catch (PDOException $e) {
        $db_error = "Database Error: " . $e->getMessage();
        $pdo = null;
    }
}
?>
