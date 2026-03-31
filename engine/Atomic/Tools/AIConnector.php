<?php
declare(strict_types=1);
namespace Engine\Atomic\Tools;

if (!defined( 'ATOMIC_START' ) ) exit;

use Engine\Atomic\Core\App;

class AIConnector {
    protected static ?self $instance = null;
    protected App $atomic;
    protected ?string $apiKey;
    protected string $provider;
    protected string $model;
    protected ?string $customDomain = null;
    
    const PROVIDER_OPENAI = 'openai';
    const PROVIDER_GROQ = 'groq';
    const PROVIDER_OPENROUTER = 'openrouter';
    const PROVIDER_GLOBUS = 'globus';

    public static function instance(?string $apiKey = null, string $provider = self::PROVIDER_OPENAI): self {
        if (self::$instance === null) {
            self::$instance = new self($apiKey, $provider);
        }
        return self::$instance;
    }

    protected function __construct(?string $apiKey = null, string $provider = self::PROVIDER_OPENAI) {
        $this->atomic = App::instance();
        $this->setProvider($provider);
        
        // If API key is not provided, try to get it from config
        if ($apiKey === null) {
            $apiKey = $this->atomic->get("ai.{$provider}.api_key");
        }
        
        $this->apiKey = $apiKey;
    }

    public function setProvider(string $provider, ?string $customDomain = null): self {
        $this->provider = $provider;
        $this->customDomain = $customDomain;
        $this->model = $this->getDefaultModel();
        return $this;
    }

    public function setApiKey(string $apiKey): self {
        $this->apiKey = $apiKey;
        return $this;
    }

    public function setModel(string $model): self {
        $this->model = $model;
        return $this;
    }

    public function getBaseUrl(): string {
        if ($this->customDomain) {
            return $this->customDomain;
        }
        
        switch ($this->provider) {
            case self::PROVIDER_OPENAI:
                return 'https://api.openai.com/v1/';
            case self::PROVIDER_GROQ:
                return 'https://api.groq.com/openai/v1/';
            case self::PROVIDER_OPENROUTER:
                return 'https://openrouter.ai/api/v1/';
            case self::PROVIDER_GLOBUS:
                return 'https://api.globus.studio/ai/';
            default:
                return 'https://api.openai.com/v1/';
        }
    }
    
    public function getDefaultModel(): string {
        switch ($this->provider) {
            case self::PROVIDER_OPENAI:
                return 'gpt-5-nano';
            case self::PROVIDER_GROQ:
                return 'llama-3.1-8b-instant';
            case self::PROVIDER_OPENROUTER:
                return 'google/gemini-2.0-flash-001';
            case self::PROVIDER_GLOBUS:
                return 'globus-general';
            default:
                return 'gpt-5-nano';
        }
    }

    public function getAvailableModels(): array {
        switch ($this->provider) {
            case self::PROVIDER_OPENAI:
                return [
                    'gpt-5-nano',               // 400k context 128k output
                    'gpt-4.1-nano',             // 1M context 32k output
                    'gpt-5-mini',               // 400k context 128k output
                    'gpt-4.1-mini',             // 1M context 32k output
                    'gpt-4.1',                  // 1M context 32k output
                    'text-embedding-3-large'    // For embeddings and RAG! 
                ];
            case self::PROVIDER_GROQ:
                return [
                    'llama-3.1-8b-instant',                             // 128k context 32k output
                    'openai/gpt-oss-20b',                               // 128k context 65k output
                    'meta-llama/llama-4-maverick-17b-128e-instruct',    // 10M context 128k output
                    'openai/gpt-oss-120b',                              // 128k context 65k output
                    'meta-llama/llama-4-scout-17b-16e-instruct',        // 1M context 128k output  
                    'llama-3.3-70b-versatile',                          // 128k context 40k output
                ];
            case self::PROVIDER_OPENROUTER:
                return [
                    'x-ai/grok-4-fast:free',            // 2M context 30k output    
                    'qwen/qwen3-coder:free',            // 128k context 16k output  
                    'mistralai/ministral-3b',           // 33k context 4k output         
                    'openai/gpt-oss-120b',              // 128k context 65k output
                    'mistralai/ministral-8b',           // 33k context 4k output             
                    'google/gemini-2.0-flash-001',      // 1M context 128k output 
                    'google/gemini-2.5-flash',          // 1M context 128k output 
                    'meta-llama/llama-guard-2-8b',      // 8k context 1k output  
                ];
            case self::PROVIDER_GLOBUS:
                return [
                    'globus-antispam',
                    'globus-seo',
                    'globus-general'
                ];
            default:
                return [];
        }
    }

