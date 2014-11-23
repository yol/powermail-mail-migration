<?php
header("Content-Type: text/plain; charset=utf-8");

ini_set("display_errors", "1");
error_reporting(E_ALL);

$DRY_RUN = false;

print "Connecting to MySQL... ";

include "NotORM.php";
$pdo = new PDO(
        "mysql:host=localhost;dbname=my_db_name",
        "my_user",
        "my_password",
        array(PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8;")
        );

print "Connected\n";

$db = new NotORM($pdo, new NotORM_Structure_Convention('uid'));
/*$db->debug = function($query, $p) {
    print "QUERY: $query\n";
    return true;
};*/

# Old form ID -> new form ID
$formMap = array(
    658 => 2,
    577 => 3
);

$fieldMapRes = $db->tx_powermail_domain_model_fields()->select("uid, marker");
$fieldMap = array();
foreach ($fieldMapRes as $fieldMapEntry) {
    if (empty($fieldMapEntry['marker'])) {
        continue;
    }
    
    if (array_key_exists($fieldMapEntry['marker'], $fieldMap)) {
        die("Marker " . $fieldMapEntry['marker'] . " is mapped to multiple fields!");
    }
    $fieldMap[$fieldMapEntry['marker']] = $fieldMapEntry['uid'];
}

$mailsSuccessful = 0;
$mails = $db->tx_powermail_mails();
$mailCount = count($mails);
$mailI = 0;
$mailsDeleted = 0;

foreach ($mails as $mail) {
    $mailI++;
    if ($mailI % 100 == 1) {
        print "Progress: at mail $mailI out of $mailCount\n";
    }
    
    if ($mail['deleted']) {
        $mailsDeleted++;
        continue;
    }
    
    $newFormID = $formMap[$mail['formid']];
    if (!isset($newFormID)) {
        print "Could not find new form ID for " . $mail['formid'] . ", mail UID " . $mail['uid'] . "\n";
        continue;
    }
    
    $answers = $mail['piVars'];
    $xmlParser = xml_parser_create("UTF-8");
    xml_parser_set_option($xmlParser, XML_OPTION_CASE_FOLDING, 0);
    //xml_parser_set_option($xmlParser, XML_OPTION_SKIP_WHITE, 1);
    $valuesParsed = array();
    //print "Parse $answers...\n";
    if (xml_parse_into_struct($xmlParser, $answers, $valuesParsed) != 1) {
        print "Could not parse XML data in mail UID " . $mail['uid'] . "\n";
        print "Error code: " . xml_get_error_code($xmlParser) . " (" . xml_error_string(xml_get_error_code($xmlParser)) . ") at line " . xml_get_current_line_number($xmlParser) . "\n";
        continue;
    }
    
    xml_parser_free($xmlParser);
       
    $newMailValues = array(
        'pid' => $mail['pid'],
        'crdate' => $mail['crdate'],
        'tstamp' => $mail['tstamp'],
        'cruser_id' => $mail['cruser_id'],
        'sender_mail' => $mail['sender'],
        'subject' => $mail['subject_r'],
        'receiver_mail' => $mail['recipient'],
        'body' => $mail['content'],
        'feuser' => $mail['feuser'],
        'sender_ip' => $mail['senderIP'],
        'user_agent' => $mail['UserAgent'],
        'marketing_referer' => $mail['Referer'],
        'form' => $newFormID,
    );
    
    if (!$DRY_RUN) {
        $newMail = $db->tx_powermail_domain_model_mails()->insert($newMailValues);
        //$newMailID = $db->tx_powermail_domain_model_mails()->insert_id();
    }
    
    $newAnswers = array();
    foreach ($valuesParsed as $valueSubtree) {
        if ($valueSubtree['type'] != 'complete') {
            continue;
        }
        $answerMarker = $valueSubtree['tag'];
        $answerFieldID = $fieldMap[$answerMarker];
        if (!isset($answerFieldID)) {
            die("Field with marker $answerMarker has no valid mapping");
        }
        $answerText = "";
        if (isset($valueSubtree['value'])) {
            $answerText = $valueSubtree['value'];
        }
        //print "$answerMarker -> $answerFieldID: $answerText\n";
        $newAnswer = array(
            'pid' => $mail['pid'],
            'crdate' => $mail['crdate'],
            'tstamp' => $mail['tstamp'],
            'cruser_id' => $mail['cruser_id'],
            'value' => $answerText,
            'mail' => $newMail['uid'],
            'field' => $answerFieldID,
            'value_type' => 0,
        );
        $newAnswers[] = $newAnswer;
    }
    if (!$DRY_RUN) {
        $insertedCount = $db->tx_powermail_domain_model_answers()->insert_multi($newAnswers);
        if ($insertedCount != count($newAnswers)) {
            die("Could not insert all answers");
        }

        //print "Update answers to " . count($newAnswers) . "\n";
        $newMail['answers'] = count($newAnswers);
        $newMail->update();
    }
    
    $mailsSuccessful++;
}

print "Successfully converted $mailsSuccessful out of $mailCount mails ($mailsDeleted were already deleted)\n";

