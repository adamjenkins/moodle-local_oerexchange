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

/**
 * Catalogue browse/search page. Anonymous browsing is allowed by design.
 *
 * @package    local_oerexchange
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php'); // phpcs:ignore moodle.Files.RequireLogin.Missing -- see docblock above.

$query = optional_param('q', '', PARAM_TEXT);
$type = optional_param('type', '', PARAM_ALPHA);
$license = optional_param('license', '', PARAM_TEXT);
$language = optional_param('language', '', PARAM_TEXT);
$page = optional_param('page', 0, PARAM_INT);
$perpage = 20;

$PAGE->set_url('/local/oerexchange/index.php');
$PAGE->set_context(context_system::instance());
$PAGE->set_pagelayout('standard');
$PAGE->set_title(get_string('catalogtitle', 'local_oerexchange'));
$PAGE->set_heading(get_string('catalogtitle', 'local_oerexchange'));

$where = ['status = :status'];
$sqlparams = ['status' => 'published'];

if ($type !== '') {
    $where[] = 'type = :type';
    $sqlparams['type'] = $type;
}
if ($license !== '') {
    $where[] = 'licenseshortname = :license';
    $sqlparams['license'] = $license;
}
if ($language !== '') {
    $where[] = 'language = :language';
    $sqlparams['language'] = $language;
}
if ($query !== '') {
    $like = $DB->sql_like('title', ':q1', false) . ' OR ' . $DB->sql_like('summary', ':q2', false)
        . ' OR ' . $DB->sql_like('tags', ':q3', false);
    $where[] = "({$like})";
    $needle = '%' . $DB->sql_like_escape($query) . '%';
    $sqlparams['q1'] = $needle;
    $sqlparams['q2'] = $needle;
    $sqlparams['q3'] = $needle;
}

$wheresql = implode(' AND ', $where);
$total = $DB->count_records_select('local_oerexchange_resources', $wheresql, $sqlparams);
$resources = $DB->get_records_select(
    'local_oerexchange_resources',
    $wheresql,
    $sqlparams,
    'timeshared DESC',
    '*',
    $page * $perpage,
    $perpage
);

// Populate the license/language filters from what's actually in the
// catalogue, rather than a hardcoded list — these strings existed
// (filterlicense, filterlanguage) but the form never used them, so the
// only way to filter by license/language was to know the exact query
// string params (found live, 2026-07-19, while documenting the browse
// page for the walkthrough).
$distinctlicenses = $DB->get_fieldset_select(
    'local_oerexchange_resources',
    'DISTINCT licenseshortname',
    "status = :status AND licenseshortname <> ''",
    ['status' => 'published']
);
sort($distinctlicenses);
$distinctlanguages = $DB->get_fieldset_select(
    'local_oerexchange_resources',
    'DISTINCT language',
    "status = :status AND language <> ''",
    ['status' => 'published']
);
sort($distinctlanguages);

echo $OUTPUT->header();

echo html_writer::start_tag('form', [
    'method' => 'get',
    'action' => new moodle_url('/local/oerexchange/index.php'),
    'class' => 'oerexchange-searchform mb-3',
]);
echo html_writer::empty_tag('input', [
    'type' => 'text', 'name' => 'q', 'value' => $query,
    'placeholder' => get_string('searchplaceholder', 'local_oerexchange'), 'class' => 'form-control d-inline w-auto',
]);
echo html_writer::tag(
    'label',
    get_string('filterbytype', 'local_oerexchange'),
    ['for' => 'oerexchange-filter-type', 'class' => 'ms-2 me-1']
);
echo html_writer::select(
    [
        '' => '',
        'course' => get_string('typecourse', 'local_oerexchange'),
        'activity' => get_string('typeactivity', 'local_oerexchange'),
        'data' => get_string('typedata', 'local_oerexchange'),
    ],
    'type',
    $type,
    false,
    ['id' => 'oerexchange-filter-type', 'class' => 'form-select d-inline w-auto']
);
echo html_writer::tag(
    'label',
    get_string('filterlicense', 'local_oerexchange'),
    ['for' => 'oerexchange-filter-license', 'class' => 'ms-2 me-1']
);
echo html_writer::select(
    array_merge(['' => ''], array_combine($distinctlicenses, $distinctlicenses)),
    'license',
    $license,
    false,
    ['id' => 'oerexchange-filter-license', 'class' => 'form-select d-inline w-auto']
);
echo html_writer::tag(
    'label',
    get_string('filterlanguage', 'local_oerexchange'),
    ['for' => 'oerexchange-filter-language', 'class' => 'ms-2 me-1']
);
echo html_writer::select(
    array_merge(['' => ''], array_combine($distinctlanguages, $distinctlanguages)),
    'language',
    $language,
    false,
    ['id' => 'oerexchange-filter-language', 'class' => 'form-select d-inline w-auto']
);
echo ' ';
echo html_writer::empty_tag('input', [
    'type' => 'submit',
    'value' => get_string('searchbutton', 'local_oerexchange'),
    'class' => 'btn btn-primary ms-2',
]);
echo html_writer::end_tag('form');

if (empty($resources)) {
    $hasfilters = $query !== '' || $type !== '' || $license !== '' || $language !== '';
    $message = $hasfilters
        ? get_string('nocatalogresources', 'local_oerexchange')
        : get_string('catalogueempty', 'local_oerexchange');
    echo $OUTPUT->notification($message, 'info');
} else {
    echo html_writer::start_tag('div', ['class' => 'oerexchange-list row row-cols-1 row-cols-md-3 g-3']);
    foreach ($resources as $r) {
        $url = new moodle_url('/local/oerexchange/resource.php', ['id' => $r->id]);
        if ($r->type === 'course') {
            $typelabel = get_string('typecourse', 'local_oerexchange');
        } else if ($r->type === 'data') {
            $typelabel = get_string('typedata', 'local_oerexchange');
            if (!empty($r->dataresourcetype)) {
                $typelabel .= ' (' . get_string('datatype_' . $r->dataresourcetype, 'local_oerexchange') . ')';
            }
        } else {
            $typelabel = get_string('typeactivity', 'local_oerexchange')
                . ($r->activitytype ? ' (' . s($r->activitytype) . ')' : '');
        }
        echo html_writer::start_tag('div', ['class' => 'col']);
        echo html_writer::start_tag('div', ['class' => 'card h-100']);
        echo html_writer::start_tag('div', ['class' => 'card-body']);
        echo html_writer::tag('h5', html_writer::link($url, s($r->title)), ['class' => 'card-title']);
        echo html_writer::tag('p', s(shorten_text(strip_tags($r->summary ?? ''), 140)), ['class' => 'card-text text-muted']);
        echo html_writer::tag('div', $typelabel . ' · ' . s($r->licenseshortname), ['class' => 'small text-muted']);
        echo html_writer::tag(
            'div',
            get_string('downloadcountlabel', 'local_oerexchange', $r->downloadcount) . ' · '
                . get_string('importcountlabel', 'local_oerexchange', $r->importcount),
            ['class' => 'small text-muted']
        );
        echo html_writer::end_tag('div');
        echo html_writer::end_tag('div');
        echo html_writer::end_tag('div');
    }
    echo html_writer::end_tag('div');

    $baseurl = new moodle_url('/local/oerexchange/index.php', [
        'q' => $query, 'type' => $type, 'license' => $license, 'language' => $language,
    ]);
    echo $OUTPUT->paging_bar($total, $page, $perpage, $baseurl);
}

echo $OUTPUT->footer();
