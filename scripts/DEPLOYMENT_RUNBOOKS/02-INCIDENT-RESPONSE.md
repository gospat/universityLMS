# ULMS Production Incident Response Runbook
## Bells University of Technology — https://learn.bellsuniversity.edu.ng

**Scope**: Major incidents (outage, security breach, data loss) affecting ULMS production.
**Escalation model**: Any engineer who first detects a P0/P1 incident OWNS Step 1 (Declare + page)
  regardless of seniority. The Incident Commander (IC) designation happens inside Step 1.

---

## 1. Severity Definitions

| Level | Name | Triggering conditions | First response SLA |
|-------|------|-----------------------|--------------------|
| **P0** | CRITICAL — Full outage or security incident | (a) All portals return non-2xx for 5+ minutes; (b) Confirmed or strongly suspected data breach / auth bypass / PII leak; (c) Database integrity visibly compromised (grades, receipts, attendance). | **5 minutes** to declare + page on-call. |
| **P1** | HIGH — Partial outage or data integrity risk | (a) Single major portal (student / staff / admin / exam) down 10+ minutes; (b) Widespread UX broken; (c) Data integrity risk (e.g. grade writes failing). | **15 minutes** to declare + page on-call. |
| **P2** | MEDIUM — Degraded service | Slow response times (p95 > 2 s for 20+ min), specific feature broken, UX inconsistency. | **60 minutes** to triage. No page required; track on ticket queue. |

**Rule**: When in doubt, UPGRADE severity. Never downgrade a P0 to P1 on gut feeling alone — require
measured evidence of recovery plus 10 minutes of stable healthz 200s.

---

## 2. P0 Incident Response (12 Steps)

### Step 1 — Declare incident + page on-call
```
Post in #incidents (Slack/Teams):
  @channel 🔴 INCIDENT DECLARED — P0 — ULMS Production
  Detected by: <name>
  Time:     <UTC / WAT>
  Signal:   <e.g. "healthz.php returns 500 for 6 minutes", "user reports sign-in redirect loop", "SOC alert on PII">
  Blast:    (unknown, TBD in Step 2)
  IC:       <Incident Commander name> — appointed now.
  Scribe:   <Scribe name> — appointed now, starts writing timeline.
```
Page on-call DevOps + Hosting support. IC runs the rest of this runbook; others do NOT take unilateral actions.

### Step 2 — Confirm blast radius
Within 10 minutes, IC directs answering:
- All users affected, or a subset (student / staff / admin / external examiner)?
- All portals or a specific one (e.g. `/my/` works but `/course/` fails)?
- External-facing URLs only, or internal admin also?
- DB state safe or possibly modified? (`SELECT count(*)` on core tables to compare to baseline.)
- Any PII leaked in publicly accessible URLs / logs / error pages?

Update #incidents with the blast-radius summary.

### Step 3 — Maintenance page on (nginx 503 return)
Only if full outage OR security breach. Skip if partial portal outage can be isolated.
```bash
# /var/www/MAINTENANCE.html already exists per pre-flight (see 01-DEPLOY.md §1.5).
# Enable the 503 snippet in nginx:
#   edit /etc/nginx/snippets/ulms-maintenance.conf -> uncomment the return 503 block
sudo nginx -t && sudo systemctl reload nginx
# Verify
curl -skS -o /dev/null -w "%{http_code}" https://learn.bellsuniversity.edu.ng/   # expect 503
```
**Critical**: Whitelist deploy engineer source IP so debugging and rollback remains possible.

### Step 4 — SECURITY BRANCH: Secrets rotation + session invalidate
ONLY execute if the incident is suspected / confirmed security (PII leak, auth bypass, defacement, session hijack, XSS data exfiltration).
```bash
# 4a. Rotate ALL secrets in /var/www/.env. Keep copies in the vault, never plain-text in Slack.
#     DB user password (ulms_rw), Resend API key, Redis password, JWT signing key, session salt.
sudo -u www-data vi /var/www/.env
# 4b. Invalidate every user session (forces all users to re-login with new salt).
sudo -u www-data php admin/cli/session_destroy_all.php
# 4c. Reset ulms_rw MySQL password
mysql -uroot -e "ALTER USER 'ulms_rw'@'localhost' IDENTIFIED BY '<new-strong-password>'; FLUSH PRIVILEGES;"
#     Update .env DB_PASSWORD to match.
# 4d. If Redis sessions: flush
redis-cli FLUSHALL
```

