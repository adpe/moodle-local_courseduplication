<?php

global $CFG;

require_once(__DIR__ . '/../../backup/util/includes/backup_includes.php');
require_once(__DIR__ . '/../../backup/util/includes/restore_includes.php');

class local_courseduplication_controller
{

    protected $options = array(
        'activities' => 1,
        'blocks' => 1,
        'filters' => 1,
        'users' => 0,
        'role_assignments' => 0,
        'comments' => 0,
        'logs' => 0
    );

    public function backup_course($courseid, $userid, $log=false)
    {
        $bc = new backup_controller(backup::TYPE_1COURSE, $courseid, backup::FORMAT_MOODLE, backup::INTERACTIVE_NO, backup::MODE_SAMESITE, $userid);
        if ($log) {
            ob_end_flush();
            $bc->get_logger()->set_next(new output_indented_logger(backup::LOG_DEBUG, false, true));
        }

        foreach ($this->options as $name => $value) {
            $setting = $bc->get_plan()->get_setting($name);
            $setting->set_status(backup_setting::NOT_LOCKED);
            $setting->set_value($value);
        }
        $backupid       = $bc->get_backupid();
        $backupbasepath = $bc->get_plan()->get_basepath();

        $bc->execute_plan();
        $results = $bc->get_results();
        $backupfile = $results['backup_destination'];
        $bc->destroy();
        return array(
            'id' => $backupid,
            'basepath' => $backupbasepath,
            'file' => $backupfile
        );
    }

    /**
     * Restore a course that was backed up.
     * @param object $course containing fields: fullname, shortname, category
     * @param array $backup array as returned from $this->backup_course()
     * @param int $userid
     * @param bool $remove_backupfile whether or not to remove the backup file after restoring
     * @return int newcourseid
     */
    public function restore_course($course, $backup, $userid, $remove_backupfile=true, $log=false)
    {
        global $DB, $CFG;

        $backupfile = $backup['file'];
        // Check if we need to unzip the file because the backup temp dir does not contains backup files.
        if (!file_exists($backup['basepath'] . "/moodle_backup.xml")) {
            $backupfile->extract_to_pathname(get_file_packer(), $backup['basepath']);
        }

        $newcourseid = restore_dbops::create_new_course($course->fullname, $course->shortname, $course->category);
        $rc = new restore_controller($backup['id'], $newcourseid,
                backup::INTERACTIVE_NO, backup::MODE_SAMESITE, $userid, backup::TARGET_NEW_COURSE);
        if ($log) {
            $rc->get_logger()->set_next(new output_indented_logger(backup::LOG_DEBUG, false, true));
        }

        foreach ($this->options as $name => $value) {
            $setting = $rc->get_plan()->get_setting($name);
            $setting->set_status(backup_setting::NOT_LOCKED);
            $setting->set_value($value);
        }

        if (!$rc->execute_precheck()) {
            $precheckresults = $rc->get_precheck_results();
            if (is_array($precheckresults) && !empty($precheckresults['errors'])) {
                if (empty($CFG->keeptempdirectoriesonbackup)) {
                    fulldelete($backup['basepath']);
                }
                $errorinfo = '';
                foreach ($precheckresults['errors'] as $error) {
                    $errorinfo .= ' ' . $error;
                }
                if (array_key_exists('warnings', $precheckresults)) {
                    foreach ($precheckresults['warnings'] as $warning) {
                        $errorinfo .= ' ' . $warning;
                    }
                }
                $rc->destroy();
                throw new moodle_exception('errorrestoreprecheck', 'local_courseduplication', '', $errorinfo);
            }
        }

        $rc->execute_plan();
        $rc->destroy();
        if (empty($CFG->keeptempdirectoriesonbackup)) {
            fulldelete($backup['basepath']);
        }
        if ($remove_backupfile) {
            $backupfile->delete();
        }
        return $newcourseid;
    }

    public function copy_teachers($oldcourseid, $newcourseid, $warninglang='en') {
        global $DB;

        $roleusers = $this->get_archetype_users_in_course($oldcourseid);

        $warnings = array();
        foreach ($roleusers as $roleid => $roleusers) {
            foreach ($roleusers as $user) {
                if (!enrol_try_internal_enrol($newcourseid, $user->id, $roleid)) {
                    $string = new lang_string('warningenrollingfailed', 'local_courseduplication', null, $warninglang);
                    $warnings[] = $string->out();
                }
            }
        }
        return $warnings;
    }

