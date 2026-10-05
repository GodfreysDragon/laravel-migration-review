<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('status');
            $table->string('currency', 3)->default('USD');
            $table->timestamp('shipped_at')->nullable();
            $table->index('customer_email');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['customer_email']);
            $table->dropColumn(['status', 'currency', 'shipped_at']);
        });
    }
};