### Step 5 — AVAILABILITY BRANCH: Snapshot current state
If the incident is performance/availability (not confirmed breach), preserve evidence BEFORE any restart/rollback.
```bash
mkdir -p /var/backups/ulms/incident-$TIMESTAMP
cd /var/backups/ulms/incident-$TIMESTAMP

top -b -n 1 > top.txt
vmstat 1 5 > vmstat.txt
free -h > free.txt
df -h > df.txt
iostat -xz 1 5 > iostat.txt

mysql -e "SHOW FULL PROCESSLIST;" > mysql_processlist.txt
mysql -e "SHOW ENGINE INNODB STATUS\G" > innodb_status.txt
mysql -e "SHOW STATUS LIKE 'Threads%';" > mysql_threads.txt

sudo journalctl -u php8.2-fpm --since "20 minutes ago" > php_fpm.log
sudo tail -n 5000 /var/log/nginx/access.log > nginx_access_5k.log
sudo tail -n 2000 /var/log/nginx/error.log > nginx_error_2k.log
sudo tail -n 1000 /var/log/php8.2-fpm-slow.log > php_slow.log

# Archive
tar -czf /var/backups/ulms/incident-$TIMESTAMP.tar.gz /var/backups/ulms/incident-$TIMESTAMP
echo "Evidence preserved: /var/backups/ulms/incident-$TIMESTAMP.tar.gz" >> #incidents
```

### Step 6 — If rollback is warranted, execute Rollback Procedure
If root cause is new deploy OR cannot be identified within 15 minutes, IC says "ROLLBACK" and
the deploy engineer executes **FILE 1 (01-DEPLOY-AND-ROLLBACK.md) §3 — Rollback Procedure** verbatim.
Time the rollback: target completion in under 10 minutes.

### Step 7 — Root Cause Analysis (RCA) template
Scribe opens the RCA document and fills in real-time during mitigation:
```
RCA: <date> — ULMS P0 <incident title>
1. TIMELINE (UTC / WAT):
   - <T+0> Detection
   - <T+X> Declared + IC appointed
   - <T+Y> Evidence snapshot / secrets rotated
   - <T+Z> Rollback / fix applied
   - <T+W> Service recovered
2. IMPACT:
   - Users affected: <N>
   - Duration from first error to full recovery: <min>
   - Data lost or at risk:
3. ROOT CAUSE:
   - Technical (what code / config / state failed)
   - Contributing (what monitoring gap / human gap / process gap)
4. MITIGATION STEPS TAKEN:
5. REMEDIATION ACTIONS (owner + due date):
   - [ ] <short-term>
   - [ ] <long-term>
   - [ ] <monitoring/alerting>
6. EVIDENCE LINKS: (snapshots, ticket, PRs)
```

### Step 8 — Post-incident report within 24 business hours
IC owns delivery. Report shared with: DevOps, Product owner, DPO, University management, Hosting support.

### Step 9 — Forensics preserve
If security incident:
- Do NOT clean or rm the preserved snapshot (`/var/backups/ulms/incident-*.tar.gz`).
- Write-protect: `chattr +i` on the archive.
- Hand off copy to DPO + external forensics if DPO orders it.
- Do NOT modify any server state not explicitly required by Sec. 2 Step 4.

### Step 10 — Notify stakeholders
- **Internal**: University management, Registrar (exams/grades affected), Bursary (receipts/finance affected), IT head.
- **External**: NDPC Nigeria (if personal data breach, see FILE 3 §5), Hosting provider account team, Students union rep (if > 1 h outage during exam period).

### Step 11 — Re-deploy hardened patch
Once root cause identified, remediation code reviewed, and staging green:
- Execute the full **FILE 1 — Atomic Deploy Procedure** (including 10-item pre-flight) on a NEW release dir.
- Never hot-patch a recovered production server by hand after a P0 — always atomic deploy.

### Step 12 — Post-mortem meeting
Scheduled within 5 business days, chaired by IC, minuted, actions tracked to closure.

---

## 3. Ten P0 Scenarios — Step-by-step Mitigations

### Scenario A — DB Connection Exhausted (`Too many connections`)
Symptoms: 5xx across the board, `mysql -e "SHOW STATUS LIKE 'Threads_connected';"` ~= max_connections.
Steps:
1. `mysql -uroot -e "SHOW FULL PROCESSLIST;"` → identify rows with `Command=Sleep` and `Time>600`.
2. Kill sleeping connections in bulk:
   ```sql
   SELECT CONCAT('KILL ',id,';') FROM information_schema.processlist WHERE Command='Sleep' AND Time>600 INTO OUTFILE '/tmp/kill_sleep.sql';
   SOURCE /tmp/kill_sleep.sql;
   ```
