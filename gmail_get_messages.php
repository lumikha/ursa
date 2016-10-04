<?php
// Authentication things above
    require_once 'dynamoDB/dbConnect.php';
    require_once realpath(dirname(__FILE__) . '/lib/google/apiclient/src/Google/autoload.php');

    $params_t_check = [
    'TableName' => 'ursa-tickets',
    'ProjectionExpression' => 'ticket_id,ticket_gmail_id,ticket_name_from,ticket_email_subject,ticket_email_from,ticket_email_body,ticket_embedded_image,ticket_email_attachment'
    ];

        try {
        while (true) {
            $ticket_check = $dynamodb->scan($params_t_check);

            
            foreach ($ticket_check['Items'] as $i) {
                $movie = $marshaler->unmarshalItem($i);
            }

            if (isset($ticket_check['LastEvaluatedKey'])) {
                $params_t_check['ExclusiveStartKey'] = $ticket_check['LastEvaluatedKey'];
                $ticket_check = $dynamodb->scan($params_t_check);
            } else {
                break;
            }
        }
    } catch (DynamoDbException $e) {
        echo "Unable to scan USERS:\n";
        echo $e->getMessage() . "\n";
    }
    $test = array();
    foreach ($ticket_check['Items'] as $obj) {
        if($obj['ticket_gmail_id']['S']){
            array_push($test, $obj['ticket_gmail_id']['S']);
        }
    }

    $arr_msgs = array();
    foreach ($ticket_check['Items'] as $obj2) {
        if($obj2['ticket_gmail_id']['S']){
            $bdy_image = decodeBody(@$obj2['ticket_email_body']['S']);

            preg_match_all('/src="cid:(.*)"/Uims', $bdy_image, $matches);
            if(count($matches)) {
                foreach($matches[1] as $match) {
                    $search = "src=\"cid:$match\"";
                    $replace = "src=../URSA_att/".$obj2['ticket_embedded_image']['S'];
                    $bdy_image = str_replace($search, $replace, $bdy_image);         
                }
            }
            $arr_att = array();
            $att_file = $obj2['ticket_email_attachment']['S'];
            $arr = explode(',', $att_file);
                     
            if(preg_match('/.php/',$att_file)){
                foreach ($arr as $key) {
                    @$attmts = file_get_contents('../URSA_att/attachments/'.$key);
                    array_push($arr_att, $attmts);
                }
            }
            array_push($arr_msgs, array(
                "ticket_id" => $obj2['ticket_id']['S'],
                "id" => $obj2['ticket_gmail_id']['S'],
                "subject" => $obj2['ticket_email_subject']['S'],
                "body" => $bdy_image,
                "from" => $obj2['ticket_name_from']['S'],
                "email" => $obj2['ticket_email_from']['S'],
                "attachments" => $arr_att
            ));
        }
    }

    $table_tickets = 'ursa-tickets';
    $table_ticket_notes = 'ursa-ticket-notes';

    define('APPLICATION_NAME', 'Gmail API PHP Quickstart');
    define('CLIENT_SECRET_PATH', 'lib/secret_biglo/client_secret.json');
    define('SCOPES', Google_Service_Gmail::GMAIL_READONLY);
    define('CREDENTIALS_PATH', 'lib/secret_biglo/gmail-php-quickstart.json');

    $client = new Google_Client();
    $client->setApplicationName(APPLICATION_NAME);
    $client->setScopes(SCOPES);
    $client->setAuthConfigFile(CLIENT_SECRET_PATH);
    $client->setAccessType('offline');
    
    $accessToken = json_decode(file_get_contents(CREDENTIALS_PATH), true);          
    $client->setAccessToken($accessToken);
/*
 * Decode the body.
 * @param : encoded body  - or null
 * @return : the body if found, else FALSE;
 */
function decodeBody($body) {
    $rawData = $body;
    $sanitizedData = strtr($rawData,'-_', '+/');
    $decodedMessage = base64_decode($sanitizedData);
    if(!$decodedMessage){
        $decodedMessage = FALSE;
    }
    return $decodedMessage;
}

