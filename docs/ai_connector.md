## AI Connector ##

`Engine\Atomic\Tools\AIConnector` is a small wrapper around chat-completions and embeddings APIs.

Supported providers:

- `openai`
- `groq`
- `openrouter`
- `globus`

```php
$ai = ai_connector('your-api-key');

$text = $ai->completion('Explain queues in one paragraph.');

$result = $ai->chat_completion([
    ['role' => 'system', 'content' => 'You are concise.'],
    ['role' => 'user', 'content' => 'List three cache strategies.'],
]);

echo $result->choices[0]->message->content ?? '';
```

### Provider and model

```php
$ai = ai_connector(null, 'openai'); // reads ai.openai.api_key from config

$ai->set_provider('openrouter')
   ->set_model('google/gemini-2.5-flash');
```

If no model is set manually, the connector picks a provider-specific default:

- OpenAI: `gpt-5-nano`
- Groq: `llama-3.1-8b-instant`
- OpenRouter: `google/gemini-2.0-flash-001`
- Globus: `globus-general`

### Available models

```php
$models = $ai->get_available_models();
print_r($models);
```

`get_available_models()` returns a curated static list from the connector class. It does not fetch models remotely.

### Chat options

`chat_completion()` accepts an optional second argument:

```php
$result = $ai->chat_completion($messages, [
    'model' => 'gpt-5-mini',
    'temperature' => 0.2,
    'max_tokens' => 800,
    'timeout' => 60,
]);
```

Provider-specific options:

- OpenRouter: `referer`, `user_agent`, `lang`

### Embeddings

```php
$embeddings = $ai->create_embeddings('Atomic framework docs');
$vector = $embeddings->data[0]->embedding ?? [];

$batch = $ai->create_embeddings([
    'First text',
    'Second text',
]);
```

`create_embeddings()` sends requests to the `/embeddings` endpoint and defaults to `text-embedding-3-large`.

### Config

The connector reads API keys from the Atomic hive when no key is passed explicitly:

```php
$atomic->set('ai.openai.api_key', 'sk-...');
$atomic->set('ai.groq.api_key', 'gsk-...');
$atomic->set('ai.openrouter.api_key', 'or-...');
$atomic->set('ai.globus.api_key', '...');
```

### Notes

- `ai_connector()` returns a shared singleton instance.
- Switching provider on that instance also resets the default model for the selected provider.
- The current implementation exposes chat completions, plain prompt completion, and embeddings only. It does not implement streaming helpers.
