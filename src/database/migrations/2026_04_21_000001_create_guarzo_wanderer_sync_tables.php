<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('guarzo_wanderer_sync_instances', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('wanderer_url');
            $table->uuid('access_list_id');
            $table->string('access_list_token');
            $table->timestamps();

            $table->unique(
                ['wanderer_url', 'access_list_id'],
                'guarzo_wanderer_sync_instances_unique'
            );
        });

        Schema::create('guarzo_wanderer_sync_role_mappings', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->integer('role_id')->unsigned();
            $table->unsignedBigInteger('wanderer_instance_id');
            $table->timestamps();

            $table->unique(
                ['role_id', 'wanderer_instance_id'],
                'guarzo_wanderer_sync_role_mappings_unique'
            );

            $table->foreign('role_id')
                ->references('id')->on('roles')
                ->cascadeOnDelete();

            $table->foreign('wanderer_instance_id')
                ->references('id')->on('guarzo_wanderer_sync_instances')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('guarzo_wanderer_sync_role_mappings');
        Schema::dropIfExists('guarzo_wanderer_sync_instances');
    }
};
