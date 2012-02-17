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
 * Question type class for the multi-answer question type.
 *
 * @package    qtype
 * @subpackage multianswerbu
 * @copyright  1999 onwards Martin Dougiamas {@link http://moodle.com}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * This edition of the multi-answer quesiton has been hacked for use with BU's question types.
 * Use by other universities is not reccomended.
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/question/type/multichoice/question.php');
require_once($CFG->dirroot . '/question/type/scripted/question.php');
require_once($CFG->dirroot . '/question/type/scripted/questiontype.php');


/**
 * The multi-answer question type class.
 *
 * @copyright  1999 onwards Martin Dougiamas  {@link http://moodle.com}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class qtype_multianswerbu extends question_type {

    public function can_analyse_responses() 
    {
        return false;
    }

    public function get_question_options($question) 
    {
        global $DB, $OUTPUT;

        //get the sequence information and initialization code from the DB
        $record = $DB->get_record('question_multianswerbu', array('question' => $question->id), 'sequence,init_code', MUST_EXIST);

        //and get each of the wrapped question objects
        $wrappedquestions = $DB->get_records_list('question', 'id', explode(',', $record->sequence), 'id ASC');

        // We want an array with question ids as index and the positions as values
        //parse the sequence into an array of question IDs, then flip the keys and values, so we wind up with a relation of question_id => position 
        $sequence = array_flip(explode(',', $record->sequence));

        
        //The following used to read: array_walk($sequence, create_function('&$val', '$val++;'));
        //According to some basic research, array_walk is _much_ slower [http://willem.stuursma.name/2010/11/22/a-detailed-look-into-array_map-and-foreach/] 
        //and the foreach is much more readable, anyway

        //convert the positions in the above array from 0-indexed to 1-indexed
        foreach($sequence as &$num)
            ++$num;

        // If a question is lost, the corresponding index is null
        // so this null convention is used to test $question->options->questions
        // before using the values.
        // first all possible questions from sequence are nulled
        // then filled with the data if available in  $wrappedquestions
        foreach ($sequence as $seq) 
            $question->options->questions[$seq] = '';


        //restore each of the wrapped questions
        foreach ($wrappedquestions as $wrapped) 
        {
            //ensure that the relevant question type is loaded
            question_bank::get_qtype($wrapped->qtype)->get_question_options($wrapped);

            // for wrapped questions the maxgrade is always equal to the defaultmark,
            // there is no entry in the question_instances table for them
            $wrapped->maxmark = $wrapped->defaultmark;
            $question->options->questions[$sequence[$wrapped->id]] = $wrapped;
        }

        //restore the initialization script
        $question->init_code = $record->init_code;

        //restore the question hints
        $question->hints = $DB->get_records('question_hints', array('questionid' => $question->id), 'id ASC');

        return true;
    }

    public function save_question_options($question) 
    {
        global $DB;
        
        
        $result = new stdClass();

        // This function needs to be able to handle the case where the existing set of wrapped
        // questions does not match the new set of wrapped questions so that some need to be
        // created, some modified and some deleted
        // Unfortunately the code currently simply overwrites existing ones in sequence. This
        // will make re-marking after a re-ordering of wrapped questions impossible and
        // will also create difficulties if questiontype specific tables reference the id.

        //if no wrapped questions exist, start with an empty array
        if (!$oldwrappedids = $DB->get_field('question_multianswerbu', 'sequence', array('question' => $question->id))) 
            $oldwrappedquestions = array();
        //otherwise, get the wrapped questions
        else 
            $oldwrappedquestions = $DB->get_records_list('question', 'id', explode(',', $oldwrappedids), 'id ASC');
        

        $sequence = array();

        foreach ($question->options->questions as $wrapped) 
        {
            if (!empty($wrapped)) 
            {
                // if we still have some old wrapped question ids, reuse the next of them

                if (is_array($oldwrappedquestions) && $oldwrappedquestion = array_shift($oldwrappedquestions)) 
                {
                    $wrapped->id = $oldwrappedquestion->id;
                    if ($oldwrappedquestion->qtype != $wrapped->qtype) 
                    {
                        switch ($oldwrappedquestion->qtype) 
                        {
                            case 'multichoice':
                                $DB->delete_records('question_multichoice',
                                        array('question' => $oldwrappedquestion->id));
                                break;
                            case 'shortanswer':
                                $DB->delete_records('question_shortanswer',
                                        array('question' => $oldwrappedquestion->id));
                                break;
			    
 		            case 'scripted':
                                $DB->delete_records('question_scripted',
                                        array('question' => $oldwrappedquestion->id));
                                break;


                            case 'numerical':
                                $DB->delete_records('question_numerical',
                                        array('question' => $oldwrappedquestion->id));
                                break;
                            default:
                                throw new moodle_exception('qtypenotrecognized',
                                        'qtype_multianswerbu', '', $oldwrappedquestion->qtype);
                                $wrapped->id = 0;
                        }
                    }
                } else {
                    $wrapped->id = 0;
                }
            }
            $wrapped->name = $question->name;
            $wrapped->parent = $question->id;
            $previousid = $wrapped->id;

            // save_question strips this extra bit off the category again.
            $wrapped->category = $question->category . ',1';
            $wrapped = question_bank::get_qtype($wrapped->qtype)->save_question(
                    $wrapped, clone($wrapped));
            $sequence[] = $wrapped->id;
            if ($previousid != 0 && $previousid != $wrapped->id) {
                // for some reasons a new question has been created
                // so delete the old one
                question_delete_question($previousid);
            }
        }

        // Delete redundant wrapped questions
        if (is_array($oldwrappedquestions) && count($oldwrappedquestions)) {
            foreach ($oldwrappedquestions as $oldwrappedquestion) {
                question_delete_question($oldwrappedquestion->id);
            }
        }

        //if we have questions to save
        if (!empty($sequence)) 
        {
            $multianswerbu = new stdClass();
            $multianswerbu->question = $question->id;
            $multianswerbu->sequence = implode(',', $sequence);
            $multianswerbu->init_code = $question->init_code;

            //if the record exists in the database, update the record instead of creating a new one
            if ($oldid = $DB->get_field('question_multianswerbu', 'id', array('question' => $question->id))) 
            {
                $multianswerbu->id = $oldid;
                $DB->update_record('question_multianswerbu', $multianswerbu);

            } 
            //otherwise, create a new record
            else 
            {
                $DB->insert_record('question_multianswerbu', $multianswerbu);
            }
        }

        $this->save_hints($question);
    }

    public function save_question($authorizedquestion, $form) {
        $question = qtype_multianswerbu_extract_question($form->questiontext);
        if (isset($authorizedquestion->id)) {
            $question->id = $authorizedquestion->id;
        }

        $question->category = $authorizedquestion->category;
        $form->defaultmark = $question->defaultmark;
        $form->questiontext = $question->questiontext;
        $form->questiontextformat = 0;
        $form->options = clone($question->options);
        
        unset($question->options);
        
        return parent::save_question($question, $form);


    }

    public function delete_question($questionid, $contextid) {
        global $DB;
        $DB->delete_records('question_multianswerbu', array('question' => $questionid));

        parent::delete_question($questionid, $contextid);
    }

    protected function initialise_question_instance($question, $questiondata) 
    {
        parent::initialise_question_instance($question, $questiondata);

        $bits = preg_split('/\{#(\d+)\}/', $question->questiontext, null, PREG_SPLIT_DELIM_CAPTURE);

        $question->textfragments[0] = array_shift($bits);
        $i = 1;

        while (!empty($bits)) 
        {
            $question->places[$i] = array_shift($bits);
            $question->textfragments[$i] = array_shift($bits);
            $i += 1;
        }

        //load the question's initialization code
        $question->init_code = $questiondata->init_code;

        //for each of the questions
        foreach ($questiondata->options->questions as $key => $subqdata) 
        {
            $subqdata->contextid = $questiondata->contextid;

            $question->subquestions[$key] = question_bank::make_question($subqdata);
            $question->subquestions[$key]->maxmark = $subqdata->defaultmark;

            //if the subquesiton has a layout parameter set, respect it
            if (isset($subqdata->options->layout)) 
                $question->subquestions[$key]->layout = $subqdata->options->layout;

        }
    }

    public function get_random_guess_score($questiondata) {
        $fractionsum = 0;
        $fractionmax = 0;
        foreach ($questiondata->options->questions as $key => $subqdata) {
            $fractionmax += $subqdata->defaultmark;
            $fractionsum += question_bank::get_qtype(
                    $subqdata->qtype)->get_random_guess_score($subqdata);
        }
        return $fractionsum / $fractionmax;
    }
}


// BU_ANSWER_ALTERNATIVE regexes
define('BU_ANSWER_ALTERNATIVE_FRACTION_REGEX',
       '=|%(-?[0-9]+)%');
// for the syntax '(?<!' see http://www.perl.com/doc/manual/html/pod/perlre.html#item_C
define('BU_ANSWER_ALTERNATIVE_BU_ANSWER_REGEX',
        '.+?(?<!\\\\|&|&amp;)(?=[~#}]|$)');
define('BU_ANSWER_ALTERNATIVE_FEEDBACK_REGEX',
        '.*?(?<!\\\\)(?=[~}]|$)');
define('BU_ANSWER_ALTERNATIVE_REGEX',
       '(' . BU_ANSWER_ALTERNATIVE_FRACTION_REGEX .')?' .
       '(' . BU_ANSWER_ALTERNATIVE_BU_ANSWER_REGEX . ')' .
       '(#(' . BU_ANSWER_ALTERNATIVE_FEEDBACK_REGEX .'))?');

// Parenthesis positions for BU_ANSWER_ALTERNATIVE_REGEX
define('BU_ANSWER_ALTERNATIVE_REGEX_PERCENTILE_FRACTION', 2);
define('BU_ANSWER_ALTERNATIVE_REGEX_FRACTION', 1);
define('BU_ANSWER_ALTERNATIVE_REGEX_ANSWER', 3);
define('BU_ANSWER_ALTERNATIVE_REGEX_FEEDBACK', 5);

// BU_NUMBER_FORMATED_ALTERNATIVE_BU_ANSWER_REGEX is used
// for identifying numerical answers in BU_ANSWER_ALTERNATIVE_REGEX_ANSWER
define('BU_NUMBER_REGEX',
        '-?(([0-9]+[.,]?[0-9]*|[.,][0-9]+)([eE][-+]?[0-9]+)?)');
define('BU_NUMERICAL_ALTERNATIVE_REGEX',
        '^(' . BU_NUMBER_REGEX . ')(:' . BU_NUMBER_REGEX . ')?$');

// Parenthesis positions for BU_NUMERICAL_FORMATED_ALTERNATIVE_BU_ANSWER_REGEX
define('BU_NUMERICAL_CORRECT_ANSWER', 1);
define('BU_NUMERICAL_ABS_ERROR_MARGIN', 6);

// Remaining ANSWER regexes
define('BU_ANSWER_TYPE_DEF_REGEX',
        '(NUMERICAL|NM)|(MULTICHOICE|MC)|(MULTICHOICE_V|MCV)|(MULTICHOICE_H|MCH)|' .
        '(SHORTANSWER|SA|MW)|(SHORTBU_ANSWER_C|SAC|MWC)|' .
        '(SCRIPTED|SC)|(SCRIPTED_C|SCC)|(SCRIPTED_DEC|SN|SDEC)|(SCRIPTED_HEX|SHEX)|(SCRIPTED_OCT|SOCT)|(SCRIPTED_BIN|SBIN)|(MULTICHOICE_SHUFFLE|MCS)'
    
    );


define('BU_ANSWER_START_REGEX',
       '\{([0-9]*):(' . BU_ANSWER_TYPE_DEF_REGEX . '):');

define('BU_ANSWER_REGEX',
        BU_ANSWER_START_REGEX
        . '(' . BU_ANSWER_ALTERNATIVE_REGEX
        . '(~'
        . BU_ANSWER_ALTERNATIVE_REGEX
        . ')*)\}');

// Parenthesis positions for singulars in BU_ANSWER_REGEX
define('BU_ANSWER_REGEX_NORM', 1);
define('BU_ANSWER_REGEX_BU_ANSWER_TYPE_NUMERICAL', 3);
define('BU_ANSWER_REGEX_BU_ANSWER_TYPE_MULTICHOICE', 4);
define('BU_ANSWER_REGEX_BU_ANSWER_TYPE_MULTICHOICE_REGULAR', 5);
define('BU_ANSWER_REGEX_BU_ANSWER_TYPE_MULTICHOICE_HORIZONTAL', 6);
define('BU_ANSWER_REGEX_BU_ANSWER_TYPE_SHORTANSWER', 7);
define('BU_ANSWER_REGEX_BU_ANSWER_TYPE_SHORTBU_ANSWER_C', 8);
define('BU_ANSWER_REGEX_BU_ANSWER_TYPE_SCRIPTED', 9);
define('BU_ANSWER_REGEX_BU_ANSWER_TYPE_SCRIPTED_CASE', 10);
define('BU_ANSWER_REGEX_BU_ANSWER_TYPE_SCRIPTED_DEC', 11);
define('BU_ANSWER_REGEX_BU_ANSWER_TYPE_SCRIPTED_HEX', 12);
define('BU_ANSWER_REGEX_BU_ANSWER_TYPE_SCRIPTED_OCT', 13);
define('BU_ANSWER_REGEX_BU_ANSWER_TYPE_SCRIPTED_BIN', 14);
define('BU_ANSWER_REGEX_BU_ANSWER_TYPE_MULTICHOICE_SHUFFLE', 15);
define('BU_ANSWER_REGEX_ALTERNATIVES', 16);

function qtype_multianswerbu_extract_question($text) 
{

    // $text is an array [text][format][itemid]
    $question = new stdClass();
    $question->qtype = 'multianswerbu';
    $question->questiontext = $text;
    $question->generalfeedback['text'] = '';
    $question->generalfeedback['format'] = FORMAT_HTML;
    $question->generalfeedback['itemid'] = '';

    $question->options->questions = array();
    $question->defaultmark = 0; // Will be increased for each answer norm

    //for each question in the 
    for ($positionkey = 1; preg_match('/'.BU_ANSWER_REGEX.'/', $question->questiontext['text'], $answerregs); ++$positionkey)
    {

        $wrapped = new stdClass();
        $wrapped->generalfeedback['text'] = '';
        $wrapped->generalfeedback['format'] = FORMAT_HTML;
        $wrapped->generalfeedback['itemid'] = '';

        if (isset($answerregs[BU_ANSWER_REGEX_NORM])&& $answerregs[BU_ANSWER_REGEX_NORM]!== '') 
        {
            $wrapped->defaultmark = $answerregs[BU_ANSWER_REGEX_NORM];
        }
        else
        {
            $wrapped->defaultmark = '1';
        }

        if (!empty($answerregs[BU_ANSWER_REGEX_BU_ANSWER_TYPE_NUMERICAL])) 
        {
            $wrapped->qtype = 'numerical';
            $wrapped->multiplier = array();
            $wrapped->units      = array();
            $wrapped->instructions['text'] = '';
            $wrapped->instructions['format'] = FORMAT_HTML;
            $wrapped->instructions['itemid'] = '';
        } 
        else if (!empty($answerregs[BU_ANSWER_REGEX_BU_ANSWER_TYPE_SHORTANSWER])) 
        {
            $wrapped->qtype = 'shortanswer';
            $wrapped->usecase = 0;
        }
        else if (!empty($answerregs[BU_ANSWER_REGEX_BU_ANSWER_TYPE_SHORTBU_ANSWER_C])) 
        {
            $wrapped->qtype = 'shortanswer';
            $wrapped->usecase = 1;
        } 
        else if (!empty($answerregs[BU_ANSWER_REGEX_BU_ANSWER_TYPE_SCRIPTED])) 
        {
            $wrapped->qtype = 'scripted';
            $wrapped->response_mode = qtype_scripted_response_mode::MODE_STRING;
            $wrapped->answer_mode = qtype_scripted_answer_mode::MODE_MUST_EQUAL;
            $wrapped->init_code = '';
        } 
        else if (!empty($answerregs[BU_ANSWER_REGEX_BU_ANSWER_TYPE_SCRIPTED_CASE])) 
        {
            $wrapped->qtype = 'scripted';
            $wrapped->response_mode = qtype_scripted_response_mode::MODE_STRING_CASE_SENSITIVE;
            $wrapped->answer_mode = qtype_scripted_answer_mode::MODE_MUST_EQUAL;
            $wrapped->init_code = '';
        } 
        else if (!empty($answerregs[BU_ANSWER_REGEX_BU_ANSWER_TYPE_SCRIPTED_DEC])) 
        {
            $wrapped->qtype = 'scripted';
            $wrapped->response_mode = qtype_scripted_response_mode::MODE_NUMERIC;
            $wrapped->answer_mode = qtype_scripted_answer_mode::MODE_MUST_EQUAL;
            $wrapped->init_code = '';
        } 
        else if (!empty($answerregs[BU_ANSWER_REGEX_BU_ANSWER_TYPE_SCRIPTED_HEX])) 
        {
            $wrapped->qtype = 'scripted';
            $wrapped->response_mode = qtype_scripted_response_mode::MODE_HEXADECIMAL;
            $wrapped->answer_mode = qtype_scripted_answer_mode::MODE_MUST_EQUAL;
            $wrapped->init_code = '';
        } 
        else if (!empty($answerregs[BU_ANSWER_REGEX_BU_ANSWER_TYPE_SCRIPTED_BIN])) 
        {
            $wrapped->qtype = 'scripted';
            $wrapped->response_mode = qtype_scripted_response_mode::MODE_BINARY;
            $wrapped->answer_mode = qtype_scripted_answer_mode::MODE_MUST_EQUAL;
            $wrapped->init_code = '';
        } 
        else if (!empty($answerregs[BU_ANSWER_REGEX_BU_ANSWER_TYPE_SCRIPTED_OCT])) 
        {
            $wrapped->qtype = 'scripted';
            $wrapped->response_mode = qtype_scripted_response_mode::MODE_OCTAL;
            $wrapped->answer_mode = qtype_scripted_answer_mode::MODE_MUST_EQUAL;
            $wrapped->init_code = '';
        } 
        else if (!empty($answerregs[BU_ANSWER_REGEX_BU_ANSWER_TYPE_MULTICHOICE])) 
        {
            $wrapped->qtype = 'multichoice';
            $wrapped->single = 1;
            $wrapped->shuffleanswers = 0;
            $wrapped->answernumbering = 0;
            $wrapped->correctfeedback['text'] = '';
            $wrapped->correctfeedback['format'] = FORMAT_HTML;
            $wrapped->correctfeedback['itemid'] = '';
            $wrapped->partiallycorrectfeedback['text'] = '';
            $wrapped->partiallycorrectfeedback['format'] = FORMAT_HTML;
            $wrapped->partiallycorrectfeedback['itemid'] = '';
            $wrapped->incorrectfeedback['text'] = '';
            $wrapped->incorrectfeedback['format'] = FORMAT_HTML;
            $wrapped->incorrectfeedback['itemid'] = '';
            $wrapped->layout = qtype_multichoice_base::LAYOUT_DROPDOWN;
        } 
        else if (!empty($answerregs[BU_ANSWER_REGEX_BU_ANSWER_TYPE_MULTICHOICE_SHUFFLE])) 
        {
            $wrapped->qtype = 'multichoice';
            $wrapped->single = 1;
            $wrapped->shuffleanswers = 1;
            $wrapped->answernumbering = 0;
            $wrapped->correctfeedback['text'] = '';
            $wrapped->correctfeedback['format'] = FORMAT_HTML;
            $wrapped->correctfeedback['itemid'] = '';
            $wrapped->partiallycorrectfeedback['text'] = '';
            $wrapped->partiallycorrectfeedback['format'] = FORMAT_HTML;
            $wrapped->partiallycorrectfeedback['itemid'] = '';
            $wrapped->incorrectfeedback['text'] = '';
            $wrapped->incorrectfeedback['format'] = FORMAT_HTML;
            $wrapped->incorrectfeedback['itemid'] = '';
            $wrapped->layout = qtype_multichoice_base::LAYOUT_DROPDOWN;
        } 
        else if (!empty($answerregs[BU_ANSWER_REGEX_BU_ANSWER_TYPE_MULTICHOICE_REGULAR])) 
        {
            $wrapped->qtype = 'multichoice';
            $wrapped->single = 1;
            $wrapped->shuffleanswers = 0;
            $wrapped->answernumbering = 0;
            $wrapped->correctfeedback['text'] = '';
            $wrapped->correctfeedback['format'] = FORMAT_HTML;
            $wrapped->correctfeedback['itemid'] = '';
            $wrapped->partiallycorrectfeedback['text'] = '';
            $wrapped->partiallycorrectfeedback['format'] = FORMAT_HTML;
            $wrapped->partiallycorrectfeedback['itemid'] = '';
            $wrapped->incorrectfeedback['text'] = '';
            $wrapped->incorrectfeedback['format'] = FORMAT_HTML;
            $wrapped->incorrectfeedback['itemid'] = '';
            $wrapped->layout = qtype_multichoice_base::LAYOUT_VERTICAL;
        } 
        else if (!empty($answerregs[BU_ANSWER_REGEX_BU_ANSWER_TYPE_MULTICHOICE_HORIZONTAL])) 
        {
            $wrapped->qtype = 'multichoice';
            $wrapped->single = 1;
            $wrapped->shuffleanswers = 0;
            $wrapped->answernumbering = 0;
            $wrapped->correctfeedback['text'] = '';
            $wrapped->correctfeedback['format'] = FORMAT_HTML;
            $wrapped->correctfeedback['itemid'] = '';
            $wrapped->partiallycorrectfeedback['text'] = '';
            $wrapped->partiallycorrectfeedback['format'] = FORMAT_HTML;
            $wrapped->partiallycorrectfeedback['itemid'] = '';
            $wrapped->incorrectfeedback['text'] = '';
            $wrapped->incorrectfeedback['format'] = FORMAT_HTML;
            $wrapped->incorrectfeedback['itemid'] = '';
            $wrapped->layout = qtype_multichoice_base::LAYOUT_HORIZONTAL;
        } 
        else
        {
            print_error('unknownquestiontype', 'question', '', $answerregs[2]);
            return false;
        }

        // Each $wrapped simulates a $form that can be processed by the
        // respective save_question and save_question_options methods of the
        // wrapped questiontypes
        $wrapped->answer   = array();
        $wrapped->fraction = array();
        $wrapped->feedback = array();
        $wrapped->questiontext['text'] = $answerregs[0];
        $wrapped->questiontext['format'] = FORMAT_HTML;
        $wrapped->questiontext['itemid'] = '';
        $answerindex = 0;

        $remainingalts = $answerregs[BU_ANSWER_REGEX_ALTERNATIVES];
        while (preg_match('/~?'.BU_ANSWER_ALTERNATIVE_REGEX.'/', $remainingalts, $altregs)) {
            if ('=' == $altregs[BU_ANSWER_ALTERNATIVE_REGEX_FRACTION]) {
                $wrapped->fraction["$answerindex"] = '1';
            } else if ($percentile = $altregs[BU_ANSWER_ALTERNATIVE_REGEX_PERCENTILE_FRACTION]) {
                $wrapped->fraction["$answerindex"] = .01 * $percentile;
            } else {
                $wrapped->fraction["$answerindex"] = '0';
            }
            if (isset($altregs[BU_ANSWER_ALTERNATIVE_REGEX_FEEDBACK])) {
                $feedback = html_entity_decode(
                        $altregs[BU_ANSWER_ALTERNATIVE_REGEX_FEEDBACK], ENT_QUOTES, 'UTF-8');
                $feedback = str_replace('\}', '}', $feedback);
                $wrapped->feedback["$answerindex"]['text'] = str_replace('\#', '#', $feedback);
                $wrapped->feedback["$answerindex"]['format'] = FORMAT_HTML;
                $wrapped->feedback["$answerindex"]['itemid'] = '';
            } else {
                $wrapped->feedback["$answerindex"]['text'] = '';
                $wrapped->feedback["$answerindex"]['format'] = FORMAT_HTML;
                $wrapped->feedback["$answerindex"]['itemid'] = '';

            }
            if (!empty($answerregs[BU_ANSWER_REGEX_BU_ANSWER_TYPE_NUMERICAL])
                    && preg_match('~'.BU_NUMERICAL_ALTERNATIVE_REGEX.'~',
                            $altregs[BU_ANSWER_ALTERNATIVE_REGEX_ANSWER], $numregs)) {
                $wrapped->answer[] = $numregs[BU_NUMERICAL_CORRECT_ANSWER];
                if (array_key_exists(BU_NUMERICAL_ABS_ERROR_MARGIN, $numregs)) {
                    $wrapped->tolerance["$answerindex"] =
                    $numregs[BU_NUMERICAL_ABS_ERROR_MARGIN];
                } else {
                    $wrapped->tolerance["$answerindex"] = 0;
                }
            } else { // Tolerance can stay undefined for non numerical questions
                // Undo quoting done by the HTML editor.
                $answer = html_entity_decode(
                        $altregs[BU_ANSWER_ALTERNATIVE_REGEX_ANSWER], ENT_QUOTES, 'UTF-8');
                $answer = str_replace('\}', '}', $answer);
                $wrapped->answer["$answerindex"] = str_replace('\#', '#', $answer);
                if ($wrapped->qtype == 'multichoice') {
                    $wrapped->answer["$answerindex"] = array(
                            'text' => $wrapped->answer["$answerindex"],
                            'format' => FORMAT_HTML,
                            'itemid' => '');
                }
            }
            $tmp = explode($altregs[0], $remainingalts, 2);
            $remainingalts = $tmp[1];
            $answerindex++;
        }

        $question->defaultmark += $wrapped->defaultmark;
        $question->options->questions[$positionkey] = clone($wrapped);
        $question->questiontext['text'] = implode("{#$positionkey}",
                    explode($answerregs[0], $question->questiontext['text'], 2));
    }
    return $question;
}
