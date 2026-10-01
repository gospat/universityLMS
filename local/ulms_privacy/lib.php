<?php
defined('MOODLE_INTERNAL') || die();

function local_ulms_privacy_user_deleted(\core\event\user_deleted $event): void {
    try {
        $userid = (int)$event->relateduserid;
        if ($userid <= 0) {
            return;
        }
        $providerclass = '\\local_ulms_privacy\\privacy\\provider';
        if (class_exists($providerclass) && is_callable([$providerclass, 'purge_user_privacy_fields'])) {
            $providerclass::purge_user_privacy_fields($userid);
        } else {
            global $DB;
            if (empty($DB)) {
                return;
            }
            $fieldnames = ['dob', 'nok_name', 'nok_phone', 'nok_relation', 'ferpa_directory_optout', 'sms_marketing_consent'];
            [$insql, $inparams] = $DB->get_in_or_equal($fieldnames, SQL_PARAMS_NAMED, 'privfn');
            $sql = "SELECT f.id FROM {user_info_field} f WHERE f.shortname {$insql}";
            $fieldids = array_values(array_map('intval', $DB->get_fieldset_sql($sql, $inparams)));
            if (!empty($fieldids)) {
                [$finsql, $finparams] = $DB->get_in_or_equal($fieldids, SQL_PARAMS_NAMED, 'privfid');
                $finparams['udeluser'] = $userid;
                $DB->delete_records_select(
                    'user_info_data',
                    "fieldid {$finsql} AND userid = :udeluser",
                    $finparams
                );
            }
        }
    } catch (\Throwable) {
    }
}

