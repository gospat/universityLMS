<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace local_ulms_auth\local\service;

defined('MOODLE_INTERNAL') || die();

/**
 * Shared presentation layer for ULMS transactional emails.
 *
 * This service owns the branded HTML "chrome" (header, footer, layout,
 * escaping) so every ULMS-generated email looks identical and stays
 * maintainable in one place. Callers keep ownership of the subject line and
 * of the plain-text body, because those are already translated language
 * strings owned by the workflow that sends the mail.
 *
 * Design constraints:
 *  - Table-based layout with inline CSS, because Outlook and several mobile
 *    clients strip `<style>` blocks and do not support flex or grid.
 *  - The container is fixed at 600px and collapses to the viewport width below
 *    that, which is the widest layout that renders correctly on a 320px phone.
 *  - Light mode is carried entirely by inline styles. Dark mode is a
 *    progressive enhancement in `<style>` only, so a client that strips the
 *    block still gets a readable, correctly contrasted message.
 *  - Every interpolated value is escaped with {@see s()}; nothing is ever
 *    concatenated raw into the markup.
 *  - The primary action button and the visible fallback link are rendered from
 *    the SAME url value, so they can never diverge.
 *
 * No authentication secret is ever accepted or rendered by this class.
 */
class email_template_service {
    /** @var string Brand primary: university blue used for the header and button. */
    private const BRAND_PRIMARY = '#0f3d67';

    /** @var string Brand accent: lighter blue reserved for links. */
    private const BRAND_ACCENT = '#0f4c81';

    /** @var string Body text colour. */
    private const BRAND_INK = '#12263a';

    /** @var string Muted text colour for secondary content. */
    private const BRAND_MUTED = '#55677a';

    /** @var string Page background visible around the card. */
    private const BRAND_PAGE = '#eef2f6';

    /** @var string Card background. */
    private const BRAND_CARD = '#ffffff';

    /** @var string Footer panel background. */
    private const BRAND_FOOTER = '#f5f8fb';

    /** @var string Hairline border colour. */
    private const BRAND_BORDER = '#dfe6ee';

    /** @var int Rendered width of the email container, in pixels. */
    private const CONTAINER_WIDTH = 600;

    /** @var string Shared font stack, chosen for cross-client availability. */
    private const FONT_STACK = "'Segoe UI',Roboto,'Helvetica Neue',Arial,sans-serif";

    /**
     * Renders the branded HTML body of a transactional email.
     *
     * Recognised keys in $content:
     *  - string brandname : university or site name shown in the header/footer.
     *  - string logourl   : absolute URL of the hosted logo, or '' for a text brand.
     *  - string siteurl   : absolute URL of the LMS, used for the footer link.
     *  - string preheader : hidden inbox preview line.
     *  - string heading   : main heading of the message.
     *  - string intro     : introductory paragraph (plain text; newlines honoured).
     *  - array  details   : list of ['label' => string, 'value' => string,
     *                       'emphasis' => bool] rendered as a definition table.
     *  - array  cta       : ['label' => string, 'url' => string] primary action.
     *  - string fallback  : label introducing the copy-and-paste fallback link.
     *  - string notice    : security or policy note shown after the button.
     *  - string support   : support contact line shown in the footer.
     *  - string footer    : closing line shown under the support contact.
     *
     * @param array $content Content structure described above.
     * @return string Complete HTML document, ready to pass to a mail transport.
     */
    public function render_html(array $content): string {
        $content = $this->normalise($content);

        $html = $this->render_document_start($content);
        $html .= $this->render_header($content);
        $html .= $this->render_body($content);
        $html .= $this->render_footer($content);
        $html .= $this->render_document_end();

        return $html;
    }

    /**
     * Fills in defaults so callers only pass what is relevant to their email.
     *
     * @param array $content Raw caller content.
     * @return array Normalised content.
     */
    private function normalise(array $content): array {
        $content += [
            'brandname' => '',
            'logourl' => '',
            'siteurl' => '',
            'preheader' => '',
            'heading' => '',
            'intro' => '',
            'details' => [],
            'cta' => [],
            'fallback' => '',
            'notice' => '',
            'support' => '',
            'footer' => '',
        ];

        $content['cta'] = $content['cta'] + ['label' => '', 'url' => ''];

        $details = [];
        foreach ((array)$content['details'] as $detail) {
            if (empty($detail['label']) || !isset($detail['value']) || $detail['value'] === '') {
                continue;
            }
            $details[] = [
                'label' => (string)$detail['label'],
                'value' => (string)$detail['value'],
                'emphasis' => !empty($detail['emphasis']),
            ];
        }
        $content['details'] = $details;

        return $content;
    }

