<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * A clínica é de estética biomédica: quem atende é o profissional
     * biomédico, não um médico.
     */
    public function up(): void
    {
        DB::table('users')->where('role', 'doctor')->update(['role' => 'professional']);
    }

    public function down(): void
    {
        DB::table('users')->where('role', 'professional')->update(['role' => 'doctor']);
    }
};
