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
 * Callback implementations for Cuestionario LLM
 *
 * Documentation: {@link https://moodledev.io/docs/apis/plugintypes/mod}
 *
 * @package    mod_cuestionariollm
 * @copyright  2024 GONZALO ROMERO <gonzalo.romeros@alumnos.upm.es>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
require_once($CFG->libdir.'/filelib.php');
require_once(__DIR__ . '/vendor/autoload.php');
require_once($CFG->dirroot . '/mod/quiz/lib.php');
require_once($CFG->dirroot . '/mod/quiz/locallib.php');

/**
 * List of features supported in module
 *
 * @param string $feature FEATURE_xx constant for requested feature
 * @return mixed True if module supports feature, false if not, null if doesn't know or string for the module purpose.
 */
function cuestionariollm_supports($feature) {
    switch ($feature) {
        case FEATURE_MOD_INTRO:
            return true;
        case FEATURE_SHOW_DESCRIPTION:
            return true;
        case FEATURE_BACKUP_MOODLE2:
            return true;
        case FEATURE_MOD_PURPOSE:
        return MOD_PURPOSE_CONTENT;
        default:
            return null;
    }
}

/**
 * Add Cuestionario LLM instance
 *
 * Given an object containing all the necessary data, (defined by the form in mod_form.php)
 * this function will create a new instance and return the id of the instance
 *
 * @param stdClass $moduleinstance form data
 * @param mod_cuestionariollm_mod_form $form the form
 * @return int new instance id
 */
function cuestionariollm_add_instance($moduleinstance, $form = null) {
    global $DB;

    $moduleinstance->timecreated = time();
    $moduleinstance->timemodified = time();

    $id = $DB->insert_record('cuestionariollm', $moduleinstance);
    $completiontimeexpected = !empty($moduleinstance->completionexpected) ? $moduleinstance->completionexpected : null;
    \core_completion\api::update_completion_date_event($moduleinstance->coursemodule,
        'cuestionariollm', $id, $completiontimeexpected);
    return $id;
}

/**
 * Updates an instance of the Cuestionario LLM in the database.
 *
 * Given an object containing all the necessary data (defined in mod_form.php),
 * this function will update an existing instance with new data.
 *
 * @param stdClass $moduleinstance An object from the form in mod_form.php
 * @param mod_cuestionariollm_mod_form $form The form
 * @return bool True if successful, false otherwis
 */
function cuestionariollm_update_instance($moduleinstance, $form = null) {
    global $DB;

    $moduleinstance->timemodified = time();
    $moduleinstance->id = $moduleinstance->instance;

    $DB->update_record('cuestionariollm', $moduleinstance);

    $completiontimeexpected = !empty($moduleinstance->completionexpected) ? $moduleinstance->completionexpected : null;
    \core_completion\api::update_completion_date_event($moduleinstance->coursemodule, 'cuestionariollm',
      $moduleinstance->id, $completiontimeexpected);

    return true;
}

/**
 * Removes an instance of the Cuestionario LLM from the database.
 *
 * @param int $id Id of the module instance
 * @return bool True if successful, false otherwise
 */
function cuestionariollm_delete_instance($id) {
    global $DB;

    $record = $DB->get_record('cuestionariollm', ['id' => $id]);
    if (!$record) {
        return false;
    }

    // Delete all calendar events.
    $events = $DB->get_records('event', ['modulename' => 'cuestionariollm', 'instance' => $record->id]);
    foreach ($events as $event) {
        calendar_event::load($event)->delete();
    }

    // Delete the instance.
    $DB->delete_records('cuestionariollm', ['id' => $id]);

    return true;
}


/**
 * Generate and store a Cuestionario LLM quiz.
 *
 * @param int $courseid Id of the course instance
 * @param int $userid Id of the user the report is from
 */
function generate_quiz($courseid, $userid) {
    global $DB;

    // Obtener el pathnamehash del PDF desde la base de datos (ajusta según tu estructura de DB)
    $pdf_record = $DB->get_record('files', array('userid' => $userid, 'component' => 'assignsubmission_file', 'mimetype' => 'application/pdf'));

    if (!$pdf_record) {
        throw new Exception('No PDF found for the user.');
    }

    $pdf_content = get_pdf_content($pdf_record->pathnamehash);

    if ($pdf_content) {
        $questions = generate_quiz_questions($pdf_content);
        $quiz = new stdClass();
        $quiz->course = $courseid;
        $quiz->userid = $userid;
        $quiz->questions = json_encode($questions);
        $quiz->timecreated = time();

        $DB->insert_record('cuestionariollm_questions', $quiz);
    } else {
        throw new Exception('Failed to extract content from PDF. Content hash: ' . $pdf_record->contenthash);
    }
}


/**
 * Gets the pdf content of the report from the database.
 *
 * @param string $pathnamehash Hash of the student report
 * @return bool True if successful, false otherwise
 */
function get_pdf_content($pathnamehash) {
    global $CFG;
    $file_storage = get_file_storage();
    $file = $file_storage->get_file_by_hash($pathnamehash);
    
    if ($file) {
        $file_path = $file->get_content();
        return cuestionariollm_process_pdf($file_path); 
    }
      
    return false;
}


/**
 * Gets the pdf text of the report from the path.
 *
 * @param string $pdf_path Path of the student report pdf
 * @return string $text Text content of the pdf
 */
function cuestionariollm_process_pdf($pdf_path) {
    $parser = new \Smalot\PdfParser\Parser();
    $pdf = $parser->parseFile($pdf_path);
    $text = $pdf->getText();
    return $text;
}


/**
 * Makes the request to the OpenAI API.
 *
 * @param string $text Text content of the pdf
 * @return string $content Response of the OpenAI API
 */
function cuestionariollm_generate_questions($text) {
    global $CFG;
    $apikey = get_config('mod_cuestionariollm', 'apikey');

    if (empty($apikey)) {
        throw new moodle_exception('missingapikey', 'mod_cuestionariollm');
    }
    
    $client = OpenAI::client($apikey);
    $response = $client->chat()->create([
        'model' => 'gpt-3.5-turbo-0125',
        'messages' => [
            ['role' => 'system', 'content' => 'You will be provided with a text to generate 10 questions based on its content. Each question must have 3 answer options with only one correct answer, the correct answer must be marked in brackets as correct with "ok". The questions must be separated by two line breaks and the question text and its options must be on separate lines. Please ensure that the language of the responses corresponds to the language of the provided text.  The structure must be as follows:
            
            1. Question text
            a) Option 1
            b) Option 2
            c) Option 3 (ok)

            2. Question text
            a) Option 1
            b) Option 2 (ok)
            c) Option 3

            3. Question text
            a) Option 1 (ok)
            b) Option 2
            c) Option 3

            and so on...'],
            ['role' => 'user', 'content' => 'Generate the 10 questions based on the following content: ' . $text],
        ],
        'max_tokens' => 2500,
        'stop' => null,
    ]);

    $response->usage->promptTokens;
    $response->usage->completionTokens;
    $response->usage->totalTokens;

    return $response->choices[0]->message->content;
}


