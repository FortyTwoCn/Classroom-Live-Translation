<?php
use Illuminate\Database\Migrations\Migration;use Illuminate\Support\Facades\DB;
return new class extends Migration {
 public function up():void {DB::unprepared(<<<'SQL'
ALTER TABLE audio_segments ADD COLUMN recorder_user_id bigint REFERENCES users(id);
ALTER TABLE model_sessions ADD COLUMN recorder_user_id bigint REFERENCES users(id);
CREATE INDEX usage_lesson_time ON usage_records(lesson_id,created_at,id);
CREATE INDEX model_lesson_time ON model_sessions(lesson_id,created_at);
CREATE INDEX usage_response_dedup ON usage_records(model_session_id,response_id,id);
CREATE INDEX audio_epoch_owner ON audio_segments(lesson_id,epoch,recorder_user_id);
SQL);}
 public function down():void {throw new RuntimeException('Restore into an empty database instead of destructive rollback.');}
};
