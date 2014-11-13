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
 * Code for exporting questions as Moodle XML.
 *
 * @package    qformat_xml
 * @copyright  1999 onwards Martin Dougiamas {@link http://moodle.com}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/xmlize.php');
if (!class_exists('qformat_default')) {
    // This is ugly, but this class is also (ab)used by mod/lesson, which defines
    // a different base class in mod/lesson/format.php. Thefore, we can only
    // include the proper base class conditionally like this. (We have to include
    // the base class like this, otherwise it breaks third-party question types.)
    // This may be reviewd, and a better fix found one day.
    require_once($CFG->dirroot . '/question/format.php');

}
    require_once($CFG->dirroot . '/question/format/xml/format.php');

/**
 * Importer for Moodle XML question format.
 *
 * See http://docs.moodle.org/en/Moodle_XML_format for a description of the format.
 *
 * @copyright  1999 onwards Martin Dougiamas {@link http://moodle.com}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class qformat_gapfill_to_cloze extends qformat_xml {

  
    /**
     * Turns question into an xml segment
     * @param object $question the question data.
     * @return string xml segment
     */
    public function writequestion($question) {
        global $CFG, $OUTPUT;

        $invalidquestion = false;
        $fs = get_file_storage();
        $contextid = $question->contextid;
        // Get files used by the questiontext.
        $question->questiontextfiles = $fs->get_area_files(
                $contextid, 'question', 'questiontext', $question->id);
        // Get files used by the generalfeedback.
        $question->generalfeedbackfiles = $fs->get_area_files(
                $contextid, 'question', 'generalfeedback', $question->id);
        if (!empty($question->options->answers)) {
            foreach ($question->options->answers as $answer) {
                $answer->answerfiles = $fs->get_area_files(
                        $contextid, 'question', 'answer', $answer->id);
                $answer->feedbackfiles = $fs->get_area_files(
                        $contextid, 'question', 'answerfeedback', $answer->id);
            }
        }

        $expout = '';

        // Add a comment linking this to the original question id.
        $expout .= "<!-- question: {$question->id}  -->\n";

        // Check question type.
        $questiontype = $this->get_qtype($question->qtype);

        // Categories are a special case.
        if ($question->qtype == 'category') {
            $categorypath = $this->writetext($question->category);
            $expout .= "  <question type=\"category\">\n";
            $expout .= "    <category>\n";
            $expout .= "        {$categorypath}\n";
            $expout .= "    </category>\n";
            $expout .= "  </question>\n";
            return $expout;
        }
        if ($questiontype == 'gapfill') {
            $questiontype = 'multianswer';
            $question = $this->convert_to_cloze($question);
            $question->qtype = 'multianswer';
        }
        // Now we know we are are handing a real question.
        // Output the generic information.
        $expout .= "  <question type=\"{$questiontype}\">\n";
        $expout .= "    <name>\n";
        $expout .= $this->writetext($question->name, 3);
        $expout .= "    </name>\n";
        $expout .= "    <questiontext {$this->format($question->questiontextformat)}>\n";
        $expout .= $this->writetext($question->questiontext, 3);
        $expout .= $this->write_files($question->questiontextfiles);
        $expout .= "    </questiontext>\n";
        $expout .= "    <generalfeedback {$this->format($question->generalfeedbackformat)}>\n";
        $expout .= $this->writetext($question->generalfeedback, 3);
        $expout .= $this->write_files($question->generalfeedbackfiles);
        $expout .= "    </generalfeedback>\n";
        if ($question->qtype != 'multianswer') {
            $expout .= "    <defaultgrade>{$question->defaultmark}</defaultgrade>\n";
        }
        $expout .= "    <penalty>{$question->penalty}</penalty>\n";
        $expout .= "    <hidden>{$question->hidden}</hidden>\n";

        // The rest of the output depends on question type.
        switch ($question->qtype) {
            case 'category':
                // Not a qtype really - dummy used for category switching.
                break;

            case 'truefalse':
                $trueanswer = $question->options->answers[$question->options->trueanswer];
                $trueanswer->answer = 'true';
                $expout .= $this->write_answer($trueanswer);

                $falseanswer = $question->options->answers[$question->options->falseanswer];
                $falseanswer->answer = 'false';
                $expout .= $this->write_answer($falseanswer);
                break;

            case 'multichoice':
                $expout .= "    <single>" . $this->get_single($question->options->single) .
                        "</single>\n";
                $expout .= "    <shuffleanswers>" .
                        $this->get_single($question->options->shuffleanswers) .
                        "</shuffleanswers>\n";
                $expout .= "    <answernumbering>" . $question->options->answernumbering .
                        "</answernumbering>\n";
                $expout .= $this->write_combined_feedback($question->options, $question->id, $question->contextid);
                $expout .= $this->write_answers($question->options->answers);
                break;

            case 'shortanswer':
                $expout .= "    <usecase>{$question->options->usecase}</usecase>\n";
                $expout .= $this->write_answers($question->options->answers);
                break;

            case 'numerical':
                foreach ($question->options->answers as $answer) {
                    $expout .= $this->write_answer($answer, "      <tolerance>{$answer->tolerance}</tolerance>\n");
                }

                $units = $question->options->units;
                if (count($units)) {
                    $expout .= "<units>\n";
                    foreach ($units as $unit) {
                        $expout .= "  <unit>\n";
                        $expout .= "    <multiplier>{$unit->multiplier}</multiplier>\n";
                        $expout .= "    <unit_name>{$unit->unit}</unit_name>\n";
                        $expout .= "  </unit>\n";
                    }
                    $expout .= "</units>\n";
                }
                if (isset($question->options->unitgradingtype)) {
                    $expout .= "    <unitgradingtype>" . $question->options->unitgradingtype .
                            "</unitgradingtype>\n";
                }
                if (isset($question->options->unitpenalty)) {
                    $expout .= "    <unitpenalty>{$question->options->unitpenalty}</unitpenalty>\n";
                }
                if (isset($question->options->showunits)) {
                    $expout .= "    <showunits>{$question->options->showunits}</showunits>\n";
                }
                if (isset($question->options->unitsleft)) {
                    $expout .= "    <unitsleft>{$question->options->unitsleft}</unitsleft>\n";
                }
                if (!empty($question->options->instructionsformat)) {
                    $files = $fs->get_area_files($contextid, 'qtype_numerical', 'instruction', $question->id);
                    $expout .= "    <instructions " .
                            $this->format($question->options->instructionsformat) . ">\n";
                    $expout .= $this->writetext($question->options->instructions, 3);
                    $expout .= $this->write_files($files);
                    $expout .= "    </instructions>\n";
                }
                break;

            case 'match':
                $expout .= "    <shuffleanswers>" .
                        $this->get_single($question->options->shuffleanswers) .
                        "</shuffleanswers>\n";
                $expout .= $this->write_combined_feedback($question->options, $question->id, $question->contextid);
                foreach ($question->options->subquestions as $subquestion) {
                    $files = $fs->get_area_files($contextid, 'qtype_match', 'subquestion', $subquestion->id);
                    $expout .= "    <subquestion " .
                            $this->format($subquestion->questiontextformat) . ">\n";
                    $expout .= $this->writetext($subquestion->questiontext, 3);
                    $expout .= $this->write_files($files);
                    $expout .= "      <answer>\n";
                    $expout .= $this->writetext($subquestion->answertext, 4);
                    $expout .= "      </answer>\n";
                    $expout .= "    </subquestion>\n";
                }
                break;

            case 'description':
                // Nothing else to do.
                break;

            case 'multianswer':


                foreach ($question->options->questions as $index => $subq) {
                    $expout = str_replace('{#' . $index . '}', $subq->questiontext, $expout);
                }
                break;

            case 'essay':
                $expout .= "    <responseformat>" . $question->options->responseformat .
                        "</responseformat>\n";
                $expout .= "    <responserequired>" . $question->options->responserequired .
                        "</responserequired>\n";
                $expout .= "    <responsefieldlines>" . $question->options->responsefieldlines .
                        "</responsefieldlines>\n";
                $expout .= "    <attachments>" . $question->options->attachments .
                        "</attachments>\n";
                $expout .= "    <attachmentsrequired>" . $question->options->attachmentsrequired .
                        "</attachmentsrequired>\n";
                $expout .= "    <graderinfo " .
                        $this->format($question->options->graderinfoformat) . ">\n";
                $expout .= $this->writetext($question->options->graderinfo, 3);
                $expout .= $this->write_files($fs->get_area_files($contextid, 'qtype_essay', 'graderinfo', $question->id));
                $expout .= "    </graderinfo>\n";
                $expout .= "    <responsetemplate " .
                        $this->format($question->options->responsetemplateformat) . ">\n";
                $expout .= $this->writetext($question->options->responsetemplate, 3);
                $expout .= "    </responsetemplate>\n";
                break;

            case 'calculated':
            case 'calculatedsimple':
            case 'calculatedmulti':
                $expout .= "    <synchronize>{$question->options->synchronize}</synchronize>\n";
                $expout .= "    <single>{$question->options->single}</single>\n";
                $expout .= "    <answernumbering>" . $question->options->answernumbering .
                        "</answernumbering>\n";
                $expout .= "    <shuffleanswers>" . $question->options->shuffleanswers .
                        "</shuffleanswers>\n";

                $component = 'qtype_' . $question->qtype;
                $files = $fs->get_area_files($contextid, $component, 'correctfeedback', $question->id);
                $expout .= "    <correctfeedback>\n";
                $expout .= $this->writetext($question->options->correctfeedback, 3);
                $expout .= $this->write_files($files);
                $expout .= "    </correctfeedback>\n";

                $files = $fs->get_area_files($contextid, $component, 'partiallycorrectfeedback', $question->id);
                $expout .= "    <partiallycorrectfeedback>\n";
                $expout .= $this->writetext($question->options->partiallycorrectfeedback, 3);
                $expout .= $this->write_files($files);
                $expout .= "    </partiallycorrectfeedback>\n";

                $files = $fs->get_area_files($contextid, $component, 'incorrectfeedback', $question->id);
                $expout .= "    <incorrectfeedback>\n";
                $expout .= $this->writetext($question->options->incorrectfeedback, 3);
                $expout .= $this->write_files($files);
                $expout .= "    </incorrectfeedback>\n";

                foreach ($question->options->answers as $answer) {
                    $percent = 100 * $answer->fraction;
                    $expout .= "<answer fraction=\"{$percent}\">\n";
                    // The "<text/>" tags are an added feature, old files won't have them.
                    $expout .= "    <text>{$answer->answer}</text>\n";
                    $expout .= "    <tolerance>{$answer->tolerance}</tolerance>\n";
                    $expout .= "    <tolerancetype>{$answer->tolerancetype}</tolerancetype>\n";
                    $expout .= "    <correctanswerformat>" .
                            $answer->correctanswerformat . "</correctanswerformat>\n";
                    $expout .= "    <correctanswerlength>" .
                            $answer->correctanswerlength . "</correctanswerlength>\n";
                    $expout .= "    <feedback {$this->format($answer->feedbackformat)}>\n";
                    $files = $fs->get_area_files($contextid, $component, 'instruction', $question->id);
                    $expout .= $this->writetext($answer->feedback);
                    $expout .= $this->write_files($answer->feedbackfiles);
                    $expout .= "    </feedback>\n";
                    $expout .= "</answer>\n";
                }
                if (isset($question->options->unitgradingtype)) {
                    $expout .= "    <unitgradingtype>" .
                            $question->options->unitgradingtype . "</unitgradingtype>\n";
                }
                if (isset($question->options->unitpenalty)) {
                    $expout .= "    <unitpenalty>" .
                            $question->options->unitpenalty . "</unitpenalty>\n";
                }
                if (isset($question->options->showunits)) {
                    $expout .= "    <showunits>{$question->options->showunits}</showunits>\n";
                }
                if (isset($question->options->unitsleft)) {
                    $expout .= "    <unitsleft>{$question->options->unitsleft}</unitsleft>\n";
                }

                if (isset($question->options->instructionsformat)) {
                    $files = $fs->get_area_files($contextid, $component, 'instruction', $question->id);
                    $expout .= "    <instructions " .
                            $this->format($question->options->instructionsformat) . ">\n";
                    $expout .= $this->writetext($question->options->instructions, 3);
                    $expout .= $this->write_files($files);
                    $expout .= "    </instructions>\n";
                }

                if (isset($question->options->units)) {
                    $units = $question->options->units;
                    if (count($units)) {
                        $expout .= "<units>\n";
                        foreach ($units as $unit) {
                            $expout .= "  <unit>\n";
                            $expout .= "    <multiplier>{$unit->multiplier}</multiplier>\n";
                            $expout .= "    <unit_name>{$unit->unit}</unit_name>\n";
                            $expout .= "  </unit>\n";
                        }
                        $expout .= "</units>\n";
                    }
                }

                // The tag $question->export_process has been set so we get all the
                // data items in the database from the function
                // qtype_calculated::get_question_options calculatedsimple defaults
                // to calculated.
                if (isset($question->options->datasets) && count($question->options->datasets)) {
                    $expout .= "<dataset_definitions>\n";
                    foreach ($question->options->datasets as $def) {
                        $expout .= "<dataset_definition>\n";
                        $expout .= "    <status>" . $this->writetext($def->status) . "</status>\n";
                        $expout .= "    <name>" . $this->writetext($def->name) . "</name>\n";
                        if ($question->qtype == 'calculated') {
                            $expout .= "    <type>calculated</type>\n";
                        } else {
                            $expout .= "    <type>calculatedsimple</type>\n";
                        }
                        $expout .= "    <distribution>" . $this->writetext($def->distribution) .
                                "</distribution>\n";
                        $expout .= "    <minimum>" . $this->writetext($def->minimum) .
                                "</minimum>\n";
                        $expout .= "    <maximum>" . $this->writetext($def->maximum) .
                                "</maximum>\n";
                        $expout .= "    <decimals>" . $this->writetext($def->decimals) .
                                "</decimals>\n";
                        $expout .= "    <itemcount>{$def->itemcount}</itemcount>\n";
                        if ($def->itemcount > 0) {
                            $expout .= "    <dataset_items>\n";
                            foreach ($def->items as $item) {
                                $expout .= "        <dataset_item>\n";
                                $expout .= "           <number>" . $item->itemnumber . "</number>\n";
                                $expout .= "           <value>" . $item->value . "</value>\n";
                                $expout .= "        </dataset_item>\n";
                            }
                            $expout .= "    </dataset_items>\n";
                            $expout .= "    <number_of_items>" . $def->number_of_items .
                                    "</number_of_items>\n";
                        }
                        $expout .= "</dataset_definition>\n";
                    }
                    $expout .= "</dataset_definitions>\n";
                }
                break;

            default:
                // Try support by optional plugin.
                if (!$data = $this->try_exporting_using_qtypes($question->qtype, $question)) {
                    $invalidquestion = true;
                } else {
                    $expout .= $data;
                }
        }

        // Output any hints.
        $expout .= $this->write_hints($question);

        // Write the question tags.
        if (!empty($CFG->usetags)) {
            require_once($CFG->dirroot . '/tag/lib.php');
            $tags = tag_get_tags_array('question', $question->id);
            if (!empty($tags)) {
                $expout .= "    <tags>\n";
                foreach ($tags as $tag) {
                    $expout .= "      <tag>" . $this->writetext($tag, 0, true) . "</tag>\n";
                }
                $expout .= "    </tags>\n";
            }
        }

        // Close the question tag.
        $expout .= "  </question>\n";
        if ($invalidquestion) {
            return '';
        } else {
            return $expout;
        }
    }

    public function convert_to_cloze($question) {
        $rightanswers=array();
        $wronganswers = '';
        foreach ($question->options->answers as $answer) {
            $fraction = substr($answer->fraction, 0, 1);
            if ($fraction == 0) {
                $wronganswers.='~' . $answer->answer;
            }else{
               $rightanswers[]=$answer->answer;
            }
        }
        $delimitchars = $question->options->delimitchars;
        $l = substr($delimitchars, 0, 1);
        $r = substr($delimitchars, 1, 1);
        $questiontext = $question->questiontext;
        foreach ($rightanswers as $key => $answer) {
           $regex = '/\\' . $l . $answer . $r . '/';
             if ($question->options->answerdisplay == 'gapfill') {
                $replacement = '{:SA:=' . $answer . '}';
                $questiontext = preg_replace($regex, $replacement, $questiontext);
            } else {
                $replacement = '{:MC:=' . $answer . $wronganswers . '}';
                $questiontext = preg_replace($regex, $replacement, $questiontext);
            }
        }
        $question->questiontext = $questiontext;
        return $question;
    }

   
}




