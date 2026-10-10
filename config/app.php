<?php
return ['name'=>env('APP_NAME','Classroom'),'env'=>env('APP_ENV','production'),'debug'=>(bool)env('APP_DEBUG',false),'url'=>env('APP_URL','https://localhost'),'timezone'=>'UTC','locale'=>'zh-CN','fallback_locale'=>'en','key'=>env('APP_KEY'),'previous_keys'=>array_filter(explode(',',env('APP_PREVIOUS_KEYS',''))),'cipher'=>'AES-256-CBC'];