    // gets all people with roles based on the given archetypes teacher or editingteacher in the given course
    protected function get_archetype_users_in_course($courseid, $archetypes=array('teacher', 'editingteacher')) {
        global $DB;

        list ($archetypesql, $archetypeparams) = $DB->get_in_or_equal($archetypes);
        $roles = $DB->get_records_select('role', "archetype $archetypesql", $archetypeparams, '', 'id');
        if (!$roles) {
            debugging('No roles found.  Looked for roles of archetype: ' . implode(',', $archetypes));
        }

        $coursecontext = context_course::instance($courseid);
        $users = array();
        foreach ($roles as $role) {
            if ($roleusers = get_role_users($role->id, $coursecontext)) {
                $users[$role->id] = $roleusers;
            }
        }
        return $users;
    }
}

class local_course_duplication_queue {

    const STATUS_QUEUED  = 0;
    const STATUS_RUNNING = 1;
    const STATUS_SUCCESS = 2;
    const STATUS_FAILED  = 3;

    protected $runid;

    static function queue($courseid, $categoryid, $userid) {
        global $DB;
        $duplication = new stdClass;
        $duplication->courseid = $courseid;
        $duplication->categoryid = $categoryid;
        $duplication->status = self::STATUS_QUEUED;
        $duplication->userid = $userid;
        $DB->insert_record('courseduplication_queue', $duplication);
    }

    public function __construct() {
        $this->runid = uniqid(getmypid(), true);
    }

    public function process_queue() {
        global $DB;
        $info = array(
            'succeeded' => array(),
            'failed' => array()
        );

        $queued = $this->get_queued_jobs();
        if (!count($queued)) {
            return;
        }

        foreach ($queued as $job) {

            if (!$userlang = $DB->get_field('user', 'lang', array('id' => $job->userid))) {
                $userlang = 'en';
            }

            list($status, $errors, $warnings, $newcourseid) = $this->process_job($job, $userlang);

            if ($status == self::STATUS_FAILED) {
                $message = "course id $job->courseid to category $job->categoryid";
                $info['failed'][] = $message;
                add_to_log($job->courseid, 'courseduplication', 'restore', '',
                    "duplication failed.  " . $message);
                // Database connection may have been reset if there was a failure.  So pull in $DB
                // from the globals again.
                global $DB;
            } else {
                $message = "course id $job->courseid to category $job->categoryid (new course id: $newcourseid)";
                $info['succeeded'][] = $message;
                add_to_log($job->courseid, 'courseduplication', 'restore', '',
                    "duplication succeeded.  " . $message);
            }
            $this->send_mail($job, $status, $errors, $warnings, $newcourseid, $userlang);
            $DB->delete_records('courseduplication_queue', array('id' => $job->id));
        }
        return $info;
    }

    protected function send_mail($job, $status, $errors, $warnings, $newcourseid, $userlang) {
        global $DB;

        $a = new stdClass;
        $oldcourse = $DB->get_record('course', array('id' => $job->courseid), 'id, fullname, shortname');
        $a->oldfullname = $oldcourse->fullname;
        $category = $DB->get_record('course_categories', array('id' => $job->categoryid), 'id, name');
        $a->categoryname = $category->name;

        if (count($errors)) {
            $errorstr = new lang_string('error', 'error', null, $userlang);
            $a->errors = $errorstr . ":\n * " . implode("\n * ", $errors);
            $fromto = "$job->courseid to $job->categoryid: ";
            add_to_log($job->courseid, 'courseduplication', 'duplication', '', substr($fromto . $a->errors, 0, 255));
        } else {
            $a->errors = '';
        }
        if (count($warnings)) {
            $warningstr = new lang_string('warning', 'moodle', null, $userlang);
            $a->warnings = $warningstr . ":\n * " . implode("\n * ", $warnings);
            $fromto = "$job->courseid to $job->categoryid: ";
            add_to_log($job->courseid, 'courseduplication', 'duplication', '', substr($fromto . $a->warnings, 0, 255));
        } else {
            $a->warnings = '';
        }

        $subjectstringkey = 'mailduplicationfailsubject';
        $bodystringkey = 'mailduplicationfailbody';

        if ($status == self::STATUS_SUCCESS) {
            $subjectstringkey = 'mailduplicationsuccesssubject';
            $bodystringkey = 'mailduplicationsuccessbody';

            $newcourse = $DB->get_record('course', array('id' => $newcourseid), 'id, fullname, shortname, category');
            $a->newfullname = $newcourse->fullname;
            $url = new moodle_url('/course/view.php', array('id' => $newcourse->id));
            $a->newcourseurl = $url->out();
        }

        $subject = new lang_string($subjectstringkey, 'local_courseduplication', $a, $userlang);
        $body = new lang_string($bodystringkey, 'local_courseduplication', $a, $userlang);

        $user = $DB->get_record('user', array('id' => $job->userid), 'id, firstname, lastname, email, maildisplay');
        $admin = get_admin();
        email_to_user($user, $admin, $subject, $body);
    }