/**
 * Trys to find regex patterns in the response to section and format the questions.
 *
 * @param string $content Text content of the pdf
 * @return string $formatted_questions Structurated array of the questions
 */
function cuestionariollm_format_questions($content) {
    $questions = explode("\n\n", $content);
    $formatted_questions = [];

    foreach ($questions as $question) {
        if (preg_match('/^(\d+\. )(.*?)\n(a\) .*?)\(ok\)\n(b\) .*?)\n(c\) .*?)$/xms', $question, $matches)) {
            $formatted_questions[] = [
                'question' => format_text($matches[2], FORMAT_HTML),
                'options' => [
                    'a' => format_text($matches[3], FORMAT_HTML),
                    'b' => format_text($matches[4], FORMAT_HTML),
                    'c' => format_text($matches[5], FORMAT_HTML),
                ],
                'correct' => 'a'
            ];
        } elseif (preg_match('/^(\d+\. )(.*?)\na\) (.*?)\nb\) (.*?)\(ok\)\nc\) (.*?)$/xms', $question, $matches)) {
            $formatted_questions[] = [
                'question' => format_text($matches[2], FORMAT_HTML),
                'options' => [
                    'a' => format_text($matches[3], FORMAT_HTML),
                    'b' => format_text($matches[4], FORMAT_HTML),
                    'c' => format_text($matches[5], FORMAT_HTML),
                ],
                'correct' => 'b'
            ];
        } elseif (preg_match('/^(\d+\. )(.*?)\na\) (.*?)\nb\) (.*?)\nc\) (.*?)\(ok\)$/xms', $question, $matches)) {
            $formatted_questions[] = [
                'question' => format_text($matches[2], FORMAT_HTML),
                'options' => [
                    'a' => format_text($matches[3], FORMAT_HTML),
                    'b' => format_text($matches[4], FORMAT_HTML),
                    'c' => format_text($matches[5], FORMAT_HTML),
                ],
                'correct' => 'c'
            ];
        } else {
            // Handle case where pattern doesn't match expected format
            $formatted_questions[] = [
                'question' => format_text('Question format error', FORMAT_HTML),
                'options' => [
                    'a' => format_text('Error format', FORMAT_HTML),
                    'b' => format_text('Error match', FORMAT_HTML),
                    'c' => format_text('Error answer', FORMAT_HTML),
                ],
                'correct' => 'a'
            ];
        }
    }

    return $formatted_questions;
}


