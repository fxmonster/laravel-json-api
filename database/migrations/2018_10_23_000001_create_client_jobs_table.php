<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateClientJobsTable extends Migration
{

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('json_api_client_jobs', function (Blueprint $table) {
            $table->uuid('uuid')->primary();
            $table->timestamps(6);
            $table->string('api');
            $table->string('resource_type');
            $table->string('resource_id')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('json_api_client_jobs');
    }
}