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
 * Used for tracking conditions that apply before activities are displayed
 * to students ('conditional availability').
 *
 * @package    core
 * @subpackage coursecondition
 * @copyright  1999 onwards Martin Dougiamas  http://dougiamas.com
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

function coursecompletion_definition_after_data( $courseid=0, &$mform ) {
    global $DB, $CFG;

    $course_availability_set = $DB->get_records_sql("select * FROM {$CFG->prefix}course_availability WHERE courseid='{$courseid}'");
    $num=0;
    if ($course_availability_set) foreach($course_availability_set as $caid=>$ca) {
        $groupelements=$mform->getElement('courseconditiongradegroup['.$num.']')->getElements();
        $groupelements[0]->setValue($ca->sourcecourseid);
        // These numbers are always in the format 0.00000 - the rtrims remove any final zeros and,
        // if it is a whole number, the decimal place.
        $groupelements[2]->setValue(is_null($ca->grademin)?'':rtrim(rtrim($ca->grademin,'0'),'.'));
        $groupelements[4]->setValue(is_null($ca->grademax)?'':rtrim(rtrim($ca->grademax,'0'),'.'));
        $num++;
    }
    return true;
}

function coursecompletion_updatecourse(&$course, $conditiondata) {
    global $DB;

    $DB->delete_records("course_availability", array("courseid"=>$course->id));
    $linked = array();
    foreach ($conditiondata as $condition_set) {
        if (empty($condition_set['courseconditioncourseid'])) continue;
        $insdata = new stdClass();
        $insdata->courseid = $course->id;
        $insdata->sourcecourseid = $condition_set['courseconditioncourseid'];
        $insdata->grademin = $condition_set['courseconditiongrademin'];
        $insdata->grademax = $condition_set['courseconditiongrademax'];
        if (!isset($linked[$insdata->sourcecourseid])) {
            //we can have the same course linked twice, it would cause an error in the HTML form
            $DB->insert_record("course_availability", $insdata);
        }
        $linked[$insdata->sourcecourseid] = $insdata->sourcecourseid;
    }
    return true;
}