    /**
     * Returns the document head, client resets and responsive/dark-mode rules.
     *
     * @param array $content Normalised content.
     * @return string
     */
    private function render_document_start(array $content): string {
        $title = s($content['heading'] !== '' ? $content['heading'] : $content['brandname']);

        return '<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">' . "\n"
            . '<html xmlns="http://www.w3.org/1999/xhtml" lang="en" xml:lang="en">' . "\n"
            . '<head>' . "\n"
            . '<meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />' . "\n"
            . '<meta name="viewport" content="width=device-width, initial-scale=1" />' . "\n"
            . '<meta name="x-apple-disable-message-reformatting" />' . "\n"
            . '<meta name="format-detection" content="telephone=no,address=no,email=no,date=no" />' . "\n"
            . '<meta name="color-scheme" content="light dark" />' . "\n"
            . '<meta name="supported-color-schemes" content="light dark" />' . "\n"
            . '<title>' . $title . '</title>' . "\n"
            . '<style type="text/css">' . "\n"
            . 'body,table,td,a{-webkit-text-size-adjust:100%;-ms-text-size-adjust:100%;}' . "\n"
            . 'table,td{mso-table-lspace:0pt;mso-table-rspace:0pt;}' . "\n"
            . 'img{-ms-interpolation-mode:bicubic;border:0;height:auto;line-height:100%;outline:none;text-decoration:none;}' . "\n"
            . 'body{margin:0;padding:0;width:100%!important;}' . "\n"
            . 'a{word-break:break-word;}' . "\n"
            . '@media only screen and (max-width:' . self::CONTAINER_WIDTH . 'px){' . "\n"
            . '  .ulms-email-container{width:100%!important;max-width:100%!important;border-radius:0!important;}' . "\n"
            . '  .ulms-email-pad{padding-left:20px!important;padding-right:20px!important;}' . "\n"
            . '  .ulms-email-heading{font-size:22px!important;line-height:28px!important;}' . "\n"
            . '  .ulms-email-button{display:block!important;width:100%!important;padding-left:0!important;padding-right:0!important;}' . "\n"
            . '}' . "\n"
            . '@media (prefers-color-scheme:dark){' . "\n"
            . '  .ulms-email-page{background-color:#0b1a2a!important;}' . "\n"
            . '  .ulms-email-card{background-color:#12263a!important;}' . "\n"
            . '  .ulms-email-footer{background-color:#0f2133!important;border-top-color:#24384d!important;}' . "\n"
            . '  .ulms-email-heading,.ulms-email-label{color:#f2f6fa!important;}' . "\n"
            . '  .ulms-email-text{color:#cdd9e5!important;}' . "\n"
            . '  .ulms-email-muted{color:#9fb2c4!important;}' . "\n"
            . '  .ulms-email-row{border-bottom-color:#24384d!important;}' . "\n"
            . '  .ulms-email-link{color:#8fc4f5!important;}' . "\n"
            . '}' . "\n"
            . '</style>' . "\n"
            . '</head>' . "\n"
            . '<body class="ulms-email-page" style="margin:0;padding:0;background-color:' . self::BRAND_PAGE . ';">' . "\n";
    }

