<?php
namespace local_gradesheet;

defined('MOODLE_INTERNAL') || die();

/**
 * Hook callbacks for local_gradesheet.
 */
class hooks {

    /**
     * Helper to fetch distinct non-empty field values from past course configs.
     *
     * @param string $fieldname
     * @return array
     */
    private static function get_distinct_options(string $fieldname): array {
        global $DB;
        $options = [];
        try {
            $records = $DB->get_fieldset_sql(
                "SELECT DISTINCT {$fieldname} FROM {local_gradesheet_config} WHERE {$fieldname} IS NOT NULL AND {$fieldname} != '' ORDER BY {$fieldname} ASC"
            );
            foreach ($records as $val) {
                $v = trim($val);
                if (!empty($v)) {
                    $options[$v] = $v;
                }
            }
        } catch (\Exception $e) {
            // Table might not exist yet during initial installation.
        }
        return $options;
    }

    /**
     * Extend Moodle course edit form to add Gradesheet parameters.
     *
     * @param \core_course\hook\after_form_definition $hook
     */
    public static function after_form_definition(\core_course\hook\after_form_definition $hook): void {
        global $DB, $USER;

        $mform = $hook->mform;
        $course = $hook->formwrapper->get_course();
        $courseid = !empty($course->id) ? $course->id : 0;

        // Fetch existing config if editing an existing course.
        $existing = null;
        if ($courseid > 0) {
            $existing = $DB->get_record('local_gradesheet_config', ['courseid' => $courseid]);
        }

        // Add collapsible header section for Gradesheet.
        $mform->addElement('header', 'gradesheet_hdr', 'Gradesheet Configuration');
        $mform->setExpanded('gradesheet_hdr', false);

        // 1. Semester Dropdown
        $semester_options = [
            'First Semester'  => 'First Semester',
            'Second Semester' => 'Second Semester',
            'Summer'          => 'Summer',
        ];
        $mform->addElement('select', 'gradesheet_semester', 'Semester', $semester_options);
        $mform->setDefault('gradesheet_semester', $existing ? $existing->semester : 'First Semester');

        // 2. School Year Dropdown
        $cyear = (int)date('Y');
        $sy_options = [];
        for ($y = $cyear - 2; $y <= $cyear + 3; $y++) {
            $sy = $y . '-' . ($y + 1);
            $sy_options[$sy] = $sy;
        }
        $mform->addElement('select', 'gradesheet_schoolyear', 'School Year', $sy_options);
        $default_sy = $existing ? $existing->schoolyear : ($cyear . '-' . ($cyear + 1));
        $mform->setDefault('gradesheet_schoolyear', $default_sy);

        // 3. Course Number / Code
        $mform->addElement('text', 'gradesheet_coursenumber', 'Course Code / Number', ['size' => 30]);
        $mform->setType('gradesheet_coursenumber', PARAM_TEXT);
        $mform->setDefault('gradesheet_coursenumber', $existing ? $existing->coursenumber : ($course ? $course->shortname : ''));

        // 4. Descriptive Title
        $mform->addElement('text', 'gradesheet_descriptive', 'Descriptive Title', ['size' => 50]);
        $mform->setType('gradesheet_descriptive', PARAM_TEXT);
        $mform->setDefault('gradesheet_descriptive', $existing ? $existing->descriptive : ($course ? $course->fullname : ''));

        // 5. Course & Year / Section
        $mform->addElement('text', 'gradesheet_courseandyear', 'Course & Year / Section', ['size' => 30]);
        $mform->setType('gradesheet_courseandyear', PARAM_TEXT);
        $mform->setDefault('gradesheet_courseandyear', $existing ? $existing->courseandyear : '');

        // 6. Schedule (MW or TTH Days Dropdown + Typeable Time Input)
        $schedule_days_options = [
            'MW'  => 'MW (Monday, Wednesday)',
            'TTH' => 'TTH (Tuesday, Thursday)',
        ];

        $current_schedule = $existing ? $existing->schedule : 'MW 8:00-9:30 AM';
        $current_days = 'MW';
        $current_time = '8:00-9:30 AM';

        if (!empty($current_schedule)) {
            if (strpos(strtoupper($current_schedule), 'TTH') === 0) {
                $current_days = 'TTH';
                $current_time = trim(substr($current_schedule, 3));
            } else if (strpos(strtoupper($current_schedule), 'MW') === 0) {
                $current_days = 'MW';
                $current_time = trim(substr($current_schedule, 2));
            } else {
                $current_time = $current_schedule;
            }
        }

        $mform->addElement('select', 'gradesheet_schedule_days', 'Schedule Days', $schedule_days_options);
        $mform->setDefault('gradesheet_schedule_days', $current_days);

        $mform->addElement('text', 'gradesheet_schedule_time', 'Schedule Time', ['size' => 30]);
        $mform->setType('gradesheet_schedule_time', PARAM_TEXT);
        $mform->setDefault('gradesheet_schedule_time', $current_time);

        // 7. Units Dropdown
        $unit_options = [
            '1' => '1 Unit',
            '2' => '2 Units',
            '3' => '3 Units',
            '4' => '4 Units',
            '5' => '5 Units',
            '6' => '6 Units',
        ];
        $mform->addElement('select', 'gradesheet_units', 'Units', $unit_options);
        $mform->setDefault('gradesheet_units', $existing ? $existing->units : '3');

        // 8. Instructor Name (Defaults to currently logged-in user)
        $default_instructor = '';
        if ($existing && !empty($existing->instructor)) {
            $default_instructor = $existing->instructor;
        } else if (!empty($USER->id)) {
            $default_instructor = strtoupper(trim(fullname($USER)));
        }
        $mform->addElement('text', 'gradesheet_instructor', 'Instructor Name', ['size' => 50]);
        $mform->setType('gradesheet_instructor', PARAM_TEXT);
        $mform->setDefault('gradesheet_instructor', $default_instructor);

        // Helper to build signatory element (dropdown of past entries + text entry hidden unless '__new__' is chosen)
        $add_signatory_element = function(string $fieldname, string $label, ?string $existingval) use ($mform) {
            $past_options = self::get_distinct_options($fieldname);
            
            // Build options array with existing names
            $select_options = ['' => '-- Select ' . $label . ' --'];
            foreach ($past_options as $name) {
                $select_options[$name] = $name;
            }
            $select_options['__new__'] = '-- Add / Type New Name Below --';

            $select_name = 'gradesheet_' . $fieldname . '_select';
            $new_name    = 'gradesheet_' . $fieldname . '_new';

            $mform->addElement('select', $select_name, $label, $select_options);
            
            if ($existingval && isset($select_options[$existingval])) {
                $mform->setDefault($select_name, $existingval);
            } else if ($existingval && !empty($existingval)) {
                $mform->setDefault($select_name, '__new__');
            }

            $mform->addElement('text', $new_name, 'New ' . $label, ['size' => 50]);
            $mform->setType($new_name, PARAM_TEXT);
            if ($existingval && !isset($past_options[$existingval])) {
                $mform->setDefault($new_name, $existingval);
            }

            // Hide the text field unless '__new__' option is selected!
            $mform->hideIf($new_name, $select_name, 'neq', '__new__');
        };

        // 9. Department Head
        $add_signatory_element('department_head', 'Department Head', $existing ? $existing->department_head : null);

        // 10. Registrar
        $add_signatory_element('registrar', 'Registrar', $existing ? $existing->registrar : null);

        // 11. College Dean
        $add_signatory_element('college_dean', 'College Dean', $existing ? $existing->college_dean : null);
    }

