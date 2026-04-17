<?php
declare(strict_types=1);
namespace Engine\Atomic\Tools;

if (!defined( 'ATOMIC_START' ) ) exit;

use Engine\Atomic\Core\App;

class AIConnector {
    /** @var array<string, self> */
    protected static array $instances = [];
    protected App $atomic;
    protected ?string $api_key;
    protected string $provider;
    protected string $model;
    protected ?string $custom_domain = null;
    
    const PROVIDER_OPENAI = 'openai';
    const PROVIDER_GROQ = 'groq';
    const PROVIDER_OPENROUTER = 'openrouter';
    const PROVIDER_GLOBUS = 'globus';

    public static function instance(?string $api_key = null, string $provider = self::PROVIDER_OPENAI): self {
        if (!isset(self::$instances[$provider])) {
            self::$instances[$provider] = new self($api_key, $provider);
        }
        return self::$instances[$provider];
    }

    protected function __construct(?string $api_key = null, string $provider = self::PROVIDER_OPENAI) {
        $this->atomic = App::instance();
        $this->set_provider($provider);
        
        // If API key is not provided, try to get it from config
        if ($api_key === null) {
            $api_key = $this->atomic->get("ai.{$provider}.api_key");
        }
        
        $this->api_key = $api_key;
    }

    public function set_provider(string $provider, ?string $custom_domain = null): self {
        $this->provider = $provider;
        $this->custom_domain = $custom_domain;
        $this->model = $this->get_default_model();

        if (empty($this->api_key)) {
            $key = $this->atomic->get("ai.{$provider}.api_key");
            if (!empty($key)) {
                $this->api_key = (string)$key;
            }
        }

        return $this;
    }

    public function set_api_key(string $api_key): self {
        $this->api_key = $api_key;
        return $this;
    }

    public function set_model(string $model): self {
        $this->model = $model;
        return $this;
    }

    public function get_base_url(): string {
        if ($this->custom_domain) {
            return $this->custom_domain;
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
    
    public function get_default_model(): string {
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

    public function get_available_models(): array {
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

    public function chat_completion(array $messages, array $options = []): mixed {
        if (empty($this->api_key)) {
            throw new \Exception("API key is required for " . $this->provider);
        }

        $url = $this->get_base_url() . 'chat/completions';

        $payload = [
            'messages' => $messages,
            'model' => $options['model'] ?? $this->model,
            'temperature' => $options['temperature'] ?? 0.7,
            'max_tokens' => $options['max_tokens'] ?? 1024,
        ];

        $optionalParams = [
            'top_p', 'frequency_penalty', 'presence_penalty', 'seed', 'stop',
            'stream', 'stream_options', 'tools', 'tool_choice', 'parallel_tool_calls',
            'response_format', 'max_completion_tokens', 'prediction',
            'logprobs', 'top_logprobs', 'logit_bias', 'user', 'n',
        ];

        if ($this->provider === self::PROVIDER_OPENROUTER) {
            $optionalParams = array_merge($optionalParams, [
                'top_k', 'repetition_penalty', 'min_p', 'top_a',
                'structured_outputs',
                'provider', 'models', 'route', 'plugins', 'debug',
            ]);
        }

        foreach ($optionalParams as $param) {
            if (array_key_exists($param, $options)) {
                $payload[$param] = $options[$param];
            }
        }

        $headers = [
            'Content-Type: application/json',
        ];
        
        switch ($this->provider) {
            case self::PROVIDER_OPENAI:
                $headers[] = 'Authorization: Bearer ' . $this->api_key;
                break;
            case self::PROVIDER_GROQ:
                $headers[] = 'Authorization: Bearer ' . $this->api_key;
                break;
            case self::PROVIDER_OPENROUTER:
                $headers[] = 'Authorization: Bearer ' . $this->api_key;
                $headers[] = 'HTTP-Referer: ' . ($options['referer'] ?? $this->atomic->get('HOST'));
                $headers[] = 'X-OpenRouter-Title: Atomic Framework';
                break;
            case self::PROVIDER_GLOBUS:
                $headers[] = 'X-API-KEY: ' . $this->api_key;
                break;
        }

        $response = $this->make_request($url, $payload, $headers, $options);
        
        return $response;
    }

    protected function format_error_message(object $error, int $http_status): string
    {
        $code = $error->code ?? $http_status;
        $msg  = $error->message ?? 'Unknown error';
        $meta = $error->metadata ?? null;

        $extra = '';
        if ($meta !== null) {
            if (isset($meta->provider_name)) {
                $extra .= ' provider=' . $meta->provider_name;
            }
            if (isset($meta->reasons)) {
                $extra .= ' reasons=' . implode(',', (array)$meta->reasons);
            }
            if (isset($meta->flagged_input)) {
                $extra .= ' flagged="' . $meta->flagged_input . '"';
            }
            if (isset($meta->raw)) {
                $extra .= ' raw=' . (is_string($meta->raw) ? $meta->raw : json_encode($meta->raw));
            }
        }

        return '[' . $this->provider . '] ' . $msg . ' (code: ' . $code . ')' . $extra;
    }

    protected function make_request(string $url, array $payload, array $headers, array $options = []): mixed {
        $ch = curl_init($url);
        
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_TIMEOUT, $options['timeout'] ?? 120);
        
        $response = curl_exec($ch);
        
        if (curl_errno($ch)) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new \Exception('cURL error: ' . $err);
        }
        
        $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        $decoded = json_decode($response);

        if ($statusCode >= 400) {
            $msg = null;
            if (isset($decoded->error)) {
                $msg = $this->format_error_message($decoded->error, $statusCode);
            }
            throw new \Exception($msg ?? 'API error: HTTP ' . $statusCode);
        }

        if ($decoded === null) {
            throw new \Exception('Invalid JSON response from API (HTTP ' . $statusCode . ')');
        }

        // Top-level error (pre-stream errors, 200 with error body)
        if (isset($decoded->error)) {
            throw new \Exception($this->format_error_message($decoded->error, $statusCode));
        }

        // Per-choice error (provider failure, e.g. 502 from upstream)
        if (isset($decoded->choices[0]->error)) {
            throw new \Exception($this->format_error_message($decoded->choices[0]->error, $statusCode));
        }

        // Mid-stream error: finish_reason="error" with no choices[0]->error field
        if (isset($decoded->choices[0]->finish_reason) && $decoded->choices[0]->finish_reason === 'error') {
            throw new \Exception('[' . $this->provider . '] stream ended with error (finish_reason=error)');
        }

        return $decoded;
    }

    public function create_embeddings(string|array $input, array $options = []): mixed {
        if (empty($this->api_key)) {
            throw new \Exception("API key is required for " . $this->provider);
        }
        
        $url = $this->get_base_url() . 'embeddings';
        
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
            'Authorization: Bearer ' . $this->api_key
        ];
        
        $response = $this->make_request($url, $payload, $headers, $options);
        
        return $response;
    }

    public function completion(string $prompt, array $options = []): ?string {
        // Format as a chat completion with a user message
        $messages = [
            ['role' => 'user', 'content' => $prompt]
        ];
        
        $response = $this->chat_completion($messages, $options);
        
        // Extract just the generated text
        if (isset($response->choices[0]->message->content)) {
            return $response->choices[0]->message->content;
        }
        
        return null;
    }
}

function ai_connector(?string $api_key = null, string $provider = AIConnector::PROVIDER_OPENAI): AIConnector {
    return AIConnector::instance($api_key, $provider);
}

