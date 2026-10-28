<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Self-hosted model service
    |--------------------------------------------------------------------------
    |
    | Laravel talks to the model engine (llama.cpp server, Ollama, vLLM) over
    | its OpenAI-compatible HTTP API. The engine must listen on a private
    | address only; it is never exposed to the browser.
    |
    */

    'base_url' => rtrim(env('LLM_BASE_URL', 'http://127.0.0.1:8080/v1'), '/'),

    'api_key' => env('LLM_API_KEY'),

    'model' => env('LLM_MODEL', 'local-model'),

    // Seconds to wait for the engine to accept the request and for each chunk.
    'connect_timeout' => (int) env('LLM_CONNECT_TIMEOUT', 5),

    'timeout' => (int) env('LLM_TIMEOUT', 300),

    'max_tokens' => (int) env('LLM_MAX_TOKENS', 2048),

    'temperature' => (float) env('LLM_TEMPERATURE', 0.7),

    // Qwen-style models think by default; thinking is slow on small servers.
    'thinking' => (bool) env('LLM_THINKING', false),

    // How much history is sent with each request.
    'context_messages' => (int) env('LLM_CONTEXT_MESSAGES', 20),

    'context_chars' => (int) env('LLM_CONTEXT_CHARS', 24000),

    'max_input_chars' => (int) env('LLM_MAX_INPUT_CHARS', 16000),

    // Largest image attachment in kilobytes. The engine needs a vision
    // projector (llama-server --mmproj) to read images.
    'max_image_kb' => (int) env('LLM_MAX_IMAGE_KB', 2048),

    // Attached documents (PDF, .docx, .txt, .md, .csv): largest upload in
    // kilobytes, and how much of their text is kept and sent to the model.
    'max_file_kb' => (int) env('LLM_MAX_FILE_KB', 2048),

    'max_file_chars' => (int) env('LLM_MAX_FILE_CHARS', 10000),

    // Length limit for the user's personal instructions.
    'max_instructions_chars' => 2000,

    // Chat messages a user may send per minute.
    'rate_per_minute' => (int) env('LLM_RATE_PER_MINUTE', 10),

    'system_prompt' => env('LLM_SYSTEM_PROMPT', 'You are Rafeeq, a personal assistant for conversation, programming, analysis and learning. Reply in the language the user writes in (Arabic or English). Use Markdown, and put code in fenced code blocks with the language name. Be accurate; say so when you are unsure.'),

];
