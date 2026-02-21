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
        // simple key/value table intended to hold a handful of flags that
        // are normally controlled via the configuration file.  The only
        // current use case is the `sync_users` flag, but additional options
        // could be added later without needing another migration.
        Schema::create('iam_settings', function (Blueprint $table) {
            $table->id();
            $table->boolean('sync_users')->default(true)
                ->comment('Enable or disable the `/api/iam/sync-users` endpoint');
            $table->timestamps();
        });

        // insert a row so that the setting always exists; the default value
        // defined above will make sure it starts out enabled.
        DB::table('iam_settings')->insert([
            'sync_users' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('iam_settings');
    }
};
