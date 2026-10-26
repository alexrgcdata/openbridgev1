# Security

This is a development snapshot, not a security-certified production release.

Report suspected vulnerabilities privately to **alex@rgcdata.com** with a minimal
reproduction using synthetic data. Do not include passwords, bearer tokens,
installation keys, database files or customer records. Do not publish exploitable
details or secrets in a public issue.

Before hosting, review [storage security](docs/security-storage.md), use HTTPS,
disable demo accounts, configure application permissions, and verify database,
key and configuration files cannot be downloaded. Back up the installation key
separately and retain it with the matching database backup. Rotation and guided
recovery are not complete workflows yet.
