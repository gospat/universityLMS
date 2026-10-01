<?php
define('ULMS_PUBLIC_ROUTE_REQUEST', 1);

require_once(__DIR__ . '/../../config.php');
@include_once($CFG->dirroot . '/local/ulms_dashboard/lib.php');
@include_once(__DIR__ . '/lib.php');

if (function_exists('local_ulms_dashboard_mark_request_start')) {
    local_ulms_dashboard_mark_request_start();
}

if (function_exists('local_ulms_privacy_extend_navigation')) {
    local_ulms_privacy_extend_navigation(null);
}

$page = (string)($_GET['page'] ?? 'privacy');
$allowed = ['privacy', 'gdpr', 'cookie-policy', 'data-protection'];
if (!in_array($page, $allowed, true)) {
    $page = 'privacy';
}

$institutionname = '';
$institutionshort = '';
$footerline = '';
if (function_exists('ulms_institution_cascade')) {
    $institutionname = (string)ulms_institution_cascade('NAME', [
        'ULMS_DEFAULT_SUPPORT_NAME',
        'SMTP_SUPPORT_NAME',
        'RESEND_FROM_NAME',
    ], get_config('core', 'fullname') ?: 'Your University');
} else {
    $override = (string)(($_ENV['INSTITUTION_NAME'] ?? getenv('INSTITUTION_NAME')) ?: '');
    $institutionname = $override !== '' ? $override : (get_config('core', 'fullname') ?: 'Your University');
}
if (function_exists('ulms_env')) {
    $institutionshort = (string)ulms_env('INSTITUTION_SHORT_CODE', '');
    $footerline = (string)ulms_env('INSTITUTION_FOOTER', '');
} else {
    $institutionshort = (string)(($_ENV['INSTITUTION_SHORT_CODE'] ?? getenv('INSTITUTION_SHORT_CODE')) ?: '');
    $footerline = (string)(($_ENV['INSTITUTION_FOOTER'] ?? getenv('INSTITUTION_FOOTER')) ?: '');
}
if ($institutionshort === '') {
    if (function_exists('ulms_institution_short_code')) {
        $institutionshort = ulms_institution_short_code($institutionname);
    } else {
        $words = preg_split('/\s+/', trim($institutionname), -1, PREG_SPLIT_NO_EMPTY);
        $s = '';
        foreach ((array)$words as $w) {
            $c = mb_substr($w, 0, 1);
            if (preg_match('/[A-Za-z0-9]/', $c)) {
                $s .= strtoupper($c);
            }
            if (mb_strlen($s) >= 4) {
                break;
            }
        }
        $institutionshort = $s !== '' ? $s : 'ULMS';
    }
}
if ($footerline === '') {
    $year = date('Y');
    $footerline = "© {$year} {$institutionname} — All rights reserved. ULMS Platform.";
}

$titles = [
    'privacy'         => 'Privacy Policy',
    'gdpr'            => 'GDPR Data Subject Rights',
    'cookie-policy'   => 'Cookie Policy',
    'data-protection' => 'Data Protection Statement',
];
$pagetitle = $titles[$page] ?? 'Privacy Policy';
$brandprimary = '#0f4c81';
$bodyclass = 'ulms-legal-page';
$homeurl = '/';
$dashboardback = isloggedin() && !isguestuser() ? '/local/ulms_dashboard' : '/local/ulms_auth';
$escinstname = htmlspecialchars($institutionname, ENT_QUOTES, 'UTF-8');
$escshortname = htmlspecialchars($institutionshort, ENT_QUOTES, 'UTF-8');
$escfooter = htmlspecialchars($footerline, ENT_QUOTES, 'UTF-8');
$esctitle = htmlspecialchars($pagetitle, ENT_QUOTES, 'UTF-8');
$lastupdated = date('F d, Y');

