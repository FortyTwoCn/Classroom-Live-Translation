<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
return new class extends Migration {
 public function up():void{DB::unprepared(<<<'SQL'
CREATE TABLE storage_versions (version varchar(100) PRIMARY KEY, payload_encrypted text NOT NULL, created_at timestamptz NOT NULL DEFAULT now(), updated_at timestamptz NOT NULL DEFAULT now());
ALTER TABLE lessons ADD COLUMN storage_version varchar(100);
CREATE TABLE audio_chunks (id bigserial PRIMARY KEY, lesson_id bigint NOT NULL REFERENCES lessons(id), epoch integer NOT NULL CHECK(epoch>=0), group_sequence integer NOT NULL CHECK(group_sequence>=0), first_sequence integer NOT NULL CHECK(first_sequence>=0), last_sequence integer NOT NULL, frame_count integer NOT NULL CHECK(frame_count>0 AND frame_count<=100), start_sample bigint NOT NULL CHECK(start_sample>=0), sample_count integer NOT NULL CHECK(sample_count>0 AND sample_count<=160000), sha256 varchar(64), object_key text, storage_version varchar(100) NOT NULL, storage_state varchar(20) NOT NULL DEFAULT 'open' CHECK(storage_state IN('open','pending','uploading','uploaded','failed')), attempts integer NOT NULL DEFAULT 0, local_path text, sealed_at timestamptz, created_at timestamptz NOT NULL DEFAULT now(), updated_at timestamptz NOT NULL DEFAULT now(), UNIQUE(lesson_id,epoch,group_sequence), CHECK(last_sequence-first_sequence+1=frame_count));
CREATE UNIQUE INDEX one_open_audio_chunk ON audio_chunks(lesson_id,epoch) WHERE storage_state='open';
CREATE INDEX chunks_reconcile ON audio_chunks(storage_state,updated_at);
ALTER TABLE audio_segments ADD COLUMN chunk_id bigint REFERENCES audio_chunks(id), ADD COLUMN offset_bytes bigint CHECK(offset_bytes IS NULL OR offset_bytes>=0);
CREATE INDEX segments_chunk ON audio_segments(chunk_id,sequence);
SQL);}
 public function down():void{throw new RuntimeException('Destructive rollback disabled; restore into an empty database.');}
};
