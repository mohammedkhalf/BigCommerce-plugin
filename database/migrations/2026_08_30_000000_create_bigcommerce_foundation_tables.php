<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stores', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('store_hash', 64)->unique();
            $table->uuid('account_uuid')->nullable()->index();
            $table->string('name')->nullable();
            $table->string('currency', 3)->nullable();
            $table->string('timezone')->nullable();
            $table->string('locale', 16)->nullable();
            $table->text('access_token')->nullable();
            $table->json('scopes')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('installed_at')->nullable();
            $table->timestamp('uninstalled_at')->nullable();
            $table->timestamps();
        });

        Schema::create('store_users', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('store_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('bigcommerce_user_id');
            $table->string('email');
            $table->string('name')->nullable();
            $table->string('locale', 16)->nullable();
            $table->boolean('is_owner')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['store_id', 'bigcommerce_user_id']);
        });

        Schema::create('tamara_configs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('store_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('mode', 16)->default('sandbox');
            $table->boolean('enabled')->default(false);
            $table->text('api_token')->nullable();
            $table->text('notification_token')->nullable();
            $table->text('public_key')->nullable();
            $table->json('currency_allowlist')->nullable();
            $table->text('webhook_url')->nullable();
            $table->timestamp('last_tested_at')->nullable();
            $table->timestamps();
        });

        Schema::create('payment_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('store_id')->constrained()->cascadeOnDelete();
            $table->string('bc_checkout_id')->index();
            $table->string('bc_order_id')->nullable()->index();
            $table->text('checkout_token')->nullable();
            $table->string('tamara_order_id')->nullable()->index();
            $table->string('tamara_checkout_id')->nullable()->index();
            $table->string('status', 24)->default('pending')->index();
            $table->decimal('amount', 14, 3);
            $table->string('currency', 3);
            $table->json('bc_snapshot')->nullable();
            $table->json('tamara_snapshot')->nullable();
            $table->timestamp('authorised_at')->nullable();
            $table->timestamp('captured_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
            $table->unique(['store_id', 'bc_checkout_id']);
        });

        Schema::create('registered_resources', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('store_id')->constrained()->cascadeOnDelete();
            $table->string('bigcommerce_resource_id');
            $table->string('scope');
            $table->text('destination');
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['store_id', 'bigcommerce_resource_id']);
        });

        Schema::create('webhook_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('store_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('payment_session_id')->nullable()->constrained()->nullOnDelete();
            $table->string('source', 24);
            $table->string('external_id');
            $table->string('event_type')->index();
            $table->json('payload');
            $table->string('processing_status', 24)->default('received')->index();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('received_at');
            $table->timestamp('processed_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
            $table->unique(['source', 'external_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_events');
        Schema::dropIfExists('registered_resources');
        Schema::dropIfExists('payment_sessions');
        Schema::dropIfExists('tamara_configs');
        Schema::dropIfExists('store_users');
        Schema::dropIfExists('stores');
    }
};
