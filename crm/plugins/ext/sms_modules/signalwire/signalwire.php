<?php
/**
 * SignalWire SMS Module for Rukovoditel CRM
 * Sends SMS via SignalWire's Twilio-compatible REST API
 */

class signalwire
{
    public $title;
    public $site;
    public $api;
    public $version;

    function __construct()
    {
        $this->title = 'SignalWire SMS';
        $this->site = 'https://signalwire.com';
        $this->api = 'https://developer.signalwire.com/';
        $this->version = '1.0';
    }

    public function configuration()
    {
        $cfg = array();

        $cfg[] = array(
            'key' => 'space',
            'type' => 'input',
            'default' => '',
            'title' => 'SignalWire Space',
            'description' => 'Your SignalWire space name (e.g., yourspace.signalwire.com)',
            'params' => array('class' => 'form-control input-large required'),
        );

        $cfg[] = array(
            'key' => 'project_id',
            'type' => 'input',
            'default' => '',
            'title' => 'Project ID',
            'description' => 'Your SignalWire Project ID (found in Project Settings)',
            'params' => array('class' => 'form-control input-large required'),
        );

        $cfg[] = array(
            'key' => 'api_token',
            'type' => 'input',
            'default' => '',
            'title' => 'API Token',
            'description' => 'Your SignalWire API Token',
            'params' => array('class' => 'form-control input-large required'),
        );

        $cfg[] = array(
            'key' => 'from_number',
            'type' => 'input',
            'default' => '',
            'title' => 'From Phone Number',
            'description' => 'SignalWire phone number to send from (e.g., +19047066669)',
            'params' => array('class' => 'form-control input-large required'),
        );

        return $cfg;
    }

    function send($module_id, $destination = array(), $text = '')
    {
        global $alerts;

        $cfg = modules::get_configuration($this->configuration(), $module_id);
        
        // Build the API URL
        $space = trim($cfg['space']);
        if (strpos($space, '.signalwire.com') === false) {
            $space .= '.signalwire.com';
        }
        
        $projectId = trim($cfg['project_id']);
        $apiToken = trim($cfg['api_token']);
        $fromNumber = trim($cfg['from_number']);
        
        // Ensure from number has + prefix
        if (strpos($fromNumber, '+') !== 0) {
            $fromNumber = '+' . preg_replace('/[^\d]/', '', $fromNumber);
        }
        
        $url = "https://{$space}/api/laml/2010-04-01/Accounts/{$projectId}/Messages.json";
        
        foreach ($destination as $phone) {
            // Format phone number - add +1 if just 10 digits
            $phone = preg_replace('/[^\d]/', '', $phone);
            if (strlen($phone) == 10) {
                $phone = '+1' . $phone;
            } elseif (strpos($phone, '+') !== 0) {
                $phone = '+' . $phone;
            }
            
            $postFields = [
                'From' => $fromNumber,
                'To' => $phone,
                'Body' => strip_tags($text)
            ];
            
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_HEADER, false);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postFields));
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
            curl_setopt($ch, CURLOPT_USERPWD, "{$projectId}:{$apiToken}");
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/x-www-form-urlencoded',
                'Accept: application/json'
            ]);
            
            $result = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);
            
            if ($error) {
                $alerts->add($this->title . ' Error: ' . $error, 'error');
            } elseif ($httpCode >= 400) {
                $response = json_decode($result, true);
                $errorMsg = isset($response['message']) ? $response['message'] : "HTTP {$httpCode}";
                $alerts->add($this->title . ' Error: ' . $errorMsg, 'error');
            }
        }
    }
}
