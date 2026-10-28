<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            // Only the text read from an attached document is kept, not the file itself.
            $table->string('file_name')->nullable()->after('image_mime');
            $table->longText('file_text')->nullable()->after('file_name');
        });

        Schema::table('users', function (Blueprint $table) {
            // Personal instructions added to the system prompt of every chat.
            $table->text('instructions')->nullable()->after('password');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropColumn(['file_name', 'file_text']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('instructions');
        });
    }
};
