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
 * This file contains the definition for the library class for autograding submission plugin
 *
 * @package   assignsubmission_autograding
 * @copyright 2022, Dimas 13518069@std.stei.itb.ac.id
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

class assign_submission_autograding extends assign_submission_plugin {
    /**
     * Get the name of the autograding submission plugin
     * @return string
     */
    public function get_name() {
        return get_string('autograding', 'assignsubmission_autograding');
    }

    /**
     * @param int $courseid Course Id.
     * @param int $assignmentid Assignment Id.
     * @return stdClass
     */
    public function get_repo(int $courseid, int $assignmentid) {
        $config = get_config('assignsubmission_autograding');
        $curl = new curl();
        $url = get_string(
            'urltemplate',
            'assignsubmission_autograding',
            [
                'url' => $config->bridge_service_url,
                'endpoint' => "/repository/detail?courseId=$courseid&assignmentId=$assignmentid"
            ]
        );

        $curl->setHeader(array('Content-type: application/json'));
        $curl->setHeader(array('Accept: application/json', 'Expect:'));
        return json_decode($curl->get($url));
    }

    /**
     * Get the settings for autograding submission plugin
     *
     * @param MoodleQuickForm $mform The form to add elements to
     * @return void
     */
    public function get_settings(MoodleQuickForm $mform) {
        global $CFG;
        $config = get_config('assignsubmission_autograding');

        $mform->addElement(
            'filemanager',
            'assignsubmission_autograding_codereference',
            get_string('codereference', 'assignsubmission_autograding'),
            null,
            array(
                'subdirs' => 0, 'maxbytes' => $CFG->maxbytes, 'areamaxbytes' => 10485760, 'maxfiles' => 50,
                'accepted_types' => '*', 'return_types' => FILE_INTERNAL | FILE_EXTERNAL
            )
        );
        $mform->addRule('assignsubmission_autograding_codereference', get_string('required'), 'required');
        $mform->hideIf(
            'assignsubmission_autograding_codereference',
            'assignsubmission_autograding_enabled',
            'notchecked'
        );

        $grading_methods = array();
        $grading_methods['MAXIMUM'] = 'Maximum';
        $grading_methods['MINIMUM'] = 'Minimum';
        $grading_methods['AVERAGE'] = 'Average';
        $mform->addElement('select', 'assignsubmission_autograding_gradingMethod', 'Grading Method', $grading_methods);
        $mform->setDefault('assignsubmission_autograding_gradingMethod', 'MAXIMUM');
        $mform->addRule('assignsubmission_autograding_gradingMethod', get_string('required'), 'required');
        $mform->hideIf(
            'assignsubmission_autograding_gradingMethod',
            'assignsubmission_autograding_enabled',
            'notchecked'
        );

        $grading_priority = array();
        $grading_priority['FIRST'] = 'First';
        $grading_priority['LAST'] = 'Last';
        $mform->addElement('select', 'assignsubmission_autograding_gradingPriority', 'Grading Priority', $grading_priority);
        $mform->setDefault('assignsubmission_autograding_gradingPriority', 'FIRST');
        $mform->addRule('assignsubmission_autograding_gradingPriority', get_string('required'), 'required');
        $mform->hideIf(
            'assignsubmission_autograding_gradingPriority',
            'assignsubmission_autograding_enabled',
            'notchecked'
        );

        $mform->addElement('text', 'assignsubmission_autograding_timeLimit', 'Time Limit');
        $mform->setType('assignsubmission_autograding_timeLimit', PARAM_INT);
        $mform->setDefault('assignsubmission_autograding_timeLimit', 3000);
        $mform->addRule('assignsubmission_autograding_timeLimit', get_string('required'), 'required');
        $mform->hideIf(
            'assignsubmission_autograding_timeLimit',
            'assignsubmission_autograding_enabled',
            'notchecked'
        );

        $curl = new curl();
        $url = get_string(
            'urltemplate',
            'assignsubmission_autograding',
            [
                'url' => $config->bridge_service_url,
                'endpoint' => '/autograder/running'
            ]
        );
        $curl->setHeader(array('Content-type: application/json'));
        $curl->setHeader(array('Accept: application/json', 'Expect:'));
        $response_json = json_decode($curl->get($url));

        $autograders = array();
        foreach ($response_json->autograders as $autograder) {
            $autograders[$autograder] = $autograder;
        }

        $graders = $mform->addElement('select', 'assignsubmission_autograding_autograders', 'Autograders', $autograders);
        $graders->setMultiple(true);
        $mform->addRule('assignsubmission_autograding_autograders', get_string('required'), 'required');
        $mform->hideIf(
            'assignsubmission_autograding_autograders',
            'assignsubmission_autograding_enabled',
            'notchecked'
        );
    }

