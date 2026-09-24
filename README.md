# Smart Portal — Network Operations Console

A PHP-based operational console for **Network Operations Center (NOC)** workflows, built by **Nasir Ali (@itsnasiralii)**.

Smart Portal brings common incident, complaint, escalation, outage-analysis, dashboard, and reporting workflows into a single web interface.

## Highlights

- Complaint and incident workflow management
- Operational status and duration tracking
- Standardized opening, progress and closure communication
- Vendor-escalation drafting
- Active queue and dashboard views
- Outage analysis from CSV/XLS/XLSX inputs
- Excel report generation
- Persistent SQLite storage
- Asia/Karachi timezone handling
- Authentication and CSRF protection
- Docker and PHP deployment support

## Architecture

- PHP 8.1+
- SQLite
- JavaScript
- Optional Python analysis engine for outage-file processing
- Docker support

## Local development

Start the PHP application locally:

```bash
php -S 127.0.0.1:8000 router.php
```

For outage-file analysis, install the optional Python dependencies:

```bash
python -m pip install -r requirements-outage.txt
```

Use environment variables for credentials and deployment-specific configuration. Do not hard-code production passwords or secrets into the repository.

## Outage analysis

The analyzer accepts structured CSV/XLS/XLSX input and produces categorized operational reports. It supports:

- service filtering and deduplication
- bandwidth normalization
- categorized outage views
- Excel report generation
- persistent shift history and handover summaries

## Deployment notes

For production use:

- deploy behind HTTPS
- keep persistent application data outside the public web root
- use environment variables for authentication
- restrict access to operational files
- back up persistent SQLite data
- avoid committing customer, vendor, credential, or production-network information

## Verification

```bash
python tests/test_console.py
node --check noc_console.js
```

## Project purpose

This project demonstrates how web tooling and automation can support enterprise **NOC/CNOC operations**, improve consistency in incident handling, and reduce repetitive operational work.

## Author

**Nasir Ali**  
Network Operations • Telecom • IP Networking • Automation  
GitHub: [@itsnasiralii](https://github.com/itsnasiralii)

## Responsible use

Use sanitized or synthetic data for public demonstrations. Keep customer details, vendor contacts, escalation matrices, credentials, internal topology data, and production information private.