3. Immediate raise `max_connections` dynamically (perm change in my.cnf on next maintenance):
   ```sql
   SET GLOBAL max_connections = 500;
   ```
4. Snapshot state per Sec. 2 Step 5.
5. Identify app-side leak: FPM `pm.max_children` * persistent connections > DB limit. Fix pool size.

### Scenario B — Brute Force Login Storm
Symptoms: 1000+ `/login/index.php` requests/min from single IP or subnet; error_log "invalid login".
Steps:
1. Identify attacker IPs:
   ```bash
   awk '{print $1}' /var/log/nginx/access.log | sort | uniq -c | sort -rn | head -20
   ```
2. Blackhole route immediately (no firewall state cost):
   ```bash
   sudo ip route add blackhole <ATTACKER_IP/32>
   # OR subnet
   sudo ip route add blackhole <ATTACKER_SUBNET>/24
   ```
3. Rate-limit login URL in nginx + reload: `limit_req zone=login burst=10 nodelay;`
4. Enable Moodle login lockout (Site admin → Security → Site policies) if not already on.
5. Notify Hosting support DDoS mitigation if > 1 Gbps / > 10k req/min.

### Scenario C — XSS / Defacement
Symptoms: Altered homepage, unknown `<script>` tags injected, admin reports unauthorized theme changes.
Steps:
1. Take snapshot (Sec. 2 Step 5) + preserve webroot: `tar -czf /var/backups/ulms/defaced-snapshot-$TIMESTAMP.tar.gz /var/www/universityLMS/`
2. Maintenance on (Sec. 2 Step 3).
3. Redeploy from clean, verified git tag using FILE 1 Atomic Deploy (full release build, NOT hot patch).
4. Secrets rotation + session destroy (Sec. 2 Step 4) — assume admin accounts were hijacked.
5. Security branch: grep injected payload across all tables. Purge rows from `config_plugins`, `block_instances`, `course` where payload matches.
6. Health checks green → maintenance off.

### Scenario D — GDPR PII Leak (e.g. CSV / SQL dump publicly accessible)
Symptoms: External URL serves a roster, grades, or receipts dump; security researcher disclosure.
Steps:
1. Contain immediately: `chmod 0` the offending file + remove from nginx index.
2. Snapshot evidence (Sec. 2 Step 5 + 9).
3. Page DPO IMMEDIATELY (within 10 minutes of detection).
4. DPO decides whether FILE 3 §5 (Data Breach Response / NDPC 72h notification) is triggered.
5. Fix exposure: tighten `try_files`, remove autoindex, audit all directory aliases in nginx.
6. If backup object storage leaked, rotate storage keys AND mark files private.

### Scenario E — Payment Gateway Down (if applicable)
Symptoms: All enrolment checkout attempts fail, receipt not issued.
Steps:
1. Confirm gateway status page + merchant dashboard (not us).
2. Update site banner: "Payment services temporarily unavailable, please retry in 30 min."
3. Enable fallback gateway in `.env` if configured + test single transaction.
4. Fail open or closed? IC + Bursary decision. Default: fail closed on payments.
5. Log every failed attempt into `local_ulms_payments_failed` for reconciliation on recovery.
6. Notify registrar + bursary of batch retry plan.

### Scenario F — Disk Full (/) or /var partition > 95 %
Symptoms: MySQL crashes, fpm cannot write sessions, nginx logrotate fails.
Steps:
1. Find largest files:
   ```bash
   sudo du -xSh /var 2>/dev/null | sort -rh | head -15
   sudo journalctl --vacuum-time=2d
   ```
2. Purge old backups older than retention policy (never purge newest 7 daily + 4 weekly):
   ```bash
   find /var/backups/ulms/ -name "*.sql.gz" -mtime +30 -delete
   ```
3. Rotate current logs:
   ```bash
   sudo logrotate -f /etc/logrotate.d/nginx
   sudo logrotate -f /etc/logrotate.d/php8.2-fpm
   ```
4. If moodledata `filedir` is the driver: run scheduled garbage collection, check orphaned files.
5. Long-term: order larger volume with hosting support.

### Scenario G — PHP-FPM Crash Loop
Symptoms: `systemctl status php8.2-fpm` shows `activating / failed` repeated, kernel log `oom-kill`.
Steps:
1. Snapshot state (Sec. 2 Step 5) — do NOT skip, evidence is volatile.
2. Immediate rollback to PREVIOUS release via FILE 1 §3.
3. If even rollback FPM crashes: raise `memory_limit` in `php.ini` 2x temporarily; lower `pm.max_children`.
4. Restart: `systemctl restart php8.2-fpm`.
5. Post-recovery: profile with `xhprof` / `tideways` on staging to find memory spike source.