    /**
     * Returns the hidden preheader plus the page and card wrappers.
     *
     * @param array $content Normalised content.
     * @return string
     */
    private function render_header(array $content): string {
        $preheader = '';
        if ($content['preheader'] !== '') {
            $preheader = '<div style="display:none;font-size:1px;color:' . self::BRAND_PAGE
                . ';line-height:1px;max-height:0;max-width:0;opacity:0;overflow:hidden;">'
                . s($content['preheader']) . '</div>' . "\n";
        }

        $html = $preheader;
        $html .= '<table role="presentation" class="ulms-email-page" width="100%" cellpadding="0" cellspacing="0" border="0" '
            . 'style="background-color:' . self::BRAND_PAGE . ';">' . "\n"
            . '<tr><td align="center" style="padding:24px 12px;">' . "\n"
            . '<table role="presentation" class="ulms-email-container" width="' . self::CONTAINER_WIDTH
            . '" cellpadding="0" cellspacing="0" border="0" style="width:' . self::CONTAINER_WIDTH
            . 'px;max-width:' . self::CONTAINER_WIDTH . 'px;background-color:' . self::BRAND_CARD
            . ';border-radius:12px;overflow:hidden;">' . "\n";

        // Brand bar.
        $logocell = '';
        if ($content['logourl'] !== '') {
            $logocell = '<td width="48" valign="middle" style="padding:0 14px 0 0;">'
                . '<img src="' . s($content['logourl']) . '" width="48" height="48" alt="" '
                . 'style="display:block;width:48px;height:48px;border:0;" /></td>';
        }

        $html .= '<tr><td class="ulms-email-pad" bgcolor="' . self::BRAND_PRIMARY . '" '
            . 'style="background-color:' . self::BRAND_PRIMARY . ';padding:20px 28px;">' . "\n"
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>'
            . $logocell
            . '<td valign="middle" style="font-family:' . self::FONT_STACK . ';font-size:16px;font-weight:700;'
            . 'line-height:22px;color:#ffffff;letter-spacing:0.02em;">' . s($content['brandname']) . '</td>'
            . '</tr></table>' . "\n"
            . '</td></tr>' . "\n";

        return $html;
    }

    /**
     * Returns the message body: heading, intro, details, action and notices.
     *
     * @param array $content Normalised content.
     * @return string
     */
    private function render_body(array $content): string {
        $html = '<tr><td class="ulms-email-pad" style="padding:28px;">' . "\n";

        if ($content['heading'] !== '') {
            $html .= '<h1 class="ulms-email-heading" style="margin:0 0 16px;font-family:' . self::FONT_STACK
                . ';font-size:24px;line-height:30px;font-weight:700;color:' . self::BRAND_INK . ';">'
                . s($content['heading']) . '</h1>' . "\n";
        }

        if ($content['intro'] !== '') {
            $html .= '<p class="ulms-email-text" style="margin:0 0 20px;font-family:' . self::FONT_STACK
                . ';font-size:16px;line-height:24px;color:' . self::BRAND_INK . ';">'
                . $this->render_paragraph($content['intro']) . '</p>' . "\n";
        }

        $html .= $this->render_details($content['details']);
        $html .= $this->render_action($content);
        $html .= $this->render_notice($content['notice']);

        $html .= '</td></tr>' . "\n";

        return $html;
    }

    /**
     * Returns the detail rows as a two-column definition table.
     *
     * @param array $details Normalised detail rows.
     * @return string
     */
    private function render_details(array $details): string {
        if ($details === []) {
            return '';
        }

        $html = '<table role="presentation" class="ulms-email-card" width="100%" cellpadding="0" cellspacing="0" border="0" '
            . 'style="width:100%;border-collapse:collapse;margin:0 0 24px;">' . "\n";

        $lastindex = count($details) - 1;
        foreach ($details as $index => $detail) {
            $border = $index === $lastindex ? 'none' : '1px solid ' . self::BRAND_BORDER;
            $html .= '<tr class="ulms-email-row" style="border-bottom:' . $border . ';">' . "\n"
                . '<td class="ulms-email-label" width="42%" valign="top" style="padding:12px 12px 12px 0;font-family:'
                . self::FONT_STACK . ';font-size:13px;line-height:20px;font-weight:700;color:' . self::BRAND_MUTED
                . ';text-transform:uppercase;letter-spacing:0.04em;">' . s($detail['label']) . '</td>' . "\n"
                . '<td class="ulms-email-text" valign="top" style="padding:12px 0;font-family:' . self::FONT_STACK
                . ';font-size:16px;line-height:22px;color:' . self::BRAND_INK
                . ($detail['emphasis'] ? ';font-weight:700;' : ';') . '">'
                . $this->render_value($detail['value']) . '</td>' . "\n"
                . '</tr>' . "\n";
        }

        $html .= '</table>' . "\n";

        return $html;
    }

