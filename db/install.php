<?php
defined('MOODLE_INTERNAL') || die();

function xmldb_local_gradesheet_install() {
    // Creates a Registrar role when the site has none. Dean and department head
    // signatories are detected from the roles the site already has.
    \local_gradesheet\helper::ensure_signatory_roles();
    return true;
}
