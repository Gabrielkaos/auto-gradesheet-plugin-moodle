<?php
defined('MOODLE_INTERNAL') || die();

// Site administration > Plugins > Local plugins > Grade Sheet Generator.
if ($hassiteconfig) {
    $settings = new admin_settingpage('local_gradesheet', get_string('pluginname', 'local_gradesheet'));
    $ADMIN->add('localplugins', $settings);

    $settings->add(new admin_setting_heading(
        'local_gradesheet/signatoryroles',
        get_string('signatoryroles', 'local_gradesheet'),
        get_string('signatoryroles_desc', 'local_gradesheet')
    ));

    // Every role on the site, keyed by shortname, plus "auto" (match by name).
    $rolechoices = ['' => get_string('role_auto', 'local_gradesheet')];
    if (function_exists('get_all_roles')) {
        $allroles = get_all_roles();
        if (function_exists('role_fix_names')) {
            $allroles = role_fix_names($allroles, null, ROLENAME_ORIGINAL);
        }
        foreach ($allroles as $r) {
            $label = isset($r->localname) && $r->localname !== '' ? $r->localname : ($r->name !== '' ? $r->name : $r->shortname);
            $rolechoices[$r->shortname] = $label . ' (' . $r->shortname . ')';
        }
    }

    foreach (['departmenthead', 'collegedean', 'registrar'] as $sig) {
        $settings->add(new admin_setting_configselect(
            'local_gradesheet/role_' . $sig,
            get_string('role_' . $sig, 'local_gradesheet'),
            get_string('role_' . $sig . '_desc', 'local_gradesheet'),
            '',
            $rolechoices
        ));
    }
}