    /**
     * Returns the primary action button and its copy-and-paste fallback.
     *
     * The fallback is derived from the button url, so both always point at the
     * same destination.
     *
     * @param array $content Normalised content.
     * @return string
     */
    private function render_action(array $content): string {
        $url = (string)$content['cta']['url'];
        if ($url === '') {
            return '';
        }

        $html = '<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 20px;">' . "\n"
            . '<tr><td align="center" bgcolor="' . self::BRAND_PRIMARY . '" style="border-radius:8px;">' . "\n"
            . '<a class="ulms-email-button" href="' . s($url) . '" style="display:inline-block;padding:15px 32px;'
            . 'font-family:' . self::FONT_STACK . ';font-size:16px;line-height:20px;font-weight:700;color:#ffffff;'
            . 'text-decoration:none;border-radius:8px;">' . s($content['cta']['label']) . '</a>' . "\n"
            . '</td></tr></table>' . "\n";

        if ($content['fallback'] !== '') {
            $html .= '<p class="ulms-email-muted" style="margin:0 0 6px;font-family:' . self::FONT_STACK
                . ';font-size:13px;line-height:20px;color:' . self::BRAND_MUTED . ';">'
                . s($content['fallback']) . '</p>' . "\n"
                . '<p style="margin:0 0 20px;font-family:' . self::FONT_STACK . ';font-size:13px;line-height:20px;'
                . 'word-break:break-all;overflow-wrap:anywhere;">'
                . '<a class="ulms-email-link" href="' . s($url) . '" style="color:' . self::BRAND_ACCENT
                . ';text-decoration:underline;word-break:break-all;">' . s($url) . '</a></p>' . "\n";
        }

        return $html;
    }

    /**
     * Returns a highlighted security or policy notice.
     *
     * @param string $notice Notice text.
     * @return string
     */
    private function render_notice(string $notice): string {
        if ($notice === '') {
            return '';
        }

        return '<table role="presentation" class="ulms-email-card" width="100%" cellpadding="0" cellspacing="0" border="0" '
            . 'style="width:100%;background-color:' . self::BRAND_FOOTER . ';border-radius:8px;">' . "\n"
            . '<tr><td style="padding:14px 16px;font-family:' . self::FONT_STACK . ';font-size:14px;line-height:21px;'
            . 'color:' . self::BRAND_INK . ';">' . $this->render_paragraph($notice) . '</td></tr>' . "\n"
            . '</table>' . "\n";
    }

    /**
     * Returns the footer with the support contact and closing line.
     *
     * @param array $content Normalised content.
     * @return string
     */
    private function render_footer(array $content): string {
        $html = '<tr><td class="ulms-email-footer ulms-email-pad" bgcolor="' . self::BRAND_FOOTER . '" '
            . 'style="background-color:' . self::BRAND_FOOTER . ';border-top:1px solid ' . self::BRAND_BORDER
            . ';padding:20px 28px;">' . "\n";

        if ($content['support'] !== '') {
            $html .= '<p class="ulms-email-text" style="margin:0 0 10px;font-family:' . self::FONT_STACK
                . ';font-size:14px;line-height:21px;color:' . self::BRAND_INK . ';">'
                . s($content['support']) . '</p>' . "\n";
        }

        if ($content['footer'] !== '') {
            $html .= '<p class="ulms-email-muted" style="margin:0;font-family:' . self::FONT_STACK
                . ';font-size:13px;line-height:20px;color:' . self::BRAND_MUTED . ';">'
                . s($content['footer']) . '</p>' . "\n";
        }

        if ($content['siteurl'] !== '') {
            $html .= '<p style="margin:12px 0 0;font-family:' . self::FONT_STACK . ';font-size:13px;line-height:20px;">'
                . '<a class="ulms-email-link" href="' . s($content['siteurl']) . '" style="color:' . self::BRAND_ACCENT
                . ';text-decoration:underline;">' . s($content['brandname']) . '</a></p>' . "\n";
        }

        $html .= '</td></tr>' . "\n";

        return $html;
    }

    /**
     * Closes the card, page table and document.
     *
     * @return string
     */
    private function render_document_end(): string {
        return '</table>' . "\n"
            . '</td></tr>' . "\n"
            . '</table>' . "\n"
            . '</body>' . "\n"
            . '</html>' . "\n";
    }

    /**
     * Escapes a body value and preserves intentional line breaks.
     *
     * @param string $value Raw value.
     * @return string
     */
    private function render_paragraph(string $value): string {
        return nl2br(s($value), false);
    }

    /**
     * Escapes a detail value, converting it to a mailto link when it is an address.
     *
     * @param string $value Raw value.
     * @return string
     */
    private function render_value(string $value): string {
        if (validate_email($value)) {
            return '<a class="ulms-email-link" href="mailto:' . s($value) . '" style="color:' . self::BRAND_ACCENT
                . ';text-decoration:underline;">' . s($value) . '</a>';
        }

        return s($value);
    }
}
