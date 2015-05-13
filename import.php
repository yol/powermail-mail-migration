<?php
// enable dynamic status updates in browser, see php.net/flush
apache_setenv('no-gzip', 1);
ini_set('output_buffering', '0');
ini_set('zlib.output_compression', '0');

ini_set("display_errors", "1");
ini_set('html_errors', '0');
error_reporting(E_ALL);

header("Content-Type: text/plain; charset=utf-8");

$DRY_RUN = FALSE;

print <<<EOD
Converting powermail 1.x mails to 2.x.

Connecting to MySQL...
EOD;

include "notorm/NotORM.php";
$pdo = new PDO(
        "mysql:host=localhost;dbname=my_db_name",
        "my_user",
        "my_password",
        array(PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8;")
        );

print "Connected\n\n";

$db = new NotORM($pdo, new NotORM_Structure_Convention('uid'));
// Uncomment to see all SQL queries
/*$db->debug = function($query, $p) {
    print "QUERY: $query\n";
    return true;
};*/

// Old form ID -> new form ID
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
$newAnswersTotal = 0;

print "Converting $mailCount mails ... ";

foreach ($mails as $mail) {
    $mailI++;
    if ($mailI % 100 == 1) {
        print "$mailI ";
        flush();
        ob_flush();
    }
    
    if ($mail['deleted']) {
        $mailsDeleted++;
        continue;
    }

    if (!isset($formMap[$mail['formid']])) {
        $mailsWithoutNewFormId[$mail['formid']][$mail['uid']] = 1;
        continue;
    }

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
        'form' => $formMap[$mail['formid']],
    );
    
    if (!$DRY_RUN) {
        $newMail = $db->tx_powermail_domain_model_mails()->insert($newMailValues);
    }
    
    // use a hierarchical instead of a flat xml structure to cater for flexform arrays, used for checkboxes, radiobuttons, ...
    $piVarsXml = simplexml_load_string($mail['piVars']);

    $newAnswers = array();
    foreach ($piVarsXml as $field) {
        $fieldName = $field->getName();
        if (!isset($fieldMap[$fieldName])) {
            $markersWithoutMapping[$fieldName][$mail['formid']] = 1;
            continue;
        }
        $answerText = "";
        // subelements
        if ($field->count()) {  // && (string) $field['type'] == 'array'
            // XXX if needed: cater for more than one subelement, create one answer for each
            // in our data $field->count() is max 1
            $answerText = (string) $field->numIndex;
        }
        else {
            $answerText = (string) $field;
        }

        //print "$answerMarker -> $answerFieldID: $answerText\n";
        $newAnswer = array(
            'pid' => $mail['pid'],
            'crdate' => $mail['crdate'],
            'tstamp' => $mail['tstamp'],
            'cruser_id' => $mail['cruser_id'],
            'value' => $answerText,
            'mail' => $newMail['uid'],
            'field' => $fieldMap[$fieldName],
            'value_type' => 0,
        );
        $newAnswers[] = $newAnswer;
    }
    if (!$DRY_RUN) {
        $insertedCount = $db->tx_powermail_domain_model_answers()->insert_multi($newAnswers);
        if ($insertedCount != count($newAnswers)) {
            die("Could not insert all answers");
        }

        $newMail['answers'] = count($newAnswers);
        $newMail->update();

        $newAnswersTotal += $insertedCount;
    }
    
    $mailsSuccessful++;
}

print <<<EOD


Successfully converted $mailsSuccessful out of $mailCount mails ($mailsDeleted were already deleted). $newAnswersTotal new answers have been inserted.

The following mails could not be assigned to a new 2.x form:

newFormId:\tmailId1, mailId2, ...
---------------------------------

EOD;
ksort($mailsWithoutNewFormId, SORT_NATURAL);
foreach ($mailsWithoutNewFormId as $newFormId => $mails) {
    print "$newFormId:\t" . implode(', ', array_keys($mails)) . "\n";
}

print <<<EOD

The following markers had no valid mappings and were ignored:

marker:\tformid1, formid2, ...
-----------------------------

EOD;
ksort($markersWithoutMapping, SORT_NATURAL);
foreach ($markersWithoutMapping as $marker => $forms) {
    print "$marker:\t" . implode(', ', array_keys($forms)) . "\n";
}
