<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
return new class extends Migration {
 public function up(): void { DB::unprepared(<<<'SQL'
ALTER TABLE lessons ADD COLUMN version integer NOT NULL DEFAULT 1;
ALTER TABLE lessons ADD COLUMN recorder_offline_at timestamptz;
ALTER TABLE lessons ADD COLUMN deleted_at timestamptz;
ALTER TABLE lessons ADD COLUMN purge_status varchar(20);
CREATE TABLE recorder_handoffs (id uuid PRIMARY KEY, lesson_id bigint NOT NULL REFERENCES lessons(id), kind varchar(10) NOT NULL CHECK(kind IN ('invite','request')), requester_id bigint NOT NULL REFERENCES users(id), source_user_id bigint NOT NULL REFERENCES users(id), target_user_id bigint NOT NULL REFERENCES users(id), source_connection varchar(64) NOT NULL, target_connection varchar(64) NOT NULL, epoch integer NOT NULL, status varchar(20) NOT NULL DEFAULT 'pending' CHECK(status IN ('pending','preparing','completed','rejected','cancelled','expired','failed')), expires_at timestamptz NOT NULL, created_at timestamptz NOT NULL DEFAULT now(), updated_at timestamptz NOT NULL DEFAULT now());
CREATE INDEX handoffs_active ON recorder_handoffs(lesson_id,status,expires_at);
CREATE TABLE recording_issues (id bigserial PRIMARY KEY, lesson_id bigint NOT NULL REFERENCES lessons(id), model_session_id uuid REFERENCES model_sessions(id), epoch integer, start_sample bigint NOT NULL, end_sample bigint, kind varchar(60) NOT NULL, resolved_at timestamptz, created_at timestamptz NOT NULL DEFAULT now());
CREATE INDEX recording_issues_lesson ON recording_issues(lesson_id,id);
SQL); }
 public function down(): void { throw new RuntimeException('Restore into an empty database instead of destructive rollback.'); }
};
