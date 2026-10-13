<?php
defined('MOODLE_INTERNAL') || die();

$string['pluginname']          = 'Grade Sheet Generator';
$string['gradesheet']          = 'Grade Sheet';
$string['selectcourse']        = 'Select Course';
$string['generategrades']      = 'Generate Grade Sheet';
$string['exportpdf']           = 'Export as PDF';
$string['exportexcel']         = 'Export as Excel';
$string['studentid']           = 'Student ID';
$string['studentname']         = 'Student Name';
$string['quizgrade']           = 'Quiz Grade';
$string['examgrade']           = 'Exam Grade';
$string['activitygrade']       = 'Activity Grade';
$string['finalgrade']          = 'Final Grade';
$string['viewmygrades']        = 'View My Grades';
$string['nogradesfound']       = 'No grades found for this course.';
$string['gradesheet:view']     = 'View grade sheets';
$string['gradesheet:manage']   = 'Manage grade sheets';
$string['warnunmappeditems']   = '{$a} grade item(s) are not mapped to any category and are excluded from grade computation.';
$string['warnnoperioditems']   = 'No grade items are mapped to {$a}. That column will show "-" and the final average falls back to the period that has items.';
$string['warnlastcategory']    = 'The last remaining grade category cannot be deleted.';

// Admin settings: signatory auto-detection.
$string['signatoryroles']          = 'Signatory auto-detection';
$string['signatoryroles_desc']     = 'Grade sheets fill in the Instructor, Department Head, Registrar and College Dean lines automatically from Moodle role assignments when the course has not typed a name. The Instructor is the editing teacher of the course (or section). The other three are looked up by role, searching upward from the course through its categories to the site: assign the department head role at the department/program category, the dean role at the college category, and the registrar role at the system level (Site administration > Users > Permissions > Assign system roles). With "Auto-detect" the plugin uses the roles your site already has, matched by name (Dean or College Dean; Program Head, Program Chair, Department Head or Chairperson; Registrar). Only a Registrar role is created on install, and only when the site has none. Pick a role below if your site names them differently.';
$string['role_auto']               = 'Auto-detect from existing roles by name (recommended)';
$string['role_departmenthead']     = 'Department Head role';
$string['role_departmenthead_desc'] = 'Role whose holder signs as Department Head. Auto-detect matches a role named Program Head, Program Chair, Department Head, Department Chair or Chairperson.';
$string['role_collegedean']        = 'College Dean role';
$string['role_collegedean_desc']   = 'Role whose holder signs as College Dean. Auto-detect matches a role named Dean or College Dean.';
$string['role_registrar']          = 'Registrar role';
$string['role_registrar_desc']     = 'Role whose holder signs as Registrar. Auto-detect matches a role named Registrar or University Registrar.';

// Privacy API.
$string['privacy:metadata:status']              = 'Faculty-set academic status overrides (Incomplete, Dropped, Withdrawn, In Progress) per student per course.';
$string['privacy:metadata:status:courseid']     = 'The course the status applies to.';
$string['privacy:metadata:status:userid']       = 'The student the status applies to.';
$string['privacy:metadata:status:status']       = 'The status code set by the faculty member.';
$string['privacy:metadata:status:timemodified'] = 'When the status was last changed.';
$string['privacy:metadata:config']              = 'Per-course report header settings, including signatory names typed by faculty. These names are free text and are not linked to Moodle user accounts.';
$string['privacy:metadata:config:instructor']      = 'Instructor name typed for the report (if not auto-detected).';
$string['privacy:metadata:config:department_head'] = 'Department Head name typed for the report (if not auto-detected).';
$string['privacy:metadata:config:registrar']       = 'Registrar name typed for the report (if not auto-detected).';
$string['privacy:metadata:config:college_dean']    = 'College Dean name typed for the report (if not auto-detected).';
$string['privacy:metadata:groupcfg']            = 'Per-section report header overrides.';
$string['privacy:metadata:groupcfg:instructor'] = 'Instructor name typed for a section (if not auto-detected).';
