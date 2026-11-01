<?php

declare(strict_types=1);

use System\Database\Blueprint;
use System\Database\Migration;

return new class extends Migration
{
    public function up(): void
    {
        if ($this->hasTable('media')) {
            return;
        }

        $this->schema->create('media', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->char('uuid', 36);
            $table->string('collection', 32);
            $table->string('visibility', 8);

            $table->string('owner_type', 32)->nullable();
            $table->string('owner_ref', 64)->nullable();

            $table->string('mime', 100);
            $table->unsignedBigInteger('size');
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->char('sha256', 64);
            $table->string('original_name', 255)->nullable();

            $table->json('variants');

            $table->char('created_by', 36)->nullable();
            $table->timestamp('created_at')->default('CURRENT_TIMESTAMP');
            $table->timestamp('deleted_at')->nullable();

            $table->unique('uuid', 'uq_' . $this->prefix() . 'media_uuid');
            $table->index(['owner_type', 'owner_ref'], 'ix_' . $this->prefix() . 'media_owner');
            $table->index(['created_by', 'deleted_at'], 'ix_' . $this->prefix() . 'media_created_by');
            $table->index('deleted_at', 'ix_' . $this->prefix() . 'media_deleted_at');
        });
    }

    public function down(): void
    {
        $this->schema->dropIfExists('media');
    }
};
