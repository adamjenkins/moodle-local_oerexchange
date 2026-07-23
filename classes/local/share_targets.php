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

namespace local_oerexchange\local;

/**
 * The share affordance shown on profile pages and resource pages: which
 * destinations the admin has enabled, the URL each one needs, and the markup
 * for the disclosure that holds them.
 *
 * Two rules this class exists to enforce:
 *
 * 1. **No third-party script ever runs on our pages.** Every network target
 *    is a plain link to that network's own share endpoint. We embed no SDKs,
 *    no buttons-as-iframes, and no tracking pixels, so visiting a resource
 *    page tells Facebook/X/LinkedIn nothing about the visitor.
 * 2. **Something always works without JavaScript.** The disclosure always
 *    contains a readonly input holding the share URL, so a user whose browser
 *    blocks the Clipboard API (or has JS off entirely) can still select and
 *    copy the link. This is the direct fix for the original defect, where the
 *    only affordance was a button whose every branch could silently no-op.
 *
 * @package    local_oerexchange
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class share_targets {
    /**
     * Every supported target, in the order they are offered. 'copylink' is
     * deliberately first: it is the only one that always works.
     */
    const ALL = ['copylink', 'nativeshare', 'mastodon', 'facebook', 'x', 'linkedin', 'email', 'sms'];

    /**
     * Targets driven by JavaScript rather than a plain href. Rendered as
     * <button>s and wired up by amd/src/share.js.
     */
    const SCRIPTED = ['copylink', 'nativeshare', 'mastodon'];

    /**
     * Default value for the admin setting: everything on.
     *
     * @return string comma-separated key list, as admin_setting_configmulticheckbox stores it
     */
    public static function default_setting(): string {
        return implode(',', self::ALL);
    }

    /**
     * The targets this site has enabled, in ALL's display order.
     *
     * Unknown keys in the stored config are dropped rather than trusted:
     * the setting is admin-written, but it is also the thing that decides
     * which URLs we build, so it is validated against the whitelist here.
     *
     * @return string[]
     */
    public static function enabled(): array {
        $raw = get_config('local_oerexchange', 'sharetargets');
        if ($raw === false) {
            // Never saved: apply the setting's own default. Note the
            // deliberate strict comparison — an admin who unticks every box
            // stores '', which must stay "share nothing" rather than being
            // read as "unset" and silently turning everything back on.
            $raw = self::default_setting();
        }
        $chosen = array_filter(array_map('trim', explode(',', (string) $raw)), 'strlen');

        return array_values(array_intersect(self::ALL, $chosen));
    }

    /**
     * Build the enabled targets for one shareable thing.
     *
     * @param string $shareurl absolute URL being shared
     * @param string $title human-readable title of the thing being shared
     * @return array<int, array{key: string, label: string, href: ?string}> href is null for scripted targets
     */
    public static function build(string $shareurl, string $title): array {
        $targets = [];
        foreach (self::enabled() as $key) {
            $targets[] = [
                'key' => $key,
                'label' => get_string('sharetarget_' . $key, 'local_oerexchange'),
                'href' => self::href($key, $shareurl, $title),
            ];
        }

        return $targets;
    }

    /**
     * The outbound URL for one target, or null when JavaScript drives it.
     *
     * @param string $key one of self::ALL
     * @param string $shareurl
     * @param string $title
     * @return string|null
     */
    protected static function href(string $key, string $shareurl, string $title): ?string {
        $url = rawurlencode($shareurl);
        $text = rawurlencode($title);

        switch ($key) {
            case 'facebook':
                return 'https://www.facebook.com/sharer/sharer.php?u=' . $url;
            case 'x':
                return 'https://twitter.com/intent/tweet?url=' . $url . '&text=' . $text;
            case 'linkedin':
                return 'https://www.linkedin.com/sharing/share-offsite/?url=' . $url;
            case 'email':
                return 'mailto:?subject=' . $text . '&body=' . rawurlencode($title . "\n\n" . $shareurl);
            case 'sms':
                // Note '?&body=' rather than '?body=' — iOS historically wants
                // the '&' separator here and Android tolerates it, so this
                // form is the one that works on both.
                return 'sms:?&body=' . rawurlencode($title . ' ' . $shareurl);
            default:
                // Targets copylink, nativeshare and mastodon have no fixed
                // URL: Mastodon needs the user's own instance, and the other
                // two are browser APIs. amd/src/share.js handles all three.
                return null;
        }
    }

    /**
     * Render the share disclosure.
     *
     * Returns an empty string when the admin has disabled every target, so
     * callers can simply concatenate the result.
     *
     * @param string $shareurl absolute URL being shared
     * @param string $title human-readable title of the thing being shared
     * @param string $summarylabel the disclosure's own label, e.g. "Share this resource"
     * @return string HTML
     */
    public static function render(string $shareurl, string $title, string $summarylabel): string {
        global $PAGE;

        $targets = self::build($shareurl, $title);
        if (empty($targets)) {
            return '';
        }

        $PAGE->requires->js_call_amd('local_oerexchange/share', 'init');

        $inputid = \html_writer::random_id('oerexchange-share-url-');

        $out = \html_writer::start_tag('details', [
            'class' => 'oerexchange-share d-inline-block me-2',
            'data-region' => 'oerexchange-share',
            'data-share-url' => $shareurl,
            'data-share-title' => $title,
        ]);
        $out .= \html_writer::tag('summary', $summarylabel, ['class' => 'btn btn-outline-secondary']);
        $out .= \html_writer::start_tag('div', ['class' => 'mt-2 p-2 border rounded']);

        // The always-works fallback: the URL, selectable, whatever the
        // browser allows. Labelled rather than placeholder-only so screen
        // readers announce what the field holds.
        $out .= \html_writer::tag('label', get_string('sharelinklabel', 'local_oerexchange'), [
            'for' => $inputid,
            'class' => 'form-label small mb-1',
        ]);
        $out .= \html_writer::empty_tag('input', [
            'type' => 'text',
            'id' => $inputid,
            'class' => 'form-control form-control-sm mb-2',
            'value' => $shareurl,
            'readonly' => 'readonly',
            'data-region' => 'oerexchange-share-url',
        ]);

        $out .= \html_writer::start_tag('div', ['class' => 'd-flex flex-wrap gap-2']);
        foreach ($targets as $target) {
            if ($target['href'] === null) {
                $attrs = [
                    'type' => 'button',
                    'class' => 'btn btn-outline-secondary btn-sm',
                    'data-action' => 'oerexchange-share-' . $target['key'],
                ];
                if ($target['key'] === 'nativeshare') {
                    // Only meaningful where navigator.share exists, which is
                    // mostly mobile — share.js reveals it when supported.
                    $attrs['hidden'] = 'hidden';
                }
                $out .= \html_writer::tag('button', $target['label'], $attrs);
            } else {
                $out .= \html_writer::link($target['href'], $target['label'], [
                    'class' => 'btn btn-outline-secondary btn-sm',
                    'target' => '_blank',
                    // Using noopener/noreferrer: never hand the target site a
                    // window handle back to us, and don't leak the referring
                    // catalogue URL.
                    'rel' => 'noopener noreferrer',
                ]);
            }
        }
        $out .= \html_writer::end_tag('div');
        $out .= \html_writer::end_tag('div');
        $out .= \html_writer::end_tag('details');

        return $out;
    }
}