$sections = [
    'privacy' => [
        '1. Overview' => "
            <p>This Privacy Policy explains how <strong>{$escinstname}</strong> (\"we\", \"us\", or \"our\") collects, uses, discloses, transfers, stores, and processes information about you when you use the University Learning Management System (\"ULMS\" or the \"Service\"). We process personal data in accordance with the Nigeria Data Protection Act (NDPA) 2023, the EU General Data Protection Regulation (GDPR) where applicable, the U.S. Family Educational Rights and Privacy Act (FERPA) for student education records, and other applicable privacy laws.</p>
            <p>This policy applies to all users of the Service, including students, faculty, staff, administrators, contractors, and invited guests. By accessing or using the Service, you acknowledge that you have read, understood, and agreed to the practices described in this Privacy Policy.</p>
        ",
        '2. Information we collect' => "
            <p>We may collect the following categories of personal information:</p>
            <ul>
                <li><strong>Account &amp; identity data:</strong> name, username, email address, telephone number, institutional identifier (staff ID / student ID), password credentials (salted hashes only).</li>
                <li><strong>Profile &amp; academic data:</strong> faculty, department, programme of study, academic level (where applicable), staff/student photograph, date of birth, next-of-kin name, telephone and relationship.</li>
                <li><strong>Consent &amp; opt-out records:</strong> FERPA directory-information opt-out, SMS marketing consent, and cookie-consent category preferences (strictly necessary / analytics / marketing).</li>
                <li><strong>Usage &amp; device data:</strong> login timestamps, IP address, user agent, pages visited, learning activity (course views, assignment submissions, quiz attempts, attendance records), error reports.</li>
                <li><strong>Communications:</strong> emails sent through the platform, support tickets, feedback submissions, and records of marketing consents.</li>
                <li><strong>Payment &amp; financial data:</strong> receipts for any fees processed through the Service (handled exclusively by PCI-DSS compliant payment processors; we do not store full card numbers).</li>
            </ul>
        ",
        '3. How we use information' => "
            <p>We use your personal information on the following lawful bases (GDPR Article 6 / NDPA Section 7):</p>
            <ol>
                <li><strong>Performance of a contract:</strong> delivery of learning, assessment, course enrolment, authentication, and institutional student record keeping.</li>
                <li><strong>Legitimate interests:</strong> platform security, fraud prevention, academic integrity, service reliability and feature improvements based on aggregated usage analytics.</li>
                <li><strong>Compliance with a legal obligation:</strong> FERPA record keeping, financial audit, regulatory reporting, law enforcement requests duly supported by warrant or subpoena.</li>
                <li><strong>Consent:</strong> SMS marketing, optional analytics cookies, marketing cookies, and voluntary survey participation. You may withdraw consent at any time via the Privacy Centre or by contacting the Data Protection Officer.</li>
                <li><strong>Vital interests:</strong> disclosure of next-of-kin and emergency medical contact data where life or safety is at risk.</li>
            </ol>
        ",
        '4. Sharing &amp; disclosure' => "
            <p>We may share your information with the following categories of recipients, and only for the purposes described above:</p>
            <ul>
                <li>Institutional departments (Registrar, Bursary, Faculties, ICT) and institutional auditors.</li>
                <li>Sub-processors (email delivery services, SMS gateways, payment processors, CDN providers, hosting providers, backups and disaster recovery locations) operating under written Data Processing Agreements.</li>
                <li>Accrediting bodies, government regulators and law enforcement when required by applicable law.</li>
            </ul>
            <p>We do not sell, rent or lease your personal information to third parties for commercial marketing purposes without your explicit opt-in consent.</p>
        ",
        '5. International transfers' => "
            <p>Personal data is primarily processed in Nigeria. Where sub-processors operate outside Nigeria or the European Economic Area, transfers take place under adequacy decisions or Standard Contractual Clauses with appropriate supplementary technical and organizational measures (encryption in transit, pseudonymization, access controls and audit logging).</p>
        ",
        '6. Retention' => "
            <p>Student education records are retained in line with institutional records retention schedules (typically 7 years after graduation or final withdrawal in accordance with the National Universities Commission guidelines). Account credentials and consent logs are retained for the duration of your relationship with us plus the statute of limitations for any potential claim. Marketing consent records are retained for 3 years after the last recorded interaction unless you sooner withdraw consent.</p>
        ",
        '7. Your rights' => "
            <p>You may have the following rights under applicable privacy law (GDPR Articles 15–22, NDPA Sections 17–26, FERPA 20 U.S.C. § 1232g):</p>
            <ul>
                <li><strong>Access:</strong> obtain a copy of your personal information held by us.</li>
                <li><strong>Rectification:</strong> request correction of inaccurate or incomplete records.</li>
                <li><strong>Erasure:</strong> request deletion of your personal data, subject to overriding legal retention obligations.</li>
                <li><strong>Restriction:</strong> request a temporary restriction on processing pending verification.</li>
                <li><strong>Data portability:</strong> receive personal data you supplied in a structured, commonly-used, machine-readable format.</li>
                <li><strong>Objection:</strong> object to direct marketing at any time, free of charge.</li>
                <li><strong>Withdraw consent:</strong> any consent-based processing can be stopped by revoking consent from the Privacy Centre.</li>
                <li><strong>Lodge a complaint:</strong> with the National Information Technology Development Agency (NITDA) or your national data protection authority.</li>
            </ul>
            <p>To exercise your rights, contact the Data Protection Officer at the email address shown below.</p>
        ",
        '8. Data security' => "
            <p>We implement appropriate technical and organizational security measures including: per-column AES-256 encryption of sensitive fields, TLS 1.3 for all transit traffic, role-based access controls, quarterly vulnerability assessments, OWASP Top-10 hardening, structured audit logging (security events, authentication, provisioning, PII access), and regular backup testing under our incident response plan.</p>
        ",
        '9. Children' => "
            <p>The Service is not directed to children under 13 years of age. If you are a parent or guardian and believe we have inadvertently collected personal information about a child under 13 without parental consent, please contact us immediately so the record can be removed.</p>
        ",
        '10. Contact &amp; DPO' => "
            <p>For all privacy-related enquiries, suspected breaches, or rights requests, contact the Data Protection Officer at:</p>
            <blockquote>
                <strong>Data Protection Officer, {$escinstname}</strong><br />
                Email: dpo@" . preg_replace('/^www\./i', '', parse_url($CFG->wwwroot ?? '', PHP_URL_HOST) ?: (strtolower($institutionshort) . '.edu.ng')) . "<br />
                Postal: Registrar's Office, Main Campus
            </blockquote>
            <p>We acknowledge privacy requests in writing within 5 business days and respond substantively within 30 calendar days as permitted by law.</p>
        ",
        '11. Changes to this policy' => "
            <p>We may update this Privacy Policy from time to time to reflect regulatory, institutional, or Service changes. Material changes are notified via banner notice on the Service and by email to registered contacts, with an effective date. The version number and last updated date appear at the foot of this document.</p>
            <p class=\"ulms-last-updated\"><em>Last updated: {$lastupdated} &mdash; Version 1.0</em></p>
        ",
    ],
    'gdpr' => [
        'Overview' => "
            <p>This page summarises the rights of Data Subjects under the EU General Data Protection Regulation (Regulation (EU) 2016/679) and the Nigeria Data Protection Act (NDPA) 2023 where applicable, and explains how <strong>{$escinstname}</strong> honours those rights within the University Learning Management System.</p>
            <p>When our processing of your personal data is subject to the GDPR, we act as the Data Controller. Our representative in the EU for GDPR matters is identified below, and our appointed Data Protection Officer can be reached on the main privacy policy contact page.</p>
        ",
        'Lawful bases relied upon' => "
            <p>GDPR Article 6 lawful bases used within ULMS are:</p>
            <ol>
                <li><strong>Article 6(1)(b) — Contract:</strong> authentication, enrolment, grades and student records necessary to perform the institutional contract of education.</li>
                <li><strong>Article 6(1)(c) — Legal obligation:</strong> FERPA, tax and audit, public authority disclosures under warrant or court order.</li>
                <li><strong>Article 6(1)(d) — Vital interests:</strong> disclosure of next-of-kin or medical contact data in a medical emergency.</li>
                <li><strong>Article 6(1)(e) — Public task:</strong> statutory functions of the University.</li>
                <li><strong>Article 6(1)(a) — Consent:</strong> marketing cookies, analytics cookies and SMS marketing. Consent is explicit, specific, time-stamped and revocable at any time from the Cookie Preference Centre.</li>
                <li><strong>Article 6(1)(f) — Legitimate interests:</strong> cybersecurity, fraud detection, academic integrity proctoring analytics and service reliability monitoring.</li>
            </ol>
        ",
        'Special category data (GDPR Article 9)' => "
            <p>Where we process special category data (health data revealed through attendance or medical withdrawal records, racial or ethnic origin in optional demographic surveys, trade union membership where disclosed), we do so only under the following conditions:</p>
            <ul>
                <li>Explicit consent for a specified purpose (Article 9(2)(a)).</li>
                <li>Carrying out obligations under employment, social security and social protection law (Article 9(2)(b)).</li>
                <li>Protection of vital interests (Article 9(2)(c)).</li>
                <li>Establishment, exercise or defence of legal claims (Article 9(2)(j)).</li>
            </ul>
            <p>A register of processing activities (Article 30) and Data Protection Impact Assessments (Article 35) are maintained by the DPO on request.</p>
        ",
        'How to exercise your rights' => "
            <p>You may submit a Data Subject Access Request (DSAR), rectification, erasure, restriction, data portability, or objection request by writing to the DPO. Please include:</p>
            <ul>
                <li>Full legal name and institutional identifier (student ID, staff number) so we can locate your record.</li>
                <li>A clear description of the right you wish to exercise.</li>
                <li>A copy of government-issued photo ID for verification.</li>
            </ul>
            <p>We acknowledge DSARs within 5 calendar days and respond substantively within one month, extendable by a further two months for complex or high-volume requests (GDPR Article 12(3)). DSARs are provided free of charge for the first request in any 12-month period.</p>
        ",
        'EU Representative &amp; Supervisory Authority' => "
            <p>If processing of your personal data is subject to GDPR and you require the contact details of our EU Representative and lead Supervisory Authority, please request this information from the DPO and it will be provided without undue delay.</p>
            <p>Nothing on this page limits or excludes rights that are available to you under applicable law of your jurisdiction.</p>
            <p class=\"ulms-last-updated\"><em>Last updated: {$lastupdated} &mdash; Version 1.0</em></p>
        ",
    ],
    'cookie-policy' => [
        'About cookies' => "
            <p>A cookie is a small text file placed on your device (computer, tablet or mobile) by a website you visit. Cookies are widely used to make websites work more efficiently, provide useful functionality, remember preferences and, with consent, analyse usage or support marketing activities.</p>
            <p>The University Learning Management System uses cookies in line with this Cookie Policy and the Electronic Communications Privacy Directive (EU) 2002/58/EC as amended by Regulation (EU) 2016/679, the UK Privacy and Electronic Communications Regulations 2003, and the Nigeria Data Protection Regulation 2019.</p>
        ",
        'Cookie categories (3-category consent model)' => "
            <p>We offer a granular consent model with three distinct categories. You may change your preferences at any time using the Cookie Preference Centre link in the footer. Category preferences are stored in your browser for a maximum of 180 days.</p>
            <table class=\"ulms-legal-table\">
                <caption style=\"position:absolute;left:-10000px;top:auto;width:1px;height:1px;overflow:hidden;\">Cookie categories — three-category consent model</caption>
                <thead><tr><th scope=\"col\">Category</th><th scope=\"col\">Always active</th><th scope=\"col\">Description</th></tr></thead>
                <tbody>
                    <tr>
                        <td><strong>Strictly Necessary</strong></td>
                        <td>Yes — cannot be declined</td>
                        <td>Required for core platform operation: authentication sessions, CSRF protection, load-balancing affinity, security rate limiting, role-based access controls and remember-me tokens. These cookies are set without consent because the Service cannot function without them.</td>
                    </tr>
                    <tr>
                        <td><strong>Analytics</strong></td>
                        <td>No — requires opt-in</td>
                        <td>Enable us to understand how the Service is used in aggregate so we can improve usability, performance and learning outcomes. Analytics cookies are set only when you explicitly accept this category.</td>
                    </tr>
                    <tr>
                        <td><strong>Marketing</strong></td>
                        <td>No — requires opt-in</td>
                        <td>Used by approved institutional partners to present relevant educational programmes, events and resources. Marketing cookies are set only when you explicitly accept this category. We do not set marketing cookies on students under the age of 16.</td>
                    </tr>
                </tbody>
            </table>
            <p><strong>Reject-all / strict-only behaviour:</strong> If you click \"Reject all non-essential cookies\", only Strictly Necessary cookies are set. The banner will remember this preference and will not re-prompt for 180 days, or until you re-open the preference centre.</p>
        ",
        'Cookies we set' => "
            <p>The following cookies may be used on the Service:</p>
            <table class=\"ulms-legal-table\">
                <caption style=\"position:absolute;left:-10000px;top:auto;width:1px;height:1px;overflow:hidden;\">Cookie inventory — names, providers, categories, purposes and retention</caption>
                <thead><tr><th scope=\"col\">Cookie name</th><th scope=\"col\">Provider</th><th scope=\"col\">Category</th><th scope=\"col\">Purpose</th><th scope=\"col\">Retention</th></tr></thead>
                <tbody>
                    <tr><td>MoodleSession</td><td>{$escinstname}</td><td>Strictly Necessary</td><td>Maintains authenticated session state across page requests.</td><td>Session (closed on exit)</td></tr>
                    <tr><td>MOODLEID_*</td><td>{$escinstname}</td><td>Strictly Necessary</td><td>Remember-me functionality when explicitly enabled on the login form.</td><td>60 days</td></tr>
                    <tr><td>ulms_csrf_*</td><td>{$escinstname}</td><td>Strictly Necessary</td><td>Anti-CSRF tokens to protect against cross-site request forgery on forms and actions.</td><td>Session</td></tr>
                    <tr><td>ulms_cookie_consent</td><td>{$escinstname}</td><td>Strictly Necessary</td><td>Stores your cookie-category preference selections.</td><td>180 days</td></tr>
                    <tr><td>ulms_banner_dismissed</td><td>{$escinstname}</td><td>Strictly Necessary</td><td>Prevents dismissal of one-time informational banners from re-appearing on subsequent visits.</td><td>30 days</td></tr>
                    <tr><td>_ga / _ga_*</td><td>Google Analytics (if Analytics accepted)</td><td>Analytics</td><td>Distinguish unique visitors and sessions for usage analytics.</td><td>2 years</td></tr>
                    <tr><td>_gid</td><td>Google Analytics (if Analytics accepted)</td><td>Analytics</td><td>Session-level analytics information.</td><td>24 hours</td></tr>
                    <tr><td>_fbp</td><td>Meta Ads (if Marketing accepted)</td><td>Marketing</td><td>Delivery and conversion measurement of approved institutional advertising campaigns.</td><td>90 days</td></tr>
                </tbody>
            </table>
        ",
        'Managing cookies' => "
            <p>You can control and delete cookies through your browser settings. Instructions are usually available in the browser's \"Help\", \"Tools\" or \"Edit Preferences\" menu. Please note that disabling cookies will reduce functionality (for example, login sessions may not persist between visits).</p>
            <p class=\"ulms-last-updated\"><em>Last updated: {$lastupdated} &mdash; Version 1.0</em></p>
        ",
    ],
    'data-protection' => [
        'Statement' => "
            <p><strong>{$escinstname}</strong> is committed to protecting the rights and freedoms of Data Subjects by ensuring appropriate confidentiality, integrity, availability and resilience of personal data processed through the University Learning Management System. This statement summarises the framework of technical, organisational and governance measures that are in place.</p>
        ",
        'Governance' => "
            <ul>
                <li>A single Data Protection Officer (DPO) has independent oversight of all ULMS data processing activities and reports directly to University Council.</li>
                <li>A Register of Processing Activities is maintained in line with GDPR Article 30 and NDPA Schedule 1.</li>
                <li>Data Protection Impact Assessments (DPIAs) are completed for all new processing or material changes to processing that are likely to result in high risk to rights and freedoms.</li>
                <li>All staff and contractors with access to personal data complete annual data protection and information security awareness training.</li>
            </ul>
        ",
        'Technical &amp; organisational measures' => "
            <ul>
                <li>All traffic is encrypted end-to-end using TLS 1.3 modern cipher suites with HSTS enforcement.</li>
                <li>High-sensitivity personal data fields (date of birth, next-of-kin contact, consent revocation logs) are encrypted at rest using AES-256-GCM with envelope encryption key rotation.</li>
                <li>Access to personal data is granted on least-privilege principles via role-based access controls; every access is subject to mandatory multi-factor authentication for administrative and staff roles.</li>
                <li>Structured, immutable audit logs record every authentication event, every provisioning action, and every access to personal data categories. Audit logs are retained for a minimum of 12 months, and for 7 years where required by FERPA, financial audit or NDPA oversight.</li>
                <li>Quarterly vulnerability scanning, annual penetration testing and regular backup restore drills are performed.</li>
                <li>All subprocessors operate under written Data Processing Agreements incorporating Article 28 GDPR obligations.</li>
            </ul>
        ",
        'Data breach protocol' => "
            <p>We maintain an Incident Response Plan approved by the DPO. Personal data breaches that are likely to result in a risk to the rights and freedoms of natural persons are reported to the competent supervisory authority without undue delay and, where feasible, not later than 72 hours after becoming aware of the breach. Where a breach is likely to result in a high risk, affected Data Subjects are informed without undue delay together with self-protective recommendations.</p>
            <p>All suspected breaches should be reported to the DPO immediately.</p>
        ",
        'Contact' => "
            <p>Enquiries about this Data Protection Statement, including requests for copies of our subprocessor list, DPIA summaries or DPAs should be directed to the DPO at the contact address published in the Privacy Policy.</p>
            <p class=\"ulms-last-updated\"><em>Last updated: {$lastupdated} &mdash; Version 1.0</em></p>
        ",
    ],
];

$content = '';
foreach (($sections[$page] ?? []) as $heading => $html) {
    $hesc = htmlspecialchars((string)$heading, ENT_QUOTES, 'UTF-8');
    $content .= "<section class=\"ulms-legal-section\"><h2>{$hesc}</h2>{$html}</section>\n";
}

if (function_exists('local_ulms_dashboard_emit_x_render_time')) {
    local_ulms_dashboard_emit_x_render_time();
}

header('Content-Type: text/html; charset=utf-8');
echo <<<HTMLEOF
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>{$esctitle} — {$escinstname}</title>
<meta name="description" content="Official {$esctitle} for the {$escinstname} University Learning Management System (ULMS).">
<style>
    *{box-sizing:border-box}
    body{margin:0;font-family:ui-sans-serif,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,"Helvetica Neue",Arial,"Noto Sans",sans-serif;color:#0f172a;background:#f8fafc;line-height:1.6}
    .page{max-width:880px;margin:0 auto;padding:32px 20px 64px}
    .nav{display:flex;align-items:center;justify-content:space-between;gap:16px;margin-bottom:28px}
    .brand{display:flex;align-items:center;gap:12px;text-decoration:none;color:inherit}
    .brand-mark{width:40px;height:40px;border-radius:10px;background:{$brandprimary};color:#fff;display:grid;place-items:center;font-weight:800;letter-spacing:.5px}
    .brand-name{font-weight:700;color:#0f172a}
    .brand-name small{display:block;color:#475569;font-weight:500;font-size:12px;margin-top:2px}
    .back{font-size:14px;color:#475569;text-decoration:none;padding:8px 12px;border:1px solid #e2e8f0;border-radius:8px}
    .back:hover{background:#fff;color:#0f172a;border-color:#cbd5e1}
    .hero{background:linear-gradient(135deg,{$brandprimary} 0%, #08365c 100%);color:#fff;padding:28px 28px 32px;border-radius:16px;margin-bottom:28px;box-shadow:0 10px 30px rgba(15,76,129,.15)}
    .eyebrow{display:inline-block;padding:4px 10px;border-radius:999px;background:rgba(255,255,255,.12);font-size:12px;font-weight:600;letter-spacing:.04em;text-transform:uppercase;margin-bottom:12px}
    .hero h1{margin:0 0 6px;font-size:28px;line-height:1.2}
    .hero p{margin:0;opacity:.92;font-size:15px}
    .card{background:#fff;border:1px solid #e2e8f0;border-radius:14px;padding:24px 26px;box-shadow:0 1px 2px rgba(15,23,42,.04)}
    ul,ol{padding-left:22px}
    li{margin:6px 0}
    blockquote{margin:12px 0;padding:14px 18px;background:#f1f5f9;border-left:4px solid {$brandprimary};border-radius:8px;color:#0f172a;font-size:14px}
    h2{font-size:19px;margin:28px 0 10px;color:#0f172a;padding-bottom:6px;border-bottom:1px solid #e2e8f0}
    .ulms-legal-table{width:100%;border-collapse:collapse;margin:12px 0 20px;font-size:14px}
    .ulms-legal-table th,.ulms-legal-table td{padding:10px 12px;border:1px solid #e2e8f0;text-align:left;vertical-align:top}
    .ulms-legal-table th{background:#f1f5f9;font-weight:600;color:#0f172a}
    .ulms-legal-table tr:nth-child(even) td{background:#f8fafc}
    .ulms-last-updated{margin-top:24px;color:#64748b;font-size:13px}
    .footer{margin-top:36px;padding-top:18px;border-top:1px solid #e2e8f0;color:#64748b;font-size:13px;display:flex;flex-wrap:wrap;gap:14px;justify-content:space-between;align-items:center}
    .footer a{color:#475569;text-decoration:none}
    .footer a:hover{color:{$brandprimary};text-decoration:underline}
    .crumbs{color:#475569;font-size:13px;margin-bottom:16px}
    .crumbs a{color:#475569;text-decoration:none}
    .crumbs a:hover{color:{$brandprimary};text-decoration:underline}
    @media (min-width: 768px){.page{padding:40px 24px 80px}.hero{padding:36px 40px}}
</style>
</head>
<body class="{$bodyclass}">
<div class="page">
    <nav class="nav">
        <a class="brand" href="{$homeurl}">
            <span class="brand-mark">{$escshortname}</span>
            <span class="brand-name">{$escinstname}<small>University Learning Management System</small></span>
        </a>
        <a class="back" href="{$dashboardback}">← Back to portal</a>
    </nav>
    <div class="crumbs">
        <a href="{$homeurl}">Home</a> &nbsp;/&nbsp; <a href="/privacy">Privacy Centre</a> &nbsp;/&nbsp; {$esctitle}
    </div>
    <header class="hero">
        <span class="eyebrow">Privacy Centre · {$escshortname}</span>
        <h1>{$esctitle}</h1>
        <p>Official policy for the {$escinstname} University Learning Management System (ULMS).</p>
    </header>
    <main class="card">
        {$content}
    </main>
    <footer class="footer">
        <span>{$escfooter}</span>
        <span>
            <a href="/privacy">Privacy Policy</a> ·
            <a href="/cookie-policy">Cookie Policy</a> ·
            <a href="/gdpr">GDPR rights</a> ·
            <a href="/data-protection">Data protection</a>
        </span>
    </footer>
</div>
HTMLEOF;

if (!empty($CFG->additionalhtmlfooter)) {
    echo $CFG->additionalhtmlfooter;
}

echo <<<HTMLEOF2
</body>
</html>
HTMLEOF2;