function coursecompletion_formelements( &$course, &$course_edit_form, &$mform ) {
    global $DB, $CFG;

    $mform->addElement('header', '', get_string('courseavailabilityconditions', 'core_coursecondition'));
    // Conditions based on grades
    $courseoptions = array();
    $courses = $DB->get_records_sql("select c.id,c.fullname,cc.name from {$CFG->prefix}course  c
                                     LEFT JOIN {$CFG->prefix}course_categories cc ON cc.id = c.category 
                                     WHERE c.id <> 1 AND c.id <> '{$course->id}'
                                     ORDER BY cc.name,cc.id,c.fullname");
    
    if ($courses) foreach ( $courses as $course_id=>$course_and_cat ) $courseoptions[$course_id] = $course_and_cat->fullname;
    asort($courseoptions);
    $courseoptions = array(0=>get_string('none','core_coursecondition'))+$courseoptions;
    $grouparray = array();
    $grouparray[] =& $mform->createElement('select','courseconditioncourseid','',$courseoptions);
    $grouparray[] =& $mform->createElement('static', '', '',' '.get_string('grade_atleast','core_coursecondition').' ');
    $grouparray[] =& $mform->createElement('text', 'courseconditiongrademin','',array('size'=>3));
    $grouparray[] =& $mform->createElement('static', '', '','% '.get_string('grade_upto','core_coursecondition').' ');
    $grouparray[] =& $mform->createElement('text', 'courseconditiongrademax','',array('size'=>3));
    $grouparray[] =& $mform->createElement('static', '', '','%');
    $mform->setType('courseconditiongrademin',PARAM_FLOAT);
    $mform->setType('courseconditiongrademax',PARAM_FLOAT);
    $group = $mform->createElement('group','courseconditiongradegroup',
                get_string('coursegradecondition', 'core_coursecondition'),$grouparray);
    $count = 3; //@TODO set this value to current conditions count + 1
    $course_edit_form->repeat_elements(array($group),$count,array(),
                           'courseconditioncompletionrepeats','courseconditioncompletionadds',2,
                           get_string('addcompletions','core_coursecondition'),true);
    return true;
}

function coursecompletion_checkcompletion(&$course) {
    global $DB, $CFG, $USER;

    $hasoutstanding      = false;
    $completionmessage   = '';
    $completionmessages  = array();    
    $completion_elements = $DB->get_records("course_availability", array('courseid'=>$course->id));

    if ($completion_elements && sizeof($completion_elements)>0) {
        foreach ($completion_elements as $ce) {
            $grade_item     = $DB->get_record("grade_items", array('courseid'=>$ce->sourcecourseid, 'itemtype'=>'course'));
            $source_course  = $DB->get_record('course', array('id'=>$ce->sourcecourseid));
            if (!$source_course) continue; //If the conditional course is missing/deleted the condition can never be met

            if (!$grade_item) {
                $hasoutstanding = true;
                if ($source_course) $completionmessages[] =  get_string('mustcompletecourse', 'enrol_self', "<a href=\"{$CFG->wwwroot}/course/view.php?id={$source_course->id}\">{$source_course->fullname}</a>") ." before you can enter this course.";//. get_string('beforeyoucanenter', 'core_coursecondition', $course->fullname);
                continue;
            }

            $grade = $DB->get_record('grade_grades', array('itemid'=>$grade_item->id, 'userid'=>$USER->id));
            if (!$grade) {
                $hasoutstanding = true;
                $completionmessages[] = get_string('mustcompletecourse', 'enrol_self', "<a href=\"{$CFG->wwwroot}/course/view.php?id={$source_course->id}\">{$source_course->fullname}</a>") ." before you can enter this course."; //REM BY WM 08.06.15
                //$completionmessages[] = get_string('mustcompletecourse', 'enrol_self', "<a href=\"{$CFG->wwwroot}/course/view.php?id={$source_course->id}\">{$source_course->fullname}</a>") ." ". get_string('beforeyoucanenter', 'core_coursecondition', $course->fullname);
            }elseif (!empty($ce->grademin) && $grade->finalgrade < $ce->grademin) {
                $hasoutstanding = true;
                $completionmessages[] = get_string('mustcompletecourse', 'enrol_self', "<a href=\"{$CFG->wwwroot}/course/view.php?id={$source_course->id}\">{$source_course->fullname}</a>").
                //.get_string('grademorethan', 'enrol_self')." ".(int)$ce->grademin." "//REM BY WM 08.06.15
                 //.get_string('beforeyoucanenter', 'enrol_self', $course->fullname);//REM BY WM 08.06.15
                 "before you can enter this course";
                //.". ".get_string('currentlyyourgradeis', 'enrol_self', (int)$grade->finalgrade).".";//REM BY WM 08.06.15
            }elseif (!empty($ce->grademax) && $grade->finalgrade > $ce->grademax) {
                $hasoutstanding = true;
                $completionmessages[] = get_string('mustcompletecourse', 'enrol_self', "<a href=\"{$CFG->wwwroot}/course/view.php?id={$source_course->id}\">{$source_course->fullname}</a>") . " "                                                                                                                                  
                .get_string('gradebetween', 'enrol_self')." ".(int)$ce->grademin." "  
                .get_string('and', 'enrol_self')." ".(int)$ce->grademax." "
                .get_string('beforeyoucanenter', 'enrol_self', $course->fullname).". "
                .get_string('currentlyyourgradeis', 'enrol_self', $grade->finalgrade).".";
            }
        }
    }
    //prepare the report of requirements for the user
    if (sizeof($completionmessages) > 0) {
       $completionmessage = "<ul><li>".implode("</li><li>", $completionmessages)."</li></ul>";
    }

    if ($hasoutstanding) return $completionmessage;

    return false;
}