/**
 * Create the quiz object and stores it in the database.
 *
 * @param mixed $cm Course module where the LLMQuiz is
 * @param string $quizname Name of the quiz to be generated
 * @param string $questions Structurated array of the questions
 * @return stdClass $quiz Quiz object
 */
function create_moodle_quiz($cm, $quizname, $questions) {
    global $DB, $USER;

    // Create the quiz instance
    $quiz = new stdClass();
    $quiz->course = $cm->course;
    $quiz->name = $quizname;
    $quiz->intro = '';
    $quiz->introformat = FORMAT_HTML;
    $quiz->timeopen = 0;
    $quiz->timeclose = 0;
    $quiz->timelimit = 0;
    $quiz->overduehandling = 'autosubmit';
    $quiz->graceperiod = 0;
    $quiz->preferredbehaviour = 'deferredfeedback';
    $quiz->attempts = 0;
    $quiz->attemptonlast = 0;
    $quiz->grademethod = 1;
    $quiz->decimalpoints = 2;
    $quiz->questiondecimalpoints = -1;
    $quiz->reviewattempt = 1;
    $quiz->reviewcorrectness = 1;
    $quiz->reviewmarks = 1;
    $quiz->reviewoverallfeedback = 1;
    $quiz->questionsperpage = 10;
    $quiz->shufflequestions = 1;
    $quiz->shuffleanswers = 1;
    $quiz->sumgrades = 10;
    $quiz->grade = 10;
    $quiz->timecreated = time();
    $quiz->timemodified = time();
    $quiz->id = $DB->insert_record('quiz', $quiz);
    
    // Create the quiz section instance
    $quizsection = new stdClass();
    $quizsection->quizid = $quiz->id;
    $quizsection->firstslot = 1;
    $quizsection->heading = '';
    $quizsection->shufflequestions = $quiz->shufflequestions;
    $quizsection->id = $DB->insert_record('quiz_sections', $quizsection);

    // Create the course module for the quiz
    $newcm = new stdClass();
    $newcm->course = $cm->course;
    $newcm->module = $DB->get_field('modules', 'id', ['name' => 'quiz']);
    $newcm->instance = $quiz->id;
    $newcm->section = $cm->section;
    $newcm->visible = 1;
    $newcm->idnumber = '';
    $newcm->added = $cm->added;
    $newcm->id = $DB->insert_record('course_modules', $newcm);

    $quiz->cmid = $newcm->id;

    // Add the course module to the course sections
    course_add_cm_to_section($newcm->course, $newcm->id, $newcm->section);

    // Get the context ID for the course module
    $contextid = context_module::instance($quiz->cmid)->id;

    // Create the question category
    $category = new stdClass();
    $category->courseid = $cm->course->id;
    $category->contextid = $contextid;
    $category->name = 'LLM Quiz Category';
    $category->info = '';
    $category->infoformat = FORMAT_HTML;
    $category->parent = 0;
    $category->sortorder = 500;
    $category->stamp = make_unique_id_code();
    $category->id = $DB->insert_record('question_categories', $category);


    // Create a quiz slot for each question
    foreach ($questions as $question) {
        create_quiz_question($quiz, $category->id, $question);
    }

    return $quiz;
}