### Scenario H — Moodle Upgrade Stuck (lock file present from deploy)
Symptoms: Deploy upgrade step hung, `upgrade.php` output shows "Upgrade is in progress from another session".
Steps:
1. Maintenance stays on (do NOT turn off mid-upgrade).
2. Confirm lock: `ls -la /var/moodledata/upgrade.lock`
3. Remove lock ONLY if no process is actively writing: verify no DDL in `SHOW PROCESSLIST`.
   ```bash
   sudo rm /var/moodledata/upgrade.lock
   ```
4. Resume upgrade from where it left off:
   ```bash
   sudo -u www-data php admin/cli/upgrade.php --non-interactive --keep-token-modifications
   ```
5. If upgrade step repeats failure: IC decides ROLLBACK + DB restore (FILE 1 §3).

### Scenario I — DNS / TLS Certificate Expired
Symptoms: Browsers show "Your connection is not private", `curl` returns `SSL_CERTIFICATE_EXPIRED`.
Steps:
1. Confirm expiry:
   ```bash
   openssl s_client -connect learn.bellsuniversity.edu.ng:443 -servername learn.bellsuniversity.edu.ng </dev/null 2>/dev/null | openssl x509 -noout -dates
   ```
2. Renew via certbot (if managed):
   ```bash
   sudo certbot renew --force-renewal --cert-name learn.bellsuniversity.edu.ng
   sudo systemctl reload nginx
   ```
3. If DNS issue (NXDOMAIN): login to DNS provider, confirm A/AAAA records point to server IP. Temporarily announce IP-only workaround URL to internal users if downtime > 15 min.
4. Post-incident: add Prometheus/Blackbox alert on cert expiry (threshold: < 14 days).

### Scenario J — Session Fixation / Stolen Session Cookie
Symptoms: SOC alert, user reports actions performed by their account they did not initiate.
Steps:
1. Immediate: ALL sessions destroyed.
   ```bash
   sudo -u www-data php admin/cli/session_destroy_all.php
   # If Redis backed: redis-cli FLUSHDB or FLUSHALL (careful if shared!).
   ```
2. Rotate session salt + cookiesalt in `.env` / `config.php`.
   ```
   $CFG->sessioncookiename = 'MoodleSession_<NEWRAND>';
   $CFG->passwordsaltmain = '<NEW-64-CHAR-HEX>';
   ```
3. Force password reset for impacted users, or globally if scope unknown.
4. Maintenance on briefly → reload → maintenance off.
5. Investigate vector (MITM? XSS? shared workstation? 3rd-party script?).

---

## 4. Contacts List

| Role | Name | Channel | Phone / Pager | Notes |
|------|------|---------|---------------|-------|
| Service Owner (ULMS Product) | `<Owner Name>` | Slack `@owner` | `<phone>` | Business decisions / comms authority. |
| On-call DevOps / SRE | `<DevOps Name>` | Slack `@devops-oncall` | PagerDuty `<pd-service-link>` | Technical IC default. |
| Data Protection Officer (DPO) | `<DPO Name>` | Slack `@dpo` | `<phone>` | ALL PII / breach decisions, signs off NDPC notifications. |
| Hosting Support (e.g. DigitalOcean / local provider) | — | Hosting panel ticket | `<24/7 hotline>` | Hardware / network / volume. |
| Database Administrator | `<DBA Name>` | Slack `@dba` | `<phone>` | MySQL performance, backup restore validation. |
| Network Security / SOC | `<SOC Name>` | Slack `@soc` | `<phone>` | Firewall, WAF, DDoS, forensic imaging. |
| Registrar (academic impact) | `<Registrar Name>` | Email + phone | `<office>` | Exam / grade impact comms. |
| Bursar (financial / receipt impact) | `<Bursar Name>` | Email + phone | `<office>` | Payment, fee, receipt reconciliation. |
| **NDPC Nigeria — Breach Notification** | National Information Technology Development Agency | `www.ndpc.gov.ng` | **+234-9-461-5868** (Liaison Office), email: `enforcement@ndpc.gov.ng` | Mandatory GDPR/NDPR notification within 72 h of breach discovery if ≥ 500 Nigerian data subjects affected. Reference: NDPR 2019, Schedule 1 Part III. |
| Legal Counsel | `<Counsel Name>` | Email | `<phone>` | Litigation hold, regulator letters, student contract clauses. |