    /**
     * Save the settings for autograding submission plugin
     *
     * @param stdClass $data
     * @return bool
     */
    public function save_settings(stdClass $data) {
        $config = get_config('assignsubmission_autograding');
        global $DB;

        $files_data = $DB->get_records('files', array('itemid' => $data->assignsubmission_autograding_codereference));

        // create gitlab repository
        $curl = new curl();
        $name = str_replace(' ', '-', $data->name);

        $url = get_string(
            'urltemplate',
            'assignsubmission_autograding',
            [
                'url' => $config->bridge_service_url,
                'endpoint' => '/gitlab/createRepository'
            ]
        );
        $payload = array(
            'courseId' => $this->assignment->get_instance()->course,
            'assignmentId' => $this->assignment->get_instance()->id,
            'name' => $name,
            'gradingMethod' => $data->assignsubmission_autograding_gradingMethod,
            'gradingPriority' => $data->assignsubmission_autograding_gradingPriority,
            'timeLimit' => $data->assignsubmission_autograding_timeLimit,
            'dueDate' => $data->duedate,
            'autograders' => $data->assignsubmission_autograding_autograders,
        );
        $payload_string = json_encode($payload);

        $curl->setHeader(array('Content-type: application/json'));
        $curl->setHeader(array('Accept: application/json', 'Expect:'));
        $response_json = json_decode($curl->post($url, $payload_string));

        if ($response_json->success) {
            // save code reference
            foreach ($files_data as $file_data) {
                if ($file_data->filename !== '.') {
                    $fs = get_file_storage();
                    $file = $fs->get_file_by_hash($file_data->pathnamehash);
                    $curl = new curl();

                    $prop = explode('.', $file_data->filename);
                    $filename = $prop[0];
                    $ex = '';
                    if (count($prop) > 1) {
                        $ex = $prop[1];
                    }

                    $url = get_string(
                        'urltemplate',
                        'assignsubmission_autograding',
                        [
                            'url' => $config->bridge_service_url,
                            'endpoint' => '/moodle/saveReference'
                        ]
                    );
                    $payload = array(
                        'courseId' => $this->assignment->get_instance()->course,
                        'assignmentId' => $this->assignment->get_instance()->id,
                        'contentHash' => $file_data->contenthash,
                        'extension' => $ex,
                        'filename' => $filename,
                        'rawContent' => base64_encode($file->get_content()),
                    );
                    $payload_string = json_encode($payload);
                    $curl->setHeader(array('Content-type: application/json'));
                    $curl->setHeader(array('Accept: application/json', 'Expect:'));
                    $curl->post($url, $payload_string);

                    $file->delete();    // remove from moodle file system
                }
            }
        }

        unset($data->assignsubmission_autograding_codereference);
        unset($data->assignsubmission_autograding_gradingMethod);
        unset($data->assignsubmission_autograding_gradingPriority);
        unset($data->assignsubmission_autograding_timeLimit);
        unset($data->assignsubmission_autograding_autograders);

        return true;
    }

    /**
     * Display GitLab repository link in submission status table
     *
     * @param stdClass $submission
     * @param bool $showviewlink - If the summary has been truncated set this to true
     * @return string
     */
    public function view_summary(stdClass $submission, &$showviewlink) {
        $repo = $this->get_repo($this->assignment->get_instance()->course, $this->assignment->get_instance()->id)->repository;
        return '<a href="' . $repo->gitlabUrl . '" target="_blank">Gitlab Repository</a>';
    }

    /**
     * Empty if no repo exist for this assignment
     *
     * @param stdClass $submission
     * @return bool
     */
    public function is_empty(stdClass $submission) {
        $data = $this->get_repo($this->assignment->get_instance()->course, $this->assignment->get_instance()->id);
        return is_null($data) || !$data->success;
    }
}
