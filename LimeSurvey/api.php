<?php
/**
 * Created by PhpStorm.
 * User: Don
 * Date: 3/19/2015
 * Time: 10:45 AM
 */

global $plugin;
global $database;

$database
    ->query("CREATE TABLE IF NOT EXISTS `limesurvey` (
              `id` INT NOT NULL AUTO_INCREMENT,
              `sheet` MEDIUMTEXT NULL,
              `type` TEXT(15) NULL,
              `key` LONGTEXT NULL,
              `value` LONGTEXT NULL,
              `user` LONGTEXT NULL,
              PRIMARY KEY (`id`))")
    ->fetchAll();

$sheet_content = [];
$sheet_name = 'unnamed_sheet';
$dir_location = $plugin->config['losses.lime.survey']['dir_location'];

if (!isset($_SESSION['LimeSurvey']['id']))
    $_SESSION['LimeSurvey']['id'] = md5(md5(get_ip_address() . date('Y-m-d H:i:s')));

if (isset($_POST['list'])) {
    $return = [];
    $return['surveyList'] = json_decode(file_get_contents("$dir_location/list.json"), true);
    $id = $_SESSION['LimeSurvey']['id'];
    
    $return['finished'] = [];
    $finished_sheet = $database->query("SELECT `sheet` FROM `limesurvey`
                                        WHERE `user` = '$id'
                                        GROUP BY `sheet`")->fetchAll();
    for ($i = 0; $i<count($finished_sheet); $i++){
		$return['finished'][] = $finished_sheet[$i]['sheet'];
	}

    echo json_encode($return);
    exit();
}

if (!isset($_POST['sheet']))
    response_message(403, 'please select a sheet');

$sheet_name = $_POST['sheet'];

if (!is_file($sheet_location = "$dir_location/question_db/$sheet_name.json"))
    response_message(404, "$sheet_location");

$sheet_content = json_decode(file_get_contents($sheet_location), true);


if (isset($_POST['action'])) {
    if ($_POST['action'] == 'get') {
        $return = ['sheet_info' => [], 'question' => []];
        $questions = $sheet_content['sheet'];

        $return['sheet_info'] = [
            'title' => $sheet_content['info']['title'],
            'guidePage' => $sheet_content['info']['guidePage'],
            'questionPerPage' => $sheet_content['info']['questionPerPage']
        ];

        for ($i = 0; $i < count($questions); $i++) {
            $single_question = $questions[$i];
            $pad_id = str_pad($i, 2, "0", STR_PAD_LEFT);
            $id = isset($questions[$i]['id']) ? $questions[$i]['id'] : "$sheet_name-$pad_id";

            $return['question'][$id]['id'] = $id;

            $required_fill = ['question', 'type', 'required', 'suffix', 'prefix', 'hint'];

            for ($l = 0; $l < count($required_fill); $l++) {
                if (isset($single_question[$required_fill[$l]]))
                    $return['question'][$id][$required_fill[$l]] = $single_question[$required_fill[$l]];
            }

            if (isset($single_question['selection'])) {
                $return['question'][$id]['selection'] = [];
                $j = 0;
                foreach ($single_question['selection'] as $selection) {
                    $pad_selection = str_pad($j, 2, "0", STR_PAD_LEFT);
                    $return['question'][$id]['selection']["s$pad_selection"] = $selection;
                    $j = $j + 1;
                }
            }
        }

        echo json_encode($return);
        exit();
    }

    if ($_POST['action'] == 'submit') {
        $finish_notice = 'success';

        if (!isset($_POST['form_data']))
            response_message(403, "请发送表单信息");

        $form_data = json_decode($_POST['form_data'], true);
        $questions = $sheet_content['sheet'];
        $required = [];

        for ($i = 0; $i < count($questions); $i++) {
            if (!isset($questions[$i]['required'])
                || $questions[$i]['required'] != false
            ) {
                $required[] = $i;
            }
        }

        for ($i = 0; $i < count($required); $i++) {
            $id = isset($questions[$required[$i]]['id']) ?
                $questions[$required[$i]]['id'] :
                $_POST['sheet'] . '-' . str_pad($required[$i], 2, "0", STR_PAD_LEFT);

            if (!isset($form_data[$id]))
                response_message(403, '请完成所有必填内容！');
        }

        $query_insert = [];

        foreach ($form_data as $key => $value) {
            if (!isset($question_table[$key]))
                if (count($slice_name = explode('_', $key)) > 1) {
                    array_pop($slice_name);
                    $question_key = implode('_', $slice_name);
                    if (!isset($question_table[$question_key]))
                        continue;
                } else
                    continue;
            else
                $question_key = $key;

            if (in_array($question_table[$question_key]['type'], ['s', 'm'])) {
                $value = (int)explode('_', array_keys($question_table[$question_key]['selection'])[$value])[0];
            } else {
                $real_value = $value;
            }

            $query_insert[] = [
                'user' => $_SESSION['LimeSurvey']['id'],
                'sheet' => $_POST['sheet'],
                'type' => 'answer',
                'key' => $key,
                'value' => $value
            ];
        }

        if (is_file($statistic_location = "$dir_location/statistics/$sheet_name.php"))
            require $statistic_location;

        $final_query = $database->insert('limesurvey', $query_insert);

        response_message(200, $finish_notice);
        exit();
    }
}
