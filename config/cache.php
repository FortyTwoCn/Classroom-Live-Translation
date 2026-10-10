<?php
return ['default'=>env('CACHE_STORE','database'),'stores'=>['database'=>['driver'=>'database','table'=>'cache','lock_table'=>'cache_locks','connection'=>null,'lock_connection'=>null],'array'=>['driver'=>'array','serialize'=>false],'redis'=>['driver'=>'redis','connection'=>'default','lock_connection'=>'default']],'prefix'=>'classroom'];
