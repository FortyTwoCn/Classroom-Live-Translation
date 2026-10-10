<?php
use Illuminate\Database\Migrations\Migration;use Illuminate\Support\Facades\DB;
return new class extends Migration {
 public function up():void {DB::unprepared(<<<'SQL'
ALTER TABLE courses ADD COLUMN allow_audio_download boolean NOT NULL DEFAULT false;
CREATE INDEX subtitle_media_lookup ON subtitle_units(lesson_id,start_sample,end_sample) WHERE deleted_at IS NULL AND timing_precision='server_segment';
CREATE INDEX connection_test_owner ON connection_tests(owner_id,created_at DESC);
CREATE INDEX purge_pending ON purge_objects(lesson_id,status);
SQL);}
 public function down():void {throw new RuntimeException('Restore into an empty database instead of destructive rollback.');}
};
