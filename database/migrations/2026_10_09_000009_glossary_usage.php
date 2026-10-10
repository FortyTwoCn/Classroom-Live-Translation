<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
return new class extends Migration {
 public function up():void { DB::unprepared(<<<'SQL'
ALTER TABLE courses ADD COLUMN glossary_version bigint NOT NULL DEFAULT 1;
CREATE TABLE course_terms (id bigserial PRIMARY KEY, course_id bigint NOT NULL REFERENCES courses(id), source_language varchar(10) NOT NULL, target_language varchar(10) NOT NULL, source_text text NOT NULL, target_text text NOT NULL, note text NOT NULL DEFAULT '', enabled boolean NOT NULL DEFAULT true, row_version bigint NOT NULL DEFAULT 1, deleted_at timestamptz, created_at timestamptz NOT NULL DEFAULT now(), updated_at timestamptz NOT NULL DEFAULT now());
CREATE INDEX course_terms_list ON course_terms(course_id,target_language,id);
CREATE TABLE lesson_glossary_snapshots (lesson_id bigint PRIMARY KEY REFERENCES lessons(id), course_id bigint NOT NULL REFERENCES courses(id), target_language varchar(10) NOT NULL, version bigint NOT NULL, hash varchar(64) NOT NULL, phrases jsonb NOT NULL, entries jsonb NOT NULL, phrase_count integer NOT NULL, payload_bytes integer NOT NULL, created_at timestamptz NOT NULL DEFAULT now());
CREATE TABLE daily_audio_usage (day date PRIMARY KEY, sample_count bigint NOT NULL DEFAULT 0 CHECK(sample_count>=0), updated_at timestamptz NOT NULL DEFAULT now());
CREATE TABLE lesson_daily_audio_usage (lesson_id bigint NOT NULL REFERENCES lessons(id), day date NOT NULL, sample_count bigint NOT NULL DEFAULT 0 CHECK(sample_count>=0), PRIMARY KEY(lesson_id,day));
INSERT INTO daily_audio_usage(day,sample_count) SELECT (created_at AT TIME ZONE 'UTC')::date,sum(sample_count) FROM audio_segments GROUP BY (created_at AT TIME ZONE 'UTC')::date;
INSERT INTO lesson_daily_audio_usage(lesson_id,day,sample_count) SELECT lesson_id,(created_at AT TIME ZONE 'UTC')::date,sum(sample_count) FROM audio_segments GROUP BY lesson_id,(created_at AT TIME ZONE 'UTC')::date;
CREATE TABLE usage_alerts (lesson_id bigint NOT NULL REFERENCES lessons(id), day date NOT NULL, scope varchar(10) NOT NULL CHECK(scope IN ('lesson','daily')), threshold_samples bigint NOT NULL, created_at timestamptz NOT NULL DEFAULT now(), PRIMARY KEY(lesson_id,day,scope,threshold_samples));
ALTER TABLE usage_records ADD COLUMN provider_event_id text;
ALTER TABLE usage_records ADD COLUMN official boolean NOT NULL DEFAULT false;
UPDATE usage_records SET official=true WHERE jsonb_typeof(usage)='object' AND usage<>'{}'::jsonb;
CREATE TABLE usage_receipts (model_session_id uuid NOT NULL REFERENCES model_sessions(id), dedup_key varchar(66) NOT NULL, usage_record_id bigint REFERENCES usage_records(id), PRIMARY KEY(model_session_id,dedup_key));
INSERT INTO usage_receipts(model_session_id,dedup_key,usage_record_id) SELECT model_session_id,'r:'||encode(sha256(convert_to(response_id,'UTF8')),'hex'),min(id) FROM usage_records WHERE response_id IS NOT NULL AND response_id<>'' GROUP BY model_session_id,response_id;
SQL); }
 public function down():void { throw new RuntimeException('Destructive rollback is disabled. Restore from a backup into an empty database.'); }
};
