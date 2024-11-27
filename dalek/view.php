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
 * View Cuestionario LLM instance
 *
 * @package    mod_cuestionariollm
 * @copyright  2024 GONZALO ROMERO <gonzalo.romeros@alumnos.upm.es>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__.'/../../config.php');
require_once(__DIR__.'/lib.php');

// Course module id.
$id = optional_param('id', 0, PARAM_INT);

// Activity instance id.
$c = optional_param('c', 0, PARAM_INT);

if ($id) {
    $cm = get_coursemodule_from_id('cuestionariollm', $id, 0, false, MUST_EXIST);
    $course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
    $moduleinstance = $DB->get_record('cuestionariollm', ['id' => $cm->instance], '*', MUST_EXIST);
} else {
    $moduleinstance = $DB->get_record('cuestionariollm', ['id' => $c], '*', MUST_EXIST);
    $course = $DB->get_record('course', ['id' => $moduleinstance->course], '*', MUST_EXIST);
    $cm = get_coursemodule_from_instance('cuestionariollm', $moduleinstance->id, $course->id, false, MUST_EXIST);
}

require_login($course, true, $cm);

\mod_cuestionariollm\event\course_module_viewed::create_from_record($moduleinstance, $cm, $course)->trigger();

$PAGE->set_url('/mod/cuestionariollm/view.php', ['id' => $cm->id]);
$PAGE->set_title(format_string($moduleinstance->name));
$PAGE->set_heading(format_string($course->fullname));
header('Content-Type: text/html; charset=utf-8');

// Indicar qué pdf se debe procesar y en qué ruta se encuentra
$path = 'D:/Uni/Master/TFM/';
$filename = 'Memoria_TFG.pdf';

$reportcontent = optional_param('reportcontent', '', PARAM_RAW);
$text = cuestionariollm_process_pdf($path . $filename);
if(!empty($text)) {
    $reportcontent = $text;
}

// Checks if the config contains an API key
$apikey = get_config('mod_cuestionariollm', 'apikey');
if (empty($apikey)) {
    echo $OUTPUT->header();
    echo $OUTPUT->notification(get_string('missingapikey', 'mod_cuestionariollm'), 'notifyproblem');
    echo $OUTPUT->footer();
    exit;
}

// Checks if the report content is available, being a text or a transcribed pdf
if ($reportcontent) {
    $questions = cuestionariollm_generate_questions($reportcontent);
    $formatted_questions = cuestionariollm_format_questions($questions);

    $quiz = create_moodle_quiz($cm, 'Cuestionario LLM - ' . $filename, $formatted_questions);

    echo $OUTPUT->header();
    echo $OUTPUT->notification('Cuestionario creado con éxito! <a href="'.$CFG->wwwroot.'/mod/quiz/view.php?id='.$quiz->cmid.'">Ir al cuestionario</a>', 'notifysuccess');
    echo $OUTPUT->footer();
} else {
    echo $OUTPUT->header();
    echo '<form method="post">';
    echo '<textarea name="reportcontent" rows="20" cols="80"></textarea>';
    echo '<br>';
    echo '<input type="submit" value="Enviar">';
    echo '</form>';
    echo $OUTPUT->footer();
}