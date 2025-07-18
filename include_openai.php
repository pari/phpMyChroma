<?php

class OpenAIClient {
    private $apiKey;
    private $baseUrl = 'https://api.openai.com/v1';
    
    public function __construct($apiKey) {
        $this->apiKey = $apiKey;
    }
    
    public function generateEmbedding($text, $model = null) {
        if ($model === null) {
            $model = $_ENV['OPENAI_EMBEDDING_MODEL'] ?? 'text-embedding-3-small';
        }
        $ch = curl_init();
        
        curl_setopt_array($ch, [
            CURLOPT_URL => $this->baseUrl . '/embeddings',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->apiKey
            ],
            CURLOPT_POSTFIELDS => json_encode([
                'input' => $text,
                'model' => $model
            ])
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode !== 200) {
            // Try to decode error message for better readability
            $errorData = json_decode($response, true);
            $errorMessage = isset($errorData['error']['message']) ? $errorData['error']['message'] : $response;
            throw new Exception("OpenAI API Error: " . $errorMessage);
        }
        
        $data = json_decode($response, true);
        return $data['data'][0]['embedding'];
    }
}