<?php
return [
 'ws_listen'=>env('WS_LISTEN','websocket://0.0.0.0:8081'), 'ws_url'=>env('WS_PUBLIC_URL','wss://localhost/ws'),
 'allowed_origins'=>explode(',',env('WS_ALLOWED_ORIGINS',env('APP_URL','https://localhost'))),
 'lease_seconds'=>(int)env('RECORDER_LEASE_SECONDS',20),'max_seconds'=>(int)env('MAX_LESSON_SECONDS',5400),'max_active'=>(int)env('MAX_ACTIVE_LESSONS',3),
 'min_disk_bytes'=>(int)env('MIN_DISK_FREE_BYTES',536870912),
 'qwen'=>['model'=>env('QWEN_MODEL','qwen3.8-livetranslate-flash-realtime'),'region'=>env('QWEN_REGION','cn-beijing'),'workspace_id'=>env('QWEN_WORKSPACE_ID'),'endpoint'=>env('QWEN_WS_ENDPOINT'),'api_key'=>env('QWEN_API_KEY'),'allow_test_endpoint'=>(bool)env('QWEN_ALLOW_TEST_ENDPOINT',false)],
 'oss'=>['endpoint'=>env('OSS_ENDPOINT'),'region'=>env('OSS_REGION'),'bucket'=>env('OSS_BUCKET'),'key'=>env('OSS_ACCESS_KEY_ID'),'secret'=>env('OSS_ACCESS_KEY_SECRET'),'token'=>env('OSS_SESSION_TOKEN'),'prefix'=>env('OSS_PREFIX','classroom'),'presign_seconds'=>(int)env('OSS_PRESIGN_SECONDS',120),'storage_version'=>env('OSS_STORAGE_VERSION','v1')],
 'ffmpeg'=>env('FFMPEG_BINARY','ffmpeg'),'ffprobe'=>env('FFPROBE_BINARY','ffprobe'),
];