function UID() {
    date_default_timezone_set("Asia/Manila");
    $t = microtime(true);
    $micro = sprintf("%06d",($t - floor($t)) * 1000000);
    $d = new DateTime( date('Y-m-d H:i:s.'.$micro, $t) );

    return $d->format("YmdHisu");
}

$gmail = new Google_Service_Gmail($client);

$list = $gmail->users_messages->listUsersMessages('me', ['maxResults' => 1000]);

try{
    $email_num =1;
    while ($list->getMessages() != null) {
        foreach ($list->getMessages() as $mlist) {
            $message_id = $mlist->id;
            $optParamsGet2['format'] = 'full';
            $single_message = $gmail->users_messages->get('me', $message_id, $optParamsGet2);
            $payload = $single_message->getPayload();
            $parts = $payload->getParts();
            // With no attachment, the payload might be directly in the body, encoded.
            $header = $payload->getHeaders();
            $body = $payload->getBody();

            $from_email = "";
            foreach($header as $head) {
                if($head['name'] == 'Date') {
                    $date = $head['value'];
                }
                if($head['name'] == 'Subject') {
                    $subject = $head['value'];
                }
                if($head['name'] == 'From') {
                    $from = $head['value'];
                }
                if($head['name'] == 'Authentication-Results') {
                    $get_email = explode("smtp.mailfrom=", $head['value']); 
                    if(strpos($get_email[1], ";")) {
                        $if_yahoo = explode(";", $get_email[1]);
                        $from_email = $if_yahoo[0];
                    } else {
                        $from_email = $get_email[1];
                    }
                }
                //print_r($from_email);
            }

            if( in_array( $message_id ,$test)) { 
                break;
            } else if($from_email!="") {
                $FOUND_BODY = FALSE;
                // If we didn't find a body, let's look for the parts
                if(!$FOUND_BODY) {
                    foreach ($parts  as $part) {
                        if($part['parts'] && !$FOUND_BODY) {
                            foreach ($part['parts'] as $p) {
                                if($p['parts'] && count($p['parts']) > 0){
                                    foreach ($p['parts'] as $y) {
                                        if(($y['mimeType'] === 'text/html') && $y['body']) {
                                            $FOUND_BODY = decodeBody($y['body']->data);
                                            $body_msg = $y['body']->data;
                                            break;
                                        }
                                    }
                                } else if(($p['mimeType'] === 'text/html') && $p['body']) {
                                    $FOUND_BODY = decodeBody($p['body']->data);
                                    $body_msg = $p['body']->data;
                                    break;
                                }
                            }
                        }
                        if($FOUND_BODY) {
                            break;
                        }
                    }
                }

                $uniqueFilename = "(no embedded image)";
                // let's save all the images linked to the mail's body:
                if($FOUND_BODY && count($parts) > 1){
                    $images_linked = array();
                    foreach ($parts  as $part) {
                        if($part['filename']){
                            array_push($images_linked, $part);
                        } else {
                            if($part['parts']) {
                                foreach ($part['parts'] as $p) {
                                    if($p['parts'] && count($p['parts']) > 0){
                                        foreach ($p['parts'] as $y) {
                                            if(($y['mimeType'] === 'text/html') && $y['body']) {
                                                array_push($images_linked, $y);
                                            }
                                        }
                                    } else if(($p['mimeType'] !== 'text/html') && $p['body']) {
                                        array_push($images_linked, $p);
                                    }
                                }
                            }
                        }
                    }
                    // special case for the wdcid...
                    preg_match_all('/wdcid(.*)"/Uims', $FOUND_BODY, $wdmatches);
                    if(count($wdmatches)) {
                        $z = 0;
                        foreach($wdmatches[0] as $match) {
                            $z++;
                            if($z > 9){
                                $FOUND_BODY = str_replace($match, 'image0' . $z . '@', $FOUND_BODY);
                            } else {
                                $FOUND_BODY = str_replace($match, 'image00' . $z . '@', $FOUND_BODY);
                            }
                        }
                    }
                    preg_match_all('/src="cid:(.*)"/Uims', $FOUND_BODY, $matches);
                    if(count($matches)) {
                        $uniqueFilename = UID().".png";
                        $search = array();
                        $replace = array();
                        // let's trasnform the CIDs as base64 attachements 
                        foreach($matches[1] as $match) {
                            foreach($images_linked as $img_linked) {
                                foreach($img_linked['headers'] as $img_lnk) {
                                    if( $img_lnk['name'] === 'Content-ID' || $img_lnk['name'] === 'Content-Id' || $img_lnk['name'] === 'X-Attachment-Id'){
                                        if ($match === str_replace('>', '', str_replace('<', '', $img_lnk->value)) || explode("@",$match)[0] === explode(".", $img_linked->filename)[0] || explode("@",$match)[0] === $img_linked->filename) {
                                            $search = "src=\"cid:$match\"";
                                            $mimetype = $img_linked->mimeType;
                                            $attachment = $gmail->users_messages_attachments->get('me', $mlist->id, $img_linked['body']->attachmentId);
                                            $data64 = strtr($attachment->getData(), array('-' => '+', '_' => '/'));
                                            $replace = "src=\"data:" . $mimetype . ";base64," . $data64 . "\"";
                                            $FOUND_BODY = str_replace($search, $replace, $FOUND_BODY);
                                            file_put_contents("../URSA_att/$uniqueFilename", decodeBody($attachment['data']));
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
                // If we didn't find the body in the last parts, 
                // let's loop for the first parts (text-html only)
                if(!$FOUND_BODY) {
                    foreach ($parts  as $part) {
                        if($part['body'] && $part['mimeType'] === 'text/html') {
                            $FOUND_BODY = decodeBody($part['body']->data);
                            $body_msg = $part['body']->data;
                            break;
                        }
                    }
                }
                // With no attachment, the payload might be directly in the body, encoded.
                if(!$FOUND_BODY) {
                    $FOUND_BODY = decodeBody($body['data']);
                    $body = decodeBody($body['data']);
                }
                // Last try: if we didn't find the body in the last parts, 
                // let's loop for the first parts (text-plain only)
                if(!$FOUND_BODY) {
                    foreach ($parts  as $part) {
                        if($part['body']) {
                            $FOUND_BODY = decodeBody($part['body']->data);
                            $body_msg = $part['body']->data;
                            break;
                        }
                    }
                }
                if(!$FOUND_BODY) {
                    $FOUND_BODY = '(No message)';
                    $body_msg = '(No message)';
                }

                $uniqueFilename2 = "";
                $attachmentName = array();
                $arr_att = array();
                $cnt_att = 0;
                foreach($parts as $ptest) {
                    if($ptest['body']['attachmentId']) {
                        $uniqueFilename2 = UID().".php";
                        $attachment = $gmail->users_messages_attachments->get('me', $message_id, $ptest['body']['attachmentId']);
                        $data64 = strtr($attachment->getData(), array('-' => '+', '_' => '/'));
                        $replace = "src=\"data:" . $ptest['mimeType'] . ";base64," . $data64 . "\"";
                        $att_dl = "data:" . $ptest['mimeType'] . ";base64," . $data64;
                        if($ptest['mimeType'] == 'image/gif' || $ptest['mimeType'] == 'image/png' || $ptest['mimeType'] == 'image/jpeg') {
                            $att = "&nbsp&nbsp&nbsp&nbsp
                                <div onmouseover='showTitle(this)' onmouseout='hideTitle(this)' style='position: relative; display: inline-block;'>
                                    <a href='#' class='open-modal-previewAtt' data-src='".$att_dl."' data-fn='".$ptest['filename']."'>
                                        <div class='att_title' style='position: absolute; background: rgb(0, 0, 0); background: rgba(0, 0, 0, 0.7); width: 200px; height: 200px; display: none; color: #ffffff; font-weight: bold; padding: 5px; word-wrap: break-word; cursor: zoom-in;'>
                                            <span>".$ptest['filename']."</span>
                                        </div>
                                    </a>
                                    <img style='width: 200px; height: 200px; margin-bottom:25px;' ".$replace.">
                                    <a href='".$att_dl."' download='".$ptest['filename']."' style=''>
                                        <button style='position: absolute; width: 50px; height: 50px; top: 65%; left: 72%; background: transparent; background-image: url(img/download_icon.png); background-size: 100%; border-color: #0071BC;'></button>
                                    </a>
                                </div>";
                            array_push($arr_att, $att);
                            array_push($attachmentName, $uniqueFilename2);
                            file_put_contents("../URSA_att/attachments/$uniqueFilename2", $att);
                        } else if($ptest['mimeType'] == 'application/pdf') {
                            
                            $pdf_decoded = base64_decode ($data64);
                            $pdf = fopen ('../URSA_att/attachments/'.$ptest['filename'] ,'w');
                            fwrite ($pdf,$pdf_decoded);
                            fclose ($pdf);

                            $att = "&nbsp&nbsp&nbsp&nbsp  
                                <div onmouseover='showTitle(this)' onmouseout='hideTitle(this)' style='position: relative; display: inline-block;'>
                                    <div class='att_title' style='position: absolute; background: rgb(0, 0, 0); background: rgba(0, 0, 0, 0.7); width: 200px; height: 200px; display: none; color: #ffffff; font-weight: bold; padding: 5px; word-wrap: break-word;'>
                                        <span>".$ptest['filename']."</span>
                                    </div>
                                    <img style='width: 200px; height: 200px; margin-bottom:25px;' src='img/pdf.png'>
                                    <a href='../URSA_att/attachments/".$ptest['filename']."' download='".$ptest['filename']."' style=''>
                                        <button style='position: absolute; width: 50px; height: 50px; top: 65%; left: 72%; background: transparent; background-image: url(img/download_icon.png); background-size: 100%; border-color: #0071BC;'></button>
                                    </a>
                                </div>";
                            array_push($arr_att, $att);
                            array_push($attachmentName, $uniqueFilename2);
                            file_put_contents("../URSA_att/attachments/$uniqueFilename2", $att);
                        } else if($ptest['mimeType'] == "application/vnd.openxmlformats-officedocument.wordprocessingml.document") {
                            $att = "&nbsp&nbsp&nbsp&nbsp  
                                <div onmouseover='showTitle(this)' onmouseout='hideTitle(this)' style='position: relative; display: inline-block;'>
                                    <div class='att_title' style='position: absolute; background: rgb(0, 0, 0); background: rgba(0, 0, 0, 0.7); width: 200px; height: 200px; display: none; color: #ffffff; font-weight: bold; padding: 5px; word-wrap: break-word;'>
                                        <span>".$ptest['filename']."</span>
                                    </div>
                                    <img style='width: 200px; height: 200px; margin-bottom:25px;' src='img/docx.png'></a>
                                    <a href='".$att_dl."' download='".$ptest['filename']."' style=''>
                                        <button style='position: absolute; width: 50px; height: 50px; top: 65%; left: 72%; background: transparent; background-image: url(img/download_icon.png); background-size: 100%; border-color: #0071BC;'></button>
                                    </a>
                                </div>";
                            array_push($arr_att, $att);
                            array_push($attachmentName, $uniqueFilename2);
                            file_put_contents("../URSA_att/attachments/$uniqueFilename2", $att);
                        } else if($ptest['mimeType'] == 'application/msword') {
                            $att = "&nbsp&nbsp&nbsp&nbsp  
                                <div onmouseover='showTitle(this)' onmouseout='hideTitle(this)' style='position: relative; display: inline-block;'>
                                    <div class='att_title' style='position: absolute; background: rgb(0, 0, 0); background: rgba(0, 0, 0, 0.7); width: 200px; height: 200px; display: none; color: #ffffff; font-weight: bold; padding: 5px; word-wrap: break-word;'>
                                        <span>".$ptest['filename']."</span>
                                    </div>
                                    <img style='width: 200px; height: 200px; margin-bottom:25px;' src='img/doc.png'></a>
                                    <a href='".$att_dl."' download='".$ptest['filename']."' style=''>
                                        <button style='position: absolute; width: 50px; height: 50px; top: 65%; left: 72%; background: transparent; background-image: url(img/download_icon.png); background-size: 100%; border-color: #0071BC;'></button>
                                    </a>
                                </div>";
                            array_push($arr_att, $att);
                            array_push($attachmentName, $uniqueFilename2);
                            file_put_contents("../URSA_att/attachments/$uniqueFilename2", $att);
                        } else {
                            $att = "&nbsp&nbsp&nbsp&nbsp  
                                <div onmouseover='showTitle(this)' onmouseout='hideTitle(this)' style='position: relative; display: inline-block;'>
                                    <div class='att_title' style='position: absolute; background: rgb(0, 0, 0); background: rgba(0, 0, 0, 0.7); width: 200px; height: 200px; display: none; color: #ffffff; font-weight: bold; padding: 5px; word-wrap: break-word;'>
                                        <span>".$ptest['filename']."</span>
                                    </div>
                                    <img style='width: 200px; height: 200px; margin-bottom:25px;' src='img/unknown.png'></a>
                                    <a href='".$att_dl."' download='".$ptest['filename']."' style=''>
                                        <button style='position: absolute; width: 50px; height: 50px; top: 65%; left: 72%; background: transparent; background-image: url(img/download_icon.png); background-size: 100%; border-color: #0071BC;'></button>
                                    </a>
                                </div>";
                            array_push($arr_att, $att);
                            array_push($attachmentName, $uniqueFilename2);
                            file_put_contents("../URSA_att/attachments/$uniqueFilename2", $att);
                        }
                    }
                    $cnt_att++;
                }

                $comma_separated = "(no attachment)";
                if($uniqueFilename2 != ""){
                    $comma_separated = implode(",", $attachmentName);
                }
                $t_id = UID();
                if(!empty($FOUND_BODY)){
                    $item_t_add = $marshaler->marshalJson('
                    {
                        "ticket_id": "'.$t_id.'",
                        "ticket_gmail_id": "'.$message_id.'",
                        "ticket_email_from": "'.$from_email.'",
                        "ticket_name_from": "'.$from.'",
                        "ticket_email_subject": "'.$subject.'",
                        "ticket_email_date": "'.$date.'",
                        "ticket_email_body": "'.$body_msg.'",
                        "ticket_embedded_image": "'.$uniqueFilename.'",
                        "ticket_email_attachment": "'.$comma_separated.'"
                    }');

                    $params_t_add = [
                        'TableName' => $table_tickets,
                        'Item' => $item_t_add
                    ];

                    try {
                        $result = $dynamodb->putItem($params_t_add);
                    } catch (DynamoDbException $e) {
                        echo "Unable to add item:\n";
                        echo $e->getMessage() . "\n";
                    }

                    array_unshift($arr_msgs, array(
                        "ticket_id" => $t_id,
                        "id" => $message_id,
                        //"date" => $date,
                        "subject" => $subject,
                        "body" => $FOUND_BODY,
                        "from" => $from,
                        "email" => $from_email,
                        "attachments" => $arr_att
                    ));

                }
            } else {}

        }

        if ($list->getNextPageToken() != null) {
            $pageToken = $list->getNextPageToken();
            $list = $gmail->users_messages->listUsersMessages('me', ['pageToken' => $pageToken, 'maxResults' => 1000]);
        } else {
            break;
        }
    }
} catch (Exception $e) {
    echo $e->getMessage();
}

function array_sort_by_column(&$arr, $col, $dir = SORT_ASC) {
    $sort_col = array();
    foreach ($arr as $key=> $row) {
        $sort_col[$key] = $row[$col];
    }

    array_multisort($sort_col, $dir, $arr);
}


array_sort_by_column($arr_msgs, 'ticket_id');
?>