function local_ulms_privacy_extend_navigation(?global_navigation $_navigation = null): void {
    global $PAGE;
    try {
        if (PHP_SAPI === 'cli' || WS_SERVER || AJAX_SCRIPT) {
            return;
        }
        $consent = null;
        if (isset($_COOKIE['ulms_cookie_consent'])) {
            $decoded = json_decode(base64_decode((string)$_COOKIE['ulms_cookie_consent'], true), true);
            if (is_array($decoded) && isset($decoded['strict'])) {
                $consent = $decoded;
            }
        }
        $showbanner = $consent === null;
        $hasanalytics = is_array($consent) && !empty($consent['analytics']);
        $hasmarketing = is_array($consent) && !empty($consent['marketing']);
        $institutionname = '';
        if (function_exists('ulms_institution_cascade')) {
            $institutionname = (string)ulms_institution_cascade('NAME', [], 'Your University');
        } else {
            $override = (string)(($_ENV['INSTITUTION_NAME'] ?? getenv('INSTITUTION_NAME')) ?: '');
            $institutionname = $override !== '' ? $override : (get_config('core', 'fullname') ?: 'Your University');
        }
        $escname = json_encode($institutionname, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS);
        $bannerhtml = '';
        if ($showbanner) {
            $bannerhtml = <<<BANNERHTML
<div id="ulms-cookie-banner" role="region" aria-label="Cookie consent" aria-live="polite" style="position:fixed;left:16px;right:16px;bottom:16px;z-index:99999;max-width:1040px;margin:0 auto;background:#ffffff;border:1px solid #e2e8f0;border-radius:14px;box-shadow:0 12px 40px rgba(15,23,42,.18);padding:20px 22px;font-family:ui-sans-serif,system-ui,-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,Helvetica Neue,Arial,Noto Sans,sans-serif;font-size:14px;color:#0f172a">
  <div style="display:flex;gap:18px;align-items:flex-start">
    <div style="flex:1;min-width:0">
      <div style="font-weight:700;font-size:15px;margin-bottom:6px;color:#0f4c81">Cookie &amp; Privacy Preferences</div>
      <div style="color:#334155;line-height:1.55;margin-bottom:10px">
        We use cookies to make the ULMS work reliably (strictly necessary). With your consent we also use analytics cookies to improve learning experience quality and marketing cookies to present relevant opportunities. You may accept or reject each non-essential category.
        <a href="/cookie-policy" style="color:#0f4c81;text-decoration:underline;margin-left:4px">Read full Cookie Policy →</a>
      </div>
      <fieldset style="border:none;padding:0;margin:0 0 14px 0">
        <legend style="padding:0 0 8px 0;font-weight:700;color:#0f172a;font-size:14px;width:auto">Cookie categories — select which non-essential cookies you accept</legend>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:10px">
          <label style="display:flex;gap:10px;align-items:flex-start;padding:10px 12px;border:1px solid #e2e8f0;border-radius:10px;background:#f8fafc;cursor:not-allowed;opacity:.78" aria-disabled="true">
            <input type="checkbox" id="ulms-cc-strict" checked disabled style="margin-top:3px" aria-disabled="true" />
            <div>
              <div style="font-weight:600;color:#0f172a">Strictly Necessary <span style="color:#065f46;font-weight:500;font-size:12px">(Always on)</span></div>
              <div style="color:#475569;font-size:13px">Authentication, CSRF protection, session state, load-balancing, security, and banner dismiss state.</div>
            </div>
          </label>
          <label style="display:flex;gap:10px;align-items:flex-start;padding:10px 12px;border:1px solid #e2e8f0;border-radius:10px;background:#fff;cursor:pointer">
            <input type="checkbox" id="ulms-cc-analytics" style="margin-top:3px" />
            <div>
              <div style="font-weight:600;color:#0f172a">Analytics</div>
              <div style="color:#475569;font-size:13px">Aggregate usage patterns, performance, and UX improvement insights. Opt-in only.</div>
            </div>
          </label>
          <label style="display:flex;gap:10px;align-items:flex-start;padding:10px 12px;border:1px solid #e2e8f0;border-radius:10px;background:#fff;cursor:pointer">
            <input type="checkbox" id="ulms-cc-marketing" style="margin-top:3px" />
            <div>
              <div style="font-weight:600;color:#0f172a">Marketing</div>
              <div style="color:#475569;font-size:13px">Relevant programmes, events and opportunities from approved University partners. Opt-in only.</div>
            </div>
          </label>
        </div>
      </fieldset>
    </div>
  </div>
  <div class="ulms-banner-actions" style="display:flex;gap:10px;flex-wrap:wrap;justify-content:flex-end;margin-top:4px">
    <button id="ulms-cc-reject" type="button" class="ulms-banner-reject-nonessential" style="padding:10px 14px;background:#f1f5f9;color:#0f172a;border:1px solid #cbd5e1;border-radius:8px;font-weight:600;cursor:pointer">Reject non-essential</button>
    <button id="ulms-cc-save" type="button" class="ulms-banner-save-preferences" style="padding:10px 14px;background:#ffffff;color:#0f4c81;border:1px solid #0f4c81;border-radius:8px;font-weight:600;cursor:pointer">Save preferences</button>
    <button id="ulms-cc-acceptall" type="button" class="ulms-banner-accept-all" style="padding:10px 16px;background:#0f4c81;color:#ffffff;border:none;border-radius:8px;font-weight:700;cursor:pointer">Accept all</button>
  </div>
</div>
BANNERHTML;
        }
        $analyticsinject = '';
        if ($hasanalytics) {
            $analyticsinject = "/* ANALYTICS: accepted at preference time. Placeholder for institutional Google Analytics / Matomo script tag. */\n            (function() { try { window.ulmsConsentAnalytics = true; } catch(e) {} })();";
        }
        $marketinginject = '';
        if ($hasmarketing) {
            $marketinginject = "/* MARKETING: accepted at preference time. Placeholder for approved institutional Meta / conversion pixel script tags. */\n            (function() { try { window.ulmsConsentMarketing = true; } catch(e) {} })();";
        }
        $jscode = <<<JSCODE
(function(){
  try {
    var ESC_NAME = {$escname};
    function setConsentCookie(choices) {
      var PL = encodeURIComponent('/');
      try { PL = encodeURIComponent(new URL(document.baseURI || location.href).pathname.replace(/[^\\/]*$/, '')); } catch(e) {}
      var payload = btoa(JSON.stringify({ strict: true, analytics: !!choices.analytics, marketing: !!choices.marketing, ts: Math.floor(Date.now()/1000), v: 1 }));
      var maxAge = 180 * 24 * 60 * 60;
      document.cookie = 'ulms_cookie_consent=' + encodeURIComponent(payload) + '; Max-Age=' + maxAge + '; Path=/; SameSite=Lax; Secure';
    }
    function hideBanner() {
      try {
        var b = document.getElementById('ulms-cookie-banner');
        if (b && b.parentNode) b.parentNode.removeChild(b);
      } catch(e) {}
    }
    {$analyticsinject}
    {$marketinginject}
    document.addEventListener('DOMContentLoaded', function() {
      var banner = document.getElementById('ulms-cookie-banner');
      if (!banner) return;
      document.addEventListener('keydown', function(ev) { if (ev.key === 'Escape' || ev.keyCode === 27) { var still = document.getElementById('ulms-cookie-banner'); if (still && still.parentNode) { setConsentCookie({strictly_necessary:true,analytics:false,marketing:false}); try { window.dispatchEvent(new CustomEvent('ulms-cookie-consent-changed',{detail:{strict:true,analytics:false,marketing:false,source:'escape'}})); } catch(e) {} hideBanner(); location.reload(); } } });
      function readChoices() {
        return {
          analytics: !!document.getElementById('ulms-cc-analytics').checked,
          marketing: !!document.getElementById('ulms-cc-marketing').checked
        };
      }
      document.getElementById('ulms-cc-acceptall').addEventListener('click', function() {
        setConsentCookie({ analytics: true, marketing: true });
        try { window.dispatchEvent(new CustomEvent('ulms-cookie-consent-changed', {detail:{strict:true,analytics:true,marketing:true,source:'accept-all'}})); } catch(e) {}
        hideBanner(); location.reload();
      });
      document.getElementById('ulms-cc-reject').addEventListener('click', function() {
        setConsentCookie({ analytics: false, marketing: false });
        try { window.dispatchEvent(new CustomEvent('ulms-cookie-consent-changed', {detail:{strict:true,analytics:false,marketing:false,source:'reject'}})); } catch(e) {}
        hideBanner(); location.reload();
      });
      document.getElementById('ulms-cc-save').addEventListener('click', function() {
        var ch = readChoices();
        setConsentCookie(ch);
        try { window.dispatchEvent(new CustomEvent('ulms-cookie-consent-changed', {detail:{strict:true,analytics:ch.analytics,marketing:ch.marketing,source:'save'}})); } catch(e) {}
        hideBanner(); location.reload();
      });
    });
  } catch(e) { try { console.warn('ULMS cookie banner init failed:', e); } catch(_) {} }
})();
JSCODE;
        $injected = '';
        if ($bannerhtml !== '') {
            $injected .= $bannerhtml . "\n";
        }
        if ($jscode !== '') {
            $injected .= '<script>' . $jscode . '</script>';
        }
        if ($injected !== '' || true) {
            try {
                if ($PAGE instanceof \moodle_page) {
                    // P0-4: Ungated skip-link target (always set, regardless of banner presence)
                    /** @var object $requires */
                    $requires = $PAGE->requires;
                    $requires->skip_link_target_id = 'maincontent';
                    if (method_exists($requires, 'set_skip_link_id')) {
                        $requires->set_skip_link_id('maincontent');
                    }
                    // P0-4 + P1-12: Inject explicit anchor targets via top-of-body if not already present
                    global $CFG;
                    if (empty($CFG->additionalhtmltopofbody)) {
                        $CFG->additionalhtmltopofbody = '';
                    }
                    $anchoranchors = '<a id="maincontent" aria-hidden="true" tabindex="-1" style="position:absolute;left:-9999px;width:1px;height:1px;overflow:hidden"></a>'
                        . '<a id="region-main" aria-hidden="true" tabindex="-1" style="position:absolute;left:-9999px;width:1px;height:1px;overflow:hidden"></a>'
                        . '<a id="main-content" aria-hidden="true" tabindex="-1" style="position:absolute;left:-9999px;width:1px;height:1px;overflow:hidden"></a>';
                    if (strpos((string)$CFG->additionalhtmltopofbody, 'id="maincontent"') === false) {
                        $CFG->additionalhtmltopofbody = $anchoranchors . $CFG->additionalhtmltopofbody;
                    }
                    // JS init code always
                    $PAGE->requires->js_init_code($jscode, true);
                    // Banner-specific body class (gated to banner html)
                    if ($bannerhtml !== '') {
                        $PAGE->add_body_class('has-ulms-cookie-banner');
                    }
                }
            } catch (\Throwable) {
            }
            global $CFG;
            if (empty($CFG->additionalhtmlfooter)) {
                $CFG->additionalhtmlfooter = '';
            }
            if ($injected !== '') {
                $CFG->additionalhtmlfooter .= "\n" . $injected;
            } else {
                $CFG->additionalhtmlfooter .= "\n" . '<script>' . $jscode . '</script>';
            }
        }
    } catch (\Throwable) {
    }
}
