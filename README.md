# Corporate NOC Console

PHP 8.1+ website with SQLite persistence, restored against the supplied Python NOC Console reference. The existing welcome.sqlite database is retained by default. Schema additions are automatic; no MySQL import is needed. setup.sql belongs to the original welcome-page prototype and is not used by this console.

## Run locally

```powershell
php -S 127.0.0.1:8000 router.php
```

Open http://127.0.0.1:8000. PHP must have PDO SQLite enabled. Local loopback access works without credentials for development. To use the shared login, set NOC_PASSWORD before starting PHP; the default username is nasir. Requests from outside loopback require the password to be configured.

```powershell
$env:NOC_USERNAME = 'nasir'
$env:NOC_PASSWORD = 'your-own-password'
php -S 127.0.0.1:8000 router.php
```

Administration uses the console login. Optionally configure NOC_ADMIN_PASSWORD for a separate administration password. The previous hardcoded admin123 password has been removed.

## Outage analyzer

The website and complaint workflows run in PHP. Only alarm-file processing uses the Python analysis engine from the supplied reference, preserving its classification and Excel report formats.

```powershell
py -m pip install -r requirements-outage.txt
```

Set NOC_PYTHON to an absolute Python executable path when needed. Defaults: py on Windows, python3 on Linux. PHP must allow proc_open. Other console tabs do not require Python. Analyzer failures display setup guidance without affecting complaint workflows.

Accepted files: CSV, XLSX, and XLS, up to 20 MB (also subject to PHP upload_max_filesize and post_max_size). Required columns: Location Info and Last Occurred (ST). Preamble rows are supported. Processing excludes visibility alarms and non-ESS labels, deduplicates services, prioritizes links above 250 Mbps, separates BGP DIA, and produces categorized and simple Excel reports. Bandwidth values in Gbps and Kbps are normalized to Mbps.

Outage history persists in SQLite until cleared. Handover totals cover all entries since the last clear, so clear shift history at the shift boundary. Report files are stored in the configured data directory under exports; old export directories can be removed during maintenance after their downloads are no longer needed.

## Restored workflows

- Complaint selection, ticket context, refresh, details, and removal live in Complaint Manager only.
- Opening directly from a typed label or queued complaint, with custom issue and automatic/manual Pakistan times.
- Closure validation (open complaints only; closing time cannot precede opening), full root-cause/action options, custom fields, final status, priority, and consistent NOC-impact duration.
- Full customer findings and actions, including a separate internal Turbonet policy notice.
- All 12 troubleshooting scenarios, audience selection, extra context, and PCAPdroid instructions for banking applications.
- Progress-specific wording, all ETTR modes, custom ETTR, audience, notes, priority, and saved dashboard stages.
- Vendor Escalation shows only the vendor selector and editable generated email body. Selecting a vendor generates the message automatically, using the complaint selected in Complaint Manager or a service-details placeholder. Advanced escalation and draft endpoints remain available in the backend but are not exposed in this simplified tab.
- Vendor matrix viewer and administration link for vendor/contact CRUD.
- Independent active queue and filtered dashboard tables, all-complaints view, last-stage column, and minute-by-minute age refresh.
- Outage analysis, two Excel downloads, persistent shift history, and handover summary.

Generated subjects and bodies are editable before copying or downloading. Dates and complaint timers use Asia/Karachi (UTC+05:00), regardless of the server operating-system timezone. Re-generating an opening preserves its saved opening time. Re-registering a closed service creates a new complaint and retains the previous closed record.

## Hosting and storage

Use Apache/PHP or another supported PHP web server for deployment; the built-in PHP server is intended for local development. .htaccess blocks direct downloads of database and implementation files under Apache. Enable overrides for these rules. Other web servers need equivalent rules, or a document root containing only public assets and entry points. Use HTTPS for remote access.

NOC_DATA_DIR sets a persistent private storage directory outside the web root. When configured, the database is read from NOC_DATA_DIR/welcome.sqlite; to retain an existing installation, copy its database there before starting with this setting. Without it, the existing project database is used, and analyzer exports go to the sibling noc_data directory. Back up the database before moving an installation. The PHP process needs write access to its data directory.

## Verification

```powershell
py tests/test_console.py
node --check noc_console.js
```

The integration suite launches an isolated local PHP server with a temporary database. It covers authentication/CSRF, complaint transitions, manual times, all troubleshooting scenarios, escalation formats, stage updates, duration handling, outage deduplication, both Excel downloads, handover history, and deletion. It does not modify the installation database.