/**
 * Creates the object of a question and stores it in the database.
 *
 * @param mixed $quiz Quiz object
 * @param string $categoryid Id of the category of the question
 * @param string $questiondata Structurated array with one questions and its awnsers
 */
function create_quiz_question($quiz, $categoryid, $questiondata) {
    global $DB, $USER;

    // Create the question
    $question = new stdClass();
    $question->category = $categoryid;
    $question->name = substr($questiondata['question'], 0, 255);
    $question->questiontext = $questiondata['question'];
    $question->questiontextformat = FORMAT_HTML;
    $question->generalfeedback = '';
    $question->generalfeedbackformat = FORMAT_HTML;
    $question->defaultmark = 1;
    $question->penalty = 0.2;
    $question->qtype = 'multichoice';
    $question->length = 1;
    $question->stamp = make_unique_id_code();
    $question->version = make_unique_id_code();
    $question->id = $DB->insert_record('question', $question);

    // Crear la entrada en question_bank_entries
    $bankentry = new stdClass();
    $bankentry->questioncategoryid = $categoryid;
    $bankentry->idnumber = $question->id; // Optional should be '' as default
    $bankentry->ownerid = $USER->id;
    $bankentry->id = $DB->insert_record('question_bank_entries', $bankentry);

    // Crear la versión en la tabla 'question_versions'
    $version = new stdClass();
    $version->questionbankentryid = $bankentry->id; 
    $version->version = 2;
    $version->questionid = $question->id;
    $version->status = 1000;
    $DB->insert_record('question_versions', $version);

    // Create the answers
    $answers = $questiondata['options'];
    foreach ($answers as $key => $answertext) {
        $answer = new stdClass();
        $answer->question = $question->id;
        $answer->answer = $answertext;
        $answer->answerformat = FORMAT_HTML;
        $answer->fraction = ($key == $questiondata['correct']) ? 1.0 : 0.0;
        $answer->feedback = '';
        $answer->feedbackformat = FORMAT_HTML;
        $answer->id = $DB->insert_record('question_answers', $answer);
    }

    // Create the multichoice question options
    $multichoice = new stdClass();
    $multichoice->questionid = $question->id;
    $multichoice->layout = 0;
    $multichoice->single = 1;
    $multichoice->shuffleanswers = 1;
    $multichoice->correctfeedback = 'La respuesta que has seleccionado es correcta.';
    $multichoice->correctfeedbackformat = FORMAT_HTML;
    $multichoice->partiallycorrectfeedback = 'La respuesta que has seleccionado es parcialmente correcta.';
    $multichoice->partiallycorrectfeedbackformat = FORMAT_HTML;
    $multichoice->incorrectfeedback = 'La respuesta que has seleccionado es incorrecta.';
    $multichoice->incorrectfeedbackformat = FORMAT_HTML;
    $multichoice->answernumbering = 'abc';
    $multichoice->id = $DB->insert_record('qtype_multichoice_options', $multichoice);

    // Add the question to the quiz
    quiz_add_quiz_question($question->id, $quiz);
}
