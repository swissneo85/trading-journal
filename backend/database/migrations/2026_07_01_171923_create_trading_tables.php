<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activities', function (Blueprint $table) {
            $table->string('deal_id');
            $table->string('date_utc');
            $table->string('epic')->nullable();
            $table->string('instrument')->nullable();
            $table->string('direction')->nullable();
            $table->float('size')->nullable();
            $table->float('level')->nullable();
            $table->float('open_price')->nullable();
            $table->string('source')->nullable();
            $table->primary(['deal_id', 'date_utc']);
        });

        Schema::create('transactions', function (Blueprint $table) {
            $table->string('reference')->primary();
            $table->string('deal_id')->nullable();
            $table->string('date_utc')->nullable();
            $table->string('instrument')->nullable();
            $table->string('transaction_type')->nullable();
            $table->float('pl_chf')->nullable();
            $table->string('note')->nullable();
        });

        Schema::create('trade_tags', function (Blueprint $table) {
            $table->string('deal_id')->primary();
            $table->string('quelle')->nullable();
            $table->text('notiz')->nullable();
            $table->string('tagged_at')->nullable();
        });

        Schema::create('config', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->text('value')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('config');
        Schema::dropIfExists('trade_tags');
        Schema::dropIfExists('transactions');
        Schema::dropIfExists('activities');
    }
};