    /**
     * Save Gradesheet parameters when course form is submitted.
     *
     * @param \core_course\hook\after_form_submission $hook
     */
    public static function after_form_submission(\core_course\hook\after_form_submission $hook): void {
        global $DB;

        $data = $hook->get_data();
        $courseid = !empty($data->id) ? (int)$data->id : 0;

        if ($courseid <= 0) {
            return;
        }

        // Ensure defaults (categories, weights, etc.) are established.
        \local_gradesheet\helper::ensure_course_defaults($courseid);

        // Process signatory values (prefer newly entered name if provided, otherwise select dropdown value)
        $resolve_signatory = function(string $fieldname) use ($data): string {
            $newval = !empty($data->{'gradesheet_' . $fieldname . '_new'}) ? trim($data->{'gradesheet_' . $fieldname . '_new'}) : '';
            if (!empty($newval)) {
                return strtoupper($newval);
            }
            $selectval = !empty($data->{'gradesheet_' . $fieldname . '_select'}) ? trim($data->{'gradesheet_' . $fieldname . '_select'}) : '';
            if ($selectval !== '__new__' && !empty($selectval)) {
                return strtoupper($selectval);
            }
            return '';
        };

        // Process schedule (combine Days dropdown + Time text input)
        $sch_days = !empty($data->gradesheet_schedule_days) ? trim($data->gradesheet_schedule_days) : 'MW';
        $sch_time = !empty($data->gradesheet_schedule_time) ? trim($data->gradesheet_schedule_time) : '';
        $full_schedule = trim($sch_days . ' ' . $sch_time);

        // Only save if our custom fields were present on the form submission.
        if (isset($data->gradesheet_semester) || isset($data->gradesheet_instructor)) {
            $existing = $DB->get_record('local_gradesheet_config', ['courseid' => $courseid]);

            $configdata = (object)[
                'courseid'        => $courseid,
                'semester'        => $data->gradesheet_semester ?? 'First Semester',
                'schoolyear'      => $data->gradesheet_schoolyear ?? (date('Y') . '-' . (date('Y') + 1)),
                'coursenumber'    => !empty($data->gradesheet_coursenumber) ? $data->gradesheet_coursenumber : '',
                'descriptive'     => !empty($data->gradesheet_descriptive) ? $data->gradesheet_descriptive : '',
                'courseandyear'   => !empty($data->gradesheet_courseandyear) ? $data->gradesheet_courseandyear : '',
                'schedule'        => $full_schedule,
                'units'           => $data->gradesheet_units ?? '3',
                'instructor'      => !empty($data->gradesheet_instructor) ? strtoupper(trim($data->gradesheet_instructor)) : '',
                'department_head' => $resolve_signatory('department_head'),
                'registrar'       => $resolve_signatory('registrar'),
                'college_dean'    => $resolve_signatory('college_dean'),
                'timemodified'    => time(),
            ];

            if ($existing) {
                $configdata->id = $existing->id;
                $configdata->timecreated = $existing->timecreated;
                $DB->update_record('local_gradesheet_config', $configdata);
            } else {
                $configdata->timecreated = time();
                $DB->insert_record('local_gradesheet_config', $configdata);
            }
        }
    }
}
