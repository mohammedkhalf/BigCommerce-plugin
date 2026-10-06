<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('api_logs')->where('action', 'authorised')->delete();
        DB::table('api_logs')->where('action', 'refund')->update(['action' => 'refunded']);
    }

    public function down(): void
    {
        DB::table('api_logs')->where('action', 'refunded')->update(['action' => 'refund']);
    }
};
