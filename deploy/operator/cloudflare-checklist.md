# Cloudflare Operator Checklist (run BEFORE enabling the site)
#
# All of these settings are verified in the Cloudflare Dashboard:
#   1. Log in → learn.bellsuniversity.edu.ng zone → SSL/TLS → Overview
#   2. Log in → SSL/TLS → Edge Certificates
#   3. Log in → Rules → Page Rules (or Rules → Transform Rules)
#   4. Log in → Network
#   5. Log in → Security → WAF → Tools → IP Access Rules
#   6. Log in → DNS
#
# Run every check.  Mark DONE next to each after confirmation.  Save a
# screenshot of each section for audit trail if possible.

## SECTION A — SSL / TLS (THE #1 REASON FOR REDIRECT LOOPS)
# ❗ Cloudflare SSL/TLS mode MUST BE "Full (strict)".
#    - Flexible = BAD.  Flexible terminates TLS at Cloudflare and sends
#      plain HTTP to the origin.  Nginx then redirects HTTP→HTTPS,
#      Cloudflare receives HTTPS, sends HTTP again → 301 loop.  NEVER use
#      Flexible for a site that does its own TLS termination or sits
#      behind an Nginx that wants HTTPS.
#    - Full = OK (uses origin cert, self-signed allowed).
#    - Full (strict) = PREFERRED.  Install a Cloudflare Origin CA cert on
#      the Droplet at:
#        /etc/ssl/private/learn.bellsuniversity.edu.ng-origin-ca.pem
#        /etc/ssl/private/learn.bellsuniversity.edu.ng-origin-ca.key
#      and update the ssl_certificate / ssl_certificate_key lines in
#      /etc/nginx/sites-available/learn.bellsuniversity.edu.ng.conf.
#      After install: sudo nginx -t && sudo systemctl reload nginx
# DONE: [ ]  SSL/TLS mode = Full (strict) confirmed
# DONE: [ ]  Minimum TLS version = TLS 1.2 (SSL/TLS → Edge Certificates)
# DONE: [ ]  Opportunistic Encryption = Off (not required, breaks old caches)
# DONE: [ ]  Automatic HTTPS Rewrites = On
# DONE: [ ]  Always Use HTTPS = On (adds belt-and-braces redirect in front of the Nginx redirect)
# DONE: [ ]  HSTS → OFF for first month of production (max-age already set to
#              "0" in the Nginx origin header).  Enable HSTS with max-age
#              >= 15552000 only after 1 month of zero certificate issues.
#              Premature HSTS bricks the site for 6 months if origin cert
#              renewal or Cloudflare mode change fails.

## SECTION B — DNS
# DONE: [ ]  A-record `learn.bellsuniversity.edu.ng → 165.232.37.213` exists,
#              Proxy status = "Proxied" (orange cloud).  Grey-cloud = DNS-only,
#              exposes the Droplet IP directly and skips WAF/TLS termination.
# DONE: [ ]  No AAAA record for `learn.bellsuniversity.edu.ng` unless you have
#              explicitly configured IPv6 on the Droplet + Nginx listen [::]:
#              (both are already present in the reference Nginx config — but
#              if IPv6 networking is not enabled DO NOT create the AAAA).

## SECTION C — WAF / Trusted Origin
# DONE: [ ]  DigitalOcean Managed MySQL → Settings → Trusted sources:
#              Public IPv4 of Droplet (165.232.37.213) added.  Without this,
#              port 25060 is rejected.
# DONE: [ ]  Cloudflare WAF → IP Access Rules: allow your ops workstation
#              public IPv4 / IPv6 as "Allow" (so you don't get locked out
#              by WAF rate limits during deploy).
# DONE: [ ]  Cloudflare WAF → Tools → Security Level = Medium for production.

## SECTION D — CACHE / PERFORMANCE
# DONE: [ ]  Caching → Configuration:
#              Cache Level = Standard (aggressive breaks Moodle session cache)
#              Browser Cache TTL = 4 hours (Moodle itself uses far-future for
#              static assets via the location ~* .(css|js|woff) block).
# DONE: [ ]  Caching → Tiered Cache = On (Argo if you have it, otherwise the
#              free tiered cache will speed static files for students).
# DONE: [ ]  Rules → Cache Rules → create a bypass rule for paths:
#              - `*learn.bellsuniversity.edu.ng/login/*`
#              - `*learn.bellsuniversity.edu.ng/my/*`
#              - `*learn.bellsuniversity.edu.ng/course/modedit.php*`
#              - `*learn.bellsuniversity.edu.ng/local/ulms_dashboard/*`
#              - `*learn.bellsuniversity.edu.ng/local/ulms_exam/*`
#              Action: Bypass cache.  Moodle is HEAVILY stateful — caching
#              its dynamic output = CSRF token mismatches, broken login.

## SECTION E — NETWORK / ORIGIN
# DONE: [ ]  Network → WebSockets = On (Moodle chats, H5P, real-time
#              components use them).
# DONE: [ ]  Network → Onion Routing = Optional (On = safer for users on Tor,
#              but enable only after validating H5P/SCORM uploads work).

## SECTION F — TRANSFORM RULES (real client IP)
# DONE: [ ]  Rules → Transform Rules → Managed Transforms:
#              Add visitor location headers = On (optional).
#              Restore original visitor IP = On (CF-Connecting-IP always set).
#              The Nginx reference site block already restores REMOTE_ADDR
#              from CF-Connecting-IP for every request via set_real_ip_from.
# DONE: [ ]  No Transform Rule overrides X-Forwarded-Proto.  Cloudflare sets
#              X-Forwarded-Proto: https automatically when it terminates TLS,
#              and the Nginx reference block forces fastcgi_param HTTPS=on, so
#              Moodle SSLPROXY + config.php detection both work correctly.

## POST-CHECK
# After applying all of the above, run these from a machine OUTSIDE of the
# Cloudflare/DO network (e.g. your home laptop, not the Droplet SSH shell):
#
#   1.  curl -v http://learn.bellsuniversity.edu.ng/
#         → expect HTTP 301 Location: https://learn.bellsuniversity.edu.ng/
#   2.  curl -v https://learn.bellsuniversity.edu.ng/ -o /dev/null
#         → expect TLS 1.3 (or 1.2), HTTP 200 or 303 (login redirect)
#   3.  curl -I https://learn.bellsuniversity.edu.ng/.env
#         → expect HTTP 403 (Nginx deny-all regex, NOT 200, NOT 404)
#   4.  curl -s https://www.cloudflare.com/cdn-cgi/trace | head -5
#         → check "h=..." matches your zone, "colo=..." non-empty
#
# If all of the above pass, you are ready for the DB state probe and
# install/upgrade decision in §4.2 of ULMS_DEPLOYMENT_SYNC.md.