    public function chatCompletion(array $messages, array $options = []): mixed {
        if (empty($this->apiKey)) {
            throw new \Exception("API key is required for " . $this->provider);
        }
        
        $endpoint = $this->provider === self::PROVIDER_GLOBUS 
            ? 'chat/completions' 
            : 'chat/completions';
            
        $url = $this->getBaseUrl() . $endpoint;
        
        $payload = [
            'messages' => $messages,
            'model' => $options['model'] ?? $this->model,
            'temperature' => $options['temperature'] ?? 0.7,
            'max_tokens' => $options['max_tokens'] ?? 1024,
        ];
        
        // Add provider-specific parameters
        if ($this->provider === self::PROVIDER_OPENROUTER && !empty($options['lang'])) {
            $payload['language'] = $options['lang'];
        }
        
        // For OpenRouter, we need to specify the HTTP route in the request
        if ($this->provider === self::PROVIDER_OPENROUTER) {
            $payload['http_referer'] = $options['referer'] ?? $this->atomic->get('HOST');
            $payload['http_user_agent'] = $options['user_agent'] ?? ATOMIC_HTTP_USERAGENT;
        }

        $headers = [
            'Content-Type: application/json',
        ];
        
        switch ($this->provider) {
            case self::PROVIDER_OPENAI:
                $headers[] = 'Authorization: Bearer ' . $this->apiKey;
                break;
            case self::PROVIDER_GROQ:
                $headers[] = 'Authorization: Bearer ' . $this->apiKey;
                break;
            case self::PROVIDER_OPENROUTER:
                $headers[] = 'Authorization: Bearer ' . $this->apiKey;
                $headers[] = 'HTTP-Referer: ' . ($options['referer'] ?? $this->atomic->get('HOST'));
                $headers[] = 'X-Title: Atomic Framework';
                break;
            case self::PROVIDER_GLOBUS:
                $headers[] = 'X-API-KEY: ' . $this->apiKey;
                break;
        }

        $response = $this->makeRequest($url, $payload, $headers, $options);
        
        return $response;
    }

    protected function makeRequest(string $url, array $payload, array $headers, array $options = []): mixed {
        $ch = curl_init($url);
        
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_TIMEOUT, $options['timeout'] ?? 120);
        
        $response = curl_exec($ch);
        
        if (curl_errno($ch)) {
            throw new \Exception('cURL error: ' . curl_error($ch));
        }
        
        $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($statusCode >= 400) {
            $error = json_decode($response, true);
            $errorMessage = isset($error['error']['message']) 
                ? $error['error']['message'] 
                : 'API error: HTTP ' . $statusCode;
            throw new \Exception($errorMessage);
        }
        
        return json_decode($response);
    }

    public function createEmbeddings(string|array $input, array $options = []): mixed {
        if (empty($this->apiKey)) {
            throw new \Exception("API key is required for " . $this->provider);
        }
        
        $url = $this->getBaseUrl() . 'embeddings';
        
        // Ensure input is properly formatted
        if (is_string($input)) {
            $input = [$input];
        }
        
        $payload = [
            'input' => $input,
            'model' => $options['model'] ?? 'text-embedding-3-large',
        ];
        
        $headers = [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $this->apiKey
        ];
        
        $response = $this->makeRequest($url, $payload, $headers, $options);
        
        return $response;
    }

    public function completion(string $prompt, array $options = []): ?string {
        // Format as a chat completion with a user message
        $messages = [
            ['role' => 'user', 'content' => $prompt]
        ];
        
        $response = $this->chatCompletion($messages, $options);
        
        // Extract just the generated text
        if (isset($response->choices[0]->message->content)) {
            return $response->choices[0]->message->content;
        }
        
        return null;
    }
}

function ai_connector(?string $apiKey = null, string $provider = AIConnector::PROVIDER_OPENAI): AIConnector {
    return AIConnector::instance($apiKey, $provider);
}
