<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
return new class extends Migration {
 public function up():void{DB::statement('ALTER TABLE lesson_events ADD COLUMN broadcasted_at timestamptz');DB::statement('CREATE INDEX pending_outbox ON lesson_events(seq) WHERE broadcasted_at IS NULL');}
 public function down():void{throw new RuntimeException('Destructive rollback disabled');}
};
