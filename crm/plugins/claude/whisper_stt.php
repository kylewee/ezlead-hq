<?php
/**
 * Whisper Speech-to-Text endpoint
 * Accepts audio from browser MediaRecorder, sends to OpenAI Whisper, returns text
 */
header('Content-Type: application/json');

$OPENAI_KEY = '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';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['error' => 'POST required']);
    exit;
}

if (!isset($_FILES['audio'])) {
    echo json_encode(['error' => 'No audio file']);
    exit;
}

$audioFile = $_FILES['audio']['tmp_name'];
$audioName = $_FILES['audio']['name'] ?: 'recording.webm';

// Send to OpenAI Whisper
$ch = curl_init('https://api.openai.com/v1/audio/transcriptions');
$cfile = new CURLFile($audioFile, 'audio/webm', $audioName);

curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
        'Authorization: Bearer ' . $OPENAI_KEY,
    ],
    CURLOPT_POSTFIELDS => [
        'file' => $cfile,
        'model' => 'whisper-1',
        'language' => 'en',
    ],
    CURLOPT_TIMEOUT => 30,
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode !== 200) {
    echo json_encode(['error' => 'Whisper API error', 'details' => $response]);
    exit;
}

$result = json_decode($response, true);
echo json_encode(['text' => $result['text'] ?? '']);
