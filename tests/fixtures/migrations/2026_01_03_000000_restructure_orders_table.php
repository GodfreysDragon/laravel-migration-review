<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('notes');
            $table->renameColumn('customer_email', 'email');
            $table->string('reference', 20)->change();
        });

        Schema::dropIfExists('legacy_invoices');
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->renameColumn('email', 'customer_email');
            $table->text('notes')->nullable();
        });
    }
};
