<?php
/**
 * Этот файл является частью программы "CRM Руководитель" - конструктор CRM систем для бизнеса
 * https://www.rukovoditel.net.ru/
 * 
 * CRM Руководитель - это свободное программное обеспечение, 
 * распространяемое на условиях GNU GPLv3 https://www.gnu.org/licenses/gpl-3.0.html
 * 
 * Автор и правообладатель программы: Харчишина Ольга Александровна (RU), Харчишин Сергей Васильевич (RU).
 * Государственная регистрация программы для ЭВМ: 2023664624
 * https://fips.ru/EGD/3b18c104-1db7-4f2d-83fb-2d38e1474ca3
 */


class telegram
{

    public $title;
    public $site;
    public $api;
    public $version;

    function __construct()
    {
        $this->title = TEXT_MODULE_TELEGRAM_TITLE;
        $this->site = 'https://telegram.org';
        $this->api = 'https://core.telegram.org/bots/api#sendmessage';
        $this->version = '2.0';
    }

    public function configuration()
    {
        $cfg = array();

        $cfg[] = array(
            'key' => 'bot_token',
            'type' => 'input',
            'default' => '',
            'title' => TEXT_MODULE_TELEGRAM_BOT_TOKEN,
            'description' => TEXT_MODULE_TELEGRAM_BOT_TOKEN_DESCRIPTION,
            'params' => array('class' => 'form-control input-large required'),
        );


        return $cfg;
    }

    function send($module_id, $destination = array(), $text = '', $rule = [], $item = [])
    {
        global $alerts;
        
        $attachments = $this->getAttachments($rule, $item);
        
        $text = $this->removeAttachmentsFilenames($attachments, $text);

        $cfg = modules::get_configuration($this->configuration(), $module_id);
        $url = "https://api.telegram.org/bot" . $cfg['bot_token'] . "/sendMessage";

        foreach($destination as $chat_id)
        {
            $params = [
                'chat_id' => $chat_id,
                'text' => strip_tags($text, '<b><i><a><code><pre>'),
                'parse_mode' => 'HTML',
                'disable_web_page_preview' => 'true',
            ];

            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_HEADER, false);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, ($params));
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
            $result = curl_exec($ch);
            curl_close($ch);

            if($result)
            {
                $result = json_decode($result, true);

                if(isset($result['error_code']))
                {
                    if(is_object($alerts))
                    {
                        $alerts->add($this->title . ' ' . TEXT_ERROR . ' ' . $result['error_code'] . ' ' . $result['description'] . '. (chat_id: ' . $chat_id . ')', 'error');
                    }
                    else
                    {
                        error_log(date('Y-m-d H:i') . ' ' . $this->title . ' Error: ' . $result['error_code'] . ' ' . $result['description'] . '. (chat_id: ' . $chat_id . ')' . "\n",3,'log/sms.errors.log');
                    }
                }
                else
                {
                    $this->sendAttachments($cfg, $chat_id, $attachments);
                }
            }
        }
    }
    
    //to get available attachmetsn form item
    function getAttachments($rule, $item)
    {
        global $app_fields_cache;
        
        $attachments = [];
        
        $description = $rule['description'];
        
        if(preg_match_all('/\[(\d+)\]/', $description, $matches))
        {
            foreach($matches[1] as $matches_key => $fields_id)
            {
                if(isset($app_fields_cache[$rule['entities_id']][$fields_id]))
                {
                    $type = $app_fields_cache[$rule['entities_id']][$fields_id]['type'];
                    
                    if(in_array($type, fields_types::get_attachments_types()))
                    {                                                
                        if(strlen($files = $item['field_' . $fields_id]??''))
                        {
                            foreach(array_map('trim', explode(',', $files)) as $filename)
                            {
                                $file = attachments::parse_filename($filename); 
                                if(is_file($file['file_path']))
                                {
                                    $attachments[$file['file_path']] = $file['name'];                                
                                }
                            }
                        }
                        
                    }
                }
            }
        }
        
        //print_rr($rule);        
        //print_rr($item);        
        //print_rr($attachments);                        
        //exit();
        
        return $attachments;
    }
    
    //to send attachments in chat
    /*function sendAttachments($cfg, $chat_id, $attachmetns)
    {        
        if(count($attachmetns)>0)
        {
            $this->sendAttachmentsGroups($cfg, $chat_id, $attachmetns);
        }
        else
        {
            $url = "https://api.telegram.org/bot" . $cfg['bot_token'] . "/sendDocument";
                    
            foreach($attachmetns as $filepath => $filename)
            {
                $params = [
                    'chat_id' => $chat_id,
                    'document' => new CURLFile($filepath, mime_content_type($filepath), $filename),
                    'disable_notification' => 0,
                ];

                $ch = curl_init($url);
                curl_setopt($ch, CURLOPT_HEADER, false);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                curl_setopt($ch, CURLOPT_POST, 1);
                curl_setopt($ch, CURLOPT_POSTFIELDS, ($params));
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                curl_setopt($ch, CURLOPT_TIMEOUT, 10);
                $result = curl_exec($ch);
                curl_close($ch);
            }
        }
    }
     * 
     */
    
    function sendAttachments($cfg, $chat_id, $attachmetns)
    {
        $groupKey = 0;
        $attachmentsGroups = [];
        $count = 1;
        foreach($attachmetns as $filepath => $filename)
        {
            $attachmentsGroups[$groupKey][$filepath] = $filename;
            
            if($count/9 == floor($count/9))
            {
                $groupKey++;
                $count=0;
            }
            
            $count++;                        
        }
        
        //debug groups
        //print_rr($attachmentsGroups);        
        //exit();
        
        foreach($attachmentsGroups as $attachmentsGroup)
        {        
            $params = [
                'chat_id' => $chat_id,                
            ];
            
            $media = [];
            $key = 1;
            foreach($attachmentsGroup as $filepath => $filename)
            {
                $fileKey = 'file' . $key;
                $media[] = ['type' => 'document', 'media' => 'attach://' . $fileKey ];
                $params[$fileKey] = new CURLFile($filepath, mime_content_type($filepath), $filename);
                
                $key++;
            }
            
            $url = "https://api.telegram.org/bot" . $cfg['bot_token'] . "/sendMediaGroup";
                    
            $params['media'] = json_encode($media);
            
            //print_rr($params);

            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_HEADER, false);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, ($params));
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            //curl_setopt($ch, CURLOPT_TIMEOUT, 60);
            $result = curl_exec($ch);
            curl_close($ch);
            
            //debug result
            //print_rr($result);
        }
        
        //exit();
                
    }
    
    function removeAttachmentsFilenames($attachmetns, $text)
    {
        foreach($attachmetns as $filename)
        {
            $text = str_replace([$filename . ',', $filename], '', $text);
        }
        
        return $text;
    }
}