    /**
     * Gets queued jobs
     *
     * This marks the jobs in the table with a run id, so that no other process
     * tries to simultaneously process the same job.
     */
    protected function get_queued_jobs() {
        global $DB;
        $queued = $DB->get_records('courseduplication_queue', array('status' => self::STATUS_QUEUED));
        if (!count($queued)) {
            return array();
        }
        foreach ($queued as $job) {
            $job->status = self::STATUS_RUNNING;
            $job->runid  = $this->runid;
            $DB->update_record('courseduplication_queue', $job);
        }
        return $DB->get_records('courseduplication_queue',
            array('status' => self::STATUS_RUNNING, 'runid' => $this->runid));
    }

    protected function process_job($job, $stringlang='en') {
        global $DB;

        $status = 0;
        $errors = 1;
        $warnings = 2;
        $courseid = 3;

        $return = array(
            $status => self::STATUS_FAILED,
            $errors => array(),
            $warnings => array(),
            $courseid => 0
        );

        if (!$course = $DB->get_record('course', array('id' => $job->courseid))) {
            $return[$errors][] = get_string('duplicatefailedbackup', 'local_courseduplication') . ': ' . get_string('invalidcourseid');
        }

        if (!$category = $DB->get_record('course_categories', array('id' => $job->categoryid))) {
            $string1 = new lang_string('duplicatefailedbackup', 'local_courseduplication', null, $stringlang);
            $string2 = new lang_string('errornosuchcategory', 'local_courseduplication', null, $stringlang);
            $return[$errors][] = $string1 . ': ' . $string2;
        }

        if (count($return[$errors])) {
            return $return;
        }

        if (!has_capability('local/courseduplication:restore_course', context_coursecat::instance($category->id))) {
            $return[$errors][] = get_string('errornopermsintarget', 'local_courseduplication');
            return $return;
        }

        $admin = get_admin();
        $dup = new local_courseduplication_controller();

        try {
            $backup = $dup->backup_course($course->id, $admin->id);
        } catch (Exception $e) {
            $return[$errors][] = get_string('duplicatefailedbackup', 'local_courseduplication');
            $return[$errors][] = $e->getMessage();
            debugging($e->getMessage());
            $this->database_reconnect();
            return $return;
        }

        $newcourse = new stdClass();
        $newcourse->fullname = $course->fullname . ' copy';
        $newcourse->shortname = $course->shortname . 'copy';
        $newcourse->category = $category->id;

        try {
            $return[$courseid] = $dup->restore_course($newcourse, $backup, $admin->id);
        } catch (Exception $e) {
            $return[$errors][] = get_string('duplicatefailedrestore', 'local_courseduplication');
            $return[$errors][] = $e->getMessage();
            debugging($e->getMessage());
            $this->database_reconnect();
            return $return;
        }

        $return[$status] = self::STATUS_SUCCESS;
        $return[$warnings] = $dup->copy_teachers($course->id, $return[$courseid]);
        return $return;
    }

    /**
     * Reconnect to the Moodle database.  This drops any temp tables
     * which were created during a backup / restore process.
     *
     * Note that it will be necessary to re-initialize any references to the global $DB
     * in call-stack ancestor functions after this is called.
     */
    protected function database_reconnect() {
        global $DB;
        $DB->dispose();
        $GLOBALS['DB'] = null;
        setup_DB();
    }

}